<?php

namespace App\Support;

/**
 * Estonia money / B2B landers (Estonian as used by SEO teams, plus genuine loanwords).
 * Marketplace stays /ee/turg and pricing stays /ee/hinnad.
 */
class EstonianMoneyLanders
{
    use MoneyLanderPages;

    public const LOCALE = 'ee';

    /**
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return [
            'marketplace' => '/ee/turg',
            'katalog-publishers' => '/ee/turg',
            'guest-post-estonia' => '/ee/osta-kulalispostitus',
            'kulalispostitus-blogides' => '/ee/osta-kulalispostitus',
            'osta-guest-post' => '/ee/osta-kulalispostitus',
            'sponsorartikkel' => '/ee/osta-kulalispostitus',
            'advertorial' => '/ee/sponsoreeritud-artikkel',
            'tasuline-artikkel' => '/ee/sponsoreeritud-artikkel',
            'eesti-backlingid' => '/ee/osta-backlinke',
            'osta-linke' => '/ee/osta-backlinke',
            'kulalispostituse-hind' => '/ee/hinnad',
            'linkbuilding-hinnad' => '/ee/hinnad',
            'white-label-linkbuilding' => '/ee/agentuurid',
            'pressiteade' => '/ee/digital-pr',
            'lisa-link' => '/ee/niche-edits',
            'guide' => '/ee/juhend',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function ui(): array
    {
        return [
            'cluster_title' => 'Lehed külalispostituse, backlinkide ja linkbuildingu kohta Eestis',
            'from' => 'Alates',
            'price_note' => 'Madalaim kehtiv eurohind aktiivsetel, kontrollitud kataloogiridadel, mille põhiriik on Eesti. See ei ole fikseeritud hinnakiri.',
            'sites_preview' => 'Saidid eelvaates',
            'count_note' => 'Aktiivsed, kontrollitud publishers, kelle põhiriik on Eesti, kui loendus on saadaval.',
            'th_site' => 'Sait',
            'th_country' => 'Riik',
            'th_language' => 'Keel',
            'th_from' => 'Alates',
            'teaser_foot' => 'Näitame DA-d, DR-i ja eurohinda, kui need on kataloogireal. Puuduvad näitajad jäävad tühjaks.',
            'see_also' => 'Vaadake ka',
        ];
    }

    /**
     * @return list<array{slug: string, label: string, url: string}>
     */
    public static function clusterLinks(?string $current = null): array
    {
        return self::filterCluster([
            ['slug' => 'home', 'label' => 'Turg Eesti', 'url' => url('/ee')],
            ['slug' => 'osta-kulalispostitus', 'label' => 'Osta külalispostitus', 'url' => self::url('osta-kulalispostitus')],
            ['slug' => 'sponsoreeritud-artikkel', 'label' => 'Sponsoreeritud artikkel', 'url' => self::url('sponsoreeritud-artikkel')],
            ['slug' => 'turg', 'label' => 'Publishers kataloog', 'url' => self::marketingUrl('marketplace')],
            ['slug' => 'linkbuilding', 'label' => 'Linkbuilding', 'url' => self::url('linkbuilding')],
            ['slug' => 'osta-backlinke', 'label' => 'Osta backlinke', 'url' => self::url('osta-backlinke')],
            ['slug' => 'hinnad', 'label' => 'Hinnad', 'url' => self::marketingUrl('pricing')],
            ['slug' => 'agentuurid', 'label' => 'Agentuuridele', 'url' => self::url('agentuurid')],
            ['slug' => 'digital-pr', 'label' => 'Digital PR', 'url' => self::url('digital-pr')],
            ['slug' => 'niche-edits', 'label' => 'Niche edits', 'url' => self::url('niche-edits')],
            ['slug' => 'juhend', 'label' => 'Juhend', 'url' => self::url('juhend')],
        ], $current);
    }

    /**
     * @return list<array{slug: string, label: string, url: string}>
     */
    public static function footerLinks(): array
    {
        return array_values(array_filter(
            self::clusterLinks('home'),
            static fn (array $item) => ! in_array($item['slug'], ['turg', 'hinnad'], true)
        ));
    }

