# SEOLinkBuildings blog topic plan

**Date:** 29 Sep 2026  
**Role of this file:** the writing queue. It is not a keyword-research file and it does not replace one.  
**Sources:** the uploaded market files dated 24 Sep 2026 (Germany, Austria, Switzerland, Italy, United Kingdom, United States, Ireland, Belgium). Scores below are copied from those files. They are heuristic 0–100 bands, not Semrush monthly volume and not tool keyword difficulty.  
**Already shipped:** `/de/blog/was-ist-ein-gastbeitrag` (primary `was ist ein gastbeitrag`).

Greek, Spanish, Romanian, French-for-France, and Dutch-for-the-Netherlands are not in this queue. No keyword file for those markets was uploaded, so no primary is invented for them. Belgium Dutch is a later wave on locale `be`. The French Belgium primary stays on hold because there is no `fr-BE` locale.

---

## How each page is written

Same bar as the German glossary page. A page that misses one of these is not ready.

- One primary keyword, taken from the research file, in the H1, the SEO title, the meta description, and the first 150 words.
- The first paragraph is the definition: 40–60 words, non-promotional, class `glossary-definition`.
- Body about 1,500–2,500 words. Written in that language from scratch. Not a translation of the German page, and not the same sentence order.
- SEO title 50–70 characters. Meta description at most 180 characters. The H1 can be short; the length limit is the SEO title.
- One money link, descriptive anchor, to a page that already exists. Two or three sister links. No second money URL.
- Buy-intent queries stay off the H1. They are answered in a short section that points at the money page. Examples that are not glossary H1s: `gastbeitrag kaufen`, `was kostet ein gastbeitrag`, `come comprare guest post in sicurezza`, `how to buy backlinks safely`, `how to buy backlinks uk`, `comment acheter des backlinks belgique`.
- No invented euro prices, market shares, monthly search volumes, or keyword difficulty. No ranking promise. A live URL exists after publication, not on the price list. Niche edits are not a product.
- Austria and Switzerland do not get their own “what is a guest post” URL. `was ist ein gastbeitrag seo`, `was ist ein gastbeitrag ch`, and `gastbeitrag ss schreibweise schweiz` are sections of the German page, which is already published. Austria uses ß, like Germany. Only Switzerland uses ss.
- Tables where a comparison is the point. FAQ headings are real questions from the research, rendered as `h3`, not FAQ schema.
- Diagrams are authored in the language of the page. Not an AI image, and not a fake translation of the English dashboard. The logged-in product stays English, so product screenshots stay English.
- Record the brief fields on the page class (`GuestPostingGuideI18n::seoRecords()` is the pattern) when the page is saved.

The research files mark many of these rows “skip for launch week”. That note means “after the money pages”, not “never”. The money pages for these markets already exist, so the glossary queue is the educational layer that links to them.

---

## Queue

Upgrade an existing URL before opening a new one. A secondary keyword that already has a home as a section does not get a second article.

