import os, sys, json, pathlib
from typing import Dict, Any, Optional, Iterable
import requests
from tenacity import retry, wait_exponential, stop_after_attempt
from openai import OpenAI
import openai as _openai  # for __version__

# === Env ===
WP_BASE_URL = os.environ["WP_BASE_URL"].rstrip("/")
WP_USER = os.environ["WP_USER"]
WP_APP_PASSWORD = os.environ["WP_APP_PASSWORD"]
OPENAI_API_KEY = os.environ["OPENAI_API_KEY"]
VECTOR_STORE_NAME = os.environ.get("VECTOR_STORE_NAME", "vbl-rag-test")

INPUT_SINCE = os.environ.get("INPUT_SINCE") or ""
STORED_CURSOR = os.environ.get("STORED_CURSOR") or ""
UPDATE_IDS = os.environ.get("UPDATE_IDS") or ""

PAGES_PER_PAGE = 50
FIELDS = "text"

# === HTTP session for WordPress ===
SESSION = requests.Session()
SESSION.auth = (WP_USER, WP_APP_PASSWORD)
SESSION.headers.update({"Accept": "application/json"})

def wp_url(path: str, params: Dict[str, str] | None = None) -> str:
    from urllib.parse import urlencode
    url = f"{WP_BASE_URL}{path}"
    if params:
        url += f"?{urlencode(params)}"
    return url

@retry(wait=wait_exponential(multiplier=1, min=1, max=10), stop=stop_after_attempt(5))
def get_json(url: str) -> Dict[str, Any]:
    r = SESSION.get(url, timeout=60)
    r.raise_for_status()
    return r.json()

def iter_pages(modified_after: Optional[str], ids: Optional[str]) -> Iterable[Dict[str, Any]]:
    """Yield each page JSON from /rag/v1/pages (paged or by specific IDs)."""
    if ids:
        url = wp_url("/wp-json/rag/v1/pages", {"ids": ids, "fields": FIELDS})
        data = get_json(url)
        yield from data.get("pages", [])
        return

    page = 1
    while True:
        params = {"per_page": str(PAGES_PER_PAGE), "page": str(page), "fields": FIELDS}
        if modified_after:
            params["modified_after"] = modified_after
        data = get_json(wp_url("/wp-json/rag/v1/pages", params))
        for p in data.get("pages", []):
            yield p
        meta = data.get("metadata", {})
        if not meta.get("has_more"):
            break
        page += 1

def page_to_text(page_obj: Dict[str, Any]) -> str:
    """Flatten a page record to a nice .txt body for Vector Store upload."""
    title = page_obj.get("title") or ""
    permalink = page_obj.get("permalink") or ""
    modified = page_obj.get("modified_date_gmt") or ""
    headings = page_obj.get("headings") or []
    content_text = page_obj.get("content_text") or ""

    lines = [
        f"TITLE: {title}",
        f"URL: {permalink}",
        f"LAST_MODIFIED: {modified}",
        "",
    ]
    if headings:
        lines.append("HEADINGS:")
        for h in headings:
            lvl = h.get("level")
            txt = h.get("text", "")
            hid = h.get("id")
            lines.append(f"  - H{lvl} {txt}" + (f"  (#{hid})" if hid else ""))
        lines.append("")
    lines.append("CONTENT:")
    lines.append(content_text.strip())
    return "\n".join(lines) + "\n"

def ensure_dir(p: pathlib.Path):
    p.mkdir(parents=True, exist_ok=True)

# === OpenAI Vector Store compatibility helpers ===

def _get_vs_api(client: OpenAI):
    """
    Return the vector stores API object regardless of SDK layout.
    - Newer SDKs: client.beta.vector_stores
    - Some versions: client.vector_stores
    """
    if hasattr(client, "beta") and hasattr(client.beta, "vector_stores"):
        return client.beta.vector_stores
    if hasattr(client, "vector_stores"):
        return client.vector_stores
    raise RuntimeError(
        "Your OpenAI SDK doesn't expose Vector Stores. "
        "Upgrade with: pip install 'openai>=1.48,<2'"
    )

def _list_stores(vs_api):
    """Return a list of vector store objects from vs_api.list(...)."""
    res = vs_api.list(limit=100)
    # Some SDKs return a Paged object with .data; others return a plain list
    return getattr(res, "data", res)

def _attach_file(vs_api, vector_store_id: str, file_id: str, attrs: Dict[str, Any]):
    """
    Attach a file to the vector store.
    Newer SDKs expect 'attributes='; older pre-release builds used 'metadata='.
    Try attributes first, then fallback to metadata.
    """
    try:
        return vs_api.files.create(
            vector_store_id=vector_store_id,
            file_id=file_id,
            attributes=attrs,
        )
    except TypeError:
        # Older signature
        return vs_api.files.create(
            vector_store_id=vector_store_id,
            file_id=file_id,
            metadata=attrs,
        )

def main():
    print("OpenAI SDK version:", getattr(_openai, "__version__", "unknown"))

    # Decide mode (incremental vs specific IDs)
    since = INPUT_SINCE or STORED_CURSOR or ""
    ids = UPDATE_IDS.strip() or ""

    out_dir = pathlib.Path("/tmp/rag_pages")
    ensure_dir(out_dir)

    # 1) Fetch pages and write .txt files
    count_pages = 0
    written_files: list[tuple[pathlib.Path, Dict[str, Any]]] = []
    for page in iter_pages(modified_after=since, ids=ids):
        pid = page.get("id")
        if not pid:
            continue
        text_body = page_to_text(page)
        outfile = out_dir / f"{pid}.txt"
        outfile.write_text(text_body, encoding="utf-8")
        written_files.append((outfile, page))
        count_pages += 1

    print(f"Prepared {count_pages} page files in {out_dir}")
    if count_pages == 0:
        return

    # 2) OpenAI Vector Store: create or reuse
    client = OpenAI(api_key=OPENAI_API_KEY)
    vs_api = _get_vs_api(client)

    stores = _list_stores(vs_api)
    vector_store = next((s for s in stores if getattr(s, "name", None) == VECTOR_STORE_NAME), None)
    if not vector_store:
        vector_store = vs_api.create(name=VECTOR_STORE_NAME)
    print(f"Using Vector Store: {vector_store.id} ({vector_store.name})")

    # 3) Upload each file then attach with attributes/metadata
    uploaded = 0
    for path, page in written_files:
        with open(path, "rb") as f:
            file_obj = client.files.create(file=f, purpose="assistants")

        attrs = {
            "source": page.get("permalink"),
            "page_id": str(page.get("id")),
            "modified": page.get("modified_date_gmt"),
            "title": page.get("title"),
            "brand": page.get("brand"),
            "pillar": page.get("pillar"),
            "section": page.get("section"),
        }
        _attach_file(vs_api, vector_store.id, file_obj.id, attrs)
        uploaded += 1

    print(f"Uploaded {uploaded} files to vector store.")

if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        print("ERROR:", e)
        sys.exit(1)
