<?php

namespace App\Support;

/**
 * Denmark money / B2B landers (Danish as used by SEO teams, plus genuine loanwords).
 * Marketplace stays /dk/markedsplads and pricing stays /dk/priser.
 */
class DanishMoneyLanders
{
    use MoneyLanderPages;

    public const LOCALE = 'dk';

    /**
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return [
            'marketplace' => '/dk/markedsplads',
            'katalog-publishers' => '/dk/markedsplads',
            'platform-gaesteindlaeg' => '/dk/markedsplads',
            'guest-post-denmark' => '/dk/koeb-gaesteindlaeg',
            'gaesteindlaeg-blogs' => '/dk/koeb-gaesteindlaeg',
            'koeb-guest-post' => '/dk/koeb-gaesteindlaeg',
            'bestil-gaesteindlaeg' => '/dk/koeb-gaesteindlaeg',
            'gaesteblog-koeb' => '/dk/koeb-gaesteindlaeg',
            'advertorial' => '/dk/sponsoreret-artikel',
            'betalingsartikel' => '/dk/sponsoreret-artikel',
            'danske-backlinks' => '/dk/koeb-backlinks',
            'koeb-links' => '/dk/koeb-backlinks',
            'hvad-koster-gaesteindlaeg' => '/dk/priser',
            'linkbuilding-priser' => '/dk/priser',
            'white-label-linkbuilding' => '/dk/bureauer',
            'pressemeddelelse' => '/dk/digital-pr',
            'indsaet-link' => '/dk/niche-edits',
            'guide' => '/dk/vejledning',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function ui(): array
    {
        return [
            'cluster_title' => 'Sider om gæsteindlæg, backlinks og linkbuilding i Danmark',
            'from' => 'Fra',
            'price_note' => 'Laveste aktuelle pris i euro på aktive, verificerede katalogregler med hovedland Danmark. Ikke en fast prisliste.',
            'sites_preview' => 'Sites i forhåndsvisning',
            'count_note' => 'Aktive, verificerede publishers med Danmark som hovedland, når tællingen er tilgængelig.',
            'th_site' => 'Site',
            'th_country' => 'Land',
            'th_language' => 'Sprog',
            'th_from' => 'Fra',
            'teaser_foot' => 'Vi viser DA, DR og prisen i euro, når de står på katalogreglen. Manglende metrics forbliver tomme.',
            'see_also' => 'Se også',
        ];
    }

    /**
     * @return list<array{slug: string, label: string, url: string}>
     */
    public static function clusterLinks(?string $current = null): array
    {
        return self::filterCluster([
            ['slug' => 'home', 'label' => 'Markedsplads Danmark', 'url' => url('/dk')],
            ['slug' => 'koeb-gaesteindlaeg', 'label' => 'Køb gæsteindlæg', 'url' => self::url('koeb-gaesteindlaeg')],
            ['slug' => 'sponsoreret-artikel', 'label' => 'Sponsoreret artikel', 'url' => self::url('sponsoreret-artikel')],
            ['slug' => 'markedsplads', 'label' => 'Katalog publishers', 'url' => self::marketingUrl('marketplace')],
            ['slug' => 'linkbuilding', 'label' => 'Linkbuilding', 'url' => self::url('linkbuilding')],
            ['slug' => 'koeb-backlinks', 'label' => 'Køb backlinks', 'url' => self::url('koeb-backlinks')],
            ['slug' => 'priser', 'label' => 'Priser', 'url' => self::marketingUrl('pricing')],
            ['slug' => 'bureauer', 'label' => 'Til bureauer', 'url' => self::url('bureauer')],
            ['slug' => 'digital-pr', 'label' => 'Digital PR', 'url' => self::url('digital-pr')],
            ['slug' => 'niche-edits', 'label' => 'Niche edits', 'url' => self::url('niche-edits')],
            ['slug' => 'vejledning', 'label' => 'Vejledning', 'url' => self::url('vejledning')],
        ], $current);
    }

