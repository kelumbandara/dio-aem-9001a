# Developer setup

Tested on Windows 11 with XAMPP already installed (PHP 8.2, left untouched). Linux/macOS: install
PHP 8.3, PostgreSQL 16, Composer and Node 22 with your package manager and skip to **3. Project**.

## 1. Tools

| Tool | Version | Notes |
|---|---|---|
| PHP | 8.3+ | extensions: `pdo_pgsql pgsql mbstring intl openssl curl fileinfo zip gd sodium bcmath` |
| PostgreSQL | 16 | tests and the app both require it (ADR-0003) |
| Composer | 2.x | |
| Node | 22 | Vite 7 + Tailwind 4 |

### PHP 8.3 on Windows (beside XAMPP)

```powershell
winget install PHP.PHP.8.3
```

The package is a plain zip, so enable the extensions yourself: copy `php.ini-development` to
`php.ini` in the install folder (`%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.3_*`), then
uncomment `extension_dir = "ext"` and the extensions listed above.

XAMPP's PHP 8.2 comes first on the machine `PATH` and cannot run Laravel 13. In every new terminal:

```powershell
. .\scripts\dev\dev-env.ps1     # puts PHP 8.3 first for this terminal only; prints the version
```

### PostgreSQL 16 on Windows

Preferred: the official installer from <https://www.postgresql.org/download/windows/> (runs as a
service). `winget install PostgreSQL.PostgreSQL.16` downloads from EnterpriseDB and needs
administrator rights; on the first dev machine that download returned HTTP 403.

**Portable alternative (no admin rights)** that the first dev machine uses: PostgreSQL 16 binaries
from Maven Central (`io.zonky.test.postgres:embedded-postgres-binaries-windows-amd64`, a jar that
contains a `.txz`).

```powershell
$base = "$env:LOCALAPPDATA\dio-tools"; New-Item -ItemType Directory -Force "$base\dl","$base\pg16" | Out-Null
Invoke-WebRequest "https://repo1.maven.org/maven2/io/zonky/test/postgres/embedded-postgres-binaries-windows-amd64/16.15.0/embedded-postgres-binaries-windows-amd64-16.15.0.jar" -OutFile "$base\dl\pg.zip"
Expand-Archive "$base\dl\pg.zip" "$base\dl\jar"
tar -xJf "$base\dl\jar\postgres-windows-x86_64.txz" -C "$base\pg16"
# one-off: create the cluster (choose your own superuser password; keep it out of the repo)
Set-Content "$base\pw.tmp" -NoNewline '<choose-a-password>'
& "$base\pg16\bin\initdb.exe" -D "$base\data" -U postgres -A scram-sha-256 --pwfile="$base\pw.tmp" -E UTF8 --locale=C
Remove-Item "$base\pw.tmp"
```

It is not a Windows service. Start it after each reboot:

```powershell
.\scripts\dev\postgres.ps1 start     # or: stop | status
```

The portable build has no `psql`; create databases through PDO (below) or any GUI client.

## 2. Databases

Two databases on the same server: `dio_erp` (development) and `dio_erp_test` (automated tests; wiped by
the test run). With any client:

```sql
CREATE DATABASE dio_erp;
CREATE DATABASE dio_erp_test;
```

## 3. Project

```powershell
composer install
copy .env.example .env
php artisan key:generate
# edit .env: DB_PASSWORD (and DB_USERNAME/DB_HOST if different). .env is git-ignored; never commit it.
php artisan migrate --seed          # prints a generated demo admin password once (local/testing only)
npm install
npm run build                       # or `npm run dev` while working on views
php artisan serve                   # http://127.0.0.1:8000
```

The test database uses the same host/user/password as `.env` and the name `dio_erp_test` (set in
`phpunit.xml`). CI overrides everything with environment variables.

First login: `admin@dio.test` with the password printed by the seeder. The demo admin holds the
`super-admin` role, which requires authenticator-app (TOTP) enrolment before anything else works;
the app walks you through it. Re-run `php artisan db:seed --class="App\Modules\Core\Database\Seeders\DemoAdminSeeder"`
for a new password. The seeder refuses to run outside `local`/`testing`.

## 4. Commands

| Command | What it does |
|---|---|
| `composer test` | Pest: unit, feature, architecture, concurrency (needs PostgreSQL) |
| `composer lint` / `composer lint:fix` | Pint check / fix |
| `composer analyse` | Larastan level 6 |
| `composer check` | lint + analyse + test (what CI runs, plus `composer audit`) |
| `npm run dev` / `npm run build` | Vite dev server / production assets |
| `php artisan idempotency:prune` | delete expired idempotency keys (scheduled daily; needs `schedule:run`) |

## 5. Troubleshooting

- `Your Composer dependencies require a PHP version ">= 8.3"`: the terminal is using XAMPP's PHP;
  dot-source `scripts/dev/dev-env.ps1`.
- `could not find driver`: `pdo_pgsql` is not enabled in the `php.ini` of the PHP you are running
  (`php --ini` shows which file is loaded).
- Tests fail with connection refused: PostgreSQL is not running (`.\scripts\dev\postgres.ps1 start`).
- `Vite manifest not found` in the browser: run `npm run build` or keep `npm run dev` running.

## 6. Redis (from Sprint 2: queues, cache, rate limits)

Redis runs in Docker (Docker Desktop with WSL2). The client is `predis` (pure PHP), so no PHP extension is needed.

```powershell
# one-off: put a password in .env  ->  REDIS_PASSWORD=<random>   (REDIS_CLIENT=predis is already set)
docker compose up -d redis      # listens on 127.0.0.1:6379 only, password required, data persisted in a volume
docker compose ps               # STATUS should say (healthy)
docker compose down             # stop (data volume is kept)
```

Laravel Horizon needs the `pcntl`/`posix` extensions, which do not exist on Windows PHP. Locally use
`php artisan queue:work redis`; Horizon runs in the Linux container (Coolify) and in Docker locally.
