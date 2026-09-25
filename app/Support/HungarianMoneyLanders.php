<?php

namespace App\Support;

/**
 * Hungary money / B2B landers (Hungarian as used by SEO teams, plus genuine loanwords).
 * Marketplace stays /hu/piac and pricing stays /hu/arak.
 * Indexable campaign slug is linkepites; linkbuilding is an alias only.
 */
class HungarianMoneyLanders
{
    use MoneyLanderPages;

    public const LOCALE = 'hu';

    /**
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return [
            'marketplace' => '/hu/piac',
            'katalogus-publishers' => '/hu/piac',
            'guest-post-hungary' => '/hu/vendegposzt',
            'vendegcikk' => '/hu/vendegposzt',
            'vendegposzt-vasarlas' => '/hu/vendegposzt',
            'advertorial' => '/hu/szponzoralt-cikk',
            'fizetett-cikk' => '/hu/szponzoralt-cikk',
            'magyar-backlinkek' => '/hu/backlink-vasarlas',
            'seo-linkek' => '/hu/backlink-vasarlas',
            'vendegposzt-ar' => '/hu/arak',
            'linkepites-arak' => '/hu/arak',
            'white-label-linkepites' => '/hu/ugynoksegek',
            'sajtokozlemeny' => '/hu/digital-pr',
            'link-beszuras' => '/hu/niche-edits',
            'guide' => '/hu/utmutato',
            'linkbuilding' => '/hu/linkepites',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function ui(): array
    {
        return [
            'cluster_title' => 'Oldalak vendégposztról, backlinkről és linképítésről Magyarországon',
            'from' => 'Ettől',
            'price_note' => 'A legalacsonyabb aktuális euróár az aktív, ellenőrzött katalógussorokon, ahol a főország Magyarország. Nem rögzített árlista.',
            'sites_preview' => 'Site-ok az előnézetben',
            'count_note' => 'Aktív, ellenőrzött publishers, ahol a főország Magyarország, ha a számláló elérhető.',
            'th_site' => 'Site',
            'th_country' => 'Ország',
            'th_language' => 'Nyelv',
            'th_from' => 'Ettől',
            'teaser_foot' => 'A DA, a DR és az euróár akkor jelenik meg, ha a katalógussoron szerepel. A hiányzó mutatók üresek maradnak.',
            'see_also' => 'Lásd még',
        ];
    }

    /**
     * @return list<array{slug: string, label: string, url: string}>
     */
    public static function clusterLinks(?string $current = null): array
    {
        return self::filterCluster([
            ['slug' => 'home', 'label' => 'Piac Magyarország', 'url' => url('/hu')],
            ['slug' => 'vendegposzt', 'label' => 'Vendégposzt', 'url' => self::url('vendegposzt')],
            ['slug' => 'szponzoralt-cikk', 'label' => 'Szponzorált cikk', 'url' => self::url('szponzoralt-cikk')],
            ['slug' => 'piac', 'label' => 'Publishers katalógus', 'url' => self::marketingUrl('marketplace')],
            ['slug' => 'linkepites', 'label' => 'Linképítés', 'url' => self::url('linkepites')],
            ['slug' => 'backlink-vasarlas', 'label' => 'Backlink vásárlás', 'url' => self::url('backlink-vasarlas')],
            ['slug' => 'arak', 'label' => 'Árak', 'url' => self::marketingUrl('pricing')],
            ['slug' => 'ugynoksegek', 'label' => 'Ügynökségeknek', 'url' => self::url('ugynoksegek')],
            ['slug' => 'digital-pr', 'label' => 'Digital PR', 'url' => self::url('digital-pr')],
            ['slug' => 'niche-edits', 'label' => 'Niche edits', 'url' => self::url('niche-edits')],
            ['slug' => 'utmutato', 'label' => 'Útmutató', 'url' => self::url('utmutato')],
        ], $current);
    }

    /**
     * @return list<array{slug: string, label: string, url: string}>
     */
    public static function footerLinks(): array
    {
        return array_values(array_filter(
            self::clusterLinks('home'),
            static fn (array $item) => ! in_array($item['slug'], ['piac', 'arak'], true)
        ));
    }

