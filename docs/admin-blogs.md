# Admin → Blogs

Staff desk at `/admin/blogs`. Public SEO posts and daily updates for the marketing blog — not Campaigns mail and not Content Library articles.

## Sync vs create

Code deploy does not insert curated pillar rows. **Sync curated SEO blogs** (or `php artisan blog:upsert-curated`) loads unedited pillars from code. Posts you already edited stay yours; deleted pillars stay deleted. **Create New Blog** is a custom post.

## List and editor

Filters (search, status, primary locale, curated/custom, incomplete translations, sort) live in `AdminBlog`. Create/edit **Back** and the create link keep that query.

Theme-selects on the list GET bar submit the form. The create/edit POST form uses `data-admin-filter-live=1` so locale/theme picks do not save the post.

## Locales

English is always a tab. Extra locales follow `AdminBlog::formLocales()` (primary, existing translations, `?add_locale=`). Unpublished drafts stay off the public blog until you publish.
