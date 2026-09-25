<?php

namespace App\Support;

/**
 * Belgium money / B2B landers (Belgian Dutch as the public chrome, with
 * French netlinking terms where they are the natural search language).
 * Marketplace stays /be/marketplace and pricing stays /be/prijzen.
 * Unique slugs must not steal NL marktplaats/prijzen/gastblog-kopen/gesponsord-artikel/gids/bureaus
 * or FR marche/tarifs/acheter-* /guide/agences/netlinking. Conversion URLs stay *-belgie.
 */
class BelgianMoneyLanders
{
    use MoneyLanderPages;

    public const LOCALE = 'be';

    /**
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return [
            'marktplaats' => '/be/marketplace',
            'catalogue' => '/be/marketplace',
            'catalogue-publishers' => '/be/marketplace',
            'guest-post-belgium' => '/be/koop-guest-post-belgie',
            'acheter-guest-post-belgique' => '/be/koop-guest-post-belgie',
            'publier-sur-blogs-belgie' => '/be/koop-guest-post-belgie',
            'gesponsord-artikel' => '/be/gesponsord-artikel-belgie',
            'article-sponsorise-belgie' => '/be/gesponsord-artikel-belgie',
            'publiredactionnel' => '/be/gesponsord-artikel-belgie',
            'acheter-publiredactionnel-belgie' => '/be/gesponsord-artikel-belgie',
            'acheter-backlinks-belgique' => '/be/koop-backlinks-belgie',
            'acheter-netlinking' => '/be/linkbuilding',
            'netlinking-belgie' => '/be/linkbuilding',
            'linkbuilding-belgie' => '/be/linkbuilding',
            'prix-belgie' => '/be/prijzen',
            'combien-coute' => '/be/prijzen',
            'bureaus' => '/be/bureaus-belgie',
            'agences-belgie' => '/be/bureaus-belgie',
            'white-label' => '/be/bureaus-belgie',
            'insertion-de-lien' => '/be/niche-edits',
            'link-invoegen-belgie' => '/be/niche-edits',
            'communique-de-presse-belgie' => '/be/digital-pr',
            'gids' => '/be/gids-belgie',
            'guide-belgie' => '/be/gids-belgie',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function ui(): array
    {
        return [
            'cluster_title' => 'Pagina’s over guest posts, backlinks en linkbuilding in België',
            'from' => 'Vanaf',
            'price_note' => 'Laagste huidige prijs in euro op actieve, geverifieerde catalogusregels met hoofdland België. Geen vaste prijslijst.',
            'sites_preview' => 'Sites in voorvertoning',
            'count_note' => 'Actieve, geverifieerde publishers met België als hoofdland, wanneer de telling beschikbaar is.',
            'th_site' => 'Site',
            'th_country' => 'Land',
            'th_language' => 'Taal',
            'th_from' => 'Vanaf',
            'teaser_foot' => 'We tonen DA, DR en de prijs in euro wanneer ze op de catalogusregel staan. Ontbrekende metrics blijven leeg.',
            'see_also' => 'Zie ook',
        ];
    }

    /**
     * @return list<array{slug: string, label: string, url: string}>
     */
    public static function clusterLinks(?string $current = null): array
    {
        return self::filterCluster([
            ['slug' => 'home', 'label' => 'Marktplaats België', 'url' => url('/be')],
            ['slug' => 'koop-guest-post-belgie', 'label' => 'Koop guest posts', 'url' => self::url('koop-guest-post-belgie')],
            ['slug' => 'gesponsord-artikel-belgie', 'label' => 'Gesponsord artikel', 'url' => self::url('gesponsord-artikel-belgie')],
            ['slug' => 'marketplace', 'label' => 'Catalogus publishers', 'url' => self::marketingUrl('marketplace')],
            ['slug' => 'linkbuilding', 'label' => 'Linkbuilding / netlinking', 'url' => self::url('linkbuilding')],
            ['slug' => 'koop-backlinks-belgie', 'label' => 'Koop backlinks', 'url' => self::url('koop-backlinks-belgie')],
            ['slug' => 'prijzen', 'label' => 'Prijzen', 'url' => self::marketingUrl('pricing')],
            ['slug' => 'bureaus-belgie', 'label' => 'Voor bureaus', 'url' => self::url('bureaus-belgie')],
            ['slug' => 'digital-pr', 'label' => 'Digital PR', 'url' => self::url('digital-pr')],
            ['slug' => 'niche-edits', 'label' => 'Niche edits', 'url' => self::url('niche-edits')],
            ['slug' => 'gids-belgie', 'label' => 'Gids', 'url' => self::url('gids-belgie')],
        ], $current);
    }

