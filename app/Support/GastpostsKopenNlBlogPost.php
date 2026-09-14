<?php

namespace App\Support;

/**
 * Dutch advertiser guide: buying guest posts on SEOLinkBuildings.
 */
class GastpostsKopenNlBlogPost
{
    public const SLUG = 'gastposts-kopen-op-seolinkbuildings-adverteerdersgids';

    public const FEATURED_ASSET = 'assets/img/blog/market-nl-buy-featured.jpg';

    public const FEATURED_STORAGE = 'blogs/featured/market-nl-buy-featured.jpg';

    public const IMAGE_CATALOG = 'market-nl-buy-catalog.jpg';

    public const IMAGE_FUNDS = 'howto-adv-add-funds.jpg';

    public const IMAGE_CONTENT = 'howto-adv-content-library.jpg';

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
            'title' => 'Gastposts kopen op SEOLinkBuildings: gids voor adverteerders',
            'slug' => self::SLUG,
            'primary_locale' => 'nl',
            'excerpt' => 'Praktische walkthrough voor adverteerders: account aanmaken, EUR-wallet vullen, catalogus filteren, content klaarzetten, afrekenen en orders volgen tot de live URL.',
            'content' => self::contentHtml(),
            'author' => 'Arslan Jason',
            'tags' => [
                'Adverteerdersgids',
                'Gastposts',
                'Marketplace',
                'Wallet',
                'Catalogus',
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
                'question' => 'Moet ik content uploaden voordat ik een plaatsing koop?',
                'answer' => 'Je mag eerst browsen en sites in de winkelwagen zetten. Bij checkout verwacht elk site een goedgekeurd artikel. Upload vroeg in de contentbibliotheek, zodat goedkeuring niet de bottleneck wordt bij betalen.',
            ],
            [
                'question' => 'Hoe werkt de wallet in euro’s?',
                'answer' => 'Je stort besteedbaar saldo (kaart, bankoverschrijving of een andere aangeboden methode). Als een welkomstactie actief is, kan besteedbaar welkomstkrediet gelden onder de platformregels. Bij betaling dekt de wallet het ordertotaal volgens die regels.',
            ],
            [
                'question' => 'Wat als de live URL niet klopt?',
                'answer' => 'Open de orderchat, noteer de URL en wat er mis is (anker, attributen, pagina). Vraag de uitgever om correctie. Bewaar een korte log — die telt mee bij escalatie of escrow-regels.',
            ],
            [
                'question' => 'Kan ik alleen op NL en BE filteren?',
                'answer' => 'Ja. Gebruik land- en taalfilters in de catalogus. Voor Nederlandstalige campagnes kies je Nederlands plus sites waarvan verkeer of primair land bij NL of BE past — niet alleen een Europees label.',
            ],
        ];
    }

    public static function contentHtml(): string
    {
        $register = '/register';
        $marketplace = '/marketplace';
        $howItWorks = '/how-it-works';
        $walletBlog = '/blog/wallet-escrow-and-refunds-explained';
        $imgCatalog = BlogInlineImages::publicUrl(self::IMAGE_CATALOG);
        $imgFunds = BlogInlineImages::publicUrl(self::IMAGE_FUNDS);
        $imgContent = BlogInlineImages::publicUrl(self::IMAGE_CONTENT);

        return <<<HTML
<p>Gastposts kopen hoeft geen gok te zijn. SEOLinkBuildings is ingericht zoals operations-teams werken: account, wallet, catalogus, goedgekeurde artikelen, betalen, order volgen tot de live URL er is.</p>
<p>Deze gids volgt precies die route. Wil je eerst het marketplace-model in één pagina, lees <a href="{$howItWorks}">how it works</a>. Kom daarna terug om echt te bestellen.</p>

<h2>1. Adverteerdersaccount aanmaken en e-mail verifiëren</h2>
<p>Registreer op <a href="{$register}">/register</a> en kies de adverteerdersrol. Bevestig de verificatiemail voordat je volledige toegang verwacht — zonder verificatie komt er geen normale login-sessie.</p>
<p>Na verificatie land je in de adverteerdersshell. Als een welkomstactie actief is, is eventueel welkomstkrediet koopkracht voor plaatsingen, geen cash-opname.</p>
<p>De navigatie blijft consistent: Catalogus, Contentbibliotheek, Orders, Saldo toevoegen, Facturatie. Onthoud dat schema — bijna alles hangt eraan.</p>

<h2>2. Wallet in EUR aanvullen</h2>
<p>Open <strong>Saldo toevoegen</strong> zodra de winkelwagen je saldo dreigt te overschrijden. Kies de methode die voor jouw account zichtbaar is (kaart via Stripe, bankoverschrijving, enz.). Kaartgegevens gaan via Stripe; ze blijven niet op onze servers staan.</p>

<figure>
<img src="{$imgFunds}" alt="Pagina Saldo toevoegen om de adverteerderswallet te vullen" loading="lazy" width="1200" height="675">
<figcaption>Saldo toevoegen — vul besteedbaar saldo bij vóór of tijdens checkout.</figcaption>
</figure>

<p>De wallet is in euro’s. Of je nu Nederlandse of Belgische publishers koopt: op het platform zie en betaal je in EUR. Meer over escrow en restituties: <a href="{$walletBlog}">wallet, escrow and refunds explained</a>.</p>
<p>Simpele tip: stort met een buffer. Per plaatsing bijbetalen vertraagt wekelijkse batches.</p>

<h2>3. Catalogus filteren (niet alleen op hoogste DR)</h2>
<p>Open de <strong>Catalogus</strong>. Filter op land, taal, niche en realistische metric-vloeren. Zet een site alleen in de winkelwagen als linktype, sponsored-status en doorlooptijd kloppen met wat je wilt kopen.</p>

<figure>
<img src="{$imgCatalog}" alt="SEOLinkBuildings-catalogus met filters en uitgeverssites" loading="lazy" width="1200" height="675">
<figcaption>Catalogus — filter eerst, vergelijk daarna DR, verkeer en niche op de shortlist.</figcaption>
</figure>

<p>Voor NL/BE start je met Nederlands + Nederland of België. Een “sterke” Engelse site met US-publiek helpt een regionale Nederlandse landing zelden.</p>
<p>Publieke inventaris staat samengevat op de <a href="{$marketplace}">marketplace</a>-pagina’s; echt kopen doe je in de ingelogde catalogus. Je mag items in de cart laten staan en verder zoeken. Een cart van twintig sites zonder content klaar betekent vooral vertraging betalen.</p>

<h2>4. Artikelen klaarzetten in de contentbibliotheek</h2>
<p>Elke site in een order heeft een eigen goedgekeurd artikel nodig. Upload vroeg. Wachten tot de cart vol is, is hoe teams deadlines missen.</p>

<figure>
<img src="{$imgContent}" alt="Contentbibliotheek om gastpost-artikelen te uploaden" loading="lazy" width="1200" height="675">
<figcaption>Contentbibliotheek — upload, wacht op goedkeuring, wijs toe bij checkout.</figcaption>
</figure>

<p>Gebruik duidelijke bestandsnamen. Eén artikel per geplande plaatsing. Na goedkeuring koppel je het bij betaling. Loopt goedkeuring nog? Rond browsen af — reken er niet op dat checkout doorgaat zonder content.</p>
<p>Minimaal nuttig briefing: doel-URL, gewenst anker, taboes, toon, en wat de uitgever mag weigeren. Vage briefs maken narework. Narework vertraagt live URL’s.</p>

<h2>5. Checkout: toewijzen, bevestigen, betalen</h2>
<p>Bij checkout wijs je per site een goedgekeurd artikel toe, check je het totaal en betaal je vanuit de wallet. Facturen blijven onder Facturatie als finance die later nodig heeft.</p>
<p>Dekking tekort? Vul bij en kom terug. De guided flow (Market → Publishers → Content → Pay) helpt beginners; power users blijven vaak in de catalogus en openen de cart als de shortlist klaar is.</p>

<h2>6. Orders volgen tot de live URL</h2>
<p><strong>Orders</strong> is de operationele view na betaling. Volg statuswijzigingen, gebruik orderchat voor verduidelijking, en controleer de live URL wanneer de uitgever de plaatsing afrondt.</p>
<p>Korte QA op de dag zelf:</p>
<ul>
<li>juiste pagina, juiste doel-URL</li>
<li>verwacht anker</li>
<li>linkattributen (dofollow / nofollow / sponsored) zoals in de listing</li>
<li>indexatie — of in ieder geval een crawlable, coherente pagina</li>
</ul>
<p>Documenteer dat meteen. Een maand later “nog even checken” is hoe slechte leveringen onopgemerkt blijven.</p>

<h2>Gewoontes die campagnes schoon houden</h2>
<ul>
<li>Content uploaden vóór een grote cart</li>
<li>Filters en shortlists bewaren voor herorders</li>
<li>Wallet met buffer vullen</li>
<li>Live URL’s en attributen dezelfde dag loggen</li>
</ul>
<p>Die discipline scheidt een marketplace-account van een spreadsheet met vergeten links. <a href="{$register}">Maak je adverteerdersaccount</a>, verifieer je e-mail, en plaats de eerste order met content die al goedgekeurd is.</p>

<h2>Veelgestelde vragen</h2>
<h3>Moet ik content uploaden voordat ik een plaatsing koop?</h3>
<p>Je mag eerst browsen en sites in de winkelwagen zetten. Bij checkout verwacht elk site een goedgekeurd artikel. Upload vroeg in de contentbibliotheek, zodat goedkeuring niet de bottleneck wordt bij betalen.</p>
<h3>Hoe werkt de wallet in euro’s?</h3>
<p>Je stort besteedbaar saldo (kaart, bankoverschrijving of een andere aangeboden methode). Als een welkomstactie actief is, kan besteedbaar welkomstkrediet gelden onder de platformregels. Bij betaling dekt de wallet het ordertotaal volgens die regels.</p>
<h3>Wat als de live URL niet klopt?</h3>
<p>Open de orderchat, noteer de URL en wat er mis is (anker, attributen, pagina). Vraag de uitgever om correctie. Bewaar een korte log — die telt mee bij escalatie of escrow-regels.</p>
<h3>Kan ik alleen op NL en BE filteren?</h3>
<p>Ja. Gebruik land- en taalfilters in de catalogus. Voor Nederlandstalige campagnes kies je Nederlands plus sites waarvan verkeer of primair land bij NL of BE past — niet alleen een Europees label.</p>
HTML;
    }
}
