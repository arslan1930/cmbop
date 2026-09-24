<?php

namespace App\Support;

/**
 * Poland money / B2B landers (Polish as used by SEO teams, plus genuine loanwords).
 * Marketplace stays /pl/rynek and pricing stays /pl/cennik.
 * Shared slug link-building stays indexable on /pl (Italy keeps the unprefixed canonical).
 */
class PolishMoneyLanders
{
    use MoneyLanderPages;

    public const LOCALE = 'pl';

    /**
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return [
            'marketplace' => '/pl/rynek',
            'katalog-publishers' => '/pl/rynek',
            'guest-post-poland' => '/pl/wpis-goscinny',
            'wpis-goscinny-blogi' => '/pl/wpis-goscinny',
            'kup-guest-post' => '/pl/wpis-goscinny',
            'publikacja-sponsorowana' => '/pl/artykul-sponsorowany',
            'advertorial' => '/pl/artykul-sponsorowany',
            'polskie-backlinki' => '/pl/kup-backlinki',
            'kup-linki-seo' => '/pl/kup-backlinki',
            'ile-kosztuje-artykul' => '/pl/cennik',
            'cennik-link-building' => '/pl/cennik',
            'white-label-link-building' => '/pl/agencje',
            'komunikat-prasowy' => '/pl/digital-pr',
            'wstawienie-linku' => '/pl/niche-edits',
            'guide' => '/pl/przewodnik',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function ui(): array
    {
        return [
            'cluster_title' => 'Strony o wpisie gościnnym, backlinkach i link buildingu w Polsce',
            'from' => 'Od',
            'price_note' => 'Najniższa aktualna cena w euro na aktywnych, zweryfikowanych pozycjach katalogu z głównym krajem Polska. To nie jest stały cennik.',
            'sites_preview' => 'Witryny w podglądzie',
            'count_note' => 'Aktywni, zweryfikowani publishers z Polską jako krajem głównym, gdy licznik jest dostępny.',
            'th_site' => 'Witryna',
            'th_country' => 'Kraj',
            'th_language' => 'Język',
            'th_from' => 'Od',
            'teaser_foot' => 'Pokazujemy DA, DR i cenę w euro, gdy stoją na pozycji katalogu. Brakujące metryki zostają puste.',
            'see_also' => 'Zobacz też',
        ];
    }

    /**
     * @return list<array{slug: string, label: string, url: string}>
     */
    public static function clusterLinks(?string $current = null): array
    {
        return self::filterCluster([
            ['slug' => 'home', 'label' => 'Rynek Polska', 'url' => url('/pl')],
            ['slug' => 'wpis-goscinny', 'label' => 'Wpis gościnny', 'url' => self::url('wpis-goscinny')],
            ['slug' => 'artykul-sponsorowany', 'label' => 'Artykuł sponsorowany', 'url' => self::url('artykul-sponsorowany')],
            ['slug' => 'rynek', 'label' => 'Katalog publishers', 'url' => self::marketingUrl('marketplace')],
            ['slug' => 'link-building', 'label' => 'Link building', 'url' => self::url('link-building')],
            ['slug' => 'kup-backlinki', 'label' => 'Kup backlinki', 'url' => self::url('kup-backlinki')],
            ['slug' => 'cennik', 'label' => 'Cennik', 'url' => self::marketingUrl('pricing')],
            ['slug' => 'agencje', 'label' => 'Dla agencji', 'url' => self::url('agencje')],
            ['slug' => 'digital-pr', 'label' => 'Digital PR', 'url' => self::url('digital-pr')],
            ['slug' => 'niche-edits', 'label' => 'Niche edits', 'url' => self::url('niche-edits')],
            ['slug' => 'przewodnik', 'label' => 'Przewodnik', 'url' => self::url('przewodnik')],
        ], $current);
    }

    /**
     * @return list<array{slug: string, label: string, url: string}>
     */
    public static function footerLinks(): array
    {
        return array_values(array_filter(
            self::clusterLinks('home'),
            static fn (array $item) => ! in_array($item['slug'], ['rynek', 'cennik'], true)
        ));
    }

