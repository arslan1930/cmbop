<?php

namespace App\Support;

/**
 * Sweden money / B2B landers (Swedish as used by SEO teams, plus genuine loanwords).
 * Marketplace stays /se/marknadsplats and pricing stays /se/priser.
 */
class SwedishMoneyLanders
{
    use MoneyLanderPages;

    public const LOCALE = 'se';

    /**
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return [
            'marketplace' => '/se/marknadsplats',
            'katalog-publishers' => '/se/marknadsplats',
            'plattform-gastinlagg' => '/se/marknadsplats',
            'guest-post-sweden' => '/se/kopa-gastinlagg',
            'gastinlagg-bloggar' => '/se/kopa-gastinlagg',
            'kopa-guest-post' => '/se/kopa-gastinlagg',
            'bestall-gastinlagg' => '/se/kopa-gastinlagg',
            'advertorial' => '/se/sponsrat-inlagg',
            'betald-artikel' => '/se/sponsrat-inlagg',
            'svenska-backlinks' => '/se/kopa-backlinks',
            'kopa-lankar' => '/se/kopa-backlinks',
            'vad-kostar-gastinlagg' => '/se/priser',
            'linkbuilding-priser' => '/se/priser',
            'white-label-linkbuilding' => '/se/byraer',
            'pressmeddelande' => '/se/digital-pr',
            'infoga-lank' => '/se/niche-edits',
            'guide' => '/se/handledning',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function ui(): array
    {
        return [
            'cluster_title' => 'Sidor om gästinlägg, backlinks och linkbuilding i Sverige',
            'from' => 'Från',
            'price_note' => 'Lägsta aktuella pris i euro på aktiva, verifierade katalogregler med huvudland Sverige. Inte en fast prislista.',
            'sites_preview' => 'Sajter i förhandsvisning',
            'count_note' => 'Aktiva, verifierade publishers med Sverige som huvudland, när räkningen är tillgänglig.',
            'th_site' => 'Sajt',
            'th_country' => 'Land',
            'th_language' => 'Språk',
            'th_from' => 'Från',
            'teaser_foot' => 'Vi visar DA, DR och priset i euro när de står på katalogregeln. Saknade metrics lämnas tomma.',
            'see_also' => 'Se också',
        ];
    }

    /**
     * @return list<array{slug: string, label: string, url: string}>
     */
    public static function clusterLinks(?string $current = null): array
    {
        return self::filterCluster([
            ['slug' => 'home', 'label' => 'Marknadsplats Sverige', 'url' => url('/se')],
            ['slug' => 'kopa-gastinlagg', 'label' => 'Köp gästinlägg', 'url' => self::url('kopa-gastinlagg')],
            ['slug' => 'sponsrat-inlagg', 'label' => 'Sponsrat inlägg', 'url' => self::url('sponsrat-inlagg')],
            ['slug' => 'marknadsplats', 'label' => 'Katalog publishers', 'url' => self::marketingUrl('marketplace')],
            ['slug' => 'linkbuilding', 'label' => 'Linkbuilding', 'url' => self::url('linkbuilding')],
            ['slug' => 'kopa-backlinks', 'label' => 'Köp backlinks', 'url' => self::url('kopa-backlinks')],
            ['slug' => 'priser', 'label' => 'Priser', 'url' => self::marketingUrl('pricing')],
            ['slug' => 'byraer', 'label' => 'Till byråer', 'url' => self::url('byraer')],
            ['slug' => 'digital-pr', 'label' => 'Digital PR', 'url' => self::url('digital-pr')],
            ['slug' => 'niche-edits', 'label' => 'Niche edits', 'url' => self::url('niche-edits')],
            ['slug' => 'handledning', 'label' => 'Handledningen', 'url' => self::url('handledning')],
        ], $current);
    }

    /**
     * @return list<array{slug: string, label: string, url: string}>
     */
    public static function footerLinks(): array
    {
        return array_values(array_filter(
            self::clusterLinks('home'),
            static fn (array $item) => ! in_array($item['slug'], ['marknadsplats', 'priser'], true)
        ));
    }

