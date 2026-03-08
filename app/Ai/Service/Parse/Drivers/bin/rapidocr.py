#!/usr/bin/env python3
import sys


def main() -> int:
    if len(sys.argv) < 2:
        sys.stderr.write("usage: rapidocr.py <image_path>\n")
        return 2

    image_path = sys.argv[1]

    try:
        from rapidocr_onnxruntime import RapidOCR  # type: ignore
    except Exception:
        sys.stderr.write("RapidOCR not installed, run: pip install rapidocr-onnxruntime pillow\n")
        return 3

    try:
        engine = RapidOCR()
        result, _ = engine(image_path)
    except Exception as exc:
        sys.stderr.write(f"RapidOCR run failed: {exc}\n")
        return 4

    lines = []
    if result:
        for item in result:
            if isinstance(item, (list, tuple)) and len(item) >= 2:
                text = str(item[1]).strip()
                if text:
                    lines.append(text)

    sys.stdout.write("\n".join(lines).strip())
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