    /**
     * @return array{h2: string, body: string, links: string}
     */
    public static function marketplaceCopy(): array
    {
        return [
            'h2' => 'Katalog polskich publishers',
            'body' => 'To publiczna lista publishers z Polską jako krajem głównym: nisza, język, DA/DR i cena w euro. Nie indeksujemy każdej kombinacji filtrów, a miasta (Warszawa i inne) nie mają własnego URL. Pełny katalog z domenami otwiera się po rejestracji.',
            'links' => '<a href="'.self::url('wpis-goscinny').'">Wpis gościnny w Polsce</a> · <a href="'.self::url('kup-backlinki').'">Kup backlinki</a> · <a href="'.self::marketingUrl('pricing').'">Ile kosztuje artykuł sponsorowany</a> · <a href="'.url('/guest-posts-poland').'">Poland inventory (English)</a>',
        ];
    }

    /**
     * @return array{h2: string, body: string}
     */
    public static function pricingCopy(): array
    {
        return [
            'h2' => 'Ile kosztuje wpis gościnny i artykuł sponsorowany w Polsce',
            'body' => 'Nie publikujemy stałego cennika PDF i nie wymyślamy polskiego NIP-u: cena jest witryny, w euro. «Ile kosztuje artykuł», «cennik link building» i «cena backlinków» wynikają z pozycji katalogu, które wybierasz. Ponumerowane pakiety powyżej to prowadzone kampanie digital PR, nie worek anonimowych URL-i. Aktualne kwoty są w <a href="'.self::marketingUrl('marketplace').'">polskim katalogu</a> po rejestracji. Siedziba jest w Londynie (Topurlz Ltd).',
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
        $guest = self::url('wpis-goscinny');
        $sponsored = self::url('artykul-sponsorowany');
        $links = self::url('kup-backlinki');
        $lb = self::url('link-building');
        $agencies = self::url('agencje');
        $pr = self::url('digital-pr');
        $niche = self::url('niche-edits');
        $guide = self::url('przewodnik');
        $publisher = self::marketingUrl('become-a-publisher');

        return [
            'wpis-goscinny' => [
                'kicker' => 'Wpis gościnny z backlinkiem',
                'h1' => 'Wpis gościnny na polskich blogach',
                'subtitle' => 'Wybierz zweryfikowanych polskich i europejskich publishers, porównaj niszę, DA/DR i cenę w euro, wyślij brief i śledź live-URL w zamówieniu.',
                'meta_title' => 'Wpis gościnny na polskich blogach | SEOLinkBuildings',
                'meta_description' => 'Zamów wpis gościnny na polskich blogach i witrynach .pl: filtr niszy, DA/DR i cena EUR, dofollow albo sponsored oraz live-URL w zamówieniu.',
                'teaser_countries' => ['pl'],
                'teaser_title' => 'Witryny na wpis gościnny w Polsce',
                'teaser_subtitle' => 'Zamaskowany podgląd aktywnych pozycji katalogu w Polsce. Domeny zobaczysz po rejestracji.',
                'intro' => [
                    'SEOLinkBuildings to self-service marketplace, nie nieprzejrzysty pakiet wpisów. Wybierasz witrynę — często .pl, często po polsku — płacisz w euro z salda i trzymasz brief, czat i live-URL w tym samym zamówieniu. Siedziba jest w Londynie (Topurlz Ltd); nie wymyślamy polskiego NIP-u.',
                    '«Kup guest post», «wpis gościnny na polskich blogach» i «artykuł sponsorowany» to w praktyce ta sama intencja: płatna publikacja na witrynie, której nie posiadasz, z zapisanymi zasadami długości, linków i dostawy. W Polsce dominuje termin artykuł sponsorowany — ta strona opisuje wpis gościnny; katalog i rozliczenie są te same.',
                ],
                'points' => [
                    [
                        'title' => 'Publishers, nie fantomowa lista',
                        'body' => 'Każdy wiersz to witryna z niszą, językiem, krajem, DA/DR, zadeklarowanym ruchem i ceną. Nie sprzedajemy PBN i nie sprzedajemy «pakietów 50 linków».',
                    ],
                    [
                        'title' => 'Jak złożyć zamówienie',
                        'body' => 'Zakładasz konto, filtrujesz Polskę, wrzucasz witrynę do koszyka i wysyłasz tytuł, tekst albo brief plus kotwicę. Publisher dostarcza live-URL do akceptacji.',
                    ],
                    [
                        'title' => 'Dofollow i sponsored',
                        'body' => 'Atrybut linku stoi na pozycji katalogu. Wiele mediów oznacza płatne publikacje. Przeczytaj typ linku przed zamówieniem — nie ma «dofollow za wszelką cenę».',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Ruch, nisza i stałe linki',
                        'body' => 'Wpis «z ruchem» oznacza, że pozycja katalogu pokazuje ruch zadeklarowany przez publishera — nie gwarancję odwiedzin. Nisze (zdrowie, finanse, tech, ubezpieczenia, nieruchomości, travel, ecommerce) filtrujesz w katalogu, nie na osobnych URL-ach. «Stały» zależy od zasad witryny. Przeczytaj pozycję katalogu.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Czy mogę publikować tylko na witrynach .pl?',
                        'a' => 'Tak. Filtrujesz kraj Polska. Europejski katalog jest w tym samym saldzie EUR. Czechy i Niemcy to osobne filtry.',
                    ],
                    [
                        'q' => 'Czy piszecie artykuł?',
                        'a' => 'Standardowe zamówienie korzysta z Twojego briefu. Niektóre pozycje katalogu oferują redakcję; widać to na witrynie, nie jako wymyśloną dopłatę tutaj.',
                    ],
                    [
                        'q' => 'Czy jest strona dla Warszawy?',
                        'a' => 'Nie. Miasta nie mają własnego URL. Filtrujesz kraj.',
                    ],
                ],
                'cta_primary' => ['label' => 'Załóż konto i zobacz publishers', 'url' => $register],
                'cta_secondary' => ['label' => 'Katalog publishers', 'url' => $market],
                'see_also' => [
                    ['label' => 'Kup backlinki', 'url' => $links],
                    ['label' => 'Artykuł sponsorowany', 'url' => $sponsored],
                    ['label' => 'Cennik', 'url' => $prices],
                    ['label' => 'Przewodnik', 'url' => $guide],
                ],
            ],
            'artykul-sponsorowany' => [
                'kicker' => 'Advertorial',
                'h1' => 'Kup artykuł sponsorowany w polskich mediach',
                'subtitle' => 'Kupujesz płatny artykuł na witrynie z katalogu, z ceną w euro i live-URL. Nie sprzedajemy abonamentu na ogólne komunikaty prasowe.',
                'meta_title' => 'Artykuł sponsorowany w Polsce | SEOLinkBuildings',
                'meta_description' => 'Kup artykuł sponsorowany albo advertorial w polskich mediach. Cena w EUR na pozycji katalogu, brief i live-URL — bez wymyślonej agencji prasowej.',
                'teaser_countries' => ['pl'],
                'teaser_title' => 'Media na wpis gościnny i artykuł sponsorowany',
                'teaser_subtitle' => 'Ten sam podgląd publishers w Polsce. Artykuł sponsorowany istnieje tylko wtedy, gdy witryna jest w katalogu.',
                'intro' => [
                    '«Kup artykuł sponsorowany», «publikacja sponsorowana» i «advertorial» opisują płatną publikację, nie linię prasową, której nie mamy. Jeśli domeny nie ma w katalogu, jej nie sprzedajemy. W Polsce artykuł sponsorowany to dominujące hasło komercyjne — ten sam proces co wpis gościnny.',
                    'Native advertising pod SEO to tutaj ten sam przebieg: wybierasz publikację, płacisz w EUR i dostajesz URL. Nie obiecujemy Google News.',
                ],
                'points' => [
                    [
                        'title' => 'Oznaczenie jako sponsorowane',
                        'body' => 'Wiele witryn wymaga rel sponsored albo widocznego oznaczenia. Stosuj się do zasady na pozycji katalogu.',
                    ],
                    [
                        'title' => 'Cena',
                        'body' => 'Ile kosztuje artykuł sponsorowany, zależy od witryny. Zobacz <a href="'.$prices.'">cennik</a> dla modelu i katalog dla aktualnych kwot.',
                    ],
                    [
                        'title' => 'Treść',
                        'body' => 'Wysyłasz tekst albo brief. Publisher publikuje na własnej witrynie i przesyła live-URL.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Artykuł sponsorowany versus wpis gościnny',
                        'body' => 'W praktyce oba to płatna publikacja z linkiem. Różnica jest redakcyjna. Rozliczenie jest to samo. <a href="'.$guest.'">Wpis gościnny</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Czy jest jedna stawka za artykuł sponsorowany?',
                        'a' => 'Nie. Każda pozycja katalogu ma własną cenę, w euro.',
                    ],
                    [
                        'q' => 'Czy publikujecie we wszystkich gazetach?',
                        'a' => 'Tylko na witrynach, które są w katalogu i przyjmują zamówienie.',
                    ],
                ],
                'cta_primary' => ['label' => 'Zobacz polskie witryny', 'url' => $register],
                'cta_secondary' => ['label' => 'Digital PR', 'url' => $pr],
                'see_also' => [
                    ['label' => 'Digital PR', 'url' => $pr],
                    ['label' => 'Cennik', 'url' => $prices],
                    ['label' => 'Wpis gościnny', 'url' => $guest],
                ],
            ],
            'kup-backlinki' => [
                'kicker' => 'Redakcyjne backlinki',
                'h1' => 'Kup backlinki w Polsce',
                'subtitle' => 'Backlinki to tutaj linki z publikacji, które kupujesz na prawdziwych witrynach — nie anonimowy worek URL-i.',
                'meta_title' => 'Kup backlinki w Polsce | SEOLinkBuildings',
                'meta_description' => 'Polskie backlinki z wpisu gościnnego na prawdziwych witrynach. Cena w EUR, dofollow albo sponsored na pozycji katalogu, live-URL w zamówieniu.',
                'teaser_countries' => ['pl'],
                'teaser_title' => 'Witryny .pl na backlinki',
                'teaser_subtitle' => 'Podgląd pozycji katalogu z głównym krajem Polska. Pokazany ruch jest zadeklarowany, nie obiecany.',
                'intro' => [
                    '«Kup backlinki», «kup backlink» i «kup linki SEO» szukają tego samego: linku na opublikowanej stronie. U nas dostajesz go przez wpis gościnny albo artykuł sponsorowany, z kotwicą z briefu.',
                    'Tematyczne backlinki oznaczają, że wybierasz niszę witryny. «Z prawdziwym ruchem» oznacza, że patrzysz na ruch na pozycji katalogu — nie gwarantujemy odwiedzin.',
                ],
                'points' => [
                    [
                        'title' => 'Jakość, którą czytasz',
                        'body' => 'Kraj, język, nisza, DA/DR i cena stoją na pozycji. Nie sprzedajemy «jakościowych backlinków» jako etykiety bez witryny.',
                    ],
                    [
                        'title' => 'Dofollow nie jest standardem',
                        'body' => 'Filtruj po typie linku. Publisher odpowiada za żywy HTML.',
                    ],
                    [
                        'title' => 'Bez PBN',
                        'body' => 'Nie sprzedajemy prywatnych sieci. Ryzyka są w <a href="'.$guide.'">przewodniku</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Jak wybrać',
                        'body' => 'Zacznij od <a href="'.$market.'">katalogu</a>, filtruj Polskę, porównaj cenę i zasady kotwic. Potem <a href="'.$guest.'">wpis gościnny</a> albo <a href="'.$sponsored.'">artykuł sponsorowany</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Czy mogę kupić tylko dofollow?',
                        'a' => 'Możesz filtrować oferty, które wspominają dofollow. Sprawdź atrybut na żywej stronie.',
                    ],
                    [
                        'q' => 'Czy wstawiacie link w istniejący artykuł?',
                        'a' => 'Nie jako SKU niche edits. Niektóre witryny sprzedają dopłatę na stronę główną z terminem.',
                    ],
                    [
                        'q' => 'Ile kosztują backlinki w Polsce?',
                        'a' => 'Zależy od witryny. <a href="'.$prices.'">Cennik</a> tłumaczy model; katalog pokazuje aktualne kwoty.',
                    ],
                ],
                'cta_primary' => ['label' => 'Porównaj witryny', 'url' => $register],
                'cta_secondary' => ['label' => 'Link building', 'url' => $lb],
                'see_also' => [
                    ['label' => 'Cennik', 'url' => $prices],
                    ['label' => 'Wpis gościnny', 'url' => $guest],
                    ['label' => 'Niche edits', 'url' => $niche],
                ],
            ],
            'link-building' => [
                'kicker' => 'Kampanie',
                'h1' => 'Link building w Polsce',
                'subtitle' => 'Budujesz kampanię z katalogu, publikacja po publikacji, albo wybierasz prowadzony pakiet digital PR przy cenniku. Nie ma «tanich backlinków» bez witryny.',
                'meta_title' => 'Link building w Polsce | SEOLinkBuildings',
                'meta_description' => 'Link building Polska: self-service katalog w EUR, śledzone zamówienia i pakiety digital PR. Bez anonimowych pakietów linków.',
                'teaser_countries' => ['pl'],
                'teaser_title' => 'Wybór na kampanie w Polsce',
                'teaser_subtitle' => 'Te same pozycje katalogu co na stronie wpisu gościnnego. Europę filtrujesz po zalogowaniu.',
                'intro' => [
                    'Link building oznacza tu, że wybierasz publishers, płacisz i śledzisz URL. To nie jest abonament, który «robi SEO» za Ciebie.',
                    'Europejski link building korzysta z tego samego salda. Polska to filtr kraju, nie osobny produkt. Agencja, która pracuje wewnętrznie, może używać tego samego katalogu.',
                ],
                'points' => [
                    [
                        'title' => 'Self-service',
                        'body' => 'Strategia to Twój wybór witryn, kotwic i tempa. Źródłem jest katalog. Miesięczny link building to rytm, który sam ustawiasz.',
                    ],
                    [
                        'title' => 'Pakiety link building',
                        'body' => 'Ponumerowane pakiety przy cenniku to prowadzone kampanie digital PR, nie worek URL-i.',
                    ],
                    [
                        'title' => 'White label',
                        'body' => 'Agencja może zamawiać z własnego konta. Nie dostarczamy portalu z Waszym logo. Szczegóły: <a href="'.$agencies.'">dla agencji</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Wpis gościnny, niche edits i digital PR',
                        'body' => 'Wpis gościnny to nowy artykuł. Niche edits (link w już opublikowanej treści) nie sprzedajemy jako SKU. Digital PR to kampania; na marketplace i tak płacisz publikację.',
                    ],
                    [
                        'h2' => 'Jak zaczyna się kampania',
                        'body' => 'Konto, saldo w EUR, filtr Polska, zamówienie. Przebieg: <a href="'.$how.'">jak to działa</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Czy macie tani link building?',
                        'a' => 'Cena jest pozycji katalogu. Nie mamy osobnej «taniej» warstwy obok katalogu.',
                    ],
                    [
                        'q' => 'Czy robicie też strategię?',
                        'a' => 'Przewodnik tłumaczy ryzyka i kotwice. Wykonanie w self-service jest Twoje.',
                    ],
                ],
                'cta_primary' => ['label' => 'Otwórz katalog', 'url' => $register],
                'cta_secondary' => ['label' => 'Zobacz cennik', 'url' => $prices],
                'see_also' => [
                    ['label' => 'Dla agencji', 'url' => $agencies],
                    ['label' => 'Digital PR', 'url' => $pr],
                    ['label' => 'Przewodnik', 'url' => $guide],
                ],
            ],
            'agencje' => [
                'kicker' => 'Konto B2B',
                'h1' => 'Link building dla agencji w Polsce',
                'subtitle' => 'Self-service katalog dla agencji SEO, resellerów i zespołów, które refakturują. Saldo EUR, śledzone zamówienia, faktury w rozliczeniach reklamodawcy.',
                'meta_title' => 'Link building dla agencji | SEOLinkBuildings',
                'meta_description' => 'Wpis gościnny dla agencji w Polsce: katalog w EUR, faktury, zamówienia per brand — bez portalu resellerskiego z Waszym logo.',
                'teaser_countries' => ['pl'],
                'teaser_title' => 'Wybór, który refakturujesz',
                'teaser_subtitle' => 'Te same pozycje katalogu co dla wewnętrznego reklamodawcy. Konto jest Wasze; brandy są w projektach i zamówieniach.',
                'intro' => [
                    '«Wpis gościnny dla agencji», «white label link building Polska» i «reseller backlinki» szukają partnera, który wykonuje. Tutaj agencja trzyma stery: wybieracie witryny, płacicie i przekazujecie live-URL klientowi.',
                    'Operacyjny white label oznacza, że klient końcowy nie potrzebuje konta. To nie jest program resellerski z Waszym brandem na stronie publicznej.',
                ],
                'points' => [
                    [
                        'title' => 'Jedno saldo, kilka kampanii',
                        'body' => 'Wpłacacie euro (karta albo przelew, gdy metoda jest aktywna) i rozdzielacie saldo na zamówienia.',
                    ],
                    [
                        'title' => 'Zamówienie i faktura',
                        'body' => 'Faktury za wpłaty albo zamówienia pobieracie z rozliczeń reklamodawcy, gdy produkt je wystawia. Dane spółki są brytyjskie (Topurlz Ltd). Nie wymyślamy polskiego NIP-u.',
                    ],
                    [
                        'title' => 'Powierzchnia pracy dla zespołów SEO',
                        'body' => 'Filtry, metryki, czat przy zamówieniu i live-URL. Po zalogowaniu dashboard zostaje po angielsku dla wszystkich ról.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Agencja versus marketplace',
                        'body' => 'Agencja wybiera witryny dla klienta. Marketplace pokazuje witryny kupującemu. SEOLinkBuildings to to drugie. Jeśli Wasz zespół jest agencją, wybór zostaje u Was, a katalog jest źródłem.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Czy możemy ukryć marketplace przed klientem?',
                        'a' => 'Tak, pracując z Waszego konta. Nie dostarczamy portalu white-label z Waszym brandem.',
                    ],
                    [
                        'q' => 'Czy wystawiacie faktury z polskim NIP-em?',
                        'a' => 'Rozliczenia idą za spółką brytyjską. Pobierzcie dokumenty i uzgodnijcie z księgowością. Nie wymyślamy NIP-u ani numeru VAT.',
                    ],
                ],
                'cta_primary' => ['label' => 'Załóż konto agencji', 'url' => $register],
                'cta_secondary' => ['label' => 'Katalog', 'url' => $market],
                'see_also' => [
                    ['label' => 'Link building', 'url' => $lb],
                    ['label' => 'Cennik', 'url' => $prices],
                    ['label' => 'Digital PR', 'url' => $pr],
                ],
            ],
            'digital-pr' => [
                'kicker' => 'PR w mediach cyfrowych',
                'h1' => 'Digital PR w Polsce',
                'subtitle' => 'Kampanie digital PR jako publikacje na witrynach marketplace plus prowadzone pakiety przy cenniku. Bez obietnicy Google News.',
                'meta_title' => 'Digital PR w Polsce | SEOLinkBuildings',
                'meta_description' => 'Digital PR Polska: publikacje z katalogu, saldo EUR, live-URL i prowadzone pakiety — bez gwarancji News ani prasy.',
                'teaser_countries' => ['pl'],
                'teaser_title' => 'Polskie witryny w katalogu',
                'teaser_subtitle' => 'Część publishers przypomina mediakit; nie wszystkie to dziennik. Niszę i język filtrujesz po zalogowaniu.',
                'intro' => [
                    '«Digital PR Polska», «kup komunikat prasowy» i «komunikat prasowy SEO» mieszają PR z link buildingiem. Tutaj kupujesz publikacje na witrynach, które faktycznie są w katalogu.',
                    'Prowadzone pakiety (kwoty stoją przy Cenniku; dziś od 499 €/miesiąc na planie bazowym, jeśli nadal się wyświetla) to wykonanie zespołu, nie przycisk «wejdź do ogólnopolskiej gazety».',
                ],
                'points' => [
                    [
                        'title' => 'Media tylko jeśli są w katalogu',
                        'body' => 'Nie mamy kanału do Google News. Wpis «w prasie» istnieje tylko wtedy, gdy ta witryna jest pozycją katalogu i przyjmuje brief.',
                    ],
                    [
                        'title' => 'Brand mentions',
                        'body' => 'Wzmianka może wynikać z publikacji. Nie sprzedajemy «brand mention» jako SKU bez URL.',
                    ],
                    [
                        'title' => 'Kampanie',
                        'body' => 'Self-service: wybierasz witryny. Prowadzone: pakiety przy cenniku.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'PR i SEO, bez napompowania',
                        'body' => 'Przydatna publikacja ma czytelników, kontekst i link (albo wzmiankę), która ma sens. Nie zastępuje newsa. Katalog: <a href="'.$market.'">lista witryn</a>. Pakiety: <a href="'.$prices.'">cennik</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Czy gwarantujecie artykuł w prasie?',
                        'a' => 'Nie. Dostarczamy URL na witrynie, którą zamówiłeś, jeśli publisher zaakceptuje.',
                    ],
                    [
                        'q' => 'Czy to coś innego niż artykuł sponsorowany?',
                        'a' => 'Artykuł sponsorowany to publikacja. Digital PR to kampania. Na marketplace i tak płacisz publikację. <a href="'.$sponsored.'">Artykuł sponsorowany</a>.',
                    ],
                ],
                'cta_primary' => ['label' => 'Zobacz pakiety i katalog', 'url' => $prices],
                'cta_secondary' => ['label' => 'Rejestracja', 'url' => $register],
                'see_also' => [
                    ['label' => 'Artykuł sponsorowany', 'url' => $sponsored],
                    ['label' => 'Dla agencji', 'url' => $agencies],
                    ['label' => 'Wpis gościnny', 'url' => $guest],
                ],
            ],
            'niche-edits' => [
                'kicker' => 'To nie osobny produkt',
                'h1' => 'Niche edits w Polsce — i co sprzedajemy',
                'subtitle' => 'Niche edits (wstawienie linku w już opublikowany artykuł) nie są SKU w SEOLinkBuildings. Tu jest granica wobec wpisu gościnnego, plus ryzyka.',
                'meta_title' => 'Niche edits w Polsce — wyjaśnienie | SEOLinkBuildings',
                'meta_description' => 'Czym są niche edits i «wstawienie linku w artykuł», kiedy to ryzykowne i dlaczego w Polsce sprzedajemy publikacje redakcyjne — nie wstawianie w cudzy tekst.',
                'teaser_countries' => ['pl'],
                'teaser_title' => 'Witryny redakcyjne, nie sieć wstawek',
                'teaser_subtitle' => 'Podgląd aktywnych pozycji katalogu w Polsce. Standardowy produkt to nowy artykuł z linkiem w tekście.',
                'intro' => [
                    'Niche edit to link w już opublikowanym artykule, często dlatego, że URL jest już zindeksowany. «Wstawienie linku» szuka dokładnie tego.',
                    'Nie sprzedajemy tego jako produktu. Standardowe zamówienie to nowa publikacja (wpis gościnny albo artykuł sponsorowany) z briefem i live-URL. Niektóre witryny oferują dopłatę na stronę główną z terminem; to stoi na pozycji katalogu.',
                ],
                'points' => [
                    [
                        'title' => 'Dlaczego tego nie sprzedajemy',
                        'body' => 'Link w artykule, którego nie pisałeś, trudniej kontrolować i częściej koliduje z zasadami witryny. Nie chcemy obiecywać SKU, którego nie dostarczymy równo.',
                    ],
                    [
                        'title' => 'Co możesz kupić zamiast tego',
                        'body' => '<a href="'.$guest.'">Wpis gościnny</a> albo <a href="'.$sponsored.'">artykuł sponsorowany</a> z kotwicą w nowym tekście.',
                    ],
                    [
                        'title' => 'Ryzyko',
                        'body' => 'Wstawki w starych artykułach mogą zniknąć, zmienić atrybut albo trafić w nieistotne kotwice. Przeczytaj <a href="'.$guide.'">przewodnik</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Kontekstowe backlinki',
                        'body' => 'Link kontekstowy w nowym wpisie gościnnym nadal jest linkiem redakcyjnym. Różnica: znasz brief i dostajesz live-URL w zamówieniu. <a href="'.$links.'">Kup backlinki</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Czy mogę prosić publishera o wstawkę w stary artykuł?',
                        'a' => 'Tylko jeśli pozycja katalogu to opisuje. To nie jest nasz standardowy SKU.',
                    ],
                ],
                'cta_primary' => ['label' => 'Kup zamiast tego wpis gościnny', 'url' => $guest],
                'cta_secondary' => ['label' => 'Katalog', 'url' => $market],
                'see_also' => [
                    ['label' => 'Link building', 'url' => $lb],
                    ['label' => 'Wpis gościnny', 'url' => $guest],
                    ['label' => 'Artykuł sponsorowany', 'url' => $sponsored],
                ],
            ],
            'przewodnik' => [
                'kicker' => 'Jeden przewodnik',
                'h1' => 'Przewodnik: wpis gościnny i link building',
                'subtitle' => 'Czym jest wpis gościnny, jak kupować backlinki, dofollow versus nofollow, kotwice, PBN i ryzyka — na jednej stronie, nie w cienkich artykułach.',
                'meta_title' => 'Przewodnik: wpis gościnny | SEOLinkBuildings',
                'meta_description' => 'Krótki przewodnik: czym jest wpis gościnny, jak kupić backlinki, dofollow vs nofollow, kotwice, rel sponsored i dlaczego PBN nie jest naszym produktem.',
                'teaser_countries' => ['pl'],
                'teaser_title' => 'Od wyjaśnienia do katalogu',
                'teaser_subtitle' => 'Po przewodniku prawdziwe witryny są w polskim katalogu, z ceną za publikację.',
                'intro' => [
                    'Ten przewodnik pokrywa wyszukiwania informacyjne (czym jest link building, jak kupować backlinki, czy backlinki są legalne, tekst kotwicy) bez nowej strony na każde zdanie.',
                    'Dashboard po zalogowaniu zostaje po angielsku. Strona publiczna jest po polsku.',
                ],
                'points' => [
                    [
                        'title' => 'Czym jest wpis gościnny?',
                        'body' => 'Artykuł na cudzej witrynie, zwykle z linkiem do Ciebie, za zapłatę albo wymianę. U nas płatność jest w EUR, per zamówienie. W Polsce ten sam zakup często nazywa się artykułem sponsorowanym.',
                    ],
                    [
                        'title' => 'Dofollow, nofollow, sponsored',
                        'body' => 'Dofollow zwykle przekazuje sygnał. Nofollow i sponsored mówią, że link jest oznaczony. Google traktuje rel sponsored jako link płatny. Wybierz to, co wskazuje pozycja katalogu.',
                    ],
                    [
                        'title' => 'Kotwice',
                        'body' => 'Dokładna kotwica powtarzana na wielu witrynach to ryzykowny wzorzec. Różnicuj sformułowanie i trzymaj kotwicę zgodną ze stroną docelową.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Jak kupować backlinki',
                        'body' => 'Konto, saldo, filtr kraju i niszy, brief, akceptacja live-URL. Operacje: <a href="'.$how.'">jak to działa</a>. Strona handlowa: <a href="'.$links.'">kup backlinki</a>.',
                    ],
                    [
                        'h2' => 'PBN versus wpis gościnny',
                        'body' => 'PBN to sieć, którą sterujesz, żeby wysyłać linki. Tego nie sprzedajemy. Wpis gościnny to publikacja na witrynie z własnymi czytelnikami. Jeśli nie umiesz nazwać witryny, to nie jest ten produkt.',
                    ],
                    [
                        'h2' => 'Ryzyka kupowania backlinków',
                        'body' => 'Witryny bez prawdziwego ruchu, agresywne kotwice, znikające linki, brak oznaczenia sponsored, napompowane metryki. Sprawdź pozycję katalogu i live-URL. Nie obiecujemy pozycji w rankingu.',
                    ],
                    [
                        'h2' => 'Strategia, krótko',
                        'body' => 'Kilka trafnych publikacji bije wolumen linków bez kontekstu. Do wykonania: <a href="'.$lb.'">link building</a>, <a href="'.$guest.'">wpis gościnny</a>, <a href="'.$publisher.'">zostań wydawcą</a>, jeśli sprzedajesz miejsce.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Czy to przewodnik link building 2026?',
                        'a' => 'Nie. To strona produktowa, która tłumaczy aktualne zasady marketplace, nie kalendarz trendów.',
                    ],
                    [
                        'q' => 'Gdzie zobaczę ceny?',
                        'a' => 'Model jest przy <a href="'.$prices.'">cenniku</a>. Aktualne kwoty są w katalogu po rejestracji.',
                    ],
                ],
                'cta_primary' => ['label' => 'Zobacz wybór', 'url' => $register],
                'cta_secondary' => ['label' => 'Wpis gościnny', 'url' => $guest],
                'see_also' => [
                    ['label' => 'Link building', 'url' => $lb],
                    ['label' => 'Kup backlinki', 'url' => $links],
                    ['label' => 'Cennik', 'url' => $prices],
                ],
            ],
        ];
    }
}
