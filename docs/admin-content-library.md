# Admin → Content Library

Staff desk at `/admin/content-library`. This is the **article desk** (preview, override, archive, retry). Policy settings and scan logs live under **Admin → Moderation**.

## Status chips

**Approved** means checkout-ready: file, market, rights, and a valid link pair — not only that a scan passed. A staff override can still leave an article on **Needs corrections**.

**All** omits unused expired articles. Those appear only on **Expired**.

## List Back

Filters (`availability`, advertiser, market, sort, on-order, expiring, upload dates, search) are stored in session (`AdminContentLibrary`). Detail **← Content Library** uses the current URL when it has filters, otherwise the last list.

When live search is on (`content_library.live_search.enabled`), the filter bar sets `data-admin-filter-live="1"` so theme-selects do not full-page GET; the library script reloads the fragment. Pager links stay on the index URL (not `/results`). Turn live search off and the live attribute is omitted so theme-selects submit the GET form.

## Bulk and export

Select a page of rows (up to `content_library.bulk_limit`, default 50). Bulk can re-evaluate, archive, or restore. Failures name the article ids.

Export CSV is the **first 2000** matching rows (id, title, email, market, availability, moderation, scores, order, file-on-disk, expiry).

## Detail

Staff actions stay on the article. **Revert override** is on the current scan in Moderation. Scan history lists recent logs for this article.
