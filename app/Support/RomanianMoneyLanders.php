<?php

namespace App\Support;

/**
 * Romania-only money / B2B marketing landers (Romanian as used by SEO teams).
 * Marketplace stays /ro/piata and pricing stays /ro/preturi (existing public slugs).
 * Shared slugs (link-building, digital-pr, niche-edits) are indexable on /ro;
 * unprefixed canonicals stay with Italy or Germany.
 */
class RomanianMoneyLanders
{
    public const LOCALE = 'ro';

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_keys(self::pages());
    }

    /**
     * Research URL aliases → Romanian canonicals (no extra indexable twins).
     *
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return [
            'catalog-publicatii-seo' => '/ro/piata',
            'guest-post-romania' => '/ro/cumpara-guest-post',
            'guest-posting-sites-romania' => '/ro/cumpara-guest-post',
            'publica-pe-bloguri' => '/ro/cumpara-guest-post',
            'publisheri-romania' => '/ro/cumpara-guest-post',
            'media-romania' => '/ro/piata',
            'cumpara-articol-sponsorizat' => '/ro/articol-sponsorizat',
            'publica-articol-sponsorizat' => '/ro/articol-sponsorizat',
            'cumpara-backlinks' => '/ro/cumpara-backlink',
            'cumpara-linkuri-seo' => '/ro/cumpara-backlink',
            'linkbuilding' => '/ro/link-building',
            'pret-guest-post' => '/ro/preturi',
            'preturi-guest-post' => '/ro/preturi',
            'cost-linkbuilding' => '/ro/preturi',
            'white-label-linkbuilding' => '/ro/agentii',
            'comunicat-de-presa' => '/ro/digital-pr',
            'cumpara-comunicat-de-presa' => '/ro/digital-pr',
            'ghid-link-building' => '/ro/ghid',
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
        return ['ro'];
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
        return url('/ro/'.$slug);
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
            ['slug' => 'home', 'label' => 'Marketplace România', 'url' => url('/ro')],
            ['slug' => 'cumpara-guest-post', 'label' => 'Cumpără guest post', 'url' => self::url('cumpara-guest-post')],
            ['slug' => 'articol-sponsorizat', 'label' => 'Articol sponsorizat', 'url' => self::url('articol-sponsorizat')],
            ['slug' => 'piata', 'label' => 'Catalog publicații', 'url' => url('/ro/piata')],
            ['slug' => 'link-building', 'label' => 'Link building', 'url' => self::url('link-building')],
            ['slug' => 'cumpara-backlink', 'label' => 'Cumpără backlinkuri', 'url' => self::url('cumpara-backlink')],
            ['slug' => 'preturi', 'label' => 'Preț guest post', 'url' => url('/ro/preturi')],
            ['slug' => 'agentii', 'label' => 'Pentru agenții', 'url' => self::url('agentii')],
            ['slug' => 'digital-pr', 'label' => 'Digital PR', 'url' => self::url('digital-pr')],
            ['slug' => 'niche-edits', 'label' => 'Niche edits', 'url' => self::url('niche-edits')],
            ['slug' => 'ghid', 'label' => 'Ghid', 'url' => self::url('ghid')],
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
        $piata = '/ro/piata';
        $preturi = '/ro/preturi';
        $how = '/ro/cum-functioneaza';
        $register = '/register';
        $guest = '/ro/cumpara-guest-post';
        $sponsored = '/ro/articol-sponsorizat';
        $links = '/ro/cumpara-backlink';
        $lb = '/ro/link-building';
        $agencies = '/ro/agentii';
        $pr = '/ro/digital-pr';
        $niche = '/ro/niche-edits';
        $ghid = '/ro/ghid';
        $publisher = '/ro/devino-publisher';

        return [
            'cumpara-guest-post' => [
                'kicker' => 'Guest post cu backlink',
                'h1' => 'Cumpără guest post pe site-uri din România',
                'subtitle' => 'Alege publishers românești și europeni verificați, compară nișa, DA/DR și prețul în euro, trimite briefingul și urmărește URL-ul live în comandă.',
                'meta_title' => 'Cumpără guest post în România | SEOLinkBuildings',
                'meta_description' => 'Guest post pe bloguri și site-uri din România: filtrezi nișa, DA/DR și prețul în EUR, alegi dofollow sau sponsored și primești URL-ul live în comandă.',
                'teaser_countries' => ['ro'],
                'teaser_title' => 'Site-uri pentru guest post în România',
                'teaser_subtitle' => 'Previzualizare mascată a listingurilor active din România. Domeniile se văd după înregistrare.',
                'intro' => [
                    'SEOLinkBuildings este un marketplace self-service, nu un pachet opac de guest posturi. Alegi site-ul — adesea .ro, adesea în română —, plătești în euro din portofel și ții briefingul, chatul și URL-ul live într-o singură comandă. Sediul este la Londra (Topurlz Ltd); nu inventăm un CUI românesc.',
                    '„Guest post România”, „guest post pe bloguri din România” și „guest posting sites Romania” sunt aceeași intenție: o publicație plătită pe un site care nu este al tău, cu reguli scrise de lungime, linkuri și termene.',
                ],
                'points' => [
                    [
                        'title' => 'Publishers, nu o listă fantomă',
                        'body' => 'Fiecare rând este un site cu nișă, limbă, țară, DA/DR, trafic declarat și preț de checkout. Nu vindem PBN și nici „pachete de 50 de linkuri”.',
                    ],
                    [
                        'title' => 'Cum comanzi',
                        'body' => 'Creezi cont, filtrezi România, adaugi site-ul în coș și trimiți titlul, textul sau briefingul plus ancora. Publisherul livrează URL-ul live spre aprobare.',
                    ],
                    [
                        'title' => 'Dofollow și sponsored',
                        'body' => 'Atributul linkului este pe listing. Multe site-uri marchează publicațiile plătite. Citește tipul de link înainte să comanzi — nu există „dofollow cu orice preț”.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Trafic, nișă și permanență',
                        'body' => 'Un guest post „cu trafic” înseamnă că listingul arată traficul declarat de publisher, nu o garanție de vizite. Nișele (finanțe, sănătate, tehnologie, asigurări, imobiliare, fintech, travel, marketing) se filtrează în catalog, nu pe URL-uri separate. „Permanent” depinde de regulile site-ului: unele publicații rămân, altele au termen. Citește listingul.',
                    ],
                    [
                        'h2' => 'DA și DR, fără fraze forțate',
                        'body' => 'Poți sorta după DA și DR când metricile există pe rând. Un guest post cu DA ridicat sau cu DR ridicat nu este un produs separat și nu promite poziții. Metricile lipsă rămân goale — nu le inventăm.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Pot publica doar pe site-uri .ro?',
                        'a' => 'Da, filtrând țara România. Catalogul european rămâne disponibil în același portofel EUR.',
                    ],
                    [
                        'q' => 'Scrieți voi articolul?',
                        'a' => 'Comanda standard folosește briefingul tău. Unele listinguri oferă redactare; o vezi pe site, nu ca un serviciu inventat aici.',
                    ],
                    [
                        'q' => 'Există pagini pentru București sau Cluj?',
                        'a' => 'Nu. Orașele nu au URL propriu. Filtrezi țara, nu ușa de oraș.',
                    ],
                ],
                'cta_primary' => ['label' => 'Creează cont și vezi catalogul', 'url' => $register],
                'cta_secondary' => ['label' => 'Catalog de publicații', 'url' => $piata],
                'see_also' => [
                    ['label' => 'Cumpără backlinkuri', 'url' => $links],
                    ['label' => 'Articol sponsorizat', 'url' => $sponsored],
                    ['label' => 'Prețuri', 'url' => $preturi],
                    ['label' => 'Ghid', 'url' => $ghid],
                ],
            ],
            'articol-sponsorizat' => [
                'kicker' => 'Advertorial',
                'h1' => 'Articole sponsorizate și advertoriale în România',
                'subtitle' => 'Publici un articol plătit pe un site din catalog, cu preț în euro și URL live. Nu vindem un comunicat de presă generic și nici o garanție de știre.',
                'meta_title' => 'Articol sponsorizat și advertorial România | SEOLinkBuildings',
                'meta_description' => 'Cumperi articole sponsorizate și advertoriale pe site-uri din România. Preț în EUR pe listing, briefing, URL live — fără teletip inventat.',
                'teaser_countries' => ['ro'],
                'teaser_title' => 'Site-uri media pentru guest post și advertorial',
                'teaser_subtitle' => 'Aceeași previzualizare de publishers din România. Un advertorial există doar dacă site-ul este listat și acceptă brief-ul.',
                'intro' => [
                    '„Articol sponsorizat”, „advertorial” și „publicitate nativă” descriu o publicație plătită, nu un canal de presă pe care nu îl avem. Dacă domeniul nu este în catalog, nu îl vindem.',
                    'Un comunicat de presă SEO, aici, înseamnă același flux: alegi publicația, plătești în EUR și primești URL-ul. Nu promitem apariție în Google News.',
                ],
                'points' => [
                    [
                        'title' => 'Marcare sponsorizată',
                        'body' => 'Multe site-uri cer rel sponsored sau o etichetă vizibilă. Respectă regula din listing.',
                    ],
                    [
                        'title' => 'Tariful',
                        'body' => 'Cât costă un advertorial depinde de site. Vezi <a href="'.$preturi.'">prețurile</a> pentru model și catalogul pentru sumele în vigoare.',
                    ],
                    [
                        'title' => 'Conținut',
                        'body' => 'Trimiți textul sau briefingul. Publisherul publică pe propriul site și îți trimite URL-ul live.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Advertorial vs guest post',
                        'body' => 'În practică, ambele sunt o publicație plătită cu link. Diferența este editorială: advertorialul seamănă mai des cu o mențiune de brand. Checkout-ul este același. <a href="'.$guest.'">Cumpără guest post</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Aveți un tarif unic de advertorial?',
                        'a' => 'Nu. Fiecare listing are prețul lui de checkout, în euro.',
                    ],
                    [
                        'q' => 'Publicați pe orice ziar?',
                        'a' => 'Doar pe site-urile care sunt în catalog și acceptă comanda.',
                    ],
                ],
                'cta_primary' => ['label' => 'Vezi site-urile din România', 'url' => $register],
                'cta_secondary' => ['label' => 'Digital PR', 'url' => $pr],
                'see_also' => [
                    ['label' => 'Digital PR', 'url' => $pr],
                    ['label' => 'Prețuri', 'url' => $preturi],
                    ['label' => 'Guest post', 'url' => $guest],
                ],
            ],
            'cumpara-backlink' => [
                'kicker' => 'Backlinkuri editoriale',
                'h1' => 'Cumpără backlinkuri în România',
                'subtitle' => 'Backlinkurile de aici sunt linkuri din publicații cumpărate pe site-uri reale, nu un pachet anonim de URL-uri.',
                'meta_title' => 'Cumpără backlinkuri în România | SEOLinkBuildings',
                'meta_description' => 'Backlinkuri tematice din guest posturi pe site-uri din România. Preț în EUR, link dofollow sau sponsored pe listing, URL live în comandă.',
                'teaser_countries' => ['ro'],
                'teaser_title' => 'Site-uri .ro pentru backlinkuri',
                'teaser_subtitle' => 'Previzualizare a listingurilor cu țara principală România. Traficul afișat este cel declarat, nu o promisiune.',
                'intro' => [
                    '„Cumpără backlink”, „backlinkuri România” și „linkuri dofollow” caută același lucru: un link pe o pagină publicată. La noi îl obții printr-un guest post sau un articol sponsorizat, cu ancora din briefing.',
                    'Backlinkuri tematice înseamnă că alegi nișa site-ului. „Cu trafic real” înseamnă că te uiți la traficul din listing, nu că garantăm vizite.',
                ],
                'points' => [
                    [
                        'title' => 'Calitate pe care o poți citi',
                        'body' => 'Țară, limbă, nișă, DA/DR și preț sunt pe rând. Nu vindem „backlinkuri de calitate” ca etichetă fără site.',
                    ],
                    [
                        'title' => 'Dofollow nu este implicit',
                        'body' => 'Filtrează tipul de link. Publisherul rămâne responsabil de HTML-ul live.',
                    ],
                    [
                        'title' => 'Fără PBN',
                        'body' => 'Nu vindem rețele private. Riscurile sunt în <a href="'.$ghid.'">ghid</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Cum alegi',
                        'body' => 'Pornește din <a href="'.$piata.'">catalog</a>, filtrează România, compară prețul de checkout și regulile de ancoră. Apoi <a href="'.$guest.'">guest post</a> sau <a href="'.$sponsored.'">articol sponsorizat</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Pot cumpăra doar dofollow?',
                        'a' => 'Poți filtra ofertele care îl declară. Verifică atributul pe pagina live.',
                    ],
                    [
                        'q' => 'Faceți inserări în articole existente?',
                        'a' => 'Nu ca SKU de niche edit. Unele site-uri vând un extra de homepage, cu termen.',
                    ],
                    [
                        'q' => 'Cât costă backlinkurile în România?',
                        'a' => 'Depinde de site. <a href="'.$preturi.'">Prețurile</a> explică modelul; catalogul arată sumele în vigoare.',
                    ],
                ],
                'cta_primary' => ['label' => 'Compară site-urile', 'url' => $register],
                'cta_secondary' => ['label' => 'Link building', 'url' => $lb],
                'see_also' => [
                    ['label' => 'Prețuri', 'url' => $preturi],
                    ['label' => 'Guest post', 'url' => $guest],
                    ['label' => 'Niche edits', 'url' => $niche],
                ],
            ],
            'link-building' => [
                'kicker' => 'Campanii',
                'h1' => 'Link building în România',
                'subtitle' => 'Construiești campania din catalog, publicație cu publicație, sau alegi un pachet gestionat de digital PR listat la prețuri. Fără „linkbuilding ieftin” ca ofertă fără site.',
                'meta_title' => 'Link building în România | SEOLinkBuildings',
                'meta_description' => 'Link building pentru România: catalog self-service în EUR, comenzi urmărite și pachete de digital PR. Fără pachete anonime de linkuri.',
                'teaser_countries' => ['ro'],
                'teaser_title' => 'Inventar pentru campanii în România',
                'teaser_subtitle' => 'Aceleași listinguri ca pe pagina de guest post. Europa se filtrează separat, după autentificare.',
                'intro' => [
                    'Un serviciu de link building, aici, înseamnă că alegi publishers, plătești și urmărești URL-ul. Nu este un abonament care „face SEO” în locul tău.',
                    'Link building în Europa folosește același portofel. România este filtrul de țară, nu un produs separat.',
                ],
                'points' => [
                    [
                        'title' => 'Self-service',
                        'body' => 'Strategie înseamnă selecția ta de site-uri, ancore și ritm. Catalogul este sursa.',
                    ],
                    [
                        'title' => 'Pachete',
                        'body' => 'Pachetele numerotate de pe pagina de prețuri sunt campanii gestionate de digital PR, nu un sac de URL-uri.',
                    ],
                    [
                        'title' => 'White label',
                        'body' => 'Agenția poate comanda în contul ei. Nu livrăm un portal cu logoul tău. Detalii: <a href="'.$agencies.'">pentru agenții</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Outreach',
                        'body' => 'Outreach-ul self-service ești tu: scrii briefingul și alegi site-ul. Outreach-ul gestionat este în pachetele de digital PR, dacă rămân listate (azi de la 499 €/lună pe planul de bază).',
                    ],
                    [
                        'h2' => 'Cum începe o campanie',
                        'body' => 'Cont, fonduri în EUR, filtru România, comandă. Fluxul: <a href="'.$how.'">cum funcționează</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Aveți link building ieftin?',
                        'a' => 'Prețul este al listingului. Nu avem un tarif „ieftin” separat de catalog.',
                    ],
                    [
                        'q' => 'Faceți și strategie?',
                        'a' => 'Pagina de ghid explică riscurile și ancorele. Execuția self-service rămâne la tine.',
                    ],
                ],
                'cta_primary' => ['label' => 'Deschide catalogul', 'url' => $register],
                'cta_secondary' => ['label' => 'Prețuri', 'url' => $preturi],
                'see_also' => [
                    ['label' => 'Pentru agenții', 'url' => $agencies],
                    ['label' => 'Digital PR', 'url' => $pr],
                    ['label' => 'Ghid', 'url' => $ghid],
                ],
            ],
            'agentii' => [
                'kicker' => 'Cont B2B',
                'h1' => 'Link building pentru agenții în România',
                'subtitle' => 'Catalog self-service pentru agenții SEO, reselleri și echipe care refacturează. Portofel EUR, comenzi urmărite, facturi în facturarea advertiserului.',
                'meta_title' => 'Link building pentru agenții în România | SEOLinkBuildings',
                'meta_description' => 'Guest post pentru agenții în România: catalog EUR, facturi, comenzi pe brand — fără un frontend de revânzare cu logoul tău.',
                'teaser_countries' => ['ro'],
                'teaser_title' => 'Inventar pe care îl poți refactura',
                'teaser_subtitle' => 'Aceleași listinguri ca pentru un advertiser intern. Contul este al tău; brandurile stau în proiectele și comenzile tale.',
                'intro' => [
                    '„Agenție link building România”, „guest post pentru agenții” și „outreach linkbuilding” caută un furnizor care execută. Aici agenția păstrează comanda: alege site-urile, plătește și predă clientului URL-ul live.',
                    'White label operațional înseamnă că clientul final nu trebuie să își facă cont. Nu este un program de revânzare cu logoul tău pe site-ul public.',
                ],
                'points' => [
                    [
                        'title' => 'Un portofel, mai multe campanii',
                        'body' => 'Încarci euro (card sau transfer, dacă metoda este activă) și distribui soldul între comenzi.',
                    ],
                    [
                        'title' => 'Comandă și factură',
                        'body' => 'Facturile pentru încărcări de portofel sau comenzi se descarcă din facturarea advertiserului, când produsul le emite. Datele firmei sunt cele din UK (Topurlz Ltd), pe <a href="/ro/despre-noi">Despre noi</a> — nu există o entitate românească inventată.',
                    ],
                    [
                        'title' => 'Panou pentru echipe SEO',
                        'body' => 'Filtre, metrici, chatul comenzii și URL live. După login, panoul este în engleză pentru toate rolurile.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Agenție vs marketplace',
                        'body' => 'O agenție alege site-urile pentru client. Un marketplace arată site-urile cumpărătorului. SEOLinkBuildings este al doilea. Dacă echipa ta este agenția, selecția rămâne la voi, iar catalogul este sursa.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Pot ascunde marketplace-ul de client?',
                        'a' => 'Da, operând din contul tău. Nu livrăm un portal white-label cu marca ta.',
                    ],
                    [
                        'q' => 'Emiteți facturi cu TVA românesc?',
                        'a' => 'Facturarea urmează societatea britanică a produsului. Descarcă documentele și clarifică cu contabilitatea dacă ai nevoie de alte integrări. Nu inventăm un CUI.',
                    ],
                    [
                        'q' => 'Există un preț de agenție separat?',
                        'a' => 'Prețul de checkout este al listingului. Nu există un al doilea catalog „pentru agenții”.',
                    ],
                ],
                'cta_primary' => ['label' => 'Creează cont de agenție', 'url' => $register],
                'cta_secondary' => ['label' => 'Catalog', 'url' => $piata],
                'see_also' => [
                    ['label' => 'Link building', 'url' => $lb],
                    ['label' => 'Prețuri', 'url' => $preturi],
                    ['label' => 'Digital PR', 'url' => $pr],
                ],
            ],
            'digital-pr' => [
                'kicker' => 'PR în media digitale',
                'h1' => 'Digital PR în România',
                'subtitle' => 'Campanii de digital PR ca publicații în site-urile din marketplace, plus pachete gestionate la prețuri. Fără promisiune de Google News.',
                'meta_title' => 'Digital PR în România | SEOLinkBuildings',
                'meta_description' => 'Digital PR în România: publicații din catalog, portofel EUR, URL live și pachete gestionate — fără garanții de News sau teletip.',
                'teaser_countries' => ['ro'],
                'teaser_title' => 'Site-uri din România în catalog',
                'teaser_subtitle' => 'Unii publishers arată ca un media kit; nu toți sunt un cotidian. Nișa și limba se filtrează după login.',
                'intro' => [
                    '„Digital PR România”, „comunicat de presă SEO” și „mențiuni de brand” amestecă PR-ul cu link buildingul. Aici cumperi publicații pe site-uri care sunt efectiv în catalog.',
                    'Pachetele gestionate (valorile sunt la Prețuri; azi de la 499 €/lună pe planul de bază, dacă rămâne listat) sunt outreach de echipă, nu un buton „să apară într-un ziar național”.',
                ],
                'points' => [
                    [
                        'title' => 'Media doar dacă sunt în catalog',
                        'body' => 'Nu avem un canal de Google News. Un guest post „de presă” există doar dacă acel site este un listing și acceptă briefingul.',
                    ],
                    [
                        'title' => 'Mențiuni de brand',
                        'body' => 'O mențiune poate ieși dintr-o publicație. Nu vindem „brand mention” ca SKU fără URL.',
                    ],
                    [
                        'title' => 'Campanii',
                        'body' => 'Self-service: alegi site-urile. Gestionat: pachetele de pe pagina de prețuri.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'PR și SEO, fără inflație',
                        'body' => 'O publicație utilă are cititori, context și un link (sau o mențiune) care are sens. Nu înlocuiește o știre. Catalog: <a href="'.$piata.'">lista de site-uri</a>. Pachete: <a href="'.$preturi.'">prețuri</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Garantează un articol în presă?',
                        'a' => 'Nu. Livrăm URL-ul pe site-ul pe care l-ai comandat, dacă publisherul acceptă.',
                    ],
                    [
                        'q' => 'Este diferit de un advertorial?',
                        'a' => 'Advertorialul este publicația. Digital PR-ul este campania. În marketplace plătești tot publicația. <a href="'.$sponsored.'">Articol sponsorizat</a>.',
                    ],
                ],
                'cta_primary' => ['label' => 'Vezi pachetele și catalogul', 'url' => $preturi],
                'cta_secondary' => ['label' => 'Înregistrare', 'url' => $register],
                'see_also' => [
                    ['label' => 'Articol sponsorizat', 'url' => $sponsored],
                    ['label' => 'Pentru agenții', 'url' => $agencies],
                    ['label' => 'Guest post', 'url' => $guest],
                ],
            ],
            'niche-edits' => [
                'kicker' => 'Nu este un produs separat',
                'h1' => 'Niche edits în România — și ce vindem în loc',
                'subtitle' => 'Niche edits (inserarea unui link într-un articol deja publicat) nu sunt un SKU pe SEOLinkBuildings. Aici este limita față de guest post și riscurile.',
                'meta_title' => 'Niche edits în România, explicate | SEOLinkBuildings',
                'meta_description' => 'Ce sunt niche edits și inserările de link, când sunt riscante, și de ce în România vindem publicații editoriale — nu un insert într-un articol străin.',
                'teaser_countries' => ['ro'],
                'teaser_title' => 'Site-uri editoriale, nu rețele de inserturi',
                'teaser_subtitle' => 'Previzualizare de listinguri active din România. Produsul standard este un articol nou cu link în text.',
                'intro' => [
                    'Un niche edit este un link pus într-un articol deja publicat, adesea pentru că URL-ul este deja indexat. „Inserare link” și „contextual backlinks” caută exact asta.',
                    'Nu vindem acest lucru ca produs. Comanda standard este o publicație nouă (guest post sau articol sponsorizat) cu briefing și URL live. Unele site-uri oferă un extra de homepage, cu termen; este vizibil pe listing, nu un insert tăcut într-un articol care deja rankează.',
                ],
                'points' => [
                    [
                        'title' => 'Verifică relevanța',
                        'body' => 'Un insert într-un text vechi, pe altă temă, este de obicei mai slab decât un articol nou pe un site potrivit.',
                    ],
                    [
                        'title' => 'Riscuri',
                        'body' => 'Proprietate neclară, ancore schimbate ulterior, lipsa marcajului sponsorizat, rețele de inserturi cu același tipar.',
                    ],
                    [
                        'title' => 'Ce cumperi aici',
                        'body' => 'O publicație cu reguli, checkout EUR și URL live. <a href="'.$guest.'">Cumpără guest post</a> sau <a href="'.$links.'">cumpără backlinkuri</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Când ar avea sens un niche edit',
                        'body' => 'Doar dacă articolul existent se potrivește tematic, publisherul răspunde editorial și linkul rămâne transparent. Nu îl orchestrăm ca SKU de volum.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Pot cere un link într-un articol existent?',
                        'a' => 'Doar dacă un listing anume îl oferă. Implicit este un articol nou.',
                    ],
                    [
                        'q' => 'De ce nu există o pagină de vânzare pentru niche edits?',
                        'a' => 'Pentru că nu avem produsul. O pagină de vânzare ar fi înșelătoare.',
                    ],
                    [
                        'q' => 'Care este alternativa?',
                        'a' => 'Guest posturi sau articole sponsorizate din catalogul României, cu briefing și URL live.',
                    ],
                ],
                'cta_primary' => ['label' => 'Alege guest posturi', 'url' => $register],
                'cta_secondary' => ['label' => 'Cumpără backlinkuri', 'url' => $links],
                'see_also' => [
                    ['label' => 'Link building', 'url' => $lb],
                    ['label' => 'Guest post', 'url' => $guest],
                    ['label' => 'Articol sponsorizat', 'url' => $sponsored],
                ],
            ],
            'ghid' => [
                'kicker' => 'Un singur ghid',
                'h1' => 'Ghid de guest post și link building',
                'subtitle' => 'Ce este un guest post, cum cumperi backlinkuri, dofollow față de nofollow, ancore, PBN și riscuri — pe o singură pagină, nu pe zeci de articole subțiri.',
                'meta_title' => 'Ghid guest post și link building | SEOLinkBuildings',
                'meta_description' => 'Ghid scurt: ce este un guest post, cum cumperi backlinkuri, dofollow vs nofollow, ancore, rel sponsored și de ce un PBN nu este produsul nostru.',
                'teaser_countries' => ['ro'],
                'teaser_title' => 'De la explicație la catalog',
                'teaser_subtitle' => 'După ghid, site-urile reale sunt în catalogul României, cu preț de checkout.',
                'intro' => [
                    'Acest ghid acoperă întrebările informaționale (ce este link building, cum să cumperi backlinkuri, riscuri, anchor text) fără o pagină nouă pentru fiecare frază.',
                    'Panoul de după login rămâne în engleză. Pagina publică este în română.',
                ],
                'points' => [
                    [
                        'title' => 'Ce este un guest post',
                        'body' => 'Un articol publicat pe site-ul altcuiva, de obicei cu un link către tine, în schimbul unei plăți sau al unui schimb. La noi plata este în EUR, pe comandă.',
                    ],
                    [
                        'title' => 'Dofollow, nofollow, sponsored',
                        'body' => 'Dofollow transmite semnal în mod obișnuit. Nofollow și sponsored îi spun motorului că linkul este marcat. Google tratează rel sponsored ca pe un link plătit. Alege ce declară listingul.',
                    ],
                    [
                        'title' => 'Ancore',
                        'body' => 'O ancoră exactă repetată pe multe site-uri este un tipar riscant. Variază formularea și ține ancora relevantă pentru pagina țintă.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Cum să cumperi backlinkuri',
                        'body' => 'Cont, fonduri, filtru de țară și nișă, briefing, aprobarea URL-ului live. Pașii operaționali: <a href="'.$how.'">cum funcționează</a>. Pagina comercială: <a href="'.$links.'">cumpără backlinkuri</a>.',
                    ],
                    [
                        'h2' => 'PBN vs guest post',
                        'body' => 'Un PBN este o rețea controlată ca să paseze linkuri. Nu vindem asta. Un guest post este o publicație pe un site cu cititori proprii. Dacă nu poți numi site-ul, nu este acest produs.',
                    ],
                    [
                        'h2' => 'Riscurile cumpărării de backlinkuri',
                        'body' => 'Site-uri fără trafic real, ancore agresive, linkuri care dispar, lipsa marcajului sponsorizat, metrici umflate. Verifică listingul și URL-ul live. Nu promitem poziții.',
                    ],
                    [
                        'h2' => 'Strategie, pe scurt',
                        'body' => 'Puține publicații relevante bat un volum de linkuri fără context. Pentru execuție: <a href="'.$lb.'">link building</a>, <a href="'.$guest.'">guest post</a>, <a href="'.$publisher.'">devino publisher</a> dacă vinzi spațiu.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Este acesta un curs din 2026?',
                        'a' => 'Nu. Este o pagină de produs care explică regulile actuale ale marketplace-ului, nu un calendar de tendințe.',
                    ],
                    [
                        'q' => 'Unde văd prețurile?',
                        'a' => 'Modelul este pe <a href="'.$preturi.'">prețuri</a>. Sumele în vigoare sunt în catalog, după înregistrare.',
                    ],
                ],
                'cta_primary' => ['label' => 'Vezi catalogul', 'url' => $register],
                'cta_secondary' => ['label' => 'Cumpără guest post', 'url' => $guest],
                'see_also' => [
                    ['label' => 'Link building', 'url' => $lb],
                    ['label' => 'Backlinkuri', 'url' => $links],
                    ['label' => 'Prețuri', 'url' => $preturi],
                ],
            ],
        ];
    }
}
