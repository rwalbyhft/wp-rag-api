<?php
/*
Plugin Name: RAG Content API Endpoint
Plugin URI:  https://vbl.harborfreight.com
Description: Creates custom REST API endpoints for RAG systems with incremental updates, authentication, and optimized content processing.
Version: 1.5
Author: Ross Walby
*/

// Prevent direct access
if (!defined('ABSPATH')) {
  exit;
}

/**
 * Register the custom REST API routes
 */
add_action('rest_api_init', function () {
  // Main pages endpoint
  register_rest_route('rag/v1', '/pages', array(
    'methods'  => 'GET',
    'callback' => 'get_rag_optimized_pages',
    'permission_callback' => 'rag_check_permissions',
    'args' => array(
      'modified_since' => array(
        'description' => 'ISO 8601 date string (e.g., 2024-01-01T00:00:00)',
        'type' => 'string',
        'format' => 'date-time',
      ),
      'full_sync' => array(
        'description' => 'Force full sync regardless of modified_since',
        'type' => 'boolean',
        'default' => false,
      ),
    ),
  ));

  // Status endpoint
  register_rest_route('rag/v1', '/status', array(
    'methods'  => 'GET',
    'callback' => 'get_rag_status',
    'permission_callback' => 'rag_check_permissions',
  ));
});

/**
 * Permission callback for RAG endpoints
 * Requires authenticated user with read permissions
 */
function rag_check_permissions()
{
  $user = wp_get_current_user();

  // Skip rate limiting for RAG system user
  if ($user->user_login === 'rag-system') {
    return current_user_can('read');
  }

  // Apply rate limiting for all other users
  $user_id = get_current_user_id();
  $transient_key = 'rag_api_calls_' . $user_id;
  $calls = get_transient($transient_key) ?: 0;

  if ($calls > 100) { // 100 calls per hour for non-RAG users
    return new WP_Error('rate_limit', 'Rate limit exceeded', array('status' => 429));
  }

  set_transient($transient_key, $calls + 1, HOUR_IN_SECONDS);
  return current_user_can('read');
}

/**
 * Main callback function for the pages endpoint
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error A response object with the site's content or an error.
 */
function get_rag_optimized_pages($request)
{
  $start_time = microtime(true);

  $since_date = $request->get_param('modified_since');
  $full_sync = $request->get_param('full_sync') === true;

  // Block page IDs we don't want crawlers to see
  $excluded_page_ids = array(94416, 110038, 113920, 95957, 119660, 92850, 119624, 97780, 116190, 97579, 112176, 119525, 95849, 112223, 120906, 128079, 128641, 128115, 128084, 94401, 114954, 113030, 92807);

  $args = array(
    'post_type'      => 'page',
    'posts_per_page' => -1,
    'post_status'    => 'publish',
    'post__not_in'   => $excluded_page_ids,
  );

  // Only get pages modified since last sync (unless full sync requested)
  if (!$full_sync && $since_date) {
    $args['date_query'] = array(
      array(
        'column' => 'post_modified_gmt',
        'after' => $since_date,
        'inclusive' => true,
      ),
    );
  }

  $pages = get_posts($args);
  $data = array();

  // Add metadata about the sync
  $sync_metadata = array(
    'sync_timestamp' => current_time('mysql', true),
    'sync_type' => $full_sync ? 'full' : 'incremental',
    'total_pages' => count($pages),
    'excluded_pages' => count($excluded_page_ids),
  );

  // If no pages are found, return an error
  if (empty($pages)) {
    return new WP_Error('no_pages', 'No pages were found with the specified criteria.', array('status' => 404));
  }

  // Loop through each page to structure the data
  foreach ($pages as $page) {
    $data[] = process_page_for_rag($page);
  }

  $processing_time = microtime(true) - $start_time;
  $sync_metadata['processing_time_seconds'] = round($processing_time, 2);
  $sync_metadata['pages_per_second'] = count($data) > 0 ? round(count($data) / $processing_time, 2) : 0;

  // Return all the collected data in a single JSON response
  return new WP_REST_Response(array(
    'metadata' => $sync_metadata,
    'pages' => $data,
  ), 200);
}

