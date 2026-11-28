# Clubarr: notes for working on this repository

- Read docs/development.md (structure and rules) and docs/security.md before
  changing anything that touches sign-in, invitations or media servers.
- Before a commit: `php artisan test` and `vendor/bin/pint --test` pass.
  Texts are English with `__()`; new ones need no Danish line in
  lang/da.json (the owner chose to stop adding them).
- Secrets never go in logs, the audit log, exceptions, Livewire public
  properties or the browser.
