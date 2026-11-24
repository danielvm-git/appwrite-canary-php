#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
export SHARE_FUNCTION_ID="${SHARE_FUNCTION_ID:-share-task-php}"
bash "$ROOT/scripts/verify-todo-schema-live.sh"
bash "$ROOT/scripts/verify-todo-live.sh"
bash "$ROOT/scripts/observability-check.sh"
echo "verify-story-e09s03: OK"