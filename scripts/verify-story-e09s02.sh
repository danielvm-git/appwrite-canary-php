#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
test -d functions/share-task
test -f functions/share-task/src/index.php
npm run test:function
echo "verify-story-e09s02: OK"