<?php

namespace App\Support;

/**
 * English guide on dofollow/nofollow and anchor text for marketplace links.
 * Twin of DofollowNofollowAnkertexteBlogPost (DE).
 */
class DofollowNofollowAnchorsEnBlogPost
{
    public const SLUG = 'dofollow-nofollow-and-anchor-text-for-marketplace-links';

    public const FEATURED_ASSET = 'assets/img/blog/market-dofollow-anchors-en-featured.jpg';

    public const FEATURED_STORAGE = 'blogs/featured/market-dofollow-anchors-en-featured.jpg';

    public const IMAGE_TYPES = 'market-dofollow-anchors-en-types.jpg';

    public const IMAGE_MIX = 'market-dofollow-anchors-en-mix.jpg';

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
            'title' => 'DoFollow, NoFollow & Anchor Text: What Matters for Marketplace Links',
            'slug' => self::SLUG,
            'primary_locale' => 'en',
            'excerpt' => 'What dofollow and nofollow mean in practice, how to mix anchor text naturally, and what to check before you buy marketplace links.',
            'content' => self::contentHtml(),
            'author' => 'Arslan Jason',
            'tags' => [
                'DoFollow',
                'NoFollow',
                'Anchor text',
                'Marketplace links',
                'Link building',
            ],
            'status' => 'published',
            'featured_image' => self::FEATURED_STORAGE,
            'faq' => self::faqItems(),
            'translations' => self::translations(),
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function translations(): array
    {
        return class_exists(DofollowNofollowAnchorsI18n::class)
            ? DofollowNofollowAnchorsI18n::all()
            : [];
    }

    /**
     * @return list<array{question: string, answer: string}>
     */
    public static function faqItems(): array
    {
        return [
            [
                'question' => 'Are nofollow links useless on marketplace placements?',
                'answer' => 'No. They do not pass classic PageRank the way dofollow links can, but they can still bring traffic, brand mention value, and a more natural profile. A healthy link profile includes both — not only “everything dofollow, please.”',
            ],
            [
                'question' => 'How high can the share of exact-match anchors be?',
                'answer' => 'There is no magic percentage. If most of your anchors are exact money keywords, the profile often looks steered. Brand, URL, partial-match, and generic phrases should carry the mix.',
            ],
            [
                'question' => 'Can I force dofollow from the publisher after the fact?',
                'answer' => 'Only if the offer and editorial policy allow it. Many sites mark paid placements on purpose. Read the listing before you order — renegotiating after the live URL is slow and often goes nowhere.',
            ],
            [
                'question' => 'How do I recognise the link type after publication?',
                'answer' => 'Open the live URL and inspect the link (rel attribute). If there is no blocking rel such as nofollow, sponsored, or ugc in the relevant form, the link is often dofollow in practice. Log it in order chat and check indexation separately.',
            ],
        ];
    }