    /**
     * @return array{h2: string, body: string, links: string}
     */
    public static function marketplaceCopy(): array
    {
        return [
            'h2' => 'Magyar publishers katalógusa',
            'body' => 'Itt van a nyilvános lista azokkal a publishers-szel, ahol a főország Magyarország: niche, nyelv, DA/DR és euróár. Nem indexelünk minden szűrőkombinációt, és a városoknak (Budapest és mások) nincs saját URL-jük. A teljes katalógus domainekkel regisztráció után nyílik.',
            'links' => '<a href="'.self::url('vendegposzt').'">Vendégposzt Magyarországon</a> · <a href="'.self::url('backlink-vasarlas').'">Backlink vásárlás</a> · <a href="'.self::marketingUrl('pricing').'">Mennyibe kerül a vendégposzt</a> · <a href="'.url('/guest-posts-hungary').'">Hungary inventory (English)</a>',
        ];
    }

    /**
     * @return array{h2: string, body: string}
     */
    public static function pricingCopy(): array
    {
        return [
            'h2' => 'Mennyibe kerül egy vendégposzt Magyarországon',
            'body' => 'Nem adunk ki rögzített PDF-árlistát, és nem találunk ki magyar adószámot: az ár a site-é, euróban. A «mennyibe kerül a vendégposzt», a «linképítés árak» és a «backlink ár» azokhoz a katalógussorokhoz igazodik, amelyeket Ön választ. A fenti sorszámozott csomagok irányított digital-PR kampányok, nem zsáknyi névtelen URL. Az aktuális összegek a <a href="'.self::marketingUrl('marketplace').'">magyar katalógusban</a> jelennek meg regisztráció után. A székhely Londonban van (Topurlz Ltd).',
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
        $guest = self::url('vendegposzt');
        $sponsored = self::url('szponzoralt-cikk');
        $links = self::url('backlink-vasarlas');
        $lb = self::url('linkepites');
        $agencies = self::url('ugynoksegek');
        $pr = self::url('digital-pr');
        $niche = self::url('niche-edits');
        $guide = self::url('utmutato');
        $publisher = self::marketingUrl('become-a-publisher');

        return [
            'vendegposzt' => [
                'kicker' => 'Vendégposzt backlinkkel',
                'h1' => 'Vendégposzt magyar blogokon',
                'subtitle' => 'Válasszon ellenőrzött magyar és európai publishers közül, hasonlítsa a niche-t, a DA/DR-t és az euróárat, küldje a briefet, és kövesse az élő URL-t a megrendelésben.',
                'meta_title' => 'Vendégposzt magyar blogokon | SEOLinkBuildings',
                'meta_description' => 'Vendégposzt magyar blogokon és .hu site-okon: szűrje a niche-t, a DA/DR-t és az EUR-árat, válasszon dofollow vagy sponsored linket, és kapja meg az élő URL-t.',
                'teaser_countries' => ['hu'],
                'teaser_title' => 'Site-ok vendégposzthoz Magyarországon',
                'teaser_subtitle' => 'Maszkolt előnézet az aktív magyar katalógussorokról. A domaineket regisztráció után látja.',
                'intro' => [
                    'A SEOLinkBuildings self-service piactér, nem átláthatatlan vendégposzt-csomag. Ön választja a site-ot — gyakran .hu, gyakran magyarul —, euróban fizet az egyenlegből, és a brief, a chat és az élő URL ugyanabban a megrendelésben marad. A székhely Londonban van (Topurlz Ltd); nem találunk ki magyar adószámot.',
                    'A «vendégposzt vásárlás», a «guest post Magyarország» és a «vendégcikk» ugyanazt jelenti: fizetett publikáció olyan site-on, amelyet Ön nem birtokol, írásos szabályokkal a hosszra, a linkekre és a leadásra.',
                ],
                'points' => [
                    [
                        'title' => 'Publishers, nem fantomlista',
                        'body' => 'Minden sor egy site niche-sel, nyelvvel, országgal, DA/DR-rel, bevallott forgalommal és árral. Nem árulunk PBN-t és nem árulunk «50 linkes csomagot».',
                    ],
                    [
                        'title' => 'Így rendel',
                        'body' => 'Fiókot hoz létre, Magyarországra szűr, a site-ot a kosárba teszi, majd címet, szöveget vagy briefet küld horgonnyal. A publisher az élő URL-t jóváhagyásra adja át.',
                    ],
                    [
                        'title' => 'Dofollow és sponsored',
                        'body' => 'A linkattribútum a katalógussoron áll. Sok média megjelöli a fizetett publikációt. Olvassa el a linktípust rendelés előtt — nincs «dofollow mindenáron».',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Forgalom, niche és állandó linkek',
                        'body' => 'A «forgalmas» vendégposzt azt jelenti, hogy a katalógussor a publisher által bevallott forgalmat mutatja — nem látogatói garanciát. A niche-eket (egészség, pénzügy, tech, biztosítás, ingatlan, travel, ecommerce) a katalógusban szűri, nem külön URL-eken. Az «állandó» a site szabályaitól függ. Olvassa el a katalógussort.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Csak .hu site-on publikálhatok?',
                        'a' => 'Igen. Magyarországra szűr. Az európai katalógus ugyanahhoz az EUR-egyenleghez tartozik. Ausztria és Szlovákia külön szűrő.',
                    ],
                    [
                        'q' => 'Megírják a cikket?',
                        'a' => 'A standard megrendelés az Ön briefjét használja. Egyes katalógussorok szerkesztést kínálnak; ezt a site-on látja, nem kitalált felárként itt.',
                    ],
                    [
                        'q' => 'Van oldal Budapestnek?',
                        'a' => 'Nincs. A városoknak nincs saját URL-jük. Ön az országot szűri.',
                    ],
                ],
                'cta_primary' => ['label' => 'Fiók nyitása és publishers megtekintése', 'url' => $register],
                'cta_secondary' => ['label' => 'Publishers katalógus', 'url' => $market],
                'see_also' => [
                    ['label' => 'Backlink vásárlás', 'url' => $links],
                    ['label' => 'Szponzorált cikk', 'url' => $sponsored],
                    ['label' => 'Árak', 'url' => $prices],
                    ['label' => 'Útmutató', 'url' => $guide],
                ],
            ],
            'szponzoralt-cikk' => [
                'kicker' => 'Advertorial',
                'h1' => 'Szponzorált cikk vásárlása Magyarországon',
                'subtitle' => 'Fizetett cikket vesz a katalógus egyik site-ján, euróárral és élő URL-lel. Nem árulunk általános sajtóközlemény-előfizetést.',
                'meta_title' => 'Szponzorált cikk Magyarországon | SEOLinkBuildings',
                'meta_description' => 'Szponzorált cikk vagy advertorial magyar site-okon. EUR-ár katalógussoronként, brief és élő URL — kitalált sajtóiroda nélkül.',
                'teaser_countries' => ['hu'],
                'teaser_title' => 'Média vendégposzthoz és advertorialhoz',
                'teaser_subtitle' => 'Ugyanaz a magyar publishers-előnézet. Advertorial csak akkor van, ha a site a katalógusban szerepel.',
                'intro' => [
                    'A «szponzorált cikk vásárlás», a «fizetett cikk SEO» és az «advertorial» fizetett publikációt ír le, nem sajtósort, amellyel nem rendelkezünk. Ha a domain nincs a katalógusban, nem áruljuk.',
                    'A native advertising SEO-hoz itt ugyanaz a folyamat: Ön választja a publikációt, EUR-ban fizet, és megkapja az URL-t. Nem ígérünk Google News-t.',
                ],
                'points' => [
                    [
                        'title' => 'Szponzorált jelölés',
                        'body' => 'Sok site rel sponsored-et vagy látható jelölést kér. Kövesse a katalógussor szabályát.',
                    ],
                    [
                        'title' => 'Az ár',
                        'body' => 'A szponzorált cikk ára a site-tól függ. A modellhez lásd az <a href="'.$prices.'">árakat</a>, az aktuális összegekhez a katalógust.',
                    ],
                    [
                        'title' => 'Tartalom',
                        'body' => 'Ön küldi a szöveget vagy a briefet. A publisher a saját site-ján publikál, és elküldi az élő URL-t.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Advertorial versus vendégposzt',
                        'body' => 'Gyakorlatban mindkettő fizetett publikáció linkkel. A különbség szerkesztői. Az elszámolás ugyanaz. <a href="'.$guest.'">Vendégposzt</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Van egységes advertorial-díj?',
                        'a' => 'Nincs. Minden katalógussornak saját euróára van.',
                    ],
                    [
                        'q' => 'Minden napilapban elhelyezik?',
                        'a' => 'Csak azokon a site-okon, amelyek a katalógusban vannak, és elfogadják a megrendelést.',
                    ],
                ],
                'cta_primary' => ['label' => 'Magyar site-ok megtekintése', 'url' => $register],
                'cta_secondary' => ['label' => 'Digital PR', 'url' => $pr],
                'see_also' => [
                    ['label' => 'Digital PR', 'url' => $pr],
                    ['label' => 'Árak', 'url' => $prices],
                    ['label' => 'Vendégposzt', 'url' => $guest],
                ],
            ],
            'backlink-vasarlas' => [
                'kicker' => 'Szerkesztői backlinkek',
                'h1' => 'Backlink vásárlás Magyarországon',
                'subtitle' => 'A backlink itt olyan publikációból származó link, amelyet valós site-on vesz — nem névtelen URL-zsák.',
                'meta_title' => 'Backlink vásárlás Magyarországon | SEOLinkBuildings',
                'meta_description' => 'Magyar backlinkek vendégposztból valós site-okon. EUR-ár, dofollow vagy sponsored a katalógussoron, élő URL a megrendelésben.',
                'teaser_countries' => ['hu'],
                'teaser_title' => '.hu site-ok backlinkhez',
                'teaser_subtitle' => 'Előnézet a Magyarország főországú katalógussorokról. A mutatott forgalom bevallott, nem ígért.',
                'intro' => [
                    'A «backlink vásárlás», a «backlinket venni» és a «SEO linkek vásárlása» ugyanazt keresi: linket egy publikált oldalon. Nálunk ezt vendégposzttal vagy szponzorált cikkel kapja, a brief horgonyával.',
                    'A tematikus backlink azt jelenti, hogy Ön választja a site niche-ét. A «valódi forgalommal» azt jelenti, hogy megnézi a katalógussor forgalmát — látogatót nem garantálunk.',
                ],
                'points' => [
                    [
                        'title' => 'Olvasható minőség',
                        'body' => 'Ország, nyelv, niche, DA/DR és ár a soron áll. Nem árulunk «minőségi backlinket» címkeként site nélkül.',
                    ],
                    [
                        'title' => 'A dofollow nem alapértelmezett',
                        'body' => 'Szűrjön linktípusra. Az élő HTML a publisher felelőssége.',
                    ],
                    [
                        'title' => 'Nincs PBN',
                        'body' => 'Nem árulunk magánhálózatot. A kockázatok az <a href="'.$guide.'">útmutatóban</a> vannak.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Így választ',
                        'body' => 'Kezdje a <a href="'.$market.'">katalógusban</a>, szűrjön Magyarországra, hasonlítsa az árat és a horgonyszabályokat. Ezután <a href="'.$guest.'">vendégposzt</a> vagy <a href="'.$sponsored.'">szponzorált cikk</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Csak dofollow-t vehetek?',
                        'a' => 'Szűrhet olyan ajánlatokra, amelyek dofollow-t említenek. Ellenőrizze az attribútumot az élő oldalon.',
                    ],
                    [
                        'q' => 'Beszúrnak linket egy meglévő cikkbe?',
                        'a' => 'Nem niche-edits SKU-ként. Egyes site-ok határidős honlap-felárat árulnak.',
                    ],
                    [
                        'q' => 'Mennyibe kerülnek a backlinkek Magyarországon?',
                        'a' => 'A site-tól függ. Az <a href="'.$prices.'">árak</a> a modellt magyarázzák; a katalógus az aktuális összegeket mutatja.',
                    ],
                ],
                'cta_primary' => ['label' => 'Site-ok összehasonlítása', 'url' => $register],
                'cta_secondary' => ['label' => 'Linképítés', 'url' => $lb],
                'see_also' => [
                    ['label' => 'Árak', 'url' => $prices],
                    ['label' => 'Vendégposzt', 'url' => $guest],
                    ['label' => 'Niche edits', 'url' => $niche],
                ],
            ],
            'linkepites' => [
                'kicker' => 'Kampányok',
                'h1' => 'Linképítés Magyarországon',
                'subtitle' => 'A kampányt a katalógusból építi, publikációnként, vagy irányított digital-PR csomagot választ az áraknál. Nincs «olcsó backlink» site nélkül.',
                'meta_title' => 'Linképítés Magyarországon | SEOLinkBuildings',
                'meta_description' => 'Linképítés Magyarország: self-service katalógus EUR-ban, követett megrendelések és digital-PR csomagok. Nincs névtelen linkcsomag.',
                'teaser_countries' => ['hu'],
                'teaser_title' => 'Választék kampányokhoz Magyarországon',
                'teaser_subtitle' => 'Ugyanazok a katalógussorok, mint a vendégposzt oldalon. Európát belépés után szűri.',
                'intro' => [
                    'A linképítés itt azt jelenti, hogy Ön választ publishers-t, fizet, és követi az URL-t. Ez nem előfizetés, amely «csinálja az SEO-t» Ön helyett.',
                    'Az európai linképítés ugyanazt az egyenleget használja. Magyarország ország-szűrő, nem külön termék. A belső ügynökségi csapat ugyanazt a katalógust használhatja.',
                ],
                'points' => [
                    [
                        'title' => 'Self-service',
                        'body' => 'A stratégia az Ön site-, horgony- és tempóválasztása. A forrás a katalógus. A havi linképítés az a ritmus, amelyet Ön állít be.',
                    ],
                    [
                        'title' => 'Linképítés-csomagok',
                        'body' => 'Az áraknál sorszámozott csomagok irányított digital-PR kampányok, nem URL-zsák.',
                    ],
                    [
                        'title' => 'White label',
                        'body' => 'Az ügynökség a saját fiókjából rendelhet. Nem adunk portált az Ön logójával. Részletek: <a href="'.$agencies.'">ügynökségeknek</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Vendégposzt, niche edits és digital PR',
                        'body' => 'A vendégposzt új cikk. A niche edits-et (link meglévő tartalomban) nem áruljuk SKU-ként. A digital PR a kampány; a piactéren továbbra is a publikációt fizeti.',
                    ],
                    [
                        'h2' => 'Így indul egy kampány',
                        'body' => 'Fiók, EUR-egyenleg, Magyarország-szűrő, megrendelés. A folyamat: <a href="'.$how.'">hogyan működik</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Van olcsó linképítés?',
                        'a' => 'Az ár a katalógussoré. Nincs külön «olcsó» réteg a katalógus mellett.',
                    ],
                    [
                        'q' => 'A stratégiát is megcsinálják?',
                        'a' => 'Az útmutató a kockázatokat és a horgonyokat magyarázza. A self-service végrehajtás az Öné.',
                    ],
                ],
                'cta_primary' => ['label' => 'Katalógus megnyitása', 'url' => $register],
                'cta_secondary' => ['label' => 'Árak megtekintése', 'url' => $prices],
                'see_also' => [
                    ['label' => 'Ügynökségeknek', 'url' => $agencies],
                    ['label' => 'Digital PR', 'url' => $pr],
                    ['label' => 'Útmutató', 'url' => $guide],
                ],
            ],
            'ugynoksegek' => [
                'kicker' => 'B2B-fiók',
                'h1' => 'Linképítés ügynökségeknek Magyarországon',
                'subtitle' => 'Self-service katalógus SEO-ügynökségeknek, resellereknek és tovább-számlázó csapatoknak. EUR-egyenleg, követett megrendelések, számlák a hirdetői számlázásban.',
                'meta_title' => 'Linképítés ügynökségeknek | SEOLinkBuildings',
                'meta_description' => 'Vendégposzt ügynökségeknek Magyarországon: EUR-katalógus, számlák, megrendelések brandenként — resellerportál nélkül az Ön logójával.',
                'teaser_countries' => ['hu'],
                'teaser_title' => 'Választék, amelyet tovább-számlázhat',
                'teaser_subtitle' => 'Ugyanazok a katalógussorok, mint egy belső hirdetőnek. A fiók az Öné; a brandek a projektekben és a megrendelésekben vannak.',
                'intro' => [
                    'A «vendégposzt ügynökségeknek», a «white label linképítés Magyarország» és a «reseller backlink» végrehajtó partnert keres. Itt az ügynökség tartja a kormányt: Ön választja a site-okat, fizet, és adja át az élő URL-t az ügyfélnek.',
                    'Az operatív white label azt jelenti, hogy a végfelhasználónak nem kell fiók. Ez nem resellerprogram az Ön brandjével a nyilvános oldalon.',
                ],
                'points' => [
                    [
                        'title' => 'Egy egyenleg, több kampány',
                        'body' => 'Eurót tölt (kártya vagy átutalás, ha a módszer aktív), és az egyenleget megrendelésekre osztja.',
                    ],
                    [
                        'title' => 'Megrendelés és számla',
                        'body' => 'A befizetések vagy megrendelések számláit a hirdetői számlázásból tölti le, amikor a termék kiállítja őket. A cégadatok brit adatok (Topurlz Ltd). Nem találunk ki magyar adószámot.',
                    ],
                    [
                        'title' => 'Munkafelület SEO-csapatoknak',
                        'body' => 'Szűrők, mutatók, chat a megrendelésen és élő URL. Belépés után a dashboard angol marad minden szerepkörnek.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Ügynökség versus piactér',
                        'body' => 'Az ügynökség site-okat választ az ügyfélnek. A piactér site-okat mutat a vevőnek. A SEOLinkBuildings az utóbbi. Ha a csapata az ügynökség, a választás Önnél marad, a katalógus a forrás.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Elrejthetjük a piacteret az ügyfél elől?',
                        'a' => 'Igen, a saját fiókból dolgozva. Nem adunk white-label portált az Ön brandjével.',
                    ],
                    [
                        'q' => 'Magyar áfaszámos számlát állítanak ki?',
                        'a' => 'A számlázás a brit társaságot követi. Töltse le a dokumentumokat, és egyeztessen a könyveléssel. Nem találunk ki adószámot vagy áfaszámot.',
                    ],
                ],
                'cta_primary' => ['label' => 'Ügynökségi fiók nyitása', 'url' => $register],
                'cta_secondary' => ['label' => 'Katalógus', 'url' => $market],
                'see_also' => [
                    ['label' => 'Linképítés', 'url' => $lb],
                    ['label' => 'Árak', 'url' => $prices],
                    ['label' => 'Digital PR', 'url' => $pr],
                ],
            ],
            'digital-pr' => [
                'kicker' => 'PR digitális médiában',
                'h1' => 'Digital PR Magyarországon',
                'subtitle' => 'Digital-PR kampányok publikációkként a piactér site-jain, plusz irányított csomagok az áraknál. Nincs Google News-ígéret.',
                'meta_title' => 'Digital PR Magyarországon | SEOLinkBuildings',
                'meta_description' => 'Digital PR Magyarország: publikációk a katalógusból, EUR-egyenleg, élő URL és irányított csomagok — News- vagy sajtógarancia nélkül.',
                'teaser_countries' => ['hu'],
                'teaser_title' => 'Magyar site-ok a katalógusban',
                'teaser_subtitle' => 'Egyes publishers mediakitre hasonlít; nem mind napilap. A niche-t és a nyelvet belépés után szűri.',
                'intro' => [
                    'A «digital PR Magyarország», a «sajtóközlemény vásárlás» és a «sajtóközlemény SEO» a PR-t keveri a linképítéssel. Itt olyan site-ok publikációit veszi, amelyek ténylegesen a katalógusban vannak.',
                    'Az irányított csomagok (az összegek az Áraknál állnak; ma 499 €/hó-tól az alapterven, ha még látszik) csapatvégrehajtás, nem gomb «kerüljön országos napilapba».',
                ],
                'points' => [
                    [
                        'title' => 'Média csak akkor, ha a katalógusban van',
                        'body' => 'Nincs Google News-csatornánk. A «sajtóban» megjelenő vendégposzt csak akkor létezik, ha az a site katalógussor, és elfogadja a briefet.',
                    ],
                    [
                        'title' => 'Brand mentions',
                        'body' => 'Az említés jöhet publikációból. Nem árulunk «brand mention» SKU-t URL nélkül.',
                    ],
                    [
                        'title' => 'Kampányok',
                        'body' => 'Self-service: Ön választja a site-okat. Irányított: a csomagok az áraknál.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'PR és SEO, felfújás nélkül',
                        'body' => 'A hasznos publikációnak olvasói, kontextusa és értelmes linkje (vagy említése) van. Nem helyettesít hírt. Katalógus: <a href="'.$market.'">a site-ok listája</a>. Csomagok: <a href="'.$prices.'">árak</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Garantálnak cikket a sajtóban?',
                        'a' => 'Nem. Az Ön által rendelt site URL-jét adjuk át, ha a publisher elfogadja.',
                    ],
                    [
                        'q' => 'Ez más, mint az advertorial?',
                        'a' => 'Az advertorial a publikáció. A digital PR a kampány. A piactéren továbbra is a publikációt fizeti. <a href="'.$sponsored.'">Szponzorált cikk</a>.',
                    ],
                ],
                'cta_primary' => ['label' => 'Csomagok és katalógus', 'url' => $prices],
                'cta_secondary' => ['label' => 'Regisztráció', 'url' => $register],
                'see_also' => [
                    ['label' => 'Szponzorált cikk', 'url' => $sponsored],
                    ['label' => 'Ügynökségeknek', 'url' => $agencies],
                    ['label' => 'Vendégposzt', 'url' => $guest],
                ],
            ],
            'niche-edits' => [
                'kicker' => 'Nem külön termék',
                'h1' => 'Niche edits Magyarországon — és mit árulunk',
                'subtitle' => 'A niche edits (link beszúrása már publikált cikkbe) nem SKU a SEOLinkBuildingsön. Itt a határ a vendégposzthoz képest, plusz a kockázatok.',
                'meta_title' => 'Niche edits Magyarországon | SEOLinkBuildings',
                'meta_description' => 'Mik a niche edits és a «link beszúrása cikkbe», mikor kockázatos, és miért szerkesztői publikációt árulunk Magyarországon — nem idegen cikkbe való beszúrást.',
                'teaser_countries' => ['hu'],
                'teaser_title' => 'Szerkesztői site-ok, nem beszúróhálózat',
                'teaser_subtitle' => 'Előnézet az aktív magyar katalógussorokról. A standard termék új cikk linkkel a szövegben.',
                'intro' => [
                    'A niche edit link egy már publikált cikkben, gyakran azért, mert az URL már indexelt. A «link beszúrása cikkbe» pont ezt keresi.',
                    'Ezt nem áruljuk termékként. A standard megrendelés új publikáció (vendégposzt vagy szponzorált cikk) brief-fel és élő URL-lel. Egyes site-ok határidős honlap-felárat kínálnak; ez a katalógussoron áll.',
                ],
                'points' => [
                    [
                        'title' => 'Miért nem áruljuk',
                        'body' => 'A link olyan cikkben, amelyet Ön nem írt, nehezebben ellenőrizhető, és gyakrabban ütközik a site saját szabályaival. Nem ígérünk SKU-t, amelyet nem tudunk egységesen szállítani.',
                    ],
                    [
                        'title' => 'Mit vehet helyette',
                        'body' => '<a href="'.$guest.'">Vendégposztot</a> vagy <a href="'.$sponsored.'">szponzorált cikket</a> a horgonnyal az új szövegben.',
                    ],
                    [
                        'title' => 'Kockázat',
                        'body' => 'A régi cikkekbe tett beszúrások eltűnhetnek, attribútumot válthatnak, vagy irreleváns horgonyra eshetnek. Olvassa el az <a href="'.$guide.'">útmutatót</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Kontextuális backlinkek',
                        'body' => 'Az új vendégposztban lévő kontextuslink továbbra is szerkesztői link. A különbség: ismeri a briefet, és megkapja az élő URL-t a megrendelésben. <a href="'.$links.'">Backlink vásárlás</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kérhetem a publishert, hogy régi cikkbe szúrjon?',
                        'a' => 'Csak ha a katalógussor leírja. Ez nem a standard SKU-nk.',
                    ],
                ],
                'cta_primary' => ['label' => 'Vegyen inkább vendégposztot', 'url' => $guest],
                'cta_secondary' => ['label' => 'Katalógus', 'url' => $market],
                'see_also' => [
                    ['label' => 'Linképítés', 'url' => $lb],
                    ['label' => 'Vendégposzt', 'url' => $guest],
                    ['label' => 'Szponzorált cikk', 'url' => $sponsored],
                ],
            ],
            'utmutato' => [
                'kicker' => 'Egy útmutató',
                'h1' => 'Útmutató: vendégposzt és linképítés',
                'subtitle' => 'Mi a vendégposzt, hogyan vesz backlinket, dofollow versus nofollow, horgonyok, PBN és kockázatok — egy oldalon, nem vékony cikkekben.',
                'meta_title' => 'Útmutató vendégposzthoz | SEOLinkBuildings',
                'meta_description' => 'Rövid útmutató: mi a vendégposzt, hogyan vesz backlinket, dofollow vs nofollow, horgonyok, rel sponsored, és miért nem PBN a termékünk.',
                'teaser_countries' => ['hu'],
                'teaser_title' => 'A magyarázattól a katalógusig',
                'teaser_subtitle' => 'Az útmutató után a valódi site-ok a magyar katalógusban vannak, publikációnkénti árral.',
                'intro' => [
                    'Ez az útmutató az információs kereséseket fedi (mi a linképítés, hogyan vesz backlinket, legális-e a backlink, horgonyszöveg) anélkül, hogy minden mondatra új oldalt nyitnánk.',
                    'Belépés után a dashboard angol marad. A nyilvános oldal magyar.',
                ],
                'points' => [
                    [
                        'title' => 'Mi a vendégposzt?',
                        'body' => 'Cikk valaki más site-ján, jellemzően linkkel Önhöz, fizetés vagy csere ellenében. Nálunk a fizetés EUR-ban, megrendelésenként történik.',
                    ],
                    [
                        'title' => 'Dofollow, nofollow, sponsored',
                        'body' => 'A dofollow általában jelet ad tovább. A nofollow és a sponsored azt mondja, hogy a link jelölt. A Google a rel sponsored-et fizetett linkként kezeli. Azt válassza, amit a katalógussor megad.',
                    ],
                    [
                        'title' => 'Horgonyok',
                        'body' => 'A sok site-on ismételt exact horgony kockázatos minta. Változtassa a megfogalmazást, és tartsa a horgonyt relevánsnak a céloldalhoz.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Hogyan vesz backlinket',
                        'body' => 'Fiók, egyenleg, ország- és niche-szűrő, brief, élő URL jóváhagyása. Üzemeltetés: <a href="'.$how.'">hogyan működik</a>. Kereskedelmi oldal: <a href="'.$links.'">backlink vásárlás</a>.',
                    ],
                    [
                        'h2' => 'PBN versus vendégposzt',
                        'body' => 'A PBN olyan hálózat, amelyet Ön irányít, hogy linkeket küldjön. Ezt nem áruljuk. A vendégposzt publikáció saját olvasókkal rendelkező site-on. Ha nem tudja megnevezni a site-ot, ez nem ez a termék.',
                    ],
                    [
                        'h2' => 'A backlink-vásárlás kockázatai',
                        'body' => 'Valódi forgalom nélküli site-ok, agresszív horgonyok, eltűnő linkek, hiányzó sponsored jelölés, felfújt mutatók. Ellenőrizze a katalógussort és az élő URL-t. Nem ígérünk helyezést.',
                    ],
                    [
                        'h2' => 'Stratégia, röviden',
                        'body' => 'Kevesebb releváns publikáció többet ér, mint kontextus nélküli linktömeg. Végrehajtáshoz: <a href="'.$lb.'">linképítés</a>, <a href="'.$guest.'">vendégposzt</a>, <a href="'.$publisher.'">legyen publisher</a>, ha helyet árul.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Ez egy 2026-os linképítés-útmutató?',
                        'a' => 'Nem. Ez termékoldal, amely a piactér jelenlegi szabályait magyarázza, nem trendnaptár.',
                    ],
                    [
                        'q' => 'Hol látom az árakat?',
                        'a' => 'A modell az <a href="'.$prices.'">áraknál</a> áll. Az aktuális összegek a katalógusban vannak regisztráció után.',
                    ],
                ],
                'cta_primary' => ['label' => 'Választék megtekintése', 'url' => $register],
                'cta_secondary' => ['label' => 'Vendégposzt', 'url' => $guest],
                'see_also' => [
                    ['label' => 'Linképítés', 'url' => $lb],
                    ['label' => 'Backlink vásárlás', 'url' => $links],
                    ['label' => 'Árak', 'url' => $prices],
                ],
            ],
        ];
    }
}
