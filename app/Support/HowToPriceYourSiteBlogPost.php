<?php

namespace App\Support;

/**
 * English publisher post: pricing inventory and sensitive niche add-ons.
 */
class HowToPriceYourSiteBlogPost
{
    public const SLUG = 'how-to-price-your-site-and-sensitive-niches';

    public const FEATURED_ASSET = 'assets/img/blog/supply-price-site-featured.jpg';

    public const FEATURED_STORAGE = 'blogs/featured/supply-price-site-featured.jpg';

    public const IMAGE_MYSITES = 'supply-price-site-inline.jpg';

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
            'title' => 'How to Price Your Site (and Sensitive Niches)',
            'slug' => self::SLUG,
            'primary_locale' => 'en',
            'excerpt' => 'Set a base guest-post price advertisers will actually buy, then price crypto, CBD, forex, and trading add-ons without scaring off orders — or undercharging your risk.',
            'content' => self::contentHtml(),
            'author' => 'M. Arslan',
            'tags' => [
                'Publisher tips',
                'Pricing',
                'Sensitive niches',
                'My Sites',
                'Supply quality',
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
                'question' => 'What is the base price vs a sensitive price?',
                'answer' => 'The base price is what advertisers pay for a standard guest post on your listing. Sensitive prices are optional extras for crypto, trading, CBD, or forex. When an advertiser chooses one of those topics, they pay base + that add-on.',
            ],
            [
                'question' => 'Do I have to accept crypto or CBD?',
                'answer' => 'No. Leave sensitive topics unchecked if you do not want those placements. Opening the sensitive panel is only for publishers who accept those niches and want a clear surcharge.',
            ],
            [
                'question' => 'Can I change prices after the site is live?',
                'answer' => 'Yes, from My Sites — but treat live prices as what new orders will see. Do not change terms mid-order without talking to the advertiser in chat.',
            ],
            [
                'question' => 'Why was my price flagged in review?',
                'answer' => 'Unrealistic prices relative to traffic and authority slow approval. Extremely high prices with thin metrics look like speculation; near-zero prices look like link farms. Aim for a number you would pay as a buyer.',
            ],
        ];
    }

    public static function contentHtml(): string
    {
        $publisherGuide = '/blog/publisher-guide-add-sites-complete-orders-withdraw';
        $approveGuide = '/blog/why-sites-get-rejected-and-how-to-get-approved';
        $payoutsGuide = '/blog/faster-publisher-payouts-turnaround-quality-revisions';
        $become = '/become-a-publisher';
        $pricing = '/pricing';
        $register = '/register';
        $imgSites = BlogInlineImages::publicUrl(self::IMAGE_MYSITES);

        return <<<HTML
<p>Pricing is the first filter advertisers use. Too high and your listing sits idle. Too low and you attract the wrong briefs — then burn time rejecting tasks. Sensitive niches (crypto, trading, CBD, forex) need a second number on top of the base, so buyers know the surcharge before checkout.</p>
<p>This guide is for publishers already listing (or about to list) on SEOLinkBuildings. For the full click-path through My Sites, Tasks, and Withdraw, use the <a href="{$publisherGuide}">publisher platform guide</a>. Platform fee context sits on <a href="{$pricing}">pricing</a>.</p>

<h2>Start with one honest base price</h2>
<p>Your base guest-post price should reflect what a careful buyer would pay for <em>this</em> site: real niche fit, language/country, traffic quality, and how permanent you keep links. Ignore vanity DA screenshots from unrelated networks.</p>
<p>A practical way to set it:</p>
<ol>
<li>Look at comparable live listings in the same language and niche band.</li>
<li>Ask what you would pay if you were buying for a client budget.</li>
<li>Leave room for revisions — rush work is not free labour.</li>
<li>Prefer a round euro amount you can defend in chat.</li>
</ol>
<p>If review keeps bouncing the listing for “unrealistic price,” the number is usually the problem, not the rest of the form. See <a href="{$approveGuide}">why sites get rejected</a>.</p>

<figure>
<img src="{$imgSites}" alt="Publisher My Sites screen showing website listings and pricing fields" loading="lazy" width="1200" height="675">
<figcaption>My Sites — set the base guest-post price when you add or edit a website.</figcaption>
</figure>

<h2>Sensitive niches: optional add-ons, not a second listing</h2>
<p>On the site form, sensitive topics are opt-in. Open the disclosure only if you accept placements about:</p>
<ul>
<li><strong>Cryptocurrency</strong></li>
<li><strong>Trading</strong></li>
<li><strong>CBD</strong></li>
<li><strong>Forex</strong></li>
</ul>
<p>For each topic you tick, enter an <strong>extra price in euros</strong>. Advertisers who pick that niche pay your base price plus that add-on. The surcharge is a pass-through for editorial risk and brand friction — not a hidden platform fee.</p>
<p>Guidelines that keep inventory clean:</p>
<ul>
<li>If you will reject crypto briefs anyway, do not enable the topic. Silence after accept hurts everyone.</li>
<li>Price the add-on for the real cost of review and possible legal/editorial heat — not as a vanity premium.</li>
<li>Keep add-ons consistent across similar sites you own so buyers are not confused.</li>
</ul>

<h2>What “good pricing” looks like to buyers</h2>
<p>Buyers compare your number to traffic, language, link type, and how clear your niche tags are. Inflated traffic claims next to a luxury price get skipped. Underpriced high-authority sites get carted by agencies that expect white-glove turnaround — then dispute when you cannot keep up.</p>
<p>Match price to service level:</p>
<ul>
<li><strong>Lower price</strong> — standard niche, clear dofollow/nofollow rules, normal turnaround</li>
<li><strong>Mid price</strong> — stronger traffic or competitive niches, limited outbound links</li>
<li><strong>Higher price</strong> — scarce language/country inventory, strict editorial, slow but careful publishing</li>
</ul>

<h2>After you go live</h2>
<p>Watch which orders you accept vs reject. If you reject half of sensitive briefs, raise the add-on or turn the topic off. If standard orders never arrive, drop the base a notch and fix niche tags before you cut further.</p>
<p>Faster completion and clean live URLs matter more than a €20 bump — see <a href="{$payoutsGuide}">faster payouts</a>. Ready to list? <a href="{$become}">Become a publisher</a> or <a href="{$register}">register</a> and price the first site as if you were buying it yourself.</p>

<h2>Frequently asked questions</h2>
<h3>What is the base price vs a sensitive price?</h3>
<p>The base price is what advertisers pay for a standard guest post on your listing. Sensitive prices are optional extras for crypto, trading, CBD, or forex. When an advertiser chooses one of those topics, they pay base + that add-on.</p>
<h3>Do I have to accept crypto or CBD?</h3>
<p>No. Leave sensitive topics unchecked if you do not want those placements. Opening the sensitive panel is only for publishers who accept those niches and want a clear surcharge.</p>
<h3>Can I change prices after the site is live?</h3>
<p>Yes, from My Sites — but treat live prices as what new orders will see. Do not change terms mid-order without talking to the advertiser in chat.</p>
<h3>Why was my price flagged in review?</h3>
<p>Unrealistic prices relative to traffic and authority slow approval. Extremely high prices with thin metrics look like speculation; near-zero prices look like link farms. Aim for a number you would pay as a buyer.</p>
HTML;
    }
}
