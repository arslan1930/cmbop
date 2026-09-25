<?php

namespace App\Support;

/**
 * DE/FR/NL bodies for the guest-posting-guide pillar.
 */
class GuestPostingGuideI18n
{
    /**
     * @return array<string, array{title: string, slug: string, excerpt: string, content: string, meta_title: string, meta_description: string}>
     */
    public static function all(): array
    {
        return [
            'de' => [
                'title' => 'Was ist ein Gastbeitrag: Leitfaden für Guest Blogging und SEO',
                'slug' => 'was-ist-ein-gastbeitrag',
                'excerpt' => 'Was ein Gastbeitrag ist, wie Sie passende Hosts finden, pitchen und schreiben — und Netzwerke meiden, die nur Links verkaufen.',
                'meta_title' => 'Was ist ein Gastbeitrag? Pitch, Text und Publikation',
                'meta_description' => 'Was ist ein Gastbeitrag: Publisher finden, pitchen, für deren Leser schreiben, Anker setzen und minderwertige Guest-Post-Netze meiden.',
                'content' => self::de(),
            ],
            'fr' => [
                'title' => 'Guest posting: un guide complet du guest blogging pour le SEO',
                'slug' => 'guide-guest-posting',
                'excerpt' => 'Comment le guest posting fonctionne vraiment: trouver des hôtes pertinents, pitcher, écrire pour leurs lecteurs, et éviter les réseaux qui ne vendent qu’un lien.',
                'meta_title' => 'Guest posting: pitcher, écrire et publier pour le SEO',
                'meta_description' => 'Guide guest posting: trouver des éditeurs, pitcher, écrire pour leurs lecteurs, gérer les ancres, et éviter les réseaux low-cost.',
                'content' => self::fr(),
            ],
            'nl' => [
                'title' => 'Gastbloggen: een complete gids voor guest posting en SEO',
                'slug' => 'gastbloggen-gids',
                'excerpt' => 'Hoe gastposts écht werken: passende hosts vinden, pitchen, voor hun lezers schrijven, en netwerken mijden die alleen een link verkopen.',
                'meta_title' => 'Gastblog-gids: pitchen, schrijven en plaatsen voor SEO',
                'meta_description' => 'Praktische gastblog-gids: publishers vinden, pitchen, voor hun lezers schrijven, ankers zetten, en goedkope guest-postnetwerken overslaan.',
                'content' => self::nl(),
            ],
            'it' => [
                'title' => 'Cos’è un guest post: guida al guest blogging per il SEO',
                'slug' => 'cose-un-guest-post',
                'excerpt' => 'Come funziona davvero un guest post: trovare host pertinenti, proporre il pezzo, scrivere per i loro lettori e evitare i network che vendono solo un link.',
                'meta_title' => 'Cos’è un guest post: pitch, testo e pubblicazione SEO',
                'meta_description' => 'Guida al guest post: cosa sono, come si pitchano, come si scrivono e come si evita di comprare reti low-cost. CTA al catalogo SEOLinkBuildings.',
                'content' => self::it(),
            ],
        ];
    }

