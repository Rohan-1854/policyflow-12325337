#!/usr/bin/env bash
# End-to-end walkthrough of the API against a running server.
#   php artisan serve            # in another terminal
#   bash examples/demo.sh        # BASE_URL defaults to http://127.0.0.1:8000
# Re-running is safe: repeated creates return 200 and repeated submits make no insurer call.
set -uo pipefail

BASE="${BASE_URL:-http://127.0.0.1:8000}"
REQ="$(cd "$(dirname "$0")" && pwd)/requests"

call() { # METHOD PATH [JSON_FILE]
  local method=$1 path=$2 file=${3:-}
  echo; echo ">>> $method $path ${file:+(body: $(basename "$file"))}" >&2
  if [[ -n "$file" ]]; then
    curl -s -w '\n<<< HTTP %{http_code}\n' -X "$method" "$BASE$path" -H 'Accept: application/json' -H 'Content-Type: application/json' --data "@$file"
  else
    curl -s -w '\n<<< HTTP %{http_code}\n' -X "$method" "$BASE$path" -H 'Accept: application/json'
  fi
}

# Print only the interesting fields (uses PHP, which you already have).
summary() {
  php -r '
    $raw = stream_get_contents(STDIN);
    [$json, $status] = array_pad(explode("\n<<< ", $raw, 2), 2, "");
    $b = json_decode($json, true) ?? [];
    $d = $b["data"] ?? [];
    $out = isset($b["error"]) ? ["error" => $b["error"]] : array_filter([
      "id" => $d["id"] ?? null, "status" => $d["status"] ?? null,
      "premium" => $d["premium"]["amount"] ?? null, "policy_number" => $d["policy_number"] ?? null,
      "attempts" => $d["attempt_count"] ?? null, "result" => $d["result"] ?? null,
      "meta" => $b["meta"] ?? null,
    ], fn ($v) => $v !== null);
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n", trim($status), "\n";'
}
id_of() { php -r '$b=json_decode(explode("\n<<< ", stream_get_contents(STDIN))[0], true); echo $b["data"]["id"] ?? "";'; }

echo "=== 1. Intake: create, then repeat with the same agent_id + client_request_id"
call POST /api/policy-requests "$REQ/create-example.json" | summary
call POST /api/policy-requests "$REQ/create-example.json" | summary

echo "=== 2. Validation errors (422)"
call POST /api/policy-requests "$REQ/invalid-dates.json" | summary
call POST /api/policy-requests "$REQ/invalid-plan.json" | summary

echo "=== 3. SUCCESS: submit issues exactly one policy; a repeated submit makes no insurer call"
ID=$(call POST /api/policy-requests "$REQ/scenario-success.json" | id_of)
call POST "/api/policy-requests/$ID/submit" | summary
call POST "/api/policy-requests/$ID/submit" | summary

echo "=== 4. TIMEOUT_AFTER_ISSUE: timeout -> status lookup -> ISSUED, insurer asked to issue once"
ID=$(call POST /api/policy-requests "$REQ/scenario-timeout-after-issue.json" | id_of)
call POST "/api/policy-requests/$ID/submit" | summary

echo "=== 5. TEMPORARY_FAILURE: first submit 502 (retriable), retry issues with the same premium"
ID=$(call POST /api/policy-requests "$REQ/scenario-temporary-failure.json" | id_of)
call POST "/api/policy-requests/$ID/submit" | summary
call POST "/api/policy-requests/$ID/submit" | summary

echo "=== 6. DUPLICATE_CALLBACK: pending, then the insurer sends the same event 3 times"
ID=$(call POST /api/policy-requests "$REQ/scenario-duplicate-callback.json" | id_of)
call POST "/api/policy-requests/$ID/submit" | summary
call POST /api/mock-insurer/deliver-callbacks
call GET "/api/policy-requests/$ID" | summary

echo "=== 7. List ISSUED requests"
call GET "/api/policy-requests?status=ISSUED&per_page=5" | php -r '$b=json_decode(explode("\n<<< ", stream_get_contents(STDIN))[0], true); echo json_encode($b["meta"] ?? $b, JSON_PRETTY_PRINT), "\n";'
