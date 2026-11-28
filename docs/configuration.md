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

Back up the whole folder. All files are readable only by Clubarr's user.

## Reverse proxy

Point the proxy at port 8080 and set `APP_URL`, `TRUSTED_PROXIES` and
`SESSION_SECURE_COOKIE=true`. Clubarr sends its own security headers; the
proxy should not add a conflicting `Content-Security-Policy`.

## Tracearr, Seerr and Discord

All under Admin → Settings; **Test Tracearr, Seerr and Discord** checks them.

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
