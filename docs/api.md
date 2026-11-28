# REST API

Other programs (Home Assistant, a script, a dashboard) can read Clubarr's
status, members and invitations, and make invitations from a template.

## Keys

Admin → System → **API keys** → New key. The key (`clb_…`) is shown once;
only a hash of it is stored. A key can only read, unless "May make
invitations" is on. Revoke a key to stop it at once.

Send the key in a header with every call:

```
Authorization: Bearer clb_…
```

Limits: 60 calls a minute per key (120 per IP), and 100 new invitations an
hour per key. Over the limit, the answer is `429`.

## Endpoints

All answers are JSON. Times are ISO 8601.

| Call | What |
|---|---|
| `GET /api/v1/status` | Version, number of members (total, with access, paused), the servers (online or not), open invitations. |
| `GET /api/v1/members?page=1` | Members, 100 per page: name, email, paused, when access ends, last seen, groups, server users. |
| `GET /api/v1/invitations` | Templates and open invitations: label, uses, servers, when it ends. Never the codes. |
| `POST /api/v1/invitations` | Makes an invitation from a template (the key must be allowed to). |

### Make an invitation

```
POST /api/v1/invitations
{"template_id": 3, "label": "For Sam", "max_uses": 1, "expires_in_days": 7, "email": "sam@example.com"}
```

Only `template_id` is needed: servers, libraries, how long and limits come
from the template. Defaults: 1 use, ends in 7 days. With `email`, Clubarr
mails the link too. The answer has the link, once:

```
201 {"id": 12, "link": "https://clubarr.example.com/join/…", "expires_at": "…", "sent": [...]}
```

## Errors

`401` no or wrong key, `403` the key may not do that, `422` something in
the request is wrong (the answer says what), `429` too many calls.
