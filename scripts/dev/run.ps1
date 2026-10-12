# Starts everything the app needs locally, each in its own window: web server, queue worker, scheduler.
# PostgreSQL and Redis must be running (scripts\dev\postgres.ps1 start, docker compose up -d redis).
#
# Usage:  .\scripts\dev\run.ps1        (close the windows to stop)
#         .\scripts\dev\run.ps1 -Lan   also reachable from a phone on the same Wi-Fi (the mobile app): the web server then listens on
#                                      every network address, so use it only on a network you trust, and allow PHP in the Windows firewall
#
# Why three processes: approvals run their action in a queued job (queue worker), and reminders,
# expiry, the outbox relay and audit retention run from the scheduler. Without the worker, an
# approved request stays "Approved" until a worker picks it up.

param([switch]$Lan)

. "$PSScriptRoot\dev-env.ps1" | Out-Null
$root = Resolve-Path "$PSScriptRoot\..\.."
$php = (Get-Command php).Source

function Start-Window([string]$title, [string]$arguments) {
    Start-Process -FilePath powershell -WorkingDirectory $root -ArgumentList @(
        '-NoExit', '-Command', "`$Host.UI.RawUI.WindowTitle = '$title'; & '$php' $arguments"
    )
}

if ($Lan) {
    $ip = (Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -match '^(192\.168|10\.|172\.(1[6-9]|2\d|3[01]))\.' -and $_.PrefixOrigin -ne 'WellKnown' -and $_.InterfaceAlias -notmatch 'vEthernet|WSL|Docker|VirtualBox|VMware|Loopback' } | Select-Object -First 1).IPAddress
    Start-Window "DIO web (http://${ip}:8000)" 'artisan serve --host=0.0.0.0 --port=8000'
    Write-Output "Phone address for the DIO app: http://${ip}:8000"
} else {
    Start-Window 'DIO web (http://127.0.0.1:8000)' 'artisan serve --host=127.0.0.1 --port=8000'
}
Start-Window 'DIO queue worker' 'artisan queue:work redis --sleep=1 --tries=3'
Start-Window 'DIO scheduler' 'artisan schedule:work'

Write-Output 'Started: web on http://127.0.0.1:8000, queue worker, scheduler.'
