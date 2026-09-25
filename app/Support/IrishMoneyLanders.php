<?php

namespace App\Support;

/**
 * Ireland SEO landers on the existing UK locale URL prefix (/uk/…-ireland).
 * Not a new application locale and not /ie/. Chrome stays English (en-GB).
 */
class IrishMoneyLanders
{
    use MoneyLanderPages;

    public const LOCALE = 'uk';

    /**
     * Ireland pages are English; marketing chrome lives on unprefixed UK URLs.
     */
    public static function marketingUrl(string $englishKey): string
    {
        return match ($englishKey) {
            'marketplace' => self::url('marketplace-ireland'),
            'pricing' => self::url('pricing-ireland'),
            default => url('/'.$englishKey),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return [
            'guest-post-ireland' => '/uk/buy-guest-posts-ireland',
            'order-guest-posts-ireland' => '/uk/buy-guest-posts-ireland',
            'paid-guest-posts-ireland' => '/uk/buy-guest-posts-ireland',
            'irish-media-guest-posts' => '/uk/buy-guest-posts-ireland',
            'sponsored-articles-ireland' => '/uk/sponsored-posts-ireland',
            'ie-backlinks' => '/uk/buy-backlinks-ireland',
            'contextual-backlinks-ireland' => '/uk/buy-backlinks-ireland',
            'link-building-dublin' => '/uk/link-building-ireland',
            'packages-ireland' => '/uk/pricing-ireland',
            'guest-post-cost-ireland' => '/uk/pricing-ireland',
            'white-label-ireland' => '/uk/agencies-ireland',
            'link-insertions-ireland' => '/uk/niche-edits-ireland',
            'press-release-ireland' => '/uk/digital-pr-ireland',
            'digital-pr-dublin' => '/uk/digital-pr-ireland',
            'dofollow-vs-nofollow-ireland' => '/uk/guide-ireland',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function ui(): array
    {
        return [
            'cluster_title' => 'Pages about guest posts, backlinks and link building in Ireland',
            'from' => 'From',
            'price_note' => 'Lowest current euro price on active, verified catalog rows whose primary country is Ireland. Not a fixed rate card.',
            'sites_preview' => 'Sites in preview',
            'count_note' => 'Active, verified publishers with Ireland as primary country when the count is available.',
            'th_site' => 'Site',
            'th_country' => 'Country',
            'th_language' => 'Language',
            'th_from' => 'From',
            'teaser_foot' => 'We show DA, DR and the euro price when they exist on the listing. Missing metrics stay blank.',
            'see_also' => 'See also',
        ];
    }

    /**
     * @return list<array{slug: string, label: string, url: string}>
     */
    public static function clusterLinks(?string $current = null): array
    {
        return self::filterCluster([
            ['slug' => 'home', 'label' => 'UK marketplace home', 'url' => url('/')],
            ['slug' => 'marketplace-ireland', 'label' => 'Ireland marketplace', 'url' => self::url('marketplace-ireland')],
            ['slug' => 'buy-guest-posts-ireland', 'label' => 'Buy guest posts', 'url' => self::url('buy-guest-posts-ireland')],
            ['slug' => 'sponsored-posts-ireland', 'label' => 'Sponsored posts', 'url' => self::url('sponsored-posts-ireland')],
            ['slug' => 'buy-backlinks-ireland', 'label' => 'Buy backlinks', 'url' => self::url('buy-backlinks-ireland')],
            ['slug' => 'link-building-ireland', 'label' => 'Link building Ireland', 'url' => self::url('link-building-ireland')],
            ['slug' => 'pricing-ireland', 'label' => 'Pricing Ireland', 'url' => self::url('pricing-ireland')],
            ['slug' => 'agencies-ireland', 'label' => 'For agencies', 'url' => self::url('agencies-ireland')],
            ['slug' => 'digital-pr-ireland', 'label' => 'Digital PR Ireland', 'url' => self::url('digital-pr-ireland')],
            ['slug' => 'niche-edits-ireland', 'label' => 'Niche edits', 'url' => self::url('niche-edits-ireland')],
            ['slug' => 'guide-ireland', 'label' => 'Ireland guide', 'url' => self::url('guide-ireland')],
        ], $current);
    }

    /**
     * @return list<array{slug: string, label: string, url: string}>
     */
    public static function footerLinks(): array
    {
        return array_values(array_filter(
            self::clusterLinks('home'),
            static fn (array $item) => ! in_array($item['slug'], ['home'], true)
        ));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function pages(): array
    {
        $market = self::url('marketplace-ireland');
        $prices = self::url('pricing-ireland');
        $how = url('/how-it-works');
        $register = '/register';
        $guest = self::url('buy-guest-posts-ireland');
        $sponsored = self::url('sponsored-posts-ireland');
        $links = self::url('buy-backlinks-ireland');
        $lb = self::url('link-building-ireland');
        $agencies = self::url('agencies-ireland');
        $pr = self::url('digital-pr-ireland');
        $niche = self::url('niche-edits-ireland');
        $guide = self::url('guide-ireland');
        $publisher = url('/become-a-publisher');
        $ukLander = url('/guest-posts-uk');
        $ieLander = url('/guest-posts-ireland');
        $ukHome = url('/');

        return [
            'marketplace-ireland' => [
                'kicker' => 'Irish publishers',
                'h1' => 'Guest post and backlink marketplace for Ireland',
                'subtitle' => 'Browse verified publishers whose primary country is Ireland, compare niche, language, DA/DR and the euro price, then track the live URL on the order.',
                'meta_title' => 'Ireland link building marketplace | Irish publishers',
                'meta_description' => 'Ireland SEO marketplace for Irish publishers and .ie sites: filter niche, DA/DR and EUR price. Same wallet as the UK catalog — Ireland is its own country filter.',
                'teaser_countries' => ['ie'],
                'teaser_title' => 'Sample Irish inventory',
                'teaser_subtitle' => 'Masked preview of active catalog rows with Ireland as primary country. Domains open after you register.',
                'intro' => [
                    'This page is the Ireland-targeted catalog lander, not a second UK homepage. The unprefixed marketplace stays the UK/global public list; here the teasers are Ireland-primary publishers — often .ie, often written for Irish readers.',
                    'SEOLinkBuildings is a self-serve marketplace: you pick the site, pay in euro from the wallet, and keep briefing, chat and live URL on the same order. Headquarters is London (Topurlz Ltd). We do not invent an Irish office or VAT number.',
                ],
                'points' => [
                    [
                        'title' => 'Ireland, not a UK bundle',
                        'body' => 'UK publishers stay on <a href="'.$ukLander.'">guest posts in the UK</a> and the unprefixed catalog. Filter country Ireland after login when you need Irish media or a .ie host.',
                    ],
                    [
                        'title' => 'How the Ireland marketplace works',
                        'body' => 'Browse publishers, filter websites, read requirements and prices, place the order, the publisher publishes, you receive the live URL on the order. Only those steps exist in the product.',
                    ],
                    [
                        'title' => 'EUR wallet',
                        'body' => 'Checkout is euro even when the publisher is Irish. Fund once and buy Irish and other European placements from the same balance.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Irish media versus a mixed English dump',
                        'body' => 'Language English is not a country. If you need Irish readers or a .ie backlink, filter Ireland — do not buy a generic English site and hope. Dublin is not a separate URL; it is the same country filter.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Can I buy guest posts from Irish publishers?',
                        'a' => 'Yes, when a catalog row lists Ireland as the primary country. Register, filter Ireland, and check out in EUR.',
                    ],
                    [
                        'q' => 'Is this the same as the UK marketplace?',
                        'a' => 'Same product, different country filter. UK inventory is a separate filter and a separate English lander. This page is Ireland.',
                    ],
                    [
                        'q' => 'Do you invent a count of .ie sites?',
                        'a' => 'No. The preview count is live catalog rows when the database has Ireland-primary listings. Empty metrics stay empty.',
                    ],
                ],
                'cta_primary' => ['label' => 'Create an account and see publishers', 'url' => $register],
                'cta_secondary' => ['label' => 'UK marketplace home', 'url' => $ukHome],
                'see_also' => [
                    ['label' => 'Buy guest posts in Ireland', 'url' => $guest],
                    ['label' => 'Buy backlinks in Ireland', 'url' => $links],
                    ['label' => 'Ireland pricing', 'url' => $prices],
                    ['label' => 'Ireland inventory (English lander)', 'url' => $ieLander],
                ],
            ],
            'buy-guest-posts-ireland' => [
                'kicker' => 'Guest posts with a backlink',
                'h1' => 'Buy guest posts in Ireland',
                'subtitle' => 'Order paid guest posts on Irish blogs and media in the catalog: filter niche, DA/DR and euro price, send the brief, approve the live URL.',
                'meta_title' => 'Buy guest posts in Ireland | Irish publisher marketplace',
                'meta_description' => 'Buy guest posts in Ireland on verified Irish publishers. Filter .ie and Irish media, pay in EUR, and track the live URL on the order.',
                'teaser_countries' => ['ie'],
                'teaser_title' => 'Sites for guest posts in Ireland',
                'teaser_subtitle' => 'Masked preview of Ireland-primary listings. Hosts stay hidden until you register.',
                'intro' => [
                    '«Buy guest posts Ireland», «guest post sites Ireland» and «order guest posts Ireland» are the same intent: a paid article on a site you do not own. You choose the row — often .ie, often aimed at Irish readers — and pay from the euro wallet.',
                    'Finance, health, property and tech are catalog niches, not separate doorway URLs. High-DA rows are whatever DA the listing currently shows; we do not invent a DA package.',
                ],
                'points' => [
                    [
                        'title' => 'Irish publishers, not a UK swap',
                        'body' => 'This lander is Ireland-primary inventory. British publishers remain on the UK lander and the unprefixed catalog. Do not treat .co.uk and .ie as interchangeable.',
                    ],
                    [
                        'title' => 'Dofollow guest posts',
                        'body' => 'The link attribute is on the catalog row. Many paid placements are marked sponsored. There is no “dofollow at any price”.',
                    ],
                    [
                        'title' => 'How you order',
                        'body' => 'Create an account, filter Ireland, add the site, send title, copy or brief plus anchor. The publisher returns the live URL for approval.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Irish media guest posts',
                        'body' => 'A row that looks like Irish media still has to be in the catalog and accept the brief. We do not run a separate press-subscription SKU. Niches (finance, health, property, tech) are filters after login.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'What are Irish guest posts?',
                        'a' => 'Paid publications on sites whose primary country is Ireland, usually in English, with the link type written on the listing.',
                    ],
                    [
                        'q' => 'Can I only buy dofollow guest posts in Ireland?',
                        'a' => 'You can filter listings that mention dofollow. Check the live HTML; we do not rewrite the publisher’s attributes.',
                    ],
                    [
                        'q' => 'Do you write the article?',
                        'a' => 'The default order uses your brief. Some listings offer editorial; that is on the row, not a made-up add-on here.',
                    ],
                ],
                'cta_primary' => ['label' => 'Create an account and see Irish sites', 'url' => $register],
                'cta_secondary' => ['label' => 'Ireland marketplace', 'url' => $market],
                'see_also' => [
                    ['label' => 'Sponsored posts Ireland', 'url' => $sponsored],
                    ['label' => 'Buy backlinks Ireland', 'url' => $links],
                    ['label' => 'Ireland pricing', 'url' => $prices],
                    ['label' => 'Ireland guide', 'url' => $guide],
                ],
            ],
            'sponsored-posts-ireland' => [
                'kicker' => 'Paid articles',
                'h1' => 'Buy sponsored posts in Ireland',
                'subtitle' => 'A sponsored post here is a paid article on a catalog site, with a euro price and a live URL — not a generic advertorial subscription.',
                'meta_title' => 'Sponsored posts in Ireland | Irish media placements',
                'meta_description' => 'Buy sponsored posts in Ireland on verified publishers. EUR price per listing, brief and live URL — no invented Irish press desk.',
                'teaser_countries' => ['ie'],
                'teaser_title' => 'Irish sites for sponsored articles',
                'teaser_subtitle' => 'Same Ireland-primary preview as the guest-post page. A sponsored placement exists only if the site is in the catalog.',
                'intro' => [
                    '«Buy sponsored posts Ireland» and «paid guest posts» describe a paid publication. If the domain is not a catalog row, we do not sell it.',
                    'Native advertising for SEO is the same flow: pick the publication, pay in EUR, get the URL. We do not promise Google News Ireland.',
                ],
                'points' => [
                    [
                        'title' => 'Sponsored marking',
                        'body' => 'Many sites require rel sponsored or a visible label. Follow the listing rule.',
                    ],
                    [
                        'title' => 'Price',
                        'body' => 'What a sponsored article costs depends on the site. See <a href="'.$prices.'">Ireland pricing</a> for the model and the catalog for live amounts.',
                    ],
                    [
                        'title' => 'Irish media placements',
                        'body' => 'You send copy or a brief. The publisher publishes on their site and returns the live URL.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Sponsored article versus guest post',
                        'body' => 'Both are paid publications with a link. The editorial label differs; checkout does not. <a href="'.$guest.'">Buy guest posts in Ireland</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Is there one Irish advertorial rate?',
                        'a' => 'No. Each catalog row has its own euro price.',
                    ],
                    [
                        'q' => 'Do you place on every Irish newspaper?',
                        'a' => 'Only on sites that are in the catalog and accept the order.',
                    ],
                ],
                'cta_primary' => ['label' => 'See Irish sites', 'url' => $register],
                'cta_secondary' => ['label' => 'Digital PR Ireland', 'url' => $pr],
                'see_also' => [
                    ['label' => 'Digital PR Ireland', 'url' => $pr],
                    ['label' => 'Ireland pricing', 'url' => $prices],
                    ['label' => 'Buy guest posts Ireland', 'url' => $guest],
                ],
            ],
            'buy-backlinks-ireland' => [
                'kicker' => 'Editorial backlinks',
                'h1' => 'Buy backlinks in Ireland',
                'subtitle' => 'Backlinks here come from publications you buy on real sites — not an anonymous bag of URLs, and not a guaranteed ranking.',
                'meta_title' => 'Buy backlinks Ireland | Contextual .ie links',
                'meta_description' => 'Buy contextual and dofollow backlinks in Ireland from guest posts on real sites. EUR price, link type on the listing, live URL on the order.',
                'teaser_countries' => ['ie'],
                'teaser_title' => '.ie and Irish sites for backlinks',
                'teaser_subtitle' => 'Preview of Ireland-primary catalog rows. Reported traffic is disclosed, not promised.',
                'intro' => [
                    '«Buy backlinks Ireland», «buy dofollow backlinks Ireland» and «buy contextual backlinks Ireland» seek a link on a published page. You get that through a guest post or sponsored article, with the anchor from your brief.',
                    'A .ie backlink exists when the listing’s host is a .ie domain. We do not invent .ie availability; empty inventory stays empty.',
                ],
                'points' => [
                    [
                        'title' => 'Quality you can read',
                        'body' => 'Country, language, niche, DA/DR and price sit on the row. We do not sell “quality backlinks” as a label without a site.',
                    ],
                    [
                        'title' => 'Dofollow is not the default',
                        'body' => 'Filter on link type. The publisher owns the live HTML.',
                    ],
                    [
                        'title' => 'No PBN',
                        'body' => 'We do not sell private networks. Risks are in the <a href="'.$guide.'">Ireland guide</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'How to choose',
                        'body' => 'Start in the <a href="'.$market.'">Ireland marketplace</a>, filter Ireland, compare price and anchor rules. Then <a href="'.$guest.'">buy guest posts</a> or a <a href="'.$sponsored.'">sponsored post</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Can I order .ie backlinks?',
                        'a' => 'When a listing uses a .ie host and Ireland as the primary country. Filter after login; we do not fabricate TLD stock.',
                    ],
                    [
                        'q' => 'Can I buy only dofollow?',
                        'a' => 'You can filter listings that mention dofollow. Verify the attribute on the live page.',
                    ],
                    [
                        'q' => 'Do you insert a link into an existing article?',
                        'a' => 'Not as a niche-edit SKU. Some sites sell a homepage extra with a time limit.',
                    ],
                ],
                'cta_primary' => ['label' => 'Compare Irish sites', 'url' => $register],
                'cta_secondary' => ['label' => 'Link building Ireland', 'url' => $lb],
                'see_also' => [
                    ['label' => 'Ireland pricing', 'url' => $prices],
                    ['label' => 'Buy guest posts Ireland', 'url' => $guest],
                    ['label' => 'Niche edits Ireland', 'url' => $niche],
                ],
            ],
            'link-building-ireland' => [
                'kicker' => 'Campaigns',
                'h1' => 'Link building in Ireland',
                'subtitle' => 'Build the campaign from the catalog, publication by publication, or pick a managed digital-PR package under pricing. No cheap anonymous link packs.',
                'meta_title' => 'Link building Ireland | Guest posts and backlinks',
                'meta_description' => 'Link building Ireland and Dublin: self-serve Irish publisher catalog in EUR, tracked orders, and managed digital-PR packages — not a UK clone with the country swapped.',
                'teaser_countries' => ['ie'],
                'teaser_title' => 'Inventory for Ireland campaigns',
                'teaser_subtitle' => 'Same Ireland-primary rows as the guest-post page. Other markets stay on their own filters after login.',
                'intro' => [
                    'Link building here means you pick Irish publishers, pay, and follow the URL. It is not a retainer that “does SEO” for you. Dublin is not a separate product — it is the same Ireland country filter.',
                    'European campaigns use the same wallet. Ireland is the country filter, not a second SaaS. UK campaigns stay on unprefixed UK pages.',
                ],
                'points' => [
                    [
                        'title' => 'Self-serve',
                        'body' => 'Strategy is your choice of sites, anchors and pace. The catalog is the source. Monthly link building is the pace you set.',
                    ],
                    [
                        'title' => 'Link building packages',
                        'body' => 'Numbered packages under pricing are managed digital-PR campaigns, not a sack of URLs.',
                    ],
                    [
                        'title' => 'White label',
                        'body' => 'An agency can order from its own account. We do not ship a portal with your logo. Details: <a href="'.$agencies.'">agencies Ireland</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Guest posts, niche edits and digital PR',
                        'body' => 'A guest post is a new article. Niche edits (link in existing content) are not a SKU. Digital PR is the campaign; in the marketplace you still pay for the publication.',
                    ],
                    [
                        'h2' => 'How a campaign starts',
                        'body' => 'Account, euro balance, filter Ireland, order. Flow: <a href="'.$how.'">how it works</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Do you sell cheap Irish link building?',
                        'a' => 'Price is the listing’s. There is no separate cheap layer beside the catalog.',
                    ],
                    [
                        'q' => 'Is this Dublin-only?',
                        'a' => 'No city doorway. Filter country Ireland. Dublin searches land here because that is the market, not a second URL.',
                    ],
                ],
                'cta_primary' => ['label' => 'Open the Ireland catalog', 'url' => $register],
                'cta_secondary' => ['label' => 'See Ireland pricing', 'url' => $prices],
                'see_also' => [
                    ['label' => 'Agencies Ireland', 'url' => $agencies],
                    ['label' => 'Digital PR Ireland', 'url' => $pr],
                    ['label' => 'Ireland guide', 'url' => $guide],
                ],
            ],
            'pricing-ireland' => [
                'kicker' => 'EUR, per listing',
                'h1' => 'Guest post and backlink prices in Ireland',
                'subtitle' => 'There is no invented Irish rate card. You pay the listing price in euro, or a managed digital-PR package shown on the main pricing page.',
                'meta_title' => 'Guest post cost Ireland | Backlink prices',
                'meta_description' => 'How much guest posts cost in Ireland: per-site EUR prices from the catalog, plus managed digital-PR packages. No invented Irish VAT line.',
                'teaser_countries' => ['ie'],
                'teaser_title' => 'Live “from” prices on Irish rows',
                'teaser_subtitle' => 'Lowest current advertiser price on verified Ireland-primary listings when the catalog has them.',
                'intro' => [
                    '«How much do guest posts cost Ireland», «backlink prices Ireland» and «link building packages Ireland» follow the same model as the rest of the marketplace: the site’s euro price, visible after you register.',
                    'Headquarters is London (Topurlz Ltd). We do not invent an Irish VAT number or a Dublin surcharge. Managed packages on <a href="'.url('/pricing').'">the UK pricing page</a> are team-run digital PR, not a bag of anonymous Irish URLs.',
                ],
                'points' => [
                    [
                        'title' => 'Per publication',
                        'body' => 'Each Irish listing has its own checkout amount. Metrics, niche and homepage extras move the quote.',
                    ],
                    [
                        'title' => 'Packages',
                        'body' => 'Numbered packages are managed campaigns. Amounts are those currently shown on the public pricing page — we do not restyle them as an Ireland-only menu.',
                    ],
                    [
                        'title' => 'What we do not promise',
                        'body' => 'No guaranteed rankings, traffic or indexing. You buy a publication and a live URL.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Where the live amounts are',
                        'body' => 'This page explains the model. Current figures sit on catalog rows after login and in the Ireland preview when listings exist. <a href="'.$market.'">Ireland marketplace</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'How much do guest posts in Ireland cost?',
                        'a' => 'Whatever the listing shows in euro. There is no single Irish average published here.',
                    ],
                    [
                        'q' => 'Can I pay in euro?',
                        'a' => 'Yes. The wallet and checkout are EUR.',
                    ],
                ],
                'cta_primary' => ['label' => 'See Irish listings', 'url' => $register],
                'cta_secondary' => ['label' => 'UK pricing model', 'url' => url('/pricing')],
                'see_also' => [
                    ['label' => 'Buy guest posts Ireland', 'url' => $guest],
                    ['label' => 'Link building Ireland', 'url' => $lb],
                    ['label' => 'Ireland marketplace', 'url' => $market],
                ],
            ],
            'agencies-ireland' => [
                'kicker' => 'B2B account',
                'h1' => 'White label link building for agencies in Ireland',
                'subtitle' => 'Self-serve catalog for SEO agencies, resellers and teams who rebill. EUR balance, tracked orders, invoices in advertiser billing.',
                'meta_title' => 'White label link building Ireland | Agencies',
                'meta_description' => 'Guest posts for agencies in Ireland: EUR catalog, invoices, orders per brand — without a reseller portal wearing your logo.',
                'teaser_countries' => ['ie'],
                'teaser_title' => 'Inventory you can rebill',
                'teaser_subtitle' => 'Same Ireland-primary rows as an in-house advertiser. The account is yours; brands live in projects and orders.',
                'intro' => [
                    '«White label link building Ireland» and «guest posts for agencies» seek a party that fulfils. Here the agency keeps the wheel: you pick Irish sites, pay, and deliver the live URL to the client.',
                    'Operational white label means the end client does not need an account. It is not a reseller programme with your brand on the public site.',
                ],
                'points' => [
                    [
                        'title' => 'One balance, several campaigns',
                        'body' => 'You add euro (card or transfer when the method is active) and spread the balance across orders.',
                    ],
                    [
                        'title' => 'Order and invoice',
                        'body' => 'Invoices for deposits or orders come from advertiser billing when the product issues them. Company details are British (Topurlz Ltd). We do not invent Irish VAT.',
                    ],
                    [
                        'title' => 'SEO team workspace',
                        'body' => 'Filters, metrics, order chat and live URL. After login the dashboard is English for every role.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Agency versus marketplace',
                        'body' => 'An agency picks sites for the client. A marketplace shows sites to the buyer. SEOLinkBuildings is the latter. If your team is the agency, selection stays with you and the catalog is the source.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Can agencies use the Ireland marketplace?',
                        'a' => 'Yes. Register an advertiser account, filter Ireland, and rebill from your own process. We do not host a white-label portal.',
                    ],
                    [
                        'q' => 'Do you issue invoices with an Irish VAT number?',
                        'a' => 'Billing follows the UK company. Download the documents and check with your accountant. We do not invent CRO or VAT numbers.',
                    ],
                ],
                'cta_primary' => ['label' => 'Create an agency account', 'url' => $register],
                'cta_secondary' => ['label' => 'Ireland marketplace', 'url' => $market],
                'see_also' => [
                    ['label' => 'Link building Ireland', 'url' => $lb],
                    ['label' => 'Ireland pricing', 'url' => $prices],
                    ['label' => 'Digital PR Ireland', 'url' => $pr],
                ],
            ],
            'niche-edits-ireland' => [
                'kicker' => 'Not a separate SKU',
                'h1' => 'Niche edits in Ireland — and what we sell',
                'subtitle' => 'Link insertions in an already published article are not a SKU on SEOLinkBuildings. Here is the line versus a guest post, plus the risks.',
                'meta_title' => 'Niche edits and link insertions Ireland | Explained',
                'meta_description' => 'What niche edits and link insertions in Ireland are, when they are risky, and why we sell new editorial publications — not inserts into someone else’s article.',
                'teaser_countries' => ['ie'],
                'teaser_title' => 'Editorial sites, not insertion networks',
                'teaser_subtitle' => 'Preview of active Ireland-primary rows. The default product is a new article with a link in the copy.',
                'intro' => [
                    'A niche edit is a link in an already published article, often because the URL is already indexed. «Link insertions Ireland» is that query.',
                    'We do not sell it as a product. The default order is a new publication (guest post or sponsored article) with a brief and a live URL. Some sites offer a homepage extra with a time limit; that is on the listing.',
                ],
                'points' => [
                    [
                        'title' => 'Why we do not sell it',
                        'body' => 'A link in an article you did not write is harder to control and more often against the site’s own rules. We will not promise a SKU we cannot deliver consistently.',
                    ],
                    [
                        'title' => 'What you can buy instead',
                        'body' => 'A <a href="'.$guest.'">guest post</a> or <a href="'.$sponsored.'">sponsored post</a> with the anchor in the new copy.',
                    ],
                    [
                        'title' => 'Risk',
                        'body' => 'Inserts in old articles can vanish, change attribute or hit irrelevant anchors. Read the <a href="'.$guide.'">Ireland guide</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Contextual backlinks',
                        'body' => 'A contextual link in a new guest post is still an editorial link. The difference is that you know the brief and get the live URL on the order. <a href="'.$links.'">Buy backlinks in Ireland</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Can I ask the publisher to insert into an old article?',
                        'a' => 'Only if the listing describes it. It is not our default SKU.',
                    ],
                ],
                'cta_primary' => ['label' => 'Buy a guest post instead', 'url' => $guest],
                'cta_secondary' => ['label' => 'Ireland marketplace', 'url' => $market],
                'see_also' => [
                    ['label' => 'Link building Ireland', 'url' => $lb],
                    ['label' => 'Buy guest posts Ireland', 'url' => $guest],
                    ['label' => 'Sponsored posts Ireland', 'url' => $sponsored],
                ],
            ],
            'digital-pr-ireland' => [
                'kicker' => 'Digital media PR',
                'h1' => 'Digital PR in Ireland',
                'subtitle' => 'Digital-PR campaigns as publications on marketplace sites, plus managed packages under pricing. No Google News promise.',
                'meta_title' => 'Digital PR Ireland | Dublin media placements',
                'meta_description' => 'Digital PR Ireland and Dublin: catalog publications, EUR wallet, live URL and managed packages — without a News or press-release guarantee.',
                'teaser_countries' => ['ie'],
                'teaser_title' => 'Irish sites in the catalog',
                'teaser_subtitle' => 'Some publishers look like a media kit; not all are a national daily. Niche and language are filters after login.',
                'intro' => [
                    '«Digital PR Ireland», «digital PR Dublin» and «buy press release Ireland» mix PR with link building. Here you buy publications on sites that actually sit in the catalog.',
                    'Managed packages (amounts on the pricing page; today from €499/month on the starter plan if it is still shown) are team fulfilment, not a button that puts you in a national paper.',
                ],
                'points' => [
                    [
                        'title' => 'Media only if they are in the catalog',
                        'body' => 'We do not run a Google News channel. A guest post “in the press” exists only if that site is a listing and accepts the brief.',
                    ],
                    [
                        'title' => 'Brand mentions',
                        'body' => 'A mention can come from a publication. We do not sell “brand mention” as a SKU without a URL.',
                    ],
                    [
                        'title' => 'Campaigns',
                        'body' => 'Self-serve: you pick sites. Managed: packages under pricing.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'PR and SEO, without fluff',
                        'body' => 'A useful publication has readers, context and a link (or mention) that makes sense. It does not replace a news story. Catalog: <a href="'.$market.'">Ireland marketplace</a>. Packages: <a href="'.$prices.'">Ireland pricing</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Do you guarantee an Irish press article?',
                        'a' => 'No. We deliver the URL on the site you ordered, if the publisher accepts.',
                    ],
                    [
                        'q' => 'Is this different from a sponsored post?',
                        'a' => 'The sponsored post is the publication. Digital PR is the campaign. In the marketplace you still pay for the publication. <a href="'.$sponsored.'">Sponsored posts Ireland</a>.',
                    ],
                ],
                'cta_primary' => ['label' => 'See packages and catalog', 'url' => $prices],
                'cta_secondary' => ['label' => 'Register', 'url' => $register],
                'see_also' => [
                    ['label' => 'Sponsored posts Ireland', 'url' => $sponsored],
                    ['label' => 'Agencies Ireland', 'url' => $agencies],
                    ['label' => 'Buy guest posts Ireland', 'url' => $guest],
                ],
            ],
            'guide-ireland' => [
                'kicker' => 'One guide',
                'h1' => 'Guide: guest posts and link building in Ireland',
                'subtitle' => 'What a guest post is, how you buy backlinks, dofollow versus nofollow, anchors, PBN and risks — on one page, not a stack of thin posts.',
                'meta_title' => 'Ireland link building guide | Guest posts and risks',
                'meta_description' => 'Short Ireland guide: what a guest post is, how to buy backlinks, dofollow vs nofollow, anchors, rel sponsored, and why PBN is not our product.',
                'teaser_countries' => ['ie'],
                'teaser_title' => 'From explanation to catalog',
                'teaser_subtitle' => 'After the guide, real sites sit in the Ireland catalog, priced per publication.',
                'intro' => [
                    'This guide covers informational searches (what is link building, how to buy backlinks, are backlinks legal, anchor text) without a new URL per sentence. It is Ireland-focused; UK-generic explainers stay on unprefixed English pages.',
                    'The dashboard after login stays English. This public page is UK-locale English written for the Ireland market.',
                ],
                'points' => [
                    [
                        'title' => 'What is a guest post?',
                        'body' => 'An article on someone else’s site, typically with a link to you, against payment. Here payment is EUR, per order.',
                    ],
                    [
                        'title' => 'Dofollow, nofollow, sponsored',
                        'body' => 'Dofollow typically passes a signal. Nofollow and sponsored mark the link. Google treats rel sponsored as a paid link. Pick what the listing states.',
                    ],
                    [
                        'title' => 'Anchors',
                        'body' => 'An exact-match anchor repeated on many sites is a risky pattern. Vary the wording and keep the anchor relevant to the destination.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'How to buy backlinks',
                        'body' => 'Account, balance, country and niche filter, brief, live-URL approval. Ops: <a href="'.$how.'">how it works</a>. Commercial page: <a href="'.$links.'">buy backlinks in Ireland</a>.',
                    ],
                    [
                        'h2' => 'PBN versus guest posts',
                        'body' => 'A PBN is a network you control to send links. We do not sell that. A guest post is a publication on a site with its own readers. If you cannot name the site, it is not this product.',
                    ],
                    [
                        'h2' => 'Risks of buying backlinks',
                        'body' => 'Sites without real traffic, aggressive anchors, links that disappear, missing sponsored marking, inflated metrics. Check the listing and the live URL. We do not promise rankings.',
                    ],
                    [
                        'h2' => 'Strategy, short',
                        'body' => 'A few relevant publications beat a volume of links without context. For fulfilment: <a href="'.$lb.'">link building Ireland</a>, <a href="'.$guest.'">buy guest posts</a>, <a href="'.$publisher.'">become a publisher</a> if you sell space.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Is this a 2026 link-building trend calendar?',
                        'a' => 'No. It explains the marketplace’s current rules, not a fashion year.',
                    ],
                    [
                        'q' => 'Where are the prices?',
                        'a' => 'The model is on <a href="'.$prices.'">Ireland pricing</a>. Live amounts are in the catalog after you register.',
                    ],
                ],
                'cta_primary' => ['label' => 'See Irish inventory', 'url' => $register],
                'cta_secondary' => ['label' => 'Buy guest posts in Ireland', 'url' => $guest],
                'see_also' => [
                    ['label' => 'Link building Ireland', 'url' => $lb],
                    ['label' => 'Buy backlinks Ireland', 'url' => $links],
                    ['label' => 'Ireland pricing', 'url' => $prices],
                ],
            ],
        ];
    }
}
