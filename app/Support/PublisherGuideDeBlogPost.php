<?php

namespace App\Support;

/**
 * German publisher how-to guide for SEOLinkBuildings.
 * Twin of PublisherPlatformGuideBlogPost (EN).
 */
class PublisherGuideDeBlogPost
{
    public const SLUG = 'publisher-leitfaden-websites-hinzufuegen-auftraege-auszahlen';

    public const FEATURED_ASSET = 'assets/img/blog/market-pub-guide-de-featured.jpg';

    public const FEATURED_STORAGE = 'blogs/featured/market-pub-guide-de-featured.jpg';

    public const IMAGE_SITES = 'market-pub-guide-de-sites.jpg';

    public const IMAGE_TASKS = 'market-pub-guide-de-tasks.jpg';

    public const IMAGE_BALANCE = 'howto-pub-balance.jpg';

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
            'title' => 'Publisher-Leitfaden: Websites hinzufügen, Aufträge erfüllen, Guthaben auszahlen',
            'slug' => self::SLUG,
            'primary_locale' => 'de',
            'excerpt' => 'So listen Publisher Websites auf SEOLinkBuildings, erfüllen Platzierungsaufträge, reichen Live-URLs ein und zahlen verfügbares Guthaben aus.',
            'content' => self::contentHtml(),
            'author' => 'M. Arslan',
            'tags' => [
                'Publisher-Leitfaden',
                'My Sites',
                'Tasks',
                'Auszahlung',
                'Guest Posting',
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
                'question' => 'Wann erscheinen meine Sites im Advertiser-Katalog?',
                'answer' => 'Nachdem Sie sie eingereicht haben und Verifizierung sowie Admin-Review bestanden sind. Unvollständige Profile, doppelte Domains oder fehlgeschlagene Verifizierung halten eine Site aus dem Inventar.',
            ],
            [
                'question' => 'Kann ich einen Task ablehnen?',
                'answer' => 'Ja, wenn Briefing oder Content nicht zu Ihren redaktionellen Regeln passen. Lehnen Sie zügig mit klarem Grund ab, damit der Advertiser neu zuweisen kann. Schweigen erzeugt Streit.',
            ],
            [
                'question' => 'Wann kann ich Guthaben auszahlen?',
                'answer' => 'Wenn das Guthaben nach den Freigaberegeln der Plattform für abgeschlossene Arbeit verfügbar ist. Nutzen Sie Withdraw, wählen Sie eine gespeicherte Auszahlungsmethode und reichen Sie nur Beträge ein, die Sie mit den hinterlegten Daten belegen können.',
            ],
            [
                'question' => 'Ich kaufe auch Platzierungen. Brauche ich zwei E-Mail-Adressen?',
                'answer' => 'Meist nicht. Ein Konto kann Advertiser- und Publisher-Rolle halten. Wechseln Sie die Rolle in der oberen Leiste statt doppelter Logins.',
            ],
        ];
    }

    public static function contentHtml(): string
    {
        $register = '/register';
        $becomePublisher = '/become-a-publisher';
        $howItWorks = '/how-it-works';
        $advertiserGuide = '/blog/how-to-buy-guest-posts-on-seolinkbuildings-advertiser-guide';
        $liveCheck = '/blog/what-to-check-after-the-live-link-indexation-attributes-rankings';
        $imgSites = BlogInlineImages::publicUrl(self::IMAGE_SITES);
        $imgTasks = BlogInlineImages::publicUrl(self::IMAGE_TASKS);
        $imgBalance = BlogInlineImages::publicUrl(self::IMAGE_BALANCE);

        return <<<HTML
<p>Publisher auf SEOLinkBuildings verkaufen Platzierungen auf Sites, die sie kontrollieren. Die Arbeit ist klar: Inventar korrekt listen, Tasks annehmen, die Sie erfüllen können, im vereinbarten Standard veröffentlichen, Live-URL einreichen, dann auszahlen, wenn Guthaben verfügbar ist.</p>
<p>Advertiser gehen einen anderen Weg — siehe den <a href="{$advertiserGuide}">Advertiser-Leitfaden</a>. Das Zweiseiten-Modell in Kurzform steht unter <a href="{$howItWorks}">How it works</a>. Wer erst einsteigen will: <a href="{$becomePublisher}">Become a publisher</a>.</p>

<h2>1. Als Publisher registrieren und My Sites öffnen</h2>
<p>Legen Sie ein Konto unter <a href="{$register}">/register</a> mit der Publisher-Rolle an (oder wechseln Sie zu Publisher, wenn Sie bereits als Advertiser kaufen). Verifizieren Sie die E-Mail und öffnen Sie <strong>My Sites</strong>.</p>

<figure>
<img src="{$imgSites}" alt="Publisher-My-Sites-Seite zum Hinzufügen von Websites auf SEOLinkBuildings" loading="lazy" width="1200" height="675">
<figcaption>My Sites — eine Website nach der anderen hinzufügen oder Bulk Add nutzen, wenn Sie ein größeres Portfolio onboarden.</figcaption>
</figure>

<p>Nutzen Sie <strong>Add New Website</strong> für ein einzelnes Listing. Nutzen Sie Bulk Add, wenn Sie viele Domains onboarden und eine geführte Tabellen-Workflow wollen. Nische, Land, Sprache, Preise und Link-Bedingungen sorgfältig ausfüllen — Advertiser kaufen gegen genau diese Felder.</p>
<p>Doppelte Domains werden blockiert. Ist eine Site schon beansprucht, folgen Sie dem Claim-Hinweis auf dem Screen statt ein zweites Listing zu erfinden.</p>

<h2>2. Verifizierung und Freigabe</h2>
<p>Erwarten Sie einen Verifizierungsschritt, der Kontrolle über die Domain belegt, danach ein Admin-Review, bevor die Site ins Katalog-Inventar kommt. Unvollständige Metriken, unklare Nischen oder unrealistische Preise verzögern den Review.</p>
<p>Halten Sie Kontaktdaten aktuell. Wenn ein Listing live ist, behandeln Sie Preis und Link-Attribute wie vertraglich: Mitten in einer Bestellung ohne Absprache ändern erzeugt Refunds und schlechte Bewertungen.</p>

<h2>3. Tasks abarbeiten, sobald sie kommen</h2>
<p><strong>Tasks</strong> ist Ihre Erfüllungs-Warteschlange. Öffnen Sie einen Task, lesen Sie das Briefing, laden Sie den zugewiesenen Artikel herunter oder prüfen Sie die Vorschau — und akzeptieren Sie nur Arbeit, die Sie im Zeitplan publizieren können.</p>

<figure>
<img src="{$imgTasks}" alt="Publisher-Tasks-Seite zum Annehmen und Abschließen von Platzierungsaufträgen" loading="lazy" width="1200" height="675">
<figcaption>Tasks — annehmen, publizieren und die Live-URL für jede Platzierung einreichen.</figcaption>
</figure>

<p>Nach der Veröffentlichung reichen Sie die Live-URL im Task-Flow ein. Advertiser prüfen Attribute und Content; unsere öffentliche Checkliste zur <a href="{$liveCheck}">QA nach dem Live-Link</a> ist derselbe Standard, den viele Käufer anlegen. Widerspricht etwas im Briefing Ihrer redaktionellen Policy: früh ablehnen, mit klarem Grund.</p>

<h2>4. Guthaben und Auszahlungen</h2>
<p>Abgeschlossene Arbeit schreibt Ihr Publisher-Guthaben nach Freigaberegeln gut. Öffnen Sie <strong>Withdraw</strong> (oder Balance, je nach Menü), prüfen Sie verfügbare Mittel und fordern Sie eine Auszahlung an.</p>

<figure>
<img src="{$imgBalance}" alt="Publisher-Withdraw-Seite zum Anfordern der Auszahlung von Einnahmen" loading="lazy" width="1200" height="675">
<figcaption>Withdraw — Auszahlung mit den Zahlungsmethoden anfordern, die Sie am Konto hinterlegt haben.</figcaption>
</figure>

<p>Geben Sie nur Methoden an, die Sie kontrollieren. Unvollständige Bank- oder Wallet-Daten verzögern die Bearbeitung. Bei Policy-Fragen bleiben FAQ und In-App-Support der verbindliche Weg.</p>

<h2>5. Betriebsstandards, die Ihr Listing schützen</h2>
<ul>
<li>Liefern Sie, was Sie verkauft haben: gleicher Link-Typ, gleiche Abschnittsqualität, gleiche Permanenz-Erwartung.</li>
<li>Antworten Sie im Order-Chat in einem vernünftigen Fenster — Schweigen wirkt wie Aufgabe.</li>
<li>Tauschen Sie URLs nach Freigabe nicht ohne Dokumentation aus.</li>
<li>Halten Sie My-Sites-Daten ehrlich; aufgeblasene Traffic-Claims fliegen schnell auf und kosten Vertrauen.</li>
</ul>
<p>Zuverlässige Publisher bekommen Wiederholungsaufträge. Dieser Ruf wächst schneller als jeder einzelne hohe Preis.</p>
<p><a href="{$register}">Als Publisher registrieren</a>, die erste Site korrekt listen und die ersten drei Tasks als Beweis behandeln, wie Sie arbeiten.</p>

<h2>Häufig gestellte Fragen</h2>
<h3>Wann erscheinen meine Sites im Advertiser-Katalog?</h3>
<p>Nachdem Sie sie eingereicht haben und Verifizierung sowie Admin-Review bestanden sind. Unvollständige Profile, doppelte Domains oder fehlgeschlagene Verifizierung halten eine Site aus dem Inventar.</p>
<h3>Kann ich einen Task ablehnen?</h3>
<p>Ja, wenn Briefing oder Content nicht zu Ihren redaktionellen Regeln passen. Lehnen Sie zügig mit klarem Grund ab, damit der Advertiser neu zuweisen kann. Schweigen erzeugt Streit.</p>
<h3>Wann kann ich Guthaben auszahlen?</h3>
<p>Wenn das Guthaben nach den Freigaberegeln der Plattform für abgeschlossene Arbeit verfügbar ist. Nutzen Sie Withdraw, wählen Sie eine gespeicherte Auszahlungsmethode und reichen Sie nur Beträge ein, die Sie mit den hinterlegten Daten belegen können.</p>
<h3>Ich kaufe auch Platzierungen. Brauche ich zwei E-Mail-Adressen?</h3>
<p>Meist nicht. Ein Konto kann Advertiser- und Publisher-Rolle halten. Wechseln Sie die Rolle in der oberen Leiste statt doppelter Logins.</p>
HTML;
    }
}
