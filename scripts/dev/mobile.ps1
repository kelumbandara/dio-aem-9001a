# Starts the phone app's development server (Expo). Install "Expo Go" on the phone, join the same Wi-Fi as this PC, scan the QR code.
#
# Usage:  .\scripts\dev\mobile.ps1          first run installs the app's packages (a few minutes)
#
# Run the website for the phone with:  .\scripts\dev\run.ps1 -Lan   and type the address it prints into the app's "Server address".
# Remote push notifications do not work inside Expo Go; they need a built app (docs/architecture/mobile.md).

$root = Resolve-Path "$PSScriptRoot\..\.."
Set-Location "$root\mobile"
if (-not (Test-Path node_modules)) { npm install }
npx expo start --go
