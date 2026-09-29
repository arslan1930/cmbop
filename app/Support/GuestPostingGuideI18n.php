<?php

namespace App\Support;

/**
 * Localized bodies for the guest-posting-guide pillar.
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
                'title' => 'Was ist ein Gastbeitrag?',
                'slug' => 'was-ist-ein-gastbeitrag',
                'excerpt' => 'Ein Gastbeitrag ist ein Artikel auf einer fremden Website. Der Text erklärt das Format, die Kennzeichnung und worauf Redaktionen in Deutschland, Österreich und der Schweiz achten.',
                'meta_title' => 'Was ist ein Gastbeitrag? Definition, Ablauf und Grenzen',
                'meta_description' => 'Was ist ein Gastbeitrag: ein Artikel auf einer fremden Website, oft mit Link. Unterschied zu Advertorial und Linkkauf, und was Redaktionen im DACH-Raum prüfen.',
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
                'title' => 'Cos’è un guest post?',
                'slug' => 'cose-un-guest-post',
                'excerpt' => 'Un guest post è un articolo su un sito altrui. La pagina distingue il formato da advertorial e acquisto del solo link, e dice cosa controlla una redazione.',
                'meta_title' => 'Cos’è un guest post? Definizione, i passaggi e i limiti',
                'meta_description' => 'Cos’è un guest post: un articolo su un sito altrui, spesso con un link. Differenza da advertorial e acquisto link, e cosa controlla una redazione.',
                'content' => self::it(),
            ],
        ];
    }

    /**
     * Heuristic keyword record for glossary audit. Scores are copied from the
     * market research files (24 Sep 2026). They are not Semrush volume or KD.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function seoRecords(): array
    {
        $de = self::all()['de'];
        $it = self::all()['it'];

        return [
            'it' => [
                'language' => 'it',
                'country' => 'IT',
                'topic' => 'Cos’è un guest post',
                'primary_keyword' => 'cos\'è un guest post',
                'secondary_keywords' => [
                    'cosa sono i guest post',
                ],
                'long_tail_keywords' => [
                    'guest post SEO guida',
                    'anchor text guest post',
                ],
                'search_intent' => 'informational',
                'difficulty' => '50 Medium',
                'difficulty_note' => 'Heuristic 0–100 from italy-keyword-research, not Semrush KD.',
                'demand_band' => 'High',
                'traffic_potential' => 55,
                'traffic_note' => 'Heuristic 0–100, not monthly visits.',
                'opportunity_score' => 48,
                'competition' => 'Medium',
                'business_fit' => 'Low',
                'effort' => 'L',
                'seo_title' => $it['meta_title'],
                'meta_description' => $it['meta_description'],
                'url_slug' => $it['slug'],
                'h1' => $it['title'],
                'word_count' => self::plainWordCount($it['content']),
                'money_url' => '/it/comprare-guest-post',
                'sister_urls' => [
                    '/it/blog/come-ottenere-backlink',
                    '/it/blog/guest-post-vs-articolo-sponsorizzato',
                    '/it/blog/dofollow-vs-nofollow',
                ],
                'image' => [
                    'filename' => 'guest-posting-guide-workflow-it.svg',
                    'alt' => 'Passaggi di un guest post: trovare siti, valutare, proporre, scrivere, pubblicare e ricontrollare',
                    'caption' => 'L’host tiene l’indirizzo. Chi scrive consegna il testo e, dopo la pubblicazione, ricontrolla attributo e pagina di destinazione.',
                    'ai_generated' => false,
                    'note' => 'Authored Italian SVG. Not a photograph and not an AI image.',
                ],
            ],
            'de' => [
                'language' => 'de',
                'country' => 'DE',
                'topic' => 'Was ist ein Gastbeitrag',
                'primary_keyword' => 'was ist ein gastbeitrag',
                'secondary_keywords' => [
                    'dofollow vs nofollow',
                    'dofollow',
                    'was sind backlinks',
                    'gastbeitrag schreiben tipps',
                    'anchor text strategie',
                    'rel sponsored bedeutung',
                ],
                'long_tail_keywords' => [
                    'was ist ein gastbeitrag seo',
                    'was ist ein gastbeitrag ch',
                    'gastbeitrag ss schreibweise schweiz',
                ],
                'search_intent' => 'informational',
                'difficulty' => '25 Easy',
                'difficulty_note' => 'Heuristic 0–100 from germany-keyword-research, not Semrush KD.',
                'demand_band' => 'Medium',
                'traffic_potential' => 40,
                'traffic_note' => 'Heuristic 0–100, not monthly visits.',
                'opportunity_score' => 56,
                'competition' => 'Low',
                'seo_title' => $de['meta_title'],
                'meta_description' => $de['meta_description'],
                'url_slug' => $de['slug'],
                'h1' => $de['title'],
                'word_count' => self::plainWordCount($de['content']),
                'money_url' => '/de/gastbeitrag-kaufen',
                'sister_urls' => [
                    '/de/blog/was-sind-backlinks',
                    '/de/blog/gesponserte-beitraege-leitfaden',
                    '/de/blog/dofollow-vs-nofollow-ankertext',
                ],
                'image' => [
                    'filename' => 'guest-posting-guide-workflow-de.svg',
                    'alt' => 'Ablauf eines Gastbeitrags von der Themenwahl über die Host-Prüfung bis zur Live-URL',
                    'caption' => 'Der Host behält die URL. Der Autor liefert den Text und prüft danach Attribut und Zielseite.',
                    'ai_generated' => false,
                    'note' => 'Authored German SVG. Not a photograph and not an AI image.',
                ],
            ],
        ];
    }

    public static function plainWordCount(string $html): int
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');
        if ($text === '') {
            return 0;
        }

        return count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    private static function de(): string
    {
        $backlinks = '/de/blog/was-sind-backlinks';
        $sponsored = '/de/blog/gesponserte-beitraege-leitfaden';
        $dofollow = '/de/blog/dofollow-vs-nofollow-ankertext';
        $buyDe = '/de/gastbeitrag-kaufen';
        $img = BlogLocaleImages::publicUrl(GuestPostingGuideBlogPost::IMAGE_WORKFLOW, 'de');

        return <<<HTML
<p class="glossary-definition">Ein Gastbeitrag ist ein redaktioneller Artikel, den eine Person oder ein Unternehmen auf einer fremden Website veröffentlicht. Der Host behält die Adresse und die Leser. Der Autor liefert den Text und steht in der Regel mit Namen darunter. Oft führt ein Link auf die eigene Seite. Das Format ist kein Advertorial und kein unabhängiges Zitat.</p>
<p>Die Suche „Was ist ein Gastbeitrag“ meint genau diese Abgrenzung. Wer einen Pitch an ein Fachportal schickt oder eine Platzierung vergleicht, muss zuerst wissen, welches Format er vor sich hat. Danach erst lohnt die Frage, ob der Text für die Leser dieser Seite taugt und wie der Link gekennzeichnet wird.</p>

<nav aria-label="Inhalt">
<p><strong>Inhalt</strong></p>
<ol>
<li><a href="#gastbeitrag-oder-nicht">Was das Format ist und was nicht</a></li>
<li><a href="#wozu">Wozu Redaktionen einen Gastbeitrag nutzen</a></li>
<li><a href="#ablauf">So läuft eine Platzierung ab</a></li>
<li><a href="#rel">Dofollow, Nofollow und rel sponsored</a></li>
<li><a href="#host">Woran Sie einen tauglichen Host erkennen</a></li>
<li><a href="#dach">Deutschland, Österreich, Schweiz</a></li>
<li><a href="#preis">Warum es keinen Einheitspreis gibt</a></li>
<li><a href="#fehler">Typische Fehler</a></li>
<li><a href="#fragen">Fragen</a></li>
</ol>
</nav>

<h2 id="gastbeitrag-oder-nicht">Was ist ein Gastbeitrag, und was ist er nicht?</h2>
<p>Sie liefern den Text. Der Host veröffentlicht ihn unter seiner URL, für sein Publikum, und behält das Recht zu kürzen oder abzulehnen. Das ist der redaktionelle Kern. Ein <a href="{$backlinks}">Backlink</a> kann dabei entstehen, muss es aber nicht. Manche Redaktionen nennen den Autor nur in der Byline und setzen keinen Link. Andere erlauben einen Link im Text und einen in der Autorenzeile. Beides ist noch ein Gastbeitrag.</p>
<p>Diese Formate werden im Deutschen oft in denselben Satz gepackt. Sie sind nicht austauschbar.</p>
<table>
<thead>
<tr><th>Format</th><th>Was der Leser sieht</th><th>Wer das Thema setzt</th><th>Link</th><th>Wann es passt</th></tr>
</thead>
<tbody>
<tr><td>Gastbeitrag</td><td>Autorenzeile, Ton der Rubrik</td><td>Autor schlägt vor, Host nimmt an</td><td>Oft redaktionell; bei Bezahlung gekennzeichnet</td><td>Fachtext, den die Leser auch ohne die Marke lesen würden</td></tr>
<tr><td>Advertorial</td><td>Beitrag im Look der Seite, als Werbung erkennbar</td><td>Auftraggeber</td><td>Als Werbung gekennzeichnet</td><td>Bezahlte Darstellung einer Leistung</td></tr>
<tr><td>Gesponserter Beitrag</td><td>Hinweis auf Kooperation oder Bezahlung</td><td>Gemeinsam oder vom Auftraggeber</td><td><code>rel="sponsored"</code></td><td>Wenn Geld, ein Tausch oder eine Gegenleistung den Platz möglich gemacht hat</td></tr>
<tr><td>Reiner Linkkauf</td><td>Kaum eigener Text</td><td>Käufer</td><td>Häufig ohne Kennzeichnung</td><td>Passt nicht. Das ist kein Gastbeitrag.</td></tr>
</tbody>
</table>
<p>Ein unbezahlter Gastbeitrag kann einen normalen redaktionellen Link tragen, wenn die Redaktion Thema und Link selbst verantwortet. Sobald eine Zahlung, ein Rabatt oder ein vereinbarter Tausch den Platz kauft, ist der Link bezahlt. Die Kennzeichnung folgt der Gegenleistung, nicht der Überschrift. Wie bezahlte Beiträge einzuordnen sind, steht im <a href="{$sponsored}">Leitfaden zu gesponserten Beiträgen</a>.</p>
<p>Googles Spam-Richtlinien nennen großflächiges Guest Posting mit immer gleichen, keywordreichen Ankern als Linkspam-Muster. Das Format ist damit nicht verboten. Das Muster, Links zu setzen, um Rankings zu manipulieren, schon. Ein Beitrag, den die Leser der Host-Seite brauchen, bleibt auf der sicheren Seite. Derselbe Text, an hundert Blogs verkauft, verlässt sie. Eine bessere Platzierung ist kein Bestandteil des Formats und wird hier nicht versprochen.</p>

<h2 id="wozu">Wozu Redaktionen einen Gastbeitrag nutzen</h2>
<p>Host und Autor haben nicht dasselbe Ziel. Der Beitrag funktioniert nur, wenn beide Ziele im Text sichtbar bleiben.</p>
<p>Der Host will einen Artikel, den seine Leser zu Ende lesen und der zur übrigen Seite passt. Er gibt Länge, Ton, Zahl der Links und Bildrechte vor. Er darf ablehnen, auch wenn der Pitch höflich war.</p>
<p>Der Autor will eine Bühne außerhalb der eigenen Domain: ein Fachthema erklären, eine Quelle nennen, manchmal eine Leistung vorstellen. Die saubere Stelle für die Leistung ist der Satz, in dem sie die Aussage stützt. Der Link gehört auf die Seite, die genau diesen Satz einlöst, nicht pauschal auf die Startseite.</p>
<p>Drei Situationen taugen:</p>
<ul>
<li>Ein Fachmedium hat Leser, die das Thema schon kennen, und sucht eine Außenstimme mit Praxis statt mit Produktpitch.</li>
<li>Eine Marke kann einen Fall zeigen, und die Host-Seite bedient genau diese Branche.</li>
<li>Einer Redaktion fehlt die eigene Expertise für ein Thema, und sie holt sie über eine klare Autorenzeile.</li>
</ul>
<p>Untauglich ist der Beitrag, wenn die einzige Leistung ein Link ist und der Text auf jede Domain passen würde. Dann fehlt das Publikum, für das sich der Aufwand lohnt. Sichtbarkeit bei echten Lesern und eine nachvollziehbare Quellenangabe lassen sich vor der Veröffentlichung prüfen. Ein Ranking lässt sich nicht bestellen.</p>

<h2 id="ablauf">So läuft eine Platzierung ab</h2>
<p>Gastbeitrag schreiben, so dass eine Redaktion ihn annimmt, heißt: für deren Leser schreiben, nicht für die eigene Startseite. Die Reihenfolge entscheidet mehr als Stilregeln am Rand.</p>
<ol>
<li><strong>Eine Ziel-URL festlegen.</strong> Eine Seite, die das Thema wirklich behandelt. Nicht die Startseite für jeden Beitrag.</li>
<li><strong>Hosts mit überlappender Leserschaft sammeln.</strong> Lesen Sie die Rubrik und die letzten Artikel. Achten Sie darauf, wer dort schon mit Namen geschrieben hat. Ein Katalog ist eine Vergleichsschicht, kein Qualitätssiegel.</li>
<li><strong>Regeln lesen, bevor der Pitch hinausgeht.</strong> Länge, Links, Bilder, Kennzeichnung, Exklusivität. Wer die Regeln erst nach der Zusage liest, schreibt den Text zweimal.</li>
<li><strong>Kurz pitchen.</strong> Warum diese Seite, eine Idee, ein Arbeitstitel, zwei Sätze Gliederung, eine Zeile zur eigenen Rolle. Kein Lob der „tollen Inhalte“.</li>
<li><strong>Für deren Leser schreiben.</strong> Ein Beispiel aus der Branche des Hosts. Eine primäre URL. Ein Anker, den ein Mensch in einem Satz setzen würde: Markenname, nackte URL oder eine beschreibende Wendung.</li>
<li><strong>Die Live-URL prüfen.</strong> Ob die Seite indexierbar ist, welches <code>rel</code>-Attribut am Link steht, ob der Anker zum Satz passt und ob die Zielseite das Versprechen hält. Notieren Sie ein Datum für die spätere Kontrolle.</li>
</ol>
<figure>
<img src="{$img}" alt="Ablauf eines Gastbeitrags von der Themenwahl über die Host-Prüfung bis zur Live-URL" loading="lazy" width="1200" height="675">
<figcaption>Der Host behält die URL. Der Autor liefert den Text und prüft danach Attribut und Zielseite.</figcaption>
</figure>
<p><strong>Beispiel.</strong> Eine Steuerkanzlei in Köln will nicht auf zwanzig Blogs denselben kommerziellen Anker streuen. Sie schreibt auf einem Mittelstandsportal über die Frist, die GmbH-Geschäftsführer im ersten Quartal regelmäßig verpassen, und verlinkt den eigenen Fristenrechner mit dem Anker „Fristenrechner für GmbH-Geschäftsführer“. Der Satz wäre auch ohne SEO-Absicht so formuliert. Das ist eine brauchbare Ankertext-Strategie: beschreibend, einmalig, an die Zielseite gebunden. Dieselbe exakte Geld-Phrase auf jeder Platzierung ist ein Muster, keine Strategie.</p>
<p>Die Ankertext-Strategie scheitert meist nicht am einzelnen Wort, sondern an der Wiederholung. Marke, URL und eine sachliche Umschreibung im Wechsel sehen aus wie Sätze. Ein immer gleicher Exact-Match sieht aus wie eine Liste.</p>

<h2 id="rel">Dofollow, Nofollow und rel sponsored</h2>
<p>Wer „dofollow vs nofollow“ vergleicht, sucht die Wirkung des Links, nicht ein Extra-Attribut namens dofollow. Ein solches Attribut gibt es nicht. Ein Link ohne einschränkendes <code>rel</code> darf von einer Suchmaschine als Empfehlung gelesen werden. Das ist der Zustand, den die Branche dofollow nennt. <code>rel="nofollow"</code> ist der Hinweis, den Link nicht als Empfehlung zu werten. <code>rel="sponsored"</code> kennzeichnet Links aus Bezahlung oder einer vergleichbaren Gegenleistung. <code>rel="ugc"</code> gilt für Nutzerinhalte wie Kommentare, nicht für einen beauftragten Fachartikel.</p>
<table>
<thead>
<tr><th>Attribut</th><th>Bedeutung</th><th>Wann es bei einem Gastbeitrag hingehört</th></tr>
</thead>
<tbody>
<tr><td>kein <code>rel</code> (dofollow)</td><td>Der Link darf als redaktionelle Empfehlung gelten</td><td>Die Redaktion verantwortet Thema und Link ohne Zahlung</td></tr>
<tr><td><code>nofollow</code></td><td>Hinweis, den Link nicht als Empfehlung zu werten</td><td>Der Host setzt den Link, will ihn aber nicht empfehlen</td></tr>
<tr><td><code>sponsored</code></td><td>Bezahlt oder gleichwertig entgolten</td><td>Geld, Tausch oder eine vereinbarte Gegenleistung</td></tr>
<tr><td><code>ugc</code></td><td>Nutzerinhalt</td><td>Kommentare und Foren, nicht ein Fachtext im Auftrag</td></tr>
</tbody>
</table>
<p>Was <code>rel="sponsored"</code> bedeutet, lässt sich in einem Satz sagen: Das Attribut beschreibt das Geschäftsverhältnis. Es sagt nichts über die Qualität des Textes. Ein gut geschriebener bezahlter Beitrag bleibt ein bezahlter Beitrag. Die Gegenüberstellung von Dofollow, Nofollow und Ankertext steht auf der Seite <a href="{$dofollow}">Dofollow, Nofollow und Ankertext</a>. Seit 2019 behandelt Google diese Angaben als Hinweise, nicht als harte Sperre. Die Kennzeichnung bleibt trotzdem die ehrliche Beschreibung dessen, was vereinbart wurde.</p>

<h2 id="host">Woran Sie einen tauglichen Host erkennen</h2>
<p>Domain Authority und Domain Rating sind Filter, keine Kaufgründe. Ein hoher Wert bei leerem Archiv, austauschbaren Autoren und immer denselben drei Partnerlinks ist ein Warnsignal. Reichweite zählt nur, wenn die Seiten zum Thema des Beitrags passen. Ein Portal mit viel Traffic im Glücksspiel hilft einem B2B-Thema nicht.</p>
<p>Prüfen Sie vor dem Pitch:</p>
<ul>
<li>Die Zielgruppe lässt sich in einem Satz nennen, und sie überschneidet sich mit den eigenen Lesern.</li>
<li>Die letzten Artikel sind eigenständig und bleiben in einem Themenfeld.</li>
<li>Ausgehende Links sehen nicht nach einer Farm aus. Nicht Casino, CBD und Küche im Wechsel.</li>
<li>Es gibt erkennbare Autoren oder eine Redaktion, die man ansprechen kann.</li>
<li>Die künftige URL ist indexierbar, und das <code>rel</code>-Attribut ist vor der Veröffentlichung bekannt.</li>
<li>Die eigene Zielseite verdient den Klick. Ein Link auf eine dünne Landingpage macht auch einen guten Host-Artikel wertlos.</li>
</ul>
<p>Seiten mit der Überschrift „Write for us“, die nur eine Kennzahl nennen und keine Themenregeln, sind ein Vertriebskanal für Links, keine Redaktion. Wenn Ihnen die URL erst nach der Zahlung genannt wird, ist das ebenfalls eine Antwort: Gehen Sie weiter.</p>
<p>Der Kontakt läuft über die Adresse, die der Host selbst veröffentlicht: Redaktionspostfach, Autorenzeile oder das Bestellfenster einer konkreten Platzierung. Zehn geratene Rollenadressen in einer Mail erkennt jede Redaktion als Streuung.</p>

<h2 id="dach">Deutschland, Österreich, Schweiz</h2>
<p>Dieselbe Definition gilt in allen drei Märkten. Die Suchformulierung nicht. Die Schreibung mit ß teilen Deutschland und Österreich. In der Schweiz steht ss.</p>
<p>In Deutschland ist „Gastbeitrag“ der normale Begriff, daneben „Gastartikel“. Englische Lehnwörter wie Guest Post, Backlink und Dofollow stehen in Agenturtexten, selten in der Frage eines Redakteurs. Ein Pitch an ein deutsches Fachportal gehört auf Deutsch, und das Thema heißt so wie die Rubrik, nicht „guest post opportunity“.</p>
<p>In Österreich wird die Frage oft als „Was ist ein Gastbeitrag SEO“ gestellt. Gemeint ist dieselbe Definition, mit der Erwartung, dass der Text einen Link und eine SEO-Absicht mitmeint. SEO bleibt ein möglicher Nutzen, kein anderer Beitragstyp. Österreich schreibt ß wie Deutschland. Was sich ändert, sind die Beispiele: eine österreichische Redaktion erwartet Bezüge aus Österreich, nicht nur Behörden, Städte und Portale aus Deutschland.</p>
<p>In der Schweiz suchen manche „Was ist ein Gastbeitrag CH“. Inhaltlich ist es derselbe Artikel. Die Schreibweise weicht ab: In der Schweiz steht häufig „ss“, wo Deutschland und Österreich „ß“ setzen, also „gross“ statt „groß“. Ein Beitrag für eine Schweizer Site sollte diese Konvention und Schweizer Beispiele nutzen. Ein Text mit durchgehendem „ß“ und nur deutschen Städten wirkt dort wie ein umetikettierter Deutschland-Artikel. Das ist keine Kleinigkeit der Rechtschreibung, sondern das Signal, ob der Text für diese Leser geschrieben wurde.</p>

<h2 id="preis">Warum es keinen Einheitspreis gibt</h2>
<p>Was ein Gastbeitrag kostet, hängt von der einzelnen Site ab: Thema, redaktioneller Aufwand, Reichweite, ob der Link gekennzeichnet wird. Eine einzige Euro-Zahl für Deutschland, Österreich oder die Schweiz wäre erfunden. Diese Seite nennt deshalb keine Preise und keine Marktanteile.</p>
<p>Vergleichen lässt sich das nur über konkrete Angebote. Jede Platzierung bei SEOLinkBuildings hat ihren eigenen Preis in Euro und die Regeln des Hosts. Eine Live-URL gibt es, wenn der Beitrag veröffentlicht ist, nicht schon auf der Preisliste. Wer Angebote nebeneinanderlegen will, findet sie unter <a href="{$buyDe}">deutschen Gastbeiträgen mit eigenem Euro-Preis</a>. Zahlen macht den Text nicht gut oder schlecht. Es ändert, wie der Link gekennzeichnet werden sollte.</p>

<h2 id="fehler">Typische Fehler</h2>
<ul>
<li><strong>Den Beitrag an der Ankerphrase entlang schreiben.</strong> Der Text wird eine Hülle. Leser merken das im ersten Absatz.</li>
<li><strong>Dieselbe exakte Ankerphrase auf jeder Platzierung.</strong> Das ist das Muster, vor dem die Spam-Richtlinien warnen.</li>
<li><strong>Bezahlung verschweigen.</strong> Ein bezahlter Platz mit einem unmarkierten Dofollow-Link ist kein redaktioneller Gastbeitrag mehr.</li>
<li><strong>Kennzahlen vor dem Thema prüfen.</strong> DA oder DR als einziges Kriterium kauft Reichweite ohne Leser für das eigene Thema.</li>
<li><strong>Die Startseite verlinken.</strong> Die Ziel-URL muss den Satz einlösen, in dem der Link steht.</li>
<li><strong>Österreich und die Schweiz mit einem Deutschland-Text bedienen.</strong> Andere Beispiele, und in der Schweiz ss statt ß.</li>
<li><strong>Die Live-URL nicht nachsehen.</strong> Ohne Prüfung von Adresse, Attribut und Indexierung weiß niemand, was tatsächlich online steht.</li>
<li><strong>Denselben Artikel mehrfach publizieren.</strong> Redaktionen erwarten ein Original. Doppelte Seiten konkurrieren miteinander. Syndikation nur, wenn sie ausdrücklich vereinbart ist.</li>
</ul>

<h2 id="fragen">Fragen</h2>
<h3>Was ist ein Gastbeitrag?</h3>
<p>Ein Artikel auf einer Website, die dem Autor nicht gehört. Der Host veröffentlicht ihn für sein eigenes Publikum. Eine Autorenzeile ist üblich, ein Link möglich. Eine Ranking-Zusage ist nicht Teil des Formats.</p>
<h3>Ist ein Gastbeitrag dasselbe wie ein Advertorial?</h3>
<p>Nein. Der Gastbeitrag ist ein Fachtext unter dem Namen des Autors, den die Redaktion thematisch annimmt. Das Advertorial ist eine bezahlte Darstellung im Look der Seite und muss als Werbung erkennbar sein. Sobald Geld den Platz kauft, rückt auch ein sonst redaktioneller Text in Richtung Kennzeichnung.</p>
<h3>Was bedeutet rel sponsored bei einem Gastbeitrag?</h3>
<p><code>rel="sponsored"</code> markiert einen Link, der wegen einer Zahlung oder einer vergleichbaren Gegenleistung gesetzt wurde. Das Attribut beschreibt das Geschäftsverhältnis, nicht die Textqualität.</p>
<h3>Soll ein Gastbeitrag dofollow sein?</h3>
<p>Nur wenn die Redaktion den Link ohne Gegenleistung selbst verantwortet. Gibt es eine Zahlung, ist <code>sponsored</code> die ehrliche Kennzeichnung. Ein unmarkierter Dofollow-Link macht einen bezahlten Platz nicht wertvoller.</p>
<h3>Wie schreibt man einen Gastbeitrag, der nicht nach Werbung klingt?</h3>
<p>Mit einem Beispiel, das die Leser des Hosts betrifft, einem Anker, den Sie auch in einer E-Mail so formulieren würden, und einer Zielseite, die genau diesen Punkt vertieft. Die eigene Leistung nur dort nennen, wo sie die Aussage trägt.</p>
<h3>Was kostet ein Gastbeitrag?</h3>
<p>Es gibt keinen Einheitspreis. Jede Site setzt den eigenen Preis nach Thema, Aufwand und Reichweite. Konkrete Euro-Beträge stehen an den einzelnen Angeboten, nicht in einer Definition.</p>

<h2>Fazit</h2>
<p>Ein Gastbeitrag ist ein Text auf fremder URL, geschrieben für deren Leser, mit einer Autorenzeile und höchstens dem Link, den die Aussage braucht. In Deutschland, Österreich und der Schweiz gilt dieselbe Definition. Pitch und Beispiele müssen zum Markt passen, in der Schweiz auch die Schreibung mit ss statt ß. Dofollow ohne Bezahlung, <code>sponsored</code> mit Bezahlung, und ein Host, dessen Archiv zum Thema gehört: das sind die drei Prüfungen vor dem Schreiben.</p>

<h2>Quellen</h2>
<ul>
<li><a href="https://developers.google.com/search/docs/essentials/spam-policies">Google Search Central — Spam-Richtlinien</a> (Linkspam, Guest-Posting-Muster, bezahlte Links)</li>
<li><a href="https://developers.google.com/search/blog/2019/09/evolving-nofollow-new-ways-to-identify">Google Search Central Blog — Evolving nofollow</a> (<code>sponsored</code>, <code>ugc</code>, Hinweise)</li>
<li><a href="https://developers.google.com/search/docs/fundamentals/creating-helpful-content">Google Search Central — Hilfreiche Inhalte</a></li>
</ul>
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
        $img = BlogLocaleImages::publicUrl(GuestPostingGuideBlogPost::IMAGE_WORKFLOW, 'fr');

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
        $img = BlogLocaleImages::publicUrl(GuestPostingGuideBlogPost::IMAGE_WORKFLOW, 'nl');

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
        $dofollow = '/it/blog/dofollow-vs-nofollow';
        $buy = '/it/comprare-guest-post';
        $img = BlogLocaleImages::publicUrl(GuestPostingGuideBlogPost::IMAGE_WORKFLOW, 'it');

        return <<<HTML
<p class="glossary-definition">Un guest post è un articolo pubblicato su un sito di cui l’autore non è titolare. L’host tiene l’indirizzo e i lettori. L’autore consegna il testo e di solito lo firma. Un link verso una sua pagina è frequente. Il formato non è un advertorial e non è l’acquisto del solo link.</p>
<p>Chi cerca «cos’è un guest post» sta chiedendo questo confine, non un listino e non una promessa di posizione. Il plurale, «cosa sono i guest post», indica lo stesso formato ripetuto su più siti: non è un prodotto diverso. Prima di proporre un pezzo a una testata italiana conviene tenere quel confine, fra articolo firmato, advertorial e acquisto del solo link, e solo dopo chiedersi se quei lettori hanno un motivo per arrivare in fondo.</p>

<nav aria-label="Indice">
<p><strong>In questa pagina</strong></p>
<ol>
<li><a href="#plurale">Cosa sono i guest post</a></li>
<li><a href="#formati">Quattro formati che in italiano si mescolano</a></li>
<li><a href="#redazione">Cosa controlla una redazione</a></li>
<li><a href="#passaggi">I passaggi, fino all’indirizzo pubblico</a></li>
<li><a href="#seo">Guest post e SEO, senza promesse</a></li>
<li><a href="#ancora">L’ancora dentro la frase</a></li>
<li><a href="#prezzo">Prezzo, fattura e IVA</a></li>
<li><a href="#errori">Errori che si ripetono</a></li>
<li><a href="#domande">Domande</a></li>
</ol>
</nav>

<h2 id="plurale">Cosa sono i guest post</h2>
<p>Un singolo guest post è un testo ospitato. «Cosa sono i guest post», al plurale, descrive la stessa ospitalità ripetuta: più firme esterne, più indirizzi, più redazioni. Non compare un secondo formato solo perché la domanda usa il plurale. Cambia il numero dei pezzi, non la natura di ciascuno.</p>
<p>In redazione il patto è semplice. Chi scrive porta un argomento che i lettori di quel sito possono usare. Chi pubblica decide se il pezzo entra, con quale titolo, con quanti link e se la firma resta. L’indirizzo finale appartiene all’host. Anche un brano eccellente, se la testata lo rifiuta, non è ancora un guest post: è una bozza nel cassetto di chi l’ha scritta.</p>
<p>Il link non è obbligatorio per definizione. Alcune testate firmano l’autore e non collegano nulla. Altre accettano un collegamento nel corpo e uno nella firma. Entrambe le scelte restano un guest post. Quello che non resta un guest post è una riga isolata, pagata per comparire in un articolo altrui già online, senza un testo nuovo firmato da chi promuove la pagina di destinazione.</p>
<p>Il lavoro di trovare quei collegamenti, al di fuori di questo formato, è un’altra domanda. Sta nella pagina su <a href="{$backlinks}">come ottenere un backlink</a>. Qui il tema è il pezzo ospitato, non l’elenco delle tecniche.</p>

<h2 id="formati">Quattro formati che in italiano si mescolano</h2>
<p>In una stessa conversazione finiscono quattro cose diverse. Tenerle sulla stessa riga produce ordini sbagliati: si paga un advertorial credendo di firmare un articolo, oppure si compra un link e lo si chiama guest post perché da qualche parte c’è un paragrafo.</p>
<table>
<thead>
<tr><th>Formato</th><th>Chi firma</th><th>Chi decide il tema</th><th>Cosa capisce il lettore</th></tr>
</thead>
<tbody>
<tr><td>Guest post</td><td>L’autore esterno, in genere con nome</td><td>Proposta dell’autore, accettazione della redazione</td><td>Un articolo della testata, scritto da fuori</td></tr>
<tr><td>Articolo sponsorizzato</td><td>Spesso la testata, a volte il committente</td><td>Il committente, dentro i vincoli grafici del sito</td><td>Un contenuto pagato, riconoscibile come tale</td></tr>
<tr><td>Pubbliredazionale</td><td>Di solito non una firma esterna autonoma</td><td>Il messaggio pubblicitario</td><td>Pubblicità scritta con il tono della testata</td></tr>
<tr><td>Acquisto del solo link</td><td>Nessun articolo nuovo</td><td>Chi compra il collegamento</td><td>Poco o niente: manca il pezzo</td></tr>
</tbody>
</table>
<p>Il pubbliredazionale resta una riga di questa tabella. Il confronto esteso tra guest post e articolo sponsorizzato, e il posto del pubbliredazionale in quel confronto, sta nella pagina sulla <a href="{$sponsored}">differenza tra guest post e articolo sponsorizzato</a>. Non è un secondo nome del guest post.</p>
<p>Un pezzo non pagato può portare un link che la redazione considera proprio, perché ha scelto lei il tema e la destinazione. Appena il posto si ottiene con un pagamento, uno sconto o uno scambio pattuito, il link descrive un rapporto commerciale. L’etichetta segue quello scambio, non il titolo del pezzo. Un testo pulito e pagato resta un testo pagato.</p>

<h2 id="redazione">Cosa controlla una redazione</h2>
<p>Una redazione italiana, prima di rispondere a una proposta, guarda i propri lettori, non la scheda di chi scrive. Il pezzo deve poter vivere nella rubrica già esistente. Se gli ultimi articoli parlano di cantieri, un testo sul software per officine meccaniche va motivato con un ponte vero, non con una frase di cortesia.</p>
<p>Quattro controlli tornano quasi sempre, anche quando la testata non li scrive in un modulo.</p>
<ul>
<li>I lettori si possono nominare in una frase, e si sovrappongono a chi userebbe la pagina di destinazione.</li>
<li>Gli articoli recenti sono firmati e restano in un ambito. Un archivio che salta da un settore all’altro ogni settimana non è una rubrica.</li>
<li>L’indirizzo futuro del pezzo potrà essere indicizzato. Una pagina chiusa, duplicata o esclusa dai motori non ospita un articolo: lo nasconde.</li>
<li>L’attributo del link è concordato prima della pubblicazione. Scoprirlo dopo, sull’indirizzo già online, significa accettare un patto che non si è letto.</li>
</ul>
<p>Si aggiunge la lingua. Un sito rivolto a lettori in Italia si aspetta italiano scritto per loro, con esempi che riconoscono: una scadenza, un cantiere, un comune, un modo di dire del mestiere. Un testo pensato altrove e poi voltato in italiano si riconosce dagli esempi, prima che dalla grammatica.</p>
<p>Pagine intitolate «write for us» che citano solo un punteggio e non dicono quali temi rifiutano sono un canale di vendita di link. Se l’indirizzo del pezzo viene comunicato solo dopo il pagamento, la risposta utile è passare oltre. Il contatto giusto è quello che la testata pubblica: la mail di redazione, la firma di un articolo, oppure la scheda di una singola pubblicazione che indica già le regole.</p>

<h2 id="passaggi">I passaggi, fino all’indirizzo pubblico</h2>
<p>L’ordine conta più dello stile. Saltare un passaggio costringe a rifare il testo: le regole della testata si scoprono quando il pezzo è già scritto, spesso verso la pagina di destinazione sbagliata.</p>
<ol>
<li><strong>Trovare siti.</strong> Si parte dalla rubrica, non dal punteggio. Si leggono gli ultimi pezzi e si annota chi firma. Un elenco di domini è una lista di candidati, non un giudizio.</li>
<li><strong>Valutare.</strong> Lettori, ambito, link in uscita, esistenza di una redazione raggiungibile, possibilità di indicizzare l’indirizzo futuro. Un archivio vuoto con un numero alto non supera questo passaggio.</li>
<li><strong>Proporre.</strong> Perché quella testata, un’idea, un titolo di lavoro, due frasi di scaletta, una riga su chi scrive e se il pezzo è inedito. Niente elogi generici ai «contenuti di qualità».</li>
<li><strong>Scrivere.</strong> Per i lettori di quella rubrica. Una sola pagina di destinazione. Un esempio che quella redazione riconoscerebbe. Le regole di lunghezza, immagini e numero di link si applicano ora, non dopo il rifiuto.</li>
<li><strong>Pubblicare.</strong> La testata tiene l’indirizzo. Può tagliare, chiedere una fonte, spostare il link in firma. Finché non esiste l’indirizzo pubblico, il pezzo non è online.</li>
<li><strong>Ricontrollare.</strong> Indicizzazione, attributo del link, ancora dentro la frase, pagina di destinazione che mantiene la promessa. Si annota una data per un controllo successivo.</li>
</ol>
<figure>
<img src="{$img}" alt="Passaggi di un guest post: trovare siti, valutare, proporre, scrivere, pubblicare e ricontrollare" loading="lazy" width="1200" height="675">
<figcaption>L’host tiene l’indirizzo. Chi scrive consegna il testo e, dopo la pubblicazione, ricontrolla attributo e pagina di destinazione.</figcaption>
</figure>
<p><strong>Un caso.</strong> Uno studio di architettura a Bari non vuole la stessa frase commerciale su venti blog. Scrive su una rivista letta da imprese edili un pezzo sul computo che i capicantiere contestano in cantiere, e collega la pagina in cui mostra un computo commentato. L’ancora è «computo metrico commentato», cioè l’oggetto della frase. La stessa identica formula commerciale, ripetuta su ogni pubblicazione, non è una scelta di stile: è uno schema.</p>

<h2 id="seo">Guest post e SEO, senza promesse</h2>
<p>Una guida sul guest post e sul SEO può dire cosa il formato è in grado di fare e dove si ferma. Può dare visibilità a lettori che già frequentano un’altra testata. Può lasciare un collegamento che un motore è in grado di scansionare, se la pagina è indicizzabile e il link non è nascosto. Non consegna una posizione, e questa pagina non la promette.</p>
<p>Le linee pubbliche di Google sullo spam indicano, tra gli schemi di link, il guest posting su larga scala con ancore sempre uguali e cariche di parole chiave. Il formato, da solo, non è vietato. Lo schema costruito per manipolare le posizioni sì. Un articolo che i lettori di quella testata leggerebbero anche senza il link resta nel perimetro del formato. Lo stesso testo venduto a cento blog ne esce.</p>
<p>Tra le fonti in fondo ci sono il post pubblico del 2019 sul nofollow e la documentazione sui contenuti utili. Il significato di dofollow, nofollow e <code>rel="sponsored"</code> non si apre in questa pagina.</p>

<h2 id="ancora">L’ancora dentro la frase</h2>
<p>L’anchor text di un guest post è la parte visibile del link, quella che si potrebbe leggere ad alta voce. Funziona quando starebbe in una mail alla redazione: il nome di chi scrive, l’indirizzo nudo, oppure una descrizione della pagina di arrivo. Non funziona quando la frase esiste solo per ospitare la parola che si vorrebbe in prima posizione.</p>
<p>Il fallimento di solito non è la singola parola. È la ripetizione. Marca, indirizzo e una perifrasi si alternano e sembrano frasi. La stessa sequenza esatta, su ogni pezzo, sembra un elenco. La pagina di destinazione deve mantenere ciò che l’ancora promette. Collegare sempre la pagina iniziale, qualunque sia il tema del paragrafo, rompe quel patto.</p>
<p>Dofollow, nofollow e il significato di <code>rel="sponsored"</code> non si esauriscono in questo capoverso. La spiegazione sta nella pagina su <a href="{$dofollow}">dofollow, nofollow e l’attributo sponsored</a>. Qui basta il patto operativo: l’attributo si decide prima di pubblicare, e un pagamento si dice.</p>

<h2 id="prezzo">Prezzo, fattura e IVA</h2>
<p>Non esiste un prezzo unico del guest post in Italia. Ogni sito applica il proprio, in base al tema, al lavoro di redazione, alla diffusione e al modo in cui il link viene segnalato. Una cifra sola, o una quota di mercato, sarebbe inventata. Questa pagina non le riporta.</p>
<p>Il confronto si fa sulle singole offerte. Ogni pubblicazione ha il suo prezzo in euro e le regole di chi la ospita. L’indirizzo pubblico esiste quando il pezzo è pubblicato, non nella colonna del prezzo. In Italia il pagamento incontra fattura e IVA: sono passaggi amministrativi del comprare, non una tariffa del formato e non un’aliquota che una definizione possa fissare.</p>
<p>Pagare non rende il testo migliore né peggiore. Cambia il modo in cui il link va descritto. Le offerte si confrontano tra le <a href="{$buy}">inserzioni italiane, ciascuna con il proprio prezzo in euro</a>.</p>

<h2 id="errori">Errori che si ripetono</h2>
<ul>
<li><strong>Scrivere il pezzo intorno all’ancora.</strong> Il testo diventa un contenitore. Chi legge se ne accorge subito.</li>
<li><strong>Ripetere la stessa ancora esatta.</strong> È lo schema che le linee sullo spam descrivono.</li>
<li><strong>Tacere il pagamento.</strong> Un posto pagato con un link presentato come scelta spontanea della redazione non è più un guest post redazionale.</li>
<li><strong>Scegliere solo un punteggio.</strong> Un numero alto, senza lettori per quel tema, compra diffusione inutile.</li>
<li><strong>Collegare la pagina iniziale per abitudine.</strong> La pagina di arrivo deve mantenere la frase in cui il link sta.</li>
<li><strong>Non ricontrollare l’indirizzo pubblico.</strong> Senza indirizzo, attributo e indicizzazione non si sa cosa sia finito online.</li>
<li><strong>Ripubblicare lo stesso testo.</strong> Le redazioni chiedono un inedito. Due copie della stessa pagina competono tra loro. Una ripubblicazione esiste solo se è pattuita.</li>
</ul>

<h2 id="domande">Domande</h2>
<h3>Cos’è un guest post?</h3>
<p>Un articolo su un sito di cui l’autore non è titolare. L’host lo pubblica per i propri lettori e tiene l’indirizzo. La firma è abituale, il link è possibile. Una posizione in classifica non fa parte del formato.</p>
<h3>Cosa sono i guest post?</h3>
<p>Lo stesso formato, al plurale. Più pezzi su più siti non creano un prodotto nuovo. Ogni pezzo ha un host, una firma e, se c’è, un link.</p>
<h3>Come comprare guest post in sicurezza?</h3>
<p>La domanda non sostituisce la definizione. Si parte dalle regole della singola offerta, descritte nella sezione sul <a href="#prezzo">prezzo</a>: prezzo in euro di quel sito, attributo concordato prima, indirizzo che esiste solo dopo la pubblicazione. Non c’è una cifra valida per tutti.</p>
<h3>Un guest post senza link è ancora un guest post?</h3>
<p>Sì. Se la testata pubblica il testo per i suoi lettori e firma l’autore, il formato c’è anche senza collegamento. Il link è una conseguenza frequente, non la definizione.</p>
<h3>Si può ripubblicare lo stesso articolo?</h3>
<p>Di norma no. Un inedito è ciò che la redazione si aspetta. Due indirizzi con lo stesso testo si fanno concorrenza. Una ripubblicazione si fa solo con un accordo esplicito.</p>

<h2>In chiusura</h2>
<p>Un guest post è un testo su un indirizzo altrui, scritto per i lettori di quell’indirizzo, con una firma e al massimo il link che la frase richiede. In Italia la definizione non cambia da una città all’altra. Cambiano gli esempi, la lingua del pezzo e il modo in cui fattura e IVA accompagnano il pagamento. L’attributo si accorda prima. L’indirizzo si ricontrolla dopo. Una posizione non si ordina insieme al pezzo.</p>

<h2>Fonti</h2>
<ul>
<li><a href="https://developers.google.com/search/docs/essentials/spam-policies">Google Search Central — Spam policies</a> (schemi di link, guest posting su larga scala, link a pagamento)</li>
<li><a href="https://developers.google.com/search/blog/2019/09/evolving-nofollow-new-ways-to-identify">Google Search Central Blog — Evolving nofollow</a> (<code>sponsored</code>, <code>ugc</code>, indicazioni)</li>
<li><a href="https://developers.google.com/search/docs/fundamentals/creating-helpful-content">Google Search Central — Contenuti utili</a></li>
</ul>
HTML;
    }
}
