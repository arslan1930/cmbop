# Admin → Catalog Activity

Staff desk at `/admin/catalog-activity` (System nav). Hide-mode / copy-strike queue plus hide-mode eye / visit / cart unlocks. **Everyday catalog browsing is not logged.** Marketers do not see this page.

## Two clocks

**Needs attention** (copy table) is always the last **14 days**: in hide now, or warned / served hide in that window. **All** is everyone still on the strike ladder.

**Unlocks** (and the 1 / 7 / 14 / 30 day chips) use `days` (default **7**, max 90). The copy-table Unlocks column is that same window, not the 14-day attention window.

## Pin vs search

Admin bells and Users 360 use `?user=`. That pin highlights both tables and can append a clean account. Typing a search (`q`) **drops** the pin so a leftover query param does not keep pinning the wrong row.

Details → **Back to queue** restores the last index filters (`days`, `copy`, `q`, `user`) from session. If you never opened the queue this visit, or the session only has a **different** `?user=` pin, Back uses `?user=` for the account you are viewing.

## Staff actions

- **Lift hide** — names and URLs show again. Strikes and copy history stay. Pace **trust is cleared**, including leftover trust after hide already ended (exemption is only meaningful while hide is on).
- **Reset strikes** — ladder back to zero. Hide stays on until you lift it. Copy history is kept.
- **Mark as trusted** — pauses pace checks for `catalog.url_reveal.pace.exemption_minutes` while hide is on.
- Deprecated `POST .../clear-copy-hide` still lifts hide **and** resets strikes for old bookmarks, and also clears trust.

**History** on Details is Activity History pinned to `user_id`. Unlock IPs stay off this HTML table. Live sites deep-link; gone sites show **Removed**.

## Caps

Copy queue shows at most **100** rows, unlocks **50**. When capped, the table says so — narrow with search. Details lists the last 50 copies and last 50 unlocks.
