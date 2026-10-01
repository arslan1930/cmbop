# Admin → Promotions

Staff hub at `/admin/promotions` (marketers: `/marketing/promotions`). Five products:

1. **Site notices** — announcements; max **2** live per audience (`max_live_announcements`).
2. **Ad banners** — **1** per wired slot (`banners_per_placement`), daily crc32 rotation.
3. **Welcome bonus** — admin only.
4. **Feature packages / credits** — admin only. Credits go to publishers for one of their sites.
5. **Marketplace featured / discounts** — read-only list of publisher-set site promotions.

Marketing can create notices and banners. They cannot send Campaigns, toggle welcome credit, or grant feature credits.

## Now showing

Hub **Now on the site** and list **Showing** badges reuse `PromotionService::activeAnnouncements()` / `activeBanners()` (same take and rotation as the public site). Extra live notices are **Live, not shown**. Extra live banners with a safe image are **Not today’s rotation**. Preview is a sandbox (no real chrome, no impression counts). A placement that is not in `wired_placements` for that audience shows no banner.

## Email handoff

**Open in Campaigns** (admin only) copies notice title/body/CTA into Campaigns compose for marketplace advertisers/publishers. Audience `all` → both roles. Audience `public` has no email list.

## List Back

Announcement and banner list filters are stored in session (`AdminPromotions`). Create/edit **Back** and post-save redirects keep those filters.

List GET bars do **not** set `data-admin-filter-live=1`, so theme-selects submit the filter. Create/edit and the hub feature-credit form keep `live=1` so a theme-select does not POST the editor.

## Feature credits

The publisher dropdown is publishers who already have sites. Sites load from `GET /admin/promotions/feature-credit-sites?user_id=`. Grant still requires `site.publisher_id === user_id`.
