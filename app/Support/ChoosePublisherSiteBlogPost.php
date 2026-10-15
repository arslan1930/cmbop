<?php

namespace App\Support;

/**
 * English guide: how to choose publisher sites in the catalog.
 */
class ChoosePublisherSiteBlogPost
{
    public const SLUG = 'how-to-choose-a-publisher-site-dr-da-traffic-niche';

    public const FEATURED_ASSET = 'assets/img/blog/trust-choose-publisher-featured.jpg';

    public const FEATURED_STORAGE = 'blogs/featured/trust-choose-publisher-featured.jpg';

    public const IMAGE_CATALOG = 'trust-choose-publisher-inline.jpg';

    public const IMAGE_METRICS = 'howto-adv-catalog.jpg';

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
            'title' => 'How to Choose a Publisher Site (DR, DA, Traffic, Niche Fit)',
            'slug' => self::SLUG,
            'primary_locale' => 'en',
            'excerpt' => 'A practical way to shortlist guest-post sites: read DR and DA without worshipping them, check traffic, and never skip niche fit.',
            'content' => self::contentHtml(),
            'author' => 'M. Arslan',
            'tags' => [
                'Catalog',
                'Domain Rating',
                'Domain Authority',
                'Traffic',
                'Guest posts',
                'Advertiser tips',
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
                'question' => 'Is a high DR site always better than a mid-DR niche blog?',
                'answer' => 'No. A DR 70 site in the wrong niche can underperform a DR 28 site that already ranks for your topic. Relevance and real traffic usually beat a vanity score.',
            ],
            [
                'question' => 'What if traffic looks fine but the sample article is thin?',
                'answer' => 'Treat that as a red flag. Open the sample, skim recent posts, and skip listings that feel like pure link farms — even when the metrics look tidy.',
            ],
            [
                'question' => 'Should I filter verified sites only?',
                'answer' => 'Verified helps, but it is not the whole story. Combine verification with niche match, turnaround, link type, and a quick look at the sample article.',
            ],
            [
                'question' => 'How many sites should I shortlist before buying?',
                'answer' => 'For a first campaign, five to ten solid options is enough. Fill a huge cart before you have content ready and you will pay for delay later.',
            ],
        ];
    }

    public static function contentHtml(): string
    {
        $catalogHelp = '/blog/how-to-buy-guest-posts-on-seolinkbuildings-advertiser-guide';
        $liveCheck = '/blog/what-to-check-after-the-live-link-indexation-attributes-rankings';
        $marketplace = '/marketplace';
        $register = '/register';
        $howItWorks = '/how-it-works';
        $imgCatalog = BlogInlineImages::publicUrl(self::IMAGE_CATALOG);
        $imgMetrics = BlogInlineImages::publicUrl(self::IMAGE_METRICS);

        return <<<HTML
<p>Most people open a guest-post catalog and sort by the biggest number they recognise. That is how you end up with expensive links that do almost nothing for the site you actually care about.</p>
<p>This guide is the slower, better habit: use Domain Rating (DR), Domain Authority (DA), and traffic as filters — then decide with niche fit, sample content, and the listing terms. If you are new to the product path itself, read the <a href="{$catalogHelp}">advertiser how-to</a> first, then come back here before you fill the cart.</p>

<h2>Start with the job, not the metric</h2>
<p>Write one sentence before you filter anything: “I need placements that support <em>this</em> topic for <em>this</em> country/language.” That sentence kills half the bad options early.</p>
<p>Examples that work:</p>
<ul>
<li>German home-finance blog posts with dofollow links to a comparison page</li>
<li>English SaaS tutorials aimed at US readers</li>
<li>French local business sites for a regional landing page</li>
</ul>
<p>If you cannot write that sentence, you are shopping — not buying.</p>

<figure>
<img src="{$imgCatalog}" alt="SEOLinkBuildings catalog with publisher metrics and filters" loading="lazy" width="1200" height="675">
<figcaption>Catalog view — filter first, then compare DR, DA, traffic, and niche on the shortlist.</figcaption>
</figure>

<h2>What DR and DA are good for (and what they are not)</h2>
<p>DR (Ahrefs) and DA (Moz) are comparative scores. They help you sort a long list. They do not prove a site will move your rankings tomorrow.</p>
<p>Use them like this:</p>
<ul>
<li><strong>Floor, not trophy.</strong> Set a minimum that matches your budget tier, then stop staring at the ceiling.</li>
<li><strong>Compare within a niche.</strong> A DR 40 travel blog and a DR 40 casino PBNs are not the same purchase.</li>
<li><strong>Watch for mismatch.</strong> Very high DR with near-zero traffic (or the reverse) deserves a second look at the sample article and outbound links.</li>
</ul>
<p>On SEOLinkBuildings you will see bars under the numbers so a “55” has a scale. That helps you scan a page of results without treating every digit as gospel.</p>

<figure>
<img src="{$imgMetrics}" alt="Publisher listings showing traffic, DR and DA in the catalog" loading="lazy" width="1200" height="675">
<figcaption>Metrics in context — use the bars to compare listings quickly, then open details.</figcaption>
</figure>

<h2>Traffic: prefer signal over vanity</h2>
<p>Monthly traffic estimates are still estimates. They are useful when you ask: “Does anyone actually read this site?”</p>
<p>Practical rules we use with advertisers:</p>
<ol>
<li>Prefer sites with steady, believable traffic for the niche — not a spike that looks borrowed.</li>
<li>Match traffic geography to your target market when the listing shows a primary country.</li>
<li>If traffic is low but the site is tightly topical and indexed, it can still be worth a trial order. One relevant referring domain often beats three random “authority” links.</li>
</ol>

<h2>Niche fit beats almost everything else</h2>
<p>Open the sample article. Read the categories. Ask whether your anchor and URL would look natural on that page.</p>
<p>Skip a listing when:</p>
<ul>
<li>the sample is spun or stuffed with outbound links</li>
<li>the niche tag says “marketing” but every post is about gambling offers</li>
<li>the publisher’s turnaround or link type does not match what you need</li>
</ul>
<p>Sensitive topics (crypto, CBD, forex, and similar) often carry add-on prices. That is normal. What is not normal is hiding the topic until after checkout — pick the add-on on purpose.</p>

<h2>A short shortlist process that works</h2>
<ol>
<li>Filter country, language, and category.</li>
<li>Set rough floors for DR/DA and traffic that fit the campaign budget.</li>
<li>Open five to ten listings. Check sample, link type, turnaround, and price.</li>
<li>Add only the ones you would still want next month.</li>
<li>Upload content before you check out — see the <a href="{$catalogHelp}">buy guide</a> — then pay from the wallet.</li>
</ol>
<p>After the live URL lands, run the <a href="{$liveCheck}">live-link checklist</a>. Buying well means nothing if you never verify the placement.</p>

<h2>Common mistakes</h2>
<ul>
<li>Sorting only by highest DR and buying the first three rows</li>
<li>Ignoring language and country because the price looked good</li>
<li>Filling a 20-site cart with no approved articles ready</li>
<li>Treating “verified” as a substitute for reading the sample</li>
</ul>
<p>Browse the public <a href="{$marketplace}">marketplace</a> overview if you want the model in plain language, or jump into <a href="{$howItWorks}">how it works</a>. When you are ready to shortlist for real, <a href="{$register}">create an advertiser account</a> and open the catalog with a written brief in hand.</p>

<h2>Frequently asked questions</h2>
<h3>Is a high DR site always better than a mid-DR niche blog?</h3>
<p>No. A DR 70 site in the wrong niche can underperform a DR 28 site that already ranks for your topic. Relevance and real traffic usually beat a vanity score.</p>
<h3>What if traffic looks fine but the sample article is thin?</h3>
<p>Treat that as a red flag. Open the sample, skim recent posts, and skip listings that feel like pure link farms — even when the metrics look tidy.</p>
<h3>Should I filter verified sites only?</h3>
<p>Verified helps, but it is not the whole story. Combine verification with niche match, turnaround, link type, and a quick look at the sample article.</p>
<h3>How many sites should I shortlist before buying?</h3>
<p>For a first campaign, five to ten solid options is enough. Fill a huge cart before you have content ready and you will pay for delay later.</p>
HTML;
    }
}
