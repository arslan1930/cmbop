<?php

namespace App\Support;

/**
 * English trust post: wallet, escrow reservation, and refunds.
 */
class WalletEscrowRefundsBlogPost
{
    public const SLUG = 'wallet-escrow-and-refunds-explained';

    public const FEATURED_ASSET = 'assets/img/blog/trust-wallet-escrow-featured.jpg';

    public const FEATURED_STORAGE = 'blogs/featured/trust-wallet-escrow-featured.jpg';

    public const IMAGE_FUNDS = 'trust-wallet-escrow-inline.jpg';

    public const IMAGE_ORDERS = 'trust-wallet-refund-orders.jpg';

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
            'title' => 'Wallet, Escrow & Refunds Explained',
            'slug' => self::SLUG,
            'primary_locale' => 'en',
            'excerpt' => 'How SEOLinkBuildings holds your euros, when publishers get paid, what the welcome bonus can (and cannot) do, and how refunds work.',
            'content' => self::contentHtml(),
            'author' => 'Arslan Jason',
            'tags' => [
                'Wallet',
                'Escrow',
                'Refunds',
                'Stripe',
                'Trust',
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
                'question' => 'Do you store my card details?',
                'answer' => 'No. Card payments go through Stripe. Card numbers do not sit on SEOLinkBuildings servers.',
            ],
            [
                'question' => 'Can I cash out the welcome bonus?',
                'answer' => 'No. Welcome bonus credit is promotional and spend-only. It is not withdrawable as cash and is not refundable as cash.',
            ],
            [
                'question' => 'When does the publisher receive money?',
                'answer' => 'After the placement is approved — either by you, or automatically about 72 hours after the live URL is submitted if you do not respond or request changes.',
            ],
            [
                'question' => 'If a refund is upheld, where does the money go?',
                'answer' => 'Back to your wallet as spendable credit you can use on another placement, according to the refund policy for that case.',
            ],
        ];
    }

    public static function contentHtml(): string
    {
        $refund = '/refund-policy';
        $pricing = '/pricing';
        $faq = '/faq';
        $addFundsGuide = '/blog/how-to-buy-guest-posts-on-seolinkbuildings-advertiser-guide';
        $linkRemoved = '/blog/what-happens-if-a-live-link-is-removed';
        $register = '/register';
        $imgFunds = BlogInlineImages::publicUrl(self::IMAGE_FUNDS);
        $imgOrders = BlogInlineImages::publicUrl(self::IMAGE_ORDERS);

        return <<<HTML
<p>If you have been burned by “pay the invoice, hope for the best” outreach, a wallet marketplace feels different on purpose. Money moves in stages. That is the whole point.</p>
<p>Here is the plain version of how SEOLinkBuildings handles euros: top up, reserve at checkout, release on approval, refund when a case is upheld. The legal detail lives on the <a href="{$refund}">refund policy</a> page — this article is the practical walkthrough.</p>

<h2>Everything runs in a EUR wallet</h2>
<p>Balances are in euros. You add funds first, then pay for placements from that balance. You are not typing a card number into every order.</p>
<p>Typical top-up paths:</p>
<ul>
<li><strong>Card via Stripe</strong> — fast when available for your account</li>
<li><strong>Bank transfer</strong> — credited after we confirm receipt</li>
</ul>
<p>When a welcome promotion is active, treat any credited amount as purchasing power for placements under the current rules. It is not a cash gift you can withdraw.</p>

<figure>
<img src="{$imgFunds}" alt="Add Funds page for topping up the SEOLinkBuildings wallet" loading="lazy" width="1200" height="675">
<figcaption>Add Funds — top up spendable balance before or during checkout.</figcaption>
</figure>

<h2>Checkout reserves money. It does not tip the publisher yet.</h2>
<p>When you place an order, the amount is reserved from your wallet. The publisher is only credited after the placement is approved:</p>
<ul>
<li>you approve the live URL, or</li>
<li>the order auto-approves about <strong>72 hours</strong> after the live URL is submitted if you stay silent</li>
</ul>
<p>That gap exists so you can ask for a fix. Use it. Open the order chat, write what is wrong, and request a revision while the funds are still reserved.</p>

<figure>
<img src="{$imgOrders}" alt="Advertiser orders screen where wallet-funded placements are tracked" loading="lazy" width="1200" height="675">
<figcaption>Orders — reserved funds stay tied to the placement until approval or a resolved dispute.</figcaption>
</figure>

<h2>What a refund usually means here</h2>
<p>“Refund” on this platform usually means <strong>wallet credit</strong>, not an instant bank reversal of every top-up. That keeps the marketplace accounting clean and lets you redeploy the budget on another site.</p>
<p>Common cases:</p>
<ul>
<li><strong>Publisher cannot deliver</strong> — we review; wallet credit may return</li>
<li><strong>Order cancelled under the rules</strong> — reserved money goes back to the wallet</li>
<li><strong>Upheld link-removal dispute</strong> — order amount can return to your wallet (see the <a href="{$linkRemoved}">link removal guide</a>)</li>
</ul>
<p>Promotional bonus credit is different: spend-only, not cashable. Pricing and fee context sit on <a href="{$pricing}">pricing</a>; edge cases are in the <a href="{$faq}">FAQ</a> and refund policy.</p>

<h2>How to request a review without wasting a week</h2>
<ol>
<li>Open the order conversation.</li>
<li>Include the order ID, live URL (if any), and what failed.</li>
<li>Screenshots help when an attribute or anchor changed.</li>
<li>Wait for the review outcome before you assume the money is gone forever.</li>
</ol>
<p>If you want the click-path for funding and checkout, the <a href="{$addFundsGuide}">advertiser guide</a> covers Add Funds and cart payment step by step.</p>

<h2>A simple mental model</h2>
<p>Think of the wallet as a project budget. Top up once. Buy placements as you approve content. Let reservation protect you between payment and live URL. Ask for a review when delivery breaks the deal.</p>
<p>That is less exciting than “instant SEO,” and more survivable when something goes wrong. <a href="{$register}">Create an account</a>, fund a small balance, and run one clean order before you scale.</p>

<h2>Frequently asked questions</h2>
<h3>Do you store my card details?</h3>
<p>No. Card payments go through Stripe. Card numbers do not sit on SEOLinkBuildings servers.</p>
<h3>Can I cash out the welcome bonus?</h3>
<p>No. Welcome bonus credit is promotional and spend-only. It is not withdrawable as cash and is not refundable as cash.</p>
<h3>When does the publisher receive money?</h3>
<p>After the placement is approved — either by you, or automatically about 72 hours after the live URL is submitted if you do not respond or request changes.</p>
<h3>If a refund is upheld, where does the money go?</h3>
<p>Back to your wallet as spendable credit you can use on another placement, according to the refund policy for that case.</p>
HTML;
    }
}
