#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT/public"
echo "AI Wire standalone reader on http://127.0.0.1:8080"
exec php -S 127.0.0.1:8080
