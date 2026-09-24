<?php

namespace App\Support;

/**
 * DE/FR/NL bodies for the link-building-guide pillar.
 */
class LinkBuildingGuideI18n
{
    /**
     * @return array<string, array{title: string, slug: string, excerpt: string, content: string, meta_title: string, meta_description: string}>
     */
    public static function all(): array
    {
        return [
            'de' => [
                'title' => 'Linkaufbau-Strategien: wie Linkbuilding in der Praxis funktioniert',
                'slug' => 'linkaufbau-strategien',
                'excerpt' => 'Linkaufbau-Strategien: wie Suchmaschinen Links lesen, welche Taktiken tragen, wie Sie messen — und was Sie lassen. Inklusive Gastbeiträge und Digital PR.',
                'meta_title' => 'Linkaufbau-Strategien: praktische SEO-Taktik',
                'meta_description' => 'Wie funktioniert Linkbuilding: Linktypen, Content und PR, Gastbeiträge, Outreach, Anker, Messung und riskante Taktiken, die Sie lassen.',
                'content' => self::de(),
            ],
            'fr' => [
                'title' => 'Guide de netlinking: une stratégie SEO pratique pour bâtir de l’autorité',
                'slug' => 'guide-netlinking',
                'excerpt' => 'Stratégie de netlinking: comment les moteurs lisent les liens, quelles tactiques tiennent, comment mesurer, et quoi éviter.',
                'meta_title' => 'Guide netlinking: stratégie SEO pratique d’autorité',
                'meta_description' => 'Stratégie de netlinking: types de liens, contenu et RP, guest posts, outreach, ancres, mesure, et tactiques risquées à éviter.',
                'content' => self::fr(),
            ],
            'nl' => [
                'title' => 'Linkbuilding-gids: een praktische SEO-strategie voor autoriteit',
                'slug' => 'linkbuilding-gids',
                'excerpt' => 'Praktische linkbuilding: hoe zoekmachines links lezen, welke tactieken houdbaar zijn, hoe je meet, en wat je laat.',
                'meta_title' => 'Linkbuilding-gids: een praktische strategie voor SEO',
                'meta_description' => 'Praktische linkbuildingstrategie: soorten links, content en PR, gastposts, outreach, ankers, meting, en risicovolle tactieken om te laten.',
                'content' => self::nl(),
            ],
            'it' => [
                'title' => 'Come fare link building: strategia SEO pratica',
                'slug' => 'come-fare-link-building',
                'excerpt' => 'Link building in pratica: come i motori leggono i link, quali tattiche reggono, come misuri, e cosa lasciare stare — anche in Italia.',
                'meta_title' => 'Come fare link building: una strategia SEO pratica',
                'meta_description' => 'Guida al link building: tipi di link, content e PR, guest post, outreach, ancore, misurazione e tattiche rischiose da evitare.',
                'content' => self::it(),
            ],
        ];
    }

