#!/usr/bin/env bash
# Idempotent: create the share-task-php function (if missing) and deploy functions/share-task.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WS="$(cd "$ROOT/.." && pwd)"
if [[ -z "${APPWRITE_API_KEY:-}" && -f "$WS/.env.provision" ]]; then
  # shellcheck disable=SC1091
  source "$WS/.env.provision"
fi
: "${APPWRITE_ENDPOINT:?}"
: "${APPWRITE_PROJECT_ID:?}"
: "${APPWRITE_API_KEY:?}"

FUNCTION_ID="share-task-php"
SRC="$ROOT/functions/share-task"
auth=(-H "X-Appwrite-Project: $APPWRITE_PROJECT_ID" -H "X-Appwrite-Key: $APPWRITE_API_KEY")
json() { python3 -c "import json,sys; print(json.load(sys.stdin).get('$1',''))"; }

config='{
  "name":"Share task (PHP)","runtime":"php-8.0","execute":["users"],
  "entrypoint":"src/index.php","commands":"composer install --no-dev --no-interaction",
  "scopes":["users.read","rows.read","rows.write"],"timeout":15,"logging":true
}'

existing="$(curl -skS "${auth[@]}" "$APPWRITE_ENDPOINT/functions/$FUNCTION_ID")"
if [[ "$(json '$id' <<<"$existing")" == "$FUNCTION_ID" ]]; then
  echo "function exists — updating config"
  curl -skS -X PUT "${auth[@]}" -H "Content-Type: application/json" \
    "$APPWRITE_ENDPOINT/functions/$FUNCTION_ID" -d "$config" | head -c 200
else
  echo "creating function"
  body="$(python3 -c "import json,sys; d=json.loads(sys.argv[1]); d['functionId']='$FUNCTION_ID'; print(json.dumps(d))" "$config")"
  curl -skS -X POST "${auth[@]}" -H "Content-Type: application/json" \
    "$APPWRITE_ENDPOINT/functions" -d "$body" | head -c 200
fi
echo

ARCHIVE="$(mktemp -t share-task-php.XXXXXX.tar.gz)"
trap 'rm -f "$ARCHIVE"' EXIT
tar -czf "$ARCHIVE" -C "$SRC" --exclude vendor --exclude tests --exclude '*.cache' .

deploy="$(curl -skS -X POST "${auth[@]}" "$APPWRITE_ENDPOINT/functions/$FUNCTION_ID/deployments" \
  -F "activate=true" -F "entrypoint=src/index.php" -F "commands=composer install --no-dev --no-interaction" \
  -F "code=@$ARCHIVE;type=application/gzip;filename=code.tar.gz")"
DEPLOY_ID="$(json '$id' <<<"$deploy")"
[[ -n "$DEPLOY_ID" ]] || { echo "deployment failed: $deploy" >&2; exit 1; }
echo "deployment=$DEPLOY_ID"

for _ in $(seq 1 60); do
  status="$(curl -skS "${auth[@]}" "$APPWRITE_ENDPOINT/functions/$FUNCTION_ID/deployments/$DEPLOY_ID" | json status)"
  case "$status" in
    ready) echo "deploy-share-function: OK"; exit 0 ;;
    failed) echo "build failed" >&2
      curl -skS "${auth[@]}" "$APPWRITE_ENDPOINT/functions/$FUNCTION_ID/deployments/$DEPLOY_ID" | json buildLogs >&2
      exit 1 ;;
  esac
  sleep 5
done
echo "timeout waiting for $DEPLOY_ID" >&2
exit 1