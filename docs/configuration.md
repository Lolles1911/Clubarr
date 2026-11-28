# Configuration

Clubarr is set up in the browser (Admin → Settings). The container only needs
a few environment variables:

| Variable | What |
|---|---|
| `APP_URL` | The address people open Clubarr on, e.g. `https://club.example.com`. Invitation links and mails use it. |
| `TRUSTED_PROXIES` | Behind a reverse proxy: its IP or network (`172.18.0.0/16`), so Clubarr sees the real visitor IP (for rate limits and the log) and https. `*` trusts everyone; only use it when Clubarr is not reachable except through the proxy. |
| `SESSION_SECURE_COOKIE` | `true` when Clubarr is only reached over https. Recommended. |
| `TZ` | Time zone, e.g. `Europe/Copenhagen`. |
| `PUID` / `PGID` | The user and group Clubarr runs as and owns `/config` with (Unraid: 99/100). |
| `DB_CONNECTION`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Optional MySQL/MariaDB (`mysql`) or PostgreSQL (`pgsql`) instead of the built-in SQLite. |

## /config

| File | What |
|---|---|
| `clubarr.sqlite` | The database (with SQLite). |
| `app.key` | The encryption key. API keys, the mail password and two-factor secrets are encrypted with it. **Without it they can't be read**, so back it up with the database. |
| `setup-token` | Exists only until the first admin is made. |
| `backups/` | Nightly copies of the database (the newest 7). `CLUBARR_BACKUP_PATH` puts them elsewhere. |

Back up the whole folder. All files are readable only by Clubarr's user.

## Backups

Admin → Settings → Backup.

- **Automatic**: every night at 03:41 a copy of the database goes to
  `/config/backups`; the newest 7 are kept. **Make one now** makes one.
- **Download a backup**: the database and the encryption key in one file,
  locked with a password you choose (it is not saved anywhere). With it you
  can move Clubarr to a new machine: install it, make the first admin, then
  **Restore from a file**.
- **Restoring** replaces everything. The current database is copied to
  `/config/backups` first, and everyone signs in again. A backup from another
  installation has its secrets moved to this one's key; nothing to restart.
- With MySQL/MariaDB or PostgreSQL, back up the database with its own tools
  (and keep `app.key`).

## Notifications

Admin → Settings → Notifications: paste a Discord webhook URL (the channel →
Edit channel → Integrations → Webhooks → New webhook → Copy webhook URL) and
tick what you want to hear about: a new member, a media server that stops
answering (checked every 5 minutes) and comes back, someone on the waiting
list, a stream stopped for the limit, a new Clubarr version. **Send a test
message** checks it.

## Messages to members

Admin → Messages → New message: a title and text, to all members or one
server's members, by mail, as a Discord message (members who linked Discord)
and/or as a banner on their page until a date.

## Look

Admin → Settings → General: an accent colour and a logo (PNG, JPEG or WebP,
at most 300 KB). **Tell me when a new Clubarr is out** checks GitHub's
public list of releases once a day and shows a notice on the dashboard.

## Reverse proxy

Point the proxy at port 8080 and set `APP_URL`, `TRUSTED_PROXIES` and
`SESSION_SECURE_COOKIE=true`. Clubarr sends its own security headers; the
proxy should not add a conflicting `Content-Security-Policy`.

## Tracearr, Seerr and Discord

All under Admin → Settings → Integrations; each has its own **Test** button.

- **Tracearr**: its address and a public API key (Tracearr → Settings →
  Data & API, starts with `trr_pub_`). Gives My devices, stream limits,
  numbers and posters.
- **Overseerr / Jellyseerr**: its address and API key (Seerr → Settings →
  General). Members request as their own Seerr user, so it must be connected
  to the same Jellyfin/Emby or Plex. If Seerr has "CSRF Protection" on, turn
  it off (Settings → Network), or API calls are refused.
- **Discord**: an application at discord.com/developers with a bot. Add the
  redirect shown under Settings (`<APP_URL>/discord/callback`) under OAuth2,
  invite the bot to your server with **Manage Roles**, and put the bot's
  role above the members' role. Enter the application ID, client secret,
  bot token, your server's ID and the role's ID (Discord → Settings →
  Advanced → Developer Mode, then right-click → Copy ID).

## Member limit and waiting list

Set **Members at most** on a server. Under Settings → Waiting list choose
the invitation the waiting list copies (its servers, libraries and limits).
Share `<APP_URL>/waitlist`. Every 10 minutes, when there is room, the first
in line gets an invitation by mail, valid 48 hours; unused, it goes to the
next. A waiting-list invitation keeps its seat while it is valid.

## Mail

Admin → Settings → Mail: an SMTP server (STARTTLS on 587 or TLS on 465), the
user name and password, and a from address. **Send a test mail to me** checks
it. Without mail, Clubarr works, but members don't get welcome mails,
password links or reminders.
