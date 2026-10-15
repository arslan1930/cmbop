<?php

namespace App\Support;

/**
 * English pillar: how to get backlinks without treating every link as equal.
 */
class HowToGetBacklinksBlogPost
{
    public const SLUG = 'how-to-get-backlinks';

    public const FEATURED_ASSET = 'assets/img/blog/how-to-get-backlinks-featured.jpg';

    public const FEATURED_STORAGE = 'blogs/featured/how-to-get-backlinks-featured.jpg';

    public const IMAGE_METHODS = 'how-to-get-backlinks-methods.jpg';

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
            'title' => 'How to Get Backlinks: A Practical Guide to Earning High-Quality Links',
            'slug' => self::SLUG,
            'primary_locale' => 'en',
            'excerpt' => 'How to earn and evaluate backlinks: what makes a link useful, which tactics still work, and how paid placements differ from editorial links.',
            'content' => self::contentHtml(),
            'author' => 'M. Arslan',
            'tags' => ['Backlinks', 'Link building', 'Guest posts', 'Outreach', 'SEO'],
            'status' => 'published',
            'featured_image' => self::FEATURED_STORAGE,
            'meta_title' => 'How to Get Backlinks: Practical Guide to SEO Links',
            'meta_description' => 'Learn how to get backlinks that are worth having: relevance, referring domains, guest posts, digital PR, and how paid placements differ from earned links.',
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
        return class_exists(HowToGetBacklinksI18n::class)
            ? HowToGetBacklinksI18n::all()
            : [];
    }

    public static function contentHtml(): string
    {
        $guest = '/blog/guest-posting-guide';
        $sponsored = '/blog/sponsored-post-guide';
        $linkGuide = '/blog/link-building-guide';
        $chooseSite = '/blog/how-to-choose-a-publisher-site-dr-da-traffic-niche';
        $buyGuide = '/blog/how-to-buy-guest-posts-on-seolinkbuildings-advertiser-guide';
        $dofollow = '/blog/dofollow-nofollow-and-anchor-text-for-marketplace-links';
        $outreach = '/blog/marketplace-vs-cold-outreach-vs-digital-pr';
        $catalog = '/marketplace';
        $how = '/how-it-works';
        $imgMethods = BlogInlineImages::publicUrl(self::IMAGE_METHODS);

        return <<<HTML
<p>A backlink is a hyperlink from one website to another. Search engines use those links, among many other signals, to understand how pages relate and which sources other sites consider worth citing.</p>
<p>That is the whole mechanic. Everything else in this guide is about not wasting months collecting links that do not help the page they point to.</p>
<p>If you want the wider strategy — types of links, measurement, and a beginner-to-advanced roadmap — use the <a href="{$linkGuide}">link building guide</a>. This page stays on acquisition: how people actually get backlinks, and how to tell a useful one from a noisy one.</p>

<h2>What is a backlink?</h2>
<p>In plain terms: site A publishes a page that links to a URL on site B. That link is a backlink for site B. The site sending the link is a <strong>referring domain</strong> if you have not counted it before.</p>
<p>People mix up “backlinks” and “referring domains.” Ten links from one blog are not the same as ten links from ten different publishers. Campaign reports should show both.</p>
<p>A backlink can be:</p>
<ul>
<li><strong>Editorial</strong> — a writer or editor chose to cite you because the page helps their reader</li>
<li><strong>Earned through outreach</strong> — you asked, they agreed, the page still has to be useful</li>
<li><strong>Paid or sponsored</strong> — money, product, or another commercial deal sits behind the placement</li>
<li><strong>User-generated</strong> — comments, profiles, forum signatures</li>
</ul>
<p>Those are not equal. Google’s spam policies treat buying links <em>to manipulate rankings</em> as link spam, and they also say advertising and sponsorship links are a normal part of the web when they are qualified with <code>rel="sponsored"</code> or <code>rel="nofollow"</code>. Details live in the <a href="{$sponsored}">sponsored posts guide</a>.</p>

<h2>Why backlinks can matter</h2>
<p>Links are not a secret ranking cheat code. They are one way search systems discover pages and judge whether other sites treat a URL as a reference. A link from a page that already ranks for your topic, gets real readers, and sits in a sentence about that topic is more interesting than a footer dump on an unrelated site.</p>
<p>Backlinks also send people. If nobody clicks the link, you still have a citation. If they click and bounce because the landing page is a homepage with no matching content, you wasted the placement.</p>
<p>Do not expect “get N backlinks, rank #1.” Rankings move with content, intent match, internal links, technical access, and competitors doing the same work. Links are a factor, not a contract.</p>

<h2>What makes a backlink valuable</h2>
<p>Value is not a single metric. Score the page, not the logo in the header.</p>

<h3>Relevance</h3>
<p>The linking page should be about a topic a human would associate with your URL. A cybersecurity SaaS does not need a link from a recipe roundup. Adjacent is fine — a privacy law explainer citing an encryption product can be more useful than another SaaS “best tools” list that nobody reads.</p>
<p>Site-level niche helps. Page-level context matters more. Read the paragraph around the link, not only the homepage category.</p>

<h3>Referring domains vs raw link count</h3>
<p>New domains usually teach search systems more than the fifth link from the same publisher. Repeating the same site is still valid for traffic and branding. Just do not report it as five independent endorsements.</p>

<h3>Editorial context</h3>
<p>In-body links in a real article beat sitewide sidebar links, author-bio spam, and widget footers. If the sentence works without your URL, the editor did not need you. If removing the link would leave a hole in the argument, you are in better shape.</p>

<h3>Website quality</h3>
<p>Look at the page a stranger would land on: original writing, dated updates, outbound links to sources, an about page that names a company, working contact details. Thin English spun from another language, 40 outbound “partners” in one post, and a copyright year stuck on 2019 are not subtle.</p>
<p>Domain Rating, Domain Authority, and similar third-party scores are estimates from one vendor’s crawl. They are filters, not proof. Two sites with a similar score can have completely different traffic, topical focus, and outbound-link hygiene. See <a href="{$chooseSite}">how to choose a publisher site</a> for a marketplace-oriented checklist.</p>

<h3>Traffic</h3>
<p>Organic traffic to the linking URL is a useful sanity check. A page with no search demand and no shares can still be a clean citation. A page with real readers can send leads even if the link is marked <code>nofollow</code>. Decide whether you are buying (or earning) a ranking signal, an audience, or both — then pick sites that match that job.</p>

<h2>Different ways to get backlinks</h2>
<p>Most teams mix several of these. None of them replace a page that deserves to be cited.</p>
<figure>
<img src="{$imgMethods}" alt="Five ways to get backlinks: digital PR, guest post, resource page, broken link, and sponsored placement" loading="lazy" width="1200" height="675">
<figcaption>Useful backlinks come from methods that match the page — not from a volume quota.</figcaption>
</figure>
<table>
<thead>
<tr><th>Method</th><th>Typical speed</th><th>Editorial control</th><th>Main risk</th></tr>
</thead>
<tbody>
<tr><td>Digital PR / newsworthy assets</td><td>Slow</td><td>High if the story is real</td><td>No story, no coverage</td></tr>
<tr><td>Guest posting</td><td>Medium</td><td>Shared with the host</td><td>Low-quality host sites</td></tr>
<tr><td>Resource / broken-link outreach</td><td>Medium</td><td>High when the fit is obvious</td><td>Low reply rates</td></tr>
<tr><td>Marketplace placements</td><td>Fast once listed</td><td>Rules sit on the listing</td><td>Treating every listing as “editorial SEO juice”</td></tr>
<tr><td>Directories and associations</td><td>Fast</td><td>Low</td><td>Junk listings that exist to sell links</td></tr>
</tbody>
</table>

<h3>Guest posting</h3>
<p>You write (or supply) an article for another site and include a link back when the host allows it. Done well, the host gets a piece their readers will finish. Done badly, it is a 400-word advertisement with an exact-match anchor.</p>
<p>The full workflow — pitching, evaluating publishers, briefs, attributes — is in the <a href="{$guest}">guest posting guide</a>.</p>

<h3>Digital PR</h3>
<p>Digital PR is not “email 200 journalists a PDF.” It is a reason to be cited: original data, a method people can repeat, a comment that actually adds something, a tool other writers link as a reference. Coverage often includes a link; sometimes you only get a brand mention. Mentions still help people find you. They are not the same as a crawlable <code>&lt;a href&gt;</code>.</p>
<p>Journalist query services (the market that used to centre on HARO has shifted toward products such as Connectively and similar desks) can surface requests. Treat them as a beat list, not a link vending machine.</p>

<h3>Linkable assets</h3>
<p>Pages that attract links without a pitch: calculators, original surveys, public datasets, well-maintained glossaries, documentation that other tutorials cite. Build the asset first. Outreach second. If you cannot describe in one sentence why a stranger would bookmark it, it is not a linkable asset yet.</p>

<h3>Resource pages</h3>
<p>Many universities, libraries, and trade bodies keep “useful links” pages. If your page belongs on that list, a short email that quotes the current section and explains the gap is enough. Do not spray the same paragraph to 80 resource URLs.</p>

<h3>Broken-link opportunities</h3>
<p>Find a relevant page that already links out, catch a 404, and offer a working replacement — ideally yours, sometimes a third-party source plus yours if you are being honest. This only works when your page is a genuine substitute. “Your link is dead, please use my homepage” is why reply rates collapsed.</p>

<h3>Industry directories and associations</h3>
<p>Chamber of commerce, professional registers, software marketplaces with editorial review, and conference speaker lists can be legitimate. Paid “SEO directories” that exist to sell a dofollow slot to anyone with a card are the opposite. If the directory’s own articles are spun and every listing has the same three outbound links, skip it.</p>

<h3>Outreach</h3>
<p>Outreach is a process, not a tool. Name the page you are asking them to update, why their reader benefits, and what you want them to do. One specific ask beats a “loved your post, we should collaborate” template. Compare marketplace buying vs cold email vs PR in <a href="{$outreach}">marketplace vs cold outreach vs digital PR</a>.</p>

<h3>Competitor backlink research</h3>
<p>Export referring domains for a page that already ranks for your query. Sort by relevance and traffic, not vanity score. Ask: could I earn a similar citation with a better resource, a guest article, or a correction? Copying their exact anchor list onto random blogs is how campaigns start looking like a network.</p>

<h3>Paid and sponsored placements</h3>
<p>Paying a publisher to host an article or insert a link is advertising. Google’s documentation is explicit that this is allowed when the links are qualified so they are not sold as ranking manipulation. It is not the same as an editor independently citing you.</p>
<p>If you use a marketplace such as <a href="{$catalog}">SEOLinkBuildings</a>, treat listings as publisher inventory with rules (niches, turnaround, link type), not as a bag of “guaranteed ranking links.” The product path is in <a href="{$how}">how it works</a> and the <a href="{$buyGuide}">advertiser buy guide</a>.</p>

<h2>How to evaluate a backlink opportunity</h2>
<p>Suppose you are comparing two sites for a B2B payroll article. Site A has a higher third-party “authority” score, but the last 20 posts are casino roundups and the outbound links are all exact-match finance phrases. Site B is a smaller HR operators’ blog with named authors, comments from practitioners, and a post that already ranks for a related query. Site B is usually the better placement, even if the dashboard looks less impressive.</p>
<p>Run this pass before you pitch, buy, or celebrate a live URL:</p>
<ol>
<li>Read the specific URL that would carry the link, not only the domain.</li>
<li>Check topical overlap with your landing page.</li>
<li>Look at outbound links on recent posts. A farm is obvious.</li>
<li>Confirm the page is indexable (no accidental noindex, not blocked in robots.txt).</li>
<li>Note the offered link attribute: dofollow, nofollow, sponsored. See <a href="{$dofollow}">dofollow, nofollow, and anchor text</a>.</li>
<li>Decide the job: referral traffic, citation, or both.</li>
<li>Write the landing-page test: would a reader from that article get the next step without hunting?</li>
</ol>

<h2>A realistic backlink campaign</h2>
<p>Pick one URL. One. Spreading five mediocre links across five product pages teaches you nothing.</p>
<p>Example shape for a quarter, not a promise of results:</p>
<ul>
<li>Improve the target page so it deserves a citation (clear heading, evidence, next step).</li>
<li>Map 15 genuinely relevant publishers: 5 you might earn, 5 you might guest on, 5 you might pay with proper disclosure and attributes.</li>
<li>Cap exact-match anchors. Mix brand, URL, and descriptive phrases.</li>
<li>Ship, then wait. Recheck live URLs for the attribute you agreed, the target URL, and whether the article still exists in a month.</li>
</ul>
<p>If the first five placements send no clicks and the pages never get indexed, stop scaling. Change the page or the publisher set. Adding volume to a broken pattern is how people end up in link-spam territory without meaning to.</p>

<h2>Common mistakes</h2>
<ul>
<li>Buying bulk “DA50+ homepage links” from strangers with no site list.</li>
<li>Exact-match anchors on every placement.</li>
<li>Private blog networks (PBNs): sites you or a vendor control that exist to link out. They look cheap until they do not.</li>
<li>Automated profile and comment links.</li>
<li>Guest posts on sites that publish anything that pays, with no audience overlap.</li>
<li>Ignoring the landing page. A great citation to a thin doorway page is still a thin doorway page.</li>
</ul>

<h2>Practical checklist</h2>
<ul>
<li>Target URL is the best page for that topic, not a random homepage.</li>
<li>You can explain why the host’s reader would care.</li>
<li>Referring domain is new or the repeat is intentional.</li>
<li>Link sits in the article body.</li>
<li>Attribute matches the commercial reality (<code>sponsored</code> / <code>nofollow</code> when it is paid).</li>
<li>Anchor would not embarrass you if a journalist quoted it.</li>
<li>You have a live-URL check on the calendar.</li>
</ul>

<h2>Frequently asked questions</h2>
<h3>How many backlinks do I need?</h3>
<p>There is no number that works across markets. A local plumber and a competitive SaaS category do not share a quota. Track whether relevant pages start ranking and whether referral visits convert, not a running total.</p>
<h3>Do nofollow links help?</h3>
<p>Google has said <code>nofollow</code>, <code>sponsored</code>, and <code>ugc</code> are hints, not hard exclusions. They still send people. They still show up in brand research. Do not treat them as worthless, and do not treat them as a loophole either.</p>
<h3>Is guest posting still useful?</h3>
<p>On sites with real readers and editorial standards, yes. On networks that exist to sell the same article to 200 blogs, no. The host quality is the tactic.</p>
<h3>Are marketplace links “bad”?</h3>
<p>A marketplace is a way to find publishers and handle orders. Quality still depends on the site, the article, and how the link is labelled. It is not automatically spam, and it is not automatically an editorial endorsement.</p>
<h3>Should I disavow old bad links?</h3>
<p>Only after you understand what you are looking at. Most sites pick up junk over time. Google’s own guidance has long warned against panic-disavowing normal links. Use Search Console, look for patterns you control, and get help if a known spam network is pointing at you at scale.</p>
<h3>Can I just exchange links with partners?</h3>
<p>Occasional genuine partnerships happen. Industrial “you link to me, I link to you” pages built for rankings are listed as link spam in Google’s policies. If the only reason the page exists is the swap, skip it.</p>

<h2>Sources and further reading</h2>
<ul>
<li><a href="https://developers.google.com/search/docs/essentials/spam-policies">Google Search Central — Spam policies for Google web search</a> (link spam, paid links, qualifying attributes)</li>
<li><a href="https://developers.google.com/search/blog/2019/09/evolving-nofollow-new-ways-to-identify">Google Search Central Blog — Evolving nofollow</a> (<code>sponsored</code>, <code>ugc</code>, hints)</li>
<li><a href="https://developers.google.com/search/blog/2021/07/link-tagging-and-link-spam-update">Google Search Central Blog — Qualifying links and link spam</a></li>
<li><a href="https://developers.google.com/search/docs/crawling-indexing/links-crawlable">Google Search Central — Link best practices (crawlable links)</a></li>
<li><a href="https://developers.google.com/search/docs/fundamentals/creating-helpful-content">Google Search Central — Creating helpful, reliable, people-first content</a></li>
</ul>
HTML;
    }
}
