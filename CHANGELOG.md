# Changelog

What changed in each version of Clubarr. Each section is also the text of its
release on GitHub (`.github/release-notes.sh` puts it there).

## 0.11.0-beta (2026-11-30)

### New look
- **Themes** (Settings → General → Theme):
  - **Streaming** (the new default): dark, a glow of your accent colour,
    glass cards, round buttons, big posters; posters drift behind the
    sign-in and invitation pages, and the newest title fills the greeting on
    the members' page.
  - **Midnight**: navy, clear cards and bold colours, like Wizarr.
  - **Classic**: the look until now, light or dark.
- **Members and invitations as cards** in the admin (Streaming and Midnight),
  like Wizarr; Classic keeps tables.
- The front page and the invitation mail follow the theme's colour and font.
  Your own accent colour and logo are used in every theme.

### Fixed
- **Get started after joining** shows only the servers (and wizard steps)
  of the invitation just used, not every server the member has. Opened
  later from the menu, it still shows all of them.

## 0.10.2-beta (2026-11-30)

### Changed
- **Invitation codes are shown in full** in the invitations list (like in
  Wizarr), with a **Copy link** button, and on the member's page. They are
  kept encrypted with the key in `/config/app.key`. Invitations made before
  this update show only their first characters; "New link" gives them a
  code you can see.

## 0.10.1-beta (2026-11-30)

This is the release that has 0.9 and 0.10 in it: the releases 0.8.1-beta,
0.9.0-beta and 0.10.0-beta were made before that code was on main, so their
image is 0.8.0-beta. Everything in 0.9.0-beta and 0.10.0-beta below is in this
one.

### Changed
- **The version is shown** at the bottom of every page (when signed in), so
  you can see that an update arrived.

## 0.10.0-beta (2026-11-30)

### New members
- **Wizard** (Servers → Wizard): steps new members see before joining (on
  the invitation page) and after (Get started, and later under Help), per
  server or for all. Drag to order, "Add ready-made steps" per server type,
  and **bundles**: other sets of steps chosen on an invitation (e.g. family).
- **A user they already have**: on an invitation, members can sign in with a
  Jellyfin or Emby user they already have instead of making a new one; the
  server checks the password, Clubarr never keeps it. Server administrators
  can never be linked.
- **Choices when applying**: you make choices from templates ("Films",
  "Everything"); applicants pick one and may leave out servers they don't
  need. Approving gives just those.

### For members
- **"I already have a user on a server"** on their page, the same way.
- On My page → **Your access** (each when you allow it): **extra users for
  the family** (with the member's libraries; they follow the member's
  access), **pause** their access (the time is added to the end date), and
  **move to another server**.
- **Movie nights** ("I'm coming", a reminder before) and **challenges**
  ("10 films in December") with their progress, on the front page.
- **Discord commands**: /status, /access, /stats and /password.

### For admins
- **Link a server user** to a member (a user on a server no one here has).
- Movie nights and challenges (with results) under Members.
- Settings → Members: family users, pausing, servers members may move to.

## 0.9.0-beta (2026-11-30)

### For members
- **My page** (in the menu, and behind the avatar): the account (name,
  email, password, two-factor, where you are signed in), **My numbers**
  (hours watched, plays, most watched this year, and your access: when it
  ends, groups, requests and invitations left), and **Settings** (weekly
  mail, Discord, delete my account), each on a tab.
- **Your access** at a glance on the front page, and a link to more numbers.

### Without Tracearr or Tautulli
- **Numbers and "Continue watching" from Jellyfin, Emby and Silo**: Clubarr
  reads what each member watched from their own server user. Silo's own
  playback history gives real watching time; Jellyfin and Emby give what was
  finished, by the day it was last watched. Fetched on the queue and kept
  half an hour, so the page never waits for the servers.
- **Silo posters** for "Continue watching", from Silo's catalog.

## 0.8.0-beta (2026-11-30)

### For visitors
- **A front page** at your address, if you turn it on (Settings → Front
  page): your name, logo and a text, buttons to sign in and (when open) to
  apply or join the waiting list, posters of the newest films and series
  (no titles, if you want), and whether the servers are online. Off, the
  address goes straight to sign-in as before.
- **Your own questions** when people apply ("Who do you know here?"); you
  see the answers under Applications.

### For admins
- **Moderators**: an admin role for members, invitations, requests,
  applications, problems and messages, without settings, servers, groups,
  API keys, the log or other admins.
- **Approve friends' invitations** first, if you want (Settings →
  Invitations). The link works, and is mailed, once you approve; the member
  is told either way.
- **Export members** as CSV (Members → Export).
- **Planned maintenance** (Servers → Maintenance): members get a mail, a
  Discord message and a banner; the front page says "Maintenance"; and
  "server down" alerts wait while it lasts.

## 0.7.0-beta (2026-11-29)

### For members
- **My statistics**: their year (hours, plays, top titles, busiest month)
  and the last 30 days, and when they watch. Needs Tracearr or Tautulli.
- **More on the front page**: coming soon (from Sonarr and Radarr), their
  latest requests, and how many friends they may still invite.
- **Profile**: the browsers they are signed in on, and **Sign out everywhere
  else**. A new email is only used once the link mailed to it is opened.
