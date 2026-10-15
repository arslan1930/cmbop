<?php

namespace App\Support;

/**
 * English comparison: marketplace vs cold outreach vs digital PR.
 */
class MarketplaceVsOutreachBlogPost
{
    public const SLUG = 'marketplace-vs-cold-outreach-vs-digital-pr';

    public const FEATURED_ASSET = 'assets/img/blog/trust-marketplace-vs-outreach-featured.jpg';

    public const FEATURED_STORAGE = 'blogs/featured/trust-marketplace-vs-outreach-featured.jpg';

    public const IMAGE_HOWITWORKS = 'trust-marketplace-vs-outreach-inline.jpg';

    public const IMAGE_CATALOG = 'howto-adv-catalog.jpg';

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
            'title' => 'Marketplace vs Cold Outreach vs Digital PR',
            'slug' => self::SLUG,
            'primary_locale' => 'en',
            'excerpt' => 'When to buy placements on a marketplace, when to pitch journalists yourself, and when a digital PR story is the better spend.',
            'content' => self::contentHtml(),
            'author' => 'M. Arslan',
            'tags' => [
                'Marketplace',
                'Outreach',
                'Digital PR',
                'Link building',
                'Strategy',
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
                'question' => 'Is marketplace link building “worse” than outreach?',
                'answer' => 'Neither is automatically better. Marketplaces win on speed and clear pricing. Outreach wins when you need a specific site that is not listed. Quality depends on how you choose publishers and content.',
            ],
            [
                'question' => 'Can I mix all three?',
                'answer' => 'Yes — and most teams that stay sane do. Use the marketplace for repeatable volume, outreach for unique targets, and digital PR when you have a real story.',
            ],
            [
                'question' => 'Where does SEOLinkBuildings fit?',
                'answer' => 'It is the marketplace lane: verified listings, wallet checkout, order tracking, and a path to report problems after go-live.',
            ],
            [
                'question' => 'What usually fails first for beginners?',
                'answer' => 'Buying random high-DR sites with thin content, or sending 200 cold emails with no offer. Pick a lane, run a small test, measure, then scale.',
            ],
        ];
    }

    public static function contentHtml(): string
    {
        $howItWorks = '/how-it-works';
        $marketplace = '/marketplace';
        $choose = '/blog/how-to-choose-a-publisher-site-dr-da-traffic-niche';
        $wallet = '/blog/wallet-escrow-and-refunds-explained';
        $register = '/register';
        $imgHow = BlogInlineImages::publicUrl(self::IMAGE_HOWITWORKS);
        $imgCatalog = BlogInlineImages::publicUrl(self::IMAGE_CATALOG);

        return <<<HTML
<p>People argue about link building like it is a religion. Marketplace people dunk on outreach. Outreach people dunk on paid placements. Digital PR people pretend both are beneath them.</p>
<p>In practice you pick the tool that matches the job. Here is a straight comparison so you can stop pretending one channel replaces the other.</p>

<figure>
<img src="{$imgHow}" alt="How SEOLinkBuildings marketplace placements work end to end" loading="lazy" width="1200" height="675">
<figcaption>Marketplace lane — clear steps from catalog to live URL. See also <a href="{$howItWorks}">how it works</a>.</figcaption>
</figure>

<h2>1. Guest-post marketplace</h2>
<p><strong>What it is:</strong> a catalog of publishers with prices, metrics, and rules. You pick, attach content, pay from a wallet, track the order.</p>
<p><strong>Good for:</strong></p>
<ul>
<li>repeatable campaigns across countries and languages</li>
<li>teams that want a receipt, a status, and a chat thread</li>
<li>budgets that need predictable cost per placement</li>
</ul>
<p><strong>Weak at:</strong></p>
<ul>
<li>landing a specific newspaper that never lists itself</li>
<li>earning a story that only works as news</li>
</ul>
<p>SEOLinkBuildings sits here. Inventory is browsable on the <a href="{$marketplace}">marketplace</a>; buying happens in the logged-in catalog. Money handling is covered in <a href="{$wallet}">wallet &amp; refunds</a>. Selection habits are in <a href="{$choose}">how to choose a publisher site</a>.</p>

<figure>
<img src="{$imgCatalog}" alt="Catalog of publisher websites with prices and SEO metrics" loading="lazy" width="1200" height="675">
<figcaption>Catalog shopping — price and metrics up front, then content + checkout.</figcaption>
</figure>

<h2>2. Cold outreach</h2>
<p><strong>What it is:</strong> you find sites, email owners, negotiate, chase invoices, hope the link stays.</p>
<p><strong>Good for:</strong></p>
<ul>
<li>a short list of dream sites that are not on any marketplace</li>
<li>relationship-led placements (existing partners, communities)</li>
<li>odd formats that need a custom deal</li>
</ul>
<p><strong>Weak at:</strong></p>
<ul>
<li>speed — replies take days or never arrive</li>
<li>ops — your spreadsheet becomes the product</li>
<li>disputes — when the link dies, you are on your own</li>
</ul>
<p>Outreach still matters. Just do not romanticise the unpaid labour. If you need twenty solid placements this month across two languages, a marketplace is usually the calmer path.</p>

<h2>3. Digital PR</h2>
<p><strong>What it is:</strong> a story, data study, or newsworthy angle pitched to journalists and publishers. Links are a by-product of coverage.</p>
<p><strong>Good for:</strong></p>
<ul>
<li>brand mentions and high-trust domains</li>
<li>AI/search visibility that cares about entities and citations</li>
<li>campaigns where the screenshot of coverage matters as much as the href</li>
</ul>
<p><strong>Weak at:</strong></p>
<ul>
<li>guaranteed links on a schedule</li>
<li>cheap experiments — good PR needs a real hook and often a specialist</li>
</ul>
<p>If you do not have a story, you do not have digital PR. You have outreach with better adjectives.</p>

<h2>Side-by-side (honest version)</h2>
<table>
<thead>
<tr><th></th><th>Marketplace</th><th>Cold outreach</th><th>Digital PR</th></tr>
</thead>
<tbody>
<tr><td>Speed</td><td>Fast once content is ready</td><td>Slow / uneven</td><td>Campaign cycles</td></tr>
<tr><td>Price clarity</td><td>Listed up front</td><td>Negotiated</td><td>Retainer + unknowns</td></tr>
<tr><td>Control of site choice</td><td>High within catalog</td><td>Highest (if they reply)</td><td>Editor decides</td></tr>
<tr><td>Ops burden</td><td>Low–medium</td><td>High</td><td>High (creative)</td></tr>
<tr><td>Best first test</td><td>Small paid batch</td><td>10 personalised pitches</td><td>One real data story</td></tr>
</tbody>
</table>

<h2>A simple decision rule</h2>
<ul>
<li>Need links on a schedule with known prices → <strong>marketplace</strong></li>
<li>Need one specific unlisted site → <strong>outreach</strong></li>
<li>Have a story journalists would publish without a “sponsored” wink → <strong>digital PR</strong></li>
</ul>
<p>Most growth teams eventually run all three. The mistake is using digital PR budgets to buy junk links, or using marketplace budgets to chase vanity logos that were never going to list.</p>

<h2>If you start on SEOLinkBuildings</h2>
<p>Keep the first month boring: pick a niche, shortlist carefully, upload decent articles, fund the wallet, complete a handful of orders, log the live URLs. Then decide whether outreach or PR deserves the next slice of budget.</p>
<p><a href="{$register}">Create an advertiser account</a> when you want the marketplace lane without building a mini agency in a spreadsheet.</p>

<h2>Frequently asked questions</h2>
<h3>Is marketplace link building “worse” than outreach?</h3>
<p>Neither is automatically better. Marketplaces win on speed and clear pricing. Outreach wins when you need a specific site that is not listed. Quality depends on how you choose publishers and content.</p>
<h3>Can I mix all three?</h3>
<p>Yes — and most teams that stay sane do. Use the marketplace for repeatable volume, outreach for unique targets, and digital PR when you have a real story.</p>
<h3>Where does SEOLinkBuildings fit?</h3>
<p>It is the marketplace lane: verified listings, wallet checkout, order tracking, and a path to report problems after go-live.</p>
<h3>What usually fails first for beginners?</h3>
<p>Buying random high-DR sites with thin content, or sending 200 cold emails with no offer. Pick a lane, run a small test, measure, then scale.</p>
HTML;
    }
}
