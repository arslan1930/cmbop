<?php

namespace App\Support;

/**
 * English trust post: what happens when a completed live link is removed.
 */
class LiveLinkRemovedBlogPost
{
    public const SLUG = 'what-happens-if-a-live-link-is-removed';

    public const FEATURED_ASSET = 'assets/img/blog/trust-link-removed-featured.jpg';

    public const FEATURED_STORAGE = 'blogs/featured/trust-link-removed-featured.jpg';

    public const IMAGE_ORDERS = 'trust-link-removed-inline.jpg';

    public const IMAGE_CHECK = 'live-link-checklist-attributes.jpg';

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
            'title' => 'What Happens If a Live Link Is Removed?',
            'slug' => self::SLUG,
            'primary_locale' => 'en',
            'excerpt' => 'Completed does not mean abandoned. How to report a removed placement, what the 30-day window means, and what happens when a dispute is upheld.',
            'content' => self::contentHtml(),
            'author' => 'M. Arslan',
            'tags' => [
                'Disputes',
                'Link removal',
                'Refunds',
                'Orders',
                'Trust',
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
                'question' => 'How long do I have to report a removed link?',
                'answer' => 'Advertisers can report a completed placement for 30 days after completion. Do not wait until month three to open the ticket.',
            ],
            [
                'question' => 'What do I get if the dispute is upheld?',
                'answer' => 'When we uphold the report, wallet and card orders return as wallet credit; PayPal checkout refunds return to the PayPal account that paid. The publisher payout for that order is clawed back. If they already withdrew those funds, we record a debt and block further withdrawals until it is resolved.',
            ],
            [
                'question' => 'Does every missing page automatically win?',
                'answer' => 'No. We review the case. Bring the live URL you were given, proof it is gone or altered, and the order ID. Vague claims slow everyone down.',
            ],
            [
                'question' => 'Should I still log placements after approval?',
                'answer' => 'Yes. Keep live URL, target URL, anchor, and date. That log is what makes a removal report easy to uphold.',
            ],
        ];
    }

    public static function contentHtml(): string
    {
        $refund = '/refund-policy';
        $wallet = '/blog/wallet-escrow-and-refunds-explained';
        $liveCheck = '/blog/what-to-check-after-the-live-link-indexation-attributes-rankings';
        $faq = '/faq';
        $register = '/register';
        $imgOrders = BlogInlineImages::publicUrl(self::IMAGE_ORDERS);
        $imgCheck = BlogInlineImages::publicUrl(self::IMAGE_CHECK);

        return <<<HTML
<p>A marketplace order can look finished and still go wrong a week later. The article disappears. The anchor changes. The dofollow link turns into a bare mention. That is not “SEO variance.” That is a delivery problem.</p>
<p>SEOLinkBuildings expects completed placements to stay live as agreed. When they do not, you have a reporting path — with a clock on it. This page explains that path without the legal small print (that lives on the <a href="{$refund}">refund policy</a>).</p>

<h2>First: make sure it is actually gone</h2>
<p>Before you open a dispute, do a five-minute check:</p>
<ol>
<li>Open the live URL from the order in a private window.</li>
<li>Confirm the target URL and anchor (or note exactly what changed).</li>
<li>Check whether the page 404s, redirects oddly, or the link attribute changed.</li>
<li>Save a screenshot with the date visible if you can.</li>
</ol>
<p>Our <a href="{$liveCheck}">post-live checklist</a> is built for this moment. If you never logged the original URL, you are arguing from memory — and memory loses disputes.</p>

<figure>
<img src="{$imgCheck}" alt="Checking link attributes on a live guest-post placement" loading="lazy" width="1200" height="675">
<figcaption>Verify the live page before you escalate — URL, anchor, and link attributes.</figcaption>
</figure>

<h2>The 30-day reporting window</h2>
<p>After completion, advertisers can report a removed (or broken) placement for <strong>30 days</strong>. That window is not endless on purpose. Campaigns need a clear cut-off; publishers need to know when a job is closed.</p>
<p>Practical advice: put a calendar reminder at day 7 and day 21 for every completed batch. Spot-check the URLs. It takes minutes.</p>

<h2>How to report from the order</h2>
<ol>
<li>Open the order in your advertiser Orders view.</li>
<li>Use the report / dispute action for that completed item.</li>
<li>State what failed in plain language: removed page, missing link, wrong attribute, etc.</li>
<li>Include the live URL you were given and any proof.</li>
</ol>
<p>Order chat is still useful for a quick “hey, the page 404s — can you restore?” Some publishers fix it the same day. If they do not, escalate while you are still inside the window.</p>

<figure>
<img src="{$imgOrders}" alt="Advertiser orders view used to track and report placements" loading="lazy" width="1200" height="675">
<figcaption>Orders — keep the thread and report from the completed placement while the window is open.</figcaption>
</figure>

<h2>What happens when we uphold the dispute</h2>
<p>If the report is upheld:</p>
<ul>
<li>the order amount can return to your <strong>wallet</strong> as credit</li>
<li>the publisher payout for that order is clawed back</li>
<li>if the publisher already withdrew those funds, we record a debt and block further withdrawals until it is sorted</li>
</ul>
<p>That last point matters. Clawback is how the marketplace stays fair when money has already left the publisher wallet. Details and edge cases sit in the <a href="{$refund}">refund policy</a> and <a href="{$faq}">FAQ</a>.</p>
<p>For the money flow before and after a dispute, read <a href="{$wallet}">wallet, escrow &amp; refunds</a>.</p>

<h2>What this is not</h2>
<ul>
<li>A guarantee that every ranking will rise</li>
<li>A free rewrite service forever</li>
<li>A cash wire back to your bank for every complaint</li>
</ul>
<p>It is a rule that completed placements should stay available, with a concrete way to push back when they do not.</p>

<h2>Habits that prevent ugly surprises</h2>
<ul>
<li>Log every live URL the day it arrives</li>
<li>Approve or request revision inside the 72-hour review window after live URL submission</li>
<li>Re-check completed links inside the first month</li>
<li>Prefer publishers with clear samples and realistic turnaround over mystery “DR 90” rows</li>
</ul>
<p>If you are just getting started, <a href="{$register}">register as an advertiser</a>, buy one placement, and practice the log + re-check habit before you scale spend.</p>

<h2>Frequently asked questions</h2>
<h3>How long do I have to report a removed link?</h3>
<p>Advertisers can report a completed placement for 30 days after completion. Do not wait until month three to open the ticket.</p>
<h3>What do I get if the dispute is upheld?</h3>
<p>When we uphold the report, wallet and card orders return as wallet credit; PayPal checkout refunds return to the PayPal account that paid. The publisher payout for that order is clawed back. If they already withdrew those funds, we record a debt and block further withdrawals until it is resolved.</p>
<h3>Does every missing page automatically win?</h3>
<p>No. We review the case. Bring the live URL you were given, proof it is gone or altered, and the order ID. Vague claims slow everyone down.</p>
<h3>Should I still log placements after approval?</h3>
<p>Yes. Keep live URL, target URL, anchor, and date. That log is what makes a removal report easy to uphold.</p>
HTML;
    }
}
