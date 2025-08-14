#!/usr/bin/env python3
import sys, json

try:
    from pypdf import PdfReader
except Exception:
    # pypdf not installed or cannot import; output empty mapping
    print("{}")
    sys.exit(0)

if len(sys.argv) < 2:
    print("{}")
    sys.exit(0)

path = sys.argv[1]
try:
    reader = PdfReader(path)
except Exception:
    print("{}")
    sys.exit(0)

labels = {}
for i in range(len(reader.pages)):
    try:
        lbl = reader.get_page_label(i)
    except Exception:
        lbl = None
    if lbl is not None:
        labels[str(i + 1)] = str(lbl)

print(json.dumps(labels, ensure_ascii=False))
