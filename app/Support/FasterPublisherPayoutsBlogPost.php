<?php

namespace App\Support;

/**
 * English publisher post: turnaround, quality, revisions, and payouts.
 */
class FasterPublisherPayoutsBlogPost
{
    public const SLUG = 'faster-publisher-payouts-turnaround-quality-revisions';

    public const FEATURED_ASSET = 'assets/img/blog/supply-payouts-featured.jpg';

    public const FEATURED_STORAGE = 'blogs/featured/supply-payouts-featured.jpg';

    public const IMAGE_TASKS = 'supply-payouts-tasks.jpg';

    public const IMAGE_WITHDRAW = 'supply-payouts-withdraw.jpg';

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
            'title' => 'Faster Publisher Payouts: Turnaround, Quality & Revisions',
            'slug' => self::SLUG,
            'primary_locale' => 'en',
            'excerpt' => 'You get paid when placements are approved — not when you accept a task. How turnaround, clean live URLs, and revision hygiene unlock balance and withdrawals.',
            'content' => self::contentHtml(),
            'author' => 'M. Arslan',
            'tags' => [
                'Publisher tips',
                'Payouts',
                'Tasks',
                'Revisions',
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
                'question' => 'When is my balance credited?',
                'answer' => 'After the placement is approved — either by the advertiser, or automatically about 72 hours after you submit the live URL if they do not respond or request changes.',
            ],
            [
                'question' => 'Can I withdraw any available amount?',
                'answer' => 'Yes, when balance is available and you are not blocked by outstanding clawback debt. Use Withdraw, pick a saved payout method, and submit the amount you need.',
            ],
            [
                'question' => 'Why did my payout methods lock?',
                'answer' => 'After the first successful withdrawal setup, payout details lock so funds always go to the same verified methods. Contact support if you need an admin override to change them.',
            ],
            [
                'question' => 'What blocks withdrawals after a dispute?',
                'answer' => 'If an advertiser’s link-removal report (within 30 days of completion) is upheld and a clawback cannot be taken from balance, outstanding debt blocks further withdrawals until it is cleared.',
            ],
        ];
    }

    public static function contentHtml(): string
    {
        $publisherGuide = '/blog/publisher-guide-add-sites-complete-orders-withdraw';
        $walletGuide = '/blog/wallet-escrow-and-refunds-explained';
        $linkRemoved = '/blog/what-happens-if-a-live-link-is-removed';
        $liveCheck = '/blog/what-to-check-after-the-live-link-indexation-attributes-rankings';
        $briefGuide = '/blog/guest-post-brief-anchors-urls-images-sensitive-topics';
        $register = '/register';
        $imgTasks = BlogInlineImages::publicUrl(self::IMAGE_TASKS);
        $imgWithdraw = BlogInlineImages::publicUrl(self::IMAGE_WITHDRAW);

        return <<<HTML
<p>Publisher earnings are not “accept task → instant cash.” Money stays reserved on the advertiser side until the live URL is approved. That is good for trust — and it means your payout speed is mostly operational: how fast you publish, how clean the URL is, and how you handle revisions.</p>
<p>For the wallet model from the buyer’s view, read <a href="{$walletGuide}">wallet, escrow &amp; refunds</a>. For the My Sites → Tasks → Withdraw path, use the <a href="{$publisherGuide}">publisher guide</a>.</p>

<h2>Credit happens on approval (or ~72h silence)</h2>
<p>After you submit the live URL:</p>
<ul>
<li>the advertiser can <strong>approve</strong> → your balance is credited, or</li>
<li>they can <strong>request changes</strong> → fix and resubmit, or</li>
<li>they stay silent → the order <strong>auto-approves about 72 hours</strong> after the live URL submission</li>
</ul>
<p>Fast turnaround only helps if the URL survives that review. A same-day publish with the wrong anchor or a broken link just starts a revision loop.</p>

<figure>
<img src="{$imgTasks}" alt="Publisher Tasks queue for accepting and completing placement orders" loading="lazy" width="1200" height="675">
<figcaption>Tasks — accept work you can fulfil, publish to the brief, then submit the live URL.</figcaption>
</figure>

<h2>Turnaround habits that unlock payouts</h2>
<ol>
<li><strong>Accept or reject early.</strong> If the brief conflicts with your editorial rules, reject with a clear reason. Silence looks like abandonment.</li>
<li><strong>Publish what you sold.</strong> Same link type, section quality, and link count the listing promised.</li>
<li><strong>Submit a real live URL</strong> — publicly reachable, correct attributes. Advertisers use the same checklist as our <a href="{$liveCheck}">post-live QA guide</a>.</li>
<li><strong>Answer order chat.</strong> Revision requests during the review window are cheaper than disputes after completion.</li>
</ol>
<p>Agencies write clearer briefs when they follow <a href="{$briefGuide}">guest-post brief tips</a> — when a brief is vague, ask one concrete question before you publish.</p>

<h2>Revisions without drama</h2>
<p>A revision request is still a path to approval. Fix the named issue (anchor, attribute, section, typo), resubmit, and keep the conversation in the order thread. Do not swap the URL after approval without documenting the change — that is how removal disputes start.</p>

<h2>Withdraw available balance</h2>
<p>When credit lands, open <strong>Withdraw</strong>. You can request payout of available balance using the payment methods you provided. After the first withdrawal setup, methods typically <strong>lock</strong> so payouts stay on verified details — contact support if you need a change.</p>

<figure>
<img src="{$imgWithdraw}" alt="Publisher Withdraw page for requesting payout of available balance" loading="lazy" width="1200" height="675">
<figcaption>Withdraw — request payout when balance is available and payout methods are set.</figcaption>
</figure>

<h2>Protect earnings after completion</h2>
<p>Completed does not mean forgotten. Advertisers can report a removed or broken placement for <strong>30 days</strong> after completion. If the report is upheld, the order can be clawed back; if funds were already withdrawn, outstanding debt can <strong>block further withdrawals</strong> until resolved. Keep links live; read <a href="{$linkRemoved}">what happens if a live link is removed</a>.</p>
<p>Reliable turnaround and durable links beat chasing one more euro on the listing price. <a href="{$register}">Register as a publisher</a>, clear your Tasks queue cleanly, and withdraw when the balance shows available.</p>

<h2>Frequently asked questions</h2>
<h3>When is my balance credited?</h3>
<p>After the placement is approved — either by the advertiser, or automatically about 72 hours after you submit the live URL if they do not respond or request changes.</p>
<h3>Can I withdraw any available amount?</h3>
<p>Yes, when balance is available and you are not blocked by outstanding clawback debt. Use Withdraw, pick a saved payout method, and submit the amount you need.</p>
<h3>Why did my payout methods lock?</h3>
<p>After the first successful withdrawal setup, payout details lock so funds always go to the same verified methods. Contact support if you need an admin override to change them.</p>
<h3>What blocks withdrawals after a dispute?</h3>
<p>If an advertiser’s link-removal report (within 30 days of completion) is upheld and a clawback cannot be taken from balance, outstanding debt blocks further withdrawals until it is cleared.</p>
HTML;
    }
}
