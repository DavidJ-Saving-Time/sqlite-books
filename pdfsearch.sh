#!/usr/bin/env bash
set -euo pipefail

# Config
SRC_DIR="${PDFSEARCH_DIR:-/srv/http/pdf}"           # change or export PDFSEARCH_DIR
CACHE_DIR="${PDFSEARCH_CACHE:-$HOME/.cache/pdfsearch}"

need() { command -v "$1" >/dev/null 2>&1 || { echo "Missing dependency: $1" >&2; exit 1; }; }

usage() {
  cat <<EOF
pdfsearch — index & search many PDFs from the CLI

Usage:
  pdfsearch index [DIR]      Extract/update text cache (incremental).
  pdfsearch rebuild [DIR]    Wipe and rebuild cache from scratch.
  pdfsearch search "query"   Search with ripgrep, outputs PDF:line:match.
  pdfsearch fzf "query"      Interactive search with preview; Enter opens PDF.

Notes:
  - Default source DIR: $SRC_DIR  (override: export PDFSEARCH_DIR=/path)
  - Cache lives in: $CACHE_DIR    (override: export PDFSEARCH_CACHE=/path)
Deps:
  - required: pdftotext (poppler), rg (ripgrep)
  - optional for fzf mode: fzf, bat, xdg-open
EOF
}

# Turn a source PDF path into its cache TXT path
to_cache() {
  local src="$1"
  local rel="${src#"$SRC_DIR"/}"
  echo "$CACHE_DIR/$rel.txt"
}

# Turn a cache TXT path back into its source PDF path
to_src() {
  local cache="$1"
  local rel="${cache#"$CACHE_DIR"/}"
  rel="${rel%.txt}.pdf"
  echo "$SRC_DIR/$rel"
}

index_dir() {
  local dir="${1:-$SRC_DIR}"
  need pdftotext
  mkdir -p "$CACHE_DIR"
  echo "Indexing PDFs from: $dir"
  # find all PDFs and (re)extract if missing or out-of-date
  IFS=$'\n'
  find "$dir" -type f -iname '*.pdf' -print0 | while IFS= read -r -d '' f; do
    out="$(to_cache "$f")"
    mkdir -p "$(dirname "$out")"
    if [[ ! -e "$out" || "$f" -nt "$out" ]]; then
      printf '  → %s\n' "$f"
      # -layout keeps line-ish structure, -nopgbrk avoids page-break form feeds
      pdftotext -q -layout -nopgbrk "$f" "$out" || {
        echo "     (warning: failed to extract text for $f)" >&2
      }
    fi
  done
  echo "Done."
}

rebuild_dir() {
  local dir="${1:-$SRC_DIR}"
  echo "Rebuilding cache at: $CACHE_DIR"
  rm -rf "$CACHE_DIR"
  index_dir "$dir"
}

search_query() {
  local q="${1:-}"; [[ -z "$q" ]] && { echo "Need a query." >&2; exit 1; }
  need rg
  [[ -d "$CACHE_DIR" ]] || { echo "No cache yet. Run: pdfsearch index" >&2; exit 1; }
  # Search cache, then map paths back to PDFs
  rg -n -i -S -C2 --color=always -- "$q" "$CACHE_DIR" \
    | sed -E "s#^$(printf '%q' "$CACHE_DIR")/(.*)\\.txt:#$SRC_DIR/\\1.pdf:#"
}

fzf_query() {
  local q="${1:-}"; [[ -z "$q" ]] && { echo "Need a query." >&2; exit 1; }
  need rg; need fzf; need bat
  [[ -d "$CACHE_DIR" ]] || { echo "No cache yet. Run: pdfsearch index" >&2; exit 1; }

  # Pipe search to fzf. Enter opens the PDF (xdg-open) and quits fzf.
  rg -n -i -S -C2 --color=always -- "$q" "$CACHE_DIR" \
    | sed -E "s#^$(printf '%q' "$CACHE_DIR")/(.*)\\.txt:#$SRC_DIR/\\1.pdf:#" \
    | fzf --ansi --delimiter ':' --with-nth=1,2,3.. \
           --preview '
             file="$(cut -d: -f1 <<< {})"
             line="$(cut -d: -f2 <<< {})"
             cache_file="${file/#'"$SRC_DIR"'/'"$CACHE_DIR"'}"
             cache_file="${cache_file%.pdf}.txt"
             if [[ -f "$cache_file" ]]; then
               # show ~200 lines around the hit
               start=$(( (line>100?line-100:1) ))
               end=$(( line+100 ))
               bat --style=plain --paging=never --line-range "$start:$end" "$cache_file"
             else
               echo "(no text cache for preview)"
             fi
           ' \
           --bind "enter:execute-silent(xdg-open {1})+abort"
}

cmd="${1:-}"; shift || true
case "$cmd" in
  index)   index_dir "${1:-}";;
  rebuild) rebuild_dir "${1:-}";;
  search)  search_query "$*";;
  fzf)     fzf_query "$*";;
  ""|-h|--help) usage;;
  *) echo "Unknown command: $cmd" >&2; usage; exit 1;;
esac

