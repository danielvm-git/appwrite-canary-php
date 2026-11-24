#!/usr/bin/env bash
# Idempotent health + Todo schema checks (local or production URL).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BASE_URL="${BASE_URL:-https://php.danielvm.net}"

echo "== HTTP health ($BASE_URL) =="
health="$(curl -skS -o /tmp/php-health.json -w '%{http_code}' "$BASE_URL/health.json")"
if [[ "$health" != "200" ]]; then
  echo "health.json HTTP $health" >&2
  exit 1
fi
grep -q '"status"' /tmp/php-health.json && grep -q '"version"' /tmp/php-health.json
cat /tmp/php-health.json
echo

echo "== SPA shell =="
code="$(curl -skS -o /tmp/php-index.html -w '%{http_code}' "$BASE_URL/")"
[[ "$code" == "200" ]] || { echo "index HTTP $code" >&2; exit 1; }
grep -q 'id="root"' /tmp/php-index.html && grep -q 'assets/index-' /tmp/php-index.html
echo "ok"

WS="$(cd "$ROOT/.." && pwd)"
if [[ -f "$WS/.env.provision" ]]; then
  echo "== TablesDB schema (live) =="
  bash "$ROOT/scripts/verify-todo-schema-live.sh"
else
  echo "skip verify-todo-schema-live (.env.provision not found)"
fi

echo "observability-check: OK"