# Changelog

What changed in each version of Clubarr. Each section is also the text of its
release on GitHub (`.github/release-notes.sh` puts it there).

## 0.1.0-beta (2026-11-27)

The first beta: invitations, accounts and a member page for your Plex,
Jellyfin, Emby and Silo servers, in one Docker container.

### Getting started
- **Setup in the browser** on the first start, with a one-time **setup
  token** printed in the container's log, so a fresh Clubarr on the internet
  can't be claimed by someone else. You choose the site's name and make the
  first admin; after that, /setup is gone.
- **"Getting started"** on the admin dashboard: add a media server, set up
  mail, make the first invitation.

### Media servers
- **Jellyfin, Emby, Plex and Silo.** The address and key are checked before a
  server is saved, and its libraries are read. **Check** on each server tests
  it again and refreshes the libraries; the last result is shown.
- Two addresses per server: the one Clubarr uses (often on your network) and
  the one members open.
- **Import user**: link a user that already exists on a server to a new or
  existing member, so your current users can sign in to Clubarr too (new
  members get a mail to choose their password).

### Invitations
- Choose the **servers and libraries**, how long the **link is valid**, how
  many times it **can be used**, and for how many **days the access lasts**
  (or until you remove it).
- The link is shown **once** when it is made (with a copy button); it is
  stored only as a hash. **New link** replaces a lost one; **Withdraw** stops
  it.

### Joining
- `/join/<code>`: the person makes **their own account** (email and
  password). A signed-in member can redeem another invitation to add servers
  to their account.
- **Jellyfin and Emby**: their user is made with the user name they choose
  and only the invitation's libraries; a taken user name is shown on the form.
- **Plex**: "Sign in with Plex"; the server is shared with their own Plex
  account and the share is accepted for them.
- **Silo**: a one-time Silo link where they choose their user; Clubarr
  notices when it is used.
- All or nothing: if a server fails, what was made is removed again and the
  invitation can still be used.

### My access (members)
- Their servers with status, user name and libraries, an **Open** button,
  when their access ends, and a **new password** for watching (Silo: a
  password link). Your own "getting started" text (Settings) below.
- Profile with optional **two-factor sign-in**.

### Admin
- **Members**: access end (change, or no end), disable/enable (pauses or
  resumes their users on the servers), send a password link, remove
  (removes their users from the servers too), add admins.
- **Settings**: site name, language (English/Danish), the members' "getting
  started" text, and **mail** (SMTP) with **Send a test mail to me**.
- **Log**: who did what and when, for setup, servers, invitations, members,
  passwords and settings.
- Numbers on the dashboard: members, open invitations, members still to
  finish Silo/Plex, access ending within 7 days, servers with a problem.

### Automatic
- Every hour: a mail **a few days before** access ends, and when it has
  ended the member's users are **paused** (with a mail). Taken Silo links and
  Plex shares are picked up.
- Mails: welcome, access ends soon, access ended, password links.

### Security
- **Admins must use two-factor sign-in** (authenticator app, with recovery
  codes). No open registration.
- Invitation codes are stored only as a hash; API keys, the mail password and
  two-factor secrets are **encrypted** with the key in `/config/app.key`.
- **Clubarr never changes a server's administrators.**
- Calls to media servers: no redirects, short timeouts, tokens only in
  headers, errors without keys.
- Rate limits on sign-in, invitation codes, joining, Plex sign-in and password
  changes. Security headers (CSP, no framing, HSTS over https) on every page.
  No outside services from the browser.
- Members can't reach /admin, and only ever their own server users.
- See [docs/security.md](docs/security.md).

### Install
- One Docker image, `ghcr.io/lolles1911/clubarr:beta` (amd64 and arm64):
  nginx, PHP, the scheduler and the queue, as an unprivileged user; data in
  `/config`; `PUID`/`PGID` for Unraid. SQLite by default, MySQL/PostgreSQL
  optional.
- [Unraid template](unraid/clubarr.xml) and a
  [compose example](docker-compose.example.yml).

### Known limits in this beta
- Tested against a stand-in Jellyfin; **Plex, Emby and Silo are tested only
  with simulated answers**. Plex's sharing API isn't public, so Plex is the
  most likely to need fixes.
- Plex has no "disabled": pausing removes the share, and resuming shares again
  (the member accepts it in Plex).
- Not yet: Tracearr (devices, stream limits, stats, posters), Seerr requests,
  Sonarr/Radarr "coming soon", Discord, waiting list. They come from Paywarr
  in the next versions.