/**
 * Process individual page for RAG optimization
 *
 * @param WP_Post $page The page object.
 * @return array Processed page data.
 */
function process_page_for_rag($page)
{
  // Get content description with fallback priority:
  // 1. ACF RAG field (if available)
  // 2. WordPress excerpt (if set)
  // 3. Auto-generated excerpt from content
  $dynamic_content = '';
  if (function_exists('get_field')) {
    $dynamic_content = get_field('rag_description_data', $page->ID);
  }
  if (empty($dynamic_content)) {
    $dynamic_content = !empty($page->post_excerpt)
      ? $page->post_excerpt
      : wp_trim_words(wp_strip_all_tags($page->post_content), 55);
  }

  // Get rendered HTML content (works with Bricks Builder and other page builders)
  $page_content_html = get_rendered_page_content($page->ID);

  // Remove navigation elements, sidebars, etc. for cleaner content
  $content_clean = preg_replace('/<nav[^>]*>.*?<\/nav>/is', '', $page_content_html);
  $content_clean = preg_replace('/<aside[^>]*>.*?<\/aside>/is', '', $content_clean);
  $content_clean = preg_replace('/<header[^>]*>.*?<\/header>/is', '', $content_clean);
  $content_clean = preg_replace('/<footer[^>]*>.*?<\/footer>/is', '', $content_clean);

  // Extract images and their alt text from the rendered content
  $images_data = extract_images($page_content_html);

  // Extract headings for better content structure
  $headings = extract_headings($page_content_html);

  return array(
    'id' => $page->ID,
    'title' => $page->post_title,
    'permalink' => get_permalink($page->ID),
    'modified_date_gmt' => $page->post_modified_gmt,
    'content_html' => $page_content_html,
    'content_text' => wp_strip_all_tags($content_clean),
    'content_excerpt' => wp_trim_words(wp_strip_all_tags($content_clean), 50),
    'headings' => $headings,
    'word_count' => str_word_count(wp_strip_all_tags($content_clean)),
    'custom_data' => $dynamic_content,
    'images_in_content' => $images_data,
    'last_author' => get_the_author_meta('display_name', $page->post_author),
  );
}

/**
 * Get rendered page content by fetching the live HTML page with security improvements
 *
 * @param int $page_id The page ID.
 * @return string Rendered HTML content.
 */
