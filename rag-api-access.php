<?php
/*
Plugin Name: RAG Content API Endpoint
Plugin URI:  https://vbl.harborfreight.com
Description: REST API endpoints for RAG pipelines with live Bricks <main> extraction, lazy-load resolution, structural plaintext, chunking, incremental sync. Optimized for OpenAI Vector Store ingestion.
Version: 2.5.0
Author: Ross Walby
*/

if (!defined('ABSPATH')) { exit; }

class RAG_Content_API {
    const API_NS           = 'rag/v1';
    const CAPABILITY       = 'read';
    const RAG_USER         = 'rag-system';
    const MAX_PER_PAGE     = 100;
    const DEFAULT_PER_PAGE = 50;

    // Chunking defaults (~300 tokens for text-embedding-3-* models)
    const CHUNK_SIZE_CHARS = 1200;
    const CHUNK_OVERLAP    = 150;

    // Transient TTL for processed items
    const CACHE_TTL        = 12 * HOUR_IN_SECONDS;

    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
        // Enforce HTTPS + Application Password basic auth for our namespace
        add_filter('rest_authentication_errors', array($this, 'enforce_basic_auth_for_namespace'), 9);
        // Clear cache when pages are updated
        add_action('save_post_page', array($this, 'clear_page_cache'));
        add_action('delete_post', array($this, 'clear_page_cache'));
    }

    public function register_routes() {
        register_rest_route(self::API_NS, '/status', array(
            'methods'  => 'GET',
            'callback' => array($this, 'get_status'),
            'permission_callback' => array($this, 'check_permissions'),
        ));

        register_rest_route(self::API_NS, '/pages', array(
            'methods'  => 'GET',
            'callback' => array($this, 'get_pages'),
            'permission_callback' => array($this, 'check_permissions'),
            'args' => array(
                'per_page' => array(
                    'description' => 'Items per request (1-100)',
                    'type' => 'integer',
                    'default' => self::DEFAULT_PER_PAGE,
                    'minimum' => 1,
                    'maximum' => self::MAX_PER_PAGE,
                    'sanitize_callback' => 'absint',
                ),
                'page' => array(
                    'description' => 'Page number (1-indexed)',
                    'type' => 'integer',
                    'default' => 1,
                    'minimum' => 1,
                    'sanitize_callback' => 'absint',
                ),
                'fields' => array(
                    'description' => 'Response format: "text" (default) or "full" (includes HTML)',
                    'type' => 'string',
                    'enum' => array('text', 'full'),
                    'default' => 'text',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'modified_after' => array(
                    'description' => 'ISO8601; return posts strictly modified after this timestamp',
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'ids' => array(
                    'description' => 'Comma-separated post IDs to fetch directly',
                    'type' => 'string',
                    'sanitize_callback' => function($v){ return preg_replace('/[^0-9,]/', '', (string)$v); },
                ),
            ),
        ));

        register_rest_route(self::API_NS, '/reindex', array(
            'methods'  => 'POST',
            'callback' => array($this, 'reindex_now'),
            'permission_callback' => array($this, 'check_permissions'),
            'args' => array(
                'ids'    => array('type'=>'array','items'=>array('type'=>'integer')),
                'fields' => array('type'=>'string','enum'=>array('text','full'),'default'=>'text'),
            ),
        ));
    }

    /**
     * Clear cached data for a page when it's updated
     */
    public function clear_page_cache($post_id) {
        if (get_post_type($post_id) !== 'page') {
            return;
        }
        
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            '_transient_rag_page_' . $post_id . '_%',
            '_transient_timeout_rag_page_' . $post_id . '_%'
        ));
    }

    /**
     * Require HTTPS and Application Password basic auth for rag/v1 routes.
     */
    public function enforce_basic_auth_for_namespace($result) {
        if (!empty($result) && is_wp_error($result)) { return $result; }
        $req_uri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($req_uri, '/wp-json/' . self::API_NS . '/') === false) {
            return $result; // not our routes
        }

        // HTTPS is strongly recommended (WordPress.com Business is HTTPS by default)
        if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
            return new WP_Error('rag_https_required', 'HTTPS required.', array('status' => 400));
        }

        // Require Application Password style Basic Auth (prevents cookie usage/CSRF)
        if (!isset($_SERVER['PHP_AUTH_USER'])) {
            return new WP_Error('rag_auth_required', 'Application Password authentication required.', array('status' => 401));
        }
        return $result;
    }

    /**
     * Permission callback - restrict to rag-system (read) or admins.
     */
    public function check_permissions() {
        if (!is_user_logged_in()) {
            return new WP_Error('rag_auth', 'Authentication required.', array('status' => 401));
        }
        $user = wp_get_current_user();
        if (($user->user_login === self::RAG_USER && current_user_can(self::CAPABILITY)) || current_user_can('manage_options')) {
            return true;
        }
        return new WP_Error('rag_forbidden', 'Forbidden.', array('status' => 403));
    }

    private function get_excluded_page_ids() {
        $excluded = array(
            94416,110038,113920,95957,119660,92850,119624,97780,
            116190,97579,112176,119525,95849,112223,120906,128079,
            128641,128115,128084,94401,114954,113030,92807
        );
        return apply_filters('rag_excluded_page_ids', $excluded);
    }

    public function get_status() {
        $excluded_ids = $this->get_excluded_page_ids();
        $count_query = new WP_Query(array(
            'post_type'        => 'page',
            'post_status'      => 'publish',
            'post__not_in'     => $excluded_ids,
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'no_found_rows'    => false,
            'suppress_filters' => true,
        ));
        $last_modified_posts = get_posts(array(
            'post_type'        => 'page',
            'post_status'      => 'publish',
            'post__not_in'     => $excluded_ids,
            'orderby'          => 'modified',
            'order'            => 'DESC',
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'suppress_filters' => true,
        ));
        $last_modified = $last_modified_posts ? get_post_modified_time('c', true, $last_modified_posts[0]) : null;

        return new WP_REST_Response(array(
            'healthy'        => true,
            'version'        => '4.2.0',
            'total_items'    => (int) $count_query->found_posts,
            'excluded_pages' => count($excluded_ids),
            'last_modified'  => $last_modified,
            'server_time'    => current_time('c', true),
            'endpoints'      => array(
                'pages'   => rest_url(self::API_NS . '/pages'),
                'status'  => rest_url(self::API_NS . '/status'),
                'reindex' => rest_url(self::API_NS . '/reindex'),
            ),
        ), 200);
    }

    public function get_pages(WP_REST_Request $request) {
        $start_time     = microtime(true);
        $per_page       = min(self::MAX_PER_PAGE, max(1, (int) $request->get_param('per_page')));
        $page           = max(1, (int) $request->get_param('page'));
        $fields         = in_array($request->get_param('fields'), array('text','full'), true) ? $request->get_param('fields') : 'text';
        $modified_after = $request->get_param('modified_after');
        $ids_raw        = $request->get_param('ids');
        $excluded_ids   = $this->get_excluded_page_ids();

        // Fast path: explicit IDs
        if (!empty($ids_raw)) {
            $ids = array_values(array_filter(array_map('absint', explode(',', $ids_raw))));
            $posts = $ids ? get_posts(array(
                'post_type'        => 'page',
                'post_status'      => 'publish',
                'post__not_in'     => $excluded_ids,
                'post__in'         => $ids,
                'orderby'          => 'post__in',
                'posts_per_page'   => count($ids),
                'suppress_filters' => true,
            )) : array();

            $data = array();
            foreach ($posts as $p) {
                if ($item = $this->process_page($p, $fields)) { $data[] = $item; }
            }
            $metadata = array(
                'mode'                     => 'ids',
                'returned'                 => count($data),
                'fields'                   => $fields,
                'sync_timestamp'           => current_time('c', true),
                'processing_time_seconds'  => round(microtime(true) - $start_time, 2),
            );
            $res = new WP_REST_Response(array('metadata' => $metadata, 'pages' => $data), 200);
            $res->header('X-WP-Total', (string) count($ids));
            $res->header('X-WP-TotalPages', '1');
            return $res;
        }

        // Normal path: paged + optional modified_after
        $args = array(
            'post_type'        => 'page',
            'post_status'      => 'publish',
            'post__not_in'     => $excluded_ids,
            'orderby'          => array('modified' => 'DESC', 'ID' => 'DESC'),
            'posts_per_page'   => $per_page,
            'paged'            => $page,
            'fields'           => 'ids',
            'no_found_rows'    => false,
            'suppress_filters' => true,
        );
        if (!empty($modified_after)) {
            $args['date_query'] = array(array(
                'column'    => 'post_modified_gmt',
                'after'     => $modified_after,
                'inclusive' => false,
            ));
        }

        $args  = apply_filters('rag_pages_query_args', $args, $request);
        $query = new WP_Query($args);

        $total_count = (int) $query->found_posts;
        $total_pages = (int) ceil(max(1, $total_count) / $per_page);

        $posts = !empty($query->posts) ? get_posts(array(
            'post_type'        => 'page',
            'post_status'      => 'publish',
            'post__in'         => $query->posts,
            'orderby'          => 'post__in',
            'posts_per_page'   => $per_page,
            'suppress_filters' => true,
        )) : array();

        $data = array();
        foreach ($posts as $p) {
            if ($item = $this->process_page($p, $fields)) { $data[] = $item; }
        }

        $processing_time = microtime(true) - $start_time;
        $metadata = array(
            'mode'                     => 'paged',
            'sync_timestamp'           => current_time('c', true),
            'page'                     => $page,
            'per_page'                 => $per_page,
            'total_items'              => $total_count,
            'total_pages'              => $total_pages,
            'returned'                 => count($data),
            'has_more'                 => $page < $total_pages,
            'next_page'                => $page < $total_pages ? $page + 1 : null,
            'fields'                   => $fields,
            'modified_after'           => $modified_after ?: null,
            'processing_time_seconds'  => round($processing_time, 2),
            'pages_per_second'         => count($data) ? round(count($data) / max(0.0001, $processing_time), 2) : 0,
        );

        $payload  = array('metadata' => $metadata, 'pages' => $data);
        $response = new WP_REST_Response($payload, 200);

        $response->header('X-WP-Total', (string) $total_count);
        $response->header('X-WP-TotalPages', (string) $total_pages);
        if ($metadata['has_more']) {
            $next = add_query_arg(array(
                'page'           => $metadata['next_page'],
                'per_page'       => $per_page,
                'fields'         => $fields,
                'modified_after' => $modified_after,
            ), rest_url(self::API_NS . '/pages'));
            $response->header('Link', '<' . esc_url($next) . '>; rel="next"');
        }

        // Optional: ETag/304
        $etag = '"' . md5( wp_json_encode($payload) ) . '"';
        $response->header('ETag', $etag);
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
            $response->set_status(304);
            $response->set_data(null);
        }

        return $response;
    }

    public function reindex_now(WP_REST_Request $r) {
        $ids    = array_map('absint', (array) $r->get_param('ids'));
        $fields = in_array($r->get_param('fields'), array('text','full'), true) ? $r->get_param('fields') : 'text';
        if (!$ids) { return new WP_REST_Response(array('pages'=>array()), 200); }

        $excluded_ids = $this->get_excluded_page_ids();
        $posts = get_posts(array(
            'post_type'        => 'page',
            'post_status'      => 'publish',
            'post__not_in'     => $excluded_ids,
            'post__in'         => $ids,
            'orderby'          => 'post__in',
            'posts_per_page'   => count($ids),
            'suppress_filters' => true,
        ));

        $out = array();
        foreach ($posts as $p) {
            if ($item = $this->process_page($p, $fields)) { $out[] = $item; }
        }
        return new WP_REST_Response(array('pages' => $out), 200);
    }

    private function process_page(WP_Post $page, $fields) {
        $permalink    = get_permalink($page->ID);
        $modified_iso = get_post_modified_time('c', true, $page);

        // Cache keyed by last modified time
        $cache_key = 'rag_page_' . $page->ID . '_' . strtotime(get_post_modified_time('U', true, $page));
        $cached = get_transient($cache_key);
        if ($cached && ($fields === 'text' || !empty($cached['content_html']))) {
            $item = $cached;
        } else {
            $rendered_html = $this->get_rendered_content($page->ID);
            if (empty($rendered_html)) {
                error_log('RAG: Empty content for page ' . $page->ID);
                return null;
            }

            $metadata     = $this->extract_metadata($page->ID, $permalink);
            $headings     = $this->extract_headings($rendered_html);
            $content_text = $this->html_to_plaintext($rendered_html);

            $item = array(
                'id'                => $page->ID,
                'title'             => get_the_title($page),
                'permalink'         => $permalink,
                'modified_date_gmt' => $modified_iso,
                'brand'             => $metadata['brand'],
                'pillar'            => $metadata['pillar'],
                'section'           => $metadata['section'],
                'content_text'      => $content_text,
                'content_excerpt'   => wp_trim_words($content_text, 50),
                'headings'          => $headings,
                'word_count'        => $this->word_count_mb($content_text),
                'last_author'       => get_the_author_meta('display_name', $page->post_author),
                'canonical'         => $permalink,
                'template'          => get_page_template_slug($page->ID) ?: 'default',
            );
            if ($fields === 'full') {
                $item['content_html'] = $rendered_html;
            }
            set_transient($cache_key, $item, self::CACHE_TTL);
        }

        if ($breadcrumbs = $this->get_acf_breadcrumbs($page->ID)) {
            $item['breadcrumbs'] = $breadcrumbs;
        }

        // Build chunks with full metadata
        $chunks = array();
        foreach ($this->chunk_text($item['content_text'], self::CHUNK_SIZE_CHARS, self::CHUNK_OVERLAP) as $i => $c) {
            $chunks[] = array(
                'index'    => $i,
                'text'     => $c,
                'source'   => $permalink,
                'page_id'  => $page->ID,
                'modified' => $modified_iso,
                'title'    => $item['title'],
                'brand'    => $item['brand'],
                'pillar'   => $item['pillar'],
                'section'  => $item['section'],
            );
        }
        $item['chunks'] = apply_filters('rag_chunks', $chunks, $page->ID);
        $item = apply_filters('rag_transform_item', $item, $page, $fields);
        return $item;
    }

    private function get_rendered_content($page_id) {
        $page_url  = add_query_arg('rag_extract', '1', get_permalink($page_id));
        $home_host = wp_parse_url(home_url(), PHP_URL_HOST);
        $dest_host = wp_parse_url($page_url, PHP_URL_HOST);

        if (!$page_url || !$home_host || !$dest_host || !hash_equals($home_host, $dest_host)) {
            error_log('RAG: Host mismatch for page ' . $page_id);
            return $this->get_fallback_content($page_id);
        }

        $response = wp_safe_remote_get($page_url, array(
            'timeout'            => 30,
            'redirection'        => 3,
            'limit_response_size'=> 5 * 1024 * 1024,
            'user-agent'         => 'RAG-Content-Extractor/4.2',
            'sslverify'          => true,
            'headers'            => array('Accept' => 'text/html,application/xhtml+xml'),
        ));

        if (is_wp_error($response)) {
            error_log('RAG: Fetch error for page ' . $page_id . ': ' . $response->get_error_message());
            return $this->get_fallback_content($page_id);
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log('RAG: Non-200 for page ' . $page_id . ' (code: ' . $code . ')');
            return $this->get_fallback_content($page_id);
        }
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        if ($content_type && stripos($content_type, 'text/html') === false) {
            error_log('RAG: Invalid content type for page ' . $page_id);
            return $this->get_fallback_content($page_id);
        }

        $html    = wp_remote_retrieve_body($response);
        $content = $this->extract_main_content($html);

        if (empty($content) || strlen(trim(wp_strip_all_tags($content))) < 50) {
            error_log('RAG: Insufficient content for page ' . $page_id);
            return $this->get_fallback_content($page_id);
        }
        return $this->resolve_lazy_loading_and_clean($content);
    }

    private function get_fallback_content($page_id) {
        $post = get_post($page_id);
        if (!$post) { return ''; }
        $html = apply_filters('the_content', $post->post_content);
        return $this->extract_main_content($html);
    }

    private function extract_main_content($html) {
        if (empty($html)) { return ''; }
        if (preg_match('/<main[^>]*id=["\']brx-content["\'][^>]*>([\s\S]*?)<\/main>/i', $html, $m)) {
            return $m[1];
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($dom);

        $selectors = array('//*[@id="brx-content"]','//main','//article','//div[@id="content"]','//body');
        $node = null;
        foreach ($selectors as $sel) {
            $nodes = $xpath->query($sel);
            if ($nodes && $nodes->length > 0) { $node = $nodes->item(0); break; }
        }
        if (!$node) { return ''; }

        // Strip scripts, styles, nav/header/footer, templates, hidden elements
        $unwanted = $xpath->query('.//nav | .//header | .//footer | .//script | .//style | .//template | .//*[@hidden] | .//*[@aria-hidden="true"]', $node);
        foreach ($unwanted as $el) { if ($el->parentNode) { $el->parentNode->removeChild($el); } }

        return $dom->saveHTML($node);
    }

    private function normalize_url($url) {
        if (empty($url) || preg_match('#^https?://#i', $url)) { return $url; }
        if (strpos($url, '//') === 0) { return (is_ssl() ? 'https:' : 'http:') . $url; }
        return esc_url_raw( wp_make_link_absolute( $url, home_url('/') ) );
    }

    private function resolve_lazy_loading_and_clean($html) {
        if (empty($html)) { return $html; }
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));

        foreach ($dom->getElementsByTagName('img') as $img) {
            $lazy_attrs = array('data-src','data-lazy-src','data-original');
            $current_src = $img->getAttribute('src');

            // Resolve lazy loading first
            if (empty($current_src) || strpos($current_src, 'data:image') === 0) {
                foreach ($lazy_attrs as $attr) {
                    if ($img->hasAttribute($attr)) {
                        $current_src = $this->normalize_url($img->getAttribute($attr));
                        $img->setAttribute('src', $current_src);
                        $img->removeAttribute($attr);
                        break;
                    }
                }
            }
            
            // Normalize existing src
            if (!empty($current_src)) {
                $img->setAttribute('src', $this->normalize_url($current_src));
            }
            
            foreach (array('data-srcset','data-lazy-srcset') as $attr) {
                if ($img->hasAttribute($attr)) {
                    $img->setAttribute('srcset', $img->getAttribute($attr));
                    $img->removeAttribute($attr);
                }
            }
            $class = $img->getAttribute('class');
            if ($class) {
                $class = preg_replace('/\b(lazyload|bricks-lazy-hidden|lazy)\b/', '', $class);
                $class = trim(preg_replace('/\s+/', ' ', $class));
                $class ? $img->setAttribute('class', $class) : $img->removeAttribute('class');
            }
            foreach (array('data-sizes','data-type','loading','decoding','fetchpriority','intrinsicsize') as $attr) {
                if ($img->hasAttribute($attr)) { $img->removeAttribute($attr); }
            }

            // Replace image with text that includes URL and alt
            $alt = trim($img->getAttribute('alt'));
            $src = $img->getAttribute('src');
            if ($img->parentNode) {
                if ($alt && $src) {
                    $replacement_text = '[Image: ' . $alt . ' | URL: ' . $src . ']';
                } elseif ($src) {
                    $replacement_text = '[Image: ' . $src . ']';
                } elseif ($alt) {
                    $replacement_text = '[Image: ' . $alt . ']';
                } else {
                    $replacement_text = '';
                }
                
                if ($replacement_text) {
                    $replacement = $dom->createTextNode($replacement_text);
                    $img->parentNode->replaceChild($replacement, $img);
                } else {
                    $img->parentNode->removeChild($img);
                }
            }
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) { return $html; }

        $output = '';
        foreach ($body->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }
        return $output;
    }

    private function html_to_plaintext($html) {
        $html = preg_replace('#<(script|style|noscript)[\s\S]*?</\1>#i', ' ', $html);
        $block = '(?:p|div|section|article|li|ul|ol|table|thead|tbody|tr|td|th|h[1-6]|blockquote|pre)';
        $html = preg_replace('#</'.$block.'>#i', "\n", $html);
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = preg_replace_callback('#<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#i', function($m){
            return trim(wp_strip_all_tags($m[2])) . ' (' . esc_url_raw($m[1]) . ')';
        }, $html);

        $text = wp_strip_all_tags($html, true);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\R{3,}/", "\n\n", $text);
        $text = preg_replace("/[ \t]{2,}/", ' ', $text);
        return trim($text);
    }

    private function extract_headings($html) {
        $headings = array();
        if (empty($html)) { return $headings; }
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        for ($i=1; $i<=6; $i++) {
            foreach ($dom->getElementsByTagName('h' . $i) as $node) {
                $text = trim($node->textContent);
                $id   = $node->getAttribute('id') ?: null;
                $headings[] = array('level' => $i, 'text' => $text, 'id' => $id);
            }
        }
        return $headings;
    }

    private function extract_metadata($post_id, $permalink) {
        $breadcrumbs = $this->get_acf_breadcrumbs($post_id);
        if ($breadcrumbs && !empty($breadcrumbs['brand_url'])) {
            $brand_path  = wp_parse_url($breadcrumbs['brand_url'], PHP_URL_PATH);
            $brand_parts = array_values(array_filter(explode('/', trim($brand_path, '/'))));
            $brand   = $brand_parts[0] ?? null;
            $pillar  = !empty($breadcrumbs['level2']) ? sanitize_title($breadcrumbs['level2']) : null;
            $sections = array();
            if (!empty($breadcrumbs['level3'])) { $sections[] = sanitize_title($breadcrumbs['level3']); }
            if (!empty($breadcrumbs['level4'])) { $sections[] = sanitize_title($breadcrumbs['level4']); }
            return array('brand'=>$brand,'pillar'=>$pillar,'section'=>!empty($sections) ? implode('/', $sections) : null);
        }
        $path  = wp_parse_url($permalink, PHP_URL_PATH);
        $parts = array_values(array_filter(explode('/', trim($path, '/'))));
        return array(
            'brand'   => $parts[0] ?? null,
            'pillar'  => $parts[1] ?? null,
            'section' => isset($parts[2]) ? implode('/', array_slice($parts, 2)) : null,
        );
    }

    private function get_acf_breadcrumbs($post_id) {
        if (!function_exists('get_field')) { return null; }
        $fields = apply_filters('rag_acf_field_names', array(
            'level1'    => 'breadcrumb_level_1',
            'level2'    => 'breadcrumb_level_2',
            'level3'    => 'breadcrumb_level_3',
            'level4'    => 'breadcrumb_level_4',
            'brand_url' => 'brand_url',
        ));
        $data = array();
        foreach ($fields as $key => $field_name) {
            $value = trim((string) get_field($field_name, $post_id));
            $data[$key] = $value ?: null;
        }
        return array_filter($data) ? $data : null;
    }

    private function word_count_mb($text) {
        $parts = preg_split('/\p{Z}+/u', trim($text));
        return $parts ? count($parts) : 0;
    }

    private function chunk_text($text, $max=self::CHUNK_SIZE_CHARS, $overlap=self::CHUNK_OVERLAP) {
        $chunks = array();
        $len = mb_strlen($text);
        $step = max(1, $max - $overlap);
        for ($i = 0; $i < $len; $i += $step) {
            $chunks[] = mb_substr($text, $i, $max);
        }
        return $chunks;
    }
}

// Initialize
add_action('plugins_loaded', function(){ new RAG_Content_API(); });
