<?php

namespace App\Support;

/**
 * Netherlands-only money / B2B marketing landers (Dutch as used by SEO teams).
 * Marketplace stays /nl/marktplaats and pricing stays /nl/prijzen (existing public slugs).
 * Shared slugs (linkbuilding, digital-pr, niche-edits) are indexable on /nl;
 * unprefixed canonicals stay with Germany or Italy.
 */
class DutchMoneyLanders
{
    public const LOCALE = 'nl';

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_keys(self::pages());
    }

    /**
     * Research URL aliases → Dutch canonicals (no extra indexable twins).
     *
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return [
            'marketplace' => '/nl/marktplaats',
            'marktplaats-gastblogs' => '/nl/marktplaats',
            'catalogus-publishers' => '/nl/marktplaats',
            'guest-posting-sites-nederland' => '/nl/gastblog-kopen',
            'publiceren-op-blogs' => '/nl/gastblog-kopen',
            'guest-post-kopen' => '/nl/gastblog-kopen',
            'gastblog-nederland' => '/nl/gastblog-kopen',
            'gastblog-blogs-nederland' => '/nl/gastblog-kopen',
            'artikel-laten-plaatsen' => '/nl/gesponsord-artikel',
            'advertorial-kopen' => '/nl/gesponsord-artikel',
            'backlink-kopen' => '/nl/backlinks-kopen',
            'nederlandse-backlinks' => '/nl/backlinks-kopen',
            'links-kopen-seo' => '/nl/backlinks-kopen',
            'prijs-guest-post' => '/nl/prijzen',
            'prijslijst-linkbuilding' => '/nl/prijzen',
            'kosten-linkbuilding' => '/nl/prijzen',
            'white-label-linkbuilding' => '/nl/bureaus',
            'linkbuilding-bureau' => '/nl/bureaus',
            'persbericht-kopen' => '/nl/digital-pr',
            'persbericht-seo' => '/nl/digital-pr',
            'link-invoegen' => '/nl/niche-edits',
            'gids-linkbuilding' => '/nl/gids',
        ];
    }

    public static function isSlug(string $segment): bool
    {
        $segment = trim($segment, '/');

        return $segment !== '' && in_array($segment, self::slugs(), true);
    }

    public static function isPublicSegment(string $segment): bool
    {
        $segment = trim($segment, '/');
        if ($segment === '') {
            return false;
        }

        return self::isSlug($segment) || array_key_exists($segment, self::aliases());
    }

    /**
     * @return list<string>
     */
    public static function copyRedirectLocales(): array
    {
        return ['nl'];
    }

    public static function capturesLocaleCopy(string $locale, string $segment): bool
    {
        $locale = strtolower(trim($locale));

        return in_array($locale, self::copyRedirectLocales(), true)
            && self::isPublicSegment($segment);
    }

    /**
     * @return list<string>
     */
    public static function publicSegments(): array
    {
        return array_values(array_unique(array_merge(
            self::slugs(),
            array_keys(self::aliases())
        )));
    }

    public static function url(string $slug): string
    {
        return url('/nl/'.$slug);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $slug): ?array
    {
        $slug = trim($slug, '/');

        return self::pages()[$slug] ?? null;
    }

    /**
     * @return list<array{slug: string, label: string, url: string}>
     */
    public static function clusterLinks(?string $current = null): array
    {
        $items = [
            ['slug' => 'home', 'label' => 'Marktplaats Nederland', 'url' => url('/nl')],
            ['slug' => 'gastblog-kopen', 'label' => 'Gastblog kopen', 'url' => self::url('gastblog-kopen')],
            ['slug' => 'gesponsord-artikel', 'label' => 'Gesponsord artikel', 'url' => self::url('gesponsord-artikel')],
            ['slug' => 'marktplaats', 'label' => 'Catalogus publishers', 'url' => url('/nl/marktplaats')],
            ['slug' => 'linkbuilding', 'label' => 'Linkbuilding', 'url' => self::url('linkbuilding')],
            ['slug' => 'backlinks-kopen', 'label' => 'Backlinks kopen', 'url' => self::url('backlinks-kopen')],
            ['slug' => 'prijzen', 'label' => 'Prijzen', 'url' => url('/nl/prijzen')],
            ['slug' => 'bureaus', 'label' => 'Voor bureaus', 'url' => self::url('bureaus')],
            ['slug' => 'digital-pr', 'label' => 'Digital PR', 'url' => self::url('digital-pr')],
            ['slug' => 'niche-edits', 'label' => 'Niche edits', 'url' => self::url('niche-edits')],
            ['slug' => 'gids', 'label' => 'Gids', 'url' => self::url('gids')],
        ];

        if ($current === null || $current === '') {
            return $items;
        }

        return array_values(array_filter(
            $items,
            static fn (array $item) => $item['slug'] !== $current
        ));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function pages(): array
    {
        $marktplaats = '/nl/marktplaats';
        $prijzen = '/nl/prijzen';
        $how = '/nl/hoe-het-werkt';
        $register = '/register';
        $guest = '/nl/gastblog-kopen';
        $sponsored = '/nl/gesponsord-artikel';
        $links = '/nl/backlinks-kopen';
        $lb = '/nl/linkbuilding';
        $agencies = '/nl/bureaus';
        $pr = '/nl/digital-pr';
        $niche = '/nl/niche-edits';
        $gids = '/nl/gids';
        $publisher = '/nl/publisher-worden';

        return [
            'gastblog-kopen' => [
                'kicker' => 'Gastblog met backlink',
                'h1' => 'Gastblog kopen op Nederlandse sites',
                'subtitle' => 'Kies geverifieerde Nederlandse en Europese publishers, vergelijk niche, DA/DR en de prijs in euro, stuur de briefing en volg de live-URL in de bestelling.',
                'meta_title' => 'Gastblog kopen in Nederland | SEOLinkBuildings',
                'meta_description' => 'Gastblog kopen op blogs en sites in Nederland: filter niche, DA/DR en prijs in EUR, kies dofollow of sponsored en ontvang de live-URL.',
                'teaser_countries' => ['nl'],
                'teaser_title' => 'Sites voor gastblogs in Nederland',
                'teaser_subtitle' => 'Gemaskeerde voorvertoning van actieve catalogusregels in Nederland. Domeinen ziet u na registratie.',
                'intro' => [
                    'SEOLinkBuildings is een self-service marktplaats, geen ondoorzichtig pakket gastblogs. U kiest de site — vaak .nl, vaak in het Nederlands —, betaalt in euro vanuit de portefeuille en houdt briefing, chat en live-URL in één bestelling. Het hoofdkantoor is in Londen (Topurlz Ltd); we verzinnen geen KvK-nummer in Nederland.',
                    '«Gastblog kopen», «gastblog blogs Nederland» en «guest post kopen» beschrijven dezelfde intentie: een betaalde publicatie op een site die niet van u is, met geschreven regels voor lengte, links en levertijd.',
                ],
                'points' => [
                    [
                        'title' => 'Publishers, geen fantoomlijst',
                        'body' => 'Elke regel is een site met niche, taal, land, DA/DR, opgegeven traffic en prijs. We verkopen geen PBN en geen «pakketten van 50 links».',
                    ],
                    [
                        'title' => 'Zo bestelt u',
                        'body' => 'U maakt een account, filtert Nederland, voegt de site toe en stuurt titel, tekst of briefing plus anker. De publisher levert de live-URL ter goedkeuring.',
                    ],
                    [
                        'title' => 'Dofollow en sponsored',
                        'body' => 'Het linkattribuut staat op de catalogusregel. Veel media markeren betaalde publicaties. Lees het linktype vóór u bestelt — er bestaat geen «dofollow tot elke prijs».',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Traffic, niche en permanente plaatsing',
                        'body' => 'Een gastblog met traffic betekent dat de catalogusregel de door de publisher opgegeven traffic toont, geen garantie op bezoekers. Niches (gezondheid, finance, tech, verzekeringen, vastgoed, fintech, travel, marketing, ecommerce) filtert u in de catalogus, niet op aparte URL’s. «Permanent» hangt van de siteregels af: sommige publicaties blijven, andere hebben een termijn. Lees de catalogusregel.',
                    ],
                    [
                        'h2' => 'DA en DR, zonder gedwongen zinnen',
                        'body' => 'U kunt sorteren op DA en DR wanneer die metrics op de regel staan. Een gastblog met een hoge DA of een hoge DR is geen apart product en belooft geen posities. Ontbrekende metrics blijven leeg — we verzinnen ze niet.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kan ik alleen publiceren op .nl-sites?',
                        'a' => 'Ja. U filtert het land Nederland. De Europese catalogus blijft beschikbaar in dezelfde EUR-portefeuille. België is een apart filter.',
                    ],
                    [
                        'q' => 'Schrijven jullie het artikel?',
                        'a' => 'De standaardbestelling gebruikt uw briefing. Sommige catalogusregels bieden redactie; dat ziet u op de site, niet als een hier verzonnen dienst.',
                    ],
                    [
                        'q' => 'Zijn er pagina’s voor Amsterdam of Rotterdam?',
                        'a' => 'Nee. Steden hebben geen eigen URL. U filtert het land.',
                    ],
                ],
                'cta_primary' => ['label' => 'Maak een account en bekijk publishers', 'url' => $register],
                'cta_secondary' => ['label' => 'Catalogus publishers', 'url' => $marktplaats],
                'see_also' => [
                    ['label' => 'Backlinks kopen', 'url' => $links],
                    ['label' => 'Gesponsord artikel', 'url' => $sponsored],
                    ['label' => 'Prijzen', 'url' => $prijzen],
                    ['label' => 'Gids', 'url' => $gids],
                ],
            ],
            'gesponsord-artikel' => [
                'kicker' => 'Advertorial',
                'h1' => 'Gesponsord artikel en advertorial in Nederland',
                'subtitle' => 'U plaatst een betaald artikel op een site uit de catalogus, met prijs in euro en live-URL. We verkopen geen generiek persbericht en geen belofte van nieuwsdekking.',
                'meta_title' => 'Gesponsord artikel kopen | SEOLinkBuildings',
                'meta_description' => 'Gesponsord artikel of advertorial kopen op sites in Nederland. Prijs in EUR per catalogusregel, briefing, live-URL — zonder verzonnen persbureau.',
                'teaser_countries' => ['nl'],
                'teaser_title' => 'Media voor gastblog en advertorial',
                'teaser_subtitle' => 'Dezelfde voorvertoning van publishers in Nederland. Een advertorial bestaat alleen als de site is opgenomen en de briefing accepteert.',
                'intro' => [
                    '«Gesponsord artikel kopen», «artikel laten plaatsen» en «advertorial kopen» beschrijven een betaalde publicatie, geen perskanaal dat we niet hebben. Staat het domein niet in de catalogus, dan verkopen we het niet.',
                    'Native advertising voor SEO is hier hetzelfde traject: u kiest de publicatie, betaalt in EUR en ontvangt de URL. We beloven geen verschijning in Google News.',
                ],
                'points' => [
                    [
                        'title' => 'Markering als gesponsord',
                        'body' => 'Veel sites vragen rel sponsored of een zichtbaar label. Volg de regel op de catalogusregel.',
                    ],
                    [
                        'title' => 'Het tarief',
                        'body' => 'Wat een gesponsord artikel kost, hangt van de site af. Zie <a href="'.$prijzen.'">de prijzen</a> voor het model en de catalogus voor de actuele bedragen.',
                    ],
                    [
                        'title' => 'Content',
                        'body' => 'U stuurt de tekst of de briefing. De publisher publiceert op de eigen site en stuurt u de live-URL.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Advertorial versus gastblog',
                        'body' => 'In de praktijk zijn beide een betaalde publicatie met een link. Het verschil is redactioneel: de advertorial lijkt vaker op een merkvermelding. Het afrekenen is hetzelfde. <a href="'.$guest.'">Gastblog kopen</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Is er één tarief voor een advertorial?',
                        'a' => 'Nee. Elke catalogusregel heeft de eigen prijs, in euro.',
                    ],
                    [
                        'q' => 'Plaatsen jullie op elke krant?',
                        'a' => 'Alleen op sites die in de catalogus staan en de bestelling accepteren.',
                    ],
                ],
                'cta_primary' => ['label' => 'Bekijk sites in Nederland', 'url' => $register],
                'cta_secondary' => ['label' => 'Digital PR', 'url' => $pr],
                'see_also' => [
                    ['label' => 'Digital PR', 'url' => $pr],
                    ['label' => 'Prijzen', 'url' => $prijzen],
                    ['label' => 'Gastblog kopen', 'url' => $guest],
                ],
            ],
            'backlinks-kopen' => [
                'kicker' => 'Redactionele backlinks',
                'h1' => 'Backlinks kopen in Nederland',
                'subtitle' => 'Backlinks hier zijn links uit publicaties die u koopt op echte sites, geen anoniem pakket URL’s.',
                'meta_title' => 'Backlinks kopen in Nederland | SEOLinkBuildings',
                'meta_description' => 'Nederlandse backlinks uit gastblogs op echte sites. Prijs in EUR, dofollow of sponsored op de catalogusregel, live-URL in de bestelling.',
                'teaser_countries' => ['nl'],
                'teaser_title' => 'Sites .nl voor backlinks',
                'teaser_subtitle' => 'Voorvertoning van catalogusregels met hoofdland Nederland. Getoonde traffic is opgegeven, geen belofte.',
                'intro' => [
                    '«Backlinks kopen», «backlink kopen» en «links kopen seo» zoeken hetzelfde: een link op een gepubliceerde pagina. Bij ons krijgt u die via een gastblog of een gesponsord artikel, met het anker uit de briefing.',
                    'Thematische backlinks betekent dat u de niche van de site kiest. «Met echt verkeer» betekent dat u naar de traffic op de catalogusregel kijkt, niet dat we bezoekers garanderen.',
                ],
                'points' => [
                    [
                        'title' => 'Kwaliteit die u kunt lezen',
                        'body' => 'Land, taal, niche, DA/DR en prijs staan op de regel. We verkopen geen «kwalitatieve backlinks» als etiket zonder site.',
                    ],
                    [
                        'title' => 'Dofollow is niet standaard',
                        'body' => 'Filter op linktype. De publisher blijft verantwoordelijk voor de live HTML.',
                    ],
                    [
                        'title' => 'Geen PBN',
                        'body' => 'We verkopen geen private netwerken. Risico’s staan in de <a href="'.$gids.'">gids</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Zo kiest u',
                        'body' => 'Start in de <a href="'.$marktplaats.'">catalogus</a>, filter Nederland, vergelijk de prijs en de ankerregels. Daarna <a href="'.$guest.'">gastblog kopen</a> of een <a href="'.$sponsored.'">gesponsord artikel</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kan ik alleen dofollow kopen?',
                        'a' => 'U kunt filteren op aanbod dat dofollow vermeldt. Controleer het attribuut op de live pagina.',
                    ],
                    [
                        'q' => 'Plaatsen jullie een link in bestaande artikelen?',
                        'a' => 'Niet als SKU voor niche edits. Sommige sites verkopen een homepage-extra, met termijn.',
                    ],
                    [
                        'q' => 'Wat kosten backlinks in Nederland?',
                        'a' => 'Hangt van de site af. <a href="'.$prijzen.'">Prijzen</a> legt het model uit; de catalogus toont de actuele bedragen.',
                    ],
                ],
                'cta_primary' => ['label' => 'Vergelijk de sites', 'url' => $register],
                'cta_secondary' => ['label' => 'Linkbuilding', 'url' => $lb],
                'see_also' => [
                    ['label' => 'Prijzen', 'url' => $prijzen],
                    ['label' => 'Gastblog kopen', 'url' => $guest],
                    ['label' => 'Niche edits', 'url' => $niche],
                ],
            ],
            'linkbuilding' => [
                'kicker' => 'Campagnes',
                'h1' => 'Linkbuilding in Nederland',
                'subtitle' => 'U bouwt de campagne uit de catalogus, publicatie per publicatie, of kiest een beheerd digital-PR-pakket bij de prijzen. Geen «goedkope backlinks» als aanbod zonder site.',
                'meta_title' => 'Linkbuilding in Nederland | SEOLinkBuildings',
                'meta_description' => 'Linkbuilding Nederland: self-service catalogus in EUR, gevolgde bestellingen en digital-PR-pakketten. Geen anonieme linkpakketten.',
                'teaser_countries' => ['nl'],
                'teaser_title' => 'Aanbod voor campagnes in Nederland',
                'teaser_subtitle' => 'Dezelfde catalogusregels als op de gastblogpagina. Europa filtert u apart, na inloggen.',
                'intro' => [
                    'Linkbuilding uitbesteden betekent hier dat u publishers kiest, betaalt en de URL volgt. Het is geen abonnement dat «SEO doet» in uw plaats.',
                    'Linkbuilding in Europa gebruikt dezelfde portefeuille. Nederland is het landfilter, geen apart product. Een linkbuilding bureau Nederland dat intern werkt, kan dezelfde catalogus gebruiken.',
                ],
                'points' => [
                    [
                        'title' => 'Self-service',
                        'body' => 'Strategie is uw selectie van sites, ankers en ritme. De catalogus is de bron. Maandelijkse linkbuilding is het tempo dat u zelf zet.',
                    ],
                    [
                        'title' => 'Linkbuilding pakketten',
                        'body' => 'De genummerde pakketten op de prijzenpagina zijn beheerde digital-PR-campagnes, geen zak URL’s.',
                    ],
                    [
                        'title' => 'White label',
                        'body' => 'Het bureau kan bestellen in het eigen account. We leveren geen portaal met uw logo. Details: <a href="'.$agencies.'">voor bureaus</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Gastblog, niche edits en digital PR',
                        'body' => 'Een gastblog is een nieuw artikel. Niche edits (een link in bestaande content) verkopen we niet als SKU. Digital PR is de campagne; in de marktplaats betaalt u nog steeds de publicatie. Publisher-contact bij self-service doet u zelf via briefing en catalogus.',
                    ],
                    [
                        'h2' => 'Zo start een campagne',
                        'body' => 'Account, tegoed in EUR, filter Nederland, bestelling. Het traject: <a href="'.$how.'">hoe het werkt</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Hebben jullie goedkope linkbuilding?',
                        'a' => 'De prijs is die van de catalogusregel. We hebben geen apart tarief «goedkoop» naast de catalogus.',
                    ],
                    [
                        'q' => 'Doen jullie ook de strategie?',
                        'a' => 'De gids legt risico’s en ankers uit. De self-service-uitvoering blijft bij u.',
                    ],
                ],
                'cta_primary' => ['label' => 'Open de catalogus', 'url' => $register],
                'cta_secondary' => ['label' => 'Bekijk de prijzen', 'url' => $prijzen],
                'see_also' => [
                    ['label' => 'Voor bureaus', 'url' => $agencies],
                    ['label' => 'Digital PR', 'url' => $pr],
                    ['label' => 'Gids', 'url' => $gids],
                ],
            ],
            'bureaus' => [
                'kicker' => 'B2B-account',
                'h1' => 'Linkbuilding voor bureaus in Nederland',
                'subtitle' => 'Self-service catalogus voor SEO-bureaus, resellers en teams die doorbelasten. EUR-portefeuille, gevolgde bestellingen, facturen in de adverteerdersfacturatie.',
                'meta_title' => 'Linkbuilding voor bureaus | SEOLinkBuildings',
                'meta_description' => 'Gastblog voor bureaus in Nederland: catalogus in EUR, facturen, bestellingen per merk — zonder resellerportaal met uw logo.',
                'teaser_countries' => ['nl'],
                'teaser_title' => 'Aanbod dat u kunt doorbelasten',
                'teaser_subtitle' => 'Dezelfde catalogusregels als voor een interne adverteerder. Het account is van u; merken staan in uw projecten en bestellingen.',
                'intro' => [
                    '«Gastblog voor bureaus», «white label linkbuilding» en «linkbuilding uitbesteden» zoeken een partij die uitvoert. Hier houdt het bureau de regie: het kiest de sites, betaalt en levert de live-URL aan de klant.',
                    'Operationeel white label betekent dat de eindklant geen account hoeft. Het is geen resellerprogramma met uw merk op de publieke site.',
                ],
                'points' => [
                    [
                        'title' => 'Eén portefeuille, meerdere campagnes',
                        'body' => 'U laadt euro (kaart of overschrijving, als de methode actief is) en verdeelt het saldo over bestellingen.',
                    ],
                    [
                        'title' => 'Bestelling en factuur',
                        'body' => 'Facturen voor stortingen of bestellingen downloadt u uit de adverteerdersfacturatie, wanneer het product ze uitgeeft. De bedrijfsgegevens zijn die van het VK (Topurlz Ltd), op <a href="/nl/over-ons">Over ons</a> — er is geen verzonnen Nederlandse entiteit.',
                    ],
                    [
                        'title' => 'Werkruimte voor SEO-teams',
                        'body' => 'Filters, metrics, chat op de bestelling en live-URL. Na inloggen is het dashboard Engels voor alle rollen.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Bureau versus marktplaats',
                        'body' => 'Een bureau kiest de sites voor de klant. Een marktplaats toont de sites aan de koper. SEOLinkBuildings is het tweede. Als uw team het bureau is, blijft de selectie bij u en is de catalogus de bron. Extra zichtbaarheid via publicaties bestelt u hier; er is geen aparte dienst zonder catalogus.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kan ik de marktplaats verbergen voor de klant?',
                        'a' => 'Ja, door vanuit uw account te werken. We leveren geen white-labelportaal met uw merk.',
                    ],
                    [
                        'q' => 'Stellen jullie facturen met Nederlands btw-nummer op?',
                        'a' => 'De facturatie volgt de Britse vennootschap van het product. Download de stukken en overleg met de administratie of u andere koppelingen nodig hebt. We verzinnen geen KvK of btw-nummer.',
                    ],
                    [
                        'q' => 'Is er een apart bureautarief?',
                        'a' => 'De prijs is die van de catalogusregel. Er is geen tweede catalogus «voor bureaus».',
                    ],
                ],
                'cta_primary' => ['label' => 'Maak een bureau-account', 'url' => $register],
                'cta_secondary' => ['label' => 'Catalogus', 'url' => $marktplaats],
                'see_also' => [
                    ['label' => 'Linkbuilding', 'url' => $lb],
                    ['label' => 'Prijzen', 'url' => $prijzen],
                    ['label' => 'Digital PR', 'url' => $pr],
                ],
            ],
            'digital-pr' => [
                'kicker' => 'PR in digitale media',
                'h1' => 'Digital PR in Nederland',
                'subtitle' => 'Digital-PR-campagnes als publicaties op sites in de marktplaats, plus beheerde pakketten bij de prijzen. Geen belofte van Google News.',
                'meta_title' => 'Digital PR in Nederland | SEOLinkBuildings',
                'meta_description' => 'Digital PR in Nederland: publicaties uit de catalogus, EUR-portefeuille, live-URL en beheerde pakketten — zonder News- of persbureau-garantie.',
                'teaser_countries' => ['nl'],
                'teaser_title' => 'Nederlandse sites in de catalogus',
                'teaser_subtitle' => 'Sommige publishers lijken op een mediakit; niet alle zijn een dagblad. Niche en taal filtert u na inloggen.',
                'intro' => [
                    '«Digital PR Nederland», «persbericht kopen» en «persbericht seo» mengen PR met linkbuilding. Hier koopt u publicaties op sites die daadwerkelijk in de catalogus staan.',
                    'Beheerde pakketten (bedragen staan bij Prijzen; vandaag vanaf 499 €/maand op het basisplan, als dat nog vermeld is) zijn teamuitvoering, geen knop «verschijn in een landelijke krant». Elk gesponsord artikel is geen Digital PR.',
                ],
                'points' => [
                    [
                        'title' => 'Media alleen als ze in de catalogus staan',
                        'body' => 'We hebben geen Google News-kanaal. Een gastblog «in de pers» bestaat alleen als die site een catalogusregel is en de briefing accepteert. Digitale media voor gastblogs filtert u op niche, niet op een aparte URL.',
                    ],
                    [
                        'title' => 'Merkvermeldingen',
                        'body' => 'Een vermelding kan uit een publicatie komen. We verkopen geen «brand mention» als SKU zonder URL.',
                    ],
                    [
                        'title' => 'Campagnes',
                        'body' => 'Self-service: u kiest de sites. Beheerd: de pakketten op de prijzenpagina.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'PR en SEO, zonder inflatie',
                        'body' => 'Een nuttige publicatie heeft lezers, context en een link (of vermelding) die ergens op slaat. Ze vervangt geen nieuwsbericht. Catalogus: <a href="'.$marktplaats.'">de lijst sites</a>. Pakketten: <a href="'.$prijzen.'">prijzen</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Garanderen jullie een artikel in de pers?',
                        'a' => 'Nee. We leveren de URL op de site die u hebt besteld, als de publisher accepteert.',
                    ],
                    [
                        'q' => 'Is dit anders dan een advertorial?',
                        'a' => 'De advertorial is de publicatie. Digital PR is de campagne. In de marktplaats betaalt u alsnog de publicatie. <a href="'.$sponsored.'">Gesponsord artikel</a>.',
                    ],
                ],
                'cta_primary' => ['label' => 'Bekijk pakketten en catalogus', 'url' => $prijzen],
                'cta_secondary' => ['label' => 'Registreren', 'url' => $register],
                'see_also' => [
                    ['label' => 'Gesponsord artikel', 'url' => $sponsored],
                    ['label' => 'Voor bureaus', 'url' => $agencies],
                    ['label' => 'Gastblog kopen', 'url' => $guest],
                ],
            ],
            'niche-edits' => [
                'kicker' => 'Geen apart product',
                'h1' => 'Niche edits in Nederland — en wat we wél verkopen',
                'subtitle' => 'Niche edits (een link invoegen in een al gepubliceerd artikel) zijn geen SKU op SEOLinkBuildings. Hier staat de grens ten opzichte van een gastblog, plus de risico’s.',
                'meta_title' => 'Niche edits in Nederland uitgelegd | SEOLinkBuildings',
                'meta_description' => 'Wat niche edits en een link invoegen zijn, wanneer dat riskant is, en waarom we in Nederland redactionele publicaties verkopen — geen invoeging in een vreemd artikel.',
                'teaser_countries' => ['nl'],
                'teaser_title' => 'Redactionele sites, geen invoegnetwerken',
                'teaser_subtitle' => 'Voorvertoning van actieve catalogusregels in Nederland. Het standaardproduct is een nieuw artikel met een link in de tekst.',
                'intro' => [
                    'Een niche edit is een link in een al gepubliceerd artikel, vaak omdat de URL al geïndexeerd is. «Link invoegen» zoekt precies dat.',
                    'We verkopen dit niet als product. De standaardbestelling is een nieuwe publicatie (gastblog of gesponsord artikel) met briefing en live-URL. Sommige sites bieden een homepage-extra, met termijn; dat is zichtbaar op de catalogusregel, geen verborgen invoeging in een artikel dat al geïndexeerd is.',
                ],
                'points' => [
                    [
                        'title' => 'Controleer relevantie',
                        'body' => 'Een invoeging in een oude tekst over een ander onderwerp is meestal zwakker dan een nieuw artikel op een passende site.',
                    ],
                    [
                        'title' => 'Risico’s',
                        'body' => 'Onduidelijk eigenaarschap, later gewijzigde ankers, ontbrekende sponsored-markering, invoegnetwerken met hetzelfde patroon.',
                    ],
                    [
                        'title' => 'Wat u hier koopt',
                        'body' => 'Een publicatie met regels, afrekenen in EUR en live-URL. <a href="'.$guest.'">Gastblog kopen</a> of <a href="'.$links.'">backlinks kopen</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Wanneer een niche edit wél zinvol zou zijn',
                        'body' => 'Alleen als het bestaande artikel thematisch past, de publisher redactioneel reageert en de link transparant blijft. We verkopen het niet als volumeverkoop.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kan ik een link in een bestaand artikel vragen?',
                        'a' => 'Alleen als een specifieke catalogusregel dat biedt. Standaard is een nieuw artikel.',
                    ],
                    [
                        'q' => 'Waarom is er geen verkooppagina voor niche edits?',
                        'a' => 'Omdat we het product niet hebben. Een verkooppagina zou misleidend zijn.',
                    ],
                    [
                        'q' => 'Wat is het alternatief?',
                        'a' => 'Gastblogs of gesponsorde artikelen uit de Nederlandse catalogus, met briefing en live-URL.',
                    ],
                ],
                'cta_primary' => ['label' => 'Kies gastblogs', 'url' => $register],
                'cta_secondary' => ['label' => 'Backlinks kopen', 'url' => $links],
                'see_also' => [
                    ['label' => 'Linkbuilding', 'url' => $lb],
                    ['label' => 'Gastblog kopen', 'url' => $guest],
                    ['label' => 'Gesponsord artikel', 'url' => $sponsored],
                ],
            ],
            'gids' => [
                'kicker' => 'Eén gids',
                'h1' => 'Gids gastblog en linkbuilding',
                'subtitle' => 'Wat een gastblog is, hoe u backlinks koopt, dofollow versus nofollow, ankers, PBN en risico’s — op één pagina, niet op tientallen dunne artikelen.',
                'meta_title' => 'Gids gastblog en linkbuilding | SEOLinkBuildings',
                'meta_description' => 'Korte gids: wat een gastblog is, hoe u backlinks koopt, dofollow vs nofollow, ankers, rel sponsored en waarom een PBN ons product niet is.',
                'teaser_countries' => ['nl'],
                'teaser_title' => 'Van uitleg naar catalogus',
                'teaser_subtitle' => 'Na de gids staan de echte sites in de Nederlandse catalogus, met een prijs per publicatie.',
                'intro' => [
                    'Deze gids dekt informatieve vragen (wat is linkbuilding, hoe backlinks kopen, risico’s, anchor text) zonder een nieuwe pagina voor elke zin.',
                    'Het dashboard na inloggen blijft Engels. De publieke pagina is Nederlands.',
                ],
                'points' => [
                    [
                        'title' => 'Wat is een gastblog?',
                        'body' => 'Een artikel op de site van iemand anders, meestal met een link naar u, in ruil voor betaling of ruil. Bij ons is de betaling in EUR, per bestelling.',
                    ],
                    [
                        'title' => 'Dofollow, nofollow, sponsored',
                        'body' => 'Dofollow geeft doorgaans een signaal door. Nofollow en sponsored zeggen het systeem dat de link gemarkeerd is. Google behandelt rel sponsored als een betaalde link. Kies wat de catalogusregel vermeldt.',
                    ],
                    [
                        'title' => 'Ankers',
                        'body' => 'Een exact anker, herhaald op veel sites, is een riskant patroon. Varieer de formulering en houd het anker relevant voor de doelpagina.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Hoe backlinks kopen',
                        'body' => 'Account, tegoed, land- en nichefilter, briefing, goedkeuring van de live-URL. Operationele stappen: <a href="'.$how.'">hoe het werkt</a>. Commerciële pagina: <a href="'.$links.'">backlinks kopen</a>.',
                    ],
                    [
                        'h2' => 'PBN versus gastblog',
                        'body' => 'Een PBN is een netwerk dat u beheert om links door te geven. Dat verkopen we niet. Een gastblog is een publicatie op een site met eigen lezers. Kunt u de site niet noemen, dan is het dit product niet.',
                    ],
                    [
                        'h2' => 'Risico’s van backlinks kopen',
                        'body' => 'Sites zonder echte traffic, agressieve ankers, links die verdwijnen, ontbrekende sponsored-markering, opgeblazen metrics. Controleer de catalogusregel en de live-URL. We beloven geen posities.',
                    ],
                    [
                        'h2' => 'Strategie, kort',
                        'body' => 'Weinige relevante publicaties verslaan een volume links zonder context. Voor uitvoering: <a href="'.$lb.'">linkbuilding</a>, <a href="'.$guest.'">gastblog kopen</a>, <a href="'.$publisher.'">publisher worden</a> als u ruimte verkoopt.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Is dit een gids linkbuilding 2026?',
                        'a' => 'Nee. Dit is een productpagina die de huidige regels van de marktplaats uitlegt, geen trendkalender.',
                    ],
                    [
                        'q' => 'Waar zie ik de prijzen?',
                        'a' => 'Het model staat op <a href="'.$prijzen.'">prijzen</a>. De actuele bedragen staan in de catalogus, na registratie.',
                    ],
                ],
                'cta_primary' => ['label' => 'Bekijk het aanbod', 'url' => $register],
                'cta_secondary' => ['label' => 'Gastblog kopen', 'url' => $guest],
                'see_also' => [
                    ['label' => 'Linkbuilding', 'url' => $lb],
                    ['label' => 'Backlinks kopen', 'url' => $links],
                    ['label' => 'Prijzen', 'url' => $prijzen],
                ],
            ],
        ];
    }
}
