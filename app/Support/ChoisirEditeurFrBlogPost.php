<?php

namespace App\Support;

/**
 * French guide: choosing publishers by DR, DA, traffic and relevance.
 */
class ChoisirEditeurFrBlogPost
{
    public const SLUG = 'choisir-un-editeur-dr-da-trafic-et-pertinence';

    public const FEATURED_ASSET = 'assets/img/blog/market-fr-choose-featured.jpg';

    public const FEATURED_STORAGE = 'blogs/featured/market-fr-choose-featured.jpg';

    public const IMAGE_CATALOG = 'market-fr-choose-catalog.jpg';

    public const IMAGE_TRUST = 'trust-choose-publisher-inline.jpg';

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
            'title' => 'Choisir un éditeur : DR, DA, trafic et pertinence',
            'slug' => self::SLUG,
            'primary_locale' => 'fr',
            'excerpt' => 'Méthode simple pour sélectionner des sites guest post : lire DR et DA sans les adorer, vérifier le trafic, et ne jamais négliger niche, langue et pays (FR/BE).',
            'content' => self::contentHtml(),
            'author' => 'Arslan Jason',
            'tags' => [
                'Catalogue',
                'Domain Rating',
                'Trafic',
                'Guest posts',
                'Conseils annonceur',
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
                'question' => 'Un DR élevé bat-il toujours un blog de niche moyen ?',
                'answer' => 'Non. Un site DR 70 hors sujet peut sous-performer un DR 28 déjà classé sur votre thème. La pertinence et un trafic réel battent souvent un score de prestige.',
            ],
            [
                'question' => 'Le trafic a l’air correct, mais l’article témoin est creux. Je fais quoi ?',
                'answer' => 'Traitez-le comme un signal d’alerte. Ouvrez l’échantillon, parcourez les derniers posts, et évitez les fiches qui ressemblent à des fermes de liens — même si les métriques sont propres.',
            ],
            [
                'question' => 'Faut-il filtrer uniquement les sites vérifiés ?',
                'answer' => 'La vérification aide, mais ce n’est pas toute l’histoire. Combinez-la avec le match de niche, le délai, le type de lien et un vrai regard sur l’article témoin.',
            ],
            [
                'question' => 'Combien de sites sélectionner avant d’acheter ?',
                'answer' => 'Pour une première campagne, cinq à dix options solides suffisent. Remplir un énorme panier sans contenu prêt, c’est payer du retard plus tard.',
            ],
        ];
    }

    public static function contentHtml(): string
    {
        $marketplace = '/fr/marche';
        $register = '/register';
        $buyGuide = '/blog/acheter-des-guest-posts-sur-seolinkbuildings-guide-annonceur';
        $enChoose = '/blog/how-to-choose-a-publisher-site-dr-da-traffic-niche';
        $imgCatalog = BlogInlineImages::publicUrl(self::IMAGE_CATALOG);
        $imgTrust = BlogInlineImages::publicUrl(self::IMAGE_TRUST);

        return <<<HTML
<p>La plupart des gens ouvrent un catalogue de guest posts et trient par le plus gros chiffre qu’ils reconnaissent. C’est ainsi qu’on finit avec des liens chers qui ne font presque rien pour la page qui compte vraiment.</p>
<p>Cette méthode est plus lente — et meilleure : utilisez Domain Rating (DR), Domain Authority (DA) et le trafic comme filtres, puis décidez avec la niche, l’échantillon et les conditions de l’annonce. Si le parcours produit vous est encore flou, lisez d’abord le <a href="{$buyGuide}">guide annonceur</a>, puis revenez ici avant de remplir le panier.</p>

<h2>Commencez par le job, pas par la métrique</h2>
<p>Écrivez une phrase avant tout filtre : « J’ai besoin de placements qui soutiennent <em>ce</em> sujet pour <em>ce</em> pays / cette langue. » Cette phrase élimine déjà la moitié des mauvais choix.</p>
<p>Exemples qui fonctionnent pour FR/BE :</p>
<ul>
<li>Blogs finance ou immobilier en français, liens dofollow vers une page comparateur</li>
<li>Médias locaux belges pour une landing régionale</li>
<li>Sites SaaS / B2B francophones pour une doc produit, pas un blog voyage « parce que le DR est beau »</li>
</ul>
<p>Si vous ne pouvez pas écrire cette phrase, vous faites du shopping — pas un achat.</p>

<figure>
<img src="{$imgCatalog}" alt="Catalogue avec métriques éditeur et filtres pays langue" loading="lazy" width="1200" height="675">
<figcaption>Vue catalogue — filtrez d’abord, comparez ensuite DR, DA, trafic et niche.</figcaption>
</figure>

<h2>À quoi servent DR et DA (et à quoi ils ne servent pas)</h2>
<p>DR (Ahrefs) et DA (Moz) sont des scores comparatifs. Ils aident à trier une longue liste. Ils ne prouvent pas qu’un site fera bouger vos rankings demain.</p>
<p>Utilisez-les ainsi :</p>
<ul>
<li><strong>Plancher, pas trophée.</strong> Fixez un minimum adapté au budget, puis arrêtez de regarder le plafond.</li>
<li><strong>Comparez dans une niche.</strong> Un blog voyage DR 40 et un PBN casino DR 40 ne sont pas le même achat.</li>
<li><strong>Repérez les écarts.</strong> DR très haut + trafic quasi nul (ou l’inverse) mérite un second regard sur l’échantillon et les liens sortants.</li>
</ul>
<p>Sur SEOLinkBuildings, des barres sous les chiffres donnent une échelle. Ça aide à scanner une page de résultats sans traiter chaque digit comme une vérité révélée.</p>

<figure>
<img src="{$imgTrust}" alt="Fiches éditeurs avec trafic, DR et DA dans le catalogue" loading="lazy" width="1200" height="675">
<figcaption>Métriques en contexte — comparez vite, puis ouvrez le détail et l’échantillon.</figcaption>
</figure>

<h2>Trafic : préférez le signal au vanité</h2>
<p>Les estimations de trafic mensuel restent des estimations. Elles servent surtout à répondre : « Est-ce que quelqu’un lit vraiment ce site ? »</p>
<ol>
<li>Préférez un trafic crédible et stable pour la niche — pas un pic qui semble emprunté.</li>
<li>Alignez la géographie du trafic avec FR ou BE quand l’annonce montre un pays principal.</li>
<li>Un trafic bas sur un site très thématique et indexé peut quand même valoir un essai. Un domaine référent pertinent bat souvent trois liens « autorité » aléatoires.</li>
</ol>

<h2>Langue et pays pour la France et la Belgique</h2>
<p>Le marché francophone n’est pas un bloc unique. Un site .fr avec audience française n’est pas interchangeable avec un média belge, même si les deux parlent français. Filtrez langue <em>et</em> pays. Regardez si le contenu semble écrit pour des lecteurs locaux (exemples, devises, références réglementaires) ou pour un public « global » vague.</p>
<p>Évitez aussi le piège anglais : une fiche forte en anglais US pour une campagne FR coûte cher et rapporte peu. Gardez l’anglais pour les pages vraiment internationales.</p>

<h2>La pertinence de niche bat presque tout le reste</h2>
<p>Ouvrez l’article témoin. Lisez les catégories. Demandez-vous si votre ancre et votre URL auraient l’air naturelles sur cette page.</p>
<p>Passez votre chemin quand :</p>
<ul>
<li>l’échantillon est filé ou bourré de liens sortants</li>
<li>la niche dit « marketing » mais chaque post pousse des offres gambling</li>
<li>le délai ou le type de lien ne correspond pas à ce dont vous avez besoin</li>
</ul>
<p>Les sujets sensibles (crypto, CBD, forex, etc.) ont souvent un supplément. C’est normal. Ce qui ne l’est pas : cacher le sujet jusqu’après le paiement. Choisissez l’option en connaissance de cause.</p>

<h2>Processus de sélection court qui marche</h2>
<ol>
<li>Filtrez pays, langue et catégorie.</li>
<li>Fixez des planchers DR/DA et trafic compatibles avec le budget.</li>
<li>Ouvrez cinq à dix fiches. Vérifiez échantillon, type de lien, délai, prix.</li>
<li>N’ajoutez que ceux que vous voudrez encore le mois prochain.</li>
<li>Uploadez le contenu avant le paiement — voir le <a href="{$buyGuide}">guide d’achat</a> — puis payez depuis le portefeuille.</li>
</ol>
<p>Une version anglaise du même raisonnement existe ici : <a href="{$enChoose}">how to choose a publisher site</a>. Le principe ne change pas ; le filtre langue/pays, si.</p>

<h2>Erreurs fréquentes</h2>
<ul>
<li>Trier uniquement par DR max et acheter les trois premières lignes</li>
<li>Ignorer langue et pays parce que le prix était beau</li>
<li>Remplir un panier de 20 sites sans articles approuvés</li>
<li>Traiter « vérifié » comme un substitut à la lecture de l’échantillon</li>
</ul>
<p>Parcourez le <a href="{$marketplace}">catalogue français</a> pour le modèle en langage clair. Quand vous êtes prêt à sélectionner pour de vrai, <a href="{$register}">créez un compte annonceur</a> avec un brief écrit sous la main.</p>

<h2>Foire aux questions</h2>
<h3>Un DR élevé bat-il toujours un blog de niche moyen ?</h3>
<p>Non. Un site DR 70 hors sujet peut sous-performer un DR 28 déjà classé sur votre thème. La pertinence et un trafic réel battent souvent un score de prestige.</p>
<h3>Le trafic a l’air correct, mais l’article témoin est creux. Je fais quoi ?</h3>
<p>Traitez-le comme un signal d’alerte. Ouvrez l’échantillon, parcourez les derniers posts, et évitez les fiches qui ressemblent à des fermes de liens — même si les métriques sont propres.</p>
<h3>Faut-il filtrer uniquement les sites vérifiés ?</h3>
<p>La vérification aide, mais ce n’est pas toute l’histoire. Combinez-la avec le match de niche, le délai, le type de lien et un vrai regard sur l’article témoin.</p>
<h3>Combien de sites sélectionner avant d’acheter ?</h3>
<p>Pour une première campagne, cinq à dix options solides suffisent. Remplir un énorme panier sans contenu prêt, c’est payer du retard plus tard.</p>
HTML;
    }
}
