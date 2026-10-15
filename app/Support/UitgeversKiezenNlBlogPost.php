<?php

namespace App\Support;

/**
 * Dutch guide: choosing publishers by DR, DA, traffic and niche.
 */
class UitgeversKiezenNlBlogPost
{
    public const SLUG = 'uitgevers-kiezen-dr-da-verkeer-en-niche';

    public const FEATURED_ASSET = 'assets/img/blog/market-nl-choose-featured.jpg';

    public const FEATURED_STORAGE = 'blogs/featured/market-nl-choose-featured.jpg';

    public const IMAGE_CATALOG = 'market-nl-choose-catalog.jpg';

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
            'title' => 'Uitgevers kiezen: DR, DA, verkeer en niche',
            'slug' => self::SLUG,
            'primary_locale' => 'nl',
            'excerpt' => 'Praktische shortlist voor gastpost-sites: DR en DA als filter gebruiken, verkeer checken, en niche plus taal/land (NL/BE) nooit overslaan.',
            'content' => self::contentHtml(),
            'author' => 'M. Arslan',
            'tags' => [
                'Catalogus',
                'Domain Rating',
                'Verkeer',
                'Gastposts',
                'Adverteerdertips',
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
                'question' => 'Is een hoge DR altijd beter dan een mid-DR nicheblog?',
                'answer' => 'Nee. Een DR 70-site in de verkeerde niche kan slechter presteren dan een DR 28-site die al rankt op jouw onderwerp. Relevantie en echt verkeer winnen meestal van een prestige-score.',
            ],
            [
                'question' => 'Verkeer ziet er goed uit, maar het sample-artikel is dun. Wat dan?',
                'answer' => 'Zie dat als een rode vlag. Open het sample, skim recente posts, en sla listings over die voelen als linkfarms — ook als de metrics netjes ogen.',
            ],
            [
                'question' => 'Moet ik alleen geverifieerde sites filteren?',
                'answer' => 'Verified helpt, maar is niet het hele verhaal. Combineer het met niche-match, doorlooptijd, linktype en een echte blik op het sample-artikel.',
            ],
            [
                'question' => 'Hoeveel sites shortlist ik vóór ik koop?',
                'answer' => 'Voor een eerste campagne zijn vijf tot tien sterke opties genoeg. Een enorme cart vullen zonder content klaar betekent later vertraging betalen.',
            ],
        ];
    }

    public static function contentHtml(): string
    {
        $marketplace = '/marketplace';
        $register = '/register';
        $buyGuide = '/blog/gastposts-kopen-op-seolinkbuildings-adverteerdersgids';
        $enChoose = '/blog/how-to-choose-a-publisher-site-dr-da-traffic-niche';
        $imgCatalog = BlogInlineImages::publicUrl(self::IMAGE_CATALOG);

        return <<<HTML
<p>De meeste mensen openen een gastpost-catalogus en sorteren op het grootste getal dat ze herkennen. Zo koop je dure links die bijna niets doen voor de pagina die ertoe doet.</p>
<p>Deze gids is de langzamere, betere gewoonte: gebruik Domain Rating (DR), Domain Authority (DA) en verkeer als filters — beslis daarna op niche, sample-content en listingvoorwaarden. Ken je het productpad nog niet? Lees eerst de <a href="{$buyGuide}">adverteerdersgids</a>, kom dan terug vóór je de cart vult.</p>

<h2>Begin met de klus, niet met de metric</h2>
<p>Schrijf één zin vóór je filtert: “Ik heb plaatsingen nodig die <em>dit</em> onderwerp steunen voor <em>dit</em> land/taal.” Die zin schrapt al half de slechte opties.</p>
<p>Voorbeelden die werken voor NL/BE:</p>
<ul>
<li>Nederlandstalige finance- of woningblogs met dofollow-links naar een vergelijkingspagina</li>
<li>Belgische lokale media voor een regionale landing</li>
<li>B2B SaaS-sites in het Nederlands — niet een reisblog “omdat de DR mooi is”</li>
</ul>
<p>Kun je die zin niet schrijven, dan shop je. Je koopt nog niet.</p>

<figure>
<img src="{$imgCatalog}" alt="Catalogus met uitgeversmetrics en filters voor land en taal" loading="lazy" width="1200" height="675">
<figcaption>Catalogusview — filter eerst, vergelijk daarna DR, DA, verkeer en niche.</figcaption>
</figure>

<h2>Waar DR en DA wél (en niet) voor dienen</h2>
<p>DR (Ahrefs) en DA (Moz) zijn vergelijkende scores. Ze helpen een lange lijst te sorteren. Ze bewijzen niet dat een site morgen je rankings verschuift.</p>
<ul>
<li><strong>Vloer, geen trofee.</strong> Zet een minimum dat past bij je budgettier, staar daarna niet naar het plafond.</li>
<li><strong>Vergelijk binnen een niche.</strong> Een DR 40-reisblog en een DR 40-casino-PBN zijn niet dezelfde aankoop.</li>
<li><strong>Let op mismatch.</strong> Zeer hoge DR met bijna geen verkeer (of omgekeerd) verdient een tweede blik op sample en outbound links.</li>
</ul>
<p>Op SEOLinkBuildings staan balken onder de cijfers zodat een “55” schaal heeft. Handig om een resultaatpagina te scannen zonder elk digit als evangelie te behandelen.</p>

<h2>Verkeer: signaal boven vanity</h2>
<p>Maandelijkse verkeersschattingen blijven schattingen. Ze zijn nuttig als je vraagt: “Leest iemand deze site überhaupt?”</p>
<ol>
<li>Kies sites met geloofwaardig, stabiel verkeer voor de niche — geen piek die geleend oogt.</li>
<li>Match verkeersgeografie met NL of BE als de listing een primair land toont.</li>
<li>Laag verkeer op een strak topicale, geïndexeerde site kan nog steeds een trial-order waard zijn. Eén relevant referring domain wint vaak van drie willekeurige “authority”-links.</li>
</ol>

<h2>Taal en land voor Nederland en België</h2>
<p>Nederlandstalig is geen één markt. Een .nl-site met Nederlands publiek is niet hetzelfde als een Vlaamse of Belgische publisher, ook al is de taal Nederlands. Filter op taal <em>én</em> land. Check of content lokaal aanvoelt (voorbeelden, regelgeving, toon) of vaag “globaal”.</p>
<p>Val niet voor het Engelse valkuil-listing: een sterke US-Engelse site voor een NL-campagne is duur en levert weinig op. Bewaar Engels voor pagina’s die echt internationaal zijn.</p>

<h2>Nichefit wint bijna altijd</h2>
<p>Open het sample-artikel. Lees de categorieën. Vraag of jouw anker en URL er natuurlijk uitzien op die pagina.</p>
<p>Sla over wanneer:</p>
<ul>
<li>het sample gespind of vol outbound links zit</li>
<li>de niche “marketing” zegt maar elk post gambling-offers duwt</li>
<li>doorlooptijd of linktype niet past bij wat je nodig hebt</li>
</ul>
<p>Gevoelige topics (crypto, CBD, forex, enz.) hebben vaak een toeslag. Dat is normaal. Wat niet normaal is: het onderwerp verbergen tot na checkout. Kies de add-on bewust.</p>

<h2>Korte shortlist die werkt</h2>
<ol>
<li>Filter land, taal en categorie.</li>
<li>Zet ruwe DR/DA- en verkeersvloeren die bij het budget passen.</li>
<li>Open vijf tot tien listings. Check sample, linktype, doorlooptijd, prijs.</li>
<li>Voeg alleen toe wat je volgende maand nog wilt.</li>
<li>Upload content vóór checkout — zie de <a href="{$buyGuide}">koopgids</a> — betaal daarna vanuit de wallet.</li>
</ol>
<p>De Engelse zus van dit denken staat hier: <a href="{$enChoose}">how to choose a publisher site</a>. Het principe blijft; de taal/land-filter verandert wél.</p>

<h2>Veelgemaakte fouten</h2>
<ul>
<li>Alleen sorteren op hoogste DR en de eerste drie rijen kopen</li>
<li>Taal en land negeren omdat de prijs aantrekkelijk leek</li>
<li>Een cart van 20 sites vullen zonder goedgekeurde artikelen</li>
<li>“Verified” behandelen als vervanging voor het sample lezen</li>
</ul>
<p>Bekijk de <a href="{$marketplace}">marketplace</a> voor het model in gewone taal. Klaar om serieus te shortlisten? <a href="{$register}">Maak een adverteerdersaccount</a> met een geschreven brief bij de hand.</p>

<h2>Veelgestelde vragen</h2>
<h3>Is een hoge DR altijd beter dan een mid-DR nicheblog?</h3>
<p>Nee. Een DR 70-site in de verkeerde niche kan slechter presteren dan een DR 28-site die al rankt op jouw onderwerp. Relevantie en echt verkeer winnen meestal van een prestige-score.</p>
<h3>Verkeer ziet er goed uit, maar het sample-artikel is dun. Wat dan?</h3>
<p>Zie dat als een rode vlag. Open het sample, skim recente posts, en sla listings over die voelen als linkfarms — ook als de metrics netjes ogen.</p>
<h3>Moet ik alleen geverifieerde sites filteren?</h3>
<p>Verified helpt, maar is niet het hele verhaal. Combineer het met niche-match, doorlooptijd, linktype en een echte blik op het sample-artikel.</p>
<h3>Hoeveel sites shortlist ik vóór ik koop?</h3>
<p>Voor een eerste campagne zijn vijf tot tien sterke opties genoeg. Een enorme cart vullen zonder content klaar betekent later vertraging betalen.</p>
HTML;
    }
}