| Order | Status | Market | Primary (one) | Opp | Demand / traffic | Difficulty | Where it goes |
|------:|--------|--------|---------------|----:|------------------|------------|---------------|
| 0 | Shipped | DE | `was ist ein gastbeitrag` | 56 | Medium / 40 | 25 Easy | `/de/blog/was-ist-ein-gastbeitrag` |
| 1 | Next | IT | `cos'è un guest post` | 48 | High / 55 | 50 Medium | Upgrade `/it/blog/cose-un-guest-post` |
| 2 | Upgrade | DE | `was sind backlinks` | 55 | High / 53 | 45 Medium | Upgrade `/de/blog/was-sind-backlinks` |
| 3 | New URL | IT | `cos'è un backlink` | 50 | Medium / 40 | 25 Easy | New `/it/blog/cose-un-backlink`. Do not reuse `come-ottenere-backlink` |
| 4 | Upgrade | DE | `linkaufbau strategien` | 53 | Medium / 40 | 35 Easy | Upgrade `/de/blog/linkaufbau-strategien`. `wie funktioniert linkbuilding` (also opp 53) is an H2 on this page, not a second URL |
| 5 | Upgrade | IT | `differenza guest post e articolo sponsorizzato` | 50 | Low / 25 | 25 Easy | Upgrade `/it/blog/guest-post-vs-articolo-sponsorizzato` |
| 6 | Upgrade | DE | `dofollow vs nofollow` | 52 | Medium / 38 | 35 Easy | Upgrade `/de/blog/dofollow-vs-nofollow-ankertext` |
| 7 | Upgrade | IT | `dofollow vs nofollow` | 53 | Medium / 40 | 35 Easy | Upgrade `/it/blog/dofollow-vs-nofollow` |
| 8 | Upgrade | US + UK | `what is a guest post` (US, opp 59) | 59 | High / 53 | 35 Easy | Rewrite the opening of `/blog/guest-posting-guide`. No second English definition |
| 9 | Upgrade | US + UK | `what is link building` (both files, opp 59) | 59 | High / 53 | 35 Easy | Rewrite the opening of `/blog/link-building-guide`. No `/blog/what-is-link-building` |
| 10 | Later | BE | `wat is een gastblog` | 56 | Medium / 40 | 25 Easy | New locale `be`: `/be/blog/wat-is-een-gastblog`. Not `/nl/blog/gastbloggen-gids` |
| 11 | Later | BE | `wat is linkbuilding` | 56 | High / 55 | 45 Medium | New locale `be`: `/be/blog/wat-is-linkbuilding`. Competition in the file is High. Not `/nl/blog/linkbuilding-gids` |
| 12 | Hold | BE-FR | `qu'est-ce qu'un guest post` | 56 | Medium / 40 | 25 Easy | No `fr-BE` locale. Do not publish it on `/fr/blog/guide-guest-posting` |
| 13 | Later | IT | `cos'è lo ZA SEOZoom` | 56 | Medium / 40 | 25 Easy | New page only after rows 1, 3, 5, and 7. `metriche SEO DA DR ZA` (difficulty 40 Medium, opp 51) is a section, not a rival H1 |
| 14 | Later | DE | `domain authority erhoehen` | 52 | Medium / 38 | 35 Easy | New page. DA is a third-party score, not a ranking promise |

`cosa sono i guest post` scores higher (opp 50, difficulty 25 Easy) than the Italian H1 `cos'è un guest post` (opp 48). It stays an H2 on that same page. Opening a second Italian definition would split the URL the catalog already uses.

The file tags `was sind backlinks` as language `en` because “backlink” is a loanword. The query is German. The page is written in German.

---

## 0. Shipped — Was ist ein Gastbeitrag?

| Field | Value |
|-------|--------|
| Language / country | de / DE (Austria and Switzerland are sections, not extra URLs) |
| Primary | `was ist ein gastbeitrag` |
| Secondary, already in the page | `dofollow vs nofollow`, `dofollow`, `was sind backlinks`, `gastbeitrag schreiben tipps`, `anchor text strategie`, `rel sponsored bedeutung` |
| Long-tail, DACH section only | `was ist ein gastbeitrag seo`, `was ist ein gastbeitrag ch`, `gastbeitrag ss schreibweise schweiz` |
| Intent | informational |
| Difficulty | 25 Easy. Heuristic, not Semrush KD |
| Demand / traffic potential | Medium / 40. Heuristic, not monthly visits |
| Opportunity / competition / business fit | 56 / Low / Medium |
| SEO title (55) | Was ist ein Gastbeitrag? Definition, Ablauf und Grenzen |
| Meta description (159) | Was ist ein Gastbeitrag: ein Artikel auf einer fremden Website, oft mit Link. Unterschied zu Advertorial und Linkkauf, und was Redaktionen im DACH-Raum prüfen. |
| H1 | Was ist ein Gastbeitrag? |
| Slug | `was-ist-ein-gastbeitrag` |
| Money link, once | `/de/gastbeitrag-kaufen` |
| Sisters | `/de/blog/was-sind-backlinks`, `/de/blog/gesponserte-beitraege-leitfaden`, `/de/blog/dofollow-vs-nofollow-ankertext` |

Do not retitle this page toward `gastbeitrag kaufen` or `was kostet ein gastbeitrag`. Those belong on the money page.

---

## 1. Next — Cos'è un guest post?

Write this independently. Do not translate the German article. Italy has no ß/ss section and no DACH paragraph.

