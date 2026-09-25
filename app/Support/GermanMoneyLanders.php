<?php

namespace App\Support;

/**
 * German-only money / B2B marketing landers.
 * Not registered as shared LocalizedPublicPath keys — other locales 301 here
 * unless that locale already owns the same slug (e.g. Italian digital-pr).
 */
class GermanMoneyLanders
{
    public const LOCALE = 'de';

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_keys(self::pages());
    }

    /**
     * Research URL aliases → existing German canonicals (no extra indexable twins).
     *
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return [
            'guest-post-kaufen' => '/de/gastbeitrag-kaufen',
            'gastartikel-kaufen' => '/de/gastbeitrag-kaufen',
            'gastbeitrag-bestellen' => '/de/gastbeitrag-kaufen',
            'link-building' => '/de/linkbuilding',
            'linkaufbau' => '/de/linkbuilding',
            'backlink-kaufen' => '/de/backlinks-kaufen',
            'advertorial-kaufen' => '/de/advertorial',
            'preisliste' => '/de/preise',
            'fuer-agenturen' => '/de/agenturen',
            'white-label-linkbuilding' => '/de/agenturen',
            'was-ist-ein-gastbeitrag' => '/de/blog/was-ist-ein-gastbeitrag',
            'was-sind-backlinks' => '/de/blog/was-sind-backlinks',
            'linkaufbau-strategien' => '/de/blog/linkaufbau-strategien',
            'wie-funktioniert-linkbuilding' => '/de/blog/linkaufbau-strategien',
            'backlinks-aufbauen-anleitung' => '/de/blog/was-sind-backlinks',
            'dofollow-vs-nofollow' => '/de/blog/dofollow-vs-nofollow-ankertext',
            'rel-sponsored-bedeutung' => '/de/blog/dofollow-vs-nofollow-ankertext',
            'anchor-text-strategie' => '/de/blog/dofollow-vs-nofollow-ankertext',
            'google-guidelines-gastbeitrag' => '/de/blog/was-ist-ein-gastbeitrag',
            'spam-score-backlinks-pruefen' => '/de/blog/was-sind-backlinks',
        ];
    }

    /**
     * Previous German blog slugs → new research slugs (301, avoid 404s).
     *
     * @return array<string, string>
     */
    public static function legacyBlogSlugs(): array
    {
        return [
            'gastbeitraege-leitfaden-pitch-und-text' => 'was-ist-ein-gastbeitrag',
            'so-bekommen-sie-backlinks' => 'was-sind-backlinks',
            'linkbuilding-leitfaden-seo-strategie' => 'linkaufbau-strategien',
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
     * Germany owns /de copies. Austria owns /at and Switzerland owns /ch;
     * they are not German copy locales.
     *
     * @return list<string>
     */
    public static function copyRedirectLocales(): array
    {
        return ['de'];
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
        return url('/de/'.$slug);
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
            ['slug' => 'home', 'label' => 'Marktplatz Deutschland', 'url' => url('/de')],
            ['slug' => 'gastbeitrag-kaufen', 'label' => 'Gastbeitrag kaufen', 'url' => self::url('gastbeitrag-kaufen')],
            ['slug' => 'advertorial', 'label' => 'Advertorials', 'url' => self::url('advertorial')],
            ['slug' => 'marktplatz', 'label' => 'Publisher-Katalog', 'url' => url('/de/marktplatz')],
            ['slug' => 'linkbuilding', 'label' => 'Linkbuilding', 'url' => self::url('linkbuilding')],
            ['slug' => 'backlinks-kaufen', 'label' => 'Backlinks kaufen', 'url' => self::url('backlinks-kaufen')],
            ['slug' => 'preise', 'label' => 'Gastbeitrag-Preise', 'url' => url('/de/preise')],
            ['slug' => 'agenturen', 'label' => 'Für Agenturen', 'url' => self::url('agenturen')],
            ['slug' => 'digital-pr', 'label' => 'Digital PR', 'url' => self::url('digital-pr')],
            ['slug' => 'niche-edits', 'label' => 'Niche Edits', 'url' => self::url('niche-edits')],
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
        $marktplatz = '/de/marktplatz';
        $preise = '/de/preise';
        $how = '/de/so-funktioniert-es';
        $register = '/register';
        $guest = '/de/gastbeitrag-kaufen';
        $advertorial = '/de/advertorial';
        $links = '/de/backlinks-kaufen';
        $lb = '/de/linkbuilding';
        $agencies = '/de/agenturen';
        $pr = '/de/digital-pr';
        $niche = '/de/niche-edits';
        $blogGuest = '/de/blog/was-ist-ein-gastbeitrag';
        $blogLb = '/de/blog/linkaufbau-strategien';
        $blogBack = '/de/blog/was-sind-backlinks';
        $blogSponsored = '/de/blog/gesponserte-beitraege-leitfaden';
        $blogDofollow = '/de/blog/dofollow-vs-nofollow-ankertext';

        return [
            'gastbeitrag-kaufen' => [
                'kicker' => 'Gastbeitrag mit Backlink',
                'h1' => 'Gastbeiträge kaufen – relevante Publisher für Ihre SEO',
                'subtitle' => 'Gastbeitrag kaufen auf geprüften deutschen und europäischen Publisher-Seiten: Nische, DA/DR und Preis in Euro vergleichen, Briefing senden, Live-URL im Auftrag verfolgen.',
                'meta_title' => 'Gastbeitrag kaufen in Deutschland | SEOLinkBuildings',
                'meta_description' => 'Gastbeitrag kaufen bei geprüften Publishern. Filtern Sie Nische, DA/DR und EUR-Preis, bestellen Sie dofollow oder sponsored und verfolgen Sie die Live-URL.',
                'teaser_countries' => ['de'],
                'teaser_title' => 'Beispiel-Sites für Gastbeiträge in Deutschland',
                'teaser_subtitle' => 'Maskierte Vorschau aktiver Deutschland-Listings. Vollständige Domains sehen Sie nach der Registrierung.',
                'intro' => [
                    'SEOLinkBuildings ist ein Self-Service-Marktplatz, kein undurchsichtiges Gastbeitrag-Paket. Sie wählen die Seite, zahlen in Euro aus dem Wallet und halten Briefing, Chat und Live-URL in einem Auftrag.',
                    '„Gastartikel kaufen“, „Gastbeitrag bestellen“ und „Guest Post kaufen“ meinen dieselbe Absicht: eine bezahlte Veröffentlichung auf einer Site, die Sie nicht besitzen — mit geschriebenen Regeln zu Länge, Links, Themen und Lieferzeit.',
                ],
                'points' => [
                    [
                        'title' => 'Publisher, keine Phantom-Preisliste',
                        'body' => 'Jede Katalogzeile ist eine Website mit Nische, Sprache, Land, DA/DR, deklariertem Traffic und Checkout-Preis. Wir verkaufen keine PBNs und keine „50-Link-Pakete“.',
                    ],
                    [
                        'title' => 'So bestellen Sie',
                        'body' => 'Registrieren, Deutschland (oder andere Märkte) filtern, Site in den Warenkorb, Titel, Text oder Briefing plus Anker senden. Der Publisher liefert die Live-URL zur Freigabe.',
                    ],
                    [
                        'title' => 'Dofollow und Sponsored',
                        'body' => 'Das Link-Attribut steht auf dem Listing. Viele Sites kennzeichnen bezahlte Veröffentlichungen. Lesen Sie den Linktyp vor der Bestellung — kein „Dofollow um jeden Preis“.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Gastbeitrag mit Backlink bestellen',
                        'body' => 'Im deutschsprachigen Suchmarkt treffen „Gastbeitrag kaufen“, „Gastbeitrag Deutschland“ und „hochwertige Gastbeiträge“ dieselbe Kaufabsicht. Sie zahlen für eine Veröffentlichung mit den Regeln des Listings. Die Permanenz ist die, die der Publisher angibt — kein Ranking-Versprechen.',
                    ],
                    [
                        'h2' => 'Publisher auswählen',
                        'body' => 'Filtern Sie nach Land Deutschland, Sprache Deutsch, Nische (Finanzen, Gesundheit, Immobilien, E-Commerce, Tech, Fachportale) und Preisspanne. DA und DR helfen beim Aussortieren, entscheiden aber nicht allein. Der Live-Katalog öffnet sich nach der Anmeldung und bleibt im Dashboard auf Englisch — Preise bleiben in EUR.',
                    ],
                    [
                        'h2' => 'Pakete, Dauerhaftigkeit, Nischen',
                        'body' => 'Es gibt kein festes „günstiges Gastbeitrag-Paket“. Der Preis folgt der Site. Für Volumen und White Label: <a href="'.$agencies.'">Gastbeiträge für Agenturen</a>. Abgrenzung zum Media-Kit-Vokabular: <a href="'.$advertorial.'">Advertorial kaufen</a>. Grundlagen: <a href="'.$blogGuest.'">Was ist ein Gastbeitrag?</a>',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kann ich nur deutsche Gastbeiträge kaufen?',
                        'a' => 'Ja. Im Katalog filtern Sie nach Land Deutschland. Dasselbe EUR-Wallet gilt für andere europäische Märkte, wenn Sie sie brauchen.',
                    ],
                    [
                        'q' => 'Ist der Link immer dofollow?',
                        'a' => 'Nein. Das hängt vom Listing ab. Prüfen Sie den Linktyp und nach Live-Gang das rel-Attribut. Vertiefung: Dofollow vs. Nofollow.',
                    ],
                    [
                        'q' => 'Wie sicher bestelle ich?',
                        'a' => 'Sites mit klaren Regeln wählen, in EUR aus dem Wallet zahlen, Briefing am Auftrag halten und die Live-URL freigeben. Kein „garantiertes Ranking“ kaufen.',
                    ],
                    [
                        'q' => 'Gibt es eine Rechnung?',
                        'a' => 'Advertiser können Rechnungen zu Wallet-Belastungen und Aufträgen in der Rechnungsübersicht herunterladen, soweit das Produkt sie ausstellt. Details unter Für Agenturen.',
                    ],
                ],
                'cta_primary' => ['label' => 'Konto anlegen und Katalog öffnen', 'url' => $register],
                'cta_secondary' => ['label' => 'So funktioniert die Bestellung', 'url' => $how],
                'see_also' => [
                    ['label' => 'Publisher-Katalog', 'url' => $marktplatz],
                    ['label' => 'Was kostet ein Gastbeitrag', 'url' => $preise],
                    ['label' => 'Was ist ein Gastbeitrag', 'url' => $blogGuest],
                ],
            ],
            'advertorial' => [
                'kicker' => 'Gesponserter Artikel',
                'h1' => 'Advertorial kaufen – gesponserte Artikel auf Publisher-Sites',
                'subtitle' => 'Advertorial, gesponserter Artikel oder Native-Advertising-Text: derselbe Marktplatz wie Gastbeiträge, mit EUR-Preis, Briefing und Live-URL — plus der korrekten Kennzeichnung als bezahlter Inhalt.',
                'meta_title' => 'Advertorial kaufen in Deutschland | SEOLinkBuildings',
                'meta_description' => 'Advertorial und gesponserten Artikel kaufen: Publisher filtern, in EUR zahlen, Briefing und Live-URL am Auftrag — ohne erfundene Native-Ad-Produkte.',
                'teaser_countries' => ['de'],
                'teaser_title' => 'Beispiel-Sites für bezahlte Veröffentlichungen',
                'teaser_subtitle' => 'Derselbe Katalog wie bei Gastbeiträgen. Hier zählt die kommerzielle Sprache: Advertorial, Sponsored Content, Werbeartikel.',
                'intro' => [
                    '„Advertorial kaufen“, „Artikel sponsorieren“ und „gesponserter Artikel“ beschreiben eine bezahlte Veröffentlichung auf einer Dritt-Site. Bei SEOLinkBuildings ist das kein separates SKU: es ist dieselbe Katalogbestellung, mit Disclosure und Link-Attribut laut Listing.',
                    'Wir verkaufen kein Ranking. Wir verkaufen eine Veröffentlichung mit sichtbaren Regeln, Checkout in Euro und nachvollziehbarer Lieferung.',
                ],
                'points' => [
                    [
                        'title' => 'Derselbe Katalog, anderes Vokabular',
                        'body' => 'Filtern Sie Nische, Sprache und Preis. Das Media-Kit des Publishers steht auf dem Listing: akzeptierte Themen, Linkanzahl, Fristen.',
                    ],
                    [
                        'title' => 'Advertorial vs. Gastbeitrag',
                        'body' => 'Wenn Sie dafür zahlen, auf der Seite zu erscheinen, behandeln Sie es als gesponsert — auch wenn die Rechnung „Gastbeitrag“ sagt. Google erwartet eine Qualifizierung (rel sponsored oder nofollow) bei bezahlten Platzierungen.',
                    ],
                    [
                        'title' => 'Redaktioneller Inhalt, kein Display-Banner',
                        'body' => 'Sie liefern Text oder Briefing. Der Publisher veröffentlicht in seinem CMS. Das ist kein Display-Werbemittel und keine Markenerwähnung in einer Tageszeitung, wenn die Site das nicht ist.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Artikel gegen Backlink platzieren',
                        'body' => 'Registrieren, Sites wählen, aus dem Wallet zahlen, Material senden. Der Status bleibt am Auftrag bis zur Live-URL. Ablauf: <a href="'.$how.'">So funktioniert es</a>. Wenn Sie den Suchbegriff Gastbeitrag nutzen: <a href="'.$guest.'">Gastbeitrag kaufen</a>.',
                    ],
                    [
                        'h2' => 'Werbeartikel, Native Advertising, Presseportal',
                        'body' => 'Nutzen Sie diese Labels im Briefing, wenn der Publisher sie verlangt. Der Marktplatz ändert kein SKU — nur die Beschreibung der Platzierung. Eine Presseportal-Platzierung gibt es nur, wenn diese Domain im Katalog steht. Vergleich: <a href="'.$blogSponsored.'">Gesponserte Beiträge</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Ist das im Katalog ein anderes Produkt als ein Gastbeitrag?',
                        'a' => 'Nein als Produkt. Ja als Label und in der Frage, wie der bezahlte Link deklariert wird.',
                    ],
                    [
                        'q' => 'Kann ich Dofollow auf einem Advertorial verlangen?',
                        'a' => 'Nur wenn das Listing das zulässt. Viele Publisher kennzeichnen bezahlte Veröffentlichungen. Das ist die Policy der Site, kein Fehler des Marktplatzes.',
                    ],
                    [
                        'q' => 'Bieten Sie Platzierungen auf Nachrichtenportalen?',
                        'a' => 'Nur wenn diese Domain im Katalog ist. Wir versprechen kein Google News. Siehe auch Digital PR.',
                    ],
                ],
                'cta_primary' => ['label' => 'Katalog nach Registrierung öffnen', 'url' => $register],
                'cta_secondary' => ['label' => 'Gastbeitrag kaufen', 'url' => $guest],
                'see_also' => [
                    ['label' => 'Katalog', 'url' => $marktplatz],
                    ['label' => 'Digital PR', 'url' => $pr],
                    ['label' => 'Dofollow vs. Nofollow', 'url' => $blogDofollow],
                ],
            ],
            'linkbuilding' => [
                'kicker' => 'Plattform, kein geschlossenes Paket',
                'h1' => 'Linkaufbau und Linkbuilding über den Marktplatz',
                'subtitle' => 'Manueller Linkaufbau self-service: Veröffentlichungen wählen, in Euro zahlen, Live-URLs verfolgen. Kein garantiertes Ranking, keine mysteriösen Linkbuilding-Pakete.',
                'meta_title' => 'Linkaufbau und Linkbuilding | SEOLinkBuildings',
                'meta_description' => 'Linkaufbau in Deutschland: Self-Service-Katalog, DA/DR und Preise in EUR, nachverfolgte Veröffentlichungen. Kein undurchsichtiges Linkbuilding-Paket.',
                'teaser_countries' => ['de'],
                'teaser_title' => 'Inventar für Linkbuilding-Kampagnen',
                'teaser_subtitle' => 'Beispiel Deutschland-Sites. Für E-Commerce oder andere Nischen filtern Sie nach der Anmeldung — keine eigene Seite pro Branche.',
                'intro' => [
                    '„Linkaufbau-Paket“, „monatliches Linkbuilding“ und „Linkaufbau-Dienstleistung“ sind in deutschen SERPs oft Agenturseiten. SEOLinkBuildings ist eine Plattform: Sie bauen die Kampagne aus dem Katalog, mit einem Wallet für mehrere Märkte.',
                    'White Label oder Rechnungen für mehrere Brands: <a href="'.$agencies.'">Linkbuilding für Agenturen</a>.',
                ],
                'points' => [
                    [
                        'title' => 'Self-Service statt PDF',
                        'body' => 'Sie sehen Site, Checkout-Preis und Metriken vor der Zahlung. Sie kaufen kein „Paket mit 20 DR50“ ohne Namen.',
                    ],
                    [
                        'title' => 'Linkbuilding-Kosten',
                        'body' => 'Die Kosten sind die Summe der gewählten Veröffentlichungen plus optionale gemanagte Digital-PR-Pakete in der Preisliste. Detail: <a href="'.$preise.'">Was kostet ein Gastbeitrag</a>.',
                    ],
                    [
                        'title' => 'Manuell, Gastbeitrag, PR, Niche Edit',
                        'body' => 'Manueller Linkaufbau hier heißt: Sie wählen Publisher. Gastbeiträge und Advertorials sind Katalogbestellungen. Digital PR kann self-service oder gemanagt sein. Niche Edits sind kein SKU — Erklärung: <a href="'.$niche.'">Niche Edits</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Linkaufbau-Strategien auf einer Plattform',
                        'body' => 'Vergleichen Sie Agenturen und andere Marktplätze an prüfbaren Fakten: EUR-Wallet, URL am Auftrag, filterbarer Katalog, UK-Gesellschaft. Wir behaupten nicht, die beste Linkbuilding-Agentur Deutschlands zu sein. Strategie-Leitfaden: <a href="'.$blogLb.'">Linkaufbau-Strategien</a>.',
                    ],
                    [
                        'h2' => 'Kampagne aufsetzen',
                        'body' => 'Ziel-URLs festlegen, Sites filtern, Anker variieren, ein tragfähiges Tempo halten. Einkauf: <a href="'.$links.'">Backlinks kaufen</a>. Natürliche Backlinks entstehen nicht durch ein Button-Klick — bezahlte Platzierungen entsprechend kennzeichnen.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Verkaufen Sie Linkbuilding-Pakete?',
                        'a' => 'Nicht als undurchsichtiges SKU. Sie kaufen einzelne Veröffentlichungen. Die nummerierten Pakete auf der Preisseite sind gemanagte Digital-PR-Kampagnen, kein Sack anonymer URLs.',
                    ],
                    [
                        'q' => 'Ist das White-Hat-Linkbuilding?',
                        'a' => 'Der Katalog ist redaktionelles Inventar mit Regeln. Google behandelt gekaufte Links zur Ranking-Manipulation als Spam, wenn sie nicht qualifiziert sind. Bezahlte Veröffentlichungen entsprechend behandeln.',
                    ],
                    [
                        'q' => 'Brauche ich eine Agentur?',
                        'a' => 'Nein, um den Katalog zu nutzen. Ja, wenn jemand die Sites für Sie auswählen soll — oder Sie nutzen das Agentur-Konto und bleiben Entscheider.',
                    ],
                ],
                'cta_primary' => ['label' => 'Mit dem Katalog starten', 'url' => $register],
                'cta_secondary' => ['label' => 'Preise und Katalog', 'url' => $preise],
                'see_also' => [
                    ['label' => 'Gastbeitrag kaufen', 'url' => $guest],
                    ['label' => 'Linkaufbau-Strategien', 'url' => $blogLb],
                    ['label' => 'Für Agenturen', 'url' => $agencies],
                ],
            ],
            'backlinks-kaufen' => [
                'kicker' => 'Redaktionelle Backlinks',
                'h1' => 'Backlinks kaufen – thematisch passende Publisher',
                'subtitle' => 'Deutsche Backlinks und SEO-Backlinks aus dem Katalog: Preise in Euro, DA/DR, deklariertes Attribut. Kein PBN, kein garantiertes Ranking.',
                'meta_title' => 'Backlinks kaufen in Deutschland | SEOLinkBuildings',
                'meta_description' => 'Backlinks kaufen bei deutschen und europäischen Publishern. Dofollow, Nische und EUR-Preis vergleichen, Live-URL am Auftrag verfolgen — ohne Ranking-Garantie.',
                'teaser_countries' => ['de'],
                'teaser_title' => 'Sites für Backlinks in Deutschland (Vorschau)',
                'teaser_subtitle' => 'Hosts bleiben maskiert, bis Sie ein Konto haben. „Qualitäts-Backlinks“ heißt hier filterbar — keine objektive Rangliste.',
                'intro' => [
                    '„Backlink kaufen“, „Backlinks kaufen Deutschland“ und „themerelevante Backlinks kaufen“ sind dieselbe kommerzielle Frage. Bei SEOLinkBuildings entsteht der Backlink durch eine Veröffentlichung auf der Publisher-Site, nicht durch ein Netz abgelaufener Domains.',
                    'Google behandelt gekaufte Links zur Ranking-Manipulation als Link-Spam, wenn sie nicht qualifiziert sind. Bezahlte Veröffentlichungen als sponsored/nofollow behandeln, wenn die Site das verlangt.',
                ],
                'points' => [
                    [
                        'title' => 'Link im Inhalt, nicht im Footer',
                        'body' => 'Das Briefing fordert den Link im Artikelkörper. Wir verkaufen kein Sitewide und keine SEO-Verzeichnisse.',
                    ],
                    [
                        'title' => 'Dofollow nur laut Listing',
                        'body' => 'Filtern Sie den Linktyp nach der Anmeldung. „Dofollow Backlinks kaufen“ ersetzt nicht Nische, Sprache und Publikum.',
                    ],
                    [
                        'title' => 'Kein Niche-Edit-SKU',
                        'body' => 'Wir setzen keinen Link in einen bereits rankenden Fremdartikel, außer der Publisher bietet ein Homepage-Extra auf dem Listing. Erklärung: <a href="'.$niche.'">Niche Edits</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Deutsche Backlinks und .de-Publisher',
                        'body' => 'Filtern Sie Land Deutschland. Die öffentliche Vorschau maskiert Domains. Katalog: <a href="'.$marktplatz.'">beste Seiten für Gastbeiträge</a>. Auch ohne Kauf: <a href="'.$blogBack.'">Was sind Backlinks?</a>.',
                    ],
                    [
                        'h2' => 'Qualität statt Versprechen',
                        'body' => 'Relevante Themen, redaktioneller Kontext, Publisher-Qualität, Publikum, Inhalt, Platzierung, transparente Anker, das Gesamtprofil — das sind die Faktoren, die Sie prüfen können. Ein einzelner Backlink „verbessert Rankings“ nicht automatisch. Nach Live-Gang: <a href="'.$blogDofollow.'">Dofollow vs. Nofollow und rel sponsored</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kann ich nur Dofollow kaufen?',
                        'a' => 'Sie können Angebote filtern, die das deklarieren. Der Publisher bleibt für das Live-HTML verantwortlich.',
                    ],
                    [
                        'q' => 'Machen Sie Link-Inserts in bestehende Artikel?',
                        'a' => 'Nicht als Niche-Edit-SKU. Manche Sites verkaufen ein zeitlich begrenztes Homepage-Extra. Das ist nicht dasselbe wie ein Link in einem bereits platzierten Stück.',
                    ],
                    [
                        'q' => 'Was kosten Backlinks?',
                        'a' => 'Hängt von der Site ab. Die Preisseite erklärt das Modell (pro Veröffentlichung, in EUR), der Katalog die Live-Preise.',
                    ],
                ],
                'cta_primary' => ['label' => 'Sites im Katalog vergleichen', 'url' => $register],
                'cta_secondary' => ['label' => 'Linkbuilding Deutschland', 'url' => $lb],
                'see_also' => [
                    ['label' => 'Preise', 'url' => $preise],
                    ['label' => 'Gastbeitrag kaufen', 'url' => $guest],
                    ['label' => 'Was sind Backlinks', 'url' => $blogBack],
                ],
            ],
            'agenturen' => [
                'kicker' => 'B2B-Konto',
                'h1' => 'Linkbuilding für Agenturen und White Label',
                'subtitle' => 'Self-Service-Katalog für SEO-Agenturen, Reseller und Teams, die weiterberechnen. EUR-Wallet, nachverfolgte Aufträge, Rechnungen in der Rechnungsübersicht, soweit das Produkt sie ausstellt.',
                'meta_title' => 'Linkbuilding für Agenturen | SEOLinkBuildings',
                'meta_description' => 'White-Label-Linkbuilding und Gastbeiträge für Agenturen: EUR-Katalog, Rechnungen, Aufträge pro Brand — ohne undurchsichtige Pakete und ohne gebrandetes Reseller-Frontend.',
                'teaser_countries' => ['de'],
                'teaser_title' => 'Inventar, das Sie weiterberechnen können',
                'teaser_subtitle' => 'Dieselben Listings wie interne Advertiser. Sie bleiben das Konto; Brands liegen in Ihren Projekten und Aufträgen.',
                'intro' => [
                    'Suchen nach „Linkbuilding-Agentur“, „SEO-Agentur Linkaufbau“ und „Offpage-SEO-Dienstleister“ wollen oft einen Lieferanten, der ausführt. Hier bleibt die Agentur in Kontrolle: Sites wählen, zahlen, Live-URL an den Kunden liefern.',
                    'Operatives White Label heißt: Der Endkunde muss kein Marktplatz-Konto anlegen. Es ist kein Reseller-Programm mit Ihrem Logo auf der öffentlichen Website.',
                ],
                'points' => [
                    [
                        'title' => 'Ein Wallet, mehrere Kampagnen',
                        'body' => 'Sie laden in Euro auf (Karte oder Überweisung, soweit aktiv) und verteilen das Guthaben auf Aufträge. Keine neue Rechnung je Site, außer Sie wollen das intern.',
                    ],
                    [
                        'title' => 'Auftrag und Rechnung',
                        'body' => 'Rechnungen zu Wallet-/Auftragsbelastungen laden Sie in der Rechnungsübersicht des Advertisers herunter, wenn das Produkt sie erzeugt. Firmendaten UK (Topurlz Ltd) unter Über uns.',
                    ],
                    [
                        'title' => 'Plattform für SEO-Teams',
                        'body' => 'Filter, Metriken, Auftrags-Chat und Live-URL. Das Dashboard nach der Anmeldung ist für alle Rollen auf Englisch.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Agentur vs. Marktplatz',
                        'body' => 'Eine Agentur entscheidet die Sites für den Kunden. Ein Marktplatz zeigt die Sites dem Käufer. SEOLinkBuildings ist das Zweite. Wenn Sie die Agentur sind, legen Sie das Erste über das Zweite.',
                    ],
                    [
                        'h2' => 'Onboarding',
                        'body' => 'Advertiser-Konto anlegen, aufladen, filtern, bestellen. Support: Kontaktseite. Ablauf: <a href="'.$how.'">So funktioniert es</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kann ich den Marktplatz vor dem Kunden verbergen?',
                        'a' => 'Ja, indem Sie das Konto selbst bedienen. Wir liefern kein gebrandetes White-Label-Portal.',
                    ],
                    [
                        'q' => 'Stellen Sie deutsche E-Rechnungen aus?',
                        'a' => 'Die Rechnungsstellung folgt der UK-Gesellschaft des Produkts. Laden Sie die Belege aus Wallet/Rechnungen und klären Sie mit Ihrer Buchhaltung, ob Sie weitere Integrationen brauchen.',
                    ],
                    [
                        'q' => 'Gibt es eine separate Agentur-Preisliste?',
                        'a' => 'Der Checkout-Preis ist der des Listings. Es gibt keinen zweiten „Agentur-Katalog“.',
                    ],
                ],
                'cta_primary' => ['label' => 'Agentur-Konto anlegen', 'url' => $register],
                'cta_secondary' => ['label' => 'Publisher-Katalog', 'url' => $marktplatz],
                'see_also' => [
                    ['label' => 'Linkbuilding', 'url' => $lb],
                    ['label' => 'Preise', 'url' => $preise],
                    ['label' => 'Digital PR', 'url' => $pr],
                ],
            ],
            'digital-pr' => [
                'kicker' => 'Online-Pressearbeit',
                'h1' => 'Digital PR Deutschland: Veröffentlichungen und Erwähnungen',
                'subtitle' => 'Digital-PR-Kampagnen als Veröffentlichungen auf Sites im Marktplatz, plus gemanagte Pakete in der Preisliste. Kein Google-News-Versprechen, keine erfundenen Brand Mentions.',
                'meta_title' => 'Digital PR Deutschland | SEOLinkBuildings',
                'meta_description' => 'Digital PR und Online-Pressearbeit: Veröffentlichungen aus dem SEOLinkBuildings-Katalog, EUR-Wallet, Live-URL, gemanagte Pakete — ohne News-Garantien.',
                'teaser_countries' => ['de'],
                'teaser_title' => 'Deutschland-Sites im Katalog (Vorschau)',
                'teaser_subtitle' => 'Manche Publisher ähneln einem Media-Kit; nicht alle sind Nachrichtenportale. Nische und Sprache filtern Sie nach der Anmeldung.',
                'intro' => [
                    '„Digital PR Deutschland“, „Online-Pressearbeit“ und „Brand Mention aufbauen“ mischen PR und Linkbuilding. Hier kaufen Sie Veröffentlichungen auf Sites, die wirklich im Katalog stehen. Fehlt eine Domain, verkaufen wir sie nicht.',
                    'Gemanagte Digital-PR-Pakete (Beträge auf der Preisseite, aktuell ab 499 €/Monat für den Basisplan, sofern noch gelistet) sind Outreach durch das Team, kein Button „erscheinen Sie in einer überregionalen Zeitung“.',
                ],
                'points' => [
                    [
                        'title' => 'Medien nur wenn sie im Katalog sind',
                        'body' => 'Wir haben keinen Google-News-Kanal. Ein „News“-Gastbeitrag existiert nur, wenn diese Site ein Listing ist und das Briefing akzeptiert.',
                    ],
                    [
                        'title' => 'Brand Mentions',
                        'body' => 'Eine Erwähnung kann aus einer Veröffentlichung entstehen. Wir verkaufen „Brand Mention kaufen“ nicht als SKU ohne URL.',
                    ],
                    [
                        'title' => 'Kampagnen, kein Blindflug-Pressetext',
                        'body' => 'Self-Service: Sites wählen. Gemanagt: Vertrieb über die Pakete auf der Preisseite.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'PR und SEO, ohne Übertreibung',
                        'body' => 'Eine nützliche Veröffentlichung hat Leser, Kontext und einen Link (oder eine Erwähnung), der Sinn ergibt. Sie ersetzt keine echte Nachricht. Katalog: <a href="'.$marktplatz.'">Publisher-Liste</a>. Pakete: <a href="'.$preise.'">Preise</a>.',
                    ],
                    [
                        'h2' => 'Gastbeitrag vs. Digital PR',
                        'body' => 'Der Gastbeitrag ist ein Artikel auf der Host-Site. Digital PR zielt auf eine Geschichte, die ein Redakteur von allein wollen würde. Auf dem Marktplatz zahlen Sie trotzdem die Veröffentlichung: als gesponsert behandeln, wenn ein Entgelt fließt. <a href="'.$advertorial.'">Advertorials</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Publizieren Sie bei Google News?',
                        'a' => 'Nein als Produkt. Steht eine Katalog-Site in News, hängt das von Google und dem Publisher ab, nicht von uns.',
                    ],
                    [
                        'q' => 'Kann ich nur eine Erwähnung ohne Artikel kaufen?',
                        'a' => 'Nur wenn ein Listing das anbietet. Standard ist ein Artikel mit Link im Körper.',
                    ],
                    [
                        'q' => 'Was kostet eine Kampagne?',
                        'a' => 'Self-Service: Summe der Listings. Gemanagt: die Pakete auf der Preisseite (EUR-Beträge laut aktueller Preisliste).',
                    ],
                ],
                'cta_primary' => ['label' => 'Pakete und Katalog ansehen', 'url' => $preise],
                'cta_secondary' => ['label' => 'Registrieren', 'url' => $register],
                'see_also' => [
                    ['label' => 'Advertorials', 'url' => $advertorial],
                    ['label' => 'Für Agenturen', 'url' => $agencies],
                    ['label' => 'Gastbeitrag kaufen', 'url' => $guest],
                ],
            ],
            'niche-edits' => [
                'kicker' => 'Kein separates Produkt',
                'h1' => 'Niche Edits erklärt — und was wir stattdessen verkaufen',
                'subtitle' => 'Niche Edits (Link-Inserts in bestehende Artikel) sind auf SEOLinkBuildings kein SKU. Hier die Abgrenzung zu Gastbeiträgen, Homepage-Extras und den Risiken.',
                'meta_title' => 'Niche Edits erklärt | SEOLinkBuildings',
                'meta_description' => 'Was Niche Edits und Link-Inserts sind, wann sie riskant sind, und warum SEOLinkBuildings redaktionelle Veröffentlichungen verkauft — kein Insert in fremde Rankings.',
                'teaser_countries' => ['de'],
                'teaser_title' => 'Redaktionelle Sites statt Insert-Netze',
                'teaser_subtitle' => 'Vorschau aktiver Deutschland-Listings. Das Standardprodukt ist ein neuer Artikel mit Link im Körper, kein heimliches Einfügen.',
                'intro' => [
                    'Ein Niche Edit ist ein Link, der in einen bereits veröffentlichten Artikel gesetzt wird — oft weil die URL schon indexiert oder sichtbar ist. „Link Insert kaufen“ sucht genau das.',
                    'SEOLinkBuildings verkauft das nicht als Produkt. Der Standardauftrag ist eine neue Veröffentlichung (Gastbeitrag oder Advertorial) mit Briefing und Live-URL. Manche Publisher bieten ein zeitlich begrenztes Homepage-Extra; das ist sichtbar auf dem Listing, kein stiller Insert in einen Rankings-Artikel.',
                ],
                'points' => [
                    [
                        'title' => 'Relevanz prüfen',
                        'body' => 'Ein Insert in thematisch fremden Alt-Content ist oft schlechter als ein neuer Artikel auf einer passenden Site. Themenfit schlägt „die URL rankt schon“.',
                    ],
                    [
                        'title' => 'Risiken',
                        'body' => 'Unklare Ownership, nachträglich geänderte Anker, fehlende Disclosure, Insert-Netze mit identischen Outbound-Mustern. Das sind Qualitäts- und Compliance-Fragen, keine Ranking-Garantie.',
                    ],
                    [
                        'title' => 'Was Sie hier kaufen',
                        'body' => 'Eine Veröffentlichung mit Regeln, EUR-Checkout und nachverfolgter Live-URL. <a href="'.$guest.'">Gastbeitrag kaufen</a> oder <a href="'.$links.'">Backlinks kaufen</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Wann Niche Edits überhaupt sinnvoll wären',
                        'body' => 'Nur wenn der bestehende Artikel thematisch passt, der Publisher den Insert redaktionell verantwortet und der Link transparent bleibt. Wir orchestrieren das nicht als Massen-SKU.',
                    ],
                    [
                        'h2' => 'Homepage-Extra ist etwas anderes',
                        'body' => 'Steht ein Homepage-Placement auf dem Listing, ist das eine sichtbare, oft zeitlich begrenzte Zusatzleistung — nicht dasselbe wie ein Link in einem bereits platzierten Fachartikel.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Kann ich trotzdem einen Link in einen bestehenden Artikel bestellen?',
                        'a' => 'Nur wenn ein konkretes Listing das ausdrücklich anbietet. Der Default ist ein neuer Artikel.',
                    ],
                    [
                        'q' => 'Warum keine Niche-Edit-Landing als Verkaufsseite?',
                        'a' => 'Weil wir das Produkt nicht haben. Eine Verkaufsseite dafür wäre irreführend.',
                    ],
                    [
                        'q' => 'Was ist die Alternative?',
                        'a' => 'Thematisch passende Gastbeiträge oder Advertorials aus dem Katalog, mit Briefing und Live-URL.',
                    ],
                ],
                'cta_primary' => ['label' => 'Gastbeiträge im Katalog wählen', 'url' => $register],
                'cta_secondary' => ['label' => 'Backlinks kaufen', 'url' => $links],
                'see_also' => [
                    ['label' => 'Linkbuilding', 'url' => $lb],
                    ['label' => 'Gastbeitrag kaufen', 'url' => $guest],
                    ['label' => 'Was sind Backlinks', 'url' => $blogBack],
                ],
            ],
        ];
    }
}
