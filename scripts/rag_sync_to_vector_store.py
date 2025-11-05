import os, sys, json, time, pathlib, base64
from typing import List, Dict, Any, Optional
import requests
from tenacity import retry, wait_exponential, stop_after_attempt
from openai import OpenAI

WP_BASE_URL = os.environ["WP_BASE_URL"].rstrip("/")
WP_USER = os.environ["WP_USER"]
WP_APP_PASSWORD = os.environ["WP_APP_PASSWORD"]
OPENAI_API_KEY = os.environ["OPENAI_API_KEY"]
VECTOR_STORE_NAME = os.environ.get("VECTOR_STORE_NAME", "vbl-rag-test")

INPUT_SINCE = os.environ.get("INPUT_SINCE") or ""
STORED_CURSOR = os.environ.get("STORED_CURSOR") or ""
UPDATE_IDS = os.environ.get("UPDATE_IDS") or ""

PAGES_PER_PAGE = 50
FIELDS = "text"  # or "full" if you also want HTML back (not needed for the file body here)

SESSION = requests.Session()
SESSION.auth = (WP_USER, WP_APP_PASSWORD)
SESSION.headers.update({"Accept": "application/json"})

def wp_url(path: str, params: Dict[str, str] = None) -> str:
    from urllib.parse import urlencode
    url = f"{WP_BASE_URL}{path}"
    if params:
        qs = urlencode(params)
        url += f"?{qs}"
    return url

@retry(wait=wait_exponential(multiplier=1, min=1, max=10), stop=stop_after_attempt(5))
def get_json(url: str) -> Dict[str, Any]:
    r = SESSION.get(url, timeout=60)
    r.raise_for_status()
    return r.json()

def iter_pages(modified_after: Optional[str], ids: Optional[str]):
    """
    Generator yielding each page JSON from /rag/v1/pages.
    If ids is provided, we do a single call and return those pages.
    Otherwise we paginate with per_page & page, honoring has_more.
    """
    if ids:
        url = wp_url("/wp-json/rag/v1/pages", {
            "ids": ids,
            "fields": FIELDS,
        })
        data = get_json(url)
        for p in data.get("pages", []):
            yield p
        return

    page = 1
    while True:
        params = {
            "per_page": str(PAGES_PER_PAGE),
            "page": str(page),
            "fields": FIELDS,
        }
        if modified_after:
            params["modified_after"] = modified_after

        url = wp_url("/wp-json/rag/v1/pages", params)
        data = get_json(url)
        pages = data.get("pages", [])
        for p in pages:
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

    lines = []
    lines.append(f"TITLE: {title}")
    lines.append(f"URL: {permalink}")
    lines.append(f"LAST_MODIFIED: {modified}")
    lines.append("")

    if headings:
        lines.append("HEADINGS:")
        for h in headings:
            lvl = h.get("level")
            txt = h.get("text", "")
            hid = h.get("id")
            if hid:
                lines.append(f"  - H{lvl} {txt}  (#{hid})")
            else:
                lines.append(f"  - H{lvl} {txt}")
        lines.append("")

    lines.append("CONTENT:")
    lines.append(content_text.strip())
    return "\n".join(lines) + "\n"

def ensure_dir(p: pathlib.Path):
    p.mkdir(parents=True, exist_ok=True)

def main():
    # Decide mode
    since = INPUT_SINCE or STORED_CURSOR or ""
    ids = UPDATE_IDS.strip() or ""

    out_dir = pathlib.Path("/tmp/rag_pages")
    ensure_dir(out_dir)

    # 1) Fetch pages
    count_pages = 0
    written_files = []
    for page in iter_pages(modified_after=since, ids=ids):
        pid = page.get("id")
        if not pid:
            continue
        text_body = page_to_text(page)
        outfile = out_dir / f"{pid}.txt"
        outfile.write_text(text_body, encoding="utf-8")
        written_files.append((outfile, page))
        count_pages += 1

    if count_pages == 0:
        print("No pages to upload (nothing changed / no IDs provided).")
        return

    print(f"Prepared {count_pages} page files in {out_dir}")

    # 2) OpenAI Vector Store: create or reuse
    client = OpenAI(api_key=OPENAI_API_KEY)
    stores = client.beta.vector_stores.list(limit=100).data
    vector_store = next((s for s in stores if s.name == VECTOR_STORE_NAME), None)
    if not vector_store:
        vector_store = client.beta.vector_stores.create(name=VECTOR_STORE_NAME)
    print(f"Using Vector Store: {vector_store.id} ({vector_store.name})")

    # 3) Upload each file and attach to store with attributes
    uploaded = 0
    for path, page in written_files:
        with open(path, "rb") as f:
            file_obj = client.files.create(file=f, purpose="assistants")  # upload to OpenAI files

        meta_attrs = {
            "source": page.get("permalink"),
            "page_id": str(page.get("id")),
            "modified": page.get("modified_date_gmt"),
            "title": page.get("title"),
            # You can add brand/pillar/section if helpful for filtering:
            "brand": page.get("brand"),
            "pillar": page.get("pillar"),
            "section": page.get("section"),
        }

        # Attach the file to the vector store with attributes
        client.beta.vector_stores.files.create(
            vector_store_id=vector_store.id,
            file_id=file_obj.id,
            attributes=meta_attrs,
        )
        uploaded += 1

    print(f"Uploaded {uploaded} files to vector store.")

if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        print("ERROR:", e)
        sys.exit(1)
