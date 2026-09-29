<?php

namespace App\Support;

/**
 * German guide on dofollow/nofollow and anchor text for marketplace links.
 * One body for /blog, /de/blog, /fr/blog, /nl/blog (no per-locale fields).
 */
class DofollowNofollowAnkertexteBlogPost
{
    public const SLUG = 'dofollow-nofollow-ankertexte-marketplace-links';

    public const FEATURED_ASSET = 'assets/img/blog/dofollow-nofollow-ankertexte-featured.jpg';

    public const FEATURED_STORAGE = 'blogs/featured/dofollow-nofollow-ankertexte-featured.jpg';

    public const IMAGE_LINK_TYPES = 'dofollow-nofollow-ankertexte-linktypen.jpg';

    public const IMAGE_ANCHOR_MIX = 'dofollow-nofollow-ankertexte-mix.jpg';

    /**
     * @return array{
     *     title: string,
     *     slug: string,
     *     primary_locale: string,
     *     excerpt: string,
     *     content: string,
     *     author: string,
     *     tags: list<string>,
     *     status: string,
     *     featured_image: string,
     *     faq: list<array{question: string, answer: string}>
     * }
     */
    public static function payload(): array
    {
        return [
            'title' => 'DoFollow, NoFollow & Ankertexte: Was bei Marketplace-Links wirklich zählt',
            'slug' => self::SLUG,
            'primary_locale' => 'de',
            'excerpt' => 'DoFollow, NoFollow & Ankertexte: was bei Marketplace-Links zählt – Attribute, natürlicher Anker-Mix und Check vor der Bestellung.',
            'content' => self::contentHtml(),
            'author' => 'Arslan Jason',
            'tags' => [
                'DoFollow',
                'NoFollow',
                'Ankertexte',
                'Marketplace Links',
                'Linkbuilding',
            ],
            'status' => 'published',
            'featured_image' => self::FEATURED_STORAGE,
            'faq' => self::faqItems(),
        ];
    }

    /**
     * @return list<array{question: string, answer: string}>
     */
    public static function faqItems(): array
    {
        return [
            [
                'question' => 'Sind NoFollow-Links bei Marketplace-Platzierungen nutzlos?',
                'answer' => 'Nein. Sie vererben klassisches PageRank nicht wie DoFollow, können aber Traffic, Markenwahrnehmung und ein natürlicheres Profil bringen. Ein gesundes Linkprofil enthält beides – nicht nur „alles DoFollow, bitte“.',
            ],
            [
                'question' => 'Wie hoch darf der Anteil Exact-Match-Ankertexte sein?',
                'answer' => 'Es gibt keine magische Prozentzahl. Wenn ein Großteil Ihrer Anker exakt dem Geld-Keyword entspricht, wirkt das Profil oft gesteuert. Marke, URL, Partial-Match und generische Formulierungen sollten den Mix tragen.',
            ],
            [
                'question' => 'Kann ich beim Publisher nachträglich DoFollow erzwingen?',
                'answer' => 'Nur wenn das Angebot und die redaktionelle Policy es hergeben. Viele Sites kennzeichnen bezahlte Platzierungen bewusst. Lesen Sie die Listing-Angaben vor der Bestellung – Nachverhandeln nach Live-URL ist mühsam und oft aussichtslos.',
            ],
            [
                'question' => 'Woran erkenne ich den Link-Typ nach Veröffentlichung?',
                'answer' => 'Live-URL öffnen, Link inspizieren (rel-Attribut). Fehlt rel=\"nofollow\" bzw. sponsored/ugc in der relevanten Form, ist der Link in der Praxis oft dofollow. Dokumentieren Sie das im Order-Chat und prüfen Sie Indexierung getrennt davon.',
            ],
        ];
    }

