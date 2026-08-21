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
   if the jobs-table scan missed the retried job. Hold uses the campaign
   + user pair (meta or `audience_campaign:{id}:user:{id}`), not only the
   exact dedupe string — a leftover generic
   `audience_campaign|{email}|AudienceCampaignMail` retry still blocks
   reclaim. An unreadable email_logs table must not look like “no pending retries” — reclaim fail-closes the same way as a failed jobs-table scan. A matching
   `failed_jobs` AudienceCampaignMail is also held — that row is still
   retryable from Email Center. A
   Redis/SQS **mail** queue, inline SMTP (`sync` mail), a missing `payload` column on the **mail**
   table, or a mailable whose user id cannot be parsed is fail-closed: the
   row stays queued so an in-flight send is not doubled. An `AudienceCampaignMail` that only serializes the campaign as a ModelIdentifier (no `campaignId` property and no `dedupeKey`) must still count as in-flight so reclaim does not treat that jobs row as empty, and expire must not fail a pending campaign log beside that jobs row. A leftover generic-key pending log must also match that ModelIdentifier job via campaign+user identity — token-only matching failed the Email Center row and a later retry doubled the send. An unused redis
   `queue.default` or a broken unused database table must not block a
   healthy empty mail queue — recover must still reclaim. A second database table without `payload` on the unused connection must not look like in-flight mail. A successful
   empty scan of the live mail table must still reclaim even if the unused
   connection is broken. Give-up can leave a campaign
   `failed` with leftover `queued` claims — recover now selects those too,
   reclaims orphans, and puts the campaign back to `sending`.    A queued row
   that already has a delivered/failed log FK is synced to that log
   (expire/reclaim both require a null FK, so those rows sat queued forever).
   Heal must look up by `audience_campaign:{id}:user:{id}` **or** `latestDeliveredForCampaignUser()` (generic-key siblings), not only the attached id — an attached failed or leftover pending log FK must not beat a delivered log and must not beat a delivered sibling for the same recipient (that marked a real send failed and a later compose doubled it). Heal must also treat a leftover generic-key pending sibling as in-flight — attaching only the failed FK marked a live Email Center retry failed and a later compose doubled it. Heal runs again after pending-log expire so a leftover pending FK closed in this pass can sync the same recover.
   A timeout after the last `pending` →
   `queued` claim must **not** finalize as sent (`failed()` used to, because
   `sent_count` includes queued). Recount promotes `sending` → `sent` only
   when no pending or queued rows remain and at least one delivery landed. `queued` rows with no email
   log are first reconciled against `email_logs` by
   `audience_campaign:{id}:user:{id}` **or** `meta.campaign_id` +
   `meta.user_id`;   a delivered/failed log is attached
   instead of counting as a fake send. A delivered log is attached even when the queued row is younger than the stall window — waiting two minutes let reclaim reset that leftover to pending and dispatch a second send. Failed-log attach still waits. A leftover recipient timestamp must not abort recover — unreadable clocks skip failed-log attach so an in-flight retry is not killed. Reclaim and expire also hold user ids from `deliveredUserIdsForCampaign()` and `pendingUserIdsForCampaign()` (null means email_logs could not be read — do not reclaim or skip-stale), including leftover generic-key rows that only store the pair in meta — a canonical-only `audience_campaign:{id}:user:` scan used to miss those and blast again. `reconcileOneQueuedRecipientFromLogs` must `return`, not `continue` — that body is no longer inside the foreach and `continue` fatals class load. A historical send that wrote the
   generic default key must still attach — exact-key lookup used to miss
   it, reclaim reset the row to pending, and the next job blasted again. Reconcile must also call `latestDeliveredForCampaignUser()` when the grouped extras miss a generic-key sibling — a failed `meta->campaign_id` JSON scan used to leave that queued row forever. The parent must still walk each queued row when that group is empty so the fallback runs. Sibling lookup must not scan the newest 100 campaign emails site-wide — a later burst hid a leftover generic-key delivery and `isDuplicate()` blasted again. Sibling dedupe must **not** treat that shared generic key as one-shot across campaigns, and must not look like “no prior delivery” when email_logs cannot be read — the send is held instead of blasting again. A delivered log still wins when a
   pending Email Center row exists for the same key — skipping that attach
   let expire mark a real send stale, and a later retry doubled it.
   A delivered log is attached even when the queued row is younger than the stall window — waiting let reclaim dispatch a second send.
   Leftovers older than
   `MAIL_CAMPAIGN_MAX_AGE_HOURS` are skipped (`stale`) — a timeout can
   claim `pending` → `queued` and die before `Mail::send()` inserts the
   mailable.    Expire must **not** skip a recipient whose
   `AudienceCampaignMail` is still on a readable mail queue (a 72h
   backlog is not a lost job; a second retry doubles the send).    Expire
   must also hold a queued row that has a **fresh** pending Email Center
   log — reclaim already did, but expire skip-stale + fail-pending made
   a just-retried mailable look lost when the jobs-table scan missed it,
   and a second retry doubled the send. A leftover generic
   `audience_campaign|{email}|AudienceCampaignMail` pending row still
   holds via `meta.campaign_id` + `meta.user_id`. A pending log older
   than `MAIL_CAMPAIGN_MAX_AGE_HOURS` is a lost retry and still expires. A matching row in `failed_jobs` still
   blocks reclaim (Email Center retry would double) but must **not** block expire — that job already died, and treating it as in-flight
   left the recipient `queued` past `MAIL_CAMPAIGN_MAX_AGE_HOURS`. A later SMTP success or a send suppressed as a duplicate
   still marks the recipient `delivered` (it already went out), including
   when expire already flipped the row to skipped stale. `MessageSent` and
   `abandonOpenLog` also close leftover pending/failed Email Center rows
   for the same campaign + user when the dedupe strings differ (generic
   `audience_campaign|{email}|AudienceCampaignMail` vs
   `audience_campaign:{id}:user:{id}`) — leaving those open made retry
   a second blast. Preference, disabled, and unverified skips stay skipped — a stray `MessageSent`
   or duplicate suppress must not hide an opt-out as a successful send.
   Recover also attaches a delivered `email_logs` row to those leftovers, including queued rows younger than the stall window. Expire must not skip-stale a queued recipient who already has a delivered log. A leftover pending Email Center log for a skipped-stale recipient is failed so retry can see it — but not while that user's `AudienceCampaignMail` is still on the queue, or a second retry doubles the send. Lost transactional pending logs (Welcome / orders) with no campaign recipient are failed after the mail age window when no matching `SendQueuedMailable` is on a readable database queue — retry only accepts failed. An unused queue table without `payload` must **not** abort that expire or those Welcome rows stay pending forever.