    /**
     * @return array{h2: string, body: string, links: string}
     */
    public static function marketplaceCopy(): array
    {
        return [
            'h2' => 'Eesti publishers kataloog',
            'body' => 'Siin on avalik nimekiri publishers-test, kelle põhiriik on Eesti: nišš, keel, DA/DR ja eurohind. Me ei indekseeri iga filtrikombinatsiooni ning linnadel (Tallinn ja teised) ei ole oma URL-i. Täielik kataloog domeenidega avaneb pärast registreerimist.',
            'links' => '<a href="'.self::url('osta-kulalispostitus').'">Osta külalispostitus Eestis</a> · <a href="'.self::url('osta-backlinke').'">Osta backlinke</a> · <a href="'.self::marketingUrl('pricing').'">Külalispostituse hind</a> · <a href="'.url('/guest-posts-estonia').'">Estonia inventory (English)</a>',
        ];
    }

    /**
     * @return array{h2: string, body: string}
     */
    public static function pricingCopy(): array
    {
        return [
            'h2' => 'Kui palju maksab külalispostitus Eestis',
            'body' => 'Me ei avalda fikseeritud PDF-hinnakirja ega leiuta Eesti registrikoodi: hind on saidi oma, eurodes. «Külalispostituse hind», «linkbuilding hinnad» ja «backlinkide hind» järgivad kataloogiridu, mille teie valite. Ülal nummerdatud paketid on juhitud digital-PR kampaaniad, mitte kott anonüümseid URL-e. Kehtivad summad on <a href="'.self::marketingUrl('marketplace').'">Eesti kataloogis</a> pärast registreerimist. Peakontor on Londonis (Topurlz Ltd).',
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
        $guest = self::url('osta-kulalispostitus');
        $sponsored = self::url('sponsoreeritud-artikkel');
        $links = self::url('osta-backlinke');
        $lb = self::url('linkbuilding');
        $agencies = self::url('agentuurid');
        $pr = self::url('digital-pr');
        $niche = self::url('niche-edits');
        $guide = self::url('juhend');
        $publisher = self::marketingUrl('become-a-publisher');

        return [
            'osta-kulalispostitus' => [
                'kicker' => 'Külalispostitus backlinkiga',
                'h1' => 'Külalispostitus eesti blogides',
                'subtitle' => 'Valige kontrollitud eesti ja Euroopa publishers, võrrelge nišši, DA/DR-i ja eurohinda, saatke briefing ja jälgige live-URL-i tellimuses.',
                'meta_title' => 'Külalispostitus eesti blogides | SEOLinkBuildings',
                'meta_description' => 'Ostke külalispostitus eesti blogides ja .ee saitidel: filter nišš, DA/DR ja EUR-hind, dofollow või sponsored ning live-URL tellimuses.',
                'teaser_countries' => ['ee'],
                'teaser_title' => 'Saidid külalispostituseks Eestis',
                'teaser_subtitle' => 'Varjatud eelvaade aktiivsetest Eesti kataloogiridadest. Domeene näete pärast registreerimist.',
                'intro' => [
                    'SEOLinkBuildings on self-service turg, mitte läbipaistmatu külalispostituste pakett. Teie valite saidi — sageli .ee, sageli eesti keeles — maksate eurodes saldost ning hoiate briefingu, vestluse ja live-URL-i samas tellimuses. Peakontor on Londonis (Topurlz Ltd); me ei leiuta Eesti registrikoodi.',
                    '«Osta külalispostitus», «osta guest post» ja kohalik sünonüüm sponsorartikkel tähistavad sama kavatsust: tasuline publikatsioon saidil, mida teie ei oma, kirjalike reeglitega pikkuse, linkide ja tarne kohta.',
                ],
                'points' => [
                    [
                        'title' => 'Publishers, mitte fantoomnimekiri',
                        'body' => 'Iga rida on sait niši, keele, riigi, DA/DR-i, deklareeritud liikluse ja hinnaga. Me ei müü PBN-i ega «50 lingi pakette».',
                    ],
                    [
                        'title' => 'Kuidas tellida',
                        'body' => 'Teie loote konto, filtreerite Eesti, panete saidi ostukorvi ning saadate pealkirja, teksti või briefingu koos ankruga. Publisher toimetab live-URL-i kinnitamiseks.',
                    ],
                    [
                        'title' => 'Dofollow ja sponsored',
                        'body' => 'Lingi atribuut seisab kataloogireal. Paljud meediad märgivad tasulised publikatsioonid. Lugege lingitüüp enne tellimist — «dofollow iga hinna eest» ei ole olemas.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Liiklus, nišš ja püsivad lingid',
                        'body' => 'Külalispostitus «liiklusega» tähendab, et kataloogirida näitab publisheri deklareeritud liiklust — mitte külastuste garantiid. Nišše (tervis, finance, tech, kindlustus, kinnisvara, travel, ecommerce) filtreerite kataloogis, mitte eraldi URL-idel. «Püsiv» sõltub saidi reeglitest. Lugege kataloogirida.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kas saan avaldada ainult .ee saitidel?',
                        'a' => 'Jah. Teie filtreerite riigi Eesti. Euroopa kataloog on samas EUR-saldos. Soome ja Läti on eraldi filtrid.',
                    ],
                    [
                        'q' => 'Kas teie kirjutate artikli?',
                        'a' => 'Standardtellimus kasutab teie briefingu. Mõned kataloogiread pakuvad toimetust; seda näete saidil, mitte väljamõeldud lisateenusena siin.',
                    ],
                    [
                        'q' => 'Kas Tallinnal on oma leht?',
                        'a' => 'Ei. Linnadel ei ole oma URL-i. Teie filtreerite riiki.',
                    ],
                ],
                'cta_primary' => ['label' => 'Looge konto ja vaadake publishers', 'url' => $register],
                'cta_secondary' => ['label' => 'Publishers kataloog', 'url' => $market],
                'see_also' => [
                    ['label' => 'Osta backlinke', 'url' => $links],
                    ['label' => 'Sponsoreeritud artikkel', 'url' => $sponsored],
                    ['label' => 'Hinnad', 'url' => $prices],
                    ['label' => 'Juhend', 'url' => $guide],
                ],
            ],
            'sponsoreeritud-artikkel' => [
                'kicker' => 'Advertorial',
                'h1' => 'Ostke sponsoreeritud artikkel Eestis',
                'subtitle' => 'Teie ostate tasulise artikli kataloogi saidil, eurohinnaga ja live-URL-iga. Me ei müü üldist pressiteadete tellimust.',
                'meta_title' => 'Sponsoreeritud artikkel Eestis | SEOLinkBuildings',
                'meta_description' => 'Ostke sponsoreeritud artikkel või advertorial eesti saitidel. EUR-hind kataloogirea kaupa, briefing ja live-URL — ilma väljamõeldud pressibüroota.',
                'teaser_countries' => ['ee'],
                'teaser_title' => 'Meedia külalispostituseks ja advertorialiks',
                'teaser_subtitle' => 'Sama publishers eelvaade Eestis. Advertorial on olemas ainult siis, kui sait on kataloogis.',
                'intro' => [
                    '«Osta sponsoreeritud artikkel», «tasuline artikkel SEO» ja «advertorial» kirjeldavad tasulist publikatsiooni, mitte pressiliini, mida meil ei ole. Kui domeeni kataloogis ei ole, me seda ei müü.',
                    'Native advertising SEO jaoks on siin sama voog: teie valite publikatsiooni, maksate EUR-ides ja saate URL-i. Me ei luba Google News-i.',
                ],
                'points' => [
                    [
                        'title' => 'Sponsoreerituks märkimine',
                        'body' => 'Paljud saidid nõuavad rel sponsored või nähtavat märgistust. Järgige kataloogirea reeglit.',
                    ],
                    [
                        'title' => 'Hind',
                        'body' => 'Sponsoreeritud artikli hind sõltub saidist. Vaadake <a href="'.$prices.'">hindu</a> mudeli jaoks ja kataloogi kehtivate summade jaoks.',
                    ],
                    [
                        'title' => 'Sisu',
                        'body' => 'Teie saadate teksti või briefingu. Publisher avaldab oma saidil ja saadab live-URL-i.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Advertorial versus külalispostitus',
                        'body' => 'Praktikas on mõlemad tasuline publikatsioon lingiga. Erinevus on toimetuslik. Arveldus on sama. <a href="'.$guest.'">Osta külalispostitus</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kas advertorialil on üks tariif?',
                        'a' => 'Ei. Igal kataloogireal on oma hind, eurodes.',
                    ],
                    [
                        'q' => 'Kas paigutate kõikidesse ajalehtedesse?',
                        'a' => 'Ainult saitidele, mis on kataloogis ja võtavad tellimuse vastu.',
                    ],
                ],
                'cta_primary' => ['label' => 'Vaadake eesti saite', 'url' => $register],
                'cta_secondary' => ['label' => 'Digital PR', 'url' => $pr],
                'see_also' => [
                    ['label' => 'Digital PR', 'url' => $pr],
                    ['label' => 'Hinnad', 'url' => $prices],
                    ['label' => 'Osta külalispostitus', 'url' => $guest],
                ],
            ],
            'osta-backlinke' => [
                'kicker' => 'Toimetuslikud backlinkid',
                'h1' => 'Ostke backlinke Eestis',
                'subtitle' => 'Backlinkid on siin lingid publikatsioonidest, mida ostate päris saitidel — mitte anonüümne kott URL-e.',
                'meta_title' => 'Osta backlinke Eestis | SEOLinkBuildings',
                'meta_description' => 'Eesti backlingid külalispostitusest päris saitidel. EUR-hind, dofollow või sponsored kataloogireal, live-URL tellimuses.',
                'teaser_countries' => ['ee'],
                'teaser_title' => '.ee saidid backlinkideks',
                'teaser_subtitle' => 'Eelvaade kataloogiridadest, mille põhiriik on Eesti. Näidatud liiklus on deklareeritud, mitte lubatud.',
                'intro' => [
                    '«Osta backlinke», «osta backlink» ja «osta SEO linke» otsivad sama: linki avaldatud lehel. Meil saate selle külalispostituse või sponsoreeritud artikliga, ankruga briefingust.',
                    'Temaatilised backlingid tähendavad, et teie valite saidi niši. «Päris liiklusega» tähendab, et vaatate kataloogirea liiklust — me ei garanteeri külastusi.',
                ],
                'points' => [
                    [
                        'title' => 'Kvaliteet, mida saate lugeda',
                        'body' => 'Riik, keel, nišš, DA/DR ja hind seisavad real. Me ei müü «kvaliteetseid backlinke» sildina ilma saidita.',
                    ],
                    [
                        'title' => 'Dofollow ei ole standard',
                        'body' => 'Filtreerige lingitüübi järgi. Publisher vastutab elava HTML-i eest.',
                    ],
                    [
                        'title' => 'Ilma PBN-ita',
                        'body' => 'Me ei müü eravõrke. Riskid on <a href="'.$guide.'">juhendis</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Kuidas valida',
                        'body' => 'Alustage <a href="'.$market.'">kataloogist</a>, filtreerige Eesti, võrrelge hinda ja ankrureegleid. Seejärel <a href="'.$guest.'">ostke külalispostitus</a> või <a href="'.$sponsored.'">sponsoreeritud artikkel</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kas saan osta ainult dofollow-d?',
                        'a' => 'Saate filtreerida pakkumisi, mis mainivad dofollow-d. Kontrollige atribuuti elaval lehel.',
                    ],
                    [
                        'q' => 'Kas lisate lingi olemasolevasse artiklisse?',
                        'a' => 'Mitte niche edits SKU-na. Mõned saidid müüvad avalehe lisateenust tähtajaga.',
                    ],
                    [
                        'q' => 'Kui palju maksavad backlingid Eestis?',
                        'a' => 'See sõltub saidist. <a href="'.$prices.'">Hinnad</a> selgitavad mudelit; kataloog näitab kehtivaid summasid.',
                    ],
                ],
                'cta_primary' => ['label' => 'Võrrelge saite', 'url' => $register],
                'cta_secondary' => ['label' => 'Linkbuilding', 'url' => $lb],
                'see_also' => [
                    ['label' => 'Hinnad', 'url' => $prices],
                    ['label' => 'Osta külalispostitus', 'url' => $guest],
                    ['label' => 'Niche edits', 'url' => $niche],
                ],
            ],
            'linkbuilding' => [
                'kicker' => 'Kampaaniad',
                'h1' => 'Linkbuilding Eestis',
                'subtitle' => 'Teie ehitate kampaania kataloogist, publikatsioon publikatsiooni haaval, või valite juhitud digital-PR paketi hindade juurest. Pole «odavaid backlinke» ilma saidita.',
                'meta_title' => 'Linkbuilding Eestis | SEOLinkBuildings',
                'meta_description' => 'Linkbuilding Eesti: self-service kataloog EUR-ides, jälgitud tellimused ja digital-PR paketid. Ilma anonüümsete lingipakettideta.',
                'teaser_countries' => ['ee'],
                'teaser_title' => 'Valik kampaaniateks Eestis',
                'teaser_subtitle' => 'Samad kataloogiread mis külalispostituse lehel. Euroopat filtreerite pärast sisselogimist.',
                'intro' => [
                    'Linkbuilding tähendab siin, et teie valite publishers, maksate ja jälgite URL-i. See ei ole tellimus, mis «teeb SEO-d» teie eest.',
                    'Euroopa linkbuilding kasutab sama saldot. Eesti on riigifilter, mitte eraldi toode. Agentuur, kes töötab sisemiselt, võib kasutada sama kataloogi.',
                ],
                'points' => [
                    [
                        'title' => 'Self-service',
                        'body' => 'Strateegia on teie valik saitidest, ankrutest ja tempost. Allikas on kataloog. Igakuine linkbuilding on tempo, mille teie määrate.',
                    ],
                    [
                        'title' => 'Linkbuilding-paketid',
                        'body' => 'Nummerdatud paketid hindade juures on juhitud digital-PR kampaaniad, mitte kott URL-e.',
                    ],
                    [
                        'title' => 'White label',
                        'body' => 'Agentuur saab tellida oma kontolt. Me ei tarne portaali teie logoga. Üksikasjad: <a href="'.$agencies.'">agentuuridele</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Külalispostitus, niche edits ja digital PR',
                        'body' => 'Külalispostitus on uus artikkel. Niche edits-i (link olemasolevas sisus) me SKU-na ei müü. Digital PR on kampaania; turul maksate endiselt publikatsiooni.',
                    ],
                    [
                        'h2' => 'Kuidas kampaania algab',
                        'body' => 'Konto, EUR-saldo, filter Eesti, tellimus. Voog: <a href="'.$how.'">kuidas töötab</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kas teil on odav linkbuilding?',
                        'a' => 'Hind on kataloogirea oma. Meil ei ole eraldi «odavat» kihti kataloogi kõrval.',
                    ],
                    [
                        'q' => 'Kas teete ka strateegia?',
                        'a' => 'Juhend selgitab riske ja ankruid. Täitmine self-service-is on teie.',
                    ],
                ],
                'cta_primary' => ['label' => 'Avage kataloog', 'url' => $register],
                'cta_secondary' => ['label' => 'Vaadake hindu', 'url' => $prices],
                'see_also' => [
                    ['label' => 'Agentuuridele', 'url' => $agencies],
                    ['label' => 'Digital PR', 'url' => $pr],
                    ['label' => 'Juhend', 'url' => $guide],
                ],
            ],
            'agentuurid' => [
                'kicker' => 'B2B-konto',
                'h1' => 'Linkbuilding agentuuridele Eestis',
                'subtitle' => 'Self-service kataloog SEO-agentuuridele, resellers-itele ja edasiarveldavaile tiimidele. EUR-saldo, jälgitud tellimused, arved reklaamija arvelduses.',
                'meta_title' => 'Linkbuilding agentuuridele | SEOLinkBuildings',
                'meta_description' => 'Külalispostitus agentuuridele Eestis: kataloog EUR-ides, arved, tellimused brändi kaupa — ilma resellerportaalita teie logoga.',
                'teaser_countries' => ['ee'],
                'teaser_title' => 'Valik, mida saate edasi arveldada',
                'teaser_subtitle' => 'Samad kataloogiread mis sisemisele reklaamijale. Konto on teie; brändid on projektides ja tellimustes.',
                'intro' => [
                    '«Külalispostitus agentuuridele», «white label linkbuilding Eesti» ja «reseller backlinks» otsivad täitvat partnerit. Siin hoiab agentuur rooli: teie valite saidid, maksate ja annate live-URL-i kliendile.',
                    'Operatiivne white label tähendab, et lõppkliendil ei ole kontot vaja. See ei ole resellerprogramm teie brändiga avalikul lehel.',
                ],
                'points' => [
                    [
                        'title' => 'Üks saldo, mitu kampaaniat',
                        'body' => 'Teie lisate eurosid (kaart või ülekanne, kui meetod on aktiivne) ja jagate saldo tellimustele.',
                    ],
                    [
                        'title' => 'Tellimus ja arve',
                        'body' => 'Sissemaksete või tellimuste arved laadite reklaamija arveldusest, kui toode need väljastab. Äriandmed on briti (Topurlz Ltd). Me ei leiuta Eesti registrikoodi.',
                    ],
                    [
                        'title' => 'Töölaud SEO-tiimidele',
                        'body' => 'Filtrid, näitajad, vestlus tellimusel ja live-URL. Pärast sisselogimist jääb dashboard ingliskeelseks kõigile rollidele.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Agentuur versus turg',
                        'body' => 'Agentuur valib saidid kliendile. Turg näitab saite ostjale. SEOLinkBuildings on viimane. Kui teie tiim on agentuur, jääb valik teile ja kataloog on allikas.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kas saame turu kliendi eest peita?',
                        'a' => 'Jah, töötades oma kontolt. Me ei tarne white-label portaali teie brändiga.',
                    ],
                    [
                        'q' => 'Kas väljastate arveid Eesti käibemaksunumbriga?',
                        'a' => 'Arveldus järgib briti äriühingut. Laadige dokumendid alla ja täpsustage raamatupidamisega. Me ei leiuta registrikoodi ega käibemaksunumbrit.',
                    ],
                ],
                'cta_primary' => ['label' => 'Looge agentuuri konto', 'url' => $register],
                'cta_secondary' => ['label' => 'Kataloog', 'url' => $market],
                'see_also' => [
                    ['label' => 'Linkbuilding', 'url' => $lb],
                    ['label' => 'Hinnad', 'url' => $prices],
                    ['label' => 'Digital PR', 'url' => $pr],
                ],
            ],
            'digital-pr' => [
                'kicker' => 'PR digimeedias',
                'h1' => 'Digital PR Eestis',
                'subtitle' => 'Digital-PR kampaaniad publikatsioonidena turu saitidel, pluss juhitud paketid hindade juures. Ilma Google News lubaduseta.',
                'meta_title' => 'Digital PR Eestis | SEOLinkBuildings',
                'meta_description' => 'Digital PR Eesti: publikatsioonid kataloogist, EUR-saldo, live-URL ja juhitud paketid — ilma News- või pressigarantiita.',
                'teaser_countries' => ['ee'],
                'teaser_title' => 'Eesti saidid kataloogis',
                'teaser_subtitle' => 'Mõned publishers meenutavad mediakitti; kõik ei ole päevaleht. Nišši ja keelt filtreerite pärast sisselogimist.',
                'intro' => [
                    '«Digital PR Eesti», «osta pressiteade» ja «pressiteade SEO» segavad PR-i linkbuildinguga. Siin ostate publikatsioone saitidel, mis tegelikult kataloogis on.',
                    'Juhitud paketid (summad on Hindade juures; täna alates 499 €/kuus baasplaanil, kui see veel kuvatakse) on tiimi täitmine, mitte nupp «saa üleriigilisse lehte».',
                ],
                'points' => [
                    [
                        'title' => 'Meedia ainult siis, kui see on kataloogis',
                        'body' => 'Meil ei ole Google News kanalit. Külalispostitus «pressis» on olemas ainult siis, kui see sait on kataloogirida ja võtab briefingu vastu.',
                    ],
                    [
                        'title' => 'Brand mentions',
                        'body' => 'Mainimine võib tulla publikatsioonist. Me ei müü «brand mention» SKU-d ilma URL-ita.',
                    ],
                    [
                        'title' => 'Kampaaniad',
                        'body' => 'Self-service: teie valite saidid. Juhitud: paketid hindade juures.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'PR ja SEO, ilma paisutamiseta',
                        'body' => 'Kasulikul publikatsioonil on lugejad, kontekst ja link (või mainimine), millel on mõte. See ei asenda uudist. Kataloog: <a href="'.$market.'">saitide nimekiri</a>. Paketid: <a href="'.$prices.'">hinnad</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kas garanteerite artikli pressis?',
                        'a' => 'Ei. Me toimetame URL-i saidil, mille teie tellisite, kui publisher nõustub.',
                    ],
                    [
                        'q' => 'Kas see on midagi muud kui advertorial?',
                        'a' => 'Advertorial on publikatsioon. Digital PR on kampaania. Turul maksate endiselt publikatsiooni. <a href="'.$sponsored.'">Sponsoreeritud artikkel</a>.',
                    ],
                ],
                'cta_primary' => ['label' => 'Vaadake pakette ja kataloogi', 'url' => $prices],
                'cta_secondary' => ['label' => 'Registreeruge', 'url' => $register],
                'see_also' => [
                    ['label' => 'Sponsoreeritud artikkel', 'url' => $sponsored],
                    ['label' => 'Agentuuridele', 'url' => $agencies],
                    ['label' => 'Osta külalispostitus', 'url' => $guest],
                ],
            ],
            'niche-edits' => [
                'kicker' => 'Ei ole eraldi toode',
                'h1' => 'Niche edits Eestis — ja mida me müüme',
                'subtitle' => 'Niche edits (lisa link juba avaldatud artiklisse) ei ole SKU SEOLinkBuildingsis. Siin on piir külalispostituse suhtes, pluss riskid.',
                'meta_title' => 'Niche edits Eestis selgitatud | SEOLinkBuildings',
                'meta_description' => 'Mis on niche edits ja «lisa link artiklisse», millal see on riskantne ja miks müüme Eestis toimetuslikke publikatsioone — mitte sisestust võõrasse artiklisse.',
                'teaser_countries' => ['ee'],
                'teaser_title' => 'Toimetuslikud saidid, mitte sisestusvõrk',
                'teaser_subtitle' => 'Eelvaade aktiivsetest Eesti kataloogiridadest. Standardtoode on uus artikkel lingiga tekstis.',
                'intro' => [
                    'Niche edit on link juba avaldatud artiklis, sageli seetõttu, et URL on juba indekseeritud. «Lisa link artiklisse» otsib täpselt seda.',
                    'Me ei müü seda tootena. Standardtellimus on uus publikatsioon (külalispostitus või sponsoreeritud artikkel) briefingu ja live-URL-iga. Mõned saidid pakuvad avalehe lisateenust tähtajaga; see seisab kataloogireal.',
                ],
                'points' => [
                    [
                        'title' => 'Miks me seda ei müü',
                        'body' => 'Link artiklis, mida teie ei ole kirjutanud, on raskemini kontrollitav ja sagedamini vastuolus saidi enda reeglitega. Me ei taha lubada SKU-d, mida ei suuda ühtlaselt tarne.',
                    ],
                    [
                        'title' => 'Mida saate selle asemel osta',
                        'body' => '<a href="'.$guest.'">Külalispostitus</a> või <a href="'.$sponsored.'">sponsoreeritud artikkel</a> ankruga uues tekstis.',
                    ],
                    [
                        'title' => 'Risk',
                        'body' => 'Sisestused vanadesse artiklitesse võivad kaduda, atribuuti vahetada või tabada ebaolulisi ankruid. Lugege <a href="'.$guide.'">juhendit</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Kontekstuaalsed backlingid',
                        'body' => 'Kontekstlink uues külalispostituses on endiselt toimetuslik link. Erinevus on, et teie tunnete briefingu ja saate live-URL-i tellimuses. <a href="'.$links.'">Osta backlinke</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kas saan paluda publisheril lisada vana artiklisse?',
                        'a' => 'Ainult kui kataloogirida seda kirjeldab. See ei ole meie standard-SKU.',
                    ],
                ],
                'cta_primary' => ['label' => 'Ostke selle asemel külalispostitus', 'url' => $guest],
                'cta_secondary' => ['label' => 'Kataloog', 'url' => $market],
                'see_also' => [
                    ['label' => 'Linkbuilding', 'url' => $lb],
                    ['label' => 'Osta külalispostitus', 'url' => $guest],
                    ['label' => 'Sponsoreeritud artikkel', 'url' => $sponsored],
                ],
            ],
            'juhend' => [
                'kicker' => 'Üks juhend',
                'h1' => 'Juhend: külalispostitus ja linkbuilding',
                'subtitle' => 'Mis on külalispostitus, kuidas osta backlinke, dofollow versus nofollow, ankrud, PBN ja riskid — ühel lehel, mitte õhukestes artiklites.',
                'meta_title' => 'Juhend: külalispostitus | SEOLinkBuildings',
                'meta_description' => 'Lühike juhend: mis on külalispostitus, kuidas osta backlinke, dofollow vs nofollow, ankrud, rel sponsored ja miks PBN ei ole meie toode.',
                'teaser_countries' => ['ee'],
                'teaser_title' => 'Selgitusest kataloogini',
                'teaser_subtitle' => 'Pärast juhendit on päris saidid Eesti kataloogis, hinnaga publikatsiooni kohta.',
                'intro' => [
                    'See juhend katab informatiivseid otsinguid (mis on linkbuilding, kuidas osta backlinke, kas backlingid on seaduslikud, ankrutekst) ilma uue leheta iga lause jaoks.',
                    'Dashboard pärast sisselogimist jääb ingliskeelseks. Avalik leht on eesti keeles.',
                ],
                'points' => [
                    [
                        'title' => 'Mis on külalispostitus?',
                        'body' => 'Artikkel kellegi teise saidil, tavaliselt lingiga teile, tasu või vahetuse eest. Meil on makse EUR-ides, tellimuse kaupa.',
                    ],
                    [
                        'title' => 'Dofollow, nofollow, sponsored',
                        'body' => 'Dofollow annab tavaliselt signaali edasi. Nofollow ja sponsored ütlevad, et link on märgitud. Google käsitleb rel sponsored-it tasulise lingina. Valige see, mida kataloogirida näitab.',
                    ],
                    [
                        'title' => 'Ankrud',
                        'body' => 'Täpne ankur, korduv paljudel saitidel, on riskantne muster. Varierige sõnastust ja hoidke ankur sihtlehe suhtes asjakohane.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Kuidas osta backlinke',
                        'body' => 'Konto, saldo, riigi- ja nišifilter, briefing, live-URL-i kinnitus. Tegevus: <a href="'.$how.'">kuidas töötab</a>. Ärileht: <a href="'.$links.'">osta backlinke</a>.',
                    ],
                    [
                        'h2' => 'PBN versus külalispostitus',
                        'body' => 'PBN on võrk, mida teie juhite, et saata linke. Seda me ei müü. Külalispostitus on publikatsioon saidil, millel on oma lugejad. Kui teie ei saa saiti nimetada, ei ole see see toode.',
                    ],
                    [
                        'h2' => 'Backlinkide ostmise riskid',
                        'body' => 'Saidid ilma päris liikluseta, agressiivsed ankrud, kaduvad lingid, puuduv sponsored-märgis, paisutatud näitajad. Kontrollige kataloogirida ja live-URL-i. Me ei luba positsioone.',
                    ],
                    [
                        'h2' => 'Strateegia, lühidalt',
                        'body' => 'Vähe asjakohaseid publikatsioone lööb kontekstita linkide mahtu. Täitmiseks: <a href="'.$lb.'">linkbuilding</a>, <a href="'.$guest.'">osta külalispostitus</a>, <a href="'.$publisher.'">saa kirjastajaks</a>, kui müüte kohta.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kas see on 2026. aasta linkbuilding-juhend?',
                        'a' => 'Ei. See on tooteleht, mis selgitab turu praegusi reegleid, mitte trendikalender.',
                    ],
                    [
                        'q' => 'Kus ma näen hindu?',
                        'a' => 'Mudel on <a href="'.$prices.'">hindade</a> juures. Kehtivad summad on kataloogis pärast registreerimist.',
                    ],
                ],
                'cta_primary' => ['label' => 'Vaadake valikut', 'url' => $register],
                'cta_secondary' => ['label' => 'Osta külalispostitus', 'url' => $guest],
                'see_also' => [
                    ['label' => 'Linkbuilding', 'url' => $lb],
                    ['label' => 'Osta backlinke', 'url' => $links],
                    ['label' => 'Hinnad', 'url' => $prices],
                ],
            ],
        ];
    }
}
