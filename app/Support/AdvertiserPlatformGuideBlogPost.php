<?php

namespace App\Support;

/**
 * English advertiser how-to guide for SEOLinkBuildings.
 * One body for /blog, /de/blog, /fr/blog, /nl/blog (no per-locale fields).
 */
class AdvertiserPlatformGuideBlogPost
{
    public const SLUG = 'how-to-buy-guest-posts-on-seolinkbuildings-advertiser-guide';

    public const FEATURED_ASSET = 'assets/img/blog/howto-advertiser-featured.jpg';

    public const FEATURED_STORAGE = 'blogs/featured/howto-advertiser-featured.jpg';

    public const IMAGE_DASHBOARD = 'howto-adv-dashboard.jpg';

    public const IMAGE_CATALOG = 'howto-adv-catalog.jpg';

    public const IMAGE_CONTENT = 'howto-adv-content-library.jpg';

    public const IMAGE_FUNDS = 'howto-adv-add-funds.jpg';

    public const IMAGE_ORDERS = 'howto-adv-orders.jpg';

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
            'title' => 'How to Buy Guest Posts on SEOLinkBuildings: Advertiser Guide',
            'slug' => self::SLUG,
            'primary_locale' => 'en',
            'excerpt' => 'A practical walkthrough for advertisers: create an account, fund your wallet, choose publishers in the catalog, assign articles, and track orders to live URL.',
            'content' => self::contentHtml(),
            'author' => 'Arslan Jason',
            'tags' => [
                'Advertiser guide',
                'Guest posts',
                'Marketplace',
                'Content library',
                'Wallet',
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
                'question' => 'Do I need to upload content before I can buy a placement?',
                'answer' => 'You can browse and add sites to the cart first, but checkout expects an approved article for each site. Upload early in Content Library so approval is not the bottleneck at payment.',
            ],
            [
                'question' => 'What is the difference between spendable balance and the welcome bonus?',
                'answer' => 'When a welcome promotion is active, new advertisers may receive spend-only credit toward placements under the platform rules. Spendable balance is funded money you add (card, bank, or other offered methods). At checkout, the wallet covers the order total according to those rules.',
            ],
            [
                'question' => 'Can I switch to a publisher account from the same login?',
                'answer' => 'Yes. Many users hold both roles. Use Switch to Publisher in the top bar when you need to manage your own sites or tasks, then switch back to Advertiser for buying.',
            ],
            [
                'question' => 'Where do I go if a live URL looks wrong?',
                'answer' => 'Open the order thread, document the issue with the live URL, and ask the publisher to correct it. Keep a short log of attribute and anchor checks; that record matters if you escalate.',
            ],
        ];
    }

    public static function contentHtml(): string
    {
        $marketplace = '/marketplace';
        $register = '/register';
        $howItWorks = '/how-it-works';
        $pricing = '/pricing';
        $blog = '/blog';
        $publisherGuide = '/blog/publisher-guide-add-sites-complete-orders-withdraw';
        $liveCheck = '/blog/what-to-check-after-the-live-link-indexation-attributes-rankings';
        $imgDash = BlogInlineImages::publicUrl(self::IMAGE_DASHBOARD);
        $imgCatalog = BlogInlineImages::publicUrl(self::IMAGE_CATALOG);
        $imgContent = BlogInlineImages::publicUrl(self::IMAGE_CONTENT);
        $imgFunds = BlogInlineImages::publicUrl(self::IMAGE_FUNDS);
        $imgOrders = BlogInlineImages::publicUrl(self::IMAGE_ORDERS);

        return <<<HTML
<p>SEOLinkBuildings is built so advertisers buy placements the way operations teams actually work: pick verified publishers, attach approved articles, pay from a wallet, then follow the order until the live URL lands. This guide covers that path without theatre.</p>
<p>If you still need the marketplace model in one page, read <a href="{$howItWorks}">how the platform works</a> first. Pricing and payment options are summarised on <a href="{$pricing}">pricing</a>. When you are ready to publish as a site owner instead, use the companion <a href="{$publisherGuide}">publisher guide</a>.</p>

<h2>1. Create an advertiser account and verify your email</h2>
<p>Register at <a href="{$register}">/register</a> and choose the advertiser role. Confirm the verification email before you expect full access — unverified accounts cannot complete a normal login session.</p>
<p>After verification you land in the advertiser shell. When a welcome promotion is active, treat any credited amount as purchasing power for placements, not a cash withdrawal.</p>

<figure>
<img src="{$imgDash}" alt="Advertiser dashboard on SEOLinkBuildings after login" loading="lazy" width="1200" height="675">
<figcaption>Advertiser dashboard — start from Browse catalog or open Content Library before checkout.</figcaption>
</figure>

<p>From the dashboard you can open the catalog, start a guided placement, or upload articles. The left navigation stays consistent: Catalog, Content Library, Orders, Add Funds, Billing, and reports.</p>

<h2>2. Upload articles in Content Library before you need them</h2>
<p>Each site in an order needs its own approved article. Upload early. Waiting until the cart is full is how teams miss deadlines.</p>

<figure>
<img src="{$imgContent}" alt="Advertiser Content Library for uploading guest-post articles" loading="lazy" width="1200" height="675">
<figcaption>Content Library — upload, wait for approval, then assign articles in the cart.</figcaption>
</figure>

<p>Use clear file names and keep one article per intended placement. Once a file is approved, you can attach it when you check out. If approval is pending, finish browsing, but do not expect payment to clear until content is ready.</p>

<h2>3. Browse the catalog and select publishers</h2>
<p>Open <strong>Catalog</strong>. Filter by country, language, niche, and metrics that match your brief. Add sites to the cart only when the listing terms (link type, sponsored status, turnaround) match what you are willing to buy.</p>

<figure>
<img src="{$imgCatalog}" alt="Advertiser catalog for browsing verified publisher websites" loading="lazy" width="1200" height="675">
<figcaption>Catalog — browse verified publishers, keep items in the cart, pay when content and budget are ready.</figcaption>
</figure>

<p>You may keep shopping with items already in the cart. Prefer the guided flow only if you want the stepper to force Market → Publishers → Content → Pay. Power users usually stay in the catalog and open the cart when the shortlist is done.</p>
<p>Public inventory is also summarised on the <a href="{$marketplace}">marketplace</a> pages; the logged-in catalog is where purchasing happens.</p>

<h2>4. Fund the wallet, then pay at checkout</h2>
<p>Open <strong>Add Funds</strong> when spendable balance will not cover the cart. Choose the payment method offered for your account (card via Stripe, bank transfer, or other methods shown on the page). Card details are processed by Stripe; they do not sit on our servers.</p>

<figure>
<img src="{$imgFunds}" alt="Add Funds page for topping up the advertiser wallet" loading="lazy" width="1200" height="675">
<figcaption>Add Funds — top up spendable balance before or during checkout.</figcaption>
</figure>

<p>At checkout, assign an approved article to each site, confirm totals, and pay from the wallet. Keep invoices under Billing if finance needs a record later.</p>

<h2>5. Track orders until the live URL is accepted</h2>
<p><strong>Orders</strong> is the operational view after payment. Watch status changes, use order chat for clarifications, and review the live URL when the publisher marks the placement complete.</p>

<figure>
<img src="{$imgOrders}" alt="Advertiser Orders page for tracking guest-post placements" loading="lazy" width="1200" height="675">
<figcaption>Orders — follow each placement from payment through live URL review.</figcaption>
</figure>

<p>When a URL is live, run a short QA pass: correct page, correct target, expected anchor, expected link attributes, and indexation. Our checklist on <a href="{$liveCheck}">what to check after the live link</a> is written for that exact moment.</p>

<h2>6. Habits that keep campaigns clean</h2>
<ul>
<li>Upload content before you fill a large cart.</li>
<li>Save filters and shortlists so reorders do not start from zero.</li>
<li>Fund the wallet with a buffer; do not pay placement by placement if you run weekly batches.</li>
<li>Log live URLs and attributes the day they arrive — not a month later.</li>
</ul>
<p>That discipline is what separates a marketplace account from a spreadsheet of forgotten links. Browse the <a href="{$blog}">blog</a> for selection strategy; use this page when you need the product path itself.</p>
<p><a href="{$register}">Create your advertiser account</a>, verify your email, and place the first order with content already approved.</p>

<h2>Frequently asked questions</h2>
<h3>Do I need to upload content before I can buy a placement?</h3>
<p>You can browse and add sites to the cart first, but checkout expects an approved article for each site. Upload early in Content Library so approval is not the bottleneck at payment.</p>
<h3>What is the difference between spendable balance and the welcome bonus?</h3>
<p>When a welcome promotion is active, new advertisers may receive spend-only credit toward placements under the platform rules. Spendable balance is funded money you add (card, bank, or other offered methods). At checkout, the wallet covers the order total according to those rules.</p>
<h3>Can I switch to a publisher account from the same login?</h3>
<p>Yes. Many users hold both roles. Use Switch to Publisher in the top bar when you need to manage your own sites or tasks, then switch back to Advertiser for buying.</p>
<h3>Where do I go if a live URL looks wrong?</h3>
<p>Open the order thread, document the issue with the live URL, and ask the publisher to correct it. Keep a short log of attribute and anchor checks; that record matters if you escalate.</p>
HTML;
    }
}
