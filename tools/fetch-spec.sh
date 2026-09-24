#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 ]]; then
    echo "Usage: $0 <outdir>" >&2
    exit 1
fi

outdir="$1"
spec_url="https://api.typesafe.ai/openapi.json"

mkdir -p "$outdir"

curl -fsS "$spec_url" -o "$outdir/openapi.json"
jq -S . "$outdir/openapi.json" > "$outdir/openapi.normalized.json"

version=$(jq -r '.info.version' "$outdir/openapi.json")
fetched_at=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

if command -v sha256sum >/dev/null 2>&1; then
    sha256=$(sha256sum "$outdir/openapi.json" | awk '{print $1}')
else
    sha256=$(shasum -a 256 "$outdir/openapi.json" | awk '{print $1}')
fi

{
    echo "info.version=${version}"
    echo "fetched_at=${fetched_at}"
    echo "sha256=${sha256}"
} > "$outdir/VERSION"
