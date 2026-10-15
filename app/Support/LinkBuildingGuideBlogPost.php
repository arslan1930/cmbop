<?php

namespace App\Support;

/**
 * English pillar: link building as a strategy, not a volume contest.
 */
class LinkBuildingGuideBlogPost
{
    public const SLUG = 'link-building-guide';

    public const FEATURED_ASSET = 'assets/img/blog/link-building-guide-featured.jpg';

    public const FEATURED_STORAGE = 'blogs/featured/link-building-guide-featured.jpg';

    public const IMAGE_ROADMAP = 'link-building-guide-roadmap.jpg';

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
            'title' => 'Link Building Guide: A Practical SEO Strategy for Building Authority',
            'slug' => self::SLUG,
            'primary_locale' => 'en',
            'excerpt' => 'A practical link-building strategy: how search engines treat links, which tactics are sustainable, how to measure a campaign, and what to skip.',
            'content' => self::contentHtml(),
            'author' => 'M. Arslan',
            'tags' => ['Link building', 'Backlinks', 'SEO strategy', 'Digital PR', 'Outreach'],
            'status' => 'published',
            'featured_image' => self::FEATURED_STORAGE,
            'meta_title' => 'Link Building Guide: A Practical SEO Authority Plan',
            'meta_description' => 'A practical link-building strategy: types of links, content and PR, guest posts, outreach, anchors, measurement, and risky tactics to skip.',
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
        return class_exists(LinkBuildingGuideI18n::class)
            ? LinkBuildingGuideI18n::all()
            : [];
    }

    public static function contentHtml(): string
    {
        $backlinks = '/blog/how-to-get-backlinks';
        $guest = '/blog/guest-posting-guide';
        $sponsored = '/blog/sponsored-post-guide';
        $chooseSite = '/blog/how-to-choose-a-publisher-site-dr-da-traffic-niche';
        $dofollow = '/blog/dofollow-nofollow-and-anchor-text-for-marketplace-links';
        $outreach = '/blog/marketplace-vs-cold-outreach-vs-digital-pr';
        $live = '/blog/what-to-check-after-the-live-link-indexation-attributes-rankings';
        $aeo = '/blog/ai-aeo-seo-why-guest-posts-and-brand-mentions-matter';
        $buyGuide = '/blog/how-to-buy-guest-posts-on-seolinkbuildings-advertiser-guide';
        $catalog = '/marketplace';
        $how = '/how-it-works';
        $imgRoadmap = BlogInlineImages::publicUrl(self::IMAGE_ROADMAP);

        return <<<HTML
<p>Link building is the work of earning or placing hyperlinks from other websites to yours. Search engines use links, among other signals, to discover pages and to interpret how sites relate. That is the mechanism. It is not a promise that ten new hrefs will move a keyword.</p>
<p>This guide is the strategy layer: types of links, how to mix tactics, how to measure, and what to refuse. Tactical acquisition lives in <a href="{$backlinks}">how to get backlinks</a>. Contributor workflow is in the <a href="{$guest}">guest posting guide</a>. Paid placements are in the <a href="{$sponsored}">sponsored posts guide</a>.</p>

<h2>What is link building?</h2>
<p>In practice, link building is a campaign with a target URL, a reason someone would cite that URL, a way to reach publishers, and a definition of “done” that is not just a row in a spreadsheet. Teams also use “link earning” when the citation happens because the page deserved it, with outreach as a nudge rather than the product.</p>
<p>Both phrases describe the same job when they are honest. “Building” without a page worth citing is just outbound email. “Earning” without a distribution plan is a PDF nobody sees.</p>

<h2>Why links matter</h2>
<p>Links still do three useful things:</p>
<ul>
<li><strong>Discovery.</strong> Crawlers follow hrefs. A crawlable <code>&lt;a href&gt;</code> is how many new URLs get found. Google documents that separately from ranking.</li>
<li><strong>Context.</strong> The linking page, the anchor, and surrounding sentences are clues about what your URL is. Unrelated anchors on unrelated sites are noise.</li>
<li><strong>People.</strong> Referral visits, branded searches after a mention, and newsletter clicks do not require a ranking report to be real.</li>
</ul>
<p>Links are one factor among content quality, intent match, internal structure, and technical access. Google’s helpful-content guidance is about pages written for people. Link building that ignores that document is theatre.</p>

<h2>How search engines interpret links</h2>
<p>You do not get a public formula. You do get public constraints:</p>
<ul>
<li>Google’s spam policies list patterns used to manipulate ranking with links: buying unqualified ranking links, large-scale guest posting with keyword-rich anchors, excessive exchanges, automated generation, PBNs, and similar schemes.</li>
<li>Advertising and sponsorship are expected to use qualifying attributes (<code>sponsored</code> or <code>nofollow</code>).</li>
<li>Since 2019, <code>nofollow</code>, <code>sponsored</code>, and <code>ugc</code> are hints, not a hard “ignore this.”</li>
<li>JavaScript-only or non-<code>&lt;a href&gt;</code> “links” may not be followed. If you need a crawler to see it, use a normal crawlable link.</li>
</ul>
<p>Treat third-party “authority” scores as vendor estimates. They can rank a shortlist. They cannot certify a link.</p>

<h2>Types of backlinks</h2>
<table>
<thead>
<tr><th>Type</th><th>How it usually happens</th><th>What to watch</th></tr>
</thead>
<tbody>
<tr><td>Editorial citation</td><td>Someone references your page in their own article</td><td>The page has to be worth citing</td></tr>
<tr><td>Guest / contributed article</td><td>You write for their audience</td><td>Host quality; keyword-stuffed anchors</td></tr>
<tr><td>Sponsored / native ad</td><td>You pay for the placement</td><td>Disclosure and <code>rel="sponsored"</code></td></tr>
<tr><td>Digital PR / news</td><td>A story, dataset, or comment gets covered</td><td>Mentions without hrefs still happen</td></tr>
<tr><td>Resource / directory</td><td>A curated list includes you</td><td>Junk directories that exist to sell links</td></tr>
<tr><td>User-generated</td><td>Comments, profiles, forums</td><td>Often <code>ugc</code> / nofollow; easy to spam</td></tr>
<tr><td>Internal</td><td>Your own site links between pages</td><td>Not a backlink; still part of the strategy</td></tr>
</tbody>
</table>
<p>A referring domain is the site sending at least one link. Ten links from one domain are not ten independent endorsements. Report both counts.</p>

<h2>Link earning vs link building</h2>
<p>Earning starts with an asset: original data, a tool, documentation, a unique method. Building starts with a list of sites and a pitch. Most working programs do both. A research post with no outreach can stall. Outreach with nothing to show is a template.</p>
<p>If you cannot explain in one sentence why a stranger would bookmark the target URL, fix the URL before you hire outreach.</p>

<h2>Content-led link building</h2>
<p>Pages that attract citations without begging: calculators, original surveys, public datasets, maintained glossaries, implementation docs other tutorials cite. Build the asset. Then tell people who already write about the topic that it exists.</p>
<p>This is slow. It is also the pattern that still works when a marketplace account is paused and a journalist is not answering email. Pair it with <a href="{$aeo}">why guest posts and brand mentions still matter for SEO and answer engines</a> if your goal includes being named, not only linked.</p>

<h2>Digital PR</h2>
<p>Digital PR is a reason to be covered: a finding, a method, a comment that adds something, a tool other writers can point to. Coverage sometimes includes a link. Sometimes you get a brand mention. Mentions help people search for you. They are not the same as a crawlable href.</p>
<p>Journalist query desks (the market that used to centre on HARO has moved toward products such as Connectively and similar) are a beat list. They are not a vending machine. Compare that channel with catalogs and cold email in <a href="{$outreach}">marketplace vs cold outreach vs digital PR</a>.</p>

<h2>Guest posting</h2>
<p>Contributing an article to a relevant host can put a citation in front of their readers. The risk is industrialising it on sites that publish anything that pays, with the same commercial anchor. Host selection is the tactic. Process: <a href="{$guest}">guest posting guide</a>.</p>

<h2>Resource links and broken-link opportunities</h2>
<p>Resource pages (university lists, trade-body roundups, library guides) still add relevant citations when your page belongs on the list. Quote the section you want updated. Do not mail-merge 80 librarians.</p>
<p>Broken-link building is the same idea with a 404 in the way. Offer a genuine replacement. “Your link is dead, please use my homepage” is why reply rates died.</p>

<h2>Competitor research</h2>
<p>Export referring domains for a URL that already ranks. Sort by relevance and evidence of real content, not by the highest vendor score. For each domain ask: could I earn a similar citation with a better resource, a guest article, a correction, or a labelled sponsored placement?</p>
<p>Copying their exact anchor list onto random blogs is how your profile starts looking like theirs on a bad day — including the parts you should not copy.</p>

<h2>Outreach</h2>
<p>Name the page, why their reader benefits, and the specific ask. One follow-up. Then stop. Tools send the mail. They do not make the argument. A marketplace listing is outreach with the rules printed on the product page; use it when you want comparable inventory, not when you want to skip evaluation. Catalog: <a href="{$catalog}">SEOLinkBuildings</a>. Flow: <a href="{$how}">how it works</a>. Publisher filters: <a href="{$chooseSite}">how to choose a publisher site</a>.</p>

<h2>Anchor text</h2>
<p>Write the sentence first. Then decide which words are clickable. Brand, naked URL, and a descriptive phrase are the mix that survives a screenshot in a client review. Repeating the same exact-match commercial phrase across domains is a pattern Google’s spam policies have named for years.</p>
<p>Attributes (<code>nofollow</code>, <code>sponsored</code>, <code>ugc</code>) are part of the same decision. Paid hrefs should be qualified. Details: <a href="{$dofollow}">dofollow, nofollow, and anchor text</a> and the <a href="{$sponsored}">sponsored posts guide</a>.</p>

<h2>Internal links</h2>
<p>Internal links are not backlinks. They are still the cheapest way to tell search engines which of your URLs is the hub for a topic. A new citation to a dead-end page with no path to the rest of the site wastes the click. Before you scale outreach, fix the internal path: hub article, supporting pieces, clear next step.</p>
<p>This cluster is an example: acquisition, guest posts, sponsored posts, and this strategy page point at each other because a reader actually needs the next document. That is the test. If the only reason for the href is “internal linking for SEO,” rewrite the sentence.</p>

<h2>Link relevance</h2>
<p>Relevance is topical and, often, geographic or linguistic. A German-language trade journal is a weak citation for an English-only US consumer landing page, even if a dashboard calls both “DA 50.” Page-level context beats homepage category. If removing the link would not change the article, the editor did not need you.</p>

<h2>Measuring a campaign</h2>
<p>Pick one primary URL. Track:</p>
<ul>
<li>Referring domains and live URLs (with attributes and anchors as agreed)</li>
<li>Indexation of the linking page</li>
<li>Referral visits and what they do on the landing page</li>
<li>Queries where that URL already had impressions — movement, not a fantasy of “rank #1”</li>
<li>Losses: removed links, changed attributes, noindex after the fact (<a href="{$live}">live-link checklist</a>)</li>
</ul>
<p>Do not report “50 backlinks” when 40 are from one network. Do not declare victory because a vendor score ticked up 2 points. If the first handful of placements send no readers and never get indexed, stop adding volume.</p>

<h2>Common mistakes</h2>
<ul>
<li>Spreading weak links across too many URLs so nothing learns</li>
<li>Exact-match anchors on every live article</li>
<li>Ignoring the landing page</li>
<li>Treating nofollow as useless and sponsored as optional</li>
<li>No live-URL recheck</li>
<li>Buying from sellers who will not name the site before payment</li>
</ul>

<h2>Risky link-building tactics</h2>
<p>Skip, even when a vendor swears it is “what Google cannot see”:</p>
<ul>
<li>Private blog networks (PBNs)</li>
<li>Automated comment, profile, and widget links</li>
<li>Link farms and paid “SEO directories” with no independent purpose</li>
<li>Industrial reciprocal-link pages</li>
<li>Expired-domain networks rebuilt to link out</li>
<li>Large-scale guest posting on irrelevant sites with keyword-rich anchors</li>
<li>Buying unqualified dofollow links as a ranking product</li>
</ul>
<p>Google’s spam policies describe these patterns in public. You do not need a leak or a rumour. If the tactic only works when nobody looks at the site, it is not a strategy.</p>

<h2>Building a sustainable strategy</h2>
<p>Sustainability here means: you can explain every live URL to a sceptical editor, the mix does not depend on one vendor, and you can pause for a quarter without the profile looking like a switch was flipped.</p>
<p>A durable mix for most commercial sites:</p>
<ul>
<li>One or two linkable assets you actually maintain</li>
<li>A small guest-post or contributor program on sites you would show a customer</li>
<li>Occasional labelled sponsored placements where the audience is real</li>
<li>PR when you have a story, not a quota</li>
<li>Internal linking and content quality as the floor, not a side project</li>
</ul>
<p>Marketplaces are a procurement tool inside that mix. They are not a substitute for the mix. Ordering notes: <a href="{$buyGuide}">how to buy guest posts on SEOLinkBuildings</a>.</p>

<h2>Beginner roadmap</h2>
<figure>
<img src="{$imgRoadmap}" alt="Link-building roadmap from beginner to advanced: one URL first, then assets and mixed tactics, then a publication roster" loading="lazy" width="1200" height="675">
<figcaption>Learn host quality before you add volume. A broken pattern scaled is still a broken pattern.</figcaption>
</figure>
<ol>
<li>Choose one URL. Make it the best page you have on that topic.</li>
<li>Add internal links to it from related articles.</li>
<li>List 20 sites that already cover the topic. Read them. Cut the junk.</li>
<li>Pitch or place five relevant citations. Mix brand and descriptive anchors.</li>
<li>Recheck live URLs after two weeks and after two months.</li>
</ol>
<p>You are learning host quality and landing-page fit, not “building authority” as a slogan.</p>

<h2>Intermediate roadmap</h2>
<ol>
<li>Ship one asset designed to be cited (data, tool, or reference).</li>
<li>Run competitor referring-domain research on two ranking URLs.</li>
<li>Split work: earned outreach, a few labelled sponsored placements, contributor articles.</li>
<li>Track referring domains, referrals, and query impressions in Search Console.</li>
<li>Kill sources that fail the live-URL test. Double down on hosts that send people.</li>
</ol>

<h2>Advanced strategy</h2>
<ol>
<li>Operate a small roster of publications you can return to with new angles.</li>
<li>Align PR, product launches, and documentation so citations have a destination.</li>
<li>Localise where the business is actually local — language and country are relevance, not decoration.</li>
<li>Treat answer-engine visibility as brand mentions plus crawlable citations, not a new trick.</li>
<li>Review the profile for patterns you would not want screenshot in a spam-policy discussion. Fix what you control. Do not panic-disavow normal junk.</li>
</ol>

<h2>Link-building checklist</h2>
<ul>
<li>Target URL deserves a citation.</li>
<li>Each prospect has topical or audience overlap.</li>
<li>You know whether the href is earned, contributed, or paid.</li>
<li>Paid hrefs are qualified; readers can see sponsorship where it applies.</li>
<li>Anchor mix would survive a human review.</li>
<li>Live URL, attribute, and landing page are logged.</li>
<li>Internal links support the same URL.</li>
<li>You have a stop rule when quality drops.</li>
</ul>

<h2>Frequently asked questions</h2>
<h3>How many backlinks do I need to rank?</h3>
<p>There is no quota that works across markets. A local service page and a competitive software category do not share a number. Track relevant queries and useful referrals, not a running total.</p>
<h3>Are backlinks still a ranking factor?</h3>
<p>Search engines still use links as a way to discover and interpret pages. Google also publishes spam policies against manipulative link schemes. Treat links as one signal among many, not as a lever you pull for a guaranteed position.</p>
<h3>What is the difference between dofollow and nofollow?</h3>
<p>“Dofollow” is marketing shorthand for a link with no rel qualifier. <code>nofollow</code> and <code>sponsored</code> are hints. Paid placements should be qualified. None of this replaces relevance and a page people want to read.</p>
<h3>Is link building the same as SEO?</h3>
<p>No. SEO includes crawling, indexing, content, intent, internal links, and a long list of other work. Link building is one programme inside that. Ranking problems are often the page, not the href count.</p>
<h3>Should I disavow bad links?</h3>
<p>Most sites accumulate junk. Google has long cautioned against panic-disavowing ordinary links. Use Search Console, look for patterns you control or paid for, and get help if a known spam network is pointing at you at scale.</p>
<h3>Can I build links only with a marketplace?</h3>
<p>You can procure placements that way. You still need a page worth visiting and a quality bar for hosts. A catalog does not earn editorial citations by itself.</p>
<h3>How long does link building take?</h3>
<p>Outreach cycles are weeks. Meaningful movement on competitive queries is usually longer, and it depends on the rest of the site. Anyone selling a date-stamped ranking is selling a story.</p>

<h2>Sources and further reading</h2>
<ul>
<li><a href="https://developers.google.com/search/docs/essentials/spam-policies">Google Search Central — Spam policies for Google web search</a></li>
<li><a href="https://developers.google.com/search/blog/2019/09/evolving-nofollow-new-ways-to-identify">Google Search Central Blog — Evolving nofollow</a></li>
<li><a href="https://developers.google.com/search/blog/2021/07/link-tagging-and-link-spam-update">Google Search Central Blog — Qualifying links and link spam</a></li>
<li><a href="https://developers.google.com/search/docs/crawling-indexing/links-crawlable">Google Search Central — Link best practices (crawlable links)</a></li>
<li><a href="https://developers.google.com/search/docs/fundamentals/creating-helpful-content">Google Search Central — Creating helpful, reliable, people-first content</a></li>
</ul>
HTML;
    }
}