| Field | Value |
|-------|--------|
| Language / country | it / IT |
| Topic | Cos'è un guest post |
| Primary | `cos'è un guest post` |
| Secondary (H2, not a new URL) | `cosa sono i guest post` — Medium, traffic 40, difficulty 25 Easy, competition Low, opportunity 50, business fit Low, effort M |
| Long-tail used only as sections | `guest post SEO guida` (Medium, 40 / 35 Easy, opp 47); `anchor text guest post` (Low, 25 / 25 Easy, opp 44) |
| Intent | informational. The research row calls it a TOFU magnet and says to publish the guide after money pages, with a catalog CTA |
| Difficulty | 50 Medium. Heuristic, not Semrush KD |
| Demand / traffic potential | High / 55. Heuristic, not monthly visits |
| Opportunity / competition / business fit / effort | 48 / Medium / Low / L |
| Density guidance from the file | 0.5–1.0% (informational target guidance) |
| H1 | Cos'è un guest post? |
| SEO title (55) | Cos'è un guest post? Definizione, i passaggi e i limiti |
| Meta description (146) | Cos'è un guest post: un articolo su un sito altrui, spesso con un link. Differenza da advertorial e acquisto link, e cosa controlla una redazione. |
| Slug | `cose-un-guest-post` (existing; do not add a second slug) |
| Money link, once | `/it/comprare-guest-post` — anchor that names an Italian placement with its own euro price, not “guest post” repeated as the only anchor |
| Sisters | `/it/blog/come-ottenere-backlink`, `/it/blog/guest-post-vs-articolo-sponsorizzato`, `/it/blog/dofollow-vs-nofollow` |

**Shape (Italian, not the German outline):**

1. Definition, 40–60 words: an article on a site the author does not own. The host keeps the URL and the readers. The author supplies the text and is usually named. A link back is common. It is not an advertorial and not a bare link purchase.
2. Why the plural question `cosa sono i guest post` is the same format, not a different product.
3. Comparison table: guest post / articolo sponsorizzato / pubbliredazionale / acquisto del solo link. `pubbliredazionale SEO` (Low, 25 / 25 Easy, opp 44) is a row, not an H1.
4. What an Italian desk actually checks before it accepts a pitch: readers, recent articles, whether the future URL can be indexed, whether `rel` is agreed before publication.
5. How one placement runs, in Italian steps. The diagram labels are Italian (Trovare siti, Valutare, Proporre, Scrivere, Pubblicare, Ricontrollare). Do not reuse the German six-step wording.
6. A short attribute section, then the sister link to dofollow / nofollow / `rel sponsored`. `rel sponsored cosa significa` (Low, 25 / 25 Easy, opp 50, business fit Medium) can be one H3 here. It does not need its own page yet.
7. Price: no single euro figure and no invented market share. Each placement has its own price. Fattura and IVA are mentioned only as the practical paperwork a buyer meets, not as a rate. The live URL exists after publication. One link to `/it/comprare-guest-post`.
8. `come comprare guest post in sicurezza` is a question inside the FAQ. The answer points at the money page. It is not the H1.
9. Mistakes, a short close, and the same public Google sources already used on the German page (spam policies, the 2019 nofollow post, helpful content). Do not invent a Google quotation.

**Image:** the Italian workflow SVG already on the translated guide. Alt and caption in Italian. `ai_generated` false. Do not put the English “Guest Article Draft / Use American English” photo back on this URL.

---

## 2. Upgrade — Was sind Backlinks?

The current `/de/blog/was-sind-backlinks` is the translated how-to. Replace the opening with a definition. Keep the slug.

| Field | Value |
|-------|--------|
| Language / country | de / DE |
| Primary | `was sind backlinks` |
| Intent | informational |
| Difficulty | 45 Medium |
| Demand / traffic potential | High / 53 |
| Opportunity / competition / business fit | 55 / High / Medium |
| H1 | Was sind Backlinks? |
| SEO title (54) | Was sind Backlinks? Definition, Arten und ihre Grenzen |
| Meta description (135) | Was sind Backlinks: ein Link von einer anderen Website auf Ihre URL. Welche Arten es gibt, und warum ein gekaufter Link kein Zitat ist. |
| Slug | `was-sind-backlinks` |
| Money link, once | `/de/backlinks-kaufen` |
| Sisters | `/de/blog/was-ist-ein-gastbeitrag`, `/de/blog/linkaufbau-strategien`, `/de/blog/dofollow-vs-nofollow-ankertext` |

