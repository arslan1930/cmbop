<?php

namespace App\Support;

/**
 * DE/FR/NL bodies for the how-to-get-backlinks pillar.
 */
class HowToGetBacklinksI18n
{
    /**
     * @return array<string, array{title: string, slug: string, excerpt: string, content: string, meta_title: string, meta_description: string}>
     */
    public static function all(): array
    {
        return [
            'de' => [
                'title' => 'Was sind Backlinks: praktischer Leitfaden für hochwertige Links',
                'slug' => 'was-sind-backlinks',
                'excerpt' => 'Was Backlinks sind, wie Sie sie aufbauen und bewerten: Relevanz, verweisende Domains, Gastbeiträge, Digital PR — und warum bezahlte Platzierungen keine redaktionellen Zitate sind.',
                'meta_title' => 'Was sind Backlinks? Ein praktischer Leitfaden für SEO',
                'meta_description' => 'Was sind Backlinks und wie Sie sie aufbauen: Qualität erkennen, Gastbeiträge, Digital PR, und wie sich bezahlte Platzierungen von verdienten Links unterscheiden.',
                'content' => self::de(),
            ],
            'fr' => [
                'title' => 'Comment obtenir des backlinks: un guide pratique pour des liens de qualité',
                'slug' => 'comment-obtenir-des-backlinks',
                'excerpt' => 'Obtenir et évaluer des backlinks: pertinence, domaines référents, guest posts, relations presse — et la différence avec les placements payants.',
                'meta_title' => 'Comment obtenir des backlinks: un guide SEO pratique',
                'meta_description' => 'Comment obtenir des backlinks utiles: pertinence, domaines référents, guest posts, RP digitales, et comment les liens payants se distinguent des liens éditoriaux.',
                'content' => self::fr(),
            ],
            'nl' => [
                'title' => 'Zo krijg je backlinks: een praktische gids voor kwalitatieve links',
                'slug' => 'hoe-krijg-je-backlinks',
                'excerpt' => 'Backlinks verdienen en beoordelen: relevantie, verwijzende domeinen, gastposts, digital PR — en waarom betaalde plaatsingen geen redactionele citaten zijn.',
                'meta_title' => 'Zo krijg je backlinks: een praktische gids voor SEO',
                'meta_description' => 'Praktische gids: hoe je backlinks krijgt, waar je kwaliteit herkent, en hoe betaalde plaatsingen verschillen van verdiende links.',
                'content' => self::nl(),
            ],
            'it' => [
                'title' => 'Come ottenere backlink: guida pratica ai link di qualità',
                'slug' => 'come-ottenere-backlink',
                'excerpt' => 'Come ottenere e valutare i backlink: rilevanza, referring domain, guest post, digital PR — e perché una pubblicazione a pagamento non è una citazione editoriale.',
                'meta_title' => 'Come ottenere backlink: una guida pratica per il SEO',
                'meta_description' => 'Come ottenere backlink utili: rilevanza, referring domain, guest post, PR digitale, e come i link a pagamento si distinguono da quelli guadagnati.',
                'content' => self::it(),
            ],
        ];
    }

