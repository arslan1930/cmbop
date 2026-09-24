<?php

namespace App\Support;

/**
 * Norway money / B2B landers (Norwegian Bokmål as used by SEO teams, plus genuine loanwords).
 * Marketplace stays /no/markedsplass and pricing stays /no/priser.
 */
class NorwegianMoneyLanders
{
    use MoneyLanderPages;

    public const LOCALE = 'no';

    /**
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return [
            'marketplace' => '/no/markedsplass',
            'katalog-publishers' => '/no/markedsplass',
            'plattform-gjesteinnlegg' => '/no/markedsplass',
            'guest-post-norway' => '/no/kjope-gjesteinnlegg',
            'gjesteinnlegg-blogger' => '/no/kjope-gjesteinnlegg',
            'kjope-guest-post' => '/no/kjope-gjesteinnlegg',
            'bestille-gjesteinnlegg' => '/no/kjope-gjesteinnlegg',
            'advertorial' => '/no/sponset-artikkel',
            'betalt-artikkel' => '/no/sponset-artikkel',
            'norske-backlinks' => '/no/kjope-backlinks',
            'kjope-lenker' => '/no/kjope-backlinks',
            'hva-koster-gjesteinnlegg' => '/no/priser',
            'linkbuilding-priser' => '/no/priser',
            'white-label-linkbuilding' => '/no/for-byraer',
            'pressemelding' => '/no/digital-pr',
            'sett-inn-lenke' => '/no/niche-edits',
            'guide' => '/no/veiledning',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function ui(): array
    {
        return [
            'cluster_title' => 'Sider om gjesteinnlegg, backlinks og lenkebygging i Norge',
            'from' => 'Fra',
            'price_note' => 'Laveste aktuelle pris i euro på aktive, verifiserte katalogregler med hovedland Norge. Ikke en fast prisliste.',
            'sites_preview' => 'Nettsteder i forhåndsvisning',
            'count_note' => 'Aktive, verifiserte publishers med Norge som hovedland, når tellingen er tilgjengelig.',
            'th_site' => 'Nettsted',
            'th_country' => 'Land',
            'th_language' => 'Språk',
            'th_from' => 'Fra',
            'teaser_foot' => 'Vi viser DA, DR og prisen i euro når de står på katalogregelen. Manglende metrics forblir tomme.',
            'see_also' => 'Se også',
        ];
    }

    /**
     * @return list<array{slug: string, label: string, url: string}>
     */
    public static function clusterLinks(?string $current = null): array
    {
        return self::filterCluster([
            ['slug' => 'home', 'label' => 'Markedsplass Norge', 'url' => url('/no')],
            ['slug' => 'kjope-gjesteinnlegg', 'label' => 'Kjøp gjesteinnlegg', 'url' => self::url('kjope-gjesteinnlegg')],
            ['slug' => 'sponset-artikkel', 'label' => 'Sponset artikkel', 'url' => self::url('sponset-artikkel')],
            ['slug' => 'markedsplass', 'label' => 'Katalog publishers', 'url' => self::marketingUrl('marketplace')],
            ['slug' => 'linkbuilding', 'label' => 'Linkbuilding', 'url' => self::url('linkbuilding')],
            ['slug' => 'kjope-backlinks', 'label' => 'Kjøp backlinks', 'url' => self::url('kjope-backlinks')],
            ['slug' => 'priser', 'label' => 'Priser', 'url' => self::marketingUrl('pricing')],
            ['slug' => 'for-byraer', 'label' => 'Til byråer', 'url' => self::url('for-byraer')],
            ['slug' => 'digital-pr', 'label' => 'Digital PR', 'url' => self::url('digital-pr')],
            ['slug' => 'niche-edits', 'label' => 'Niche edits', 'url' => self::url('niche-edits')],
            ['slug' => 'veiledning', 'label' => 'Veiledning', 'url' => self::url('veiledning')],
        ], $current);
    }

    /**
     * @return list<array{slug: string, label: string, url: string}>
     */
    public static function footerLinks(): array
    {
        return array_values(array_filter(
            self::clusterLinks('home'),
            static fn (array $item) => ! in_array($item['slug'], ['markedsplass', 'priser'], true)
        ));
    }

