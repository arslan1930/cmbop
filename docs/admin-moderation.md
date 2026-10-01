# Admin → Moderation

Staff desk at `/admin/moderation`. This is **policy + scan logs**, not a human inbox. Article edits and checkout live in **Content Library**.

## Queue vs log

The default list is every scan. **Needs decision** is the current reject or error only:

- not skipped (`signals.moderation_disabled`)
- not already overridden
- still the article’s `moderation_log_id` (URL-only rows with no submission count as current)

Older rejects on the same article stay in All / Rejected. They are not the queue.

## KPIs

**Approved** is a real pass: `status=approved`, not skipped, `admin_override=false`. Overrides use the **Overridden** tile and filter. Dashboard **Moderation errors** still links `status=error` (all error rows, not the queue).

Tiles and the status dropdown share those definitions. Default filter is **All**.

## List and detail

Rows show the article title (or host / scan id), a user link to Admin → Users, result, and **View**. Override notes are on the detail page only. **Revert** stays on the list for the current override.

List filters are stored in session (`AdminModeration`). Detail **← Moderation** and post-save redirects keep them. Theme-selects do not auto-submit this GET bar (`data-admin-filter-live=1`).

## Settings

**Save policy** writes `config_override`, keywords, exceptions, and category lists only. **Save upload settings** writes `upload_config` only (uploads kill-switch, language rule, uniqueness, retention, scheduling). One form no longer overwrites the other.

## Test scan and re-scan

**Test scan** scores pasted text or an http(s) URL against the saved policy. It does not write a log and does not touch checkout.

**Re-scan** is for **error** rows only. A linked article is scanned from stored text; a URL-only row is fetched again with `force`. The new log becomes the current decision.
