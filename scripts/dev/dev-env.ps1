# Puts PHP 8.3 (installed with `winget install PHP.PHP.8.3`) first on PATH for this terminal only.
# XAMPP's PHP 8.2 sits earlier on the machine PATH and cannot run Laravel 13.
#
# Usage (dot-source so it affects the current session):  . .\scripts\dev\dev-env.ps1

$package = Get-ChildItem "$env:LOCALAPPDATA\Microsoft\WinGet\Packages" -Directory -Filter 'PHP.PHP.8.3*' -ErrorAction SilentlyContinue |
    Select-Object -First 1

if (-not $package) {
    Write-Error 'PHP 8.3 not found. Install it first: winget install PHP.PHP.8.3  (see docs/development/setup.md)'
    return
}

$env:Path = "$($package.FullName);$env:Path"
php -v | Select-Object -First 1