    private static function de(): string
    {
        $guest = '/de/blog/was-ist-ein-gastbeitrag';
        $sponsored = '/de/blog/gesponserte-beitraege-leitfaden';
        $linkGuide = '/de/blog/linkaufbau-strategien';
        $chooseSite = '/blog/how-to-choose-a-publisher-site-dr-da-traffic-niche';
        $buyGuide = '/de/gastbeitrag-kaufen';
        $dofollow = '/de/blog/dofollow-vs-nofollow-ankertext';
        $outreach = '/blog/marketplace-vs-cold-outreach-vs-digital-pr';
        $catalog = '/de/marktplatz';
        $how = '/de/so-funktioniert-es';
        $buyLinks = '/de/backlinks-kaufen';
        $img = BlogInlineImages::publicUrl(HowToGetBacklinksBlogPost::IMAGE_METHODS);

        return <<<HTML
<p>Ein Backlink ist ein Hyperlink von einer Website auf eine andere. Suchmaschinen nutzen solche Links — neben vielen anderen Signalen —, um zu verstehen, wie Seiten zusammenhängen und welche Quellen andere Sites zitieren.</p>
<p>Mehr ist die Mechanik nicht. Der Rest dieses Leitfadens geht darum, keine Monate mit Links zu verschwenden, die der Zielseite nichts nützen.</p>
<p>Die Strategie — Linktypen, Messung, Roadmap — steht im <a href="{$linkGuide}">Linkbuilding-Leitfaden</a>. Hier geht es um Beschaffung: wie man Backlinks tatsächlich bekommt, und wie man einen nützlichen von einem lauten unterscheidet.</p>

<h2>Was sind Backlinks?</h2>
<p>Site A veröffentlicht eine Seite, die auf eine URL von Site B zeigt. Für Site B ist das ein Backlink. Die sendende Site ist eine <strong>verweisende Domain</strong>, wenn Sie sie noch nicht gezählt haben.</p>
<p>Zehn Links von einem Blog sind nicht dasselbe wie zehn Links von zehn Publishern. Kampagnenberichte sollten beides zeigen.</p>
<p>Ein Backlink kann redaktionell, über Outreach verdient, bezahlt/gesponsert oder nutzergeneriert sein. Das ist nicht gleichwertig. Googles Spam-Richtlinien behandeln den Kauf von Links <em>zur Ranking-Manipulation</em> als Linkspam; Werbung und Sponsoring sind normal, wenn sie mit <code>rel="sponsored"</code> oder <code>rel="nofollow"</code> gekennzeichnet sind. Details im <a href="{$sponsored}">Leitfaden zu gesponserten Beiträgen</a>.</p>

<h2>Warum Backlinks relevant sein können</h2>
<p>Links sind kein Geheimcode für Platz 1. Sie helfen Suchsystemen, Seiten zu entdecken und einzuschätzen, ob andere Sites eine URL als Referenz behandeln. Erwarten Sie nicht „N Backlinks, dann Rank 1“. Rankings hängen auch von Inhalt, Suchintention, internen Links und Wettbewerb ab.</p>

<h2>Was einen Backlink wertvoll macht</h2>
<p>Bewerten Sie die Seite, nicht das Logo im Header. <strong>Relevanz</strong> auf Seitenebene schlägt eine lose Nischen-Zuordnung auf der Startseite. Neue verweisende Domains lehren Suchsysteme in der Regel mehr als der fünfte Link desselben Publishers. Ein Link im Fließtext schlägt Sitewide-Footer. Drittanbieter-Scores wie Domain Rating sind Filter, kein Beweis — siehe <a href="{$chooseSite}">Publisher-Site auswählen</a>. Traffic zur verlinkenden URL ist ein Plausibilitätscheck, kein Vertrag.</p>

<h2>Wege, Backlinks zu bekommen</h2>
<figure>
<img src="{$img}" alt="Fünf Wege zu Backlinks: Digital PR, Gastbeitrag, Ressourcenseite, Broken Link, Sponsored" loading="lazy" width="1200" height="675">
<figcaption>Nützliche Backlinks kommen aus Methoden, die zur Seite passen — nicht aus einem Volumen-Soll.</figcaption>
</figure>
<p>Gastbeiträge: Workflow im <a href="{$guest}">Gastbeitrags-Leitfaden</a>. Digital PR braucht eine Geschichte, keine PDF-Flut; Journalistenanfragen (früher oft HARO, heute etwa Connectively) sind eine Beat-Liste, kein Automat. Linkable Assets zuerst bauen, Outreach danach. Ressourcenseiten und Broken-Link-Angebote nur, wenn Ihre Seite wirklich passt. Verzeichnisse von Kammern und Verbänden können legitim sein; „SEO-Directories“, die nur Dofollow-Slots verkaufen, nicht. Outreach ist ein Prozess: eine konkrete Bitte schlägt eine Vorlage. Vergleich: <a href="{$outreach}">Marketplace vs. Cold Outreach vs. Digital PR</a>.</p>
<p>Bezahlte Platzierungen sind Werbung. Ein Katalog wie <a href="{$catalog}">SEOLinkBuildings</a> ist Inventar mit Regeln, kein Beutel „garantierter Ranking-Links“. Ablauf: <a href="{$how}">So funktioniert es</a> und der <a href="{$buyGuide}">Advertiser-Kaufleitfaden</a>.</p>

<h2>Chance bewerten und Kampagne aufsetzen</h2>
<p>Lesen Sie die konkrete URL, nicht nur die Domain. Prüfen Sie Themen-Overlap, Outbound-Links, Indexierbarkeit und das Link-Attribut (<a href="{$dofollow}">Dofollow, Nofollow, Ankertexte</a>). Eine Ziel-URL. Mix aus Brand-, URL- und beschreibenden Ankern. Wenn die ersten Platzierungen keine Klicks bringen und nicht indexiert werden: nicht skalieren.</p>

<h2>Häufige Fehler</h2>
<ul>
<li>Bulk-„DA50+-Homepage-Links“ ohne Site-Liste</li>
<li>Exact-Match-Anker auf jeder Platzierung</li>
<li>PBNs, automatisierte Profil- und Kommentarlinks</li>
<li>Gastbeiträge auf Sites ohne Publikum</li>
</ul>

<h2>Checkliste</h2>
<ul>
<li>Ziel-URL ist die beste Seite zum Thema</li>
<li>Sie können erklären, warum die Leser der Host-Site das interessiert</li>
<li>Link sitzt im Fließtext; Attribut passt zur kommerziellen Realität</li>
<li>Live-URL-Check steht im Kalender</li>
</ul>

<h2>Häufig gestellte Fragen</h2>
<h3>Wie viele Backlinks brauche ich?</h3>
<p>Es gibt keine branchenübergreifende Zahl. Verfolgen Sie, ob relevante Seiten ranken und ob Referral-Besuche konvertieren.</p>
<h3>Helfen Nofollow-Links?</h3>
<p>Google behandelt <code>nofollow</code>, <code>sponsored</code> und <code>ugc</code> als Hinweise. Sie schicken trotzdem Menschen. Weder wertlos noch ein Schlupfloch.</p>
<h3>Sind Marketplace-Links „schlecht“?</h3>
<p>Ein Marketplace findet Publisher und wickelt Aufträge ab. Qualität hängt von Site, Artikel und Kennzeichnung ab.</p>
<h3>Soll ich alte schlechte Links disavowen?</h3>
<p>Die meisten Sites sammeln Müll. Google warnt seit Langem vor Panik-Disavow. Search Console, Muster, die Sie steuern, Hilfe bei bekannten Spam-Netzen.</p>

<h2>Quellen und weiterführende Links</h2>
<ul>
<li><a href="https://developers.google.com/search/docs/essentials/spam-policies">Google Search Central — Spam-Richtlinien</a></li>
<li><a href="https://developers.google.com/search/blog/2019/09/evolving-nofollow-new-ways-to-identify">Evolving nofollow (2019)</a></li>
<li><a href="https://developers.google.com/search/blog/2021/07/link-tagging-and-link-spam-update">Qualifying links (2021)</a></li>
<li><a href="https://developers.google.com/search/docs/crawling-indexing/links-crawlable">Crawlable links</a></li>
<li><a href="https://developers.google.com/search/docs/fundamentals/creating-helpful-content">Helpful, people-first content</a></li>
</ul>
<p>Spam-Score und ähnliche Drittanbieter-Werte sind Filter, kein Urteil. Prüfen Sie Relevanz, Outbound-Muster und Live-HTML selbst. Kommerzielle Beschaffung: <a href="{$buyLinks}">Backlinks kaufen</a> oder <a href="{$buyGuide}">Gastbeitrag kaufen</a> — ohne Ranking-Garantie.</p>
HTML;
    }

