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
  the encrypted session, and never stored.

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