function get_rendered_page_content($page_id)
{
  $page_url = get_permalink($page_id);
  $home_host = wp_parse_url(home_url(), PHP_URL_HOST);
  $dest_host = wp_parse_url($page_url, PHP_URL_HOST);

  // Security: verify we're only fetching from our own domain
  if (!$page_url || !$home_host || !$dest_host || !hash_equals($home_host, $dest_host)) {
    error_log('RAG Plugin: Security - host mismatch for page ' . $page_id);
    $post = get_post($page_id);
    return apply_filters('the_content', $post ? $post->post_content : '');
  }

  // Make secure HTTP request with hardened settings
  $response = wp_safe_remote_get($page_url, array(
    'timeout'             => 30,
    'redirection'         => 3,
    'limit_response_size' => 5 * 1024 * 1024, // 5MB limit
    'user-agent'          => 'RAG-Content-Extractor/2.0',
    'sslverify'           => true, // Enforce SSL verification
    'headers'             => array(
      'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    ),
  ));

  if (is_wp_error($response)) {
    error_log('RAG Plugin: Failed to fetch rendered content for page ' . $page_id . ': ' . $response->get_error_message());
    $post = get_post($page_id);
    return apply_filters('the_content', $post ? $post->post_content : '');
  }

  $response_code = wp_remote_retrieve_response_code($response);
  if ($response_code !== 200) {
    error_log('RAG Plugin: Non-200 response for page ' . $page_id . ' - code: ' . $response_code);
    $post = get_post($page_id);
    return apply_filters('the_content', $post ? $post->post_content : '');
  }

  // Verify content type
  $content_type = wp_remote_retrieve_header($response, 'content-type');
  if ($content_type && stripos($content_type, 'text/html') === false) {
    error_log('RAG Plugin: Unexpected content-type for page ' . $page_id . ' - ' . $content_type);
    $post = get_post($page_id);
    return apply_filters('the_content', $post ? $post->post_content : '');
  }

  $html = wp_remote_retrieve_body($response);

  // Extract main content from full HTML page
  $content = extract_main_content_from_html($html);

  // If extraction failed, fallback to basic content
  if (empty($content) || strlen(trim(strip_tags($content))) < 50) {
    error_log('RAG Plugin: Content extraction failed for page ' . $page_id . ', using fallback');
    $post = get_post($page_id);
    return apply_filters('the_content', $post ? $post->post_content : '');
  }

  // Resolve lazy loading before returning
  return resolve_lazy_loading($content);
}

/**
 * Extract main content area from full HTML page
 *
 * @param string $html Full HTML page.
 * @return string Main content HTML.
 */
function extract_main_content_from_html($html)
{
  if (empty($html)) {
    return '';
  }

  libxml_use_internal_errors(true);
  $dom = new DOMDocument();
  $loaded = @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));

  if (!$loaded) {
    return '';
  }

  $xpath = new DOMXPath($dom);

  // Priority 1: Bricks Builder main content
  $main_brx = $xpath->query('//main[@id="brx-content"]');
  if ($main_brx->length > 0) {
    $main_node = $main_brx->item(0);

    // Get inner HTML of main element
    $inner_html = '';
    foreach ($main_node->childNodes as $child) {
      $inner_html .= $dom->saveHTML($child);
    }

    return $inner_html;
  }

  // Priority 2: Other main elements
  $content_selectors = array(
    '//main[@id="content"]',
    '//main[@class*="content"]',
    '//div[@id="content"]',
    '//div[@class*="content"]',
    '//main',
    '//article',
    '//div[@id="main"]',
    '//div[@class*="main"]',
    '//body'
  );

  foreach ($content_selectors as $selector) {
    $content_nodes = $xpath->query($selector);
    if ($content_nodes->length > 0) {
      $content_node = $content_nodes->item(0);

      // Remove unwanted elements that commonly appear in themes
      $unwanted_selectors = array(
        './/nav',
        './/header[not(ancestor::main) and not(ancestor::article)]',
        './/footer[not(ancestor::main) and not(ancestor::article)]',
        './/*[@class="sidebar"]',
        './/*[@id="sidebar"]',
        './/*[@class*="navigation"]',
        './/*[@class*="breadcrumb"]',
        './/*[@class*="menu"]',
        './/*[@role="navigation"]',
        './/script',
        './/style',
        './/noscript'
      );

      foreach ($unwanted_selectors as $unwanted_selector) {
        $unwanted = $xpath->query($unwanted_selector, $content_node);
        foreach ($unwanted as $node) {
          if ($node->parentNode) {
            $node->parentNode->removeChild($node);
          }
        }
      }

      return $dom->saveHTML($content_node);
    }
  }

  return '';
}

/**
 * Resolve lazy loading in HTML content
 *
 * @param string $html HTML content with lazy loading.
 * @return string HTML content with lazy loading resolved.
 */