    private static function fr(): string
    {
        $guest = '/blog/guest-posting-guide';
        $sponsored = '/blog/sponsored-post-guide';
        $linkGuide = '/blog/link-building-guide';
        $chooseSite = '/blog/how-to-choose-a-publisher-site-dr-da-traffic-niche';
        $buyGuide = '/blog/how-to-buy-guest-posts-on-seolinkbuildings-advertiser-guide';
        $dofollow = '/blog/dofollow-nofollow-and-anchor-text-for-marketplace-links';
        $outreach = '/blog/marketplace-vs-cold-outreach-vs-digital-pr';
        $catalog = '/marketplace';
        $how = '/how-it-works';
        $img = BlogInlineImages::publicUrl(HowToGetBacklinksBlogPost::IMAGE_METHODS);

        return <<<HTML
<p>Un backlink est un hyperlien d’un site vers un autre. Les moteurs s’en servent, parmi d’autres signaux, pour découvrir des pages et voir quelles sources d’autres sites citent.</p>
<p>Le reste de ce guide sert à ne pas passer des mois à collecter des liens qui n’aident pas la page visée.</p>
<p>La stratégie plus large est dans le <a href="{$linkGuide}">guide de netlinking</a>. Ici: comment on obtient réellement des backlinks, et comment juger leur utilité.</p>

<h2>Qu’est-ce qu’un backlink?</h2>
<p>Le site A publie une page qui pointe vers une URL du site B. Pour B, c’est un backlink. Le site émetteur est un <strong>domaine référent</strong> si vous ne l’avez pas encore compté. Dix liens d’un même blog ne valent pas dix éditeurs distincts.</p>
<p>Un lien peut être éditorial, obtenu par outreach, sponsorisé, ou généré par des utilisateurs. Google traite l’achat de liens <em>pour manipuler le classement</em> comme du spam de liens; la publicité est normale si elle est qualifiée avec <code>rel="sponsored"</code> ou <code>rel="nofollow"</code>. Voir le <a href="{$sponsored}">guide des articles sponsorisés</a>.</p>

<h2>Pourquoi les backlinks peuvent compter</h2>
<p>Ce n’est pas un contrat « N liens = position 1 ». Le contenu, l’intention, les liens internes et les concurrents pèsent aussi. Un lien dans un paragraphe utile vaut mieux qu’un pied de page sans rapport.</p>

<h2>Ce qui rend un backlink utile</h2>
<p>Jugez la page, pas le logo. Pertinence du paragraphe, nouveaux domaines référents, contexte éditorial, qualité réelle du site, trafic vers l’URL. Les scores DR/DA sont des filtres. Checklist: <a href="{$chooseSite}">choisir un site éditeur</a>.</p>

<h2>Comment obtenir des backlinks</h2>
<figure>
<img src="{$img}" alt="Cinq façons d’obtenir des backlinks: RP digitales, guest post, page de ressources, lien brisé, sponsorisé" loading="lazy" width="1200" height="675">
<figcaption>Choisissez la méthode qui correspond à la page, pas un quota de volume.</figcaption>
</figure>
<p>Guest posting: <a href="{$guest}">guide guest posting</a>. Les RP digitales demandent une raison d’être citées (données, méthode, outil). Les desks type Connectively (après HARO) sont une liste de sujets, pas un distributeur. Pages de ressources et liens brisés seulement si votre page remplace vraiment la cible. Les annuaires « SEO » qui vendent un dofollow à n’importe qui: à éviter. Comparer les canaux: <a href="{$outreach}">marketplace, outreach à froid, RP</a>.</p>
<p>Un catalogue comme <a href="{$catalog}">SEOLinkBuildings</a> est un inventaire avec des règles, pas une garantie de ranking. Parcours: <a href="{$how}">comment ça marche</a>, <a href="{$buyGuide}">guide acheteur</a>.</p>

<h2>Évaluer une opportunité</h2>
<p>Lisez l’URL précise. Vérifiez le thème, les liens sortants, l’indexation, l’attribut (<a href="{$dofollow}">dofollow, nofollow, ancres</a>). Une URL cible. Mélangez ancres de marque, URL et descriptives. Si les premiers placements n’envoient personne: arrêtez de scaler.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li>Packs « DA50+ homepage » sans liste de sites</li>
<li>Ancres exact-match partout</li>
<li>PBN, commentaires et profils automatisés</li>
<li>Guest posts sur des sites sans lecteurs</li>
</ul>

<h2>Checklist</h2>
<ul>
<li>L’URL cible mérite la citation</li>
<li>Le lecteur de l’hôte a une raison de cliquer</li>
<li>Lien dans le corps; attribut honnête si c’est payant</li>
<li>Contrôle de l’URL live au calendrier</li>
</ul>

<h2>Questions fréquentes</h2>
<h3>Combien de backlinks faut-il?</h3>
<p>Aucun quota universel. Suivez les requêtes pertinentes et les visites utiles.</p>
<h3>Les nofollow aident-ils?</h3>
<p>Google les traite comme des indices. Ils envoient encore du trafic. Ni inutiles, ni une faille.</p>
<h3>Les liens marketplace sont-ils « mauvais »?</h3>
<p>Le marketplace trouve des éditeurs. La qualité dépend du site, de l’article et du marquage.</p>
<h3>Faut-il disavow les mauvais liens?</h3>
<p>La plupart des sites accumulent du bruit. Évitez le disavow de panique. Search Console, motifs que vous contrôlez.</p>

<h2>Sources</h2>
<ul>
<li><a href="https://developers.google.com/search/docs/essentials/spam-policies">Politiques anti-spam Google</a></li>
<li><a href="https://developers.google.com/search/blog/2019/09/evolving-nofollow-new-ways-to-identify">Evolving nofollow (2019)</a></li>
<li><a href="https://developers.google.com/search/blog/2021/07/link-tagging-and-link-spam-update">Qualifying links (2021)</a></li>
<li><a href="https://developers.google.com/search/docs/crawling-indexing/links-crawlable">Liens crawlables</a></li>
<li><a href="https://developers.google.com/search/docs/fundamentals/creating-helpful-content">Contenu utile</a></li>
</ul>
HTML;
    }