    /**
     * @return list<array{slug: string, label: string, url: string}>
     */
    public static function footerLinks(): array
    {
        return array_values(array_filter(
            self::clusterLinks('home'),
            static fn (array $item) => ! in_array($item['slug'], ['markedsplads', 'priser'], true)
        ));
    }

    /**
     * @return array{h2: string, body: string, links: string}
     */
    public static function marketplaceCopy(): array
    {
        return [
            'h2' => 'Katalog over danske publishers',
            'body' => 'Her er den offentlige liste over publishers med Danmark som hovedland: niche, sprog, DA/DR og pris i euro. Vi indekserer ikke hver filterkombination, og byer (København, Aarhus) har ingen egen URL. Det fulde katalog med domæner åbner efter registrering.',
            'links' => '<a href="'.self::url('koeb-gaesteindlaeg').'">Køb gæsteindlæg i Danmark</a> · <a href="'.self::url('koeb-backlinks').'">Køb backlinks</a> · <a href="'.self::marketingUrl('pricing').'">Hvad koster et gæsteindlæg</a> · <a href="'.url('/guest-posts-denmark').'">Denmark inventory (English)</a>',
        ];
    }

    /**
     * @return array{h2: string, body: string}
     */
    public static function pricingCopy(): array
    {
        return [
            'h2' => 'Hvad koster et gæsteindlæg i Danmark',
            'body' => 'Vi udgiver ikke en fast PDF-prisliste og opfinder ikke et dansk CVR-tillæg: prisen er sitets, i euro. «Hvad koster et gæsteindlæg», «linkbuilding priser» og «pris backlinks» følger de katalogregler, du vælger. De nummererede pakker ovenfor er styrede digital-PR-kampagner, ikke en pose anonyme URL’er. Aktuelle beløb står i <a href="'.self::marketingUrl('marketplace').'">det danske katalog</a> efter registrering. Hovedkontoret er i London (Topurlz Ltd).',
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
        $guest = self::url('koeb-gaesteindlaeg');
        $sponsored = self::url('sponsoreret-artikel');
        $links = self::url('koeb-backlinks');
        $lb = self::url('linkbuilding');
        $agencies = self::url('bureauer');
        $pr = self::url('digital-pr');
        $niche = self::url('niche-edits');
        $guide = self::url('vejledning');
        $publisher = self::marketingUrl('become-a-publisher');

        return [
            'koeb-gaesteindlaeg' => [
                'kicker' => 'Gæsteindlæg med backlink',
                'h1' => 'Køb gæsteindlæg på danske blogs',
                'subtitle' => 'Vælg verificerede danske og europæiske publishers, sammenlign niche, DA/DR og prisen i euro, send briefingen og følg live-URL’en i ordren.',
                'meta_title' => 'Køb gæsteindlæg i Danmark | SEOLinkBuildings',
                'meta_description' => 'Køb gæsteindlæg på danske blogs og .dk-sites: filter niche, DA/DR og EUR-pris, vælg dofollow eller sponsored og få live-URL’en.',
                'teaser_countries' => ['dk'],
                'teaser_title' => 'Sites til gæsteindlæg i Danmark',
                'teaser_subtitle' => 'Maskeret forhåndsvisning af aktive katalogregler i Danmark. Domæner ser du efter registrering.',
                'intro' => [
                    'SEOLinkBuildings er en self-service markedsplads, ikke en uigennemsigtig pakke gæsteindlæg. Du vælger sitet — ofte .dk, ofte på dansk — betaler i euro fra saldoen og holder briefing, chat og live-URL i samme ordre. Hovedkontoret er i London (Topurlz Ltd); vi opfinder ikke et CVR-nummer i Danmark.',
                    '«Køb gæsteindlæg», «gæsteindlæg på danske blogs» og «køb guest post» er samme hensigt: en betalt publikation på et site, du ikke ejer, med skrevne regler for længde, links og levering.',
                ],
                'points' => [
                    [
                        'title' => 'Publishers, ikke en fantomliste',
                        'body' => 'Hver række er et site med niche, sprog, land, DA/DR, opgivet traffic og pris. Vi sælger ikke PBN og ikke «pakker med 50 links».',
                    ],
                    [
                        'title' => 'Sådan bestiller du',
                        'body' => 'Du opretter en konto, filtrerer Danmark, lægger sitet i kurven og sender titel, tekst eller briefing plus anker. Publisheren leverer live-URL’en til godkendelse.',
                    ],
                    [
                        'title' => 'Dofollow og sponsored',
                        'body' => 'Linkattributten står på katalogreglen. Mange medier markerer betalte publikationer. Læs linktypen, før du bestiller — der findes ikke «dofollow til enhver pris».',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Traffic, niche og permanente links',
                        'body' => 'Et gæsteindlæg «med traffic» betyder, at katalogreglen viser den traffic, publisheren har opgivet — ikke en garanti for besøg. Niches (sundhed, finance, tech, forsikring, ejendom, travel, ecommerce) filtrerer du i kataloget, ikke på egne URL’er. «Permanent» afhænger af sitets regler. Læs katalogreglen.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kan jeg kun publicere på .dk-sites?',
                        'a' => 'Ja. Du filtrerer landet Danmark. Det europæiske katalog ligger i samme EUR-saldo. Sverige og Norge er egne filtre.',
                    ],
                    [
                        'q' => 'Skriver I artiklen?',
                        'a' => 'Standardordren bruger din briefing. Nogle katalogregler tilbyder redaktion; det ser du på sitet, ikke som en opdigtet tillægsydelse her.',
                    ],
                    [
                        'q' => 'Findes der sider for København?',
                        'a' => 'Nej. Byer har ingen egen URL. Du filtrerer landet.',
                    ],
                ],
                'cta_primary' => ['label' => 'Opret konto og se publishers', 'url' => $register],
                'cta_secondary' => ['label' => 'Katalog publishers', 'url' => $market],
                'see_also' => [
                    ['label' => 'Køb backlinks', 'url' => $links],
                    ['label' => 'Sponsoreret artikel', 'url' => $sponsored],
                    ['label' => 'Priser', 'url' => $prices],
                    ['label' => 'Vejledning', 'url' => $guide],
                ],
            ],
            'sponsoreret-artikel' => [
                'kicker' => 'Advertorial',
                'h1' => 'Køb sponsoreret artikel i Danmark',
                'subtitle' => 'Du køber en betalt artikel på et site i kataloget, med pris i euro og live-URL. Vi sælger ikke et generisk pressemeddelelse-abonnement.',
                'meta_title' => 'Sponsoreret artikel i Danmark | SEOLinkBuildings',
                'meta_description' => 'Køb sponsoreret artikel eller advertorial på danske sites. Pris i EUR pr. katalogregel, briefing og live-URL — uden opdigtet pressebureau.',
                'teaser_countries' => ['dk'],
                'teaser_title' => 'Medier til gæsteindlæg og advertorial',
                'teaser_subtitle' => 'Samme forhåndsvisning af publishers i Danmark. En advertorial findes kun, hvis sitet ligger i kataloget.',
                'intro' => [
                    '«Køb sponsoreret artikel», «betalingsartikel SEO» og «advertorial» beskriver en betalt publikation, ikke en presselinje vi ikke har. Står domænet ikke i kataloget, sælger vi det ikke.',
                    'Native advertising til SEO er her samme forløb: du vælger publikationen, betaler i EUR og får URL’en. Vi lover ikke Google News.',
                ],
                'points' => [
                    [
                        'title' => 'Markering som sponsoreret',
                        'body' => 'Mange sites kræver rel sponsored eller et synligt mærke. Følg reglen på katalogreglen.',
                    ],
                    [
                        'title' => 'Prisen',
                        'body' => 'Hvad en sponsoreret artikel koster, afhænger af sitet. Se <a href="'.$prices.'">priserne</a> for modellen og kataloget for aktuelle beløb.',
                    ],
                    [
                        'title' => 'Indhold',
                        'body' => 'Du sender teksten eller briefingen. Publisheren publicerer på sit eget site og sender live-URL’en.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Advertorial versus gæsteindlæg',
                        'body' => 'I praksis er begge en betalt publikation med et link. Forskellen er redaktionel. Afregningen er den samme. <a href="'.$guest.'">Køb gæsteindlæg</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Er der én takst for en advertorial?',
                        'a' => 'Nej. Hver katalogregel har sin egen pris, i euro.',
                    ],
                    [
                        'q' => 'Placerer I på alle aviser?',
                        'a' => 'Kun på sites, der ligger i kataloget og accepterer ordren.',
                    ],
                ],
                'cta_primary' => ['label' => 'Se danske sites', 'url' => $register],
                'cta_secondary' => ['label' => 'Digital PR', 'url' => $pr],
                'see_also' => [
                    ['label' => 'Digital PR', 'url' => $pr],
                    ['label' => 'Priser', 'url' => $prices],
                    ['label' => 'Køb gæsteindlæg', 'url' => $guest],
                ],
            ],
            'koeb-backlinks' => [
                'kicker' => 'Redaktionelle backlinks',
                'h1' => 'Køb backlinks i Danmark',
                'subtitle' => 'Backlinks her er links fra publikationer, du køber på rigtige sites — ikke en anonym pose URL’er.',
                'meta_title' => 'Køb backlinks i Danmark | SEOLinkBuildings',
                'meta_description' => 'Danske backlinks fra gæsteindlæg på rigtige sites. Pris i EUR, dofollow eller sponsored på katalogreglen, live-URL i ordren.',
                'teaser_countries' => ['dk'],
                'teaser_title' => 'Sites .dk til backlinks',
                'teaser_subtitle' => 'Forhåndsvisning af katalogregler med hovedland Danmark. Vist traffic er opgivet, ikke lovet.',
                'intro' => [
                    '«Køb backlinks», «køb backlink» og «køb links SEO» søger det samme: et link på en publiceret side. Hos os får du det via et gæsteindlæg eller en sponsoreret artikel, med ankeret fra briefingen.',
                    'Tematiske backlinks betyder, at du vælger sitets niche. «Med rigtig traffic» betyder, at du kigger på traffic på katalogreglen — vi garanterer ikke besøg.',
                ],
                'points' => [
                    [
                        'title' => 'Kvalitet, du kan læse',
                        'body' => 'Land, sprog, niche, DA/DR og pris står på reglen. Vi sælger ikke «kvalitetsbacklinks» som etiket uden site.',
                    ],
                    [
                        'title' => 'Dofollow er ikke standard',
                        'body' => 'Filtrer på linktype. Publisheren står for den live HTML.',
                    ],
                    [
                        'title' => 'Ingen PBN',
                        'body' => 'Vi sælger ikke private netværk. Risici står i <a href="'.$guide.'">vejledningen</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Sådan vælger du',
                        'body' => 'Start i <a href="'.$market.'">kataloget</a>, filtrér Danmark, sammenlign pris og ankerregler. Derefter <a href="'.$guest.'">køb gæsteindlæg</a> eller en <a href="'.$sponsored.'">sponsoreret artikel</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kan jeg kun købe dofollow?',
                        'a' => 'Du kan filtrere på tilbud, der nævner dofollow. Tjek attributten på den live side.',
                    ],
                    [
                        'q' => 'Indsætter I et link i en eksisterende artikel?',
                        'a' => 'Ikke som SKU for niche edits. Nogle sites sælger et homepage-tillæg med tidsfrist.',
                    ],
                    [
                        'q' => 'Hvad koster backlinks i Danmark?',
                        'a' => 'Det afhænger af sitet. <a href="'.$prices.'">Priser</a> forklarer modellen; kataloget viser aktuelle beløb.',
                    ],
                ],
                'cta_primary' => ['label' => 'Sammenlign sites', 'url' => $register],
                'cta_secondary' => ['label' => 'Linkbuilding', 'url' => $lb],
                'see_also' => [
                    ['label' => 'Priser', 'url' => $prices],
                    ['label' => 'Køb gæsteindlæg', 'url' => $guest],
                    ['label' => 'Niche edits', 'url' => $niche],
                ],
            ],
            'linkbuilding' => [
                'kicker' => 'Kampagner',
                'h1' => 'Linkbuilding i Danmark',
                'subtitle' => 'Du bygger kampagnen ud af kataloget, publikation for publikation, eller vælger en styret digital-PR-pakke under priser. Ingen «billige backlinks» uden site.',
                'meta_title' => 'Linkbuilding i Danmark | SEOLinkBuildings',
                'meta_description' => 'Linkbuilding Danmark: self-service katalog i EUR, fulgte ordrer og digital-PR-pakker. Ingen anonyme linkpakker.',
                'teaser_countries' => ['dk'],
                'teaser_title' => 'Udvalg til kampagner i Danmark',
                'teaser_subtitle' => 'Samme katalogregler som på gæsteindlægssiden. Europa filtrerer du efter login.',
                'intro' => [
                    'Linkbuilding her betyder, at du vælger publishers, betaler og følger URL’en. Det er ikke et abonnement, der «laver SEO» for dig.',
                    'Europæisk linkbuilding bruger samme saldo. Danmark er landfilteret, ikke et separat produkt. Et bureau, der arbejder internt, kan bruge samme katalog.',
                ],
                'points' => [
                    [
                        'title' => 'Self-service',
                        'body' => 'Strategien er dit valg af sites, ankre og tempo. Kataloget er kilden. Månedlig linkbuilding er det tempo, du selv sætter.',
                    ],
                    [
                        'title' => 'Linkbuilding-pakker',
                        'body' => 'De nummererede pakker under priser er styrede digital-PR-kampagner, ikke en sæk URL’er.',
                    ],
                    [
                        'title' => 'White label',
                        'body' => 'Bureauet kan bestille på egen konto. Vi leverer ikke en portal med jeres logo. Detaljer: <a href="'.$agencies.'">til bureauer</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Gæsteindlæg, niche edits og digital PR',
                        'body' => 'Et gæsteindlæg er en ny artikel. Niche edits (link i eksisterende indhold) sælger vi ikke som SKU. Digital PR er kampagnen; i markedspladsen betaler du stadig publikationen.',
                    ],
                    [
                        'h2' => 'Sådan starter en kampagne',
                        'body' => 'Konto, saldo i EUR, filter Danmark, ordre. Forløbet: <a href="'.$how.'">sådan virker det</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Har I billig linkbuilding?',
                        'a' => 'Prisen er katalogreglens. Vi har ikke et separat «billigt» lag ved siden af kataloget.',
                    ],
                    [
                        'q' => 'Laver I også strategien?',
                        'a' => 'Vejledningen forklarer risici og ankre. Udførelsen i self-service er din.',
                    ],
                ],
                'cta_primary' => ['label' => 'Åbn kataloget', 'url' => $register],
                'cta_secondary' => ['label' => 'Se priserne', 'url' => $prices],
                'see_also' => [
                    ['label' => 'Til bureauer', 'url' => $agencies],
                    ['label' => 'Digital PR', 'url' => $pr],
                    ['label' => 'Vejledning', 'url' => $guide],
                ],
            ],
            'bureauer' => [
                'kicker' => 'B2B-konto',
                'h1' => 'Linkbuilding til bureauer i Danmark',
                'subtitle' => 'Self-service katalog til SEO-bureauer, resellers og teams, der viderefakturerer. EUR-saldo, fulgte ordrer, fakturaer i annoncørfaktureringen.',
                'meta_title' => 'Linkbuilding til bureauer | SEOLinkBuildings',
                'meta_description' => 'Gæsteindlæg til bureauer i Danmark: katalog i EUR, fakturaer, ordrer pr. brand — uden resellerportal med jeres logo.',
                'teaser_countries' => ['dk'],
                'teaser_title' => 'Udvalg I kan viderefakturere',
                'teaser_subtitle' => 'Samme katalogregler som for en intern annoncør. Kontoen er jeres; brands ligger i projekter og ordrer.',
                'intro' => [
                    '«Gæsteindlæg til bureauer», «white label linkbuilding Danmark» og «reseller backlinks» søger en part, der udfører. Her beholder bureauet styrepinden: I vælger sites, betaler og leverer live-URL’en til kunden.',
                    'Operationelt white label betyder, at slutkunden ikke behøver en konto. Det er ikke et resellerprogram med jeres brand på den offentlige side.',
                ],
                'points' => [
                    [
                        'title' => 'Én saldo, flere kampagner',
                        'body' => 'I lægger euro (kort eller overførsel, når metoden er aktiv) og fordeler saldoen på ordrer.',
                    ],
                    [
                        'title' => 'Ordre og faktura',
                        'body' => 'Fakturaer for indbetalinger eller ordrer henter I i annoncørfaktureringen, når produktet udsteder dem. Selskabsoplysningerne er de britiske (Topurlz Ltd). Vi opfinder ikke et dansk CVR.',
                    ],
                    [
                        'title' => 'Arbejdsflade til SEO-teams',
                        'body' => 'Filtre, metrics, chat på ordren og live-URL. Efter login er dashboardet engelsk for alle roller.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Bureau versus markedsplads',
                        'body' => 'Et bureau vælger sites til kunden. En markedsplads viser sites til køberen. SEOLinkBuildings er det sidste. Hvis jeres team er bureauet, bliver udvalget hos jer, og kataloget er kilden.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kan vi skjule markedspladsen for kunden?',
                        'a' => 'Ja, ved at arbejde fra jeres konto. Vi leverer ikke en white-label-portal med jeres brand.',
                    ],
                    [
                        'q' => 'Udsteder I fakturaer med dansk momsnummer?',
                        'a' => 'Faktureringen følger det britiske selskab. Hent dokumenterne og afklar med bogholderiet. Vi opfinder ikke CVR eller momsnummer.',
                    ],
                ],
                'cta_primary' => ['label' => 'Opret bureaukonto', 'url' => $register],
                'cta_secondary' => ['label' => 'Katalog', 'url' => $market],
                'see_also' => [
                    ['label' => 'Linkbuilding', 'url' => $lb],
                    ['label' => 'Priser', 'url' => $prices],
                    ['label' => 'Digital PR', 'url' => $pr],
                ],
            ],
            'digital-pr' => [
                'kicker' => 'PR i digitale medier',
                'h1' => 'Digital PR i Danmark',
                'subtitle' => 'Digital-PR-kampagner som publikationer på sites i markedspladsen, plus styrede pakker under priser. Ingen løfte om Google News.',
                'meta_title' => 'Digital PR i Danmark | SEOLinkBuildings',
                'meta_description' => 'Digital PR Danmark: publikationer fra kataloget, EUR-saldo, live-URL og styrede pakker — uden News- eller pressegaranti.',
                'teaser_countries' => ['dk'],
                'teaser_title' => 'Danske sites i kataloget',
                'teaser_subtitle' => 'Nogle publishers ligner et mediakit; ikke alle er et dagblad. Niche og sprog filtrerer du efter login.',
                'intro' => [
                    '«Digital PR Danmark», «køb pressemeddelelse» og «pressemeddelelse SEO» blander PR med linkbuilding. Her køber du publikationer på sites, der faktisk ligger i kataloget.',
                    'Styrede pakker (beløb står under Priser; i dag fra 499 €/måned på basisplanen, hvis den stadig vises) er teamudførelse, ikke en knap «kom i en landsdækkende avis».',
                ],
                'points' => [
                    [
                        'title' => 'Medier kun hvis de ligger i kataloget',
                        'body' => 'Vi har ingen Google News-kanal. Et gæsteindlæg «i pressen» findes kun, hvis det site er en katalogregel og accepterer briefingen.',
                    ],
                    [
                        'title' => 'Brand mentions',
                        'body' => 'En omtale kan komme fra en publikation. Vi sælger ikke «brand mention» som SKU uden URL.',
                    ],
                    [
                        'title' => 'Kampagner',
                        'body' => 'Self-service: du vælger sites. Styret: pakkerne under priser.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'PR og SEO, uden oppustning',
                        'body' => 'En nyttig publikation har læsere, kontekst og et link (eller en omtale), der giver mening. Den erstatter ikke en nyhed. Katalog: <a href="'.$market.'">listen over sites</a>. Pakker: <a href="'.$prices.'">priser</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Garantierer I en artikel i pressen?',
                        'a' => 'Nej. Vi leverer URL’en på det site, du har bestilt, hvis publisheren accepterer.',
                    ],
                    [
                        'q' => 'Er det noget andet end en advertorial?',
                        'a' => 'Advertorialen er publikationen. Digital PR er kampagnen. I markedspladsen betaler du stadig publikationen. <a href="'.$sponsored.'">Sponsoreret artikel</a>.',
                    ],
                ],
                'cta_primary' => ['label' => 'Se pakker og katalog', 'url' => $prices],
                'cta_secondary' => ['label' => 'Registrér', 'url' => $register],
                'see_also' => [
                    ['label' => 'Sponsoreret artikel', 'url' => $sponsored],
                    ['label' => 'Til bureauer', 'url' => $agencies],
                    ['label' => 'Køb gæsteindlæg', 'url' => $guest],
                ],
            ],
            'niche-edits' => [
                'kicker' => 'Ikke et separat produkt',
                'h1' => 'Niche edits i Danmark — og hvad vi sælger',
                'subtitle' => 'Niche edits (indsæt link i en allerede publiceret artikel) er ikke en SKU på SEOLinkBuildings. Her er grænsen over for et gæsteindlæg, plus risiciene.',
                'meta_title' => 'Niche edits i Danmark forklaret | SEOLinkBuildings',
                'meta_description' => 'Hvad niche edits og «indsæt link i artikel» er, hvornår det er risikabelt, og hvorfor vi i Danmark sælger redaktionelle publikationer — ikke indsættelse i en fremmed artikel.',
                'teaser_countries' => ['dk'],
                'teaser_title' => 'Redaktionelle sites, ikke indsættelsesnetværk',
                'teaser_subtitle' => 'Forhåndsvisning af aktive katalogregler i Danmark. Standardproduktet er en ny artikel med et link i teksten.',
                'intro' => [
                    'En niche edit er et link i en allerede publiceret artikel, ofte fordi URL’en allerede er indekseret. «Indsæt link i artikel» søger præcis det.',
                    'Vi sælger det ikke som produkt. Standardordren er en ny publikation (gæsteindlæg eller sponsoreret artikel) med briefing og live-URL. Nogle sites tilbyder et homepage-tillæg med tidsfrist; det står på katalogreglen.',
                ],
                'points' => [
                    [
                        'title' => 'Hvorfor vi ikke sælger det',
                        'body' => 'Et link i en artikel, du ikke har skrevet, er sværere at kontrollere og oftere i konflikt med sitets egne regler. Vi vil ikke love en SKU, vi ikke kan levere ensartet.',
                    ],
                    [
                        'title' => 'Hvad du kan købe i stedet',
                        'body' => 'Et <a href="'.$guest.'">gæsteindlæg</a> eller en <a href="'.$sponsored.'">sponsoreret artikel</a> med ankeret i den nye tekst.',
                    ],
                    [
                        'title' => 'Risiko',
                        'body' => 'Indsættelser i gamle artikler kan forsvinde, ændre attribut eller ramme irrelevante ankre. Læs <a href="'.$guide.'">vejledningen</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Kontekstuelle backlinks',
                        'body' => 'Et kontekstlink i et nyt gæsteindlæg er stadig et redaktionelt link. Forskellen er, at du kender briefingen og får live-URL’en på ordren. <a href="'.$links.'">Køb backlinks</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kan jeg bede publisheren om at indsætte i en gammel artikel?',
                        'a' => 'Kun hvis katalogreglen beskriver det. Det er ikke vores standard-SKU.',
                    ],
                ],
                'cta_primary' => ['label' => 'Køb et gæsteindlæg i stedet', 'url' => $guest],
                'cta_secondary' => ['label' => 'Katalog', 'url' => $market],
                'see_also' => [
                    ['label' => 'Linkbuilding', 'url' => $lb],
                    ['label' => 'Køb gæsteindlæg', 'url' => $guest],
                    ['label' => 'Sponsoreret artikel', 'url' => $sponsored],
                ],
            ],
            'vejledning' => [
                'kicker' => 'Én vejledning',
                'h1' => 'Vejledning: gæsteindlæg og linkbuilding',
                'subtitle' => 'Hvad et gæsteindlæg er, hvordan du køber backlinks, dofollow versus nofollow, ankre, PBN og risici — på én side, ikke på tynde artikler.',
                'meta_title' => 'Vejledning gæsteindlæg og linkbuilding | SEOLinkBuildings',
                'meta_description' => 'Kort vejledning: hvad et gæsteindlæg er, hvordan du køber backlinks, dofollow vs nofollow, ankre, rel sponsored og hvorfor PBN ikke er vores produkt.',
                'teaser_countries' => ['dk'],
                'teaser_title' => 'Fra forklaring til katalog',
                'teaser_subtitle' => 'Efter vejledningen ligger de rigtige sites i det danske katalog, med en pris pr. publikation.',
                'intro' => [
                    'Denne vejledning dækker informative søgninger (hvad er linkbuilding, hvordan køber man backlinks, er backlinks lovlige, ankertekst) uden en ny side for hver sætning.',
                    'Dashboardet efter login forbliver engelsk. Den offentlige side er dansk.',
                ],
                'points' => [
                    [
                        'title' => 'Hvad er et gæsteindlæg?',
                        'body' => 'En artikel på en andens site, typisk med et link til dig, mod betaling eller bytte. Hos os er betalingen i EUR, pr. ordre.',
                    ],
                    [
                        'title' => 'Dofollow, nofollow, sponsored',
                        'body' => 'Dofollow giver typisk et signal videre. Nofollow og sponsored fortæller, at linket er markeret. Google behandler rel sponsored som et betalt link. Vælg det, katalogreglen angiver.',
                    ],
                    [
                        'title' => 'Ankre',
                        'body' => 'Et eksakt anker, gentaget på mange sites, er et risikabelt mønster. Variér formuleringen og hold ankeret relevant for målsiden.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Hvordan køber man backlinks',
                        'body' => 'Konto, saldo, land- og nichefilter, briefing, godkendelse af live-URL. Drift: <a href="'.$how.'">sådan virker det</a>. Kommerciel side: <a href="'.$links.'">køb backlinks</a>.',
                    ],
                    [
                        'h2' => 'PBN versus gæsteindlæg',
                        'body' => 'Et PBN er et netværk, du styrer for at sende links. Det sælger vi ikke. Et gæsteindlæg er en publikation på et site med egne læsere. Kan du ikke nævne sitet, er det ikke dette produkt.',
                    ],
                    [
                        'h2' => 'Risici ved at købe backlinks',
                        'body' => 'Sites uden rigtig traffic, aggressive ankre, links der forsvinder, manglende sponsored-markering, oppustede metrics. Tjek katalogreglen og live-URL’en. Vi lover ingen placeringer.',
                    ],
                    [
                        'h2' => 'Strategi, kort',
                        'body' => 'Få relevante publikationer slår et volumen links uden kontekst. Til udførelse: <a href="'.$lb.'">linkbuilding</a>, <a href="'.$guest.'">køb gæsteindlæg</a>, <a href="'.$publisher.'">bliv publisher</a> hvis du sælger plads.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Er dette en linkbuilding-guide 2026?',
                        'a' => 'Nej. Det er en produktside, der forklarer markedspladsens nuværende regler, ikke en trendkalender.',
                    ],
                    [
                        'q' => 'Hvor ser jeg priserne?',
                        'a' => 'Modellen står under <a href="'.$prices.'">priser</a>. Aktuelle beløb står i kataloget efter registrering.',
                    ],
                ],
                'cta_primary' => ['label' => 'Se udvalget', 'url' => $register],
                'cta_secondary' => ['label' => 'Køb gæsteindlæg', 'url' => $guest],
                'see_also' => [
                    ['label' => 'Linkbuilding', 'url' => $lb],
                    ['label' => 'Køb backlinks', 'url' => $links],
                    ['label' => 'Priser', 'url' => $prices],
                ],
            ],
        ];
    }
}
