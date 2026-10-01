# Visitor support chat

Public + advertiser/publisher help widget. Order chat (advertiser ↔ publisher) is a separate product. Admin layouts never embed this.

## Which widget shows

1. `SUPPORT_CHAT_ENABLED=true` (default) — first-party panel (`#slbLiveChat`). Tawk is not loaded even if a property ID is set.
2. First-party off **and** `TAWK_PROPERTY_ID` set — Tawk.to fallback with the branded Lottie launcher.
3. Both off — help / feedback FAB only.

Empty `TAWK_PROPERTY_ID` is the Tawk off switch. Do not put a property ID in committed defaults.

Leftover Hostinger layouts that omit `@include('partials.tawk')` still get the snippet via `EnsureVisitorChat`.

## First-party

`SUPPORT_CHAT_PROVIDER=local` (canned reply + email to the support inbox), `http`, or `openai`. Keys stay server-side; the browser only posts to `/support/chat`.

Role chips (guest / advertiser / publisher) are rendered in the panel. Status copy is “Usually replies by email” for local, or “Usually replies in a few minutes” for http/openai.

## Tawk dashboard (when Tawk is the fallback)

Code no longer rewrites Tawk’s widget-settings fetch. Brand the widget in Tawk:

- Widget name **SEOLinkBuildings** (not “Customer Support”)
- Logo, greeting, and triggers (guest-post / wallet / sites)
- Guest pre-chat name + email
- Offline message and locale if you use them
- Hide “Powered by tawk.to” only if the Tawk plan allows it — do not CSS-hack the iframe

The page passes `Tawk_API.visitor` (name + email when logged in) and, after load, `setAttributes` / `addTags` for `role`, `user_id`, and `page`. Unread agent replies show as a badge on the launcher; the document title is left alone.

Set on Hostinger only when you want Tawk: `SUPPORT_CHAT_ENABLED=false`, `TAWK_PROPERTY_ID=…`, `TAWK_WIDGET_ID=default` (or your widget id), then `php artisan config:clear`.