    /**
     * @return array{h2: string, body: string, links: string}
     */
    public static function marketplaceCopy(): array
    {
        return [
            'h2' => 'Katalog over norske publishers',
            'body' => 'Her er den offentlige listen over publishers med Norge som hovedland: nisje, språk, DA/DR og pris i euro. Vi indekserer ikke hver filterkombinasjon, og byer har ingen egen URL. Det fulle kataloget med domener åpner etter registrering.',
            'links' => '<a href="'.self::url('kjope-gjesteinnlegg').'">Kjøp gjesteinnlegg i Norge</a> · <a href="'.self::url('kjope-backlinks').'">Kjøp backlinks</a> · <a href="'.self::marketingUrl('pricing').'">Hva koster et gjesteinnlegg</a> · <a href="'.url('/guest-posts-norway').'">Norway inventory (English)</a>',
        ];
    }

    /**
     * @return array{h2: string, body: string}
     */
    public static function pricingCopy(): array
    {
        return [
            'h2' => 'Hva koster et gjesteinnlegg i Norge',
            'body' => 'Vi publiserer ikke en fast PDF-prisliste og dikter ikke opp et norsk org.nr-tillegg: prisen er nettstedets, i euro. «Hva koster et gjesteinnlegg», «linkbuilding-priser» og «pris backlinks» følger katalogreglene du velger. De nummererte pakkene ovenfor er styrte digital-PR-kampanjer, ikke en pose anonyme URL-er. Aktuelle beløp står i <a href="'.self::marketingUrl('marketplace').'">det norske kataloget</a> etter registrering. Hovedkontoret er i London (Topurlz Ltd).',
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
        $guest = self::url('kjope-gjesteinnlegg');
        $sponsored = self::url('sponset-artikkel');
        $links = self::url('kjope-backlinks');
        $lb = self::url('linkbuilding');
        $agencies = self::url('for-byraer');
        $pr = self::url('digital-pr');
        $niche = self::url('niche-edits');
        $guide = self::url('veiledning');
        $publisher = self::marketingUrl('become-a-publisher');

        return [
            'kjope-gjesteinnlegg' => [
                'kicker' => 'Gjesteinnlegg med backlink',
                'h1' => 'Kjøp gjesteinnlegg på norske blogger',
                'subtitle' => 'Velg verifiserte norske og europeiske publishers, sammenlign nisje, DA/DR og prisen i euro, send briefingen og følg live-URL i ordren.',
                'meta_title' => 'Kjøp gjesteinnlegg i Norge | SEOLinkBuildings',
                'meta_description' => 'Kjøp gjesteinnlegg på norske blogger og .no-nettsteder: filtrer nisje, DA/DR og pris i euro, velg dofollow eller sponsored og få live-URL.',
                'teaser_countries' => ['no'],
                'teaser_title' => 'Nettsteder til gjesteinnlegg i Norge',
                'teaser_subtitle' => 'Maskert forhåndsvisning av aktive katalogregler i Norge. Domenene ser du etter registrering.',
                'intro' => [
                    'SEOLinkBuildings er en self-service-markedsplass, ikke en ugjennomsiktig pakke gjesteinnlegg. Du velger nettstedet — ofte .no, ofte på norsk — betaler i euro fra saldoen og holder briefing, chat og live-URL i samme ordre. Hovedkontoret er i London (Topurlz Ltd); vi dikter ikke opp et org.nr i Norge.',
                    '«Kjøp gjesteinnlegg», «gjesteinnlegg på norske blogger» og «kjøp guest post» er samme hensikt: en betalt publikasjon på et nettsted du ikke eier, med skrevne regler for lengde, lenker og levering.',
                ],
                'points' => [
                    [
                        'title' => 'Publishers, ikke en fantomliste',
                        'body' => 'Hver rad er et nettsted med nisje, språk, land, DA/DR, oppgitt trafikk og pris. Vi selger ikke PBN og ikke «pakker med 50 lenker».',
                    ],
                    [
                        'title' => 'Slik bestiller du',
                        'body' => 'Du oppretter en konto, filtrerer Norge, legger nettstedet i handlekurven og sender tittel, tekst eller briefing pluss anker. Publisheren leverer live-URL til godkjenning.',
                    ],
                    [
                        'title' => 'Dofollow og sponsored',
                        'body' => 'Lenkeattributten står på katalogregelen. Mange medier markerer betalte publikasjoner. Les lenketypen før du bestiller — det finnes ikke «dofollow for enhver pris».',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Trafikk, nisje og permanente lenker',
                        'body' => 'Et gjesteinnlegg «med trafikk» betyr at katalogregelen viser trafikken publisheren har oppgitt — ikke en garanti for besøk. Nisjer (helse, finance, tech, forsikring, eiendom, reise, ecommerce) filtrerer du i katalogen, ikke på egne URL-er. «Permanent» avhenger av nettstedets regler. Les katalogregelen.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kan jeg bare publisere på .no-nettsteder?',
                        'a' => 'Ja. Du filtrerer landet Norge. Det europeiske kataloget ligger i samme saldo i euro. Sverige og Danmark er egne filtre.',
                    ],
                    [
                        'q' => 'Skriver dere artikkelen?',
                        'a' => 'Standardordren bruker briefingen din. Noen katalogregler tilbyr redaksjon; det ser du på nettstedet, ikke som en oppdiktet tilleggstjeneste her.',
                    ],
                    [
                        'q' => 'Finnes det sider for Oslo?',
                        'a' => 'Nei. Byer har ingen egen URL. Du filtrerer landet.',
                    ],
                ],
                'cta_primary' => ['label' => 'Opprett konto og se publishers', 'url' => $register],
                'cta_secondary' => ['label' => 'Katalog publishers', 'url' => $market],
                'see_also' => [
                    ['label' => 'Kjøp backlinks', 'url' => $links],
                    ['label' => 'Sponset artikkel', 'url' => $sponsored],
                    ['label' => 'Priser', 'url' => $prices],
                    ['label' => 'Veiledning', 'url' => $guide],
                ],
            ],
            'sponset-artikkel' => [
                'kicker' => 'Advertorial',
                'h1' => 'Kjøp sponset artikkel i Norge',
                'subtitle' => 'Du kjøper en betalt artikkel på et nettsted i katalogen, med pris i euro og live-URL. Vi selger ikke et generisk pressemelding-abonnement.',
                'meta_title' => 'Sponset artikkel i Norge | SEOLinkBuildings',
                'meta_description' => 'Kjøp sponset artikkel eller advertorial på norske nettsteder. Pris i euro per katalogregel, briefing og live-URL — uten oppdiktet pressekontor.',
                'teaser_countries' => ['no'],
                'teaser_title' => 'Medier til gjesteinnlegg og advertorial',
                'teaser_subtitle' => 'Samme forhåndsvisning av publishers i Norge. En advertorial finnes bare hvis nettstedet ligger i katalogen.',
                'intro' => [
                    '«Kjøp sponset artikkel», «betalt artikkel SEO» og «advertorial» beskriver en betalt publikasjon, ikke en presselinje vi ikke har. Står domenet ikke i katalogen, selger vi det ikke.',
                    'Native advertising til SEO er her samme forløp: du velger publikasjonen, betaler i euro og får URL-en. Vi lover ikke Google News.',
                ],
                'points' => [
                    [
                        'title' => 'Markering som sponset',
                        'body' => 'Mange nettsteder krever rel sponsored eller et synlig merke. Følg regelen på katalogregelen.',
                    ],
                    [
                        'title' => 'Prisen',
                        'body' => 'Hva en sponset artikkel koster, avhenger av nettstedet. Se <a href="'.$prices.'">prisene</a> for modellen og katalogen for aktuelle beløp.',
                    ],
                    [
                        'title' => 'Innhold',
                        'body' => 'Du sender teksten eller briefingen. Publisheren publiserer på sitt eget nettsted og sender live-URL.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Advertorial versus gjesteinnlegg',
                        'body' => 'I praksis er begge en betalt publikasjon med en lenke. Forskjellen er redaksjonell. Avregningen er den samme. <a href="'.$guest.'">Kjøp gjesteinnlegg</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Finnes det én sats for en advertorial?',
                        'a' => 'Nei. Hver katalogregel har sin egen pris, i euro.',
                    ],
                    [
                        'q' => 'Plasserer dere på alle aviser?',
                        'a' => 'Bare på nettsteder som ligger i katalogen og aksepterer ordren.',
                    ],
                ],
                'cta_primary' => ['label' => 'Se norske nettsteder', 'url' => $register],
                'cta_secondary' => ['label' => 'Digital PR', 'url' => $pr],
                'see_also' => [
                    ['label' => 'Digital PR', 'url' => $pr],
                    ['label' => 'Priser', 'url' => $prices],
                    ['label' => 'Kjøp gjesteinnlegg', 'url' => $guest],
                ],
            ],
            'kjope-backlinks' => [
                'kicker' => 'Redaksjonelle backlinks',
                'h1' => 'Kjøp backlinks i Norge',
                'subtitle' => 'Backlinks her er lenker fra publikasjoner du kjøper på ekte nettsteder — ikke en anonym pose URL-er.',
                'meta_title' => 'Kjøp backlinks i Norge | SEOLinkBuildings',
                'meta_description' => 'Norske backlinks fra gjesteinnlegg på ekte nettsteder. Pris i euro, dofollow eller sponsored på katalogregelen, live-URL i ordren.',
                'teaser_countries' => ['no'],
                'teaser_title' => 'Nettsteder .no til backlinks',
                'teaser_subtitle' => 'Forhåndsvisning av katalogregler med hovedland Norge. Vist trafikk er oppgitt, ikke lovet.',
                'intro' => [
                    '«Kjøp backlinks», «kjøp backlink» og «kjøp lenker SEO» søker det samme: en lenke på en publisert side. Hos oss får du det via et gjesteinnlegg eller en sponset artikkel, med ankeret fra briefingen.',
                    'Tematiske backlinks betyr at du velger nettstedets nisje. «Med ekte trafikk» betyr at du ser på trafikken på katalogregelen — vi garanterer ikke besøk.',
                ],
                'points' => [
                    [
                        'title' => 'Kvalitet du kan lese',
                        'body' => 'Land, språk, nisje, DA/DR og pris står på katalogregelen. Vi selger ikke «kvalitetsbacklinks» som merkelapp uten nettsted.',
                    ],
                    [
                        'title' => 'Dofollow er ikke standard',
                        'body' => 'Filtrer på lenketype. Publisheren står for den live HTML-en.',
                    ],
                    [
                        'title' => 'Ingen PBN',
                        'body' => 'Vi selger ikke private nettverk. Risikoene står i <a href="'.$guide.'">veiledningen</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Slik velger du',
                        'body' => 'Start i <a href="'.$market.'">katalogen</a>, filtrer Norge, sammenlign pris og ankerregler. Deretter <a href="'.$guest.'">kjøp gjesteinnlegg</a> eller en <a href="'.$sponsored.'">sponset artikkel</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kan jeg bare kjøpe dofollow?',
                        'a' => 'Du kan filtrere på tilbud som nevner dofollow. Sjekk attributten på den live siden.',
                    ],
                    [
                        'q' => 'Setter dere inn en lenke i en eksisterende artikkel?',
                        'a' => 'Ikke som SKU for niche edits. Noen nettsteder selger et homepage-tillegg med tidsfrist.',
                    ],
                    [
                        'q' => 'Hva koster backlinks i Norge?',
                        'a' => 'Det avhenger av nettstedet. <a href="'.$prices.'">Priser</a> forklarer modellen; katalogen viser aktuelle beløp.',
                    ],
                ],
                'cta_primary' => ['label' => 'Sammenlign nettsteder', 'url' => $register],
                'cta_secondary' => ['label' => 'Linkbuilding', 'url' => $lb],
                'see_also' => [
                    ['label' => 'Priser', 'url' => $prices],
                    ['label' => 'Kjøp gjesteinnlegg', 'url' => $guest],
                    ['label' => 'Niche edits', 'url' => $niche],
                ],
            ],
            'linkbuilding' => [
                'kicker' => 'Kampanjer',
                'h1' => 'Linkbuilding i Norge',
                'subtitle' => 'Du bygger kampanjen ut av katalogen, publikasjon for publikasjon, eller velger en styrt digital-PR-pakke under priser. Ingen «billige backlinks» uten nettsted.',
                'meta_title' => 'Linkbuilding i Norge | SEOLinkBuildings',
                'meta_description' => 'Linkbuilding Norge: self-service-katalog i euro, fulgte ordrer og digital-PR-pakker. Ingen anonyme lenkepakker.',
                'teaser_countries' => ['no'],
                'teaser_title' => 'Utvalg til kampanjer i Norge',
                'teaser_subtitle' => 'Samme katalogregler som på gjesteinnlegg-siden. Europa filtrerer du etter innlogging.',
                'intro' => [
                    'Linkbuilding her betyr at du velger publishers, betaler og følger URL-en. Det er ikke et abonnement som «gjør SEO» for deg.',
                    'Europeisk lenkebygging bruker samme saldo i euro. Norge er landfilteret, ikke et separat produkt. Et byrå som jobber internt, kan bruke samme katalog.',
                ],
                'points' => [
                    [
                        'title' => 'Self-service',
                        'body' => 'Strategien er ditt valg av nettsteder, ankre og tempo. Katalogen er kilden. Månedlig linkbuilding er tempoet du selv setter.',
                    ],
                    [
                        'title' => 'Linkbuilding-pakker',
                        'body' => 'De nummererte pakkene under priser er styrte digital-PR-kampanjer, ikke en sekk URL-er.',
                    ],
                    [
                        'title' => 'White label',
                        'body' => 'Byrået kan bestille på egen konto. Vi leverer ikke en portal med deres logo. Detaljer: <a href="'.$agencies.'">til byråer</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Gjesteinnlegg, niche edits og digital PR',
                        'body' => 'Et gjesteinnlegg er en ny artikkel. Niche edits (lenke i eksisterende innhold) selger vi ikke som SKU. Digital PR er kampanjen; i markedsplassen betaler du fortsatt publikasjonen.',
                    ],
                    [
                        'h2' => 'Slik starter en kampanje',
                        'body' => 'Konto, saldo i euro, filter Norge, ordre. Forløpet: <a href="'.$how.'">slik fungerer det</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Har dere billig linkbuilding?',
                        'a' => 'Prisen er katalogregelens. Vi har ikke et separat «billig» lag ved siden av katalogen.',
                    ],
                    [
                        'q' => 'Lager dere også strategien?',
                        'a' => 'Veiledningen forklarer risiko og ankre. Utførelsen i self-service er din.',
                    ],
                ],
                'cta_primary' => ['label' => 'Åpne katalogen', 'url' => $register],
                'cta_secondary' => ['label' => 'Se prisene', 'url' => $prices],
                'see_also' => [
                    ['label' => 'Til byråer', 'url' => $agencies],
                    ['label' => 'Digital PR', 'url' => $pr],
                    ['label' => 'Veiledning', 'url' => $guide],
                ],
            ],
            'for-byraer' => [
                'kicker' => 'B2B-konto',
                'h1' => 'Linkbuilding til byråer i Norge',
                'subtitle' => 'Self-service-katalog til SEO-byråer, resellers og team som viderefakturerer. Saldo i euro, fulgte ordrer, fakturaer i annonsørfaktureringen.',
                'meta_title' => 'Linkbuilding til byråer | SEOLinkBuildings',
                'meta_description' => 'Gjesteinnlegg til byråer i Norge: katalog i euro, fakturaer, ordrer per merkevare — uten resellerportal med deres logo.',
                'teaser_countries' => ['no'],
                'teaser_title' => 'Utvalg dere kan viderefakturere',
                'teaser_subtitle' => 'Samme katalogregler som for en intern annonsør. Kontoen er deres; merkevarer ligger i prosjekter og ordrer.',
                'intro' => [
                    '«Gjesteinnlegg til byråer», «white label linkbuilding Norge» og «reseller backlinks» søker en part som utfører. Her beholder byrået styringen: dere velger nettsteder, betaler og leverer live-URL til kunden.',
                    'Operasjonelt white label betyr at sluttkunden ikke trenger en konto. Det er ikke et resellerprogram med deres merkevare på den offentlige siden.',
                ],
                'points' => [
                    [
                        'title' => 'Én saldo, flere kampanjer',
                        'body' => 'Dere legger euro (kort eller overføring, når metoden er aktiv) og fordeler saldoen på ordrer.',
                    ],
                    [
                        'title' => 'Ordre og faktura',
                        'body' => 'Fakturaer for innskudd eller ordrer henter dere i annonsørfaktureringen, når produktet utsteder dem. Selskapsopplysningene er de britiske (Topurlz Ltd). Vi dikter ikke opp et norsk org.nr.',
                    ],
                    [
                        'title' => 'Arbeidsflate til SEO-team',
                        'body' => 'Filtre, metrics, chat på ordren og live-URL. Etter innlogging er dashboardet på engelsk for alle roller.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Byrå versus markedsplass',
                        'body' => 'Et byrå velger nettsteder til kunden. En markedsplass viser nettsteder til kjøperen. SEOLinkBuildings er det siste. Hvis teamet deres er byrået, blir utvalget hos dere, og katalogen er kilden.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kan vi skjule markedsplassen for kunden?',
                        'a' => 'Ja, ved å jobbe fra deres konto. Vi leverer ikke en white-label-portal med deres merkevare.',
                    ],
                    [
                        'q' => 'Utsteder dere fakturaer med norsk MVA-nummer?',
                        'a' => 'Faktureringen følger det britiske selskapet. Hent dokumentene og avklar med regnskapet. Vi dikter ikke opp org.nr eller MVA-nummer.',
                    ],
                ],
                'cta_primary' => ['label' => 'Opprett byråkonto', 'url' => $register],
                'cta_secondary' => ['label' => 'Katalog', 'url' => $market],
                'see_also' => [
                    ['label' => 'Linkbuilding', 'url' => $lb],
                    ['label' => 'Priser', 'url' => $prices],
                    ['label' => 'Digital PR', 'url' => $pr],
                ],
            ],
            'digital-pr' => [
                'kicker' => 'PR i digitale medier',
                'h1' => 'Digital PR i Norge',
                'subtitle' => 'Digital-PR-kampanjer som publikasjoner på nettsteder i markedsplassen, pluss styrte pakker under priser. Ingen løfte om Google News.',
                'meta_title' => 'Digital PR i Norge | SEOLinkBuildings',
                'meta_description' => 'Digital PR Norge: publikasjoner fra katalogen, saldo i euro, live-URL og styrte pakker — uten News- eller pressegaranti.',
                'teaser_countries' => ['no'],
                'teaser_title' => 'Norske nettsteder i katalogen',
                'teaser_subtitle' => 'Noen publishers ligner et mediakit; ikke alle er en dagsavis. Nisje og språk filtrerer du etter innlogging.',
                'intro' => [
                    '«Digital PR Norge», «kjøp pressemelding» og «pressemelding SEO» blander PR med lenkebygging. Her kjøper du publikasjoner på nettsteder som faktisk ligger i katalogen.',
                    'Styrte pakker (beløp står under Priser; i dag fra 499 €/måned på basisplanen, hvis den fortsatt vises) er teamutførelse, ikke en knapp «kom i en landsdekkende avis».',
                ],
                'points' => [
                    [
                        'title' => 'Medier bare hvis de ligger i katalogen',
                        'body' => 'Vi har ingen Google News-kanal. Et gjesteinnlegg «i pressen» finnes bare hvis det nettstedet er en katalogregel og aksepterer briefingen.',
                    ],
                    [
                        'title' => 'Brand mentions',
                        'body' => 'En omtale kan komme fra en publikasjon. Vi selger ikke «brand mention» som SKU uten URL.',
                    ],
                    [
                        'title' => 'Kampanjer',
                        'body' => 'Self-service: du velger nettsteder. Styrt: pakkene under priser.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'PR og SEO, uten oppblåsing',
                        'body' => 'En nyttig publikasjon har lesere, kontekst og en lenke (eller en omtale) som gir mening. Den erstatter ikke en nyhet. Katalog: <a href="'.$market.'">listen over nettsteder</a>. Pakker: <a href="'.$prices.'">priser</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Garantierer dere en artikkel i pressen?',
                        'a' => 'Nei. Vi leverer URL-en på nettstedet du har bestilt, hvis publisheren aksepterer.',
                    ],
                    [
                        'q' => 'Er det noe annet enn en advertorial?',
                        'a' => 'Advertorialen er publikasjonen. Digital PR er kampanjen. I markedsplassen betaler du fortsatt publikasjonen. <a href="'.$sponsored.'">Sponset artikkel</a>.',
                    ],
                ],
                'cta_primary' => ['label' => 'Se pakker og katalog', 'url' => $prices],
                'cta_secondary' => ['label' => 'Registrer', 'url' => $register],
                'see_also' => [
                    ['label' => 'Sponset artikkel', 'url' => $sponsored],
                    ['label' => 'Til byråer', 'url' => $agencies],
                    ['label' => 'Kjøp gjesteinnlegg', 'url' => $guest],
                ],
            ],
            'niche-edits' => [
                'kicker' => 'Ikke et separat produkt',
                'h1' => 'Niche edits i Norge — og hva vi selger',
                'subtitle' => 'Niche edits (sett inn lenke i en allerede publisert artikkel) er ikke en SKU på SEOLinkBuildings. Her er grensen mot et gjesteinnlegg, pluss risikoene.',
                'meta_title' => 'Niche edits i Norge forklart | SEOLinkBuildings',
                'meta_description' => 'Hva niche edits og «sett inn lenke i artikkel» er, når det er risikabelt, og hvorfor vi i Norge selger redaksjonelle publikasjoner — ikke innsetting i en fremmed artikkel.',
                'teaser_countries' => ['no'],
                'teaser_title' => 'Redaksjonelle nettsteder, ikke innsettingsnettverk',
                'teaser_subtitle' => 'Forhåndsvisning av aktive katalogregler i Norge. Standardproduktet er en ny artikkel med en lenke i teksten.',
                'intro' => [
                    'En niche edit er en lenke i en allerede publisert artikkel, ofte fordi URL-en allerede er indeksert. «Sett inn lenke i artikkel» søker nettopp det.',
                    'Vi selger det ikke som produkt. Standardordren er en ny publikasjon (gjesteinnlegg eller sponset artikkel) med briefing og live-URL. Noen nettsteder tilbyr et homepage-tillegg med tidsfrist; det står på katalogregelen.',
                ],
                'points' => [
                    [
                        'title' => 'Hvorfor vi ikke selger det',
                        'body' => 'En lenke i en artikkel du ikke har skrevet, er vanskeligere å kontrollere og oftere i konflikt med nettstedets egne regler. Vi vil ikke love en SKU vi ikke kan levere jevnt.',
                    ],
                    [
                        'title' => 'Hva du kan kjøpe i stedet',
                        'body' => 'Et <a href="'.$guest.'">gjesteinnlegg</a> eller en <a href="'.$sponsored.'">sponset artikkel</a> med ankeret i den nye teksten.',
                    ],
                    [
                        'title' => 'Risiko',
                        'body' => 'Innsettinger i gamle artikler kan forsvinne, endre attributt eller treffe irrelevante ankre. Les <a href="'.$guide.'">veiledningen</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Kontekstuelle backlinks',
                        'body' => 'En kontekstlenke i et nytt gjesteinnlegg er fortsatt en redaksjonell lenke. Forskjellen er at du kjenner briefingen og får live-URL på ordren. <a href="'.$links.'">Kjøp backlinks</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kan jeg be publisheren om å sette inn i en gammel artikkel?',
                        'a' => 'Bare hvis katalogregelen beskriver det. Det er ikke vår standard-SKU.',
                    ],
                ],
                'cta_primary' => ['label' => 'Kjøp et gjesteinnlegg i stedet', 'url' => $guest],
                'cta_secondary' => ['label' => 'Katalog', 'url' => $market],
                'see_also' => [
                    ['label' => 'Linkbuilding', 'url' => $lb],
                    ['label' => 'Kjøp gjesteinnlegg', 'url' => $guest],
                    ['label' => 'Sponset artikkel', 'url' => $sponsored],
                ],
            ],
            'veiledning' => [
                'kicker' => 'Én veiledning',
                'h1' => 'Veiledning: gjesteinnlegg og linkbuilding',
                'subtitle' => 'Hva et gjesteinnlegg er, hvordan du kjøper backlinks, dofollow versus nofollow, ankre, PBN og risiko — på én side, ikke på tynne artikler.',
                'meta_title' => 'Veiledning gjesteinnlegg og linkbuilding | SEOLinkBuildings',
                'meta_description' => 'Kort veiledning: hva et gjesteinnlegg er, hvordan du kjøper backlinks, dofollow vs nofollow, ankre, rel sponsored og hvorfor PBN ikke er vårt produkt.',
                'teaser_countries' => ['no'],
                'teaser_title' => 'Fra forklaring til katalog',
                'teaser_subtitle' => 'Etter veiledningen ligger de ekte nettstedene i det norske kataloget, med en pris per publikasjon.',
                'intro' => [
                    'Denne veiledningen dekker informative søk (hva er linkbuilding, hvordan kjøper man backlinks, er backlinks lovlige, ankertekst) uten en ny side for hver setning.',
                    'Dashboardet etter innlogging forblir på engelsk. Den offentlige siden er på norsk.',
                ],
                'points' => [
                    [
                        'title' => 'Hva er et gjesteinnlegg?',
                        'body' => 'En artikkel på andres nettsted, typisk med en lenke til deg, mot betaling eller bytte. Hos oss er betalingen i euro, per ordre.',
                    ],
                    [
                        'title' => 'Dofollow, nofollow, sponsored',
                        'body' => 'Dofollow gir typisk et signal videre. Nofollow og sponsored forteller at lenken er merket. Google behandler rel sponsored som en betalt lenke. Velg det katalogregelen angir.',
                    ],
                    [
                        'title' => 'Ankre',
                        'body' => 'Et eksakt anker, gjentatt på mange nettsteder, er et risikabelt mønster. Varier formuleringen og hold ankeret relevant for målsiden.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Hvordan kjøper man backlinks',
                        'body' => 'Konto, saldo, land- og nisjefilter, briefing, godkjenning av live-URL. Drift: <a href="'.$how.'">slik fungerer det</a>. Kommersiell side: <a href="'.$links.'">kjøp backlinks</a>.',
                    ],
                    [
                        'h2' => 'PBN versus gjesteinnlegg',
                        'body' => 'Et PBN er et nettverk du styrer for å sende lenker. Det selger vi ikke. Et gjesteinnlegg er en publikasjon på et nettsted med egne lesere. Kan du ikke nevne nettstedet, er det ikke dette produktet.',
                    ],
                    [
                        'h2' => 'Risiko ved å kjøpe backlinks',
                        'body' => 'Nettsteder uten ekte trafikk, aggressive ankre, lenker som forsvinner, manglende sponsored-markering, oppblåste metrics. Sjekk katalogregelen og live-URL. Vi lover ingen plasseringer.',
                    ],
                    [
                        'h2' => 'Strategi, kort',
                        'body' => 'Få relevante publikasjoner slår et volum lenker uten kontekst. Til utførelse: <a href="'.$lb.'">linkbuilding</a>, <a href="'.$guest.'">kjøp gjesteinnlegg</a>, <a href="'.$publisher.'">bli publisher</a> hvis du selger plass.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Er dette en linkbuilding-guide 2026?',
                        'a' => 'Nei. Det er en produktside som forklarer markedsplassens nåværende regler, ikke en trendkalender.',
                    ],
                    [
                        'q' => 'Hvor ser jeg prisene?',
                        'a' => 'Modellen står under <a href="'.$prices.'">priser</a>. Aktuelle beløp står i katalogen etter registrering.',
                    ],
                ],
                'cta_primary' => ['label' => 'Se utvalget', 'url' => $register],
                'cta_secondary' => ['label' => 'Kjøp gjesteinnlegg', 'url' => $guest],
                'see_also' => [
                    ['label' => 'Linkbuilding', 'url' => $lb],
                    ['label' => 'Kjøp backlinks', 'url' => $links],
                    ['label' => 'Priser', 'url' => $prices],
                ],
            ],
        ];
    }
}