    private static function nl(): string
    {
        $guest = '/blog/guest-posting-guide';
        $sponsored = '/blog/sponsored-post-guide';
        $linkGuide = '/blog/link-building-guide';
        $chooseSite = '/blog/how-to-choose-a-publisher-site-dr-da-traffic-niche';
        $buyGuide = '/blog/how-to-buy-guest-posts-on-seolinkbuildings-advertiser-guide';
        $dofollow = '/blog/dofollow-nofollow-and-anchor-text-for-marketplace-links';
        $outreach = '/blog/marketplace-vs-cold-outreach-vs-digital-pr';
        $catalog = '/marketplace';
        $how = '/how-it-works';
        $img = BlogInlineImages::publicUrl(HowToGetBacklinksBlogPost::IMAGE_METHODS);

        return <<<HTML
<p>Een backlink is een hyperlink van de ene site naar de andere. Zoekmachines gebruiken die links, naast andere signalen, om pagina’s te ontdekken en te zien welke bronnen andere sites citeren.</p>
<p>De rest van deze gids gaat over geen maanden verspillen aan links die de doelpagina niets opleveren.</p>
<p>De bredere strategie staat in de <a href="{$linkGuide}">linkbuilding-gids</a>. Hier: hoe je backlinks écht krijgt, en hoe je nut van ruis scheidt.</p>

<h2>Wat is een backlink?</h2>
<p>Site A publiceert een pagina die naar een URL op site B wijst. Voor B is dat een backlink. De verzendende site is een <strong>verwijzend domein</strong> als je hem nog niet hebt geteld. Tien links van één blog zijn geen tien uitgevers.</p>
<p>Een link kan redactioneel, via outreach verdiend, gesponsord of user-generated zijn. Google behandelt het kopen van links <em>om rankings te manipuleren</em> als linkspam; reclame is normaal als die is gekwalificeerd met <code>rel="sponsored"</code> of <code>rel="nofollow"</code>. Zie de <a href="{$sponsored}">gids gesponsorde posts</a>.</p>

<h2>Waarom backlinks ertoe kunnen doen</h2>
<p>Geen contract van „N links = plek 1”. Content, zoekintentie, interne links en concurrenten wegen mee. Een link in een nuttige alinea slaat een irrelevante footer.</p>

<h2>Wat een backlink waardevol maakt</h2>
<p>Beoordeel de pagina, niet het logo. Topicale overlap, nieuwe verwijzende domeinen, redactionele context, echte sitekwaliteit, verkeer naar de URL. DR/DA zijn filters. Checklist: <a href="{$chooseSite}">een publishersite kiezen</a>.</p>

<h2>Manieren om backlinks te krijgen</h2>
<figure>
<img src="{$img}" alt="Vijf manieren om backlinks te krijgen: digital PR, gastpost, resourcepagina, kapotte link, sponsored" loading="lazy" width="1200" height="675">
<figcaption>Kies de methode die bij de pagina past — geen volumequota.</figcaption>
</figure>
<p>Gastposts: <a href="{$guest}">gastblog-gids</a>. Digital PR vraagt een reden om geciteerd te worden. Journalistendesk (na HARO o.a. Connectively) is een beatlijst, geen automaat. Resourcepagina’s en broken links alleen als jouw pagina écht past. „SEO-directories” die alleen dofollow verkopen: overslaan. Kanalen: <a href="{$outreach}">marketplace vs cold outreach vs PR</a>.</p>
<p>Een catalogus zoals <a href="{$catalog}">SEOLinkBuildings</a> is inventaris met regels, geen rankinggarantie. Pad: <a href="{$how}">hoe het werkt</a>, <a href="{$buyGuide}">adverteerdersgids</a>.</p>

<h2>Een kans beoordelen</h2>
<p>Lees de concrete URL. Check thema, outbound links, indexatie, attribuut (<a href="{$dofollow}">dofollow, nofollow, ankers</a>). Eén doel-URL. Mix van merk, URL en beschrijvende ankers. Geen schaal als de eerste plaatsingen niemand sturen.</p>

<h2>Veelgemaakte fouten</h2>
<ul>
<li>Bulk „DA50+ homepage links” zonder sitelijst</li>
<li>Exact-match ankers op elke plaatsing</li>
<li>PBN’s, automatische profiel- en commentaarlinks</li>
<li>Gastposts op sites zonder lezers</li>
</ul>

<h2>Checklist</h2>
<ul>
<li>Doel-URL is de beste pagina over dat onderwerp</li>
<li>Je kunt uitleggen waarom de lezer van de host klikt</li>
<li>Link in de body; attribuut klopt als het betaald is</li>
<li>Live-URL-check in de agenda</li>
</ul>

<h2>Veelgestelde vragen</h2>
<h3>Hoeveel backlinks heb ik nodig?</h3>
<p>Er is geen universeel aantal. Volg relevante queries en nuttige verwijzingen.</p>
<h3>Helpen nofollow-links?</h3>
<p>Google behandelt ze als hints. Ze sturen nog steeds mensen. Niet waardeloos, geen achterdeurtje.</p>
<h3>Zijn marketplace-links „slecht”?</h3>
<p>Een marketplace vindt publishers. Kwaliteit hangt af van site, artikel en labeling.</p>
<h3>Moet ik slechte links disavowen?</h3>
<p>De meeste sites rapen rommel op. Geen paniek-disavow. Search Console, patronen die jij stuurt.</p>

<h2>Bronnen</h2>
<ul>
<li><a href="https://developers.google.com/search/docs/essentials/spam-policies">Google-spambeleid</a></li>
<li><a href="https://developers.google.com/search/blog/2019/09/evolving-nofollow-new-ways-to-identify">Evolving nofollow (2019)</a></li>
<li><a href="https://developers.google.com/search/blog/2021/07/link-tagging-and-link-spam-update">Qualifying links (2021)</a></li>
<li><a href="https://developers.google.com/search/docs/crawling-indexing/links-crawlable">Crawlable links</a></li>
<li><a href="https://developers.google.com/search/docs/fundamentals/creating-helpful-content">Nuttige content</a></li>
</ul>
HTML;
    }