5. Individual `AudienceCampaignMail` failures mark that recipient `failed`
   (`error`) and recount. A worker timeout after SMTP already succeeded must **not** invent a leftover failed Email Center log — retry would blast again. Leftover open logs for the same send are closed as delivered, not failed. Bulk retry must also skip a leftover job that only serializes ModelIdentifier campaign+user ids after that send already delivered. If a `sent` campaign later has no queued/delivered
   rows left, status is downgraded to `failed`. A late `marketing_emails`
   opt-out, before the queued mail actually sends, is honored when
   `respect_preferences` is on. An unreadable marketing preference is treated as an opt-out so that check cannot fail-open. If Email Center disables the
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
   pending for recover give-up beside the retried mailable.    Bulk retry must mark only one failed log per job UUID — a shared stale stamp plus the same
   `to_email` used to pending-mark two campaigns and reclaim the extra
   recipient beside a single `queue:retry`. Closing a leftover already-delivered log must also drop that job UUID from the retry list — a shared stale stamp would otherwise pending-mark the other campaign.
   Dropping that job must use campaign+user identity (canonical
   `audience_campaign:{id}:user:{id}` or a ModelIdentifier), not the shared
   `audience_campaign|{email}|AudienceCampaignMail` string — that leftover must not swallow another campaign's failed job.
   Closing a leftover must not treat another campaign's generic-key delivery as this send.
   A leftover campaign job without an extractable payload dedupe key must still be skipped when that leftover owns it, including on a later bulk click after the leftover was already closed, and a stale stamp must not suppress a Welcome job.
   An empty campaign `dedupeKey` must still use the one-shot `audience_campaign:{id}:user:{id}` key — `parent::send()` used to fill `audience_campaign|{email}|AudienceCampaignMail`, so `isDuplicate()` missed a real delivery.
   `user_ids` are integers capped at
   `PICKER_LIMIT * 2` (no `exists:users,id` — a deleted picker row must not
   422 the whole send).