    private static function de(): string
    {
        $backlinks = '/de/blog/was-sind-backlinks';
        $sponsored = '/de/blog/gesponserte-beitraege-leitfaden';
        $linkGuide = '/de/blog/linkaufbau-strategien';
        $chooseSite = '/blog/how-to-choose-a-publisher-site-dr-da-traffic-niche';
        $buyGuide = '/blog/how-to-buy-guest-posts-on-seolinkbuildings-advertiser-guide';
        $brief = '/blog/guest-post-brief-anchors-urls-images-sensitive-topics';
        $dofollow = '/de/blog/dofollow-vs-nofollow-ankertext';
        $outreach = '/blog/marketplace-vs-cold-outreach-vs-digital-pr';
        $europe = '/blog/buy-guest-posts-in-europe-how-to-choose-publisher-sites';
        $ukus = '/blog/guest-posting-in-the-uk-and-us-what-to-buy-and-what-to-skip';
        $live = '/blog/what-to-check-after-the-live-link-indexation-attributes-rankings';
        $catalog = '/de/marktplatz';
        $how = '/de/so-funktioniert-es';
        $buyDe = '/de/gastbeitrag-kaufen';
        $dofollowDe = '/de/blog/dofollow-vs-nofollow-ankertext';
        $img = BlogInlineImages::publicUrl(GuestPostingGuideBlogPost::IMAGE_WORKFLOW);

        return <<<HTML
<p>Ein Gastbeitrag ist ein Artikel auf einer Website, die Sie nicht besitzen — meist mit Byline und, wenn der Host es erlaubt, einem Link zurück.</p>
<p>Die Definition ist einfach. Die Arbeit nicht. Die meisten Kampagnen scheitern an der Host-Auswahl, nicht am Schreibtalent.</p>
<p>Beschaffung insgesamt: <a href="{$backlinks}">So bekommen Sie Backlinks</a>. Bezahlte Native Ads: <a href="{$sponsored}">gesponserte Beiträge</a>. Hier der Workflow.</p>

<h2>Was ist ein Gastbeitrag?</h2>
<p>Sie liefern den Text, der Host veröffentlicht ihn für sein Publikum. Der Host behält URL, Traffic und das Recht zu kürzen oder abzulehnen. Das ist kein unabhängiges Zitat und nicht automatisch ein Advertorial. Manche Hosts nehmen unbezahlte Beiträge; manche berechnen eine Gebühr. Die kommerzielle Realität ehrlich benennen. Googles Spam-Richtlinien nennen großflächiges Guest Posting mit keyword-reichen Ankern als Muster — das Format ist nicht verboten, das Manipulationsmuster schon.</p>

<h2>Ablauf</h2>
<ol>
<li>Eine Ziel-URL auf Ihrer Site wählen</li>
<li>Hosts mit overlapping Leserschaft kurzlisten</li>
<li>Pitchen, „Write for us“ nutzen oder ein Listing mit geschriebenen Regeln bestellen</li>
<li>Constraints lesen (Länge, Links, Ton, Bilder, Disclosure)</li>
<li>Schreiben, lektorieren lassen, live gehen</li>
<li>Live-URL, Attribut, Anker und Recheck-Datum speichern</li>
</ol>

<h2>Nutzen und Grenzen</h2>
<p>Auf Sites mit echten Lesern: Sichtbarkeit, eine crawlable Zitation, ein öffentliches Schreibsample. Kein Ranking-Versprechen, kein Ersatz für schwache Landingpages. Googles Spam-Richtlinien nennen großflächiges Guest Posting mit keyword-reichen Ankern als Muster. Das Format ist nicht verboten. Das Muster zur Ranking-Manipulation schon.</p>

<h2>Hosts finden und bewerten</h2>
<p>Suche wie ein Editor (<em>write for us [Thema]</em>), verweisende Domains von Wettbewerbern, Kataloge mit Filtern — etwa <a href="{$catalog}">SEOLinkBuildings</a> als Vergleichsschicht, kein Qualitätssiegel. Ablauf: <a href="{$how}">So funktioniert es</a>, <a href="{$buyGuide}">Kaufleitfaden</a>. Land und Sprache zählen: <a href="{$europe}">Europa</a>, <a href="{$ukus}">UK und US</a>.</p>
<p>Themen- und Publikumsoverlap, Traffic als Plausibilität, DR/DA nur als Filter — <a href="{$chooseSite}">Publisher wählen</a>. Kontakt über die vom Host veröffentlichte Adresse, nicht über geratene CC-Listen.</p>

<h2>Pitchen und schreiben</h2>
<p>Warum genau diese Site, eine Idee, Arbeitstitel, zwei Sätze Outline, eine Zeile zu Ihnen, Exklusivität. Kein „loved your blog“. Kanäle: <a href="{$outreach}">Marketplace vs. Outreach vs. PR</a>. Schreiben Sie für deren Leser. Eine primäre URL. Brief: <a href="{$brief}">Anker, URLs, Bilder, sensible Themen</a>.</p>
<p>Anker wie ein Mensch: Brand, URL, beschreibende Phrase. Attribute: <a href="{$dofollow}">Dofollow, Nofollow, Ankertexte</a>. Bezahlt? Dann <a href="{$sponsored}">gesponsert kennzeichnen</a>.</p>

<h2>Warnsignale und Workflow</h2>
<figure>
<img src="{$img}" alt="Gastbeitrags-Workflow: Sites finden, bewerten, pitchen, schreiben, publizieren, Live-URL prüfen" loading="lazy" width="1200" height="675">
<figcaption>Nicht skalieren, bevor die ersten Live-URLs indexiert sind und zum Brief passen.</figcaption>
</figure>
<p>Warnsignale: dieselben drei Outbound-Partner in jedem Artikel, keine Autoren, Casino plus CBD plus Küche, „Write for us“ nur über DA. PBNs: URL erst nach Zahlung? Gehen Sie. Nach dem Live-Gang: <a href="{$live}">Live-Link prüfen</a>. Strategie-Rahmen: <a href="{$linkGuide}">Linkbuilding</a>.</p>

<h2>Publisher-Checkliste</h2>
<ul>
<li>Ich kann die Zielgruppe in einem Satz nennen</li>
<li>Aktuelle Posts sind original und thematisch</li>
<li>Outbound-Links sehen nicht nach Farm aus</li>
<li>URL indexierbar; Attribut bekannt; Landingpage verdient den Klick</li>
</ul>

<h2>Häufig gestellte Fragen</h2>
<h3>Ist Guest Posting noch nützlich?</h3>
<p>Auf Hosts mit Lesern und Standards ja. Auf Netzen, die denselben Artikel an hundert Blogs verkaufen, nein. Die Host-Qualität ist die Taktik.</p>
<h3>Soll ich für einen Gastbeitrag zahlen?</h3>
<p>Zahlen macht den Text nicht gut oder schlecht. Es ändert, wie der Link gekennzeichnet werden sollte.</p>
<h3>Welchen Ankertext?</h3>
<p>Einen Satz, den ein Mensch schreiben würde. Exact-Match auf jeder Platzierung ist ein Muster, vor dem Google warnt.</p>
<h3>Denselben Artikel mehrfach publizieren?</h3>
<p>Meist nein. Editoren erwarten Original. Duplicate-Seiten konkurrieren miteinander.</p>

<h2>Quellen</h2>
<ul>
<li><a href="https://developers.google.com/search/docs/essentials/spam-policies">Google Search Central — Spam-Richtlinien</a></li>
<li><a href="https://developers.google.com/search/blog/2019/09/evolving-nofollow-new-ways-to-identify">Evolving nofollow (2019)</a></li>
<li><a href="https://developers.google.com/search/blog/2021/07/link-tagging-and-link-spam-update">Qualifying links (2021)</a></li>
<li><a href="https://developers.google.com/search/docs/crawling-indexing/links-crawlable">Crawlable links</a></li>
<li><a href="https://developers.google.com/search/docs/fundamentals/creating-helpful-content">Helpful content</a></li>
</ul>
<p>Wenn Sie eine Veröffentlichung kaufen statt pitchen: <a href="{$buyDe}">Gastbeitrag kaufen</a> im Marktplatz, mit EUR-Wallet und Live-URL. Dofollow, Nofollow und rel sponsored: <a href="{$dofollowDe}">Dofollow vs. Nofollow</a>.</p>
HTML;
    }