Sections, not new URLs: `permanenter backlink bedeutung` (Low, traffic 25, difficulty 25 Easy, opp 44), `backlinks aufbauen anleitung` (Medium, traffic 38, difficulty 35 Easy, opp 52), `spam score backlinks pruefen` (Low, traffic 23, difficulty 25 Easy, opp 43). `backlinks aufbauen österreich` and `backlinks aufbauen schweiz` are both Medium, traffic 38, difficulty 30 Easy, competition Low, opp 54, business fit Medium. They are two short local paragraphs on this German page, the way the guest-post page treats Austria and Switzerland. They do not become `/at/` or `/ch/` blog posts.

---

## 3. New — Cos'è un backlink?

`come ottenere backlink` (High, traffic 55, difficulty 35 Easy, competition Low, opp 54, business fit Low, effort L) is a how-to and already has `/it/blog/come-ottenere-backlink`. The definition is a different query. Give it its own URL and link the two pages together.

`come fare link building` has the same band and already has `/it/blog/come-fare-link-building`. That page stays the how-to. `digital PR SEO` (Medium, traffic 40, difficulty 35 Easy, opp 53), `off-page SEO Italia`, `link building white hat`, and `SEO off-site` (each Medium, traffic 40, difficulty 35 Easy, opp 47) are sections of that how-to, not new URLs.

| Field | Value |
|-------|--------|
| Language / country | it / IT |
| Primary | `cos'è un backlink` |
| Intent | informational |
| Difficulty | 25 Easy |
| Demand / traffic potential | Medium / 40 |
| Opportunity / competition / business fit / effort | 50 / Low / Low / M |
| H1 | Cos'è un backlink? |
| SEO title (54) | Cos'è un backlink? Definizione, i tipi e i loro limiti |
| Slug | `cose-un-backlink` |
| Money link, once | `/it/comprare-backlink` |
| Sisters | `/it/blog/cose-un-guest-post`, `/it/blog/come-ottenere-backlink`, `/it/blog/come-fare-link-building` |

Do not start this until the guest-post page in row 1 is published. The how-to page stays the how-to.

---

## 4. Upgrade — Linkaufbau-Strategien

| Field | Value |
|-------|--------|
| Language / country | de / DE |
| Primary | `linkaufbau strategien` |
| H2, same page | `wie funktioniert linkbuilding` (same band: Medium, traffic 40, difficulty 35 Easy, opp 53) |
| Intent | informational |
| Difficulty | 35 Easy |
| Demand / traffic potential | Medium / 40 |
| Opportunity / competition / business fit / effort | 53 / Low / Medium / M |
| H1 | Linkaufbau-Strategien |
| Slug | `linkaufbau-strategien` |
| Money link, once | `/de/gastbeitrag-kaufen` or `/de/backlinks-kaufen`, not both |
| Sisters | the guest-post page, the backlinks page, the dofollow page |

`linkaufbau anleitung österreich` and `linkaufbau anleitung schweiz` are both demand Low, traffic 25, difficulty 20 Easy, competition Low, opp 45. They are local paragraphs, not new posts. `manueller linkaufbau` is commercial in the Germany file (demand Low, traffic 35, difficulty 40 Medium, competition Medium, opp 55, business fit High). It can be one honest paragraph. It is not the H1.

---

## 5. Upgrade — Guest post e articolo sponsorizzato

| Field | Value |
|-------|--------|
| Language / country | it / IT |
| Primary | `differenza guest post e articolo sponsorizzato` |
| Section, not H1 | `pubbliredazionale SEO` (opp 44) |
| Intent | informational |
| Difficulty | 25 Easy |
| Demand / traffic potential | Low / 25 |
| Opportunity / competition / business fit | 50 / Low / Medium |
| Slug | `guest-post-vs-articolo-sponsorizzato` |
| Money link, once | `/it/articoli-sponsorizzati` |
| Sister | `/it/blog/cose-un-guest-post` |

The German sister already exists at `/de/blog/gesponserte-beitraege-leitfaden`. When that German page is refreshed, its job is the sponsored-format definition. It does not steal `was ist ein gastbeitrag`.

---

## 6 and 7. Dofollow, nofollow, rel sponsored

Two German URLs already talk about this. Do not add a third.