    private static function it(): string
    {
        $guest = '/it/blog/cose-un-guest-post';
        $sponsored = '/it/blog/guest-post-vs-articolo-sponsorizzato';
        $linkGuide = '/it/blog/come-fare-link-building';
        $chooseSite = '/blog/how-to-choose-a-publisher-site-dr-da-traffic-niche';
        $buy = '/it/comprare-guest-post';
        $dofollow = '/it/blog/dofollow-vs-nofollow';
        $outreach = '/blog/marketplace-vs-cold-outreach-vs-digital-pr';
        $catalog = '/it/mercato';
        $how = '/it/come-funziona';
        $back = '/it/comprare-backlink';
        $img = BlogInlineImages::publicUrl(HowToGetBacklinksBlogPost::IMAGE_METHODS);

        return <<<HTML
<p>Un backlink è un collegamento ipertestuale da un sito a un altro. I motori lo usano, insieme ad altri segnali, per capire come le pagine si relazionano e chi cita chi.</p>
<p>Tutta la meccanica è questa. Il resto della guida serve a non sprecare mesi su link che non aiutano la pagina di destinazione.</p>
<p>La strategia — tipi di link, misura, roadmap — sta in <a href="{$linkGuide}">come fare link building</a>. Qui è acquisizione: come si ottengono i backlink, e come si distingue un link utile da uno rumoroso.</p>

<h2>Cos’è un backlink</h2>
<p>Il sito A pubblica una pagina che punta a una URL del sito B. Per B è un backlink. Il sito che invia il link è un <strong>referring domain</strong> se non lo hai già contato.</p>
<p>Dieci link dallo stesso blog non sono dieci publisher. I report vanno tenuti separati.</p>
<p>Un backlink può essere editoriale, ottenuto con outreach, pagato/sponsorizzato o user-generated. Non è la stessa cosa. Le spam policy di Google trattano l’acquisto di link <em>per manipolare i ranking</em> come link spam; pubblicità e sponsorizzazioni sono normali se marcate con <code>rel="sponsored"</code> o <code>rel="nofollow"</code>. Dettagli: <a href="{$sponsored}">articolo sponsorizzato</a>.</p>

<h2>Perché i backlink possono contare</h2>
<p>Non sono un codice per il primo posto. Aiutano discovery e contesto. Non aspettarti “N backlink, poi rank 1”. Contano anche contenuto, intento, link interni e concorrenza.</p>

<h2>Cosa rende un backlink utile</h2>
<p>Valuta la pagina, non il logo. La <strong>rilevanza</strong> a livello di URL batte una nicchia vaga in homepage. Nuovi referring domain insegnano di più del quinto link dallo stesso host. Un link nel corpo batte il footer. DA/DR sono filtri, non prove — <a href="{$chooseSite}">scegliere un publisher</a>. Il traffico verso la pagina che linka è un controllo di plausibilità, non un contratto. ZA non è una metrica del catalogo SEOLinkBuildings.</p>

<h2>Modi per ottenere backlink</h2>
<figure>
<img src="{$img}" alt="Cinque vie ai backlink: digital PR, guest post, pagina risorse, broken link, sponsored" loading="lazy" width="1200" height="675">
<figcaption>I backlink utili arrivano da metodi adatti alla pagina — non da un obbligo di volume.</figcaption>
</figure>
<p>Guest post: <a href="{$guest}">cos’è un guest post</a>. Digital PR vuole una storia, non un PDF. Asset linkabili prima, outreach dopo. Directory di camere e associazioni possono essere legittime; le “SEO directory” che vendono solo slot dofollow, no. Outreach: una richiesta concreta batte un template. Confronto: <a href="{$outreach}">marketplace vs cold outreach vs PR</a>.</p>
<p>Le pubblicazioni a pagamento sono pubblicità. Un catalogo come <a href="{$catalog}">SEOLinkBuildings</a> è inventario con regole, non un sacco di “link ranking garantiti”. Flusso: <a href="{$how}">come funziona</a> e <a href="{$buy}">acquistare guest post</a>. Per l’intento “comprare link”: <a href="{$back}">acquistare backlink</a>.</p>

<h2>Domande frequenti</h2>
<h3>Quanti backlink mi servono?</h3>
<p>Non c’è un numero universale. Segui query pertinenti e citazioni utili.</p>
<h3>I nofollow servono?</h3>
<p>Google li tratta come hint. Portano ancora persone. Non inutili, non un trucco.</p>
<h3>I link da marketplace sono “cattivi”?</h3>
<p>Il marketplace trova i publisher. La qualità dipende da sito, articolo e marcatura.</p>
<h3>Devo fare disavow?</h3>
<p>Quasi tutti i siti raccolgono spazzatura. Niente panico. Search Console, pattern che controlli tu.</p>
HTML;
    }
}