function resolve_lazy_loading($html)
{
  if (empty($html)) {
    return $html;
  }

  libxml_use_internal_errors(true);
  $dom = new DOMDocument();
  $loaded = @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));

  if (!$loaded) {
    return $html;
  }

  $xpath = new DOMXPath($dom);

  // Handle lazy loaded images
  foreach ($dom->getElementsByTagName('img') as $img) {
    $lazy_src_attrs = array('data-src', 'data-lazy-src', 'data-original', 'data-original-src');
    $lazy_srcset_attrs = array('data-srcset', 'data-lazy-srcset', 'data-original-srcset');

    $current_src = $img->getAttribute('src');

    // If current src is placeholder or empty, look for lazy src
    if (
      empty($current_src) ||
      strpos($current_src, 'data:image/svg+xml') === 0 ||
      strpos($current_src, 'placeholder') !== false
    ) {

      foreach ($lazy_src_attrs as $attr) {
        if ($img->hasAttribute($attr)) {
          $lazy_src = $img->getAttribute($attr);
          if (!empty($lazy_src)) {
            $img->setAttribute('src', normalize_url($lazy_src));
            $img->removeAttribute($attr);
            break;
          }
        }
      }
    }

    // Handle lazy srcset
    foreach ($lazy_srcset_attrs as $attr) {
      if ($img->hasAttribute($attr)) {
        $lazy_srcset = $img->getAttribute($attr);
        if (!empty($lazy_srcset)) {
          $img->setAttribute('srcset', $lazy_srcset);
          $img->removeAttribute($attr);
          break;
        }
      }
    }

    // Remove lazy loading classes
    $class = $img->getAttribute('class');
    if ($class) {
      $class = preg_replace('/\b(lazyload|bricks-lazy-hidden|lazy|lazyloaded)\b/', '', $class);
      $class = trim(preg_replace('/\s+/', ' ', $class));
      if ($class) {
        $img->setAttribute('class', $class);
      } else {
        $img->removeAttribute('class');
      }
    }

    // Remove other lazy loading attributes
    $lazy_attrs = array('data-sizes', 'data-type', 'data-image-meta', 'loading');
    foreach ($lazy_attrs as $attr) {
      if ($img->hasAttribute($attr)) {
        $img->removeAttribute($attr);
      }
    }

    // Set eager loading
    $img->setAttribute('loading', 'eager');
    $img->setAttribute('decoding', 'sync');
  }

  // Handle lazy background images
  foreach ($xpath->query('//*[@data-bg or @data-background or @data-lazy-background]') as $element) {
    $bg_url = $element->getAttribute('data-bg') ?:
      $element->getAttribute('data-background') ?:
      $element->getAttribute('data-lazy-background');

    if ($bg_url) {
      $bg_url = normalize_url($bg_url);
      $style = $element->getAttribute('style');
      $style = "background-image: url('{$bg_url}'); " . $style;
      $element->setAttribute('style', $style);

      // Remove lazy attributes
      $element->removeAttribute('data-bg');
      $element->removeAttribute('data-background');
      $element->removeAttribute('data-lazy-background');
    }
  }

  // Return the body content
  $body = $dom->getElementsByTagName('body')->item(0);
  $output = '';
  if ($body) {
    foreach ($body->childNodes as $child) {
      $output .= $dom->saveHTML($child);
    }
  }

  return $output;
}

/**
 * Normalize URLs to absolute format
 *
 * @param string $url URL to normalize.
 * @return string Normalized absolute URL.
 */
function normalize_url($url)
{
  if (empty($url)) {
    return $url;
  }

  // Already absolute
  if (preg_match('#^https?://#i', $url)) {
    return $url;
  }

  // Protocol-relative
  if (strpos($url, '//') === 0) {
    return (is_ssl() ? 'https:' : 'http:') . $url;
  }

  // Root-relative
  if ($url[0] === '/') {
    return home_url($url);
  }

  // Relative
  return trailingslashit(home_url()) . ltrim($url, '/');
}

/**
 * Extract images from HTML content with enhanced metadata for brand guidelines
 *
 * @param string $html The HTML content.
 * @return array Array of image data.
 */