    /**
     * @return list<array{slug: string, label: string, url: string}>
     */
    public static function footerLinks(): array
    {
        return array_values(array_filter(
            self::clusterLinks('home'),
            static fn (array $item) => ! in_array($item['slug'], ['marketplace', 'prijzen'], true)
        ));
    }

    /**
     * @return array{h2: string, body: string, links: string}
     */
    public static function marketplaceCopy(): array
    {
        return [
            'h2' => 'Catalogus van Belgische publishers',
            'body' => 'Dit is de publieke lijst van publishers met België als hoofdland: niche, taal (Nederlands of Frans), DA/DR en prijs in euro. We indexeren niet elke filtercombinatie, en steden (Brussel, Antwerpen, Gent, Luik) hebben geen eigen URL. Het volledige catalogus met domeinen opent na registratie.',
            'links' => '<a href="'.self::url('koop-guest-post-belgie').'">Koop guest posts in België</a> · <a href="'.self::url('koop-backlinks-belgie').'">Koop backlinks</a> · <a href="'.self::marketingUrl('pricing').'">Wat kost een gesponsord artikel</a> · <a href="'.url('/guest-posts-belgium').'">Belgium inventory (English)</a>',
        ];
    }

    /**
     * @return array{h2: string, body: string}
     */
    public static function pricingCopy(): array
    {
        return [
            'h2' => 'Wat kost een guest post in België',
            'body' => 'We publiceren geen vaste pdf-prijslijst en verzinnen geen Belgisch btw-tarief: de prijs is die van de site, in euro. «Prix guest post», «combien coûte un article sponsorisé» en «linkbuilding prijzen» volgen de catalogusregel die je kiest. De genummerde pakketten hierboven zijn beheerde digital-PR-campagnes, geen zak anonieme URL’s. Actuele bedragen staan in <a href="'.self::marketingUrl('marketplace').'">de Belgische catalogus</a> na registratie. Het hoofdkantoor is in Londen (Topurlz Ltd).',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function pages(): array
    {
        $market = self::marketingUrl('marketplace');
        $prices = self::marketingUrl('pricing');
        $how = self::marketingUrl('how-it-works');
        $register = '/register';
        $guest = self::url('koop-guest-post-belgie');
        $sponsored = self::url('gesponsord-artikel-belgie');
        $links = self::url('koop-backlinks-belgie');
        $lb = self::url('linkbuilding');
        $agencies = self::url('bureaus-belgie');
        $pr = self::url('digital-pr');
        $niche = self::url('niche-edits');
        $guide = self::url('gids-belgie');
        $publisher = self::marketingUrl('become-a-publisher');

        return [
            'koop-guest-post-belgie' => [
                'kicker' => 'Guest posts met backlink',
                'h1' => 'Koop guest posts op Belgische blogs',
                'subtitle' => 'Kies geverifieerde Belgische en Europese publishers, vergelijk niche, taal, DA/DR en de prijs in euro, stuur de briefing en volg de live-URL in de order.',
                'meta_title' => 'Koop guest posts in België op .be-sites | SEOLinkBuildings',
                'meta_description' => 'Koop guest posts op Belgische blogs en .be-sites: filter niche, Nederlands of Frans, DA/DR en EUR-prijs, kies dofollow of sponsored.',
                'teaser_countries' => ['be'],
                'teaser_title' => 'Sites voor guest posts in België',
                'teaser_subtitle' => 'Gemaskeerde voorvertoning van actieve catalogusregels in België. Domeinen zie je na registratie.',
                'intro' => [
                    'SEOLinkBuildings is een self-service marktplaats, geen ondoorzichtig pakket guest posts. Je kiest de site — vaak .be, op Nederlands of Frans — betaalt in euro van de wallet en houdt briefing, chat en live-URL in dezelfde order. Het hoofdkantoor is in Londen (Topurlz Ltd); we verzinnen geen Belgisch ondernemingsnummer.',
                    '«Koop guest post», «publier sur blogs Belgique» en «acheter guest post Belgique» zoeken hetzelfde: een betaalde publicatie op een site die je niet bezit, met geschreven regels voor lengte, links en levering.',
                ],
                'points' => [
                    [
                        'title' => 'Publishers, geen fantomlijst',
                        'body' => 'Elke rij is een site met niche, taal, land, DA/DR, opgegeven traffic en prijs. We verkopen geen PBN en geen «pakketten van 50 links».',
                    ],
                    [
                        'title' => 'Nederlands en Frans',
                        'body' => 'Vlaamse en Franstalige publishers staan in hetzelfde catalogus. De taal staat op de rij — match de briefing. Geen aparte URL per taal of stad.',
                    ],
                    [
                        'title' => 'Zo bestel je',
                        'body' => 'Je maakt een account, filtert België, legt de site in de winkelwagen en stuurt titel, tekst of briefing plus anker. De publisher levert de live-URL ter goedkeuring.',
                    ],
                    [
                        'title' => 'Dofollow en sponsored',
                        'body' => 'Het linkattribuut staat op de catalogusregel. Veel media markeren betaalde publicaties. Lees het linktype voor je bestelt — er is geen «dofollow tot elke prijs».',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Traffic, niche en permanente links',
                        'body' => 'Een guest post «met traffic» betekent dat de catalogusregel de traffic toont die de publisher heeft opgegeven — geen garantie op bezoek. Niches (gezondheid, finance, tech, e-commerce) filter je in het catalogus, niet op eigen URL’s. «Permanent» hangt af van de siteregels.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Wat is een Belgische guest post?',
                        'a' => 'Een betaalde publicatie op een site met hoofdland België, meestal .be, in het Nederlands of Frans, met een link volgens de catalogusregel.',
                    ],
                    [
                        'q' => 'Kan ik guest posts bestellen bij Belgische publishers?',
                        'a' => 'Ja. Filter het land België na registratie. Het Europese catalogus ligt in dezelfde EUR-wallet. Nederland en Frankrijk zijn eigen filters.',
                    ],
                    [
                        'q' => 'Zijn Belgische publishers beschikbaar in het Nederlands en Frans?',
                        'a' => 'Ja, wanneer die taal op de catalogusregel staat. We splitsen geen aparte sites per taal; je filtert op land en taal.',
                    ],
                    [
                        'q' => 'Schrijven jullie het artikel?',
                        'a' => 'De standaardorder gebruikt jouw briefing. Sommige catalogusregels bieden redactie; dat zie je op de site, niet als verzonnen extra hier.',
                    ],
                ],
                'cta_primary' => ['label' => 'Account aanmaken en publishers zien', 'url' => $register],
                'cta_secondary' => ['label' => 'Catalogus publishers', 'url' => $market],
                'see_also' => [
                    ['label' => 'Koop backlinks', 'url' => $links],
                    ['label' => 'Gesponsord artikel', 'url' => $sponsored],
                    ['label' => 'Prijzen', 'url' => $prices],
                    ['label' => 'Gids', 'url' => $guide],
                ],
            ],
            'gesponsord-artikel-belgie' => [
                'kicker' => 'Publirédactionnel',
                'h1' => 'Koop een gesponsord artikel in België',
                'subtitle' => 'Je koopt een betaald artikel op een site in het catalogus, met prijs in euro en live-URL. We verkopen geen generiek persbericht-abonnement.',
                'meta_title' => 'Gesponsord artikel in België | SEOLinkBuildings',
                'meta_description' => 'Koop een gesponsord artikel of publirédactionnel op Belgische sites. Prijs in EUR per catalogusregel, briefing en live-URL.',
                'teaser_countries' => ['be'],
                'teaser_title' => 'Media voor guest posts en advertorial',
                'teaser_subtitle' => 'Zelfde voorvertoning van publishers in België. Een publirédactionnel bestaat alleen als de site in het catalogus ligt.',
                'intro' => [
                    '«Article sponsorisé», «acheter publirédactionnel» en «gesponsord artikel» beschrijven een betaalde publicatie, geen perslijn die we niet hebben. Staat het domein niet in het catalogus, dan verkopen we het niet.',
                    'Native advertising voor SEO is hier hetzelfde traject: je kiest de publicatie, betaalt in EUR en krijgt de URL. We beloven geen Google News.',
                ],
                'points' => [
                    [
                        'title' => 'Markering als gesponsord',
                        'body' => 'Veel sites vragen rel sponsored of een zichtbaar label. Volg de regel op de catalogusregel.',
                    ],
                    [
                        'title' => 'De prijs',
                        'body' => 'Wat een gesponsord artikel kost, hangt af van de site. Zie <a href="'.$prices.'">de prijzen</a> voor het model en het catalogus voor actuele bedragen.',
                    ],
                    [
                        'title' => 'Inhoud',
                        'body' => 'Je stuurt de tekst of briefing. De publisher publiceert op de eigen site en stuurt de live-URL.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Publirédactionnel versus guest post',
                        'body' => 'In de praktijk zijn beide een betaalde publicatie met een link. Het verschil is redactioneel. De afrekening is hetzelfde. <a href="'.$guest.'">Koop guest posts</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Hoeveel kost een gesponsord artikel in België?',
                        'a' => 'Er is geen vast tarief. Elke catalogusregel heeft een eigen prijs, in euro. We verzinnen geen Belgische prijslijst.',
                    ],
                    [
                        'q' => 'Plaatsen jullie op alle Belgische media?',
                        'a' => 'Alleen op sites die in het catalogus liggen en de order aanvaarden.',
                    ],
                ],
                'cta_primary' => ['label' => 'Belgische sites bekijken', 'url' => $register],
                'cta_secondary' => ['label' => 'Digital PR', 'url' => $pr],
                'see_also' => [
                    ['label' => 'Digital PR', 'url' => $pr],
                    ['label' => 'Prijzen', 'url' => $prices],
                    ['label' => 'Koop guest posts', 'url' => $guest],
                ],
            ],
            'koop-backlinks-belgie' => [
                'kicker' => 'Redactionele backlinks',
                'h1' => 'Koop backlinks in België',
                'subtitle' => 'Backlinks hier zijn links uit publicaties die je koopt op echte sites — geen anonieme zak URL’s.',
                'meta_title' => 'Koop backlinks in België | .be publishers',
                'meta_description' => 'Belgische backlinks uit guest posts op echte sites. Prijs in EUR, dofollow of sponsored op de catalogusregel, live-URL in de order.',
                'teaser_countries' => ['be'],
                'teaser_title' => 'Sites .be voor backlinks',
                'teaser_subtitle' => 'Voorvertoning van catalogusregels met hoofdland België. Getoonde traffic is opgegeven, niet beloofd.',
                'intro' => [
                    '«Koop backlinks», «acheter backlinks Belgique» en «backlinks dofollow Belgique» zoeken hetzelfde: een link op een gepubliceerde pagina. Bij ons krijg je dat via een guest post of gesponsord artikel, met het anker uit de briefing.',
                    'Thematische backlinks betekenen dat je de niche van de site kiest. «Met echte traffic» betekent dat je naar traffic op de catalogusregel kijkt — we garanderen geen bezoek.',
                ],
                'points' => [
                    [
                        'title' => 'Kwaliteit die je kunt lezen',
                        'body' => 'Land, taal, niche, DA/DR en prijs staan op de regel. We verkopen geen «kwaliteitsbacklinks» als etiket zonder site.',
                    ],
                    [
                        'title' => 'Dofollow is geen standaard',
                        'body' => 'Filter op linktype. De publisher staat in voor de live HTML.',
                    ],
                    [
                        'title' => 'Geen PBN',
                        'body' => 'We verkopen geen private netwerken. Risico’s staan in de <a href="'.$guide.'">gids</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Zo kies je',
                        'body' => 'Start in <a href="'.$market.'">het catalogus</a>, filter België, vergelijk prijs en ankerregels. Daarna <a href="'.$guest.'">koop guest posts</a> of een <a href="'.$sponsored.'">gesponsord artikel</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kan ik backlinks kopen van Belgische websites?',
                        'a' => 'Ja, via een publicatie op een catalogusregel met hoofdland België. Filter op land na registratie.',
                    ],
                    [
                        'q' => 'Kan ik alleen dofollow kopen?',
                        'a' => 'Je kunt filteren op aanbiedingen die dofollow vermelden. Controleer het attribuut op de live pagina.',
                    ],
                    [
                        'q' => 'Voegen jullie een link in een bestaand artikel in?',
                        'a' => 'Niet als SKU voor niche edits. Sommige sites verkopen een homepage-extra met termijn.',
                    ],
                ],
                'cta_primary' => ['label' => 'Sites vergelijken', 'url' => $register],
                'cta_secondary' => ['label' => 'Linkbuilding', 'url' => $lb],
                'see_also' => [
                    ['label' => 'Prijzen', 'url' => $prices],
                    ['label' => 'Koop guest posts', 'url' => $guest],
                    ['label' => 'Niche edits', 'url' => $niche],
                ],
            ],
            'linkbuilding' => [
                'kicker' => 'Campagnes',
                'h1' => 'Linkbuilding en netlinking in België',
                'subtitle' => 'Je bouwt de campagne uit het catalogus, publicatie per publicatie, of kiest een beheerd digital-PR-pakket onder prijzen. Geen «goedkope backlinks» zonder site.',
                'meta_title' => 'Linkbuilding België | Guest posts en netlinking',
                'meta_description' => 'Linkbuilding België en netlinking Belgique: self-service catalogus in EUR, gevolgde orders en digital-PR-pakketten. Geen anonieme linkpakketten.',
                'teaser_countries' => ['be'],
                'teaser_title' => 'Selectie voor campagnes in België',
                'teaser_subtitle' => 'Zelfde catalogusregels als op de guest-postpagina. Europa filter je na login.',
                'intro' => [
                    'Linkbuilding hier betekent dat je publishers kiest, betaalt en de URL volgt. Het is geen abonnement dat «SEO voor je doet». Op Franstalige zoekopdrachten heet dat netlinking — dezelfde marktplaats, dezelfde wallet.',
                    'Europese linkbuilding gebruikt dezelfde saldo. België is het landfilter, geen apart product. Een bureau dat intern werkt, kan hetzelfde catalogus gebruiken.',
                ],
                'points' => [
                    [
                        'title' => 'Self-service',
                        'body' => 'De strategie is jouw keuze van sites, ankers en tempo. Het catalogus is de bron. Maandelijkse linkbuilding is het tempo dat je zelf zet.',
                    ],
                    [
                        'title' => 'Linkbuilding-pakketten',
                        'body' => 'De genummerde pakketten onder prijzen zijn beheerste digital-PR-campagnes, geen zak URL’s.',
                    ],
                    [
                        'title' => 'White label',
                        'body' => 'Het bureau kan bestellen op eigen account. We leveren geen portal met jullie logo. Details: <a href="'.$agencies.'">voor bureaus</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Guest posts, niche edits en digital PR',
                        'body' => 'Een guest post is een nieuw artikel. Niche edits (link in bestaande inhoud) verkopen we niet als SKU. Digital PR is de campagne; in de marktplaats betaal je nog altijd de publicatie.',
                    ],
                    [
                        'h2' => 'Zo start een campagne',
                        'body' => 'Account, saldo in EUR, filter België, order. Het traject: <a href="'.$how.'">zo werkt het</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Hoe werkt de Belgische marktplaats?',
                        'a' => 'Je bladert publishers, filtert websites, leest eisen en prijzen, plaatst de order, de publisher publiceert, jij krijgt de live-URL op de order.',
                    ],
                    [
                        'q' => 'Hebben jullie goedkope linkbuilding?',
                        'a' => 'De prijs is die van de catalogusregel. We hebben geen apart «goedkoop» laag naast het catalogus.',
                    ],
                ],
                'cta_primary' => ['label' => 'Open het catalogus', 'url' => $register],
                'cta_secondary' => ['label' => 'Bekijk de prijzen', 'url' => $prices],
                'see_also' => [
                    ['label' => 'Voor bureaus', 'url' => $agencies],
                    ['label' => 'Digital PR', 'url' => $pr],
                    ['label' => 'Gids', 'url' => $guide],
                ],
            ],
            'bureaus-belgie' => [
                'kicker' => 'B2B-account',
                'h1' => 'Linkbuilding voor bureaus in België',
                'subtitle' => 'Self-service catalogus voor SEO-bureaus, resellers en teams die doorfactureren. EUR-saldo, gevolgde orders, facturen in de adverteerdersfacturatie.',
                'meta_title' => 'White label linkbuilding voor bureaus | België',
                'meta_description' => 'Guest posts voor bureaus in België: catalogus in EUR, facturen, orders per merk — zonder resellerportal met jullie logo.',
                'teaser_countries' => ['be'],
                'teaser_title' => 'Selectie die je kunt doorfactureren',
                'teaser_subtitle' => 'Zelfde catalogusregels als voor een interne adverteerder. Het account is van jullie; merken liggen in projecten en orders.',
                'intro' => [
                    '«Guest post pour agences Belgique», «white label linkbuilding België» en «linkbuilding bureau» zoeken een partij die uitvoert. Hier houdt het bureau het stuur: jullie kiezen sites, betalen en leveren de live-URL aan de klant.',
                    'Operationeel white label betekent dat de eindklant geen account nodig heeft. Het is geen resellerprogramma met jullie merk op de publieke pagina.',
                ],
                'points' => [
                    [
                        'title' => 'Eén saldo, meerdere campagnes',
                        'body' => 'Jullie storten euro (kaart of overschrijving, wanneer de methode actief is) en verdelen het saldo over orders.',
                    ],
                    [
                        'title' => 'Order en factuur',
                        'body' => 'Facturen voor stortingen of orders haal je in de adverteerdersfacturatie, wanneer het product ze uitgeeft. De bedrijfsgegevens zijn de Britse (Topurlz Ltd). We verzinnen geen Belgisch btw-nummer.',
                    ],
                    [
                        'title' => 'Werkvlak voor SEO-teams',
                        'body' => 'Filters, metrics, chat op de order en live-URL. Na login is het dashboard Engels voor alle rollen.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Bureau versus marktplaats',
                        'body' => 'Een bureau kiest sites voor de klant. Een marktplaats toont sites aan de koper. SEOLinkBuildings is het laatste. Als jullie team het bureau is, blijft de selectie bij jullie, en is het catalogus de bron.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kunnen we de marktplaats verbergen voor de klant?',
                        'a' => 'Ja, door vanaf jullie account te werken. We leveren geen white-label-portal met jullie merk.',
                    ],
                    [
                        'q' => 'Stellen jullie facturen op met een Belgisch btw-nummer?',
                        'a' => 'De facturatie volgt het Britse bedrijf. Haal de documenten en overleg met boekhouding. We verzinnen geen ondernemingsnummer of btw.',
                    ],
                ],
                'cta_primary' => ['label' => 'Bureau-account aanmaken', 'url' => $register],
                'cta_secondary' => ['label' => 'Catalogus', 'url' => $market],
                'see_also' => [
                    ['label' => 'Linkbuilding', 'url' => $lb],
                    ['label' => 'Prijzen', 'url' => $prices],
                    ['label' => 'Digital PR', 'url' => $pr],
                ],
            ],
            'digital-pr' => [
                'kicker' => 'PR in digitale media',
                'h1' => 'Digital PR in België',
                'subtitle' => 'Digital-PR-campagnes als publicaties op sites in de marktplaats, plus beheerste pakketten onder prijzen. Geen belofte van Google News.',
                'meta_title' => 'Digital PR in België | Guest posts en media',
                'meta_description' => 'Digital PR België: publicaties uit het catalogus, EUR-saldo, live-URL en beheerste pakketten — zonder News- of persgarantie.',
                'teaser_countries' => ['be'],
                'teaser_title' => 'Belgische sites in het catalogus',
                'teaser_subtitle' => 'Sommige publishers lijken op een mediakit; niet allemaal zijn een dagblad. Niche en taal filter je na login.',
                'intro' => [
                    '«Digital PR Belgique», «acheter communiqué de presse Belgique» en «digitale PR» mengen PR met linkbuilding. Hier koop je publicaties op sites die écht in het catalogus liggen.',
                    'Beheerste pakketten (bedragen staan onder Prijzen; vandaag vanaf 499 €/maand op het basisplan, als het nog getoond wordt) zijn teamuitvoering, geen knop «kom in een landelijke krant».',
                ],
                'points' => [
                    [
                        'title' => 'Media alleen als ze in het catalogus liggen',
                        'body' => 'We hebben geen Google News-kanaal. Een guest post «in de pers» bestaat alleen als die site een catalogusregel is en de briefing aanvaardt.',
                    ],
                    [
                        'title' => 'Brand mentions',
                        'body' => 'Een vermelding kan uit een publicatie komen. We verkopen geen «brand mention» als SKU zonder URL.',
                    ],
                    [
                        'title' => 'Campagnes',
                        'body' => 'Self-service: jij kiest sites. Beheerd: de pakketten onder prijzen.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'PR en SEO, zonder opsmuk',
                        'body' => 'Een nuttige publicatie heeft lezers, context en een link (of vermelding) die zin heeft. Ze vervangt geen nieuws. Catalogus: <a href="'.$market.'">de lijst van sites</a>. Pakketten: <a href="'.$prices.'">prijzen</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Garanderen jullie een artikel in de Belgische pers?',
                        'a' => 'Nee. We leveren de URL op de site die je hebt besteld, als de publisher aanvaardt.',
                    ],
                    [
                        'q' => 'Is dit iets anders dan een publirédactionnel?',
                        'a' => 'Het gesponsorde artikel is de publicatie. Digital PR is de campagne. In de marktplaats betaal je nog altijd de publicatie. <a href="'.$sponsored.'">Gesponsord artikel</a>.',
                    ],
                ],
                'cta_primary' => ['label' => 'Bekijk pakketten en catalogus', 'url' => $prices],
                'cta_secondary' => ['label' => 'Registreren', 'url' => $register],
                'see_also' => [
                    ['label' => 'Gesponsord artikel', 'url' => $sponsored],
                    ['label' => 'Voor bureaus', 'url' => $agencies],
                    ['label' => 'Koop guest posts', 'url' => $guest],
                ],
            ],
            'niche-edits' => [
                'kicker' => 'Geen apart product',
                'h1' => 'Niche edits in België — en wat we verkopen',
                'subtitle' => 'Niche edits (link invoegen in een al gepubliceerd artikel) zijn geen SKU op SEOLinkBuildings. Hier is de grens tegenover een guest post, plus de risico’s.',
                'meta_title' => 'Niche edits in België uitgelegd | SEOLinkBuildings',
                'meta_description' => 'Wat niche edits en «link invoegen in artikel» zijn, wanneer het risicovol is, en waarom we in België redactionele publicaties verkopen — geen invoeging in een vreemd artikel.',
                'teaser_countries' => ['be'],
                'teaser_title' => 'Redactionele sites, geen invoegnetwerken',
                'teaser_subtitle' => 'Voorvertoning van actieve catalogusregels in België. Het standaardproduct is een nieuw artikel met een link in de tekst.',
                'intro' => [
                    'Een niche edit is een link in een al gepubliceerd artikel, vaak omdat de URL al geïndexeerd is. «Link invoegen in artikel» en «insertion de lien» zoeken precies dat.',
                    'We verkopen het niet als product. De standaardorder is een nieuwe publicatie (guest post of gesponsord artikel) met briefing en live-URL. Sommige sites bieden een homepage-extra met termijn; dat staat op de catalogusregel.',
                ],
                'points' => [
                    [
                        'title' => 'Waarom we het niet verkopen',
                        'body' => 'Een link in een artikel dat je niet hebt geschreven, is moeilijker te controleren en vaker in conflict met de eigen regels van de site. We willen geen SKU beloven die we niet consistent kunnen leveren.',
                    ],
                    [
                        'title' => 'Wat je in de plaats kunt kopen',
                        'body' => 'Een <a href="'.$guest.'">guest post</a> of een <a href="'.$sponsored.'">gesponsord artikel</a> met het anker in de nieuwe tekst.',
                    ],
                    [
                        'title' => 'Risico',
                        'body' => 'Invoegingen in oude artikelen kunnen verdwijnen, attribuut wijzigen of irrelevante ankers raken. Lees de <a href="'.$guide.'">gids</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Contextuele backlinks',
                        'body' => 'Een contextlink in een nieuwe guest post is nog altijd een redactionele link. Het verschil is dat je de briefing kent en de live-URL op de order krijgt. <a href="'.$links.'">Koop backlinks</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kan ik de publisher vragen om in een oud artikel in te voegen?',
                        'a' => 'Alleen als de catalogusregel dat beschrijft. Het is niet onze standaard-SKU.',
                    ],
                ],
                'cta_primary' => ['label' => 'Koop in de plaats een guest post', 'url' => $guest],
                'cta_secondary' => ['label' => 'Catalogus', 'url' => $market],
                'see_also' => [
                    ['label' => 'Linkbuilding', 'url' => $lb],
                    ['label' => 'Koop guest posts', 'url' => $guest],
                    ['label' => 'Gesponsord artikel', 'url' => $sponsored],
                ],
            ],
            'gids-belgie' => [
                'kicker' => 'Eén gids',
                'h1' => 'Gids: guest posts en linkbuilding in België',
                'subtitle' => 'Wat een guest post is, hoe je backlinks koopt, dofollow versus nofollow, ankers, PBN en risico’s — op één pagina, niet op dunne artikelen.',
                'meta_title' => 'Gids guest posts en linkbuilding België | SEOLinkBuildings',
                'meta_description' => 'Korte gids: wat een guest post is, hoe je backlinks koopt, dofollow vs nofollow, ankers, rel sponsored en waarom PBN niet ons product is.',
                'teaser_countries' => ['be'],
                'teaser_title' => 'Van uitleg naar catalogus',
                'teaser_subtitle' => 'Na de gids liggen de echte sites in het Belgische catalogus, met een prijs per publicatie.',
                'intro' => [
                    'Deze gids dekt informatieve zoekopdrachten (wat is linkbuilding, hoe koop je backlinks, zijn backlinks legaal, ankertekst) zonder een nieuwe pagina per zin.',
                    'Het dashboard na login blijft Engels. De publieke pagina is Belgisch Nederlands, met de Franse termen die adverteerders hier echt gebruiken.',
                ],
                'points' => [
                    [
                        'title' => 'Wat is een guest post?',
                        'body' => 'Een artikel op andermans site, typisch met een link naar jou, tegen betaling. Bij ons is de betaling in EUR, per order.',
                    ],
                    [
                        'title' => 'Dofollow, nofollow, sponsored',
                        'body' => 'Dofollow geeft typisch een signaal door. Nofollow en sponsored vertellen dat de link gemarkeerd is. Google behandelt rel sponsored als een betaalde link. Kies wat de catalogusregel aangeeft.',
                    ],
                    [
                        'title' => 'Ankers',
                        'body' => 'Een exact anker, herhaald op veel sites, is een risicovol patroon. Varieer de formulering en houd het anker relevant voor de doelpagina.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Hoe koop je backlinks',
                        'body' => 'Account, saldo, land- en nichefilter, briefing, goedkeuring van de live-URL. Werking: <a href="'.$how.'">zo werkt het</a>. Commerciële pagina: <a href="'.$links.'">koop backlinks</a>.',
                    ],
                    [
                        'h2' => 'PBN versus guest posts',
                        'body' => 'Een PBN is een netwerk dat jij stuurt om links te sturen. Dat verkopen we niet. Een guest post is een publicatie op een site met eigen lezers. Kun je de site niet noemen, dan is het dit product niet.',
                    ],
                    [
                        'h2' => 'Risico’s bij het kopen van backlinks',
                        'body' => 'Sites zonder echte traffic, agressieve ankers, links die verdwijnen, ontbrekende sponsored-markering, opgeblazen metrics. Check de catalogusregel en de live-URL. We beloven geen rankings.',
                    ],
                    [
                        'h2' => 'Strategie, kort',
                        'body' => 'Een paar relevante publicaties slaan een volume links zonder context. Voor uitvoering: <a href="'.$lb.'">linkbuilding</a>, <a href="'.$guest.'">koop guest posts</a>, <a href="'.$publisher.'">word publisher</a> als je plaats verkoopt.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Is dit een linkbuilding-gids 2026?',
                        'a' => 'Nee. Het is een productpagina die de huidige regels van de marktplaats uitlegt, geen trendkalender.',
                    ],
                    [
                        'q' => 'Waar zie ik de prijzen?',
                        'a' => 'Het model staat onder <a href="'.$prices.'">prijzen</a>. Actuele bedragen staan in het catalogus na registratie.',
                    ],
                ],
                'cta_primary' => ['label' => 'Bekijk de selectie', 'url' => $register],
                'cta_secondary' => ['label' => 'Koop guest posts', 'url' => $guest],
                'see_also' => [
                    ['label' => 'Linkbuilding', 'url' => $lb],
                    ['label' => 'Koop backlinks', 'url' => $links],
                    ['label' => 'Prijzen', 'url' => $prices],
                ],
            ],
        ];
    }
}
