# Media servers

Add servers under Admin → Media servers. Clubarr checks the address and key
before saving, and reads the libraries. **Clubarr never changes a server's
administrators.**

| | Key | How a member gets access | Pause | New password |
|---|---|---|---|---|
| **Jellyfin** | API key (Dashboard → API Keys) | Clubarr makes the user with the member's user name and password, with the invitation's libraries | The user is disabled | On their page |
| **Emby** | API key | As Jellyfin | As Jellyfin | On their page |
| **Plex** | The server owner's Plex token | The member signs in with Plex (Clubarr accepts the share for them) or types their Plex email (they accept Plex's invitation) | The share is removed (and made again on resume) | Their own Plex account's |
| **Silo** | API key of an admin account, full access | Clubarr makes a one-time Silo link; the member chooses their user on Silo | The account is disabled | A Silo password link on their page |

- **Address Clubarr uses** is how Clubarr reaches the server (often a LAN
  address). **Address members open** is what they get as "Open" (not used for
  Plex: members use app.plex.tv).
- **Test** on the form checks the address and key before you save. **Sync**
  on a server's row reads the libraries and updates members' users.
- **Plex**: paste the server owner's Plex token and press **Find Plex**:
  plex.tv lists your servers with every address it knows (including Plex's
  own `*.plex.direct` addresses, which have a real https certificate), and
  Clubarr tries each one and shows which it can reach and how fast. Choose
  one, then **Test**. Without a token, **Find Plex** looks on the network
  Clubarr is opened on (ports 32400 and 32401). You can always type the
  address yourself, e.g. `http://192.168.1.10:32400`.
- **Plex members** either press **Sign in with Plex** (the share is accepted
  for them), or type their Plex email or user name; then Plex sends them an
  invitation to accept, and Clubarr marks them active once they have.
- **Import users** (on the server's row) brings users that already exist on
  the server into Clubarr, many at once. With an email the server knows
  (Plex, Silo), they get a mail to choose their password. Without (Jellyfin,
  Emby), you get a personal link for each to send them; it works once, for
  30 days, and is stored only as a hash.
- Silo links and Plex shares that the member hasn't taken yet show as
  "Waiting for the member"; Clubarr notices when they are taken.

## Plex in this beta

Plex's sharing API is not public. Clubarr uses the calls Plex Web (and Wizarr)
use. If plex.tv changes them, sharing may fail until Clubarr is updated; the
error is shown and nothing is half-made.