- Glossary URL to upgrade: `/de/blog/dofollow-vs-nofollow-ankertext`. Primary `dofollow vs nofollow` (Medium, traffic 38, difficulty 35 Easy, opp 52). `dofollow` (same band, opp 52) and `rel sponsored bedeutung` (Low, 23 / 25 Easy, opp 43) and `anchor text strategie` (Low, 23 / 25 Easy, opp 43) are sections.
- The older post `dofollow-nofollow-ankertexte-marketplace-links` stays a marketplace how-to. It should link to the glossary. It should not be rewritten into a second definition of the same primary.
- Italian upgrade: `/it/blog/dofollow-vs-nofollow`. Primary `dofollow vs nofollow` (Medium, traffic 40, difficulty 35 Easy, competition Low, opp 53, business fit Medium). `rel sponsored cosa significa` can live here if it was not already used as the H3 on the guest-post page. One home only. The Italy file also has a bare `dofollow` row that is commercial (Medium, traffic 48, difficulty 65 Hard, opp 52, business fit High). That row stays off this glossary page.
- English upgrade, later: `/blog/dofollow-nofollow-and-anchor-text-for-marketplace-links`. That is the English article. The German-only slug above is not its translation.

Austria’s `dofollow nofollow erklaerung` (opp 52) and Switzerland’s `dofollow vs nofollow erklaerung` (opp 43) are a sentence each on the German page, not new posts.

---

## 8 and 9. English openings, not new English URLs

The US file’s `what is a guest post` (High, traffic 53, difficulty 35 Easy, competition Low, opp 59, business fit Medium) is a stronger opportunity score than the German primary. It still does not get a new URL. `/blog/guest-posting-guide` is already the English definition. Rewrite the first 60 words so they answer that query. Keep the slug.

`what is a guest post uk` and `guest post guide uk` are both Medium, traffic 38, difficulty 20 Easy, competition Low, opp 57. They are one UK section on that same English page. `guest post guide ireland` is the same band, opp 57. `what is a guest post ireland` is Low, traffic 23, difficulty 20 Easy, opp 44. Both Ireland queries are a shorter note on that page, not a third article. There is no `/ie/` locale. `how to find guest post opportunities` (US, Medium, traffic 38, difficulty 25 Easy, opp 55) is a section of this page too.

`what is link building` is in both the US and UK files at opp 59 (High, traffic 53, difficulty 35 Easy, competition Low). Upgrade `/blog/link-building-guide`. `what is link building ireland` (Medium, traffic 38, difficulty 20 Easy, opp 57) is a section. Do not create `what-is-link-building`.

`dofollow vs nofollow` in the US file (High, traffic 53, difficulty 45 Medium, competition High, opp 55) upgrades `/blog/dofollow-nofollow-and-anchor-text-for-marketplace-links`. `dofollow vs nofollow uk` (Medium, traffic 38, difficulty 20 Easy, opp 57) and `dofollow vs nofollow ireland` (Low, traffic 23, difficulty 20 Easy, opp 44) are sections of that English page.

`anchor text best practices` (US, opp 43) and `rel sponsored meaning` / `rel sponsored explained` (opp 43) stay sections of the dofollow article.

---

## 10–12. Belgium, after the Italian and German upgrades

The Belgium file is the only source for French and Dutch glossary primaries. The public Belgium locale is `be`, hreflang `nl-BE`. `/fr/blog/` is France (`fr-FR`). `/nl/blog/` is the Netherlands (`nl-NL`). Belgium copy does not replace those two translations. France and the Netherlands still have no keyword file.

Write the Dutch pages in Dutch, for Belgium, on new locale-`be` posts. Do not translate the German article and do not reuse its outline.

| Primary | Lang | Opp | Demand / traffic | Difficulty | Comp | Where |
|---------|------|----:|------------------|------------|------|--------|
| `wat is een gastblog` | nl | 56 | Medium / 40 | 25 Easy | Low | New `/be/blog/wat-is-een-gastblog`. Money link once: `/be/koop-guest-post-belgie` |
| `wat is linkbuilding` | nl | 56 | High / 55 | 45 Medium | High | New `/be/blog/wat-is-linkbuilding`, after the gastblog page. Money link once: `/be/linkbuilding` |
| `dofollow vs nofollow belgië` | nl | 58 | Medium / 40 | 20 Easy | Low | H2 on `wat is linkbuilding`. There is no Dutch dofollow glossary yet, and this does not open one on day one |
| `qu'est-ce qu'un guest post` | fr | 56 | Medium / 40 | 25 Easy | Low | Hold. Page type in the file is `guide_be`, language `fr`. There is no `fr-BE` blog locale. Do not put this H1 on `/fr/blog/guide-guest-posting` |

