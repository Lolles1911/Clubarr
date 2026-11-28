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

Admin → System → Notifications: tick what you want to hear about (a new
member, a media server that stops answering and comes back, someone on the
waiting list, a stream stopped for the limit, an account locked after
failed sign-ins, a new Clubarr version), and where:

- **Discord**: a webhook URL (the channel → Edit channel → Integrations →
  Webhooks → New webhook → Copy webhook URL).
- **ntfy**: the topic's address (e.g. `https://ntfy.sh/a-long-random-name`)
  and, for your own ntfy, an access token.
- **Telegram**: a bot token from @BotFather and the chat's ID.
- **Gotify**: its address and an application token.
- **Webhook**: any address; Clubarr posts JSON `{"site", "text", "time"}`.
  With a secret, `X-Clubarr-Signature: sha256=<HMAC-SHA256 of the body>`.

**Send a test message** tries every channel that is set up.

## Mail texts

Admin → System → Mail texts: the subject and words of the welcome mail and
the "access ends soon" and "access has ended" mails. `{name}`, `{site}`
and `{date}` are filled in; an empty field uses Clubarr's own text.

## API

Admin → System → API keys. See [api.md](api.md).

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

## Tracearr, Tautulli, Sonarr, Radarr, Seerr and Discord

All under Admin → System → Integrations; each has its own **Test** button.

- **Tautulli** (Plex): its address and API key. Used for what members watch
  when Tracearr is not set up.
- **Sonarr and Radarr**: their addresses and API keys. Gives "Coming soon"
  on the members' page.

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

## Invitations

Admin → Settings → Invitations: the welcome text on every invitation page,
the reminder (days; 0 = off), and members inviting friends (a number a
month and the template their invitations copy). Templates are made under
Invitations → New → "Save as a template instead". **Many at once** on the
invitations list mails a personal single-use link to each email.

## Automation

Admin → Settings → Automation: **inactive members** (nothing watched for
the days set: a mail a week before, then paused; last watched comes from
Jellyfin/Emby, or Tracearr, which is needed for Plex) and **after access
ends** (delete their users on the servers after the days set). Both run
daily at 05:13; 0 turns them off. Servers are synced every 15 minutes.

**Old data** is removed every night after the days set (0 keeps it): the
log (365), invitations that ended or were used up (90; never templates),
and decided requests, closed reports and old waiting-list entries (180).

## Media server clean-up

Media servers → **Clean up** (shown when a server has users no member has):
choose which of them to delete on the server. Server administrators are
never listed; to keep someone, use **Import users** instead.

## Groups

Admin → Members → Groups: e.g. "Family" with rules for everyone in it
(access never ends, streams at once, requests a week, may request). In
several groups, a member gets the most generous. An invitation can put new
members in a group.

## Sign-in security

After 10 failed sign-ins, an account is locked for 15 minutes. Settings →
Security → "Mail people when their account is signed in to from a new
device". People see where they are signed in on their profile and can
sign out everywhere else.

## Applications

Settings → Invitations → Apply to join: open the public `/apply` page and
choose the template an approved application's invitation copies. The
sign-in page then shows "Apply to join". Applications are under
Admin → Applications.

## Single sign-on

Settings → Security → Single sign-on. In your provider (Authentik,
Authelia, Keycloak, ...) make an OpenID Connect client (confidential, code
flow) with the redirect URI shown, scopes `openid email profile`, and
copy the issuer URL, client ID and secret into Clubarr. People sign in to
their existing account by a verified email; SSO never makes accounts. Turn
on "Members may use it too" to offer it to members. Require two-factor at
the provider: an SSO sign-in skips Clubarr's own.
