# End-to-end walkthrough for Windows PowerShell (5.1 or 7). Uses the curl.exe that ships with Windows 10+.
#   php artisan serve                       # in another terminal
#   powershell -ExecutionPolicy Bypass -File examples\demo.ps1
param([string]$BaseUrl = "http://127.0.0.1:8000")

$Req = Join-Path $PSScriptRoot "requests"

function Invoke-Api([string]$Method, [string]$Path, [string]$File = "") {
    Write-Host ""
    Write-Host ">>> $Method $Path $(if ($File) { "(body: $File)" })" -ForegroundColor Cyan
    $curlArgs = @("-s", "-w", "`n%{http_code}", "-X", $Method, "$BaseUrl$Path", "-H", "Accept: application/json")
    if ($File) { $curlArgs += @("-H", "Content-Type: application/json", "--data", "@$(Join-Path $Req $File)") }
    $raw = & curl.exe @curlArgs
    $lines = ($raw -join "`n").TrimEnd() -split "`n"
    $status = $lines[-1]
    $body = ($lines[0..($lines.Length - 2)] -join "`n") | ConvertFrom-Json
    $d = $body.data
    if ($body.error) {
        Write-Host "<<< HTTP $status  error=$($body.error.code): $($body.error.message)" -ForegroundColor Yellow
    } elseif ($d -is [array]) {
        Write-Host "<<< HTTP $status  $($d.Count) item(s); meta=$($body.meta | ConvertTo-Json -Compress)"
    } else {
        Write-Host "<<< HTTP $status  id=$($d.id) status=$($d.status) premium=$($d.premium.amount) policy=$($d.policy_number) result=$($d.result) meta=$($body.meta | ConvertTo-Json -Compress)" -ForegroundColor Green
    }
    return $body
}

Write-Host "=== 1. Intake + idempotent repeat"
Invoke-Api POST /api/policy-requests create-example.json | Out-Null
Invoke-Api POST /api/policy-requests create-example.json | Out-Null

Write-Host "=== 2. Validation errors"
Invoke-Api POST /api/policy-requests invalid-dates.json | Out-Null
Invoke-Api POST /api/policy-requests invalid-plan.json | Out-Null

Write-Host "=== 3. SUCCESS + repeated submit"
$id = (Invoke-Api POST /api/policy-requests scenario-success.json).data.id
Invoke-Api POST "/api/policy-requests/$id/submit" | Out-Null
Invoke-Api POST "/api/policy-requests/$id/submit" | Out-Null

Write-Host "=== 4. TIMEOUT_AFTER_ISSUE"
$id = (Invoke-Api POST /api/policy-requests scenario-timeout-after-issue.json).data.id
Invoke-Api POST "/api/policy-requests/$id/submit" | Out-Null

Write-Host "=== 5. TEMPORARY_FAILURE then retry"
$id = (Invoke-Api POST /api/policy-requests scenario-temporary-failure.json).data.id
Invoke-Api POST "/api/policy-requests/$id/submit" | Out-Null
Invoke-Api POST "/api/policy-requests/$id/submit" | Out-Null

Write-Host "=== 6. DUPLICATE_CALLBACK"
$id = (Invoke-Api POST /api/policy-requests scenario-duplicate-callback.json).data.id
Invoke-Api POST "/api/policy-requests/$id/submit" | Out-Null
$deliveries = Invoke-Api POST /api/mock-insurer/deliver-callbacks
$deliveries.data | ForEach-Object { Write-Host "    delivery $($_.delivery): $($_.result)" }
Invoke-Api GET "/api/policy-requests/$id" | Out-Null

Write-Host "=== 7. List ISSUED"
Invoke-Api GET "/api/policy-requests?status=ISSUED&per_page=5" | Out-Null
