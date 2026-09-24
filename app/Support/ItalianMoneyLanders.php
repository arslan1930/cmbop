<?php

namespace App\Support;

/**
 * Italian-only money / B2B marketing landers.
 * Not registered as shared LocalizedPublicPath keys — other locales 301 here.
 */
class ItalianMoneyLanders
{
    public const LOCALE = 'it';

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_keys(self::pages());
    }

    /**
     * Research URL aliases → existing Italian canonicals (no extra indexable twins).
     *
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return [
            'catalogo' => '/it/mercato',
            'prezzi-guest-post' => '/it/prezzi',
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
    public static function publicSegments(): array
    {
        return array_values(array_unique(array_merge(
            self::slugs(),
            array_keys(self::aliases())
        )));
    }

    public static function url(string $slug): string
    {
        return url('/it/'.$slug);
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
            ['slug' => 'home', 'label' => 'Marketplace Italia', 'url' => url('/it')],
            ['slug' => 'comprare-guest-post', 'label' => 'Acquistare guest post', 'url' => self::url('comprare-guest-post')],
            ['slug' => 'articoli-sponsorizzati', 'label' => 'Articoli sponsorizzati', 'url' => self::url('articoli-sponsorizzati')],
            ['slug' => 'mercato', 'label' => 'Catalogo siti', 'url' => url('/it/mercato')],
            ['slug' => 'link-building', 'label' => 'Link building', 'url' => self::url('link-building')],
            ['slug' => 'comprare-backlink', 'label' => 'Acquistare backlink', 'url' => self::url('comprare-backlink')],
            ['slug' => 'prezzi', 'label' => 'Prezzi guest post', 'url' => url('/it/prezzi')],
            ['slug' => 'agenzie', 'label' => 'Per agenzie', 'url' => self::url('agenzie')],
            ['slug' => 'digital-pr', 'label' => 'Digital PR', 'url' => self::url('digital-pr')],
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
        $mercato = '/it/mercato';
        $prezzi = '/it/prezzi';
        $comeFunziona = '/it/come-funziona';
        $register = '/register';
        $guest = '/it/comprare-guest-post';
        $sponsored = '/it/articoli-sponsorizzati';
        $links = '/it/comprare-backlink';
        $lb = '/it/link-building';
        $agencies = '/it/agenzie';
        $pr = '/it/digital-pr';
        $blogGuest = '/it/blog/cose-un-guest-post';
        $blogLb = '/it/blog/come-fare-link-building';
        $blogBack = '/it/blog/come-ottenere-backlink';
        $blogDiff = '/it/blog/guest-post-vs-articolo-sponsorizzato';
        $blogDofollow = '/it/blog/dofollow-vs-nofollow';

        return [
            'comprare-guest-post' => [
                'kicker' => 'Guest post a pagamento',
                'h1' => 'Acquistare guest post su siti di editori verificati',
                'subtitle' => 'Pubblica guest post a pagamento in Italia e in Europa: confronti nicchia, DA/DR e prezzo in euro, poi segui l’URL live sull’ordine.',
                'meta_title' => 'Acquistare guest post in Italia | SEOLinkBuildings',
                'meta_description' => 'Acquista guest post su siti italiani ed europei. Confronta editori, prezzi in EUR e metriche SEO, poi gestisci brief e URL live con SEOLinkBuildings.',
                'teaser_countries' => ['it'],
                'teaser_title' => 'Esempio di siti per guest post in Italia',
                'teaser_subtitle' => 'Anteprima mascherata di elenchi Italia attivi. I domini completi si vedono dopo la registrazione.',
                'intro' => [
                    'SEOLinkBuildings è un marketplace self-service: scegli tu il sito, non un pacchetto opaco. Guest post, articoli sponsorizzati e backlink editoriali passano dallo stesso catalogo — cambia il brief, non il prodotto inventato.',
                    'I prezzi sono in euro. Ricarichi un wallet, paghi la pubblicazione e tieni brief, chat e URL live sullo stesso ordine. I nuovi inserzionisti possono ricevere un credito di benvenuto spendibile, non prelevabile, quando l’offerta è attiva.',
                ],
                'points' => [
                    [
                        'title' => 'Editori, non un listino fantasma',
                        'body' => 'Ogni riga del catalogo è un sito con nicchia, lingua, Paese, DA/DR, traffico dichiarato e prezzo di checkout. Non vendiamo PBN né “pacchetti da 50 link”.',
                    ],
                    [
                        'title' => 'Come si compra',
                        'body' => 'Registrati, filtra Italia (o altri mercati), aggiungi il sito, invia titolo, testo o istruzioni e gli anchor. Il publisher consegna l’URL live per l’approvazione.',
                    ],
                    [
                        'title' => 'Dofollow e sponsored',
                        'body' => 'L’attributo dipende dal listing. Molti siti marcano le pubblicazioni a pagamento. Leggi il tipo di link prima di ordinare — non è un “dofollow garantito a ogni prezzo”.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Pubblicare un guest post a pagamento',
                        'body' => 'In Italia si cerca anche “comprare guest post”, “guest post a pagamento” e “guest post Italia”: è la stessa intenzione. Paghi una pubblicazione su un sito che non possiedi, con regole scritte sul listing (lunghezza, link, temi, tempi).',
                    ],
                    [
                        'h2' => 'Come scegliere i siti',
                        'body' => 'Filtra per Paese Italia, lingua italiana, nicchia e fascia di prezzo. DA e DR aiutano a scartare, non a decidere da soli. Il catalogo live si apre dopo l\'accesso e resta in inglese nella dashboard — i prezzi restano in EUR.',
                    ],
                    [
                        'h2' => 'Guest post economici, permanenti, pacchetti',
                        'body' => 'Non c’è un listino fisso “economico”. Il prezzo segue il sito. La permanenza è quella dichiarata dal publisher sul listing, non una garanzia di ranking. Non vendiamo pacchetti chiusi di guest post: compri sito per sito, oppure chiedi un volume in <a href="'.$agencies.'">modalità agenzia</a>.',
                    ],
                    [
                        'h2' => 'Differenza con l’articolo sponsorizzato',
                        'body' => 'Nel parlato SEO italiano “guest post” e “articolo sponsorizzato” spesso descrivono lo stesso acquisto. Se ti serve il vocabolario da media kit, usa <a href="'.$sponsored.'">articoli sponsorizzati</a>. La guida: <a href="'.$blogDiff.'">guest post vs articolo sponsorizzato</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Posso comprare guest post solo su siti italiani?',
                        'a' => 'Sì. Nel catalogo filtri per Paese Italia. Lo stesso wallet serve anche per altri mercati europei se ti servono.',
                    ],
                    [
                        'q' => 'Il link è sempre dofollow?',
                        'a' => 'No. Dipende dal sito. Controlla il tipo di link sul listing e, a URL live, l’attributo rel. Approfondimento: dofollow vs nofollow.',
                    ],
                    [
                        'q' => 'Come compro in sicurezza?',
                        'a' => 'Scegli siti con regole chiare, paga dal wallet in EUR, tieni il brief sull’ordine e approva l’URL live. Non comprare “ranking garantito”.',
                    ],
                    [
                        'q' => 'Serve la fattura?',
                        'a' => 'Gli inserzionisti possono scaricare le fatture degli ordini dalla fatturazione, dove il prodotto le emette. Dettagli in Per agenzie.',
                    ],
                ],
                'cta_primary' => ['label' => 'Crea un account e apri il catalogo', 'url' => $register],
                'cta_secondary' => ['label' => 'Vedi come funziona l’ordine', 'url' => $comeFunziona],
                'see_also' => [
                    ['label' => 'Catalogo siti', 'url' => $mercato],
                    ['label' => 'Prezzi guest post', 'url' => $prezzi],
                    ['label' => 'Cos’è un guest post', 'url' => $blogGuest],
                ],
            ],
            'articoli-sponsorizzati' => [
                'kicker' => 'Pubbliredazionale a pagamento',
                'h1' => 'Articoli sponsorizzati SEO su siti editoriali',
                'subtitle' => 'Acquista un articolo sponsorizzato o un pubbliredazionale con prezzo in euro, brief sull’ordine e URL live tracciato — stesso marketplace dei guest post.',
                'meta_title' => 'Articoli sponsorizzati in Italia | SEOLinkBuildings',
                'meta_description' => 'Compra articoli sponsorizzati e pubbliredazionali su siti editoriali. Prezzi in EUR, filtri per nicchia e DA/DR, URL live sull’ordine con SEOLinkBuildings.',
                'teaser_countries' => ['it'],
                'teaser_title' => 'Esempio di siti per pubblicazioni a pagamento',
                'teaser_subtitle' => 'Stesso catalogo dei guest post: qui conta il linguaggio commerciale (sponsorizzato, pubbliredazionale, inserzione editoriale).',
                'intro' => [
                    'In Italia “articolo sponsorizzato”, “pubblicazione a pagamento” e “comprare pubbliredazionale” descrivono una pubblicazione pagata sul sito di un terzo. Su SEOLinkBuildings non è un prodotto separato: è lo stesso ordine di catalogo, con disclosure e attributo link da rispettare.',
                    'Non vendiamo ranking. Vendiamo una pubblicazione con regole visibili, checkout in euro e consegna tracciata.',
                ],
                'points' => [
                    [
                        'title' => 'Stesso catalogo, altro vocabulario',
                        'body' => 'Filtra nicchia, lingua e prezzo. Il media kit del publisher sta sul listing: temi accettati, numero di link, tempi.',
                    ],
                    [
                        'title' => 'Sponsored vs guest post',
                        'body' => 'Se paghi per esistere sulla pagina, trattalo come sponsorizzato — anche se la fattura dice “guest post”. Google si aspetta qualificazione (rel sponsored o nofollow) sulle inserzioni a pagamento.',
                    ],
                    [
                        'title' => 'Inserzioni editoriali, non native ads inventate',
                        'body' => 'Consegni testo o brief. Il publisher pubblica sul suo CMS. Non è un banner display e non è una menzione su testata se il sito non lo è.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Come acquistare un articolo sponsorizzato',
                        'body' => 'Registrati, scegli i siti, paga dal wallet, invia il materiale. Lo stato resta sull’ordine fino all’URL live. Flusso: <a href="'.$comeFunziona.'">come funziona</a>. Per comprare con il termine guest post: <a href="'.$guest.'">acquistare guest post</a>.',
                    ],
                    [
                        'h2' => 'Pubbliredazionale e post sponsorizzato sul sito',
                        'body' => 'Usa queste etichette nel brief se il publisher le richiede. Il marketplace non cambia SKU: cambia come descrivi l’inserzione. Confronto: <a href="'.$blogDiff.'">differenza guest post e articolo sponsorizzato</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'È diverso da un guest post nel catalogo?',
                        'a' => 'No come prodotto. Sì come etichetta e come va dichiarato il link a pagamento.',
                    ],
                    [
                        'q' => 'Posso chiedere un dofollow su un pubbliredazionale?',
                        'a' => 'Solo se il listing lo consente. Molti editori marcano le pubblicazioni a pagamento. Non è un difetto del marketplace: è la policy del sito.',
                    ],
                    [
                        'q' => 'Offrite inserzioni su testate giornalistiche?',
                        'a' => 'Solo se quel dominio è nel catalogo. Non promettiamo Google News. Vedi anche Digital PR.',
                    ],
                ],
                'cta_primary' => ['label' => 'Apri il catalogo dopo la registrazione', 'url' => $register],
                'cta_secondary' => ['label' => 'Acquistare guest post', 'url' => $guest],
                'see_also' => [
                    ['label' => 'Catalogo', 'url' => $mercato],
                    ['label' => 'Digital PR', 'url' => $pr],
                    ['label' => 'Dofollow vs nofollow', 'url' => $blogDofollow],
                ],
            ],
            'link-building' => [
                'kicker' => 'Piattaforma, non pacchetto chiuso',
                'h1' => 'Link building in Italia sul marketplace',
                'subtitle' => 'Un servizio di link building self-service: scegli le pubblicazioni, paghi in euro, tracci gli URL live. Niente ranking garantito e niente pacchetti misteriosi.',
                'meta_title' => 'Link building in Italia: catalogo | SEOLinkBuildings',
                'meta_description' => 'Link building a pagamento in Italia con catalogo self-service. Confronta siti, DA/DR e prezzi in EUR, poi ordina pubblicazioni tracciate su SEOLinkBuildings.',
                'teaser_countries' => ['it'],
                'teaser_title' => 'Inventario per campagne di link building',
                'teaser_subtitle' => 'Esempio di siti Italia. Per ecommerce o altre nicchie filtri il catalogo dopo l\'accesso — non creiamo una pagina per ogni settore.',
                'intro' => [
                    '“Servizio link building”, “pacchetto link building” e “link building a pagamento” in SERP italiana sono spesso pagine di agenzia. SEOLinkBuildings è una piattaforma: tu costruisci la campagna dal catalogo, con lo stesso wallet per più mercati.',
                    'Se ti serve white label o fattura per più brand, vai su <a href="'.$agencies.'">guest post per agenzie</a>.',
                ],
                'points' => [
                    [
                        'title' => 'Self-service invece del PDF',
                        'body' => 'Vedi il sito, il prezzo di checkout e le metriche prima di pagare. Non compri un “pacchetto da 20 DR50” senza nomi.',
                    ],
                    [
                        'title' => 'Costo link building',
                        'body' => 'Il costo è la somma delle pubblicazioni che scegli, più eventuali pacchetti gestiti di PR digitale in listino. Dettaglio: <a href="'.$prezzi.'">prezzi guest post</a>.',
                    ],
                    [
                        'title' => 'Ecommerce e nicchie',
                        'body' => 'Non c’è una landing separata per ogni verticale. Filtri categoria (ecommerce, finanza, salute, …) nel catalogo dopo l\'accesso.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Piattaforma di link building, non “la migliore”',
                        'body' => 'Confronta con agenzie e altri marketplace su fatti verificabili: wallet EUR, URL sull’ordine, catalogo filtrabile, società UK. Non dichiariamo di essere la migliore piattaforma link building in Italia: lo decidi tu dopo una prova.',
                    ],
                    [
                        'h2' => 'Come impostare una campagna',
                        'body' => 'Scegli le URL di destinazione, filtra i siti, varia gli anchor, tieni un ritmo sostenibile. Guida: <a href="'.$blogLb.'">come fare link building</a>. Acquisto link: <a href="'.$links.'">acquistare backlink</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Vendete pacchetti di link building?',
                        'a' => 'Non come SKU opaco. Compri pubblicazioni singole. I pacchetti numerati in pagina Prezzi sono campagne gestite di PR digitale, non un sacco di URL a caso.',
                    ],
                    [
                        'q' => 'Fate link building white hat?',
                        'a' => 'Il catalogo è inventario editoriale con regole. Google considera spam i link comprati per manipolare il ranking se non qualificati. Tratta le pubblicazioni a pagamento di conseguenza.',
                    ],
                    [
                        'q' => 'Serve un’agenzia?',
                        'a' => 'No per usare il catalogo. Sì se vuoi che qualcuno scelga i siti al posto tuo — oppure usa l’account agenzia e resti tu il decision maker.',
                    ],
                ],
                'cta_primary' => ['label' => 'Inizia dal catalogo', 'url' => $register],
                'cta_secondary' => ['label' => 'Prezzi e listino', 'url' => $prezzi],
                'see_also' => [
                    ['label' => 'Acquistare guest post', 'url' => $guest],
                    ['label' => 'Come fare link building', 'url' => $blogLb],
                    ['label' => 'Per agenzie', 'url' => $agencies],
                ],
            ],
            'comprare-backlink' => [
                'kicker' => 'Backlink editoriali',
                'h1' => 'Acquistare backlink da siti editoriali',
                'subtitle' => 'Comprare backlink e link SEO dofollow dal catalogo: prezzi in euro, metriche DA/DR, attributo dichiarato sul listing. Nessun PBN, nessun ranking garantito.',
                'meta_title' => 'Acquistare backlink in Italia | SEOLinkBuildings',
                'meta_description' => 'Acquista backlink editoriali su siti italiani ed europei. Confronta dofollow, nicchia e prezzo in EUR e traccia l’URL live con SEOLinkBuildings.',
                'teaser_countries' => ['it'],
                'teaser_title' => 'Siti per backlink in Italia (anteprima)',
                'teaser_subtitle' => 'Host mascherati finché non hai un account. “Migliori siti” qui significa filtrabili, non una classifica oggettiva.',
                'intro' => [
                    '“Acquistare backlink”, “comprare link SEO” e “backlink a pagamento” sono la stessa domanda commerciale. Su SEOLinkBuildings il backlink nasce da una pubblicazione sul sito del publisher, non da un network di domini scaduti.',
                    'Google tratta i link comprati per manipolare i ranking come link spam se non qualificati. Le pubblicazioni a pagamento vanno etichettate (sponsored / nofollow) quando il sito lo richiede.',
                ],
                'points' => [
                    [
                        'title' => 'Link nel contenuto, non in footer',
                        'body' => 'Il brief chiede il link nel corpo dell’articolo. Non vendiamo sitewide né directory SEO.',
                    ],
                    [
                        'title' => 'Dofollow solo se il listing lo dice',
                        'body' => 'Filtra il tipo di link nel catalogo dopo l\'accesso. “Comprare link dofollow” non autorizza a ignorare nicchia e lingua.',
                    ],
                    [
                        'title' => 'Non è un niche edit',
                        'body' => 'Non inseriamo un link in un articolo già posizionato di un terzo, salvo che il publisher offra un extra homepage sul listing. Non è “link in articolo già in SERP” come prodotto.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Backlink siti italiani',
                        'body' => 'Filtra Paese Italia. L’anteprima pubblica maschera i domini. Catalogo: <a href="'.$mercato.'">lista siti guest post Italia</a>. Come si ottengono (anche senza comprare): <a href="'.$blogBack.'">come ottenere backlink</a>.',
                    ],
                    [
                        'h2' => 'Verificare il dofollow dopo la live',
                        'body' => 'Apri l’URL, ispeziona il rel, annota sull’ordine. Guida: <a href="'.$blogDofollow.'">dofollow vs nofollow e rel sponsored</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Posso comprare solo dofollow?',
                        'a' => 'Puoi filtrare le offerte che lo dichiarano. Il publisher resta responsabile dell’HTML live.',
                    ],
                    [
                        'q' => 'Fate inserimento link in un articolo esistente?',
                        'a' => 'Non come SKU di niche edit. Alcuni siti vendono un extra di homepage placement a tempo. Non è lo stesso di un link in un pezzo già posizionato.',
                    ],
                    [
                        'q' => 'Quanto costano i backlink?',
                        'a' => 'Dipende dal sito. Vedi la pagina Prezzi per il modello (per pubblicazione, in EUR) e il catalogo per i prezzi live.',
                    ],
                ],
                'cta_primary' => ['label' => 'Confronta i siti nel catalogo', 'url' => $register],
                'cta_secondary' => ['label' => 'Link building Italia', 'url' => $lb],
                'see_also' => [
                    ['label' => 'Prezzi', 'url' => $prezzi],
                    ['label' => 'Acquistare guest post', 'url' => $guest],
                    ['label' => 'Cos’è un backlink', 'url' => $blogBack],
                ],
            ],
            'agenzie' => [
                'kicker' => 'Account B2B',
                'h1' => 'Guest post per agenzie e white label',
                'subtitle' => 'Catalogo self-service per agenzie SEO, consulenti e team che ri-fatturano. Portafoglio in EUR, ordini tracciati, fatture nella fatturazione dell\'inserzionista.',
                'meta_title' => 'Guest post per agenzie e white label | SEOLinkBuildings',
                'meta_description' => 'Piattaforma guest post per agenzie e SEO specialist: catalogo in EUR, fatture, ordini per brand e white label operativo — senza pacchetti opachi.',
                'teaser_countries' => ['it'],
                'teaser_title' => 'Inventario che puoi ri-fatturare',
                'teaser_subtitle' => 'Stessi listing degli inserzionisti interni. Tu resti l’account; i brand stanno nei tuoi progetti/ordini.',
                'intro' => [
                    'Le ricerche “agenzia link building”, “agenzia guest post” e “consulente link building” spesso vogliono un fornitore che esegua. Qui l’agenzia resta in controllo: sceglie i siti, paga, consegna l’URL al cliente.',
                    'White label operativo significa che il cliente finale non deve creare un account sul marketplace. Non è un programma di rivendita con il tuo logo sul sito pubblico.',
                ],
                'points' => [
                    [
                        'title' => 'Un wallet, più campagne',
                        'body' => 'Ricarichi in euro (carta o bonifico dove abilitato) e distribuisci il saldo sugli ordini. Niente nuova fattura per ogni sito, a meno che tu non la voglia internamente.',
                    ],
                    [
                        'title' => 'Ordine guest post e fattura',
                        'body' => 'Le fatture degli addebiti portafoglio / ordini si scaricano dalla fatturazione dell\'inserzionista quando il prodotto le genera. Dati societari UK (Topurlz Ltd) in Chi siamo.',
                    ],
                    [
                        'title' => 'Piattaforma per SEO specialist',
                        'body' => 'Filtri, metriche, chat d’ordine e URL live. La dashboard dopo l\'accesso è in inglese per tutti i ruoli.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Agenzia vs marketplace',
                        'body' => 'Un’agenzia decide i siti al posto del cliente. Un marketplace mostra i siti al buyer. SEOLinkBuildings è il secondo. Se sei l’agenzia, usi il primo mestiere sopra il secondo.',
                    ],
                    [
                        'h2' => 'Come onboarding',
                        'body' => 'Crea un account inserzionista, ricarica, filtra, ordina. Supporto: pagina Contatti. Flusso: <a href="'.$comeFunziona.'">come funziona</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Posso nascondere il marketplace al cliente?',
                        'a' => 'Sì, operando tu l’account. Non forniamo un portale white-label branded.',
                    ],
                    [
                        'q' => 'Emittete fattura elettronica italiana?',
                        'a' => 'La fatturazione segue la società UK del prodotto. Scarica i documenti dal portafoglio/fatture in piattaforma e verifica con il tuo commercialista se ti servono integrazioni SDI.',
                    ],
                    [
                        'q' => 'C’è un listino agenzia diverso?',
                        'a' => 'Il prezzo di checkout è quello del listing. Non c’è un secondo catalogo “agenzia”.',
                    ],
                ],
                'cta_primary' => ['label' => 'Crea l’account agenzia', 'url' => $register],
                'cta_secondary' => ['label' => 'Catalogo siti', 'url' => $mercato],
                'see_also' => [
                    ['label' => 'Link building', 'url' => $lb],
                    ['label' => 'Prezzi', 'url' => $prezzi],
                    ['label' => 'Digital PR', 'url' => $pr],
                ],
            ],
            'digital-pr' => [
                'kicker' => 'PR digitale e testate',
                'h1' => 'Digital PR Italia: pubblicazioni e menzioni a catalogo',
                'subtitle' => 'Campagne digital PR come pubblicazioni su siti nel marketplace, più pacchetti gestiti in listino. Niente garanzia Google News, niente menzioni inventate.',
                'meta_title' => 'Digital PR Italia: testate e catalogo | SEOLinkBuildings',
                'meta_description' => 'Digital PR e pubblicazioni su siti italiani dal catalogo SEOLinkBuildings. Wallet in EUR, URL live, pacchetti gestiti in listino — senza promesse News.',
                'teaser_countries' => ['it'],
                'teaser_title' => 'Siti Italia nel catalogo (anteprima)',
                'teaser_subtitle' => 'Alcuni publisher sono vicini a un media kit; non tutti sono testate. Filtra nicchia e lingua dopo l\'accesso.',
                'intro' => [
                    '“Digital PR Italia”, “pubblicare su testate italiane” e “guest post su giornali” mescolano PR e link building. Qui puoi comprare pubblicazioni su siti che ci sono davvero nel catalogo. Se un dominio non è elencato, non lo vendiamo.',
                    'I pacchetti gestiti di PR digitale (importi in pagina Prezzi, oggi da 499 €/mese per il piano base se ancora in listino) sono outreach seguito dal team, non un bottone “compari su un quotidiano nazionale”.',
                ],
                'points' => [
                    [
                        'title' => 'Testate solo se sono nel catalogo',
                        'body' => 'Non abbiamo un canale Google News. Un guest post “News” esiste solo se quel sito è un listing e accetta il brief.',
                    ],
                    [
                        'title' => 'Brand mention',
                        'body' => 'Una menzione può arrivare da una pubblicazione. Non vendiamo “comprare menzione brand” come SKU separato senza URL.',
                    ],
                    [
                        'title' => 'Campagne, non un comunicato sparato',
                        'body' => 'Self-service: scegli i siti. Gestito: parla con le vendite dai pacchetti in Prezzi.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Digital PR SEO, con i piedi per terra',
                        'body' => 'Una pubblicazione utile ha lettori, contesto e un link (o una menzione) che ha senso. Non sostituisce una notizia vera. Catalogo: <a href="'.$mercato.'">lista siti</a>. Pacchetti: <a href="'.$prezzi.'">prezzi</a>.',
                    ],
                    [
                        'h2' => 'Guest post vs PR',
                        'body' => 'Il guest post è un articolo sul sito host. La digital PR punta a una storia che un editore vorrebbe da solo. Sul marketplace paghi comunque la pubblicazione: trattala come sponsorizzata se c’è un corrispettivo. <a href="'.$sponsored.'">Articoli sponsorizzati</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Pubblicate su Google News?',
                        'a' => 'No come prodotto. Se un sito del catalogo è in News, dipende da Google e dal publisher, non da noi.',
                    ],
                    [
                        'q' => 'Posso comprare solo una menzione senza articolo?',
                        'a' => 'Solo se un listing lo offre. Il default è un articolo con link nel corpo.',
                    ],
                    [
                        'q' => 'Quanto costa una campagna?',
                        'a' => 'Self-service: somma dei listing. Gestito: i pacchetti in pagina Prezzi (importi in EUR sul listino attuale).',
                    ],
                ],
                'cta_primary' => ['label' => 'Vedi i pacchetti e il catalogo', 'url' => $prezzi],
                'cta_secondary' => ['label' => 'Registrati', 'url' => $register],
                'see_also' => [
                    ['label' => 'Articoli sponsorizzati', 'url' => $sponsored],
                    ['label' => 'Per agenzie', 'url' => $agencies],
                    ['label' => 'Acquistare guest post', 'url' => $guest],
                ],
            ],
        ];
    }
}
