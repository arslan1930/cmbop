<?php

namespace App\Support;

/**
 * English pillar: sponsored posts as advertising, with honest labelling.
 */
class SponsoredPostGuideBlogPost
{
    public const SLUG = 'sponsored-post-guide';

    public const FEATURED_ASSET = 'assets/img/blog/sponsored-post-guide-featured.jpg';

    public const FEATURED_STORAGE = 'blogs/featured/sponsored-post-guide-featured.jpg';

    public const IMAGE_COMPARE = 'sponsored-post-guide-compare.jpg';

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
     *     meta_title: string,
     *     meta_description: string,
     *     faq: list<array{question: string, answer: string}>
     * }
     */
    public static function payload(): array
    {
        return [
            'title' => 'Sponsored Posts: What They Are, How They Work and What Advertisers Should Know',
            'slug' => self::SLUG,
            'primary_locale' => 'en',
            'excerpt' => 'Sponsored posts are paid placements. This guide covers how they differ from guest posts, how to choose publishers, and how paid links should be labelled.',
            'content' => self::contentHtml(),
            'author' => 'M. Arslan',
            'tags' => ['Sponsored posts', 'Native advertising', 'Guest posts', 'Paid links', 'SEO'],
            'status' => 'published',
            'featured_image' => self::FEATURED_STORAGE,
            'meta_title' => 'Sponsored Posts Guide: What Advertisers Should Know',
            'meta_description' => 'Sponsored posts explained: how they differ from guest posts, how to choose publishers, and how paid links should be labelled. A checklist for advertisers.',
            'faq' => self::faqItems(),
            'translations' => self::translations(),
        ];
    }

    /**
     * Visible FAQ lives in the article HTML. Empty here so the layout does not
     * emit FAQPage schema (Google limits those rich results to government and health sites).
     *
     * @return list<array{question: string, answer: string}>
     */
    public static function faqItems(): array
    {
        return [];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function translations(): array
    {
        return class_exists(SponsoredPostGuideI18n::class)
            ? SponsoredPostGuideI18n::all()
            : [];
    }

    public static function contentHtml(): string
    {
        $backlinks = '/blog/how-to-get-backlinks';
        $guest = '/blog/guest-posting-guide';
        $linkGuide = '/blog/link-building-guide';
        $chooseSite = '/blog/how-to-choose-a-publisher-site-dr-da-traffic-niche';
        $buyGuide = '/blog/how-to-buy-guest-posts-on-seolinkbuildings-advertiser-guide';
        $brief = '/blog/guest-post-brief-anchors-urls-images-sensitive-topics';
        $dofollow = '/blog/dofollow-nofollow-and-anchor-text-for-marketplace-links';
        $price = '/blog/how-to-price-your-site-and-sensitive-niches';
        $live = '/blog/what-to-check-after-the-live-link-indexation-attributes-rankings';
        $removed = '/blog/what-happens-if-a-live-link-is-removed';
        $catalog = '/marketplace';
        $how = '/how-it-works';
        $imgCompare = BlogInlineImages::publicUrl(self::IMAGE_COMPARE);

        return <<<HTML
<p>A sponsored post is an article (or a section of an article) that a publisher runs because someone paid, bartered, or otherwise compensated them for the placement. It is advertising that looks like editorial content. That is allowed. Pretending it is an unpaid endorsement is not.</p>
<p>Advertisers use sponsored posts for reach, for a URL they want cited, or both. Those are different jobs. A nofollow article on a site your buyers actually read can be a better spend than an unqualified link on a site nobody visits.</p>
<p>This guide is for people deciding whether to buy a placement, and for publishers deciding how to sell one without creating a mess. For unpaid contributor workflows see the <a href="{$guest}">guest posting guide</a>. For the wider tactic mix see the <a href="{$linkGuide}">link building guide</a>.</p>

<h2>What is a sponsored post?</h2>
<p>A sponsored post is native advertising on a publisher’s site: the advertiser funds the piece, the publisher hosts it, and readers should be able to tell that money was involved. Other names you will hear — advertorial, paid post, partner content, sponsored article — describe the same commercial fact with different house styles.</p>
<p>Compensation is not only a wire transfer. Free product, affiliate terms that require a link, or a discounted service in exchange for a live URL still sit on the paid side of the line. If the article would not exist without that deal, treat it as sponsored.</p>

<h2>Sponsored vs guest post vs editorial article</h2>
<table>
<thead>
<tr><th></th><th>Editorial article</th><th>Guest post</th><th>Sponsored post</th></tr>
</thead>
<tbody>
<tr><td>Who initiates</td><td>The publisher’s staff</td><td>Contributor or publisher</td><td>Advertiser or sales desk</td></tr>
<tr><td>Payment for the placement</td><td>No</td><td>Sometimes</td><td>Yes (money or other value)</td></tr>
<tr><td>Editorial control</td><td>Full</td><td>Shared</td><td>Shared, with a commercial brief</td></tr>
<tr><td>Disclosure</td><td>Not required for a normal citation</td><td>Depends on whether it is paid</td><td>Required in most advertising regimes</td></tr>
<tr><td>Link labelling</td><td>Normal editorial link</td><td>Follow contributor rules</td><td>Qualify paid links (<code>sponsored</code> or <code>nofollow</code>)</td></tr>
</tbody>
</table>
<p>People blur these on purpose. A “guest post” invoice for an exact-match dofollow link is a sponsored placement wearing a friendlier name. Keep the vocabulary honest in your own reports, even if a vendor does not.</p>
<figure>
<img src="{$imgCompare}" alt="Comparison of editorial articles, guest posts, and sponsored posts: who starts, payment, disclosure, and link labelling" loading="lazy" width="1200" height="675">
<figcaption>Paid placements are allowed. Selling an unqualified ranking link is the problem Google’s spam policies describe.</figcaption>
</figure>

<h2>How sponsored publishing works</h2>
<p>Typical path:</p>
<ol>
<li>The advertiser picks a landing page and a job for the placement (traffic, citation, or both).</li>
<li>They shortlist publishers by topic, audience, language, and inventory rules.</li>
<li>They agree price, disclosure, link attribute, number of hrefs, images, and how long the URL should stay live.</li>
<li>Either side writes. The publisher edits for house style and policy.</li>
<li>The article goes live with the agreed labelling.</li>
<li>The advertiser checks the live URL, then rechecks later. Links get edited. Pages get taken down. See <a href="{$live}">what to check after the live link</a> and <a href="{$removed}">what happens if a live link is removed</a>.</li>
</ol>
<p>On a marketplace such as <a href="{$catalog}">SEOLinkBuildings</a>, those rules often sit on the listing: niche, turnaround, sensitive-topic add-ons, link type. That makes comparison easier. It does not make every listing a good buy. Product flow: <a href="{$how}">how it works</a> and the <a href="{$buyGuide}">advertiser buy guide</a>.</p>

<h2>Why companies use sponsored content</h2>
<p>Three honest reasons, and one weak one:</p>
<ul>
<li><strong>Reach.</strong> The publisher already has readers in your category. You are renting attention.</li>
<li><strong>A citation on a relevant URL.</strong> You want a crawlable mention next to related content, labelled as paid.</li>
<li><strong>Creative control.</strong> You need a specific story told, with claims you can stand behind, faster than a PR cycle.</li>
<li><strong>Buying “ranking juice” in bulk.</strong> This is the weak one. It is also the pattern Google’s spam policies describe when paid links are sold to manipulate rankings without qualifying attributes.</li>
</ul>
<p>If the only reason you are buying the article is a third-party “authority” number, slow down. Read the page a stranger would see.</p>

<h2>How advertisers should choose publishers</h2>
<p>Imagine you are comparing two sites for a project-management tool aimed at construction firms. Site A has a higher vendor score and a rate card that promises a dofollow article in 48 hours. Recent posts are generic “productivity hacks” with outbound links to gambling brands. Site B is a trade magazine for site managers, with named journalists and a smaller audience. Site B is usually the better placement for both readers and a relevant citation.</p>

<h3>Website relevance</h3>
<p>The host should already cover the topic without you. Adjacent is fine. Unrelated is not. A recipe blog is not a construction trade journal because both “have traffic.”</p>

<h3>Audience</h3>
<p>Who subscribes, who comments, who would actually buy. A site that ranks for informational queries in your niche can still be a poor advertiser fit if those readers are students, not buyers. Look at the calls to action the publisher already uses.</p>

<h3>Traffic</h3>
<p>Estimates from SEO tools are directional. Ask the publisher for a screenshot from their analytics if the fee is large. Then look at the specific section you would occupy, not the whole domain. A site with most of its visits on a weather widget does not transfer that audience to your sponsored article.</p>

<h3>Content quality</h3>
<p>Read the last five posts. Original reporting, dated updates, and outbound links to sources are good signs. Identical sentence rhythm across categories, stock photos on every piece, and a footer full of “partners” are not.</p>
<p>A longer marketplace-oriented filter is in <a href="{$chooseSite}">how to choose a publisher site</a>.</p>

<h3>Publisher requirements</h3>
<p>Get the constraints in writing: word count, who writes, revision rounds, image rights, prohibited claims, sensitive topics (crypto, CBD, gambling, adult), and whether they will publish a competitor next to you. If you supply the copy, use a real brief — <a href="{$brief}">anchors, URLs, images, sensitive topics</a>.</p>

<h3>Pricing</h3>
<p>There is no honest global price list. Fees move with language, niche, traffic, exclusivity, whether the publisher writes, and whether the topic is treated as high-risk. Treat a “DA50 for €X” sheet with no URLs as a warning, not a bargain.</p>
<p>Publishers setting their own rates can start from <a href="{$price}">how to price your site and sensitive niches</a>. Advertisers should ask what the fee actually buys: a URL for twelve months, a social post, a newsletter mention, or only the article.</p>

<h2>Link attributes and Google’s guidance</h2>
<p>Google’s spam policies treat buying or selling links <em>that pass ranking credit</em> to manipulate search as link spam. The same documentation says advertising and sponsorship links are a normal part of the web when they are qualified so they are not a ranking shortcut.</p>
<p>That is the line. Paid placements are not forbidden. Selling an unqualified link as a ranking product is the problem.</p>

<h3><code>rel="sponsored"</code></h3>
<p>Google documents <code>rel="sponsored"</code> as the attribute for advertising and paid placements (added in 2019 alongside <code>rel="ugc"</code>). Use it when money, product, or another commercial deal sits behind the href. You can combine tokens, for example <code>rel="nofollow sponsored"</code>, if that matches the publisher’s template.</p>

<h3><code>rel="nofollow"</code></h3>
<p><code>rel="nofollow"</code> remains valid. Google has said it will treat <code>nofollow</code>, <code>sponsored</code>, and <code>ugc</code> as hints about how to crawl or rank those links, not as a hard exclusion. A nofollow sponsored article can still send people. It is not a loophole and it is not worthless.</p>
<p>Do not ask a publisher to strip <code>sponsored</code> so the link “counts more.” That request is the campaign. More detail: <a href="{$dofollow}">dofollow, nofollow, and anchor text</a>.</p>

<h2>Risks of low-quality sponsored placements</h2>
<ul>
<li><strong>Link networks.</strong> Dozens of sites with the same theme, the same outbound clients, and no audience. Cheap until a pattern is obvious.</li>
<li><strong>PBNs.</strong> Sites controlled to point at a customer. If the seller will not show URLs up front, assume this.</li>
<li><strong>Exact-match anchors on every live URL.</strong> Looks engineered because it is.</li>
<li><strong>Noindex or robots blocks</strong> on the “published” article. You paid for a page crawlers never see.</li>
<li><strong>Silent edits.</strong> Your href becomes a homepage, or the attribute changes after the screenshot.</li>
<li><strong>Brand damage.</strong> Your name next to medical claims, payday loans, or scraped content you would not show a customer.</li>
</ul>
<p>Acquisition methods and evaluation sit in <a href="{$backlinks}">how to get backlinks</a>. Sponsored inventory is one method. It inherits the same quality test.</p>

<h2>What advertisers should ask publishers before ordering</h2>
<p>A usable pre-order list:</p>
<ul>
<li>Which exact URL will host the article, or which section?</li>
<li>Who writes, and how many revision rounds?</li>
<li>What <code>rel</code> attribute will the commercial links use?</li>
<li>How will the article be labelled for readers (Sponsored, Partner, Advertisement)?</li>
<li>Will the page be indexable? Any <code>noindex</code> on sponsored templates?</li>
<li>How long will it stay live, and what happens if it is taken down?</li>
<li>How many outbound commercial links are allowed?</li>
<li>Can we approve the final draft before it goes live?</li>
<li>Sensitive-topic policy and extra fees?</li>
<li>Do you need our trademark and claims in writing?</li>
</ul>
<p>If the answers are vague, the live article will be vague. Walk away while it is still a quote.</p>

<h2>What publishers should disclose</h2>
<p>Readers should understand that the piece is paid. A small “sponsored” label near the headline is the usual pattern. Burying “partner content” in a grey footer is how you look like you hoped nobody would notice.</p>
<p>In the United States, the Federal Trade Commission’s endorsement guidance expects a clear disclosure when there is a material connection between advertiser and publisher. Other markets have their own advertising standards. This is not legal advice; it is the reason “just put a dofollow in paragraph two and say nothing” is a bad policy.</p>
<p>On the HTML side, qualifying paid hrefs with <code>rel="sponsored"</code> (or <code>nofollow</code>) matches what Google documents. Disclosure for humans and attributes for crawlers are two jobs. Do both.</p>

<h2>How to evaluate sponsored article opportunities</h2>
<p>Score the page, not the logo.</p>
<ol>
<li>Read the URL that would carry the article.</li>
<li>Check topical overlap with your landing page.</li>
<li>Inspect outbound links on recent posts.</li>
<li>Confirm indexability and a real about/contact identity.</li>
<li>Write down attribute, disclosure, and live-duration terms.</li>
<li>Decide the job: referral traffic, labelled citation, or both.</li>
<li>Price against that job. A cheap link that cannot be shown to a client is not cheap.</li>
</ol>

<h2>Practical checklist</h2>
<ul>
<li>The placement is labelled as paid for readers.</li>
<li>Commercial links use <code>sponsored</code> and/or <code>nofollow</code> as agreed.</li>
<li>The host’s audience overlaps your buyer, not only your keyword.</li>
<li>The landing page is the best URL for that topic, not a random homepage.</li>
<li>Anchors read like a sentence, not a paid denseness experiment.</li>
<li>You have the live URL, a screenshot, and a recheck date.</li>
<li>You are not buying a pack of anonymous “DA” slots.</li>
</ul>

<h2>Frequently asked questions</h2>
<h3>Are sponsored posts allowed by Google?</h3>
<p>Advertising is a normal part of the web. Google’s spam policies object to buying or selling links to manipulate rankings when those links are not qualified. Use <code>rel="sponsored"</code> or <code>rel="nofollow"</code> on paid hrefs and disclose the relationship to readers.</p>
<h3>Is a sponsored post the same as a guest post?</h3>
<p>No. A guest post is contributed content; it may be unpaid. A sponsored post is a commercial placement. Some vendors use “guest post” for both. Your contract and your <code>rel</code> attributes should still tell the truth.</p>
<h3>Will a sponsored link improve my rankings?</h3>
<p>Nobody can promise that. Rankings depend on the page, the query, competitors, and many signals besides one href. A relevant article can still be worth it for the readers it sends.</p>
<h3>Should every paid link be nofollow?</h3>
<p>Google’s documented qualifier for paid placements is <code>sponsored</code>. <code>nofollow</code> is also acceptable. The failure mode is an unqualified link sold as a ranking product.</p>
<h3>How much does a sponsored post cost?</h3>
<p>It depends on the site, language, niche, and whether the publisher writes. Ignore global “average price” claims unless they show a methodology and a site list. Compare listings, then read the sites.</p>
<h3>Do I need to disclose if I only paid for “content,” not for the link?</h3>
<p>If the article exists because of a commercial deal, treat it as sponsored. Splitting the invoice into “writing” and “the href” does not change what the reader or a crawler is looking at.</p>
<h3>Can I use exact-match commercial anchors?</h3>
<p>You can write any sentence the publisher accepts. Repeating the same commercial phrase across paid sites is a link-spam pattern in Google’s policies. Prefer brand, URL, and descriptive language.</p>

<h2>Sources and further reading</h2>
<ul>
<li><a href="https://developers.google.com/search/docs/essentials/spam-policies">Google Search Central — Spam policies for Google web search</a> (paid links, qualifying attributes)</li>
<li><a href="https://developers.google.com/search/blog/2019/09/evolving-nofollow-new-ways-to-identify">Google Search Central Blog — Evolving nofollow</a> (<code>sponsored</code>, <code>ugc</code>, hints)</li>
<li><a href="https://developers.google.com/search/blog/2021/07/link-tagging-and-link-spam-update">Google Search Central Blog — Qualifying links and link spam</a></li>
<li><a href="https://developers.google.com/search/docs/crawling-indexing/links-crawlable">Google Search Central — Link best practices (crawlable links)</a></li>
<li><a href="https://www.ftc.gov/business-guidance/resources/ftcs-endorsement-guides-what-people-are-asking">US FTC — Endorsement Guides: What People Are Asking</a> (material connections and disclosure; US)</li>
</ul>
HTML;
    }
}
