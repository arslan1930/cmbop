<?php

namespace App\Support;

/**
 * English pillar: guest posting as a publishing process, not a link vending machine.
 */
class GuestPostingGuideBlogPost
{
    public const SLUG = 'guest-posting-guide';

    public const FEATURED_ASSET = 'assets/img/blog/guest-posting-guide-featured.jpg';

    public const FEATURED_STORAGE = 'blogs/featured/guest-posting-guide-featured.jpg';

    public const IMAGE_WORKFLOW = 'guest-posting-guide-workflow.jpg';

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
            'title' => 'Guest Posting: A Complete Guide to Guest Blogging for SEO',
            'slug' => self::SLUG,
            'primary_locale' => 'en',
            'excerpt' => 'How guest posting actually works: find relevant hosts, pitch editors, write for their readers, and skip sites that exist only to sell a link.',
            'content' => self::contentHtml(),
            'author' => 'M. Arslan',
            'tags' => ['Guest posting', 'Guest blogging', 'Outreach', 'Backlinks', 'SEO'],
            'status' => 'published',
            'featured_image' => self::FEATURED_STORAGE,
            'meta_title' => 'Guest Posting Guide: Pitch, Write and Publish for SEO',
            'meta_description' => 'A practical guest posting guide: find relevant publishers, pitch and write for their readers, handle anchors, and avoid cheap guest-post networks.',
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
        return class_exists(GuestPostingGuideI18n::class)
            ? GuestPostingGuideI18n::all()
            : [];
    }

    public static function contentHtml(): string
    {
        $backlinks = '/blog/how-to-get-backlinks';
        $sponsored = '/blog/sponsored-post-guide';
        $linkGuide = '/blog/link-building-guide';
        $chooseSite = '/blog/how-to-choose-a-publisher-site-dr-da-traffic-niche';
        $buyGuide = '/blog/how-to-buy-guest-posts-on-seolinkbuildings-advertiser-guide';
        $brief = '/blog/guest-post-brief-anchors-urls-images-sensitive-topics';
        $dofollow = '/blog/dofollow-nofollow-and-anchor-text-for-marketplace-links';
        $outreach = '/blog/marketplace-vs-cold-outreach-vs-digital-pr';
        $europe = '/blog/buy-guest-posts-in-europe-how-to-choose-publisher-sites';
        $ukus = '/blog/guest-posting-in-the-uk-and-us-what-to-buy-and-what-to-skip';
        $live = '/blog/what-to-check-after-the-live-link-indexation-attributes-rankings';
        $catalog = '/marketplace';
        $how = '/how-it-works';
        $imgWorkflow = BlogInlineImages::publicUrl(self::IMAGE_WORKFLOW);

        return <<<HTML
<p>Guest posting is contributing an article to a website you do not own, usually with a byline and, when the host allows it, a link back to a page you care about.</p>
<p>That definition is simple. The work is not. Most failed campaigns fail on host selection, not on writing talent. A careful article on a site nobody reads is still a wasted week. A thin pitch to a good editor is a wasted email.</p>
<p>If you need the acquisition overview first, start with <a href="{$backlinks}">how to get backlinks</a>. For paid native placements, use the <a href="{$sponsored}">sponsored posts guide</a>. This page stays on the guest-post workflow: find hosts, evaluate them, pitch, write, and check the live URL.</p>

<h2>What is guest posting?</h2>
<p>A guest post (also called a guest blog or contributed article) is content published on a third-party site under an arrangement: you supply the piece, they publish it to their audience. The host keeps the URL, the traffic, and the editorial right to edit or reject. You keep the byline and any agreed links or bio line.</p>
<p>It is not the same as an editor independently citing you. It is also not automatically a paid advertorial. Some hosts accept unpaid contributions because they want the article. Some charge a placement fee. Some mix both. Label the commercial reality honestly — details in the <a href="{$sponsored}">sponsored posts guide</a>.</p>

<h2>How guest posting works</h2>
<p>The usual sequence looks like this:</p>
<ol>
<li>You pick a target URL on your own site (a guide, a product page, a research post).</li>
<li>You shortlist hosts whose readers overlap with that URL.</li>
<li>You pitch a specific idea, or you apply through a “write for us” page, or you order a listing on a marketplace with written rules.</li>
<li>The host accepts, sends constraints (length, links, tone, images, disclosure).</li>
<li>You write or supply the article. They edit. It goes live.</li>
<li>You record the live URL, the link attribute, the anchor, and a recheck date.</li>
</ol>
<p>Skip any step and you get the classic mess: an article that ranks for nothing, a link pointing at the homepage, or a host that quietly noindexes the post two weeks later.</p>

<h2>Benefits and limitations</h2>
<p>Done on sites with a real audience, guest posting can:</p>
<ul>
<li>Put your name in front of people who already read about the topic</li>
<li>Create a crawlable citation to a specific URL</li>
<li>Give you a public writing sample in someone else’s archive</li>
<li>Start a relationship with an editor you can pitch again</li>
</ul>
<p>It will not:</p>
<ul>
<li>Guarantee a ranking change (content, intent, and competitors still decide that)</li>
<li>Fix a weak landing page</li>
<li>Substitute for original research or a product people want to cite</li>
<li>Stay safe if you industrialise it across low-quality networks with keyword-stuffed anchors</li>
</ul>
<p>Google’s spam policies call out large-scale guest posting with keyword-rich anchors as a link-spam pattern. The tactic is not banned as a format. The pattern of using it to manipulate rankings is. Treat the host’s reader as the customer of the article. The link is a by-product, not the product.</p>

<h2>Finding relevant websites</h2>
<p>Relevance beats a vanity score. Three practical sources:</p>
<ul>
<li><strong>Search like an editor.</strong> Queries such as <em>write for us [topic]</em>, <em>contribute [topic] blog</em>, and the topic plus <em>guest post guidelines</em> still surface hosts that want contributors. Read the guidelines before you email.</li>
<li><strong>Competitor referring domains.</strong> Export links to a page that already ranks for your query. Keep hosts that publish real articles in your topic. Drop directories, comment threads, and sites that link to twenty near-identical “partners.”</li>
<li><strong>Catalogs with filters.</strong> A marketplace such as <a href="{$catalog}">SEOLinkBuildings</a> is useful when you want comparable listings (niche, language, turnaround, link rules) instead of a spreadsheet of cold emails. It is a discovery layer, not a quality stamp. How orders work is in <a href="{$how}">how it works</a> and the <a href="{$buyGuide}">advertiser buy guide</a>.</li>
</ul>
<p>Country and language matter as much as topic. A French HR blog is a poor host for an English-only US payroll landing page, even if the “authority” number looks fine. For regional buying notes see <a href="{$europe}">guest posts in Europe</a> and <a href="{$ukus}">UK and US guest posting</a>.</p>

<h2>Evaluating publishers</h2>
<p>Open the site as a stranger, not as a metrics dashboard. If you would not send a colleague the homepage, do not pitch it.</p>

<h3>Topical relevance</h3>
<p>The linking <em>page</em> should sit next to your URL in a reasonable mind map. Adjacent is fine: a privacy-law explainer citing an encryption product can be a better fit than another “top 50 SaaS tools” roundup. Site-level niche helps. Page-level context decides.</p>
<p>Read three recent posts. If two of them could be swapped onto a casino blog without anyone noticing, the topical claim is cosmetic.</p>

<h3>Audience relevance</h3>
<p>Ask who actually reads this. Comments, newsletter CTAs, named authors, conference mentions, and outbound links to specialist sources are clues. A site that only talks to other SEOs about “DA” is a poor host for a dental clinic, even if both pages contain the word “local.”</p>

<h3>Traffic</h3>
<p>Organic traffic to the host is a sanity check, not a contract. Third-party traffic estimates disagree with each other and with reality. Use them to rank a shortlist, then look at the page: is it indexed, does it rank for anything in the niche, would a human land there from search?</p>
<p>A smaller specialist blog with real readers can outperform a larger generalist site whose “traffic” is concentrated on unrelated categories.</p>

<h3>Domain metrics and their limitations</h3>
<p>Domain Rating, Domain Authority, and similar scores are vendor estimates from a crawl. They are filters. Two sites with a similar score can differ completely in outbound-link hygiene, topical focus, and whether anyone visits. The longer checklist lives in <a href="{$chooseSite}">how to choose a publisher site</a>.</p>
<p>If a vendor sells “DA50+ guest posts” as a package with no URL list, you are buying a number, not a publisher.</p>

<h2>Finding contact information</h2>
<p>Use the channel the host already published:</p>
<ul>
<li>A “write for us,” “contribute,” or editorial@ address on the site</li>
<li>The author or editor named on recent posts (LinkedIn or the byline email, if they show one)</li>
<li>The contact form, with a subject line an editor can scan</li>
<li>The listing’s order thread, if you are buying through a marketplace</li>
</ul>
<p>Do not scrape personal inboxes or guess ten role accounts. Editors recognise spray. One accurate address beats a CC list.</p>

<h2>Outreach and pitching article ideas</h2>
<p>A pitch is a short argument that this idea belongs on <em>their</em> site. It is not a résumé and it is not a link request wearing a compliment.</p>
<p>Include:</p>
<ul>
<li>Why this site, with one specific post or section you actually read</li>
<li>One idea, not five</li>
<li>A working title and a two-sentence outline</li>
<li>Who you are in one line (role, not a keyword list)</li>
<li>Whether the piece would be exclusive</li>
</ul>
<p>Leave out: “loved your blog,” attachment-heavy decks, and “we can also pay for a dofollow.” If the placement is commercial, say so plainly and follow their advertising process. Compare channels in <a href="{$outreach}">marketplace vs cold outreach vs digital PR</a>.</p>

<h3>Example outreach process</h3>
<p>Suppose you run a B2B invoicing product and you want a citation to a guide on VAT for freelancers. You find a European freelance-finance blog that already ranked a piece on mileage logs. You do not pitch “10 invoicing tools.” You pitch “A freelancer’s checklist for VAT when they first hire a contractor in another EU country,” with a note that you will not name your product in the body, only a resource link in the section on record-keeping. That is a pitch an editor can accept or reject in thirty seconds.</p>
<p>Follow up once after a week. Then stop. Editors who want it will answer. A third nudge does not convert “no” into “yes.”</p>

<h2>Writing a guest post</h2>
<p>Write for the host’s reader. If the piece still works after you strip your URL, you are close. If every section leans toward a product screenshot, it is an advertorial — and should be labelled that way.</p>
<p>Practical rules that survive most editorial desks:</p>
<ul>
<li>Match their typical length and heading style. Do not paste a 4,000-word manifesto onto a site that publishes 900-word explainers.</li>
<li>Open with the problem, not a dictionary definition of the topic.</li>
<li>Cite primary sources where you make a factual claim. Do not invent statistics.</li>
<li>One primary URL you care about. Extra links only if they help the reader (documentation, a dataset, a regulator page).</li>
<li>A byline bio that sounds like a person. Three keyword anchors in the bio is a rejection waiting to happen.</li>
</ul>
<p>If you are supplying the article through a marketplace, put the constraints in the brief before anyone writes. The operational detail is in <a href="{$brief}">guest post briefs: anchors, URLs, images, sensitive topics</a>.</p>

<h2>Editorial requirements</h2>
<p>Hosts usually specify some mix of: word count, original unpublished copy, heading structure, image rights, a cap on outbound links, banned topics, and a disclosure line. Read it. Then follow it. “We always use exact-match anchors” is not a requirement you get to override in the comments.</p>
<p>If the host asks you to add six commercial links to different clients in one article, walk away. That page is inventory, not a publication.</p>

<h2>Anchor text and link attributes</h2>
<p>Anchor text is the clickable phrase. Brand name, naked URL, and a descriptive phrase (“VAT checklist for freelancers”) are the mix that looks like a person wrote the sentence. Repeating the same exact-match commercial phrase across hosts is how campaigns start looking like a network.</p>
<p>Link attributes tell crawlers how to treat the href:</p>
<ul>
<li>A default link (often called “dofollow” in marketing shorthand) carries no rel qualifier</li>
<li><code>rel="nofollow"</code> is a hint that the host does not want to vouch for the target</li>
<li><code>rel="sponsored"</code> is the qualifier Google documents for advertising and paid placements</li>
<li><code>rel="ugc"</code> is for user-generated content such as comments</li>
</ul>
<p>Since 2019 Google has treated those qualifiers as hints, not hard exclusions. They still matter as honest labelling. If money changed hands for the placement, do not pretend it is an unpaid editorial citation. Longer notes: <a href="{$dofollow}">dofollow, nofollow, and anchor text</a>.</p>

<h2>Sponsored vs editorial guest posts</h2>
<p>Three different things get called “guest posts”:</p>
<table>
<thead>
<tr><th>Arrangement</th><th>Who chose the topic</th><th>Money</th><th>How to treat the link</th></tr>
</thead>
<tbody>
<tr><td>Editorial citation</td><td>Their writer</td><td>None for the link</td><td>Normal editorial link if they cite you</td></tr>
<tr><td>Unpaid guest article</td><td>You pitched, they accepted</td><td>None, or only your time</td><td>Follow their contributor rules</td></tr>
<tr><td>Paid / sponsored placement</td><td>Commercial brief</td><td>Fee, product, or other value</td><td>Qualify as sponsored or nofollow; disclose</td></tr>
</tbody>
</table>
<p>Mixing the three in a report is how teams convince themselves they “earned” twenty editorial links that were actually purchased. Keep the labels. The <a href="{$sponsored}">sponsored posts guide</a> covers disclosure and <code>rel="sponsored"</code> in more depth.</p>

<h2>Warning signs of low-quality guest-post websites</h2>
<p>You do not need a special tool. You need twenty minutes and a browser.</p>
<ul>
<li>The same three outbound “partners” in every article, often in the first paragraph</li>
<li>No named authors, no about page, no physical or company identity</li>
<li>Categories that span crypto, casinos, CBD, and kitchen gadgets with identical writing</li>
<li>“Write for us” that only talks about dofollow slots and DA numbers</li>
<li>A copyright year that never moves, or a burst of posts dated the same day</li>
<li>Thin English that reads like a translation nobody edited</li>
<li>The site’s own articles are not indexed, or the homepage is a parked theme</li>
</ul>
<p>Private blog networks (PBNs) — sites controlled to point links at a client — belong in the same skip pile. If the vendor will not show you the URL until after you pay, you already have your answer.</p>

<h2>Common outreach mistakes</h2>
<ul>
<li>Pitching a product roundup to a site that just published one</li>
<li>Sending the same paragraph to 80 editors</li>
<li>Asking for a homepage link from an article about a narrow subtopic</li>
<li>Exact-match anchors in the pitch itself (“please use ‘best ERP software UK’”)</li>
<li>Ignoring the live-URL check. Publication is not the end. See <a href="{$live}">what to check after the live link</a>.</li>
</ul>

<h2>Guest-post campaign workflow</h2>
<p>Pick one landing page. Map ten hosts you would be proud to show a client. Split them: some unpaid pitches, some marketplace listings with clear rules, none from a bulk “DA pack.”</p>
<figure>
<img src="{$imgWorkflow}" alt="Guest-post workflow: find sites, evaluate, pitch, write, publish, recheck the live URL" loading="lazy" width="1200" height="675">
<figcaption>Do not scale the campaign until the first live URLs are indexed and still match the brief.</figcaption>
</figure>
<ol>
<li>Fix the landing page so a referred reader gets the next step without hunting.</li>
<li>Write two pitch angles that would still make sense if your URL were removed.</li>
<li>Ship the first three placements. Recheck attributes and indexation before you scale.</li>
<li>Cap exact-match anchors. Mix brand and descriptive phrases.</li>
<li>Stop if the hosts are not indexed or send no readers. Change the set. Do not add volume to a broken pattern.</li>
</ol>
<p>That is the same discipline as a wider <a href="{$linkGuide}">link building</a> campaign, applied to one tactic.</p>

<h2>Checklist for evaluating a publisher</h2>
<ul>
<li>I can name the audience in one sentence.</li>
<li>Recent posts are original and on-topic, not spun or scraped.</li>
<li>Outbound links on recent posts do not look like a farm.</li>
<li>The prospective URL is indexable (not noindex, not blocked).</li>
<li>I know the link attribute they will use.</li>
<li>The commercial terms (if any) are written down.</li>
<li>The landing page deserves the click.</li>
<li>I have a calendar reminder to reopen the live URL.</li>
</ul>

<h2>Frequently asked questions</h2>
<h3>Is guest posting still useful for SEO?</h3>
<p>On hosts with real readers and editorial standards, it can be a reasonable way to earn a citation and an audience. On networks that exist to sell the same article shape to hundreds of blogs, it is a liability. Host quality is the tactic.</p>
<h3>How many links should a guest post contain?</h3>
<p>Follow the host. Many editors cap outbound commercial links (often a small number in the body, plus a bio). Stuffing extra hrefs is a common reason for rejection or a forced <code>nofollow</code>.</p>
<h3>Should I pay for a guest post?</h3>
<p>Paying does not make the article good or bad. It does change how the link should be labelled. If you pay, treat it as sponsored publishing and read the <a href="{$sponsored}">sponsored posts guide</a>. Do not buy “editorial dofollow” packages that exist to dodge that distinction.</p>
<h3>What anchor text should I use?</h3>
<p>Write a sentence a human would write. Brand, URL, and a short descriptive phrase are enough. Exact-match commercial anchors on every placement are a pattern Google has warned about for years in its spam policies.</p>
<h3>How long should a guest post be?</h3>
<p>As long as the host’s typical article, not as long as a keyword tool suggests. A 2,800-word piece on a site that publishes 700-word notes looks like it was written for a crawler.</p>
<h3>Can I republish the same article on several sites?</h3>
<p>Usually no. Editors expect original copy. Republishing also creates duplicate pages that compete with each other. Write a new piece, or negotiate syndication explicitly.</p>
<h3>Do I need a marketplace to guest post?</h3>
<p>No. Cold outreach and contributor pages still work. A catalog is faster when you want comparable publisher rules in one place. Quality still depends on the site you pick.</p>

<h2>Sources and further reading</h2>
<ul>
<li><a href="https://developers.google.com/search/docs/essentials/spam-policies">Google Search Central — Spam policies for Google web search</a> (link spam, guest posting patterns, paid links)</li>
<li><a href="https://developers.google.com/search/blog/2019/09/evolving-nofollow-new-ways-to-identify">Google Search Central Blog — Evolving nofollow</a> (<code>sponsored</code>, <code>ugc</code>, hints)</li>
<li><a href="https://developers.google.com/search/blog/2021/07/link-tagging-and-link-spam-update">Google Search Central Blog — Qualifying links and link spam</a></li>
<li><a href="https://developers.google.com/search/docs/crawling-indexing/links-crawlable">Google Search Central — Link best practices (crawlable links)</a></li>
<li><a href="https://developers.google.com/search/docs/fundamentals/creating-helpful-content">Google Search Central — Creating helpful, reliable, people-first content</a></li>
</ul>
HTML;
    }
}