    public static function contentHtml(): string
    {
        $marketplace = '/marketplace';
        $register = '/register';
        $imgTypes = BlogInlineImages::publicUrl(self::IMAGE_LINK_TYPES);
        $imgMix = BlogLocaleImages::publicUrl(self::IMAGE_ANCHOR_MIX, 'de');

        return <<<HTML
<p>Zwei Fragen kommen im Marketplace immer wieder: „Ist der Link DoFollow?“ und „Welchen Ankertext nehmen wir?“ Fair. Beides kann Rankings beeinflussen. Beides wird aber auch überschätzt – vor allem, wenn Relevanz und Site-Qualität schon wackeln.</p>
<p>Dieser Guide erklärt, <strong>was DoFollow und NoFollow praktisch bedeuten</strong>, wie Ankertexte natürlich wirken und worauf Sie bei Marketplace-Bestellungen achten sollten, bevor Sie Geld ausgeben.</p>

<h2>DoFollow und NoFollow – ohne Mythos</h2>
<p>Kurz und unromantisch:</p>
<ul>
<li><strong>DoFollow</strong> (genauer: Link ohne blockierendes <code>rel</code>): kann Ranking-Signale weitergeben.</li>
<li><strong>NoFollow</strong> (<code>rel="nofollow"</code>, oft auch in Kombination mit <code>sponsored</code> / <code>ugc</code>): signalisiert „nicht als redaktionelle Empfehlung werten“. Nutzen für SEO ist begrenzter, aber nicht automatisch null.</li>
</ul>
<p>Google hat die harte „NoFollow = ignorieren“-Welt längst aufgeweicht. Trotzdem: Wenn Sie gezielt Autorität aufbauen wollen, bleiben DoFollow-Platzierungen auf passenden Sites der klarere Hebel. NoFollow ist Ergänzung, kein Ersatz.</p>

<figure>
<img src="{$imgTypes}" alt="Natürliche In-Content-Links im Vergleich zu schwachen Outbound-Mustern" loading="lazy" width="1200" height="675">
<figcaption>Wo der Link sitzt, zählt oft genauso wie das rel-Attribut: Kontext schlägt Kosmetik.</figcaption>
</figure>

<h2>Was bei Marketplace-Links wirklich zählt (Reihenfolge)</h2>
<ol>
<li><strong>Themenfit &amp; Markt:</strong> deutsche Site für DACH-Intent schlägt „starke“ Random-Domain.</li>
<li><strong>Platzierung im Content:</strong> Fließtext &gt; Footer &gt; Autorenbox-Spam.</li>
<li><strong>Link-Attribut:</strong> DoFollow hilft – wenn 1 und 2 stimmen.</li>
<li><strong>Ankertext:</strong> natürlich im Satz, nicht als Keyword-Stempel.</li>
<li><strong>Tempo &amp; Mix:</strong> viele Exact-Match-DoFollows in zwei Wochen sehen selten „organisch“ aus.</li>
</ol>
<p>Wer nur Filter „DoFollow = ja“ klickt und den Rest ignoriert, kauft Attribute. Nicht Sichtbarkeit.</p>

<h2>Ankertexte: der Mix, der nicht nach Kampagne schreit</h2>
<p>Ankertext ist der klickbare Text. Google liest mit. Lesende auch. Wenn jeder zweite Link „beste Kreditkarte Vergleich 2026“ heißt, wirkt das Profil gesteuert – egal wie schön der DR der Publisher-Site ist.</p>

<figure>
<img src="{$imgMix}" alt="Vielfältige Ankertexte in einem redaktionellen Entwurf planen" loading="lazy" width="1200" height="675">
<figcaption>Marke, URL, Partial-Match, generisch – Vielfalt wirkt ruhiger als Keyword-Stakkato.</figcaption>
</figure>

<p>Ein brauchbarer Mix für Marketplace-Kampagnen:</p>
<ul>
<li><strong>Marke / Brand:</strong> „SEOLinkBuildings“, Firmenname</li>
<li><strong>Nackte URL:</strong> seolinkbuildings.com</li>
<li><strong>Partial-Match:</strong> „Gastbeiträge in Europa buchen“, „Publisher-Katalog vergleichen“</li>
<li><strong>Generisch:</strong> „hier“, „auf dieser Seite“, „mehr dazu“</li>
<li><strong>Exact-Match:</strong> sparsam, und nur wenn der Satz wirklich so gelesen werden würde</li>
</ul>
<p>Faustregel aus dem Alltag: Schreiben Sie den Satz ohne Link. Klingt er noch menschlich, wenn Sie das Keyword einfügen? Wenn nicht – Anker ändern.</p>

<h2>DoFollow filtern im Marketplace – sinnvoll, aber nicht blind</h2>
<p>Im <a href="{$marketplace}">Marketplace</a> können Sie Angebote nach Link-Typ und weiteren Kennzahlen vergleichen. Nutzen Sie den DoFollow-Filter als Qualitäts-Hinweis, nicht als einzige Wahrheit.</p>
<p>Vor der Bestellung zusätzlich prüfen:</p>
<ul>
<li>Ist DoFollow in der Listing-Beschreibung klar ausgewiesen?</li>
<li>Passt die Site thematisch und sprachlich?</li>
<li>Wirkt der Outbound-Bereich der Domain überladen?</li>
<li>Können Sie den Anker im Briefing natürlich vorgeben – ohne Keyword-Stuffing?</li>
</ul>
<p>Nach Live-Stellung: URL öffnen, Link prüfen, Attribute notieren. Unstimmigkeiten gehören in den Order-Chat, nicht in eine stille Excel-Liste drei Monate später.</p>

<h2>Häufige Fehler (die wir ständig sehen)</h2>
<ul>
<li>Nur Exact-Match-Anker auf jeder Platzierung</li>
<li>DoFollow um jeden Preis auf irrelevanten Sites</li>
<li>NoFollow-Links komplett verteufeln und dadurch Traffic-Chancen liegen lassen</li>
<li>Ankertext ändern wollen, nachdem der Artikel schon live und indexiert ist – ohne Absprache</li>
<li>Zehn DoFollow-Links in einer Woche auf eine frische Domain ballern</li>
</ul>
<p>Nichts davon ist „sofort Penalty“. Vieles davon sieht einfach unecht aus. Unecht ist in 2026 ein schlechtes Signal.</p>

<h2>Ein einfacher Briefing-Block für Ihre nächste Bestellung</h2>
<p>Kopieren, anpassen, mitbestellen:</p>
<ul>
<li>Ziel-URL: …</li>
<li>Bevorzugter Anker (1. Wahl / 2. Wahl): …</li>
<li>Tabu-Anker: …</li>
<li>Erwarteter Link-Typ laut Listing: DoFollow / NoFollow</li>
<li>Hinweis an Publisher: Link im Fließtext, kein Footer</li>
</ul>
<p>Klarheit spart Revisionsschleifen. Revisionsschleifen kosten Tage bis zur Live-URL.</p>

<h2>Kurz gesagt</h2>
<p>DoFollow hilft. NoFollow gehört dazu. Ankertexte entscheiden mit – aber nur im Kontext einer glaubwürdigen Site und eines ruhigen Tempos.</p>
<p>Wenn Sie Marketplace-Links buchen, filtern Sie smart, briefen Sie klar und prüfen Sie Live-URLs. <a href="{$register}">Konto erstellen</a> und im <a href="{$marketplace}">Katalog</a> Angebote vergleichen, bei denen Link-Typ, Markt und Thema zusammenpassen – nicht nur die größte Metrik.</p>

<h2>Häufig gestellte Fragen</h2>
<h3>Sind NoFollow-Links bei Marketplace-Platzierungen nutzlos?</h3>
<p>Nein. Sie vererben klassisches PageRank nicht wie DoFollow, können aber Traffic, Markenwahrnehmung und ein natürlicheres Profil bringen. Ein gesundes Linkprofil enthält beides – nicht nur „alles DoFollow, bitte“.</p>
<h3>Wie hoch darf der Anteil Exact-Match-Ankertexte sein?</h3>
<p>Es gibt keine magische Prozentzahl. Wenn ein Großteil Ihrer Anker exakt dem Geld-Keyword entspricht, wirkt das Profil oft gesteuert. Marke, URL, Partial-Match und generische Formulierungen sollten den Mix tragen.</p>
<h3>Kann ich beim Publisher nachträglich DoFollow erzwingen?</h3>
<p>Nur wenn das Angebot und die redaktionelle Policy es hergeben. Viele Sites kennzeichnen bezahlte Platzierungen bewusst. Lesen Sie die Listing-Angaben vor der Bestellung – Nachverhandeln nach Live-URL ist mühsam und oft aussichtslos.</p>
<h3>Woran erkenne ich den Link-Typ nach Veröffentlichung?</h3>
<p>Live-URL öffnen, Link inspizieren (rel-Attribut). Fehlt ein blockierendes rel in der relevanten Form, ist der Link in der Praxis oft dofollow. Dokumentieren Sie das im Order-Chat und prüfen Sie Indexierung getrennt davon.</p>
HTML;
    }
}
