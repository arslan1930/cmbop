# Admin → Campaigns

Bulk marketing / platform-update email from **Admin → Updates & Campaigns**
(`/admin/campaigns`). This is **not** advertiser `/campaigns` (orphaned project
UI). Recipients are marketplace advertisers and publishers only — never admins
or marketing, even if that staff account also has a marketplace role.

## Send path

1. Compose subject, HTML body, optional CTA, audience, and the two checkboxes
   (`respect_preferences`, `include_unverified`).
2. Confirm uses `POST /admin/campaigns/recipient-count` (GET still works for
   small queries) so a 400-id picker does not blow the URL limit. The submit
   button must **not** carry `data-slb-confirm` — that would let
   `slb-confirm.js` (document capture) run first and skip the count (or loop).
   Confirm is imperative `slbConfirm()` from the form script.
3. `POST /admin/campaigns/send` creates an `email_campaigns` row (`queued`),
   inserts `email_campaign_recipients` (`pending`) in one transaction, logs
   `campaign.queued`, then dispatches `SendEmailCampaignJob` on the **`emails`**
   queue. Flash: **Campaign queued for N recipient(s).**
4. The job claims a `queued` row (`queued` → `sending`) or continues an
   already-`sending` campaign. Each handle processes at most **20** pending
   rows when `Mail::send()` only enqueues, or **5** when mail is inline SMTP
   on a worker (20 sync sends blow the 25s timeout mid-batch). It
   re-dispatches itself when more remain. Recipients are claimed `pending` → `queued` atomically so two
   workers cannot double-send. A thrown handle still fails leftover **pending**
   rows only after **3** failed batches (`failStreak`). Give-up sets the
   campaign `failed` first, then recounts: a real delivery leaves it
   **sent** (partial success) instead of overwriting that to failed.
   Leftover `queued` rows with no delivery stay failed. A timeout, a
   transient DB error, or `failed()` before the claim must **not** wipe the
   rest of the audience.    Fail streak is stored in cache so stall recovery
   cannot reset it and retry forever. Stall recovery must **not** give up leftover pending while a `SendEmailCampaignJob` is already in the
   jobs table — `failed()` remembers MAX and dispatches the last attempt;
   giving up first wipes the audience beside that job. An unclaimed `queued` job is left
   for stall recovery. Opening Admin → Campaigns, web mail drain, and
   `mail:drain-queue` (even when auto-drain is off) re-dispatch stale
   `queued`/`sending` rows so a lost continuation does not sit forever.
   Recovery **touches** the campaign after a re-dispatch (or when a send
   job is already in the `jobs` table) so a backed-up emails queue cannot
   enqueue another job on every page view. The jobs-table check must
   match JSON-escaped payloads (`\"campaignId\";i:N;`) and `"campaignId":N`
   via `containsSendCampaignJob` → `containsCampaignId` — a literal
   `campaignId";i:N;` LIKE misses every database-queue row, and `i:12;`
   must not match campaign 123. It walks
   every send-job connection (mail first, then `queue.default`); a miss
   or error on the first must not skip the other. The send job pins
   `onConnection` to a drainable queue (mail connection first, otherwise
   `queue.default`) so `QUEUE_CONNECTION=sync` plus a database mail queue
   cannot run the whole audience inside the compose request.
   `mail:drain-queue` and web drain recover even when mail is `sync`,
   and they drain every drainable connection — `MAIL_QUEUE_CONNECTION=sync`
   used to skip both and leave campaign jobs sitting. Web recover still
   runs when auto-drain is on and there is nothing to drain (both
   connections sync) so a killed inline send is not left `sending` until
   cron. A `sending` campaign that still
   has `queued` recipients is left sending — leftover queued rows are not
   treated as a successful send. When those queued rows have no email log
   and no `AudienceCampaignMail` on a database queue (timeout after the
   `pending` → `queued` claim, before `Mail::send()` inserted the job),
   recover reclaims them to `pending` and dispatches a send job. A
   queued row with a pending Email Center log is held — that is a
   just-retried mailable, and reclaiming it would dispatch a second send
   if the jobs-table scan missed the retried job. A matching
   `failed_jobs` AudienceCampaignMail is also held — that row is still
   retryable from Email Center. A
   Redis/SQS **mail** queue, a missing `payload` column on the **mail**
   table, or a mailable whose user id cannot be parsed is fail-closed: the
   row stays queued so an in-flight send is not doubled. An unused redis
   `queue.default` or a broken unused database table must not block a
   healthy empty mail queue — recover must still reclaim. A second database table without `payload` on the unused connection must not look like in-flight mail. A successful
   empty scan of the live mail table must still reclaim even if the unused
   connection is broken. Give-up can leave a campaign
   `failed` with leftover `queued` claims — recover now selects those too,
   reclaims orphans, and puts the campaign back to `sending`. A queued row
   that already has a delivered/failed log FK is synced to that log
   (expire/reclaim both require a null FK, so those rows sat queued forever).
   A timeout after the last `pending` →
   `queued` claim must **not** finalize as sent (`failed()` used to, because
   `sent_count` includes queued). Recount promotes `sending` → `sent` only
   when no pending or queued rows remain and at least one delivery landed. `queued` rows with no email
   log are first reconciled against `email_logs` by
   `audience_campaign:{id}:user:{id}`; a delivered/failed log is attached
   instead of counting as a fake send. Leftovers older than
   `MAIL_CAMPAIGN_MAX_AGE_HOURS` are skipped (`stale`) — a timeout can
   claim `pending` → `queued` and die before `Mail::send()` inserts the
   mailable. Expire must **not** skip a recipient whose
   `AudienceCampaignMail` is still on a readable mail queue (a 72h
   backlog is not a lost job). A later SMTP success or a send suppressed as a duplicate
   still marks the recipient `delivered` (it already went out), including
   when expire already flipped the row to skipped stale. Recover also
   attaches a delivered `email_logs` row to those leftovers. A leftover
   pending Email Center log for a skipped-stale recipient is failed so
   retry can see it — but not while that user's `AudienceCampaignMail` is
   still on the queue, or a second retry doubles the send. A Redis/SQS
   mail queue (unreadable) must leave those pending logs alone.
