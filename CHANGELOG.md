# Changelog

## 0.1.0-beta

The first beta.

- Setup in the browser with a one-time setup token from the log.
- Media servers: Jellyfin, Emby, Plex and Silo, checked before saving, with
  their libraries.
- Invitations: servers and libraries, valid until, number of uses, access
  days; codes stored only as a hash; withdraw and new link.
- Join: own account (email and password); Jellyfin/Emby users made with the
  member's user name; "Sign in with Plex" and the share accepted for them;
  Silo through a one-time link.
- My access: servers, user names, libraries, open, when access ends, new
  password for watching (Silo: a password link).
- Admin: members (access end, disable, enable, password link, remove),
  import of existing server users, settings with mail and a test mail, a
  read-only log, a "getting started" list and numbers.
- Hourly: a mail before access ends, users paused when it has ended, Silo
  links and Plex shares that were taken.
- Two-factor sign-in (required for admins), English and Danish.
- One Docker container (amd64 and arm64), Unraid template.
