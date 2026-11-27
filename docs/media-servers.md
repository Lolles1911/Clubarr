# Media servers

Add servers under Admin → Media servers. Clubarr checks the address and key
before saving, and reads the libraries. **Clubarr never changes a server's
administrators.**

| | Key | How a member gets access | Pause | New password |
|---|---|---|---|---|
| **Jellyfin** | API key (Dashboard → API Keys) | Clubarr makes the user with the member's user name and password, with the invitation's libraries | The user is disabled | On their page |
| **Emby** | API key | As Jellyfin | As Jellyfin | On their page |
| **Plex** | The server owner's Plex token | The member signs in with Plex; Clubarr shares the libraries with their account and accepts the share for them | The share is removed (and made again on resume) | Their own Plex account's |
| **Silo** | API key of an admin account, full access | Clubarr makes a one-time Silo link; the member chooses their user on Silo | The account is disabled | A Silo password link on their page |

- **Address Clubarr uses** is how Clubarr reaches the server (often a LAN
  address). **Address members open** is what they get as "Open" (not used for
  Plex: members use app.plex.tv).
- **Import user** (on the server's row) links a user that already exists on
  the server to a member, so existing users can sign in to Clubarr too.
- Silo links and Plex shares that the member hasn't taken yet show as
  "Waiting for the member"; Clubarr notices when they are taken.

## Plex in this beta

Plex's sharing API is not public. Clubarr uses the calls Plex Web (and Wizarr)
use. If plex.tv changes them, sharing may fail until Clubarr is updated; the
error is shown and nothing is half-made.
