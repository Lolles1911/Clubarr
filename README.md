<p align="center"><img src="public/clubarr-icon.png" width="96" alt=""></p>

# Clubarr

**Invitations, accounts and a member page for your Plex, Jellyfin, Emby and
Silo servers.** Self-hosted, one Docker container. *Beta.*

- **Invitations**: a link per person or group, valid until a date, for a
  number of uses, with the servers and libraries you choose, and access for a
  number of days (or until you remove it).
- **Members get their own account** (email and password, optional
  two-factor sign-in; required for admins unless you turn it off) and a page with their servers, user names, how to open
  them, when access ends, and a new password for watching.
- **Every server the way it works**: Jellyfin and Emby users are made for
  them; Plex is shared with their own Plex account (sign in with Plex, or
  just type the Plex email);
  Silo users are made on Silo's own page.
- **Admin**: media servers, invitations, members (pause, end, remove),
  importing users who already exist on a server, mail, and a log of
  everything.
- **Automatic**: access that ends pauses the users on the servers, with a
  mail a few days before.
- **Tracearr**: members see what plays on their users (and stop it), their
  devices, hours watched and posters; a limit on streams at the same time.
- **Requests** through Overseerr/Jellyseerr, with a weekly limit per member.
- **Discord**: members link their account and get a role while they have
  access.
- **Member limit per server** and a **waiting list** that invites the next
  in line by itself.
- English and Danish.

## Install

```yaml
services:
  clubarr:
    image: ghcr.io/lolles1911/clubarr:beta
    container_name: clubarr
    restart: unless-stopped
    ports: ["8080:8080"]
    environment:
      APP_URL: "https://club.example.com"   # the address people open
      TZ: "Europe/Copenhagen"
    volumes:
      - ./config:/config
```

Start it, then read the **setup token** in the log
(`docker logs clubarr`) and open Clubarr in your browser. On **Unraid**, use
[the template](unraid/clubarr.xml).

Then: add your media server, set up mail, and make your first invitation.

## Documentation

- [Configuration](docs/configuration.md): addresses, reverse proxy, mail, backups
- [Media servers](docs/media-servers.md): what each server needs
- [Security](docs/security.md): how Clubarr protects your servers and members
- [Development](docs/development.md): structure, tests, adding a server type

## License

[AGPL-3.0-or-later](LICENSE). Clubarr's API calls for Jellyfin, Emby and Plex
follow those used by [Wizarr](https://github.com/wizarrrr/wizarr) (MIT).