Throttle: preview `20/min`, send `6/min`, recipient-count `30/min`.
Draft save / update / delete / duplicate are `20/min`.

## Drafts folder

One Drafts folder (`GET /admin/campaigns/drafts`). There are no nested
folders. Compose **Save draft** (`POST /admin/campaigns/drafts`, or
`POST /admin/campaigns/drafts/{id}` when editing) uses the same sanitize /
CTA / audience rules as send. It must **never insert recipient rows on a draft**
— `recoverStalled()` only looks at queued / sending /
sent-with-inflight, and leftover pending rows on a draft would be a
footgun. `include_unverified` is stored on `email_campaigns` so a reopened
draft keeps the checkbox.

Tabs on compose and the folder: **Compose · Drafts (N) · Sending · Sent**.
Opening `/admin/campaigns/{id}` for a draft redirects to
`admin.campaigns.drafts.edit` (the compose form). Show stays a delivery
report — there is still **no** resend-all.

**Send clones.** Optional `draft_id` on `POST /admin/campaigns/send` must
be an editable draft. Send creates a new `queued` campaign, recounts the
live audience, inserts recipients, and dispatches `SendEmailCampaignJob`.
The original draft is unchanged. Two sends from one draft are two queued
campaigns. Folder Preview posts the **saved** subject / body / CTA to the
existing `campaigns.preview` endpoint. Duplicate
(`POST /admin/campaigns/{id}/duplicate`, `canDuplicate()`) copies a draft
or a sent/failed campaign into a new draft named “{name} copy” with no
recipient rows. Queued and sending cannot be duplicated.

Compose and each Drafts row show a live **~N emailable** chip via
`POST /admin/campaigns/recipient-count` (same count as the send confirm).
Do **not** put `data-slb-confirm` on Send — confirm is still imperative
`slbConfirm()` after that count. Marketing stays redirected; advertisers
stay 403.

## Campaign show (recipients)

`GET /admin/campaigns/{campaign}` (`admin.campaigns.show`) lists paginated
recipients with status and `skip_reason` (preference, unverified, error,
stale, disabled, staff). Failed rows link to Email Center (`emails.log`
when `email_log_id` is set, otherwise the filtered recent-log list). There
is **no** resend-all or send button on this page — retry a single failed
mailable from Email Center. The recent-campaigns table on compose links
here. Compose KPI cards include paid customers, deposited / no paid
orders, and publishers with no active sites (same keys as the audience
dropdown).

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
  receive “all advertisers” blasts). The send job and `AudienceCampaignMail`
  re-check staff roles at send time so a promotion after compose cannot
  sneak a staff inbox onto a queued blast. An unreadable roles lookup is treated as staff so that check cannot fail-open. `queryForRole()` is unchanged so
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
  audience** still sends the full segment (verified by default). When any
  filter is active the inventory page shows a warning; the Email button
  still links to compose for the whole tab (no filter query string).
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
  Transactional `isDuplicate()` must not look like “no prior send” when
  `email_logs` cannot be read — the send is held instead of blasting a
  Welcome / order retry again.

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
php artisan test tests/Feature/AdminAudienceInventoryTest.php
php artisan test tests/Feature/EmailUnsubscribeTest.php
php artisan test tests/Feature/AdminCampaignsDocsTest.php
```