function extract_images($html)
{
  $images_data = array();

  if (!empty($html)) {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));

    if (!$loaded) {
      error_log('Failed to parse HTML for image extraction');
      return $images_data;
    }

    $images = $dom->getElementsByTagName('img');

    foreach ($images as $image) {
      $url = $image->getAttribute('src');
      $alt = $image->getAttribute('alt');
      $title = $image->getAttribute('title');
      $class = $image->getAttribute('class');

      // Convert relative URLs to absolute
      if (strpos($url, 'http') !== 0 && !empty($url)) {
        $url = home_url($url);
      }

      if (!empty($url)) {
        // Try to get context from surrounding elements
        $context = '';
        $parent = $image->parentNode;
        if ($parent) {
          // Look for nearby headings or captions
          $xpath = new DOMXPath($dom);
          $preceding_heading = $xpath->query('.//preceding::h1[1] | .//preceding::h2[1] | .//preceding::h3[1]', $image);
          if ($preceding_heading->length > 0) {
            $context = trim($preceding_heading->item(0)->textContent);
          }

          // Look for figure caption
          $figcaption = $xpath->query('.//ancestor::figure//figcaption | .//following-sibling::figcaption[1]', $image);
          if ($figcaption->length > 0) {
            $context .= ' - ' . trim($figcaption->item(0)->textContent);
          }
        }

        // Determine image type based on filename and context
        $image_type = 'general';
        $filename = basename(parse_url($url, PHP_URL_PATH));
        if (strpos(strtolower($filename . $alt . $context), 'logo') !== false) {
          $image_type = 'logo';
        } elseif (
          strpos(strtolower($context), 'photography') !== false ||
          strpos(strtolower($context), 'lifestyle') !== false
        ) {
          $image_type = 'photography';
        } elseif (strpos(strtolower($context), 'product') !== false) {
          $image_type = 'product';
        }

        $images_data[] = array(
          'url' => $url,
          'alt' => $alt ? $alt : '',
          'title' => $title ? $title : '',
          'width' => $image->getAttribute('width'),
          'height' => $image->getAttribute('height'),
          'css_class' => $class,
          'context' => trim($context),
          'image_type' => $image_type,
          'filename' => $filename,
        );
      }
    }
  }

  return $images_data;
}

/**
 * Extract headings from HTML content
 *
 * @param string $html The HTML content.
 * @return array Array of heading data.
 */
function extract_headings($html)
{
  $headings = array();

  if (!empty($html)) {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));

    if (!$loaded) {
      error_log('Failed to parse HTML for heading extraction');
      return $headings;
    }

    for ($i = 1; $i <= 6; $i++) {
      $h_tags = $dom->getElementsByTagName("h{$i}");
      foreach ($h_tags as $heading) {
        $headings[] = array(
          'level' => $i,
          'text' => trim($heading->textContent),
        );
      }
    }
  }

  return $headings;
}

/**
 * Status endpoint callback
 *
 * @return WP_REST_Response Status information.
 */
function get_rag_status()
{
  $excluded_page_ids = get_excluded_page_ids();

  $total_pages = wp_count_posts('page')->publish - count($excluded_page_ids);
  $last_modified = get_posts(array(
    'post_type' => 'page',
    'posts_per_page' => 1,
    'post_status' => 'publish',
    'orderby' => 'modified',
    'order' => 'DESC',
    'fields' => 'ids',
    'post__not_in' => $excluded_page_ids,
  ));

  $last_modified_date = $last_modified ? get_post_modified_time('c', true, $last_modified[0]) : null;

  return new WP_REST_Response(array(
    'total_pages' => $total_pages,
    'excluded_pages' => count($excluded_page_ids),
    'last_modified' => $last_modified_date,
    'server_time' => current_time('c', true),
    'endpoints' => array(
      'pages' => home_url('/wp-json/rag/v1/pages'),
      'status' => home_url('/wp-json/rag/v1/status'),
    ),
  ), 200);
}