5. Individual `AudienceCampaignMail` failures mark that recipient `failed`
   (`error`) and recount. If a `sent` campaign later has no queued/delivered
   rows left, status is downgraded to `failed`. A late `marketing_emails`
   opt-out, before the queued mail actually sends, is honored when
   `respect_preferences` is on. If Email Center disables the
   `audience_campaign` type, pending rows are skipped (`disabled`) and the
   campaign ends `failed`.
6. Preview renders a catalog stand-in (not the admin) and a placeholder
   unsubscribe URL. The preview iframe is sandboxed so a click cannot opt
   the operator out.    A dispatch exception marks the campaign `failed` instead
   of leaving it stuck `queued`, but must not overwrite `sent` if a sync
   job already delivered. Do **not** use `ShouldBeUnique` on the send
   job — a stale unique lock silently drops the only dispatch. The `queued` →
   `sending` claim plus per-row `pending` → `queued` is the mutex. Send
   hydrates `id`+`email` only (`collectRecipientRows` via
   `recipientRowQuery` / `recipientBuilder` / `queryForAudienceKey`) so a
   large audience cannot OOM the compose request and a new inventory key
   cannot count N then send nobody. `recipientRowQuery` must exist —
   calling it after a merge that deleted the helper 500s the send after
   the count succeeded. A live user email that is blank, whitespace, or
   missing `@` is dropped from count/collect (MySQL `TRIM` does not strip
   tabs) and failed at send instead of `Mail::to('')`. Stall recovery
   wraps **each** queue connection in its own try/catch so a broken first
   connection cannot hide a job on the other (and must stay valid PHP —
   no extra unclosed `try`, and `recipientRowQuery` / `containsCampaignId`
   must not be declared twice). A scan that throws (lock wait, missing
   `payload` column) must **not** look like “no job” or recover floods
   another send. A successful empty scan of the live jobs table must still
   redispatch even if the unused connection is broken. Live send uses the current `@` address, then the stored
   recipient email from compose, then fails — a profile wipe after queue
   must not drop someone we already counted.
   Email Center retry of a failed campaign mailable clears `email_log_id`
   so a lost retry can still expire as stale. Reviving a `failed` campaign
   must also clear the fail streak — leaving MAX parked the leftover
   pending for recover give-up beside the retried mailable. Bulk retry must mark only one failed log per job UUID — a shared stale stamp plus the same
   `to_email` used to pending-mark two campaigns and reclaim the extra
   recipient beside a single `queue:retry`.
   `user_ids` are integers capped at
   `PICKER_LIMIT * 2` (no `exists:users,id` — a deleted picker row must not
   422 the whole send).

Throttle: preview `20/min`, send `6/min`, recipient-count `30/min`.

## HTML and targeting

- Body is sanitized with `CampaignHtml` (allowlist `p, br, strong, b, em, i, u,
  ul, ol, li, a, h1–h3, blockquote`). Event handlers and `javascript:` / `data:`
  hrefs are dropped. CTA URLs must be `http` or `https`. `&nbsp;`-only bodies
  are blank and rejected before hydrate.
- Campaign `collect()` / `count()` default **`includeUnverified = false`**.
  Audience Inventory census (`paginate` / `export` / `stats()`) still includes
  unverified unless asked otherwise. Inventory cards show **all** plus
  **emailable (verified)** so the compose count matches the subtitle.
