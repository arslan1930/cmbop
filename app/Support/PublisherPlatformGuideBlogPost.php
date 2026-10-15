<?php

namespace App\Support;

/**
 * English publisher how-to guide for SEOLinkBuildings.
 * One body for /blog, /de/blog, /fr/blog, /nl/blog (no per-locale fields).
 */
class PublisherPlatformGuideBlogPost
{
    public const SLUG = 'publisher-guide-add-sites-complete-orders-withdraw';

    public const FEATURED_ASSET = 'assets/img/blog/howto-publisher-featured.jpg';

    public const FEATURED_STORAGE = 'blogs/featured/howto-publisher-featured.jpg';

    public const IMAGE_MYSITES = 'howto-pub-mysites.jpg';

    public const IMAGE_TASKS = 'howto-pub-tasks.jpg';

    public const IMAGE_BALANCE = 'howto-pub-balance.jpg';

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
            'title' => 'Publisher Guide: Add Sites, Complete Orders, and Withdraw Earnings',
            'slug' => self::SLUG,
            'primary_locale' => 'en',
            'excerpt' => 'How publishers list websites on SEOLinkBuildings, fulfil placement tasks, submit live URLs, and withdraw available balance.',
            'content' => self::contentHtml(),
            'author' => 'M. Arslan',
            'tags' => [
                'Publisher guide',
                'My Sites',
                'Tasks',
                'Withdraw',
                'Guest posting',
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
                'question' => 'When do my sites appear in the advertiser catalog?',
                'answer' => 'After you submit them and they pass verification and admin review. Incomplete profiles, duplicate domains, or failed verification keep a site out of inventory.',
            ],
            [
                'question' => 'Can I reject a task?',
                'answer' => 'Yes, when the brief or content does not fit your editorial rules. Reject promptly with a clear reason so the advertiser can reassign. Silence creates disputes.',
            ],
            [
                'question' => 'When can I withdraw earnings?',
                'answer' => 'When balance is available under the platform’s release rules for completed work. Use Withdraw, choose a saved payout method, and submit only amounts you can support with the listed details.',
            ],
            [
                'question' => 'I also buy placements. Do I need two emails?',
                'answer' => 'Usually not. One account can hold Advertiser and Publisher roles. Switch roles from the top bar instead of maintaining duplicate logins.',
            ],
        ];
    }

    public static function contentHtml(): string
    {
        $register = '/register';
        $howItWorks = '/how-it-works';
        $pricing = '/pricing';
        $advertiserGuide = '/blog/how-to-buy-guest-posts-on-seolinkbuildings-advertiser-guide';
        $liveCheck = '/blog/what-to-check-after-the-live-link-indexation-attributes-rankings';
        $faq = '/faq';
        $imgSites = BlogInlineImages::publicUrl(self::IMAGE_MYSITES);
        $imgTasks = BlogInlineImages::publicUrl(self::IMAGE_TASKS);
        $imgBalance = BlogInlineImages::publicUrl(self::IMAGE_BALANCE);

        return <<<HTML
<p>Publishers on SEOLinkBuildings sell placements on sites they control. The work is straightforward: list accurate inventory, accept tasks you can fulfil, publish to the agreed standard, submit the live URL, then withdraw when balance is available.</p>
<p>Advertisers follow a different path — covered in the <a href="{$advertiserGuide}">advertiser guide</a>. For the two-sided model in brief, see <a href="{$howItWorks}">how it works</a> and <a href="{$pricing}">pricing</a>.</p>

<h2>1. Register as a publisher and open My Sites</h2>
<p>Create an account at <a href="{$register}">/register</a> with the publisher role (or switch into Publisher if you already buy as an advertiser). Verify your email, then open <strong>My Sites</strong>.</p>

<figure>
<img src="{$imgSites}" alt="Publisher My Sites page for adding websites to SEOLinkBuildings" loading="lazy" width="1200" height="675">
<figcaption>My Sites — add one website at a time, or use bulk add when you manage a larger portfolio.</figcaption>
</figure>

<p>Use <strong>Add New Website</strong> for a single listing. Use bulk add when you are onboarding many domains and want a guided table workflow. Complete niche, country, language, pricing, and link terms carefully — advertisers buy against those fields.</p>
<p>Duplicate domains are blocked. If a site is already claimed, follow the on-screen claim guidance rather than inventing a second listing.</p>

<h2>2. Verification and approval</h2>
<p>Expect a verification step that proves control of the domain, then an admin review before the site enters catalog inventory. Incomplete metrics, unclear niches, or unrealistic prices slow that review.</p>
<p>Keep contact details current. When a listing is live, treat price and link attributes as contractual: changing them mid-order without communication creates refunds and bad ratings.</p>

<h2>3. Work Tasks as they arrive</h2>
<p><strong>Tasks</strong> is your fulfilment queue. Open a task, read the brief, download or preview the assigned article, and accept only work you can publish on schedule.</p>

<figure>
<img src="{$imgTasks}" alt="Publisher Tasks page for accepting and completing placement orders" loading="lazy" width="1200" height="675">
<figcaption>Tasks — accept, publish, and submit the live URL for each placement.</figcaption>
</figure>

<p>After publication, submit the live URL in the task flow. Advertisers will check attributes and content; our public checklist on <a href="{$liveCheck}">post-live QA</a> is the same standard many buyers apply. If something in the brief conflicts with your editorial policy, reject early with a clear reason.</p>

<h2>4. Balance and withdrawals</h2>
<p>Completed work credits your publisher balance according to release rules. Open <strong>Withdraw</strong> (or Balance, depending on your menu) to review available funds and request a payout.</p>

<figure>
<img src="{$imgBalance}" alt="Publisher withdraw page for requesting payout of earnings" loading="lazy" width="1200" height="675">
<figcaption>Withdraw — request payout using the payment methods you have provided on the account.</figcaption>
</figure>

<p>Enter only methods you control. Incomplete bank or wallet details delay processing. For policy questions, the <a href="{$faq}">FAQ</a> and in-app support channels remain the authoritative path.</p>

<h2>5. Operating standards that protect your listing</h2>
<ul>
<li>Publish what you sold: same link type, same section quality, same permanence expectations.</li>
<li>Answer order chat within a reasonable window — silence looks like abandonment.</li>
<li>Do not swap URLs after approval without documenting the change.</li>
<li>Keep My Sites data honest; inflated traffic claims are caught quickly and cost trust.</li>
</ul>
<p>Reliable publishers receive repeat orders. That reputation compounds faster than any single high price.</p>
<p><a href="{$register}">Register as a publisher</a>, list your first site accurately, and treat the first three tasks as the proof of how you work.</p>

<h2>Frequently asked questions</h2>
<h3>When do my sites appear in the advertiser catalog?</h3>
<p>After you submit them and they pass verification and admin review. Incomplete profiles, duplicate domains, or failed verification keep a site out of inventory.</p>
<h3>Can I reject a task?</h3>
<p>Yes, when the brief or content does not fit your editorial rules. Reject promptly with a clear reason so the advertiser can reassign. Silence creates disputes.</p>
<h3>When can I withdraw earnings?</h3>
<p>When balance is available under the platform’s release rules for completed work. Use Withdraw, choose a saved payout method, and submit only amounts you can support with the listed details.</p>
<h3>I also buy placements. Do I need two emails?</h3>
<p>Usually not. One account can hold Advertiser and Publisher roles. Switch roles from the top bar instead of maintaining duplicate logins.</p>
HTML;
    }
}