    /**
     * @return array{h2: string, body: string, links: string}
     */
    public static function marketplaceCopy(): array
    {
        return [
            'h2' => 'Katalog över svenska publishers',
            'body' => 'Här är den offentliga listan över publishers med Sverige som huvudland: nisch, språk, DA/DR och pris i euro. Vi indexerar inte varje filterkombination, och städer har ingen egen URL — inte ens Stockholm. Den fulla katalogen med domäner öppnas efter registrering.',
            'links' => '<a href="'.self::url('kopa-gastinlagg').'">Köp gästinlägg i Sverige</a> · <a href="'.self::url('kopa-backlinks').'">Köp backlinks</a> · <a href="'.self::marketingUrl('pricing').'">Vad kostar ett gästinlägg</a> · <a href="'.url('/guest-posts-sweden').'">Sweden inventory (English)</a>',
        ];
    }

    /**
     * @return array{h2: string, body: string}
     */
    public static function pricingCopy(): array
    {
        return [
            'h2' => 'Vad kostar ett gästinlägg i Sverige',
            'body' => 'Vi publicerar ingen fast PDF-prislista och hittar inte på ett svenskt org.nr-påslag: priset är sajtens, i euro. «Vad kostar gästinlägg», «linkbuilding-priser» och «pris backlinks» följer de katalogregler du väljer. De numrerade paketen ovan är styrda digital-PR-kampanjer, inte en påse anonyma URL:er. Aktuella belopp står i <a href="'.self::marketingUrl('marketplace').'">den svenska katalogen</a> efter registrering. Huvudkontoret ligger i London (Topurlz Ltd).',
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
        $guest = self::url('kopa-gastinlagg');
        $sponsored = self::url('sponsrat-inlagg');
        $links = self::url('kopa-backlinks');
        $lb = self::url('linkbuilding');
        $agencies = self::url('byraer');
        $pr = self::url('digital-pr');
        $niche = self::url('niche-edits');
        $guide = self::url('handledning');
        $publisher = self::marketingUrl('become-a-publisher');

        return [
            'kopa-gastinlagg' => [
                'kicker' => 'Gästinlägg med backlink',
                'h1' => 'Köp gästinlägg på svenska bloggar',
                'subtitle' => 'Välj verifierade svenska och europeiska publishers, jämför nisch, DA/DR och priset i euro, skicka briefingen och följ live-URL:en i beställningen.',
                'meta_title' => 'Köp gästinlägg i Sverige | SEOLinkBuildings',
                'meta_description' => 'Köp gästinlägg på svenska bloggar och .se-sajter: filtrera nisch, DA/DR och EUR-pris, välj dofollow eller sponsored och få live-URL:en.',
                'teaser_countries' => ['se'],
                'teaser_title' => 'Sajter för gästinlägg i Sverige',
                'teaser_subtitle' => 'Maskerad förhandsvisning av aktiva katalogregler i Sverige. Domäner ser du efter registrering.',
                'intro' => [
                    'SEOLinkBuildings är en self-service-marknadsplats, inte ett ogenomskinligt paket gästinlägg. Du väljer sajten — ofta .se, ofta på svenska — betalar i euro från plånboken och håller briefing, chatt och live-URL i samma beställning. Huvudkontoret ligger i London (Topurlz Ltd); vi hittar inte på ett svenskt org.nr.',
                    '«Köp gästinlägg», «gästinlägg på bloggar» och «köp guest post» är samma avsikt: en betald publicering på en sajt du inte äger, med skrivna regler för längd, länkar och leverans.',
                ],
                'points' => [
                    [
                        'title' => 'Publishers, inte en fantomlista',
                        'body' => 'Varje rad är en sajt med nisch, språk, land, DA/DR, uppgiven traffic och pris. Vi säljer inte PBN och inte «paket med 50 länkar».',
                    ],
                    [
                        'title' => 'Så här beställer du',
                        'body' => 'Du skapar ett konto, filtrerar på Sverige, lägger sajten i varukorgen och skickar titel, text eller briefing plus ankare. Publishern levererar live-URL:en till godkännande.',
                    ],
                    [
                        'title' => 'Dofollow och sponsored',
                        'body' => 'Länkattributet står på katalogregeln. Många medier märker betalda publiceringar. Läs länktypen innan du beställer — det finns inget «dofollow till varje pris».',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Traffic, nisch och permanenta länkar',
                        'body' => 'Ett gästinlägg «med traffic» betyder att katalogregeln visar den traffic publishern har uppgett — inte en garanti för besök. Nischer (hälsa, finance, tech, försäkring, fastigheter, resor, ecommerce) filtrerar du i katalogen, inte på egna URL:er. «Permanent» beror på sajtens regler. Läs katalogregeln.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kan jag bara publicera på .se-sajter?',
                        'a' => 'Ja. Du filtrerar på landet Sverige. Den europeiska katalogen ligger i samma saldo i euro. Danmark och Norge är egna filter.',
                    ],
                    [
                        'q' => 'Skriver ni artikeln?',
                        'a' => 'Standardbeställningen utgår från din briefing. Vissa katalogregler erbjuder redaktion; det ser du på sajten, inte som en påhittad tilläggstjänst här.',
                    ],
                    [
                        'q' => 'Finns det sidor för Stockholm?',
                        'a' => 'Nej. Städer har ingen egen URL. Du filtrerar på landet.',
                    ],
                ],
                'cta_primary' => ['label' => 'Skapa konto och se publishers', 'url' => $register],
                'cta_secondary' => ['label' => 'Katalog publishers', 'url' => $market],
                'see_also' => [
                    ['label' => 'Köp backlinks', 'url' => $links],
                    ['label' => 'Sponsrat inlägg', 'url' => $sponsored],
                    ['label' => 'Priser', 'url' => $prices],
                    ['label' => 'Handledningen', 'url' => $guide],
                ],
            ],
            'sponsrat-inlagg' => [
                'kicker' => 'Advertorial',
                'h1' => 'Köp sponsrat inlägg i Sverige',
                'subtitle' => 'Du köper en betald artikel på en sajt i katalogen, med pris i euro och live-URL. Vi säljer inget generiskt abonnemang på pressmeddelanden.',
                'meta_title' => 'Sponsrat inlägg i Sverige | SEOLinkBuildings',
                'meta_description' => 'Köp sponsrat inlägg eller advertorial på svenska sajter. Pris i EUR per katalogregel, briefing och live-URL — utan påhittad pressbyrå.',
                'teaser_countries' => ['se'],
                'teaser_title' => 'Medier för gästinlägg och advertorial',
                'teaser_subtitle' => 'Samma förhandsvisning av publishers i Sverige. En advertorial finns bara om sajten ligger i katalogen.',
                'intro' => [
                    '«Köp sponsrat inlägg», «betald artikel SEO» och «advertorial» beskriver en betald publicering, inte en presslinje vi inte har. Finns inte domänen i katalogen säljer vi den inte.',
                    'Native advertising för SEO är här samma flöde: du väljer publiceringen, betalar i EUR och får URL:en. Vi lovar inte Google News.',
                ],
                'points' => [
                    [
                        'title' => 'Märkning som sponsrat',
                        'body' => 'Många sajter kräver rel sponsored eller en synlig märkning. Följ regeln på katalogregeln.',
                    ],
                    [
                        'title' => 'Priset',
                        'body' => 'Vad ett sponsrat inlägg kostar beror på sajten. Se <a href="'.$prices.'">priserna</a> för modellen och katalogen för aktuella belopp.',
                    ],
                    [
                        'title' => 'Innehåll',
                        'body' => 'Du skickar texten eller briefingen. Publishern publicerar på sin egen sajt och skickar live-URL:en.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Advertorial kontra gästinlägg',
                        'body' => 'I praktiken är båda en betald publicering med en länk. Skillnaden är redaktionell. Betalningen är densamma. <a href="'.$guest.'">Köp gästinlägg</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Finns det en enda taxa för en advertorial?',
                        'a' => 'Nej. Varje katalogregel har sitt eget pris, i euro.',
                    ],
                    [
                        'q' => 'Placerar ni på alla tidningar?',
                        'a' => 'Bara på sajter som ligger i katalogen och accepterar beställningen.',
                    ],
                ],
                'cta_primary' => ['label' => 'Se svenska sajter', 'url' => $register],
                'cta_secondary' => ['label' => 'Digital PR', 'url' => $pr],
                'see_also' => [
                    ['label' => 'Digital PR', 'url' => $pr],
                    ['label' => 'Priser', 'url' => $prices],
                    ['label' => 'Köp gästinlägg', 'url' => $guest],
                ],
            ],
            'kopa-backlinks' => [
                'kicker' => 'Redaktionella backlinks',
                'h1' => 'Köp backlinks i Sverige',
                'subtitle' => 'Backlinks här är länkar från publiceringar du köper på riktiga sajter — inte en anonym påse URL:er.',
                'meta_title' => 'Köp backlinks i Sverige | SEOLinkBuildings',
                'meta_description' => 'Svenska backlinks från gästinlägg på riktiga sajter. Pris i EUR, dofollow eller sponsored på katalogregeln, live-URL i beställningen.',
                'teaser_countries' => ['se'],
                'teaser_title' => 'Sajter .se för backlinks',
                'teaser_subtitle' => 'Förhandsvisning av katalogregler med huvudland Sverige. Visad traffic är uppgiven, inte utlovad.',
                'intro' => [
                    '«Köp backlinks», «köp backlink» och «köp länkar SEO» söker samma sak: en länk på en publicerad sida. Hos oss får du den via ett gästinlägg eller ett sponsrat inlägg, med ankaret från briefingen.',
                    'Tematiska backlinks betyder att du väljer sajtens nisch. «Med riktig traffic» betyder att du tittar på traffic på katalogregeln — vi garanterar inte besök.',
                ],
                'points' => [
                    [
                        'title' => 'Kvalitet du kan läsa',
                        'body' => 'Land, språk, nisch, DA/DR och pris står på regeln. Vi säljer inte «kvalitetsbacklinks» som etikett utan sajt.',
                    ],
                    [
                        'title' => 'Dofollow är inte standard',
                        'body' => 'Filtrera på länktyp. Publishern ansvarar för den live HTML:en.',
                    ],
                    [
                        'title' => 'Ingen PBN',
                        'body' => 'Vi säljer inte privata nätverk. Riskerna står i <a href="'.$guide.'">handledningen</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Så väljer du',
                        'body' => 'Börja i <a href="'.$market.'">katalogen</a>, filtrera på Sverige, jämför pris och ankarregler. Därefter <a href="'.$guest.'">köp gästinlägg</a> eller ett <a href="'.$sponsored.'">sponsrat inlägg</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kan jag bara köpa dofollow?',
                        'a' => 'Du kan filtrera på erbjudanden som nämner dofollow. Kontrollera attributet på den live sidan.',
                    ],
                    [
                        'q' => 'Infogar ni en länk i en befintlig artikel?',
                        'a' => 'Inte som SKU för niche edits. Vissa sajter säljer ett tillägg på startsidan med tidsgräns.',
                    ],
                    [
                        'q' => 'Vad kostar backlinks i Sverige?',
                        'a' => 'Det beror på sajten. <a href="'.$prices.'">Priser</a> förklarar modellen; katalogen visar aktuella belopp.',
                    ],
                ],
                'cta_primary' => ['label' => 'Jämför sajter', 'url' => $register],
                'cta_secondary' => ['label' => 'Linkbuilding', 'url' => $lb],
                'see_also' => [
                    ['label' => 'Priser', 'url' => $prices],
                    ['label' => 'Köp gästinlägg', 'url' => $guest],
                    ['label' => 'Niche edits', 'url' => $niche],
                ],
            ],
            'linkbuilding' => [
                'kicker' => 'Kampanjer',
                'h1' => 'Linkbuilding i Sverige',
                'subtitle' => 'Du bygger kampanjen ur katalogen, publicering för publicering, eller väljer ett styrt digital-PR-paket under priser. Ingen «billig länkbyggnad» utan sajt.',
                'meta_title' => 'Linkbuilding i Sverige | SEOLinkBuildings',
                'meta_description' => 'Linkbuilding Sverige: self-service-katalog i EUR, följda beställningar och digital-PR-paket. Inga anonyma länkpaket.',
                'teaser_countries' => ['se'],
                'teaser_title' => 'Urval till kampanjer i Sverige',
                'teaser_subtitle' => 'Samma katalogregler som på gästinläggssidan. Europa filtrerar du efter inloggning.',
                'intro' => [
                    'Linkbuilding här betyder att du väljer publishers, betalar och följer URL:en. Det är inget abonnemang som «gör SEO» åt dig.',
                    'Europeisk länkbyggnad använder samma saldo. Sverige är landfiltret, inte en separat produkt. En byrå som arbetar internt kan använda samma katalog.',
                ],
                'points' => [
                    [
                        'title' => 'Self-service',
                        'body' => 'Strategin är ditt val av sajter, ankare och tempo. Katalogen är källan. Månadsvis linkbuilding är det tempo du själv sätter.',
                    ],
                    [
                        'title' => 'Linkbuilding-paket',
                        'body' => 'De numrerade paketen under priser är styrda digital-PR-kampanjer, inte en säck URL:er.',
                    ],
                    [
                        'title' => 'White label',
                        'body' => 'Byrån kan beställa på eget konto. Vi levererar ingen portal med er logotyp. Detaljer: <a href="'.$agencies.'">till byråer</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Gästinlägg, niche edits och digital PR',
                        'body' => 'Ett gästinlägg är en ny artikel. Niche edits (länk i befintligt innehåll) säljer vi inte som SKU. Digital PR är kampanjen; på marknadsplatsen betalar du fortfarande publiceringen.',
                    ],
                    [
                        'h2' => 'Så startar en kampanj',
                        'body' => 'Konto, saldo i euro, filter Sverige, beställning. Flödet: <a href="'.$how.'">så fungerar det</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Har ni billig linkbuilding?',
                        'a' => 'Priset är katalogregelns. Vi har inget separat «billigt» lager vid sidan av katalogen.',
                    ],
                    [
                        'q' => 'Gör ni också strategin?',
                        'a' => 'Handledningen förklarar risker och ankare. Utförandet i self-service är ditt.',
                    ],
                ],
                'cta_primary' => ['label' => 'Öppna katalogen', 'url' => $register],
                'cta_secondary' => ['label' => 'Se priserna', 'url' => $prices],
                'see_also' => [
                    ['label' => 'Till byråer', 'url' => $agencies],
                    ['label' => 'Digital PR', 'url' => $pr],
                    ['label' => 'Handledningen', 'url' => $guide],
                ],
            ],
            'byraer' => [
                'kicker' => 'B2B-konto',
                'h1' => 'Linkbuilding till byråer i Sverige',
                'subtitle' => 'Self-service-katalog för SEO-byråer, återförsäljare och team som vidarefakturerar. Saldo i euro, följda beställningar, fakturor i annonsörfaktureringen.',
                'meta_title' => 'Linkbuilding till byråer | SEOLinkBuildings',
                'meta_description' => 'Gästinlägg till byråer i Sverige: katalog i EUR, fakturor, beställningar per varumärke — utan resellerportal med er logotyp.',
                'teaser_countries' => ['se'],
                'teaser_title' => 'Urval ni kan vidarefakturera',
                'teaser_subtitle' => 'Samma katalogregler som för en intern annonsör. Kontot är ert; varumärken ligger i projekt och beställningar.',
                'intro' => [
                    '«Gästinlägg till byråer», «white label linkbuilding Sverige» och «reseller backlinks» söker en part som utför. Här behåller byrån rodret: ni väljer sajter, betalar och lämnar live-URL:en till kunden.',
                    'Operativt white label betyder att slutkunden inte behöver ett konto. Det är inget återförsäljarprogram med ert varumärke på den offentliga sidan.',
                ],
                'points' => [
                    [
                        'title' => 'Ett saldo, flera kampanjer',
                        'body' => 'Ni fyller på euro (kort eller överföring, när metoden är aktiv) och fördelar plånboken på beställningar.',
                    ],
                    [
                        'title' => 'Beställning och faktura',
                        'body' => 'Fakturor för insättningar eller beställningar hämtar ni i annonsörfaktureringen när produkten ställer ut dem. Bolagsuppgifterna är de brittiska (Topurlz Ltd). Vi hittar inte på ett svenskt org.nr.',
                    ],
                    [
                        'title' => 'Arbetsyta för SEO-team',
                        'body' => 'Filter, metrics, chatt på beställningen och live-URL. Efter inloggning är dashboardet engelskt för alla roller.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Byrå kontra marknadsplats',
                        'body' => 'En byrå väljer sajter åt kunden. En marknadsplats visar sajter för köparen. SEOLinkBuildings är det senare. Om ert team är byrån stannar urvalet hos er, och katalogen är källan.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kan vi dölja marknadsplatsen för kunden?',
                        'a' => 'Ja, genom att arbeta från ert konto. Vi levererar ingen white-label-portal med ert varumärke.',
                    ],
                    [
                        'q' => 'Ställer ni ut fakturor med svenskt momsnummer?',
                        'a' => 'Faktureringen följer det brittiska bolaget. Hämta dokumenten och stäm av med redovisningen. Vi hittar inte på org.nr eller momsnummer.',
                    ],
                ],
                'cta_primary' => ['label' => 'Skapa byråkonto', 'url' => $register],
                'cta_secondary' => ['label' => 'Katalog', 'url' => $market],
                'see_also' => [
                    ['label' => 'Linkbuilding', 'url' => $lb],
                    ['label' => 'Priser', 'url' => $prices],
                    ['label' => 'Digital PR', 'url' => $pr],
                ],
            ],
            'digital-pr' => [
                'kicker' => 'PR i digitala medier',
                'h1' => 'Digital PR i Sverige',
                'subtitle' => 'Digital-PR-kampanjer som publiceringar på sajter i marknadsplatsen, plus styrda paket under priser. Inget löfte om Google News.',
                'meta_title' => 'Digital PR i Sverige | SEOLinkBuildings',
                'meta_description' => 'Digital PR Sverige: publiceringar från katalogen, saldo i euro, live-URL och styrda paket — utan News- eller pressgaranti.',
                'teaser_countries' => ['se'],
                'teaser_title' => 'Svenska sajter i katalogen',
                'teaser_subtitle' => 'Vissa publishers liknar ett mediakit; alla är inte en dagstidning. Nisch och språk filtrerar du efter inloggning.',
                'intro' => [
                    '«Digital PR Sverige», «köp pressmeddelande» och «pressmeddelande SEO» blandar PR med länkbyggnad. Här köper du publiceringar på sajter som faktiskt ligger i katalogen.',
                    'Styrda paket (belopp står under Priser; i dag från 499 €/månad på basplanen, om den fortfarande visas) är teamutförande, inte en knapp «kom in i en landsomfattande tidning».',
                ],
                'points' => [
                    [
                        'title' => 'Medier bara om de ligger i katalogen',
                        'body' => 'Vi har ingen Google News-kanal. Ett gästinlägg «i pressen» finns bara om den sajten är en katalogregel och accepterar briefingen.',
                    ],
                    [
                        'title' => 'Brand mentions',
                        'body' => 'Ett omnämnande kan komma från en publicering. Vi säljer inte «brand mention» som SKU utan URL.',
                    ],
                    [
                        'title' => 'Kampanjer',
                        'body' => 'Self-service: du väljer sajter. Styrt: paketen under priser.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'PR och SEO, utan överdrift',
                        'body' => 'En användbar publicering har läsare, kontext och en länk (eller ett omnämnande) som hänger ihop. Den ersätter inte en nyhet. Katalog: <a href="'.$market.'">listan över sajter</a>. Paket: <a href="'.$prices.'">priser</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Garanterar ni en artikel i pressen?',
                        'a' => 'Nej. Vi levererar URL:en på den sajt du har beställt, om publishern accepterar.',
                    ],
                    [
                        'q' => 'Är det något annat än en advertorial?',
                        'a' => 'Advertorialen är publiceringen. Digital PR är kampanjen. På marknadsplatsen betalar du fortfarande publiceringen. <a href="'.$sponsored.'">Sponsrat inlägg</a>.',
                    ],
                ],
                'cta_primary' => ['label' => 'Se paket och katalog', 'url' => $prices],
                'cta_secondary' => ['label' => 'Registrera', 'url' => $register],
                'see_also' => [
                    ['label' => 'Sponsrat inlägg', 'url' => $sponsored],
                    ['label' => 'Till byråer', 'url' => $agencies],
                    ['label' => 'Köp gästinlägg', 'url' => $guest],
                ],
            ],
            'niche-edits' => [
                'kicker' => 'Inte en separat produkt',
                'h1' => 'Niche edits i Sverige — och vad vi säljer',
                'subtitle' => 'Niche edits (infoga länk i en redan publicerad artikel) är inte en SKU på SEOLinkBuildings. Här är gränsen mot ett gästinlägg, plus riskerna.',
                'meta_title' => 'Niche edits i Sverige förklarat | SEOLinkBuildings',
                'meta_description' => 'Vad niche edits och «infoga länk i artikel» är, när det är riskabelt, och varför vi i Sverige säljer redaktionella publiceringar — inte infogning i en främmande artikel.',
                'teaser_countries' => ['se'],
                'teaser_title' => 'Redaktionella sajter, inte infogningsnätverk',
                'teaser_subtitle' => 'Förhandsvisning av aktiva katalogregler i Sverige. Standardprodukten är en ny artikel med en länk i texten.',
                'intro' => [
                    'En niche edit är en länk i en redan publicerad artikel, ofta för att URL:en redan är indexerad. «Infoga länk i artikel» söker precis det.',
                    'Vi säljer det inte som produkt. Standardbeställningen är en ny publicering (gästinlägg eller sponsrat inlägg) med briefing och live-URL. Vissa sajter erbjuder ett tillägg på startsidan med tidsgräns; det står på katalogregeln.',
                ],
                'points' => [
                    [
                        'title' => 'Varför vi inte säljer det',
                        'body' => 'En länk i en artikel du inte har skrivit är svårare att kontrollera och oftare i konflikt med sajtens egna regler. Vi vill inte lova en SKU vi inte kan leverera enhetligt.',
                    ],
                    [
                        'title' => 'Vad du kan köpa i stället',
                        'body' => 'Ett <a href="'.$guest.'">gästinlägg</a> eller ett <a href="'.$sponsored.'">sponsrat inlägg</a> med ankaret i den nya texten.',
                    ],
                    [
                        'title' => 'Risk',
                        'body' => 'Infogningar i gamla artiklar kan försvinna, byta attribut eller träffa irrelevanta ankare. Läs <a href="'.$guide.'">handledningen</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Kontextuella backlinks',
                        'body' => 'En kontextlänk i ett nytt gästinlägg är fortfarande en redaktionell länk. Skillnaden är att du känner briefingen och får live-URL:en på beställningen. <a href="'.$links.'">Köp backlinks</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kan jag be publishern infoga i en gammal artikel?',
                        'a' => 'Bara om katalogregeln beskriver det. Det är inte vår standard-SKU.',
                    ],
                ],
                'cta_primary' => ['label' => 'Köp ett gästinlägg i stället', 'url' => $guest],
                'cta_secondary' => ['label' => 'Katalog', 'url' => $market],
                'see_also' => [
                    ['label' => 'Linkbuilding', 'url' => $lb],
                    ['label' => 'Köp gästinlägg', 'url' => $guest],
                    ['label' => 'Sponsrat inlägg', 'url' => $sponsored],
                ],
            ],
            'handledning' => [
                'kicker' => 'En handledning',
                'h1' => 'Handledningen: gästinlägg och linkbuilding',
                'subtitle' => 'Vad ett gästinlägg är, hur du köper backlinks, dofollow kontra nofollow, ankare, PBN och risker — på en sida, inte i tunna artiklar.',
                'meta_title' => 'Handledningen gästinlägg och linkbuilding | SEOLinkBuildings',
                'meta_description' => 'Kort handledning: vad ett gästinlägg är, hur du köper backlinks, dofollow vs nofollow, ankare, rel sponsored och varför PBN inte är vår produkt.',
                'teaser_countries' => ['se'],
                'teaser_title' => 'Från förklaring till katalog',
                'teaser_subtitle' => 'Efter handledningen ligger de riktiga sajterna i den svenska katalogen, med ett pris per publicering.',
                'intro' => [
                    'Den här handledningen täcker informativa sökningar (vad är linkbuilding, hur köper man backlinks, är backlinks lagliga, ankartext) utan en ny sida för varje mening.',
                    'Dashboardet efter inloggning förblir engelskt. Den offentliga sidan är svenska.',
                ],
                'points' => [
                    [
                        'title' => 'Vad är ett gästinlägg?',
                        'body' => 'En artikel på någon annans sajt, typiskt med en länk till dig, mot betalning eller byte. Hos oss är betalningen i EUR, per beställning.',
                    ],
                    [
                        'title' => 'Dofollow, nofollow, sponsored',
                        'body' => 'Dofollow ger vanligtvis en signal vidare. Nofollow och sponsored talar om att länken är märkt. Google behandlar rel sponsored som en betald länk. Välj det katalogregeln anger.',
                    ],
                    [
                        'title' => 'Ankare',
                        'body' => 'Ett exakt ankare, upprepat på många sajter, är ett riskabelt mönster. Variera formuleringen och håll ankaret relevant för målsidan.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Hur man köper backlinks',
                        'body' => 'Konto, saldo, land- och nischfilter, briefing, godkännande av live-URL. Drift: <a href="'.$how.'">så fungerar det</a>. Kommersiell sida: <a href="'.$links.'">köp backlinks</a>.',
                    ],
                    [
                        'h2' => 'PBN kontra gästinlägg',
                        'body' => 'Ett PBN är ett nätverk du styr för att skicka länkar. Det säljer vi inte. Ett gästinlägg är en publicering på en sajt med egna läsare. Kan du inte nämna sajten är det inte den här produkten.',
                    ],
                    [
                        'h2' => 'Risker med att köpa backlinks',
                        'body' => 'Sajter utan riktig traffic, aggressiva ankare, länkar som försvinner, saknad sponsored-märkning, uppblåsta metrics. Kolla katalogregeln och live-URL:en. Vi lovar inga placeringar.',
                    ],
                    [
                        'h2' => 'Strategi, kort',
                        'body' => 'Få relevanta publiceringar slår en volym länkar utan kontext. Till utförande: <a href="'.$lb.'">linkbuilding</a>, <a href="'.$guest.'">köp gästinlägg</a>, <a href="'.$publisher.'">bli publisher</a> om du säljer plats.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Är det här en linkbuilding-guide 2026?',
                        'a' => 'Nej. Det är en produktsida som förklarar marknadsplatsens nuvarande regler, inte en trendkalender.',
                    ],
                    [
                        'q' => 'Var ser jag priserna?',
                        'a' => 'Modellen står under <a href="'.$prices.'">priser</a>. Aktuella belopp står i katalogen efter registrering.',
                    ],
                ],
                'cta_primary' => ['label' => 'Se urvalet', 'url' => $register],
                'cta_secondary' => ['label' => 'Köp gästinlägg', 'url' => $guest],
                'see_also' => [
                    ['label' => 'Linkbuilding', 'url' => $lb],
                    ['label' => 'Köp backlinks', 'url' => $links],
                    ['label' => 'Priser', 'url' => $prices],
                ],
            ],
        ];
    }
}
