#!/usr/bin/env python3
import argparse
import contextlib
import io
import json
import sys
import time
from pathlib import Path
from typing import List, Optional

def parse_page_num_list(raw: str) -> Optional[List[int]]:
    raw = (raw or "").strip()
    if not raw:
        return None

    pages = []
    for item in raw.split(","):
        item = item.strip()
        if not item:
            continue
        if not item.isdigit():
            continue
        page = int(item)
        if page > 0:
            pages.append(page)
    if not pages:
        return None
    return sorted(set(pages))


def run_extract(extractor, pdf_path: Path, force_ocr: bool, page_num_list: Optional[List[int]]):
    with contextlib.redirect_stdout(io.StringIO()):
        return extractor(str(pdf_path), force_ocr=force_ocr, page_num_list=page_num_list)

def main() -> int:
    parser = argparse.ArgumentParser(description="Extract text from PDF via RapidOCRPDF")
    parser.add_argument("pdf_path", help="pdf file path")
    parser.add_argument("--force-ocr", default="0", help="1/0")
    parser.add_argument("--page-num-list", default="", help="comma separated pages")
    args = parser.parse_args()

    pdf_path = Path(args.pdf_path)
    if not pdf_path.exists():
        sys.stderr.write(f"PDF file not found: {pdf_path}\n")
        return 2

    script_dir = str(Path(__file__).resolve().parent)
    if script_dir in sys.path:
        sys.path.remove(script_dir)

    try:
        from rapidocr_pdf import RapidOCRPDF  # type: ignore
    except Exception:
        sys.stderr.write("RapidOCRPDF not installed, run: pip install rapidocr-pdf\n")
        return 3

    force_ocr = str(args.force_ocr).strip().lower() in {"1", "true", "yes", "on"}
    page_num_list = parse_page_num_list(args.page_num_list)

    started_at = time.time()
    try:
        extractor = RapidOCRPDF()
        result = run_extract(extractor, pdf_path, force_ocr, page_num_list)
    except Exception as exc:
        sys.stderr.write(f"RapidOCRPDF run failed: {exc}\n")
        return 4

    pages = []
    lines = []
    for item in result or []:
        if not isinstance(item, (list, tuple)) or len(item) < 3:
            continue
        page = item[0]
        text = str(item[1] or "").strip()
        confidence = str(item[2] or "").strip()
        if text:
            lines.append(text)
        pages.append(
            {
                "page": int(page) if str(page).isdigit() else page,
                "chars": len(text),
                "avg_confidence": confidence,
                "mode": "direct" if confidence in {"", "N/A", "n/a", "None"} else "ocr",
            }
        )

    payload = {
        "ok": True,
        "text": "\n\n".join(lines).strip(),
        "page_count": len(pages),
        "pages": pages,
        "duration_ms": int((time.time() - started_at) * 1000),
        "dpi": 0,
        "fallback_used": False,
    }
    sys.stdout.write(json.dumps(payload, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
