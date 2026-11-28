# Security

What Clubarr does to protect your servers and members. Found a problem?
See [SECURITY.md](../SECURITY.md).

## Accounts and sign-in

- **No open registration.** Accounts are only made by redeeming an
  invitation (or by an admin).
- **Admins must use two-factor sign-in** (an authenticator app, with recovery
  codes) unless you turn that off (at setup, or Settings → Security; it is
  logged). Members can turn it on in their profile.
- Passwords: at least 10 characters with letters and digits (admins 12),
  stored with bcrypt. Sign-in, invitation lookups, sign-ups, password changes
  and Plex sign-in are rate limited per IP.
- **Failed sign-ins are logged** (who and the IP, never what was typed). After
  10 failed sign-ins for one email, that account is locked for 15 minutes,
  even with the right password; any email typed counts the same, so the lock
  says nothing about which accounts exist. Admins can get an alert.
- **A sign-in from a new device is mailed** to the person (Settings →
  Security; needs mail). A device is a random cookie, stored as a hash; the
  first device ever (joining) is not mailed.
- On their profile, people see the browsers they are signed in on and can
  **sign out everywhere else** (with their password). A new email is only
  used once the link mailed to it is opened, and the old address gets a
  notice with a link to stop the change (when mail is on).
- Sessions are encrypted, HTTP-only cookies, `SameSite=Lax`, `Secure` with
  `SESSION_SECURE_COOKIE=true`. A disabled member is refused on every page.
- Members can't reach /admin. Every member action checks that the server user
  is the member's own; IDs from the browser are never trusted.

## Invitations

- Codes are 20 random characters (about 99 bits) and are **stored only as a
  hash**: a leaked database gives no working links. An admin sees the link
  once; "New link" replaces it.
- Using an invitation takes a use under a database lock, so two people can't
  take the last one. If making a user on a server fails, everything made so
  far is removed again and the use is given back.

## Personal links (Import users)

- Like invitation codes: random, stored only as a hash, shown once, used
  once (under a row lock), valid 30 days, and refused once the server user
  is linked to someone. Lookups are rate limited.

## Media servers

- API keys, the mail password, two-factor secrets and Silo links are
  **encrypted in the database** with the key in `/config/app.key`, and are
  never sent back to the browser.
- Calls to servers: http(s) only, no credentials in the address, short
  timeouts, **no redirects** (so a server can't send Clubarr's key on to
  another host), tokens only in headers, and errors without keys.
- **Server administrators are never changed**: before disabling, deleting or
  setting a password, Clubarr reads the user and refuses an administrator.
- Plex: the member's Plex token is used once, while signing up, kept only in
  the encrypted session, and never stored. A member who types their Plex
  email instead gets a normal Plex invitation, which they must accept
  themselves; Clubarr never sees their Plex account.
- **Sign in with Plex** on the server form keeps the token plex.tv gives
  in the encrypted session until the server is saved; it is never put in
  the form, so it never reaches the browser.
- **Find Plex** (admins only): with a token, it asks plex.tv (the only place
  the token goes) for the account's servers, then tries each address. Without
  one, it asks the addresses of one private network (the one Clubarr is
  opened on, e.g. 192.168.1.x) on ports 32400 and 32401. Either way an
  address only gets a plain request for Plex's `/identity`, public networks
  are never scanned, and only the server's ID and version are read.

## Invitations

- Codes are only stored as hashes. A link mailed to someone is kept
  encrypted only for the one reminder, and removed after it, or when the
  invitation is used or ends.
- The QR code is made in the browser; the link is never sent to another
  service to draw it.
- Members inviting friends choose only who and the words: servers,
  libraries, how long and limits come from the admin's template. Their
  monthly number is checked under a lock, and the action is rate limited.
- Posters on an invitation page come from that invitation's servers and
  libraries only, and only the ones that page listed for that visitor.
- Greetings and welcome texts are plain text everywhere (page and mail).

## Single sign-on and members' own actions

- SSO is OpenID Connect's code flow with PKCE and a one-time state (10
  minutes), as a confidential client; the provider must be on https and its
  discovery document must name the same issuer. Who signed in comes from
  the provider's userinfo endpoint over https with the access token.
- Only existing accounts sign in: by the provider's subject once linked,
  else by an email the provider marks as verified. SSO never makes
  accounts. Members only when turned on; disabled accounts never.