    public static function contentHtml(): string
    {
        $marketplace = '/marketplace';
        $register = '/register';
        $liveCheck = '/blog/what-to-check-after-the-live-link-indexation-attributes-rankings';
        $briefGuide = '/blog/guest-post-brief-anchors-urls-images-sensitive-topics';
        $imgTypes = BlogInlineImages::publicUrl(self::IMAGE_TYPES);
        $imgMix = BlogInlineImages::publicUrl(self::IMAGE_MIX);

        return <<<HTML
<p>Two questions keep showing up in the marketplace: “Is the link dofollow?” and “Which anchor do we use?” Fair. Both can affect rankings. Both get overrated when relevance and site quality are already shaky.</p>
<p>This guide covers <strong>what dofollow and nofollow mean in practice</strong>, how anchors look natural, and what to check on marketplace orders before you spend. Pair it with the <a href="{$briefGuide}">guest-post brief checklist</a> when you write the order notes.</p>

<h2>Dofollow and nofollow without the myth</h2>
<p>Short and unromantic:</p>
<ul>
<li><strong>Dofollow</strong> (more precisely: a link without a blocking <code>rel</code>): can pass ranking signals.</li>
<li><strong>Nofollow</strong> (<code>rel="nofollow"</code>, often combined with <code>sponsored</code> / <code>ugc</code>): signals “do not treat this as an editorial endorsement.” SEO value is more limited — not automatically zero.</li>
</ul>
<p>Google softened the old “nofollow = ignore” world years ago. Still: if you are deliberately building authority, dofollow placements on fitting sites remain the clearer lever. Nofollow is a complement, not a substitute.</p>

<figure>
<img src="{$imgTypes}" alt="Natural in-content links compared with weak outbound patterns" loading="lazy" width="1200" height="675">
<figcaption>Where the link sits often matters as much as the rel attribute: context beats cosmetics.</figcaption>
</figure>

<h2>What actually matters for marketplace links (priority order)</h2>
<ol>
<li><strong>Niche and market:</strong> a language-matched site for your intent beats a “strong” random domain.</li>
<li><strong>Placement in content:</strong> body copy &gt; footer &gt; author-box spam.</li>
<li><strong>Link attribute:</strong> dofollow helps — when 1 and 2 already fit.</li>
<li><strong>Anchor text:</strong> natural in the sentence, not a keyword stamp.</li>
<li><strong>Pace and mix:</strong> a pile of exact-match dofollows in two weeks rarely looks organic.</li>
</ol>
<p>Filter “dofollow = yes” and ignore the rest, and you are buying attributes. Not visibility.</p>

<h2>Anchor text: the mix that does not scream “campaign”</h2>
<p>Anchor text is the clickable phrase. Google reads it. So do people. If every other link says “best credit card comparison 2026,” the profile looks steered — no matter how pretty the publisher’s DR is.</p>

<figure>
<img src="{$imgMix}" alt="Planning a varied anchor mix in an editorial draft" loading="lazy" width="1200" height="675">
<figcaption>Brand, URL, partial match, generic — variety reads calmer than keyword staccato.</figcaption>
</figure>

<p>A workable mix for marketplace campaigns:</p>
<ul>
<li><strong>Brand:</strong> “SEOLinkBuildings”, company name</li>
<li><strong>Naked URL:</strong> seolinkbuildings.com</li>
<li><strong>Partial match:</strong> “buy guest posts in Europe”, “compare publisher catalog”</li>
<li><strong>Generic:</strong> “here”, “on this page”, “read more”</li>
<li><strong>Exact match:</strong> sparingly, and only when the sentence would still be read that way</li>
</ul>
<p>Everyday rule: write the sentence without the link. Does it still sound human after you drop the keyword in? If not — change the anchor.</p>

<h2>Filtering dofollow in the marketplace — useful, not blind</h2>
<p>In the <a href="{$marketplace}">marketplace</a> you can compare offers by link type and other metrics. Use the dofollow filter as a quality hint, not the only truth.</p>
<p>Before you order, also check:</p>
<ul>
<li>Is dofollow clearly stated in the listing?</li>
<li>Does the site fit thematically and linguistically?</li>
<li>Does the domain’s outbound area look overloaded?</li>
<li>Can you brief a natural anchor without stuffing?</li>
</ul>
<p>After go-live: open the URL, inspect the link, note attributes. Mismatches belong in order chat — not in a silent spreadsheet three months later. Use the <a href="{$liveCheck}">live-link checklist</a> so indexation and attributes are not forgotten.</p>

<h2>Common mistakes we see constantly</h2>
<ul>
<li>Exact-match anchors on every placement</li>
<li>Dofollow at any cost on irrelevant sites</li>
<li>Treating nofollow as worthless and leaving traffic opportunities on the table</li>
<li>Trying to change the anchor after the article is live and indexed — without agreement</li>
<li>Dumping ten dofollow links onto a fresh domain in one week</li>
</ul>
<p>None of that is an instant penalty. A lot of it simply looks fake. Fake is a bad signal in 2026.</p>

<h2>A simple briefing block for your next order</h2>
<p>Copy, adapt, attach:</p>
<ul>
<li>Target URL: …</li>
<li>Preferred anchor (1st choice / 2nd choice): …</li>
<li>Taboo anchors: …</li>
<li>Expected link type per listing: dofollow / nofollow</li>
<li>Note to publisher: link in body copy, not footer</li>
</ul>
<p>Clarity saves revision loops. Revision loops cost days before the live URL. More detail on briefs lives in the <a href="{$briefGuide}">anchors, URLs, images, and sensitive topics guide</a>.</p>

<h2>Bottom line</h2>
<p>Dofollow helps. Nofollow belongs in the mix. Anchors matter — but only in the context of a credible site and a calm pace.</p>
<p>When you book marketplace links, filter smart, brief clearly, and check live URLs. <a href="{$register}">Create an account</a> and compare offers in the <a href="{$marketplace}">catalog</a> where link type, market, and topic fit together — not only the biggest metric.</p>

<h2>Frequently asked questions</h2>
<h3>Are nofollow links useless on marketplace placements?</h3>
<p>No. They do not pass classic PageRank the way dofollow links can, but they can still bring traffic, brand mention value, and a more natural profile. A healthy link profile includes both — not only “everything dofollow, please.”</p>
<h3>How high can the share of exact-match anchors be?</h3>
<p>There is no magic percentage. If most of your anchors are exact money keywords, the profile often looks steered. Brand, URL, partial-match, and generic phrases should carry the mix.</p>
<h3>Can I force dofollow from the publisher after the fact?</h3>
<p>Only if the offer and editorial policy allow it. Many sites mark paid placements on purpose. Read the listing before you order — renegotiating after the live URL is slow and often goes nowhere.</p>
<h3>How do I recognise the link type after publication?</h3>
<p>Open the live URL and inspect the link (rel attribute). If there is no blocking rel such as nofollow, sponsored, or ugc in the relevant form, the link is often dofollow in practice. Log it in order chat and check indexation separately.</p>
HTML;
    }
}
