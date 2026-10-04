<?php

namespace App\Support;

/**
 * English /faq copy. Questions that still needed a guessed answer are omitted.
 * Visible text and FAQPage JSON-LD both read this list.
 */
class PublicFaq
{
    /**
     * @return list<array{q: string, a: string}>
     */
    public static function items(): array
    {
        return [
            [
                'q' => 'What is SEOLinkBuildings?',
                'a' => 'SEOLinkBuildings is a European guest post and link building marketplace with verified publishers. Advertisers, SEO agencies and in-house teams use it to buy guest posts on reviewed websites in Germany, the UK, the Nordics and across Europe, with prices in EUR. Publishers use it to sell placements on their sites. The marketplace is run by a UK-registered company based in London.',
            ],
            [
                'q' => 'Is SEOLinkBuildings a legitimate company?',
                'a' => 'Yes. SEOLinkBuildings is run by a private limited company registered in England and Wales, with a registered address at 20 Wenlock Road, London N1 7GU. Every publisher is reviewed before listing, and buyer funds are held until the buyer approves the live URL.',
            ],
            [
                'q' => 'How does buying a guest post on SEOLinkBuildings work?',
                'a' => 'You create an advertiser account, add funds to your EUR wallet, and browse the marketplace. You can filter publishers by country, language, niche and SEO metrics, see each price before checkout, and place an order. The publisher then publishes your article, you review the live URL, and you approve it. Your payment is released to the publisher only after that approval.',
            ],
            [
                'q' => 'How are publishers verified on SEOLinkBuildings?',
                'a' => 'Every publisher website is reviewed by the SEOLinkBuildings team before it can earn a Verified badge and appear in the marketplace. The review is there to make sure every listed site is suitable for editorial guest posts. Sites that don\'t meet the standard are not listed.',
            ],
            [
                'q' => 'Does SEOLinkBuildings sell PBN links?',
                'a' => 'No. SEOLinkBuildings does not list private blog networks (PBNs) and does not sell mystery link packages. Every site in the marketplace is reviewed before it earns a Verified badge, and you always see the site details and price before you order. That way you know exactly where your guest post will be published.',
            ],
            [
                'q' => 'What currency are prices shown in?',
                'a' => 'Prices on SEOLinkBuildings are set in euros (EUR). You fund your wallet in EUR, each publisher listing shows its price in EUR before checkout, and the managed Digital PR plans are billed in EUR. This makes it easy to compare publishers across European countries without converting between local currencies.',
            ],
            [
                'q' => 'How much does a guest post on a German website cost?',
                'a' => 'Verified German listings on SEOLinkBuildings start at €52. Each publisher sets its own price, so the cost depends on the site\'s audience, authority, niche and the type of placement. You can see every price in EUR before you order, and filter German, Austrian and Swiss publishers separately if you are targeting the DACH market.',
            ],
            [
                'q' => 'How does buyer protection work?',
                'a' => 'When you place an order, your money is held and not paid to the publisher straight away. Once the publisher delivers, you check the live URL and approve it, and only then are the funds released. If you don\'t respond, the order is approved automatically after 72 hours. This gives you time to check the link is live and correct.',
            ],
            [
                'q' => 'What happens if a publisher doesn\'t deliver or the link is wrong?',
                'a' => 'Because your funds are held until you approve the live URL, you don\'t pay for an order that wasn\'t delivered as agreed. Don\'t approve an order until the live URL matches what you ordered. You can raise any problem with the publisher in the order chat first, and contact support@seolinkbuildings.com if it isn\'t resolved.',
            ],
            [
                'q' => 'How long does it take for a guest post to go live?',
                'a' => 'Delivery time depends on the publisher, and each listing shows its own turnaround time before you order. Check this number when you compare sites, especially if you have a campaign deadline. You can track every order in your dashboard until the live URL is delivered, and your funds stay held until you approve it.',
            ],
            [
                'q' => 'Are the links dofollow?',
                'a' => 'Each listing shows its link attributes and placement type, such as a sponsored post or a partner article, before you order. Some European publishers are required to label paid content, so always check the listing details if the link attribute matters for your campaign.',
            ],
            [
                'q' => 'Which countries and markets does SEOLinkBuildings cover?',
                'a' => 'SEOLinkBuildings focuses on Europe, including the DACH region, the Nordics and the UK. The site has dedicated pages for 18 countries: Germany, the UK, Austria, Switzerland, France, Italy, Spain, Portugal, the Netherlands, Denmark, Sweden, Norway, Poland, Romania, Greece, Bulgaria, Hungary and Estonia.',
            ],
            [
                'q' => 'Can I buy German-language links for Germany, Austria and Switzerland?',
                'a' => 'Yes. SEOLinkBuildings has dedicated marketplace pages and filters for Germany, Austria and Switzerland, so you can find German-language publishers for each DACH market. Verified German listings start at €52, prices are shown in EUR, and every publisher is reviewed before it appears. You can filter further by niche and SEO metrics to find relevant sites.',
            ],
            [
                'q' => 'What are the Digital PR plans and what do they cost?',
                'a' => 'SEOLinkBuildings offers three managed Digital PR plans: Starter at €499 a month, Growth at €1,499 a month and Authority at €2,799 a month. Instead of choosing sites yourself, the team plans and places editorial coverage for you on relevant publications.',
            ],
            [
                'q' => 'How do I sell guest posts on my website through SEOLinkBuildings?',
                'a' => 'Create a publisher account, add your website, and set your price for each type of placement. The SEOLinkBuildings team reviews your site, and once approved it gets a Verified badge and appears in the marketplace. Advertisers can then order placements, and you publish the content, submit the live URL, and receive your earnings after the buyer approves.',
            ],
            [
                'q' => 'What does a website need to be accepted as a publisher?',
                'a' => 'Your site needs to pass the SEOLinkBuildings review before it is listed and earns the Verified badge. The review makes sure your site is suitable for editorial guest posts, and private blog networks (PBNs) are not accepted. Approved sites appear in the marketplace, where buyers can filter by country, language and niche.',
            ],
            [
                'q' => 'How and when do publishers get paid?',
                'a' => 'Publishers are paid for each completed order. After you publish the content and submit the live URL, the buyer approves it, or the order is approved automatically after 72 hours. The funds are then added to your publisher balance, and you can withdraw your earnings in EUR.',
            ],
            [
                'q' => 'Do publishers set their own prices?',
                'a' => 'Yes. Publishers set their own price for each listing on SEOLinkBuildings, and the price is shown to buyers in EUR. You can price different placement types separately, for example a sponsored post or a partner article. Clear, realistic prices help buyers compare your site with others in your country and niche.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $pageUrl = function_exists('localized_url') ? localized_url('faq') : url('/faq');
        $orgId = rtrim((string) url('/'), '/').'/#organization';
        $siteId = rtrim((string) url('/'), '/').'/#website';

        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            '@id' => rtrim((string) $pageUrl, '/').'#faq',
            'url' => $pageUrl,
            'inLanguage' => class_exists(PublicI18n::class) ? PublicI18n::htmlLang() : 'en-GB',
            'isPartOf' => ['@id' => $siteId],
            'about' => ['@id' => $orgId],
            'mainEntity' => array_map(static function (array $item): array {
                return [
                    '@type' => 'Question',
                    'name' => $item['q'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $item['a'],
                    ],
                ];
            }, self::items()),
        ];
    }
}
