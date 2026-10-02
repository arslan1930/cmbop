# Visitor support chat

Public + advertiser/publisher help widget. Order chat (advertiser ↔ publisher) is a separate product. Admin layouts never embed this.

## Which widget shows

1. `TAWK_PROPERTY_ID` set **and** first-party off (default) — official Tawk with the branded Lottie launcher. The page paints teal platform colors (`#1a585e`) and role questions before the iframe shows. Do not overlay question chips.
2. `SUPPORT_CHAT_ENABLED=true` — first-party panel (`#slbLiveChat`). Tawk is not loaded even if a property ID is set.
3. Both off — help / feedback FAB only.

Empty `TAWK_PROPERTY_ID` is the Tawk off switch. Do not put a property ID in committed defaults.

Leftover Hostinger layouts that omit `@include('partials.tawk')` still get the snippet via `EnsureVisitorChat`.

## First-party

`SUPPORT_CHAT_PROVIDER=local` (canned reply + email to the support inbox), `http`, or `openai`. Keys stay server-side; the browser only posts to `/support/chat`.

Role chips (guest / advertiser / publisher) are rendered in the panel. Status copy is “Usually replies by email” for local, or “Usually replies in a few minutes” for http/openai.

Each message has a LinkedIn-style `:)` trigger. Hover opens 👍 Like, ❤️ Love, 😂 Funny, 😮 Wow, 🙏 Thanks. Typing `:)` `<3` `:D` `:o` in the composer becomes those same five. Tawk’s iframe cannot host this tray.

## Tawk dashboard (theme + copy)

The iframe is Tawk’s. The embed intercepts `va.tawk.to/v1/widget-settings` (and session start) and writes our theme plus role questions into that JSON so the conversation is not Tawk green and does not keep “I have a question”. Dashboard Save still wins for later edits; this page pass is what visitors see without a dashboard login. Do not overlay question chips.

### Appearance

Administration → Channels → **Chat Widget** → Widget Appearance → **Advanced** → Widget Colors:

| Field | Value |
|---|---|
| Header | `#1a585e` (header + suggested-message text) |
| Header Text | `#ffffff` (header text + suggested-message fill) |
| Accent / buttons | `#3faeb2` |
| Agent message | `#e6f5f5` |
| Agent text | `#1a585e` |
| Visitor message | `#1a585e` |
| Visitor text | `#ffffff` |

Save, then hard-refresh the site.

### Details

Administration → Chat Widget → **Edit Content**:

- Header title **SEOLinkBuildings** (not “Customer Support”)
- Greeting: `Hi! How can we help with guest posts, wallet, or your sites?`
- Logo, pre-chat name + email, offline message, locale
- Hide “Powered by tawk.to” only if the plan allows it

### Suggested messages (inside the thread)

Triggers / Shortcuts / Suggested messages — replace “I have a question” / “Tell me more” with the role questions the page already sends as `predefined_1`…:

- Guest: How do I create an account? / How does the marketplace work? / What does a placement cost?
- Advertiser: How do I place an order? / How does the wallet work? / Where do I track a live URL?
- Publisher: How do I add a website? / When do payouts arrive? / How do I accept an order?

Use Tawk tags or triggers on `role` (`guest` / `advertiser` / `publisher`) so each audience sees the matching set.

The page still passes `Tawk_API.visitor` (name + email when logged in) and `setAttributes` / `addTags` for `role`, `user_id`, and `page`. Unread agent replies badge the Lottie launcher.

On Hostinger keep `SUPPORT_CHAT_ENABLED=false`, set `TAWK_PROPERTY_ID=…` and `TAWK_WIDGET_ID=default`, then `php artisan config:clear`.
