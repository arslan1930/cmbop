<?php

namespace App\Support;

/**
 * English post-publish checklist for marketplace live links.
 * One body for /blog, /de/blog, /fr/blog, /nl/blog (no per-locale fields).
 */
class LiveLinkChecklistBlogPost
{
    public const SLUG = 'what-to-check-after-the-live-link-indexation-attributes-rankings';

    public const FEATURED_ASSET = 'assets/img/blog/live-link-checklist-featured.jpg';

    public const FEATURED_STORAGE = 'blogs/featured/live-link-checklist-featured.jpg';

    public const IMAGE_ATTRIBUTES = 'live-link-checklist-attributes.jpg';

    public const IMAGE_RANKINGS = 'live-link-checklist-rankings.jpg';

    /**
     * @return array{
     *     title: string,
     *     slug: string,
     *     primary_locale: string,
     *     excerpt: string,
     *     content: string,
     *     author: string,
     *     tags: list<string>,
     *     status: string,
     *     featured_image: string,
     *     faq: list<array{question: string, answer: string}>
     * }
     */
    public static function payload(): array
    {
        return [
            'title' => 'What to Check After the Live Link (Indexation, Attributes, Rankings)',
            'slug' => self::SLUG,
            'primary_locale' => 'en',
            'excerpt' => 'Your guest post is live — now what? A practical checklist for indexation, link attributes, anchors, and ranking follow-up after marketplace placements.',
            'content' => self::contentHtml(),
            'author' => 'M. Arslan',
            'tags' => [
                'Live link checklist',
                'Indexation',
                'DoFollow NoFollow',
                'Rankings',
                'Guest posting',
            ],
            'status' => 'published',
            'featured_image' => self::FEATURED_STORAGE,
            'faq' => self::faqItems(),
        ];
    }

    /**
     * @return list<array{question: string, answer: string}>
     */
    public static function faqItems(): array
    {
        return [
            [
                'question' => 'How long should I wait before worrying that a live URL is not indexed?',
                'answer' => 'Give it a few days to a couple of weeks depending on the site’s crawl rate. Brand-new or low-traffic publishers can take longer. If the page is blocked by robots rules or stuck as “Discovered – currently not indexed,” fix crawl access first, then request indexing again.',
            ],
            [
                'question' => 'The listing said DoFollow but the live link is NoFollow. What now?',
                'answer' => 'Document the live URL and the rel attribute in the order thread. Ask the publisher to match the listing. If they cannot, use the marketplace dispute/support path rather than waiting in silence — attributes are part of what you paid for.',
            ],
            [
                'question' => 'Should rankings jump as soon as the link is indexed?',
                'answer' => 'Rarely. One solid placement is a signal, not a switch. Track referring domain status, then keyword positions over weeks — especially after a small batch of relevant links, not after a single URL.',
            ],
            [
                'question' => 'What is the minimum I should record for every live placement?',
                'answer' => 'Live URL, target URL, anchor text, link attribute (dofollow/nofollow/sponsored), date live, index status, and any publisher notes. That log saves you when something disappears or changes later.',
            ],
        ];
    }