    private static function de(): string
    {
        $backlinks = '/de/blog/was-sind-backlinks';
        $guest = '/de/blog/was-ist-ein-gastbeitrag';
        $sponsored = '/de/blog/gesponserte-beitraege-leitfaden';
        $chooseSite = '/blog/how-to-choose-a-publisher-site-dr-da-traffic-niche';
        $dofollow = '/de/blog/dofollow-vs-nofollow-ankertext';
        $outreach = '/blog/marketplace-vs-cold-outreach-vs-digital-pr';
        $live = '/blog/what-to-check-after-the-live-link-indexation-attributes-rankings';
        $aeo = '/blog/ai-aeo-seo-why-guest-posts-and-brand-mentions-matter';
        $buyGuide = '/de/gastbeitrag-kaufen';
        $catalog = '/de/marktplatz';
        $how = '/de/so-funktioniert-es';
        $lb = '/de/linkbuilding';
        $img = BlogInlineImages::publicUrl(LinkBuildingGuideBlogPost::IMAGE_ROADMAP);

        return <<<HTML
<p>Linkbuilding ist die Arbeit, Hyperlinks von anderen Websites auf die eigene zu verdienen oder zu platzieren. Suchmaschinen nutzen Links unter anderem zur Discovery und zur Einordnung. Das ist die Mechanik — kein Versprechen, dass zehn neue hrefs ein Keyword bewegen.</p>
<p>Beschaffung: <a href="{$backlinks}">Was sind Backlinks</a>. Contributor: <a href="{$guest}">Was ist ein Gastbeitrag</a>. Paid: <a href="{$sponsored}">gesponserte Beiträge</a>.</p>

<h2>Wie funktioniert Linkbuilding?</h2>
<p>Eine Kampagne braucht eine Ziel-URL, einen Grund, sie zu zitieren, einen Weg zu Publishern und eine Definition von „fertig“, die nicht nur eine Tabellenzeile ist. Links helfen bei Discovery (crawlbares <code>&lt;a href&gt;</code>), Kontext (Anker und Umgebung) und Menschen (Referral). Googles Guidance zu hilfreichem Content bleibt die Basis. Plattform-Übersicht: <a href="{$lb}">Linkaufbau auf dem Marktplatz</a>.</p>
<p>Öffentliche Leitplanken: Spam-Richtlinien zu Linkschemata, Qualifizierung von Werbung, Hinweise statt hartem Ausschluss für <code>nofollow</code>/<code>sponsored</code>/<code>ugc</code> seit 2019, crawlbare Links.</p>

<h2>Typen, Verdienen vs. Bauen</h2>
<p>Editorial, Gastbeitrag, Sponsored, Digital PR, Resource/Directory, UGC, interne Links (keine Backlinks, aber Teil der Strategie). Verweisende Domains und Rohzahl getrennt berichten. Wenn Sie in einem Satz nicht erklären können, warum ein Fremder die URL speichern würde: zuerst die URL reparieren.</p>

<h2>Taktiken</h2>
<p>Content-led Assets; Digital PR (manchmal nur eine Erwähnung — siehe <a href="{$aeo}">Guest Posts und Brand Mentions</a>); Gastbeiträge auf relevanten Hosts; Ressourcen- und Broken-Link-Outreach; Competitor-Referring-Domains nach Relevanz sortieren, nicht nach Score. Outreach: <a href="{$outreach}">Marketplace vs. Cold Email vs. PR</a>. Katalog: <a href="{$catalog}">SEOLinkBuildings</a>, <a href="{$how}">So funktioniert es</a>, <a href="{$chooseSite}">Publisher wählen</a>.</p>
<p>Anker: Satz zuerst, dann klickbare Wörter. Attribute: <a href="{$dofollow}">Dofollow, Nofollow, Ankertexte</a>. Interne Links sind der günstigste Weg, Hubs zu markieren.</p>

<h2>Messen, Fehler, Risiko</h2>
<p>Eine primäre URL. Verweisende Domains, Live-URLs mit Attributen, Indexierung, Referral, Impressionen in Search Console, Verluste — <a href="{$live}">Live-Link-Checkliste</a>. Kein „50 Backlinks“, wenn 40 aus einem Netz kommen.</p>
<p>Lassen Sie: PBNs, automatisierte Kommentar-/Profil-Links, Linkfarmen, industrielle Reciprocal-Seiten, Expired-Domain-Netze, großflächiges irrelevantes Guest Posting mit Keyword-Ankern, unqualifizierte Dofollow-Pakete.</p>

<h2>Nachhaltige Strategie und Roadmap</h2>
<figure>
<img src="{$img}" alt="Linkbuilding-Roadmap: Einsteiger, Fortgeschrittene, Fortgeschrittene Strategie" loading="lazy" width="1200" height="675">
<figcaption>Host-Qualität lernen, bevor Sie Volumen addieren.</figcaption>
</figure>
<p>Ein oder zwei Assets, die Sie pflegen. Ein kleines Contributor-Programm. Gelegentlich gekennzeichnete Sponsored Placements. PR, wenn Sie eine Geschichte haben. Interne Links als Boden. Bestellen: <a href="{$buyGuide}">Gastbeiträge kaufen</a>.</p>
<p>Einsteiger: eine URL, interne Links, fünf relevante Zitationen, Recheck. Fortgeschritten: ein zitierbares Asset, Competitor-Domains, Mix. Fortgeschritten+: kleiner Publisher-Roster, Lokalisierung, Profil auf Spam-Muster prüfen. Kein Panik-Disavow.</p>

<h2>Checkliste</h2>
<ul>
<li>Ziel-URL verdient eine Zitation</li>
<li>Jeder Prospect hat Themen- oder Publikumsoverlap</li>
<li>Verdient, contributed oder paid ist klar; Paid ist qualifiziert</li>
<li>Ankermix übersteht eine menschliche Review</li>
<li>Stop-Regel, wenn die Qualität fällt</li>
</ul>

<h2>Häufig gestellte Fragen</h2>
<h3>Wie viele Backlinks zum Rank?</h3>
<p>Kein branchenübergreifendes Soll. Relevante Queries und nützliche Referrals, keine Laufsumme.</p>
<h3>Sind Backlinks noch ein Rankingfaktor?</h3>
<p>Suchmaschinen nutzen Links weiter zur Discovery und Einordnung. Google veröffentlicht auch Spam-Regeln gegen manipulative Schemata. Ein Signal unter vielen.</p>
<h3>Ist Linkbuilding dasselbe wie SEO?</h3>
<p>Nein. SEO umfasst Crawling, Indexierung, Content, Intention, interne Links und mehr.</p>
<h3>Nur per Marketplace?</h3>
<p>Sie können Platzierungen so beschaffen. Sie brauchen trotzdem eine Seite, die den Klick verdient, und eine Qualitätsschwelle für Hosts.</p>

<h2>Quellen</h2>
<ul>
<li><a href="https://developers.google.com/search/docs/essentials/spam-policies">Google Search Central — Spam-Richtlinien</a></li>
<li><a href="https://developers.google.com/search/blog/2019/09/evolving-nofollow-new-ways-to-identify">Evolving nofollow (2019)</a></li>
<li><a href="https://developers.google.com/search/blog/2021/07/link-tagging-and-link-spam-update">Qualifying links (2021)</a></li>
<li><a href="https://developers.google.com/search/docs/crawling-indexing/links-crawlable">Crawlable links</a></li>
<li><a href="https://developers.google.com/search/docs/fundamentals/creating-helpful-content">Helpful content</a></li>
</ul>
HTML;
    }

