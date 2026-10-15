<?php

namespace App\Support;

/**
 * English publisher post: approval pipeline and common rejection causes.
 */
class WhySitesGetRejectedBlogPost
{
    public const SLUG = 'why-sites-get-rejected-and-how-to-get-approved';

    public const FEATURED_ASSET = 'assets/img/blog/supply-approve-featured.jpg';

    public const FEATURED_STORAGE = 'blogs/featured/supply-approve-featured.jpg';

    public const IMAGE_MYSITES = 'supply-approve-inline.jpg';

    public const IMAGE_BECOME = 'supply-approve-become.jpg';

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
            'title' => 'Why Sites Get Rejected (and How to Get Approved)',
            'slug' => self::SLUG,
            'primary_locale' => 'en',
            'excerpt' => 'What happens between Add Website and the advertiser catalog: verification, details, admin review, and the fixes that get a listing approved instead of removed.',
            'content' => self::contentHtml(),
            'author' => 'M. Arslan',
            'tags' => [
                'Publisher tips',
                'Site approval',
                'Verification',
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
                'question' => 'When does my site appear in the catalog?',
                'answer' => 'After ownership verification and admin approval. Sites stuck in awaiting details or failed verification stay out of advertiser inventory.',
            ],
            [
                'question' => 'What is the verification file for?',
                'answer' => 'It proves you control the domain. Upload the file to the path shown in My Sites, then run Check verification. Too many failed checks will rate-limit you for a few minutes.',
            ],
            [
                'question' => 'Can I resubmit after a rejection?',
                'answer' => 'Often yes — fix the reason (metrics, niches, price, ownership proof, or content quality), complete required details, and move the site back to ready for review. If staff removed the listing, follow the notification reason before adding it again.',
            ],
            [
                'question' => 'Someone else already listed my domain?',
                'answer' => 'Duplicate domains are blocked. Use the on-screen claim guidance rather than creating a second account listing for the same URL.',
            ],
        ];
    }

    public static function contentHtml(): string
    {
        $publisherGuide = '/blog/publisher-guide-add-sites-complete-orders-withdraw';
        $priceGuide = '/blog/how-to-price-your-site-and-sensitive-niches';
        $become = '/become-a-publisher';
        $howItWorks = '/how-it-works';
        $register = '/register';
        $imgSites = BlogInlineImages::publicUrl(self::IMAGE_MYSITES);
        $imgBecome = BlogInlineImages::publicUrl(self::IMAGE_BECOME);

        return <<<HTML
<p>Advertisers only see sites that cleared verification and admin review. Everything before that is backstage: you add the URL, finish listing details, prove control of the domain, then wait for staff to approve or reject with a reason.</p>
<p>If a listing never appears in the catalog, it is almost never “the system forgot you.” It is an incomplete profile, a failed ownership check, a duplicate domain, or a quality flag. Here is the pipeline and how to clear it.</p>

<figure>
<img src="{$imgBecome}" alt="Become a publisher page for SEOLinkBuildings" loading="lazy" width="1200" height="675">
<figcaption>Become a publisher — start here if you are new to selling placements on SEOLinkBuildings.</figcaption>
</figure>

<h2>The path from draft to catalog</h2>
<ol>
<li><strong>Add the website</strong> in My Sites (single add or bulk).</li>
<li><strong>Complete required details</strong> — niches, country, language, pricing, link terms. Bulk onboarding may leave you in <em>awaiting details</em> until those fields are filled.</li>
<li><strong>Verify ownership</strong> with the verification file: download or copy the code, place it on your domain, then click Check verification.</li>
<li><strong>Ready for review</strong> — staff review metrics, niches, and listing quality.</li>
<li><strong>Approved / verified</strong> — the site can enter advertiser catalog inventory when activated under platform rules.</li>
</ol>
<p>The step-by-step UI walkthrough lives in the <a href="{$publisherGuide}">publisher guide</a>. Marketplace roles are summarised on <a href="{$howItWorks}">how it works</a>.</p>

<figure>
<img src="{$imgSites}" alt="Publisher My Sites list with onboarding and verification status" loading="lazy" width="1200" height="675">
<figcaption>My Sites — watch onboarding status and finish details before requesting verification.</figcaption>
</figure>

<h2>Common reasons sites get rejected or stuck</h2>
<ul>
<li><strong>Incomplete details</strong> — missing niches, country, language, or prices keep the site out of the review queue.</li>
<li><strong>Failed verification</strong> — wrong path, cached 404, or checking before the file is live. Fix the file, wait a minute, check again.</li>
<li><strong>Duplicate domain</strong> — another account already claimed the URL. Follow claim guidance; do not invent a second listing.</li>
<li><strong>Unrealistic metrics or price</strong> — traffic or authority claims that do not match the site, or prices that look like spam inventory. See <a href="{$priceGuide}">how to price your site</a>.</li>
<li><strong>Thin or off-topic content</strong> — brand-new parked domains, doorway pages, or niches that do not match the live site.</li>
<li><strong>Policy / quality flags</strong> — staff may reject or remove a site with a written reason. Read the notification; guessing wastes another cycle.</li>
</ul>

<h2>How to get approved faster</h2>
<ul>
<li>Fill every required field before you hit verification. Partial forms create round-trips.</li>
<li>Use accurate niches and language — buyers filter on those labels.</li>
<li>Price like a buyer would; fantasy numbers slow review.</li>
<li>Keep the verification file reachable over HTTPS on the exact path shown.</li>
<li>If you manage many domains, finish bulk “awaiting details” rows before asking for review on the whole batch.</li>
</ul>

<h2>If you were rejected</h2>
<p>Open the status reason in the notification or My Sites. Fix the concrete issue (ownership, metrics, niches, price, or content), then move the site back through details → verification → ready for review. Do not resubmit the same broken profile hoping for a different outcome.</p>
<p>New to the marketplace? Read <a href="{$become}">become a publisher</a>, then <a href="{$register}">register</a> and treat the first listing as a quality sample — one clean approval beats five rejected drafts.</p>

<h2>Frequently asked questions</h2>
<h3>When does my site appear in the catalog?</h3>
<p>After ownership verification and admin approval. Sites stuck in awaiting details or failed verification stay out of advertiser inventory.</p>
<h3>What is the verification file for?</h3>
<p>It proves you control the domain. Upload the file to the path shown in My Sites, then run Check verification. Too many failed checks will rate-limit you for a few minutes.</p>
<h3>Can I resubmit after a rejection?</h3>
<p>Often yes — fix the reason (metrics, niches, price, ownership proof, or content quality), complete required details, and move the site back to ready for review. If staff removed the listing, follow the notification reason before adding it again.</p>
<h3>Someone else already listed my domain?</h3>
<p>Duplicate domains are blocked. Use the on-screen claim guidance rather than creating a second account listing for the same URL.</p>
HTML;
    }
}