`comment acheter des backlinks belgique` is tagged informational in the Belgium file (Medium, traffic 40, difficulty 20 Easy, competition Low, opp 58, business fit Medium), page type `guide_be`. The query itself is a purchase how-to, so it stays off the glossary H1. The money URL that already exists is `/be/koop-backlinks-belgie` (alias `acheter-backlinks-belgique`).

`guest post guide belgium` is English (Medium, traffic 38, difficulty 20 Easy, opp 57). One sentence on the English guest-post page is enough. It does not open a second English article, and it does not add a second money link to that page.

`pbn vs gastblog` and `rel sponsored uitleg` are both Low, traffic 25, difficulty 25 Easy, competition Low, opp 44, business fit Low. They are sections of the Belgian linkbuilding page.

Business fit is Medium and effort is M on every row in that table. The page has to say Belgium.

---

## 13 and 14. Only after the upgrades

**ZA (Italy).** Primary `cos'è lo ZA SEOZoom` (Medium, traffic 40, difficulty 25 Easy, competition Low, opp 56, business fit Medium, effort M). Explain what the score is. Do not invent SEOZoom figures, and do not imply SEOLinkBuildings calculates ZA. `metriche SEO DA DR ZA` is Medium, traffic 40, difficulty 40 Medium, competition Medium, opp 51, business fit Medium, effort M. That comparison is a section on this page, not a second URL.

**Domain Authority (Germany).** Primary `domain authority erhoehen` (Medium, traffic 38, difficulty 35 Easy, opp 52). The honest answer is that a third-party score is not a goal you buy. Austria’s `domain rating erhoehen österreich` and Switzerland’s `domain rating erhoehen schweiz` (both opp 44, Low) are one sentence each, not extra pages.

---

## Do not make these into glossary H1s

| Keyword | Why it stays off the glossary H1 |
|---------|----------------------------------|
| `gastbeitrag kaufen`, `was kostet ein gastbeitrag`, `gastbeitrag kosten` | Money and price pages already carry them |
| `come comprare guest post in sicurezza` | The Italy file tags it informational (Low, traffic 25, difficulty 25 Easy, opp 50, business fit Medium). It is still a purchase how-to: FAQ on the Italian definition, action on `/it/comprare-guest-post` |
| `how to buy backlinks safely`, `how to buy backlinks uk`, `how to buy backlinks ireland` | The files tag all three informational. Opp 55, 57, and 57. They stay off a new explainer. Ireland’s money URL is `/uk/buy-backlinks-ireland`. The US and UK rows have no separate buy-backlinks lander; the English catalog URL is `/marketplace` |
| `comment acheter des backlinks belgique` | Informational in the file, purchase how-to in the query. Money URL `/be/koop-backlinks-belgie` |
| `guest posting guide deutsch` | Already the shipped German page |
| `pbn alternative deutschland`, `pbn vs guest posts` (US, opp 55), `pbn vs guest posts uk`, `pbn vs guest posts ireland`, `pbn risiken österreich`, `pbn risiken schweiz` | One caution section on the relevant link-building page. A PBN how-to is out of scope |
| `brand mention aufbauen` (DE, opp 49), `brand mentions österreich`, `brand mentions schweiz` (both opp 50), `brand mention SEO` (IT, opp 44, difficulty 40 Medium) | Digital PR sections on the link-building how-to for that language. Not the first glossary wave |
| `gastbeitrag tipps österreich`, `gastbeitrag tipps schweiz`, `anchor text gastbeitrag österreich`, `anchor text gastbeitrag schweiz` (each opp 45, demand Low) | One sentence each on the shipped German page when that page is next edited. Not new URLs |
| `google guidelines gastbeitrag`, `google richtlinien gastbeitraege`, `google spam update linkbuilding`, `rel sponsored österreich`, `rel sponsored erklaeren`, `rel sponsored ireland`, `verificare backlink dofollow` | Already the sources or attribute section of the matching glossary page. Not new URLs |
| Any Greek, Spanish, Romanian, France-French, or Netherlands-Dutch query | No research file. Do not invent the keyword, the score, or the slug |

---

## What the next writing session does

1. Write only row 1, the Italian guest-post page, on `cose-un-guest-post`.
2. Save the brief fields next to the article, the way `seoRecords()` does for German.
3. Stop. Do not draft row 2 in the same pass. The Italian page has to stand on its own before the backlink definition is opened.