    private static function fr(): string
    {
        $backlinks = '/blog/how-to-get-backlinks';
        $guest = '/blog/guest-posting-guide';
        $sponsored = '/blog/sponsored-post-guide';
        $chooseSite = '/blog/how-to-choose-a-publisher-site-dr-da-traffic-niche';
        $dofollow = '/blog/dofollow-nofollow-and-anchor-text-for-marketplace-links';
        $outreach = '/blog/marketplace-vs-cold-outreach-vs-digital-pr';
        $live = '/blog/what-to-check-after-the-live-link-indexation-attributes-rankings';
        $aeo = '/blog/ai-aeo-seo-why-guest-posts-and-brand-mentions-matter';
        $buyGuide = '/blog/how-to-buy-guest-posts-on-seolinkbuildings-advertiser-guide';
        $catalog = '/marketplace';
        $how = '/how-it-works';
        $img = BlogInlineImages::publicUrl(LinkBuildingGuideBlogPost::IMAGE_ROADMAP);

        return <<<HTML
<p>Le netlinking consiste à obtenir ou placer des hyperliens d’autres sites vers le vôtre. Les moteurs s’en servent, entre autres, pour découvrir et interpréter des pages. Ce n’est pas une promesse que dix hrefs bougent une requête.</p>
<p>Acquisition: <a href="{$backlinks}">obtenir des backlinks</a>. Contributeur: <a href="{$guest}">guest posting</a>. Payant: <a href="{$sponsored}">articles sponsorisés</a>.</p>

<h2>Définition et rôle des liens</h2>
<p>Une campagne a une URL cible, une raison d’être citée, un canal vers des éditeurs, et une définition du « terminé » qui n’est pas qu’une ligne de tableur. Discovery (lien crawlable), contexte (ancre), personnes (referral). Le contenu utile reste le plancher. Garde-fous publics: politiques anti-spam, qualification de la pub, indices <code>nofollow</code>/<code>sponsored</code>/<code>ugc</code> depuis 2019.</p>

<h2>Types, gagner vs construire</h2>
<p>Éditorial, guest, sponsorisé, RP, ressource/annuaire, UGC, liens internes (pas des backlinks). Comptez domaines référents et volume à part. Si vous ne pouvez pas dire pourquoi un inconnu mettrait l’URL en favori: réparez l’URL d’abord.</p>

<h2>Tactiques</h2>
<p>Assets citables; RP digitales (parfois une mention seulement — <a href="{$aeo}">guest posts et mentions de marque</a>); guest posts sur des hôtes pertinents; pages de ressources et liens brisés; domaines concurrents triés par pertinence. Canaux: <a href="{$outreach}">marketplace vs email vs RP</a>. <a href="{$catalog}">SEOLinkBuildings</a>, <a href="{$how}">comment ça marche</a>, <a href="{$chooseSite}">choisir un éditeur</a>. Ancres et attributs: <a href="{$dofollow}">dofollow, nofollow, ancres</a>. Les liens internes marquent les hubs.</p>

<h2>Mesure, erreurs, risques</h2>
<p>Une URL primaire. Domaines, URLs live, indexation, referrals, impressions Search Console, pertes — <a href="{$live}">checklist lien live</a>. Pas « 50 backlinks » si 40 viennent d’un réseau.</p>
<p>À éviter: PBN, commentaires/profils auto, fermes, réciproques industrielles, domaines expirés, guest posting hors sujet à grande échelle, dofollow non qualifié vendu comme ranking.</p>

<h2>Stratégie durable et feuille de route</h2>
<figure>
<img src="{$img}" alt="Feuille de route netlinking: débutant, intermédiaire, avancé" loading="lazy" width="1200" height="675">
<figcaption>Apprenez la qualité d’hôte avant d’ajouter du volume.</figcaption>
</figure>
<p>Un ou deux assets entretenus. Un petit programme contributeur. Sponsoring étiqueté de temps en temps. RP quand il y a une histoire. Liens internes comme base. Commande: <a href="{$buyGuide}">acheter des guest posts</a>.</p>
<p>Débutant: une URL, liens internes, cinq citations, recontrôle. Intermédiaire: un asset, domaines concurrents, mix. Avancé: roster, localisation, revue du profil. Pas de disavow de panique.</p>

<h2>Checklist</h2>
<ul>
<li>L’URL cible mérite une citation</li>
<li>Chaque prospect a un recoupement réel</li>
<li>Gagné, contribué ou payé est clair; le payant est qualifié</li>
<li>Le mix d’ancres survit à une relecture humaine</li>
<li>Règle d’arrêt si la qualité chute</li>
</ul>

<h2>Questions fréquentes</h2>
<h3>Combien de backlinks pour ranker?</h3>
<p>Aucun quota universel. Requêtes pertinentes et referrals utiles.</p>
<h3>Les backlinks sont-ils encore un facteur?</h3>
<p>Les moteurs s’en servent encore. Google publie aussi des règles contre les schémas. Un signal parmi d’autres.</p>
<h3>Netlinking = SEO?</h3>
<p>Non. Le SEO inclut crawl, indexation, contenu, intention, liens internes, etc.</p>
<h3>Uniquement via marketplace?</h3>
<p>Vous pouvez y acheter des placements. Il faut quand même une page qui mérite le clic.</p>

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
        $backlinks = '/blog/how-to-get-backlinks';
        $guest = '/blog/guest-posting-guide';
        $sponsored = '/blog/sponsored-post-guide';
        $chooseSite = '/blog/how-to-choose-a-publisher-site-dr-da-traffic-niche';
        $dofollow = '/blog/dofollow-nofollow-and-anchor-text-for-marketplace-links';
        $outreach = '/blog/marketplace-vs-cold-outreach-vs-digital-pr';
        $live = '/blog/what-to-check-after-the-live-link-indexation-attributes-rankings';
        $aeo = '/blog/ai-aeo-seo-why-guest-posts-and-brand-mentions-matter';
        $buyGuide = '/blog/how-to-buy-guest-posts-on-seolinkbuildings-advertiser-guide';
        $catalog = '/marketplace';
        $how = '/how-it-works';
        $img = BlogInlineImages::publicUrl(LinkBuildingGuideBlogPost::IMAGE_ROADMAP);

        return <<<HTML
<p>Linkbuilding is het werk om hyperlinks van andere sites naar de jouwe te verdienen of te plaatsen. Zoekmachines gebruiken links onder meer om pagina’s te ontdekken en te duiden. Dat is de mechaniek — geen belofte dat tien nieuwe hrefs een keyword bewegen.</p>
<p>Acquisitie: <a href="{$backlinks}">zo krijg je backlinks</a>. Contributor: <a href="{$guest}">gastbloggen</a>. Betaald: <a href="{$sponsored}">gesponsorde posts</a>.</p>

<h2>Wat het is, en waarom links tellen</h2>
<p>Een campagne heeft een doel-URL, een reden om die te citeren, een pad naar publishers, en een definitie van klaar die geen spreadsheetrij is. Discovery (crawlbare <code>&lt;a href&gt;</code>), context (anker), mensen (referral). Nuttige content blijft de vloer. Publieke hekken: spambeleid, kwalificatie van reclame, hints voor <code>nofollow</code>/<code>sponsored</code>/<code>ugc</code> sinds 2019.</p>

<h2>Types, verdienen vs bouwen</h2>
<p>Redactioneel, gast, sponsored, digital PR, resource/directory, UGC, interne links (geen backlinks). Rapporteer verwijzende domeinen en ruwe counts apart. Kun je niet in één zin zeggen waarom een vreemde de URL bewaart: repareer eerst de URL.</p>

<h2>Tactieken</h2>
<p>Citeerbare assets; digital PR (soms alleen een mention — <a href="{$aeo}">gastposts en brand mentions</a>); gastposts op relevante hosts; resource- en broken-link-outreach; concurrent-domeinen op relevantie, niet op score. Kanalen: <a href="{$outreach}">marketplace vs cold email vs PR</a>. <a href="{$catalog}">SEOLinkBuildings</a>, <a href="{$how}">hoe het werkt</a>, <a href="{$chooseSite}">publisher kiezen</a>. Ankers en attributen: <a href="{$dofollow}">dofollow, nofollow, ankers</a>. Interne links markeren hubs.</p>

<h2>Meten, fouten, risico</h2>
<p>Eén primaire URL. Domeinen, live-URL’s, indexatie, referrals, Search Console-impressies, verlies — <a href="{$live}">live-linkchecklist</a>. Geen „50 backlinks” als 40 uit één netwerk komen.</p>
<p>Laat: PBN’s, automatische comments/profielen, linkfarms, industriële wederkerigheid, expired-domainnetwerken, grootschalig irrelevante guest posts, ongekwalificeerde dofollow als rankingproduct.</p>

<h2>Duurzame strategie en roadmap</h2>
<figure>
<img src="{$img}" alt="Linkbuilding-roadmap: beginner, gevorderd, advanced" loading="lazy" width="1200" height="675">
<figcaption>Leer hostkwaliteit voordat je volume toevoegt.</figcaption>
</figure>
<p>Eén of twee assets die je onderhoudt. Een klein contributorprogramma. Af en toe gelabelde sponsored plaatsingen. PR als er een verhaal is. Interne links als vloer. Bestellen: <a href="{$buyGuide}">gastposts kopen</a>.</p>
<p>Beginner: één URL, interne links, vijf citaties, recheck. Gevorderd: een asset, concurrent-domeinen, mix. Advanced: roster, lokalisatie, profiel nalopen. Geen paniek-disavow.</p>

<h2>Checklist</h2>
<ul>
<li>Doel-URL verdient een citatie</li>
<li>Elke prospect heeft overlap</li>
<li>Verdiend, contributed of betaald is duidelijk; betaald is gekwalificeerd</li>
<li>Ankermix overleeft een menselijke review</li>
<li>Stopregel als de kwaliteit daalt</li>
</ul>

<h2>Veelgestelde vragen</h2>
<h3>Hoeveel backlinks om te ranken?</h3>
<p>Geen universeel quotum. Relevante queries en nuttige referrals.</p>
<h3>Zijn backlinks nog een rankingfactor?</h3>
<p>Zoekmachines gebruiken ze nog. Google publiceert ook regels tegen schema’s. Eén signaal onder vele.</p>
<h3>Is linkbuilding hetzelfde als SEO?</h3>
<p>Nee. SEO omvat crawl, indexatie, content, intentie, interne links en meer.</p>
<h3>Alleen via een marketplace?</h3>
<p>Je kunt er plaatsingen inkopen. Je hebt nog steeds een pagina nodig die de klik verdient.</p>

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
        $backlinks = '/it/blog/come-ottenere-backlink';
        $guest = '/it/blog/cose-un-guest-post';
        $sponsored = '/it/blog/guest-post-vs-articolo-sponsorizzato';
        $chooseSite = '/blog/how-to-choose-a-publisher-site-dr-da-traffic-niche';
        $dofollow = '/it/blog/dofollow-vs-nofollow';
        $outreach = '/blog/marketplace-vs-cold-outreach-vs-digital-pr';
        $live = '/blog/what-to-check-after-the-live-link-indexation-attributes-rankings';
        $aeo = '/blog/ai-aeo-seo-why-guest-posts-and-brand-mentions-matter';
        $buy = '/it/comprare-guest-post';
        $lb = '/it/link-building';
        $catalog = '/it/mercato';
        $how = '/it/come-funziona';
        $img = BlogInlineImages::publicUrl(LinkBuildingGuideBlogPost::IMAGE_ROADMAP);

        return <<<HTML
<p>Il link building è il lavoro di ottenere o piazzare collegamenti da altri siti verso il proprio. I motori usano i link, tra le altre cose, per discovery e contesto. Non è la promessa che dieci nuovi href muovano una keyword.</p>
<p>Acquisizione: <a href="{$backlinks}">come ottenere backlink</a>. Contributor: <a href="{$guest}">cos’è un guest post</a>. Paid: <a href="{$sponsored}">articolo sponsorizzato</a>. Piattaforma: <a href="{$lb}">link building in Italia</a>.</p>

<h2>Cos’è il link building — e perché i link contano</h2>
<p>Una campagna ha una URL obiettivo, un motivo per citarla, una via verso i publisher e una definizione di “finito” che non è solo una riga Excel. I link aiutano discovery, contesto (anchor e dintorni) e persone (referral). Le linee guida Google sul contenuto utile restano la base.</p>
<p>Off-page SEO in Italia è lo stesso mestiere: citazioni esterne, non un trucco locale. “SEO off-site” e “link building white hat” descrivono metodi che reggono una review umana — non PBN e farm.</p>

<h2>Tipi, guadagnare vs comprare</h2>
<p>Editoriale, guest post, sponsored, digital PR, resource/directory, UGC, link interni (non sono backlink, ma fanno parte della strategia). Referring domain e volume vanno riportati a parte. Se in una frase non spieghi perché uno sconosciuto salverebbe l’URL: sistema prima la pagina.</p>

<h2>Tattiche</h2>
<p>Asset di contenuto; digital PR (a volte solo una menzione — <a href="{$aeo}">guest post e brand mention</a>); guest post su host pertinenti; resource e broken-link outreach; referring domain dei competitor ordinati per rilevanza, non per score. Outreach: <a href="{$outreach}">marketplace vs cold email vs PR</a>. Catalogo: <a href="{$catalog}">SEOLinkBuildings</a>, <a href="{$how}">come funziona</a>, <a href="{$chooseSite}">scegliere il publisher</a>.</p>
<p>Ancore: prima la frase, poi le parole cliccabili. Attributi: <a href="{$dofollow}">dofollow vs nofollow</a>. I link interni sono il modo più economico di marcare gli hub.</p>

<h2>Misurare, errori, rischio</h2>
<p>Una URL primaria. Referring domain, URL live con attributi, indicizzazione, referral, impressioni in Search Console, perdite — <a href="{$live}">checklist dopo il live</a>. Niente “50 backlink” se 40 arrivano da un network.</p>
<p>Lascia: PBN, commenti/profili automatici, link farm, reciprocal industriale, expired-domain net, guest posting irrilevante su larga scala con ancore keyword, pacchetti dofollow non qualificati.</p>

<h2>Roadmap</h2>
<figure>
<img src="{$img}" alt="Roadmap di link building: principianti, intermedio, avanzato" loading="lazy" width="1200" height="675">
<figcaption>Impara la qualità dell’host prima di aggiungere volume.</figcaption>
</figure>
<p>Uno o due asset che mantieni. Un piccolo programma contributor. Di tanto in tanto pubblicazioni sponsored etichettate. PR se c’è una storia. Link interni come pavimento. Ordinare: <a href="{$buy}">acquistare guest post</a>.</p>

<h2>Domande frequenti</h2>
<h3>Quanti backlink per rankare?</h3>
<p>Nessuna quota universale. Query pertinenti e referral utili.</p>
<h3>I backlink sono ancora un fattore?</h3>
<p>I motori li usano ancora. Google pubblica anche regole contro gli schemi. Un segnale tra tanti.</p>
<h3>Link building = SEO?</h3>
<p>No. La SEO include crawl, indicizzazione, contenuto, intento, link interni e altro.</p>
<h3>Solo tramite marketplace?</h3>
<p>Puoi comprare pubblicazioni. Ti serve comunque una pagina che meriti il click.</p>
HTML;
    }
}