- **Discord messages** for answers, decisions and the "access ends soon"
  warning, for members who linked Discord.

### For admins
- **Requests (Overseerr/Jellyseerr) are your choice**: "May request (Seerr)"
  on the invitation (and templates) and on each member. Members from before
  keep it.
- **Member page**: click a member to see everything: servers, what they
  watch, requests and their log.
- **Groups** ("Family", "Friends") with their own rules: access never ends,
  streams at once, requests a week, may request. An invitation can put new
  members in a group. **Notes** on members, only for admins.
- **Statistics**: plays, hours, the most watched and the busiest members
  over 7 to 365 days.
- **Notifications** to Discord, ntfy, Telegram, Gotify or a webhook of your
  own (signed if you want), on a page of their own.
- **Mail texts**: write the welcome, "access ends soon" and "access has
  ended" mails yourself.
- **API keys**: a REST API for status, members and invitations, and making
  invitations from a template (see docs/api.md).
- **Clean up** a media server: delete the users on it that no member has,
  after you choose them.
- **A tidier admin menu**: Members, Servers, Insights and System; Settings
  split into Settings, Integrations, Notifications and Mail texts.

### Integrations
- **Tautulli** (Plex), when Tracearr is not used: what members watch,
  stream limits, numbers and posters.
- **Sonarr and Radarr**: "Coming soon" for members.

### Security
- **Failed sign-ins are logged**; after 10 for one account, it is locked for
  15 minutes (and you can get an alert).
- **A mail on a sign-in from a new device** (Settings → Security).

### Automatic
- **Old data** is removed after the days you set: the log, ended
  invitations, decided requests and closed reports.

## 0.6.0-beta (2026-11-29)

### For members
- **Ask for more time** in the last 14 days of their access; you approve
  with a number of days (or decline) under **More time**, and they get a mail.
- **Help**: "Something doesn't work" (a film that won't play, subtitles,
  sign-in). You answer under **Problems**; the answer is mailed and shown on
  their Help page.
- **A weekly mail** with what is new on their servers, if they turn it on.
- **Delete my account** (with their password): their users on the servers
  go too.

### For admins
- **Dashboard**: members over 12 weeks, who watches now and the most
  watched (with Tracearr), and the latest events.
- **Libraries per member**: change what a member sees on each server
  (Jellyfin, Emby, Plex); Clubarr changes the server.
- **Many members at once**: change the end date, pause, enable, mail or
  remove the ones you tick.
- **Single sign-on** (OpenID Connect: Authentik, Authelia, Keycloak ...)
  for admins, and members if you want. Only existing accounts can sign in.

### Automatic
- **Overseerr/Jellyseerr user at once** when someone joins.
- **Inactive members**: nothing watched for the days you set: a mail a
  week before, then paused (which frees the seat).
- **After access ends**: their users on the servers are deleted after the
  days you set; the Clubarr account stays.
- **Apply to join** (/apply): people ask, you approve, and they get an
  invitation by mail from your template.

## 0.5.0-beta (2026-11-28)

### Media servers and Clubarr stay in step
- **Automatic sync every 15 minutes** (what "Sync" did by hand): libraries,
  members paused or active as the server says, and the number of users on
  the server that are not in Clubarr (shown on the server's row).
- **A user deleted on the server loses that access in Clubarr.** A member
  left with no servers is paused, not deleted. When most users seem gone at
  once, nothing is removed (that looks like a server problem) and you are
  told. Both can be a Discord notification.
- **Remove from a server** on a member: deletes their user on that one
  server (Plex: the share); the account and other servers stay. ("Remove"
  still deletes the member and their users everywhere.)
- **Invitations to servers that are deleted or switched off** can't be used,
  and show "No servers".
- **"Open" on Plex** goes straight to your server in app.plex.tv.

### Other
- Danish translations are no longer required for new texts.

## 0.4.0-beta (2026-11-28)

### Invitations
- **The invitation page is a short guide**: 1) welcome: your greeting (and
  who invites), the site's welcome text, what they get and posters of what
  is new on those servers; 2) the account; then 3) **Get started**: per
  server, the app for iPhone/iPad and Android (and the TV hint), how to sign
  in, and a button to watch in the browser.
- **A greeting per invitation**, shown on the page and in the mail.
- **A proper invitation mail** with your logo and colour, the greeting, the
  servers and a big button. Mails from scheduled jobs and the queue now use
  the site's language too.
- **Share it your way**: after making an invitation you get the link with
  Copy, Share (the phone's own share menu) and a QR code (made in the
  browser, downloadable). Or send it as a **Discord message** to a user ID.
- **Templates**: save a set-up (servers, libraries, limits, greeting) and
  make invitations from it with one click.
- **Many at once**: paste emails; everyone gets their own single-use link
  by mail, from a template.
- **Members invite friends** (Settings → Invitations): a number a month,
  one person each, valid 7 days, always with your template's set-up. You
  see who invited whom.
- **Reminders**: an invitation mailed to someone and not used gets one
  reminder after the days you set (default 3). Whoever made an invitation
  gets a mail when it is used.

### Fixed
- The "Open" filter on the invitations list failed.
- The queue worker now reads changed settings before each job.

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
