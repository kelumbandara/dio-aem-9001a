# Start/stop/inspect the portable PostgreSQL 16 used for local development on Windows.
# It is not a Windows service, so start it after each reboot.
#
# Usage:  .\scripts\dev\postgres.ps1 start | stop | status
#
# Layout (see docs/development/setup.md): binaries in %LOCALAPPDATA%\dio-tools\pg16, data in ...\data.

param(
    [Parameter(Mandatory = $true, Position = 0)]
    [ValidateSet('start', 'stop', 'status')]
    [string]$Action
)

$root = Join-Path $env:LOCALAPPDATA 'dio-tools'
$pgCtl = Join-Path $root 'pg16\bin\pg_ctl.exe'
$data = Join-Path $root 'data'
$log = Join-Path $root 'pg.log'

if (-not (Test-Path $pgCtl)) {
    Write-Error "PostgreSQL not found at $pgCtl. Follow docs/development/setup.md first."
    return
}

function Test-PostgresPort {
    try {
        $client = New-Object System.Net.Sockets.TcpClient
        $client.Connect('127.0.0.1', 5432)
        $client.Close()
        return $true
    } catch {
        return $false
    }
}

switch ($Action) {
    'start' {
        if (Test-PostgresPort) { Write-Output 'PostgreSQL is already running on port 5432.'; return }
        # No -w: waiting makes pg_ctl hang on inherited output handles; poll the port instead.
        Start-Process -FilePath $pgCtl -ArgumentList @('start', '-D', "`"$data`"", '-l', "`"$log`"") -WindowStyle Hidden
        for ($i = 0; $i -lt 30; $i++) {
            Start-Sleep -Milliseconds 500
            if (Test-PostgresPort) { Write-Output 'PostgreSQL started on port 5432.'; return }
        }
        Write-Error "PostgreSQL did not come up within 15s. See $log"
    }
    'stop' {
        & $pgCtl stop -D $data -m fast
    }
    'status' {
        if (Test-PostgresPort) { Write-Output 'PostgreSQL is running on port 5432.' } else { Write-Output 'PostgreSQL is not running.' }
    }
}
