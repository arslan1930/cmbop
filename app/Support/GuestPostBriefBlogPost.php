<?php

namespace App\Support;

/**
 * English advertiser guide for guest-post briefs and content prep.
 */
class GuestPostBriefBlogPost
{
    public const SLUG = 'guest-post-brief-anchors-urls-images-sensitive-topics';

    public const FEATURED_ASSET = 'assets/img/blog/trust-guest-brief-featured.jpg';

    public const FEATURED_STORAGE = 'blogs/featured/trust-guest-brief-featured.jpg';

    public const IMAGE_LIBRARY = 'trust-guest-brief-inline.jpg';

    public const IMAGE_CONTENT = 'howto-adv-content-library.jpg';

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
            'title' => 'Guest Post Brief: Anchors, URLs, Images & Sensitive Topics',
            'slug' => self::SLUG,
            'primary_locale' => 'en',
            'excerpt' => 'How to prepare articles that publishers can place without a messy revision loop — anchors, target URLs, images, and sensitive-topic surcharges.',
            'content' => self::contentHtml(),
            'author' => 'M. Arslan',
            'tags' => [
                'Content library',
                'Anchors',
                'Guest posts',
                'Sensitive topics',
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
                'question' => 'Can I check out without an approved article?',
                'answer' => 'You can browse and fill a cart first, but checkout expects an approved article for each site. Upload early so approval is not the bottleneck.',
            ],
            [
                'question' => 'How many dofollow links should I put in one article?',
                'answer' => 'Follow the listing. Many publishers cap outbound dofollow links (often around three). Stuffing more links is a common reason for rejection or forced edits.',
            ],
            [
                'question' => 'Why is crypto or CBD more expensive?',
                'answer' => 'Those topics carry extra risk for publishers. Sensitive-topic add-ons are priced on the listing and passed through — pick them on purpose before you pay.',
            ],
            [
                'question' => 'Do I need a feature image?',
                'answer' => 'If the upload flow asks for one, include it. A clean, relevant image reduces “please resubmit” messages and helps the piece look finished on the publisher site.',
            ],
        ];
    }

    public static function contentHtml(): string
    {
        $buyGuide = '/blog/how-to-buy-guest-posts-on-seolinkbuildings-advertiser-guide';
        $chooseSite = '/blog/how-to-choose-a-publisher-site-dr-da-traffic-niche';
        $liveCheck = '/blog/what-to-check-after-the-live-link-indexation-attributes-rankings';
        $register = '/register';
        $imgLibrary = BlogInlineImages::publicUrl(self::IMAGE_LIBRARY);
        $imgContent = BlogInlineImages::publicUrl(self::IMAGE_CONTENT);

        return <<<HTML
<p>Bad briefs create slow orders. The publisher is not psychic. If your article file, anchors, and target URLs are fuzzy, you will spend the week in chat instead of collecting live links.</p>
<p>This is the brief we want advertisers to use before they upload to Content Library. Pair it with the <a href="{$buyGuide}">buy guide</a> for the product steps, and <a href="{$chooseSite}">how to choose a site</a> before you attach the article to a cart line.</p>

<h2>Write the brief before you write the article</h2>
<p>One page is enough:</p>
<ul>
<li><strong>Target URL</strong> — the exact page you want the link to hit (not the homepage by default)</li>
<li><strong>Primary anchor</strong> — natural language, not “click here,” not a stuffed keyword every time</li>
<li><strong>Secondary links</strong> — only if the listing allows them; note dofollow vs nofollow</li>
<li><strong>Audience &amp; angle</strong> — who reads this, what problem it solves</li>
<li><strong>Must-avoid claims</strong> — medical guarantees, income promises, trademark misuse</li>
<li><strong>Sensitive topic?</strong> — crypto, CBD, forex, adult-adjacent, etc. Say it out loud</li>
</ul>
<p>If two people on your team would describe the link differently, the brief is not done.</p>

<figure>
<img src="{$imgLibrary}" alt="Content Library on SEOLinkBuildings for uploading guest-post articles" loading="lazy" width="1200" height="675">
<figcaption>Content Library — upload finished articles with clear names, then wait for approval.</figcaption>
</figure>

<h2>Anchors that survive review</h2>
<p>Mixed anchors look like real editorial. Exact-match every time looks like a campaign spreadsheet.</p>
<p>A sane mix for a small batch:</p>
<ul>
<li>1–2 branded or URL anchors</li>
<li>1–2 partial-match or topical phrases</li>
<li>occasional naked URL if it fits the sentence</li>
</ul>
<p>Match the listing’s link rules. If the publisher allows a max of three dofollow links, do not send five and hope. Also keep placeholders out of the final file — “INSERT LINK,” “TK,” and lorem blocks get articles rejected.</p>

<h2>URLs: one destination per placement</h2>
<p>Each cart site needs its own approved article. Do not reuse one doc across five unrelated niches with a find-and-replace on the brand name. Publishers notice. Readers notice. Moderators notice.</p>
<p>Check that every target URL:</p>
<ul>
<li>loads over HTTPS</li>
<li>is indexable (not blocked by a noindex experiment you forgot about)</li>
<li>matches the language of the article</li>
</ul>

<figure>
<img src="{$imgContent}" alt="Uploading and managing articles in the advertiser content library" loading="lazy" width="1200" height="675">
<figcaption>Upload early — approval time is part of the campaign timeline, not an afterthought.</figcaption>
</figure>

<h2>Images and file hygiene</h2>
<p>Use a .docx the upload wizard accepts. Name files like <code>de-home-insurance-site62.docx</code>, not <code>final_FINAL_v7.docx</code>.</p>
<p>For images:</p>
<ul>
<li>own them or have rights to use them (the platform may ask you to confirm that)</li>
<li>keep filenames boring and descriptive</li>
<li>avoid huge 10MB hero shots that slow the publisher CMS</li>
</ul>
<p>Feature images should support the article, not carry a watermark from another marketplace.</p>

<h2>Sensitive topics: pay the add-on on purpose</h2>
<p>Crypto, CBD, forex, and similar niches often cost more. That surcharge is shown on the listing when you select the topic. It is not a surprise fee invented at invoice time.</p>
<p>If your article is clearly in a sensitive niche, select the matching add-on. Hiding the topic in soft language and hoping the publisher “won’t notice” is how orders get rejected mid-flight.</p>

<h2>Quality bar that avoids the revision loop</h2>
<ul>
<li>Roughly 500+ solid words unless the listing says otherwise — thin posts look like paid links because they are</li>
<li>Real structure: intro, sections, conclusion; not one wall of text</li>
<li>Limited outbound links; stay inside the publisher’s cap</li>
<li>No spammy title casing or keyword stuffing</li>
</ul>
<p>When the live URL arrives, run the <a href="{$liveCheck}">live-link checklist</a> so a clean brief is not wasted on a messy placement.</p>

<h2>Minimum brief template you can copy</h2>
<p><strong>Campaign:</strong> Q3 DE insurance<br>
<strong>Target URL:</strong> https://example.com/de/home-insurance<br>
<strong>Primary anchor:</strong> Hausratversicherung vergleichen<br>
<strong>Extra links:</strong> none<br>
<strong>Language:</strong> German<br>
<strong>Sensitive topic:</strong> no<br>
<strong>Notes for publisher:</strong> keep dofollow on the primary link; no homepage redirect</p>
<p>Paste that above the article draft for your team. Then upload. Then buy.</p>
<p><a href="{$register}">Create an advertiser account</a> if you still need access, and keep Content Library stocked before you chase a full cart.</p>

<h2>Frequently asked questions</h2>
<h3>Can I check out without an approved article?</h3>
<p>You can browse and fill a cart first, but checkout expects an approved article for each site. Upload early so approval is not the bottleneck.</p>
<h3>How many dofollow links should I put in one article?</h3>
<p>Follow the listing. Many publishers cap outbound dofollow links (often around three). Stuffing more links is a common reason for rejection or forced edits.</p>
<h3>Why is crypto or CBD more expensive?</h3>
<p>Those topics carry extra risk for publishers. Sensitive-topic add-ons are priced on the listing and passed through — pick them on purpose before you pay.</p>
<h3>Do I need a feature image?</h3>
<p>If the upload flow asks for one, include it. A clean, relevant image reduces “please resubmit” messages and helps the piece look finished on the publisher site.</p>
HTML;
    }
}
