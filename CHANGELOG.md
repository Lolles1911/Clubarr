# Changelog

What changed in each version of Clubarr. Each section is also the text of its
release on GitHub (`.github/release-notes.sh` puts it there).

## 0.3.1-beta (2026-11-28)

### Plex, like Tracearr
- **Sign in with Plex** is back on the server form, for the token: approve
  Clubarr on plex.tv, and **Find Plex** lists your servers with every
  address Plex knows. No token to look up and paste. On a phone, come back
  to the Clubarr tab after approving; it carries on by itself.
- The token never goes into the form (or the browser): it waits in the
  encrypted session until the server is saved.

## 0.3.0-beta (2026-11-28)

### For members
- **A new front page**: a greeting, a big button per server to start
  watching, and posters of what is **new on the server**, straight from
  Jellyfin, Emby and Plex (only from the member's own libraries; no Tracearr
  needed). With Tracearr, "Continue watching" and "Popular now" too.
- **Better on phones**: buttons fill the width, posters scroll sideways, and
  the admin tables leave out the less important columns on small screens.

### For admins
- **Messages to members**: by mail, as a Discord message and/or as a banner
  on their page until a date; to everyone or one server's members.
- **Notifications** in a Discord channel (a webhook), each event on or off:
  new member, a media server down and back (checked every 5 minutes),
  someone on the waiting list, a stream stopped for the limit, a new
  Clubarr version.
- **Backups**: a copy of the database every night in `/config/backups` (the
  newest 7 are kept), and a password-locked file to download. Restore from
  either; a backup from another machine works without moving any key.
- **New version** notice on the dashboard (GitHub's public releases, daily;
  can be turned off).
- **Your logo and accent colour** under Settings → General.
- Settings has two new tabs: **Notifications** and **Backup**.

## 0.2.3-beta (2026-11-28)

### Plex
- **Find Plex** replaces "Sign in with Plex" on the server form. Paste the
  server owner's token and it lists your Plex servers from plex.tv with
  every address Plex knows (like Tracearr), tried from Clubarr with ✓ and
  the answer time; choose one, and the address and name are filled in.
  Without a token it looks on the network you opened Clubarr on (ports
  32400 and 32401). You can always type the address yourself.
- **Members can type their Plex email or user name** instead of signing in
  with Plex. Plex sends them an invitation; once they accept it, Clubarr
  sees it and marks their access active. "Sign in with Plex" is still there.

## 0.2.2-beta (2026-11-28)

### Fixed
- **Sign in with Plex on a phone** stopped at Plex's "you can close the
  window" page. Plex now sends the window back to Clubarr: a popup closes
  itself, and a phone tab returns to the page you came from (the server
  form or the invitation), which carries on. The way back is always a page
  on your own Clubarr.

### Changed
- **Settings** is split into tabs: General, Security, Mail, Integrations and
  Waiting list. Mail and each integration (Tracearr, Seerr, Discord) have
  their own **Test** button.

## 0.2.1-beta (2026-11-28)

Built from the wrong commit: the same as 0.2.0-beta. Its changes are in
0.2.2-beta.

## 0.2.0-beta (2026-11-28)

### Tracearr
- **My devices** (members): what plays on their users now, with **Stop**, and
  their devices of the last 30 days.
- **Streams at the same time**: a limit on the invitation, given to the
  member (and changeable per member). Every minute, streams over it are
  stopped, newest first, with a message on the screen (Plex).
- **My access** shows hours watched (7 and 30 days), plays, and posters:
  **Continue watching**, **Popular now** and **New on the server**. Posters
  are served by Clubarr, only those it listed; browsers never talk to
  Tracearr or the media servers.

### Requests (Overseerr / Jellyseerr)
- **Requests** page: search, request a movie or a whole show, and follow
  your requests. Made as the member's own Seerr user (found by their
  Jellyfin/Emby or Plex user), so Seerr's own rules and approval apply.
- **Requests per week**: a limit on the invitation, given to the member.
- TMDB posters are served through Clubarr as well.

### Discord
- Members can **link Discord** on My access (only their ID and name are
  read). The bot gives them a role while they have access and takes it away
  when it ends, with a direct message. Checked every hour.

### Member limit and waiting list
- **Members at most** per server. When a server is full, invitations wait
  and the visitor is pointed to the **waiting list** (/waitlist).
- When there is room, the first in line gets an invitation by mail, valid
  48 hours, copied from an invitation you choose (servers, libraries,
  limits). Unused, it goes to the next. Admin → Waiting list shows everyone,
  with **Invite now**.

### Media servers and invitations
- **Import users** takes many at once. Users the server knows the email of
  (Plex, Silo) get a mail to choose their password (or are linked to the
  member with that email). Jellyfin and Emby don't keep emails, so you get a
  **personal link** for each (valid 30 days, once) with **Copy all links**;
  opening it, they make their account and are linked to their existing user.
- **Test** on the server form checks the address and key before saving.
- **Sync** (was "Check"): tests the server, reads the libraries, and brings
  members' users up to date (paused or active as on the server, new names);
  it says how many users are no longer on the server. The version is shown.
- **Plex: Sign in with Plex** on the server form, then **Choose server**:
  your Plex servers with their addresses (local first) are listed, and the
  address and token are filled in for you.
- Invitations: **Used by** shows who joined with each; the link can be
  **mailed** with a few words when it is made, or with **New link**.
- **Copy link** works on plain http addresses too (like a LAN IP), where
  browsers don't allow the clipboard API.
- Admins have **Admin** in their menu on the members' side (and **My access**
  in the admin), so signing in at either place works.

### Other
- **Settings**: Tracearr, Seerr, Discord and the waiting list, with one
  button to test them all. All keys and tokens are stored encrypted and are
  never sent to the browser.
- When an existing member redeems another invitation, they keep the more
  generous limits.
- The Docker image uses Alpine's own PHP packages and builds in minutes
  instead of ~20 (arm64 included), and is built only for releases.

## 0.1.1-beta (2026-11-28)

- **Import user works.** The list of users on the server could not be loaded
  ("The options could not be loaded"): Clubarr kept it in its cache as
  objects, which Laravel's cache refuses to bring back. It is now kept as
  plain values. Making a new member with an email in the same step also
  failed, and works now; an email that already has an account is refused.
  Checked for all four kinds of server: **Check**, **Import user** and
  joining with an invitation are now tested for Jellyfin, Emby, Plex and
  Silo, each with its own answers.
- **Two-factor sign-in for admins can be turned off.** It is still on by
  default: a choice in the setup ("Require two-factor sign-in for admins
  (recommended)") and under **Settings → Security**. The change takes effect
  at once, without a restart, and is written in the log. Admins who haven't
  set it up are asked to on their next sign-in when it is turned on again.

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