- An SSO sign-in skips Clubarr's own two-factor step for that session (the
  provider should require it). The client secret is stored encrypted.
- Deleting one's own account needs the current password; their users on
  the servers are deleted first, and if that fails nothing is deleted.
- /apply answers the same whether an email is known, has a hidden bot trap
  and a rate limit, and gives nothing until an admin approves.
- Problem reports and requests only reach the member's own servers and are
  shown only to them; texts are plain (escaped) everywhere.

## Backups, alerts and branding

- A downloaded backup is encrypted with AES-256-GCM, its key made from the
  admin's password with PBKDF2-SHA256 (600,000 rounds). The password is never
  stored; a wrong one changes nothing. Restoring checks the file is an intact
  Clubarr database first, keeps a copy of the current one, signs everyone
  out, and moves the backup's secrets to this installation's key.
- The admin alert webhook is stored encrypted and must be a
  `https://discord.com/api/webhooks/…` address; nothing else is called.
  Alerts never mention anyone (`allowed_mentions` is empty).
- Messages to members are plain text: shown and mailed as written, never as
  HTML.
- Posters from the media servers go through Clubarr, only to members of that
  server; the members' page never waits for a media server (the lists are
  fetched in the background).
- A logo is only PNG, JPEG or WebP (no SVG, which can carry scripts), checked
  as a picture, at most 300 KB.
- The update check only reads GitHub's public list of releases, and can be
  turned off.

## Tracearr, Seerr and Discord

- Their keys and tokens are encrypted like the media server keys, and calls
  go through the same client (no redirects, short timeouts, safe errors).
- A member only ever sees and stops their own streams; the stream ID they
  send is checked against their own.
- Requests are made as the member's own Seerr user, within their weekly
  limit, and only when they have access.
- Posters and TMDB pictures are served by Clubarr (only listed posters, and
  TMDB file names checked), so members' browsers don't contact other hosts.
- Discord linking uses a one-time state in the session against forged
  callbacks, asks only for "identify", and uses the token once; an account
  linked to someone else is refused.
- The waiting list page says the same whether an email is on the list or
  not, is rate limited, and ignores bots (a hidden field).

## First start

Until the first admin exists, every page leads to /setup, which needs the
one-time **setup token** from the container's log. A fresh Clubarr on the
internet can't be claimed by someone else. After setup, /setup is gone.

## Web

- Headers on every answer: `Content-Security-Policy` (only this site, no
  framing, no plugins), `X-Frame-Options: DENY`, `X-Content-Type-Options`,
  `Referrer-Policy: same-origin`, `Permissions-Policy`, and HSTS over https.
  The policy allows inline scripts because Livewire/Alpine need them.
- CSRF protection on every form. No requests to outside services from the
  browser (avatars are made locally).
- The container: nginx, the queue and the scheduler run as an unprivileged
  user; only `public/index.php` can be run; hidden files are never served;
  `/config` files are readable only by Clubarr.

## Audit log

Admin → Log keeps who did what: setup, servers, invitations (made, redeemed,
withdrawn, new link), members (changed, disabled, removed), passwords changed,
settings saved. Never passwords or keys.

## API and admin alerts

- API keys are random (`clb_` and 40 characters), shown once and **stored
  only as a hash**. A key reads; making invitations needs a key allowed to,
  and only from a template the admin made. Calls are rate limited per key
  and per IP. Codes, passwords, keys and notes are never in an answer.
- Admin alerts (Discord, ntfy, Telegram, Gotify, a webhook): addresses and
  tokens are stored encrypted, read when sending (never put in the queue),
  calls never follow redirects, and a failure names only the channel. A
  webhook can be signed (HMAC-SHA256 of the body in X-Clubarr-Signature).
- Mail texts written by the admin are plain text; names filled in are
  escaped like everything else in the mails.

## Front page, moderators and exports

- The front page is off by default. It shows only what you turn on; posters
  have no titles and are served only to the visitor they were listed for.
  Your text is plain text.
- Moderators can't open settings, servers, groups, API keys or the log (the
  pages refuse them), never see or change admins, and can't export members.
- The CSV export is for full admins only and is logged. A cell starting
  with = + - or @ gets a ' first, so a spreadsheet never runs it.
- A member's invitation waiting for approval keeps its code and the
  friend's email encrypted, and only until it is decided.

