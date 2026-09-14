<?php

namespace App\Support;

/**
 * German advertiser how-to guide for SEOLinkBuildings.
 * Twin of AdvertiserPlatformGuideBlogPost (EN).
 */
class AdvertiserGuideDeBlogPost
{
    public const SLUG = 'gastbeitraege-kaufen-auf-seolinkbuildings-advertiser-leitfaden';

    public const FEATURED_ASSET = 'assets/img/blog/market-adv-guide-de-featured.jpg';

    public const FEATURED_STORAGE = 'blogs/featured/market-adv-guide-de-featured.jpg';

    public const IMAGE_DASHBOARD = 'market-adv-guide-de-dashboard.jpg';

    public const IMAGE_CATALOG = 'market-adv-guide-de-catalog.jpg';

    public const IMAGE_CONTENT = 'howto-adv-content-library.jpg';

    public const IMAGE_FUNDS = 'howto-adv-add-funds.jpg';

    public const IMAGE_ORDERS = 'howto-adv-orders.jpg';

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
            'title' => 'Gastbeiträge kaufen auf SEOLinkBuildings: Advertiser-Leitfaden',
            'slug' => self::SLUG,
            'primary_locale' => 'de',
            'excerpt' => 'Praxis-Leitfaden für Advertiser: Konto anlegen, Wallet aufladen, Publisher im Katalog wählen, Artikel zuweisen und Bestellungen bis zur Live-URL verfolgen.',
            'content' => self::contentHtml(),
            'author' => 'Arslan Jason',
            'tags' => [
                'Advertiser-Leitfaden',
                'Gastbeiträge kaufen',
                'Marketplace',
                'Content Library',
                'Wallet',
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
                'question' => 'Muss ich Content hochladen, bevor ich eine Platzierung kaufen kann?',
                'answer' => 'Sie können Sites zuerst browsen und in den Warenkorb legen. Beim Checkout braucht jede Site einen freigegebenen Artikel. Laden Sie früh in der Content Library hoch, damit die Freigabe nicht zum Engpass bei der Zahlung wird.',
            ],
            [
                'question' => 'Was ist der Unterschied zwischen ausgabefähigem Guthaben und dem Willkommensbonus?',
                'answer' => 'Wenn eine Willkommensaktion aktiv ist, können neue Advertiser ein nur ausgebbares Guthaben für Platzierungen unter den Plattformregeln erhalten. Ausgabefähiges Guthaben ist eingezahltes Geld (Karte, Bank oder andere angebotene Methoden). Beim Checkout deckt das Wallet den Bestellbetrag gemäß diesen Regeln.',
            ],
            [
                'question' => 'Kann ich mit demselben Login zum Publisher-Konto wechseln?',
                'answer' => 'Ja. Viele Nutzer haben beide Rollen. Nutzen Sie „Switch to Publisher“ in der oberen Leiste, wenn Sie eigene Sites oder Tasks verwalten, und wechseln Sie zurück zu Advertiser zum Einkaufen.',
            ],
            [
                'question' => 'Wohin, wenn die Live-URL falsch aussieht?',
                'answer' => 'Öffnen Sie den Order-Chat, dokumentieren Sie das Problem mit der Live-URL und bitten Sie den Publisher um Korrektur. Führen Sie ein kurzes Log zu Attribut- und Anker-Checks — das hilft bei einer Eskalation.',
            ],
        ];
    }

    public static function contentHtml(): string
    {
        $marketplace = '/marketplace';
        $register = '/register';
        $howItWorks = '/how-it-works';
        $howItWorksDe = '/de/so-funktioniert-es';
        $publisherGuide = '/blog/publisher-guide-add-sites-complete-orders-withdraw';
        $liveCheck = '/blog/what-to-check-after-the-live-link-indexation-attributes-rankings';
        $imgDash = BlogInlineImages::publicUrl(self::IMAGE_DASHBOARD);
        $imgCatalog = BlogInlineImages::publicUrl(self::IMAGE_CATALOG);
        $imgContent = BlogInlineImages::publicUrl(self::IMAGE_CONTENT);
        $imgFunds = BlogInlineImages::publicUrl(self::IMAGE_FUNDS);
        $imgOrders = BlogInlineImages::publicUrl(self::IMAGE_ORDERS);

        return <<<HTML
<p>SEOLinkBuildings ist dafür gebaut, dass Advertiser Platzierungen so kaufen, wie Ops-Teams wirklich arbeiten: passende Publisher wählen, freigegebene Artikel anhängen, aus dem Wallet zahlen und die Bestellung bis zur Live-URL begleiten. Dieser Leitfaden führt den Weg ohne Theater.</p>
<p>Wenn Sie das Marketplace-Modell erst einmal auf einer Seite brauchen: <a href="{$howItWorksDe}">So funktioniert die Plattform</a> (auch unter <a href="{$howItWorks}">/how-it-works</a>). Wer Sites verkauft statt kauft, liest den <a href="{$publisherGuide}">Publisher-Leitfaden</a>.</p>

<h2>1. Advertiser-Konto anlegen und E-Mail verifizieren</h2>
<p>Registrieren Sie sich unter <a href="{$register}">/register</a> und wählen Sie die Advertiser-Rolle. Bestätigen Sie die Verifizierungsmail, bevor Sie vollen Zugang erwarten — unverifizierte Konten kommen durch den normalen Login nicht durch.</p>
<p>Nach der Verifizierung landen Sie in der Advertiser-Oberfläche. Wenn eine Willkommensaktion aktiv ist, behandeln Sie gutgeschriebenes Guthaben als Kaufkraft für Platzierungen, nicht als auszahlbares Bargeld.</p>

<figure>
<img src="{$imgDash}" alt="Advertiser-Dashboard auf SEOLinkBuildings nach dem Login" loading="lazy" width="1200" height="675">
<figcaption>Advertiser-Dashboard — starten Sie mit dem Katalog oder öffnen Sie die Content Library vor dem Checkout.</figcaption>
</figure>

<p>Vom Dashboard aus öffnen Sie den Katalog, starten eine geführte Platzierung oder laden Artikel hoch. Die linke Navigation bleibt gleich: Catalog, Content Library, Orders, Add Funds, Billing und Reports.</p>

<h2>2. Artikel in der Content Library hochladen — bevor Sie sie brauchen</h2>
<p>Jede Site in einer Bestellung braucht einen eigenen freigegebenen Artikel. Laden Sie früh hoch. Wer wartet, bis der Warenkorb voll ist, verpasst Deadlines.</p>

<figure>
<img src="{$imgContent}" alt="Content Library für das Hochladen von Gastbeitrags-Artikeln" loading="lazy" width="1200" height="675">
<figcaption>Content Library — hochladen, Freigabe abwarten, dann Artikel im Warenkorb zuweisen.</figcaption>
</figure>

<p>Nutzen Sie klare Dateinamen und halten Sie einen Artikel pro geplanter Platzierung. Sobald eine Datei freigegeben ist, können Sie sie beim Checkout anhängen. Läuft die Freigabe noch, dürfen Sie weiter browsen — zahlen sollten Sie erst, wenn Content bereit ist.</p>

<h2>3. Katalog browsen und Publisher auswählen</h2>
<p>Öffnen Sie <strong>Catalog</strong>. Filtern Sie nach Land, Sprache, Nische und Kennzahlen, die zu Ihrem Briefing passen. Legen Sie Sites nur in den Warenkorb, wenn die Listing-Bedingungen (Link-Typ, Sponsored-Status, Turnaround) zu dem passen, was Sie kaufen wollen.</p>

<figure>
<img src="{$imgCatalog}" alt="Advertiser-Katalog zum Durchsuchen verifizierter Publisher-Websites" loading="lazy" width="1200" height="675">
<figcaption>Katalog — verifizierte Publisher vergleichen, Items im Warenkorb halten, zahlen wenn Content und Budget stehen.</figcaption>
</figure>

<p>Sie können weiter einkaufen, während schon Items im Warenkorb liegen. Den geführten Flow brauchen Sie nur, wenn der Stepper Market → Publishers → Content → Pay Ihnen Struktur gibt. Die meisten Power-User bleiben im Katalog und öffnen den Warenkorb, wenn die Shortlist steht.</p>
<p>Öffentliche Inventar-Übersichten finden Sie auf den <a href="{$marketplace}">Marketplace</a>-Seiten; gekauft wird im eingeloggten Katalog.</p>

<h2>4. Wallet aufladen, dann im Checkout zahlen</h2>
<p>Öffnen Sie <strong>Add Funds</strong>, wenn das ausgabefähige Guthaben den Warenkorb nicht deckt. Wählen Sie die Methode, die für Ihr Konto angeboten wird (Karte über Stripe, Banküberweisung oder andere Optionen auf der Seite). Kartendaten verarbeitet Stripe; sie liegen nicht auf unseren Servern.</p>

<figure>
<img src="{$imgFunds}" alt="Add-Funds-Seite zum Aufladen des Advertiser-Wallets" loading="lazy" width="1200" height="675">
<figcaption>Add Funds — ausgabefähiges Guthaben vor oder während des Checkouts aufladen.</figcaption>
</figure>

<p>Im Checkout weisen Sie jeder Site einen freigegebenen Artikel zu, prüfen die Summen und zahlen aus dem Wallet. Rechnungen liegen unter Billing, falls Finance später einen Beleg braucht.</p>

<h2>5. Bestellungen bis zur akzeptierten Live-URL verfolgen</h2>
<p><strong>Orders</strong> ist die operative Ansicht nach der Zahlung. Beobachten Sie Statusänderungen, nutzen Sie den Order-Chat für Rückfragen und prüfen Sie die Live-URL, wenn der Publisher die Platzierung als erledigt markiert.</p>

<figure>
<img src="{$imgOrders}" alt="Advertiser-Orders-Seite zum Verfolgen von Gastbeitrags-Platzierungen" loading="lazy" width="1200" height="675">
<figcaption>Orders — jede Platzierung von der Zahlung bis zur Live-URL-Prüfung begleiten.</figcaption>
</figure>

<p>Wenn eine URL live ist, machen Sie einen kurzen QA-Durchlauf: richtige Seite, richtiges Ziel, erwarteter Anker, erwartete Link-Attribute, Indexierung. Die Checkliste unter <a href="{$liveCheck}">was nach dem Live-Link zu prüfen ist</a> ist genau für diesen Moment geschrieben.</p>

<h2>6. Gewohnheiten, die Kampagnen sauber halten</h2>
<ul>
<li>Content hochladen, bevor Sie einen großen Warenkorb füllen.</li>
<li>Filter und Shortlists speichern, damit Nachbestellungen nicht bei null starten.</li>
<li>Wallet mit Puffer aufladen; nicht jede einzelne Platzierung einzeln zahlen, wenn Sie wöchentliche Batches fahren.</li>
<li>Live-URLs und Attribute am Tag des Eingangs loggen — nicht einen Monat später.</li>
</ul>
<p>Genau diese Disziplin trennt ein Marketplace-Konto von einer Excel-Liste vergessener Links. <a href="{$register}">Advertiser-Konto anlegen</a>, E-Mail verifizieren und die erste Bestellung mit bereits freigegebenem Content platzieren.</p>

<h2>Häufig gestellte Fragen</h2>
<h3>Muss ich Content hochladen, bevor ich eine Platzierung kaufen kann?</h3>
<p>Sie können Sites zuerst browsen und in den Warenkorb legen. Beim Checkout braucht jede Site einen freigegebenen Artikel. Laden Sie früh in der Content Library hoch, damit die Freigabe nicht zum Engpass bei der Zahlung wird.</p>
<h3>Was ist der Unterschied zwischen ausgabefähigem Guthaben und dem Willkommensbonus?</h3>
<p>Wenn eine Willkommensaktion aktiv ist, können neue Advertiser ein nur ausgebbares Guthaben für Platzierungen unter den Plattformregeln erhalten. Ausgabefähiges Guthaben ist eingezahltes Geld (Karte, Bank oder andere angebotene Methoden). Beim Checkout deckt das Wallet den Bestellbetrag gemäß diesen Regeln.</p>
<h3>Kann ich mit demselben Login zum Publisher-Konto wechseln?</h3>
<p>Ja. Viele Nutzer haben beide Rollen. Nutzen Sie „Switch to Publisher“ in der oberen Leiste, wenn Sie eigene Sites oder Tasks verwalten, und wechseln Sie zurück zu Advertiser zum Einkaufen.</p>
<h3>Wohin, wenn die Live-URL falsch aussieht?</h3>
<p>Öffnen Sie den Order-Chat, dokumentieren Sie das Problem mit der Live-URL und bitten Sie den Publisher um Korrektur. Führen Sie ein kurzes Log zu Attribut- und Anker-Checks — das hilft bei einer Eskalation.</p>
HTML;
    }
}
