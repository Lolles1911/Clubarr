# Development

Laravel 13, Filament 5 (Livewire 4), PHP 8.3.

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan filament:assets
php artisan serve
php artisan test          # all tests
vendor/bin/pint           # code style
vendor/bin/phpstan        # static analysis (Larastan)
```

## Structure

| Where | What |
|---|---|
| `app/MediaServers/` | Talking to media servers. `Contracts/` says what a server can do; `Drivers/` has one class per type; `ServerHttp` is the only HTTP client (timeouts, no redirects, safe errors). |
| `app/Services/` | What Clubarr does: `Redeemer` (an invitation from start to end), `AccessManager` (giving, pausing, removing access), `ServerSync`, `PlexLogin`, `PlexFinder`, `SiteSettings`, `SetupState`, `Audit` (every log line can become an alert: `AdminAlerts`), `Backups`, `Branding`, `NewOnServers`, `UpdateCheck`. |
| `app/Filament/Admin/` | /admin: resources (servers, invitations, members, log), Settings, widgets. |
| `app/Filament/App/` | The members' side: My access, Join (/join/{code}), Setup. |
| `app/Models/`, `app/Enums/` | Data. Secrets use the `encrypted` cast. |
| `app/Console/Commands/` | `clubarr:check-access` (hourly), `clubarr:check-servers` (every 5 minutes; also fetches "New on the server"), `clubarr:stream-limits`, `clubarr:waitlist`, `clubarr:backup` (nightly), `clubarr:check-update` (daily), `clubarr:setup-token`. |
| `app/Jobs/` | Queued work: admin alerts (Discord webhook) and messages to members. |
| `docker/` | The container: entrypoint, nginx, PHP-FPM, supervisord. |
| `lang/da.json` | Danish. A test fails if a text has no translation. |

## Adding a server type

1. A case in `App\Enums\ServerType` with its driver class.
2. A driver in `app/MediaServers/Drivers` implementing `MediaServerDriver`
   and one of `CreatesUsers`, `SharesWithPlexAccounts` or `UsesClaimLinks`
   (or a new one, handled in `AccessManager::provision()`).
3. Use `ServerHttp` for every call; refuse to change the server's
   administrators.
4. Tests with `Http::fake()` in `tests/Feature/MediaServers`.

## Rules

- Never trust the browser: Livewire public properties holding IDs are
  `#[Locked]`; actions look records up among the signed-in user's own.
  Public helper methods on Filament pages are `#[Unexposed]`.
- Secrets: the `encrypted` cast, `$hidden` on models, never in logs, the audit
  log or exceptions.
- Every change to members or servers goes through a service and is logged
  with `Audit::log()`.
- Texts in English with `__()`, Danish in `lang/da.json`.