    public static function contentHtml(): string
    {
        $marketplace = '/marketplace';
        $register = '/register';
        $howItWorks = '/how-it-works';
        $pricing = '/pricing';
        $blog = '/blog';
        $faq = '/faq';
        $guestEurope = '/blog/gastbeitraege-kaufen-europa-publisher-sites-richtig-waehlen';
        $backlinksDe = '/blog/backlinks-aufbauen-die-echte-rankings-erzielen-nicht-nur-zahlen';
        $imgAttributes = BlogInlineImages::publicUrl(self::IMAGE_ATTRIBUTES);
        $imgRankings = BlogInlineImages::publicUrl(self::IMAGE_RANKINGS);

        return <<<HTML
<p>The order shows “live.” Congrats — that is not the finish line. A marketplace placement only starts working after you verify the URL, the link itself, and whether search engines can even see it.</p>
<p>This is the checklist we recommend after every guest post or niche edit goes live. Boring? Maybe. Cheaper than discovering three months later that the anchor changed, the link went nofollow, or the page never got indexed.</p>
<p>If you are still choosing publishers, read <a href="{$guestEurope}">how to pick European guest-post sites</a> first. If you are earlier in the funnel, skim <a href="{$howItWorks}">how SEOLinkBuildings works</a>, then come back here once you have a live URL.</p>

<h2>1. Confirm the live URL is the real page</h2>
<p>Open the exact URL the publisher delivered. Not the homepage. Not a category archive.</p>
<ul>
<li>Does the article load without soft-404 weirdness?</li>
<li>Is your brand or offer mentioned where you expected?</li>
<li>Is the page behind a login, geo-block, or aggressive interstitial?</li>
<li>Can you reach it in an incognito window?</li>
</ul>
<p>Save the live URL in your order notes the same day. Screenshots help when content “quietly” changes later.</p>

<h2>2. Check the link target, anchor, and attributes</h2>
<figure>
<img src="{$imgAttributes}" alt="Inspecting a live guest-post link and its HTML attributes" loading="lazy" width="1200" height="675">
<figcaption>Right-click → inspect. Thirty seconds here prevents months of wrong assumptions.</figcaption>
</figure>
<p>Click your link. Confirm it hits the <strong>correct target URL</strong> (https, right path, no accidental UTM mess unless you asked for it).</p>
<p>Then inspect the HTML:</p>
<ul>
<li><strong>Anchor text</strong> — does it match the brief? Natural in the sentence, not stuffed?</li>
<li><strong>rel attributes</strong> — dofollow (no blocking rel), or <code>nofollow</code> / <code>sponsored</code> / <code>ugc</code>?</li>
<li><strong>Placement</strong> — in-body paragraph beats footer spam every time.</li>
</ul>
<p>Listings on the <a href="{$marketplace}">marketplace</a> usually state link type up front. If the live attribute does not match what you bought, raise it in the order chat immediately. For the strategy side of attributes and anchors, keep our marketplace link guide handy once it is live on the <a href="{$blog}">blog</a>.</p>

<h2>3. Indexation: can Google find the page?</h2>
<p>A beautiful DoFollow link on an unindexed URL is a paperweight.</p>
<ol>
<li>Paste the live URL into Google Search Console URL Inspection (property that makes sense for your workflow), or at least search <code>site:example.com/exact-path</code>.</li>
<li>Check for <code>noindex</code>, robots blocks, or canonical tags pointing somewhere else.</li>
<li>If the page is crawlable but not indexed yet, request indexing and wait — do not spam the button daily.</li>
</ol>
<p>New publisher domains and thin sites often crawl slowly. That is annoying. It is also normal. What is not normal: a page that returns 404 after “going live,” or a canonical that points to an unrelated URL.</p>

<h2>4. Rankings and referral signals — measure without drama</h2>
<figure>
<img src="{$imgRankings}" alt="Tracking keyword rankings and referral signals after a live backlink" loading="lazy" width="1200" height="675">
<figcaption>Log the date. Then watch positions and referring-domain status over weeks, not hours.</figcaption>
</figure>
<p>One link rarely flips page-one overnight. Still, you should track:</p>
<ul>
<li>Referring domain / link status in Ahrefs, Semrush, or Site Explorer (whichever you already pay for)</li>
<li>Target keyword positions for the URL you wanted to strengthen</li>
<li>Referral traffic from the publisher domain in analytics (even a trickle proves real humans can click)</li>
</ul>
<p>Baseline before the campaign. Snapshot again after 2–4 weeks, then at 8–12. That rhythm matches how we talk about building links that move rankings in our <a href="{$backlinksDe}">DACH backlink guide</a> — patience with receipts, not vibes.</p>

<h2>5. A simple post-live log (copy this)</h2>
<ul>
<li>Order ID / site name</li>
<li>Live URL</li>
<li>Target URL</li>
<li>Anchor text</li>
<li>Link attribute</li>
<li>Date live</li>
<li>Indexed? (Y/N + date checked)</li>
<li>Notes / follow-ups</li>
</ul>
<p>Teams that keep this sheet catch disappearing links early. Teams that do not argue from memory.</p>

<h2>6. When something is wrong</h2>
<ul>
<li><strong>Wrong URL or missing link:</strong> message the publisher with screenshots.</li>
<li><strong>Attribute mismatch:</strong> quote the listing terms; ask for correction or escalation.</li>
<li><strong>Not indexed after a reasonable wait:</strong> check robots/canonical; ask if the post is meant to be public.</li>
<li><strong>Content edited into spam:</strong> request a restore; if refused, use support paths outlined in our <a href="{$faq}">FAQ</a>.</li>
</ul>
<p>Transparent <a href="{$pricing}">pricing</a> only helps if you also protect the delivery. Verification is part of buying placements — not an optional geek hobby.</p>

<h2>Before you book the next one</h2>
<p>Fix the process, then scale. Filter the next sites carefully in the <a href="{$marketplace}">marketplace</a>, keep briefs tight, and run this checklist every time a URL goes live.</p>
<p><a href="{$register}">Create a free account</a>, place your next order, and treat “live” as the start of QA — not the end of the campaign.</p>

<h2>Frequently asked questions</h2>
<h3>How long should I wait before worrying that a live URL is not indexed?</h3>
<p>Give it a few days to a couple of weeks depending on the site’s crawl rate. Brand-new or low-traffic publishers can take longer. If the page is blocked by robots rules or stuck as “Discovered – currently not indexed,” fix crawl access first, then request indexing again.</p>
<h3>The listing said DoFollow but the live link is NoFollow. What now?</h3>
<p>Document the live URL and the rel attribute in the order thread. Ask the publisher to match the listing. If they cannot, use the marketplace dispute/support path rather than waiting in silence — attributes are part of what you paid for.</p>
<h3>Should rankings jump as soon as the link is indexed?</h3>
<p>Rarely. One solid placement is a signal, not a switch. Track referring domain status, then keyword positions over weeks — especially after a small batch of relevant links, not after a single URL.</p>
<h3>What is the minimum I should record for every live placement?</h3>
<p>Live URL, target URL, anchor text, link attribute (dofollow/nofollow/sponsored), date live, index status, and any publisher notes. That log saves you when something disappears or changes later.</p>
HTML;
    }
}
