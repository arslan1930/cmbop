<?php

namespace App\Support;

/**
 * Italian body for the English dofollow/nofollow pillar.
 */
class DofollowNofollowAnchorsI18n
{
    /**
     * @return array<string, array{title: string, slug: string, excerpt: string, content: string, meta_title: string, meta_description: string}>
     */
    public static function all(): array
    {
        return [
            'it' => [
                'title' => 'Dofollow vs nofollow: attributi, ancore e rel sponsored',
                'slug' => 'dofollow-vs-nofollow',
                'excerpt' => 'Cosa significano dofollow, nofollow e rel sponsored in pratica, come mescolare gli anchor text e cosa controllare prima di comprare un link a catalogo.',
                'meta_title' => 'Dofollow vs nofollow: attributi, ancore e sponsored',
                'meta_description' => 'Dofollow vs nofollow e rel sponsored: cosa significa, come variare gli anchor text e come verificare il link dopo la pubblicazione sul marketplace.',
                'content' => self::it(),
            ],
        ];
    }

    private static function it(): string
    {
        $marketplace = '/it/mercato';
        $register = '/register';
        $buy = '/it/comprare-guest-post';
        $links = '/it/comprare-backlink';
        $liveCheck = '/blog/what-to-check-after-the-live-link-indexation-attributes-rankings';
        $briefGuide = '/blog/guest-post-brief-anchors-urls-images-sensitive-topics';
        $imgTypes = BlogInlineImages::publicUrl(DofollowNofollowAnchorsEnBlogPost::IMAGE_TYPES);
        $imgMix = BlogInlineImages::publicUrl(DofollowNofollowAnchorsEnBlogPost::IMAGE_MIX);

        return <<<HTML
<p>Due domande tornano sempre: “Il link è dofollow?” e “Che anchor usiamo?” Giuste. Entrambe possono influire. Entrambe si sopravvalutano se rilevanza e qualità del sito sono già deboli.</p>
<p>Questa guida copre <strong>cosa significano dofollow e nofollow in pratica</strong>, come gli anchor sembrano naturali, e cosa controllare sugli ordini di marketplace. Accoppiala alla <a href="{$briefGuide}">checklist del brief</a>.</p>

<h2>Dofollow e nofollow senza mito</h2>
<p>In breve:</p>
<ul>
<li><strong>Dofollow</strong> (più precisamente: un link senza <code>rel</code> bloccante): può passare segnali di ranking.</li>
<li><strong>Nofollow</strong> (<code>rel="nofollow"</code>, spesso con <code>sponsored</code> / <code>ugc</code>): segnala “non trattarlo come endorsement editoriale”. Il valore SEO è più limitato — non automaticamente zero.</li>
</ul>
<p>Google ha ammorbidito il vecchio mondo “nofollow = ignora”. Resta: se stai costruendo autorità di proposito, i dofollow su siti adatti restano la leva più chiara. Il nofollow è un complemento, non un sostituto.</p>

<figure>
<img src="{$imgTypes}" alt="Link nel contenuto a confronto con pattern outbound deboli" loading="lazy" width="1200" height="675">
<figcaption>Dove sta il link conta quanto l’attributo rel: il contesto batte il cosmetico.</figcaption>
</figure>

<h2>Rel sponsored: cosa significa</h2>
<p><code>rel="sponsored"</code> è l’attributo documentato da Google (2019) per pubblicità e accordi a pagamento. “Rel sponsored cosa significa” in pratica: stai dicendo al motore che c’è un corrispettivo. Non è un insulto al publisher. Chiedere di toglierlo dopo il live, se il listing lo prevedeva, è una trattativa persa in partenza.</p>

<h2>Cosa conta sui link da marketplace (in ordine)</h2>
<ol>
<li><strong>Nicchia e mercato:</strong> un sito in lingua per il tuo intento batte un dominio “forte” a caso.</li>
<li><strong>Posizione nel contenuto:</strong> corpo &gt; footer &gt; spam in author box.</li>
<li><strong>Attributo:</strong> dofollow aiuta — quando 1 e 2 già tornano.</li>
<li><strong>Anchor text:</strong> naturale nella frase, non un timbro keyword.</li>
<li><strong>Ritmo e mix:</strong> una pila di exact-match dofollow in due settimane raramente sembra organica.</li>
</ol>
<p>Filtrare “dofollow = sì” e ignorare il resto è comprare attributi. Non visibilità. Vedi anche <a href="{$links}">acquistare backlink</a>.</p>

<h2>Anchor text guest post: un mix che non urla “campagna”</h2>
<p>L’anchor è la frase cliccabile. Se ogni altro link dice “miglior prestito 2026”, il profilo sembra guidato — DR o non DR.</p>

<figure>
<img src="{$imgMix}" alt="Pianificare un mix di ancore in una bozza editoriale" loading="lazy" width="1200" height="675">
<figcaption>Brand, URL, partial match, generico: la varietà si legge più calma dello staccato keyword.</figcaption>
</figure>

<p>Un mix lavorabile:</p>
<ul>
<li><strong>Brand:</strong> nome azienda</li>
<li><strong>URL nuda:</strong> seolinkbuildings.com</li>
<li><strong>Partial match:</strong> “acquistare guest post in Italia”, “confronta il catalogo”</li>
<li><strong>Generico:</strong> “qui”, “in questa pagina”, “approfondisci”</li>
<li><strong>Exact match:</strong> con parsimonia, e solo se la frase starebbe in piedi lo stesso</li>
</ul>

<h2>Verificare il backlink dofollow dopo la live</h2>
<p>Apri l’URL, ispeziona il <code>rel</code>, annota in chat d’ordine. Se non c’è nofollow/sponsored/ugc nella forma rilevante, in pratica è spesso dofollow. L’indicizzazione è un controllo a parte — <a href="{$liveCheck}">checklist del live link</a>.</p>
<p>Nel <a href="{$marketplace}">catalogo</a> confronti le offerte per tipo di link. Usalo come indizio, non come unica verità. Poi <a href="{$buy}">acquista il guest post</a> o <a href="{$register}">crea un account</a>.</p>

<h2>Errori frequenti</h2>
<ul>
<li>Exact-match su ogni pubblicazione</li>
<li>Dofollow a tutti i costi su siti irrilevanti</li>
<li>Trattare il nofollow come spazzatura</li>
<li>Cambiare l’anchor dopo che l’articolo è live, senza accordo</li>
<li>Dieci dofollow su un dominio fresco in una settimana</li>
</ul>
<p>Niente di questo è una penalità automatica. Gran parte sembra finto. Il finto è un brutto segnale.</p>
HTML;
    }
}
