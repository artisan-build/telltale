# Mobile crash queries

The server accepts envelope versions 1 and 2. Crash events require version 2. Install credentials
determine the app and install; identity fields in the request cannot redirect a write. A report ID
is deduplicated within its app and install, including retries with a different event ULID.

Crash details live on retained raw events. Groups are computed from those events, so deleting an
install or pruning history also removes its native frames, trace and build samples from queries.
There is no separate sample store. Crash events do not create sessions, activity facts or release
adoption. Their app version and platform come from the crash report; an unknown historical version
stays unknown even when the install has reported a newer version.

## MCP tools

Admitted readers have server-wide read access. Every query is scoped to its requested `app_id`.
Ingest credentials cannot use the read tools.

- `crash_groups(app_id, from?, to?, limit?)` returns fingerprints, occurrence totals, report counts,
  distinct installs, first/last times and historical versions/builds within the window. The default
  limit is 25, the maximum is 50, and `truncated` indicates more groups.
- `crash_detail(app_id, event_id)` returns one retained crash event, including its typed details,
  ordered frames, binary UUID/build IDs, addresses, offsets and available load addresses.
- `crash_free_users(app_id, from?, to?)` returns active, affected and crash-free install counts,
  percentage, window bounds, timestamp attribution and coverage caveats. The percentage is null
  when no installs are observed.

Supply RFC3339 timestamps with a timezone and at most six fractional digits (microseconds).
Query bounds with greater precision are rejected. Windows are UTC half-open intervals `[from,to)`.
The default ends now and spans the smaller of 30 days and configured raw retention. Explicit
windows must be ordered, cannot end in the future, and cannot span more than the smaller of 366
days and raw retention. Windows starting before the retention horizon are rejected. The server
also remembers pruning cutoffs: increasing retention cannot restore an interval already pruned.
After a shorter manual prune, the default start advances to the retained-history cutoff.
An explicit start before that cutoff remains an error.

The metric uses the same retained raw events for both populations. Ordinary-event installs and
crash-only installs both enter the denominator. Only fatal reports enter the affected set, once
per install regardless of report or occurrence count. Nonfatal hangs do not mark an install
affected; fatal Android ANR deaths do. Aggregate exit counts contribute to group occurrences,
without multiplying affected installs.

This is an observed install-based proxy: OS coverage is partial, delivery can be delayed, and
aggregate reports are attributed to their reporting-window end, so a missing report does not prove
crash-free operation. This server slice stores symbol inputs; it does not capture native reports
or provide symbol uploads.