- `selected` accepts advertiser/publisher IDs only. Admin and marketing IDs
  are dropped, including dual-role staff (admin+advertiser still must not
  receive “all advertisers” blasts). `queryForRole()` is unchanged so
  deposit / add-site / digest reminders can still reach those accounts.
- Custom picker is capped at 200 users per role (`AudienceInventoryService::PICKER_LIMIT`).
- `advertisers_no_orders` is an alias of `advertisers_never_checked_out` (no
  order row). `advertisers_no_paid_orders` is anyone without a **paid,
  completed, or refunded** order (abandoned checkout stays in; a later
  refund is still a customer). `payment_status=completed` is a paid alias
  (`AdvertiserOrderStatus`). `advertisers_paid_orders` is the inverse.
- Extra inventory / campaign keys: `both`, `advertisers_deposited_no_orders`
  (credited deposit and no paid/completed/refunded order — abandoned
  checkout stays in), `publishers_no_active_sites` (no catalog-visible site:
  active + verified + not archived + not leftover from a cancelled bulk).
  Publisher archive keeps `active=1`, so `active=1` alone is not “live”.
  Tab slugs (`no_orders`, `paid_orders`, …) normalize through
  `AudienceInventoryService::normalizeAudienceKey()` in inventory and in
  campaign send / recipient-count.
- The custom picker lists **verified users first** so unverified names cannot
  crowd them out of the 200-per-role cap. The “showing first 200” warning
  uses the same picker universe (usable emails: not blank/tab-only and
  containing `@`), not the verified-only KPI. Tab-only / no-`@` accounts
  must not appear in the picker or they crowd the cap and then fail at send.
- Inventory search / filters apply to the table and CSV only. **Email this
  audience** still sends the full segment (verified by default).
- Audience CSV is streamed (`chunkById`), UTF-8 BOM, formula-safe cells,
  capped at 10_000 rows, throttled `12/min`, and logged as `audience.exported`.

Do **not** change `queryForRole()` default (still includes unverified). Digests
and add-site / deposit reminders keep their own queries.

## Preferences and stale mail

- `respect_preferences` is a real checkbox (hidden `0` + checkbox `1`). The
  controller uses `$request->boolean('respect_preferences')` with no default
  `true`.
- Preference gate is `marketing_emails` only. Order, payment, and security
  mail stay on. The job pre-checks before queueing; the mailable checks again
  at send time so an unsubscribe that lands in between is still honored.
- Transactional `PlatformMailable` drops after `MAIL_MAX_AGE_HOURS` (24).
  Campaign mail uses `MAIL_CAMPAIGN_MAX_AGE_HOURS` (72). A dropped send marks
  the recipient `skipped` (`stale`, `preference`, or `disabled`).

## Signed unsubscribe

- `GET|POST /email/unsubscribe/{user}` (`email.unsubscribe`), `throttle:30,1`.
- HMAC is signed against the **path only** (`absolute: false`) and prefixed with
  `app_public_url()`, same as verify / deposit-approve links. Default expiry
  `MAIL_UNSUBSCRIBE_EXPIRE_DAYS` (30). The `{user}` segment is numeric; the
  controller checks the signature **before** loading the user so missing ids
  and bad signatures both **403** (no existence leak).
- GET shows a confirm page. The confirm form posts to the **relative**
  request URI (not `fullUrl()`) so a spoofed Host header cannot send the
  signed POST off-site. POST sets **only** `marketing_emails=false`.
- One-click (`List-Unsubscribe=One-Click` or JSON) returns empty **200**.
- CSRF is excepted for `email/unsubscribe/*` (Gmail POSTs have no token).
- Campaign markdown footer + `List-Unsubscribe` / `List-Unsubscribe-Post`
  headers share one cached signed URL. Email Center / compose previews use
  `/email/unsubscribe/preview-id` (not a signed live link). Order receipts
  do **not** get this footer.

## Queue / ops

Campaign jobs and `AudienceCampaignMail` ride the `emails` queue. Same rules as
other platform mail — see [`ops-mail-reminders.md`](ops-mail-reminders.md):

```
php artisan queue:work --queue=default,emails
```

or leave `MAIL_QUEUE_AUTO_DRAIN=true` (default). After deploy, migrate so
`email_campaign_recipients` exists (`ops:production-ready --repair` / first
production page view). `LogSentEmail` will not break other mail if that table
is missing; campaign delivery status just will not sync until migrate runs.

## Tests

```
php artisan test tests/Unit/CampaignHtmlTest.php
php artisan test tests/Feature/AdminCampaignsTest.php
php artisan test tests/Feature/EmailUnsubscribeTest.php
php artisan test tests/Feature/AdminCampaignsDocsTest.php
```