    private static function fr(): string
    {
        $backlinks = '/blog/how-to-get-backlinks';
        $sponsored = '/blog/sponsored-post-guide';
        $linkGuide = '/blog/link-building-guide';
        $chooseSite = '/blog/how-to-choose-a-publisher-site-dr-da-traffic-niche';
        $buyGuide = '/blog/how-to-buy-guest-posts-on-seolinkbuildings-advertiser-guide';
        $brief = '/blog/guest-post-brief-anchors-urls-images-sensitive-topics';
        $dofollow = '/blog/dofollow-nofollow-and-anchor-text-for-marketplace-links';
        $outreach = '/blog/marketplace-vs-cold-outreach-vs-digital-pr';
        $europe = '/blog/buy-guest-posts-in-europe-how-to-choose-publisher-sites';
        $ukus = '/blog/guest-posting-in-the-uk-and-us-what-to-buy-and-what-to-skip';
        $live = '/blog/what-to-check-after-the-live-link-indexation-attributes-rankings';
        $catalog = '/marketplace';
        $how = '/how-it-works';
        $img = BlogInlineImages::publicUrl(GuestPostingGuideBlogPost::IMAGE_WORKFLOW);

        return <<<HTML
<p>Un guest post est un article sur un site que vous ne possédez pas, en général avec une signature et, si l’hôte l’autorise, un lien retour.</p>
<p>La définition est simple. Le travail ne l’est pas. La plupart des campagnes échouent sur le choix de l’hôte, pas sur le talent d’écriture.</p>
<p>Vue d’ensemble: <a href="{$backlinks}">comment obtenir des backlinks</a>. Placements payants: <a href="{$sponsored}">articles sponsorisés</a>.</p>

<h2>Qu’est-ce que le guest posting?</h2>
<p>Vous fournissez le texte, l’hôte le publie pour son audience. Ce n’est pas une citation indépendante, et ce n’est pas forcément un publireportage. Dites la vérité commerciale.</p>

<h2>Déroulement</h2>
<ol>
<li>Choisir une URL cible chez vous</li>
<li>Lister des hôtes dont les lecteurs se recoupent</li>
<li>Pitch, page « write for us », ou commande marketplace avec règles écrites</li>
<li>Lire les contraintes</li>
<li>Écrire, relire, mettre en ligne</li>
<li>Enregistrer l’URL live, l’attribut, l’ancre, une date de recontrôle</li>
</ol>

<h2>Bénéfices et limites</h2>
<p>Sur un vrai média: visibilité, citation crawlable, écrit public. Pas de promesse de ranking. Les politiques anti-spam de Google citent le guest posting à grande échelle avec ancres riches en mots-clés. Le format n’est pas interdit. Le schéma de manipulation l’est.</p>

<h2>Trouver et juger un hôte</h2>
<p>Cherchez comme un éditeur, exportez les domaines des concurrents, comparez des listings — <a href="{$catalog}">SEOLinkBuildings</a> est une couche de découverte, pas un tampon qualité. <a href="{$how}">Comment ça marche</a>, <a href="{$buyGuide}">guide acheteur</a>. Pays et langue: <a href="{$europe}">Europe</a>, <a href="{$ukus}">UK et US</a>. Métriques: <a href="{$chooseSite}">choisir un éditeur</a>.</p>

<h2>Pitch et rédaction</h2>
<p>Pourquoi ce site, une idée, un titre, deux phrases, qui vous êtes, exclusivité. Canaux: <a href="{$outreach}">marketplace vs outreach vs RP</a>. Écrivez pour leurs lecteurs. Une URL principale. Brief: <a href="{$brief}">ancres, URLs, images, sujets sensibles</a>. Attributs: <a href="{$dofollow}">dofollow, nofollow, ancres</a>.</p>

<h2>Signaux d’alerte et workflow</h2>
<figure>
<img src="{$img}" alt="Workflow guest post: trouver, évaluer, pitcher, écrire, publier, revérifier l’URL live" loading="lazy" width="1200" height="675">
<figcaption>Ne scalez pas tant que les premières URLs live ne tiennent pas.</figcaption>
</figure>
<p>Mêmes trois partenaires sortants dans chaque article, pas d’auteurs, catégories fourre-tout, « write for us » qui ne parle que de DA. Après publication: <a href="{$live}">contrôle du lien live</a>. Cadre: <a href="{$linkGuide}">netlinking</a>.</p>

<h2>Checklist éditeur</h2>
<ul>
<li>Je peux nommer l’audience en une phrase</li>
<li>Les derniers posts sont originaux et on-topic</li>
<li>Les liens sortants ne ressemblent pas à une ferme</li>
<li>Page indexable; attribut connu; la landing mérite le clic</li>
</ul>

<h2>Questions fréquentes</h2>
<h3>Le guest posting sert-il encore?</h3>
<p>Oui sur des hôtes avec lecteurs et standards. Non sur des réseaux qui vendent la même forme d’article à cent blogs.</p>
<h3>Faut-il payer?</h3>
<p>Payer ne rend pas l’article bon ou mauvais. Ça change le marquage du lien.</p>
<h3>Quelle ancre?</h3>
<p>Une phrase humaine. L’exact-match partout est un schéma que Google décrit depuis des années.</p>
<h3>Republier le même texte?</h3>
<p>En général non. Les éditeurs attendent de l’original.</p>

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
        $sponsored = '/blog/sponsored-post-guide';
        $linkGuide = '/blog/link-building-guide';
        $chooseSite = '/blog/how-to-choose-a-publisher-site-dr-da-traffic-niche';
        $buyGuide = '/blog/how-to-buy-guest-posts-on-seolinkbuildings-advertiser-guide';
        $brief = '/blog/guest-post-brief-anchors-urls-images-sensitive-topics';
        $dofollow = '/blog/dofollow-nofollow-and-anchor-text-for-marketplace-links';
        $outreach = '/blog/marketplace-vs-cold-outreach-vs-digital-pr';
        $europe = '/blog/buy-guest-posts-in-europe-how-to-choose-publisher-sites';
        $ukus = '/blog/guest-posting-in-the-uk-and-us-what-to-buy-and-what-to-skip';
        $live = '/blog/what-to-check-after-the-live-link-indexation-attributes-rankings';
        $catalog = '/marketplace';
        $how = '/how-it-works';
        $img = BlogInlineImages::publicUrl(GuestPostingGuideBlogPost::IMAGE_WORKFLOW);

        return <<<HTML
<p>Een gastpost is een artikel op een site die je niet bezit, meestal met byline en, als de host het toestaat, een link terug.</p>
<p>De definitie is simpel. Het werk niet. De meeste campagnes stranden op hostkeuze, niet op schrijftalent.</p>
<p>Overzicht: <a href="{$backlinks}">zo krijg je backlinks</a>. Betaalde native ads: <a href="{$sponsored}">gesponsorde posts</a>.</p>

<h2>Wat is guest posting?</h2>
<p>Jij levert de tekst, de host publiceert hem voor zijn publiek. Dat is geen onafhankelijke citatie en niet automatisch een advertorial. Benoem de commerciële realiteit.</p>

<h2>Werkwijze</h2>
<ol>
<li>Kies één doel-URL</li>
<li>Shortlist hosts met overlapping lezers</li>
<li>Pitch, „write for us”, of een listing met geschreven regels</li>
<li>Lees de constraints</li>
<li>Schrijf, laat redigeren, ga live</li>
<li>Bewaar live-URL, attribuut, anker, recheckdatum</li>
</ol>

<h2>Nut en grenzen</h2>
<p>Op sites met echte lezers: zichtbaarheid, een crawlbare citatie, een publiek schrijfsample. Geen rankingbelofte. Google noemt grootschalig guest posting met keyword-rijke ankers als spam-patroon. Het format is niet verboden. Het manipulatieve patroon wel.</p>

<h2>Hosts vinden en beoordelen</h2>
<p>Zoek als een redacteur, exporteer concurrent-domeinen, vergelijk listings — <a href="{$catalog}">SEOLinkBuildings</a> is ontdekking, geen keurmerk. <a href="{$how}">Hoe het werkt</a>, <a href="{$buyGuide}">koopgids</a>. Land en taal: <a href="{$europe}">Europa</a>, <a href="{$ukus}">VK en VS</a>. Metrics: <a href="{$chooseSite}">een publisher kiezen</a>.</p>

<h2>Pitchen en schrijven</h2>
<p>Waarom deze site, één idee, werktitel, twee zinnen, wie je bent, exclusiviteit. Kanalen: <a href="{$outreach}">marketplace vs outreach vs PR</a>. Schrijf voor hun lezer. Eén primaire URL. Brief: <a href="{$brief}">ankers, URL’s, beelden, gevoelige topics</a>. Attributen: <a href="{$dofollow}">dofollow, nofollow, ankers</a>.</p>

<h2>Waarschuwingssignalen en workflow</h2>
<figure>
<img src="{$img}" alt="Gastpost-workflow: sites vinden, beoordelen, pitchen, schrijven, publiceren, live-URL controleren" loading="lazy" width="1200" height="675">
<figcaption>Schaal niet voordat de eerste live-URL’s standhouden.</figcaption>
</figure>
<p>Dezelfde drie outbound-partners in elk stuk, geen auteurs, casino plus CBD plus keuken, „write for us” dat alleen over DA praat. Na live: <a href="{$live}">live-linkchecklist</a>. Kader: <a href="{$linkGuide}">linkbuilding</a>.</p>

<h2>Publisher-checklist</h2>
<ul>
<li>Ik kan de doelgroep in één zin noemen</li>
<li>Recente posts zijn origineel en on-topic</li>
<li>Outbound links lijken niet op een farm</li>
<li>Pagina indexeerbaar; attribuut bekend; landingpage verdient de klik</li>
</ul>

<h2>Veelgestelde vragen</h2>
<h3>Is guest posting nog nuttig?</h3>
<p>Op hosts met lezers en standaarden ja. Op netwerken die hetzelfde artikel aan honderd blogs verkopen nee.</p>
<h3>Moet ik betalen?</h3>
<p>Betalen maakt de tekst niet goed of slecht. Het verandert hoe de link gelabeld moet worden.</p>
<h3>Welk anker?</h3>
<p>Een zin die een mens zou schrijven. Exact-match overal is een patroon waar Google voor waarschuwt.</p>
<h3>Hetzelfde artikel herpubliceren?</h3>
<p>Meestal niet. Redacteuren verwachten origineel werk.</p>

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
        $sponsored = '/it/blog/guest-post-vs-articolo-sponsorizzato';
        $linkGuide = '/it/blog/come-fare-link-building';
        $chooseSite = '/blog/how-to-choose-a-publisher-site-dr-da-traffic-niche';
        $buy = '/it/comprare-guest-post';
        $brief = '/blog/guest-post-brief-anchors-urls-images-sensitive-topics';
        $dofollow = '/it/blog/dofollow-vs-nofollow';
        $outreach = '/blog/marketplace-vs-cold-outreach-vs-digital-pr';
        $europe = '/blog/buy-guest-posts-in-europe-how-to-choose-publisher-sites';
        $live = '/blog/what-to-check-after-the-live-link-indexation-attributes-rankings';
        $catalog = '/it/mercato';
        $how = '/it/come-funziona';
        $img = BlogInlineImages::publicUrl(GuestPostingGuideBlogPost::IMAGE_WORKFLOW);

        return <<<HTML
<p>Un guest post è un articolo su un sito che non possiedi — di solito con byline e, se l’host lo consente, un link di ritorno.</p>
<p>La definizione è semplice. Il lavoro no. La maggior parte delle campagne fallisce sulla scelta dell’host, non sul talento di scrittura.</p>
<p>Acquisizione: <a href="{$backlinks}">come ottenere backlink</a>. Pubblicazioni a pagamento: <a href="{$sponsored}">guest post vs articolo sponsorizzato</a>. Qui il flusso.</p>

<h2>Cosa sono i guest post</h2>
<p>Consegni il testo, l’host lo pubblica per i suoi lettori. Tiene URL, traffico e il diritto di tagliare o rifiutare. Non è una citazione indipendente e non è automaticamente un pubbliredazionale. Alcuni host prendono pezzi non pagati; altri fanno pagare. Va detto.</p>

<h2>Flusso</h2>
<ol>
<li>Scegliere una URL di destinazione sul proprio sito</li>
<li>Shortlist di host con lettori in comune</li>
<li>Pitch, “write for us”, o un listing con regole scritte</li>
<li>Leggere vincoli (lunghezza, link, tono, immagini, disclosure)</li>
<li>Scrivere, far revisionare, andare live</li>
<li>Salvare URL live, attributo, anchor, data di ricontrollo</li>
</ol>

<h2>A cosa serve — e i limiti</h2>
<p>Su siti con lettori veri: visibilità, una citazione crawlable, un sample pubblico. Nessuna promessa di ranking. Le spam policy di Google citano il guest posting su larga scala con ancore keyword-rich. Il formato non è vietato. Il pattern per manipolare i ranking sì.</p>

<h2>Trovare e valutare gli host</h2>
<p>Cerca come un editor, esporta i referring domain dei competitor, confronta i listing — <a href="{$catalog}">il catalogo SEOLinkBuildings</a> è scoperta, non un bollino di qualità. <a href="{$how}">Come funziona</a>, <a href="{$buy}">acquistare guest post</a>. Europa: <a href="{$europe}">scegliere i publisher</a>. Metriche: <a href="{$chooseSite}">DA, DR, traffico</a> — ZA SEOZoom non è una colonna del listing.</p>

<h2>Pitch e testo</h2>
<p>Perché questo sito, un’idea, titolo di lavoro, due frasi, chi sei, esclusività. Canali: <a href="{$outreach}">marketplace vs outreach vs PR</a>. Scrivi per il loro lettore. Una URL primaria. Brief: <a href="{$brief}">ancore, URL, immagini</a>. Attributi: <a href="{$dofollow}">dofollow, nofollow, rel sponsored</a>.</p>

<h2>Segnali d’allarme e workflow</h2>
<figure>
<img src="{$img}" alt="Workflow del guest post: trovare siti, valutare, pitchare, scrivere, pubblicare, controllare l’URL live" loading="lazy" width="1200" height="675">
<figcaption>Non scalare prima che i primi URL live restino in piedi.</figcaption>
</figure>
<p>Sempre gli stessi tre partner outbound, niente autori, casino più CBD più cucina, “write for us” che parla solo di DA. Dopo la live: <a href="{$live}">checklist del link</a>. Quadro: <a href="{$linkGuide}">come fare link building</a>.</p>

<h2>Checklist publisher</h2>
<ul>
<li>So dire il pubblico in una frase</li>
<li>I post recenti sono originali e in tema</li>
<li>Gli outbound non sembrano una farm</li>
<li>Pagina indicizzabile; attributo noto; la landing merita il click</li>
</ul>

<h2>Domande frequenti</h2>
<h3>Il guest posting è ancora utile?</h3>
<p>Su host con lettori e standard, sì. Sui network che vendono lo stesso pezzo a cento blog, no.</p>
<h3>Devo pagare?</h3>
<p>Pagare non rende il testo buono o cattivo. Cambia come va etichettato il link. In Italia si cerca anche “guest post a pagamento”: è la stessa domanda commerciale.</p>
<h3>Quale anchor?</h3>
<p>Una frase che scriverebbe una persona. Exact-match ovunque è un pattern su cui Google avverte.</p>
<h3>Ripubblicare lo stesso articolo?</h3>
<p>Di solito no. Gli editori si aspettano lavoro originale.</p>

<h2>Fonti</h2>
<ul>
<li><a href="https://developers.google.com/search/docs/essentials/spam-policies">Spam policies di Google</a></li>
<li><a href="https://developers.google.com/search/blog/2019/09/evolving-nofollow-new-ways-to-identify">Evolving nofollow (2019)</a></li>
<li><a href="https://developers.google.com/search/blog/2021/07/link-tagging-and-link-spam-update">Qualifying links (2021)</a></li>
<li><a href="https://developers.google.com/search/docs/crawling-indexing/links-crawlable">Link crawlable</a></li>
<li><a href="https://developers.google.com/search/docs/fundamentals/creating-helpful-content">Contenuti utili</a></li>
</ul>
HTML;
    }
}
