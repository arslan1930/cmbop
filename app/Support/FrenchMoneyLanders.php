<?php

namespace App\Support;

/**
 * France-only money / B2B marketing landers (French as used by SEO teams).
 * Marketplace stays /fr/marche and pricing stays /fr/tarifs (existing public slugs).
 * Shared slugs (digital-pr, niche-edits) are indexable on /fr;
 * unprefixed canonicals stay with Italy or Germany.
 */
class FrenchMoneyLanders
{
    public const LOCALE = 'fr';

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_keys(self::pages());
    }

    /**
     * Research URL aliases → French canonicals (no extra indexable twins).
     *
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return [
            'marketplace' => '/fr/marche',
            'medias-francais' => '/fr/marche',
            'guest-post-france' => '/fr/acheter-guest-post',
            'guest-posting-sites-france' => '/fr/acheter-guest-post',
            'publier-sur-blogs' => '/fr/acheter-guest-post',
            'acheter-article-sponsorise' => '/fr/article-sponsorise',
            'acheter-publiredactionnel' => '/fr/article-sponsorise',
            'publier-article-sponsorise' => '/fr/article-sponsorise',
            'acheter-liens-seo' => '/fr/acheter-backlinks',
            'prix' => '/fr/tarifs',
            'prix-guest-post' => '/fr/tarifs',
            'cout-netlinking' => '/fr/tarifs',
            'white-label-netlinking' => '/fr/agences',
            'communique-de-presse' => '/fr/digital-pr',
            'acheter-communique-de-presse' => '/fr/digital-pr',
            'guide-netlinking' => '/fr/guide',
        ];
    }

    public static function isSlug(string $segment): bool
    {
        $segment = trim($segment, '/');

        return $segment !== '' && in_array($segment, self::slugs(), true);
    }

    public static function isPublicSegment(string $segment): bool
    {
        $segment = trim($segment, '/');
        if ($segment === '') {
            return false;
        }

        return self::isSlug($segment) || array_key_exists($segment, self::aliases());
    }

    /**
     * @return list<string>
     */
    public static function copyRedirectLocales(): array
    {
        return ['fr'];
    }

    public static function capturesLocaleCopy(string $locale, string $segment): bool
    {
        $locale = strtolower(trim($locale));

        return in_array($locale, self::copyRedirectLocales(), true)
            && self::isPublicSegment($segment);
    }

    /**
     * @return list<string>
     */
    public static function publicSegments(): array
    {
        return array_values(array_unique(array_merge(
            self::slugs(),
            array_keys(self::aliases())
        )));
    }

    public static function url(string $slug): string
    {
        return url('/fr/'.$slug);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $slug): ?array
    {
        $slug = trim($slug, '/');

        return self::pages()[$slug] ?? null;
    }

    /**
     * @return list<array{slug: string, label: string, url: string}>
     */
    public static function clusterLinks(?string $current = null): array
    {
        $items = [
            ['slug' => 'home', 'label' => 'Marketplace France', 'url' => url('/fr')],
            ['slug' => 'acheter-guest-post', 'label' => 'Acheter un guest post', 'url' => self::url('acheter-guest-post')],
            ['slug' => 'article-sponsorise', 'label' => 'Article sponsorisé', 'url' => self::url('article-sponsorise')],
            ['slug' => 'marche', 'label' => 'Catalogue de médias', 'url' => url('/fr/marche')],
            ['slug' => 'netlinking', 'label' => 'Netlinking', 'url' => self::url('netlinking')],
            ['slug' => 'acheter-backlinks', 'label' => 'Acheter des backlinks', 'url' => self::url('acheter-backlinks')],
            ['slug' => 'tarifs', 'label' => 'Prix guest post', 'url' => url('/fr/tarifs')],
            ['slug' => 'agences', 'label' => 'Pour les agences', 'url' => self::url('agences')],
            ['slug' => 'digital-pr', 'label' => 'Digital PR', 'url' => self::url('digital-pr')],
            ['slug' => 'niche-edits', 'label' => 'Niche edits', 'url' => self::url('niche-edits')],
            ['slug' => 'guide', 'label' => 'Guide', 'url' => self::url('guide')],
        ];

        if ($current === null || $current === '') {
            return $items;
        }

        return array_values(array_filter(
            $items,
            static fn (array $item) => $item['slug'] !== $current
        ));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function pages(): array
    {
        $marche = '/fr/marche';
        $tarifs = '/fr/tarifs';
        $how = '/fr/comment-ca-marche';
        $register = '/register';
        $guest = '/fr/acheter-guest-post';
        $sponsored = '/fr/article-sponsorise';
        $links = '/fr/acheter-backlinks';
        $netlinking = '/fr/netlinking';
        $agencies = '/fr/agences';
        $pr = '/fr/digital-pr';
        $niche = '/fr/niche-edits';
        $guide = '/fr/guide';
        $publisher = '/fr/devenir-editeur';

        return [
            'acheter-guest-post' => [
                'kicker' => 'Guest post avec backlink',
                'h1' => 'Acheter un guest post en France',
                'subtitle' => 'Choisissez des éditeurs français et européens, comparez la thématique, le DA/DR et le prix en euros, envoyez le brief et suivez l’URL live dans la commande.',
                'meta_title' => 'Acheter des guest posts en France | SEOLinkBuildings',
                'meta_description' => 'Guest post sur des blogs et sites en France : filtrez la thématique, le DA/DR et le prix en EUR, choisissez dofollow ou sponsored et recevez l’URL live.',
                'teaser_countries' => ['fr'],
                'teaser_title' => 'Sites pour guest posts en France',
                'teaser_subtitle' => 'Aperçu masqué des listings actifs en France. Les domaines s’ouvrent après inscription.',
                'intro' => [
                    'SEOLinkBuildings est une marketplace en libre-service, pas un forfait opaque de guest posts. Vous choisissez le site — souvent en .fr, souvent en français —, vous payez en euros depuis le portefeuille et vous gardez le brief, le chat et l’URL live dans une seule commande. Le siège est à Londres (Topurlz Ltd) ; nous n’inventons pas un SIRET français.',
                    '« Guest post France », « publier sur des blogs en France » et « guest posting sites France » décrivent la même intention : une publication payante sur un site qui n’est pas le vôtre, avec des règles écrites de longueur, de liens et de délais.',
                ],
                'points' => [
                    [
                        'title' => 'Des éditeurs, pas une liste fantôme',
                        'body' => 'Chaque ligne est un site avec thématique, langue, pays, DA/DR, trafic déclaré et prix de commande. Nous ne vendons pas de PBN ni de « packs de 50 liens ».',
                    ],
                    [
                        'title' => 'Comment commander',
                        'body' => 'Vous créez un compte, vous filtrez la France, vous ajoutez le site au panier et vous envoyez le titre, le texte ou le brief plus l’ancre. L’éditeur livre l’URL live pour validation.',
                    ],
                    [
                        'title' => 'Dofollow et sponsored',
                        'body' => 'L’attribut du lien est sur le listing. Beaucoup de médias marquent les publications payantes. Lisez le type de lien avant de commander : il n’existe pas de « dofollow à tout prix ».',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Trafic, thématique et permanence',
                        'body' => 'Un guest post « avec trafic » signifie que le listing affiche le trafic déclaré par l’éditeur, pas une garantie de visites. Les thématiques (santé, finance, tech, assurance, immobilier, voyage, fintech, marketing, e-commerce) se filtrent dans le catalogue, pas sur des URL séparées. « Permanent » dépend des règles du site : certaines publications restent, d’autres ont une durée. Lisez le listing.',
                    ],
                    [
                        'h2' => 'DA et DR, sans formules forcées',
                        'body' => 'Vous pouvez trier par DA et DR lorsque les métriques figurent sur la ligne. Un guest post avec un DA élevé ou un DR élevé n’est pas un produit à part et ne promet pas de positions. Les métriques absentes restent vides — nous ne les inventons pas.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Puis-je publier uniquement sur des sites .fr ?',
                        'a' => 'Oui. Vous filtrez le pays France. Le catalogue européen reste disponible dans le même portefeuille en euros. La Belgique et la Suisse francophone sont des filtres distincts.',
                    ],
                    [
                        'q' => 'Le guest post est-il en français ?',
                        'a' => 'La langue est sur le listing. La plupart des sites dont le pays principal est la France publient en français. Vérifiez avant d’écrire le brief.',
                    ],
                    [
                        'q' => 'Garantissez-vous un classement ?',
                        'a' => 'Non. Un backlink est un signal parmi d’autres. Nous ne promettons ni positions, ni trafic, ni un dofollow sur chaque média.',
                    ],
                ],
                'see_also' => [
                    ['label' => 'Catalogue de médias', 'url' => $marche],
                    ['label' => 'Article sponsorisé', 'url' => $sponsored],
                    ['label' => 'Acheter des backlinks', 'url' => $links],
                    ['label' => 'Prix', 'url' => $tarifs],
                    ['label' => 'Guide du netlinking', 'url' => $guide],
                ],
                'cta_primary' => ['label' => 'Voir le catalogue', 'url' => $marche],
                'cta_secondary' => ['label' => 'Créer un compte', 'url' => $register],
            ],
            'article-sponsorise' => [
                'kicker' => 'Contenu sponsorisé',
                'h1' => 'Article sponsorisé et publirédactionnel',
                'subtitle' => 'Commandez une publication éditoriale payante sur un média du catalogue, avec le brief, le lien et l’URL live dans la même commande.',
                'meta_title' => 'Article sponsorisé et publirédactionnel | SEOLinkBuildings',
                'meta_description' => 'Acheter un article sponsorisé ou un publirédactionnel : brief, lien et URL live sur des médias du catalogue, en euros, sans tarif inventé.',
                'teaser_countries' => ['fr'],
                'teaser_title' => 'Médias pour un article sponsorisé',
                'teaser_subtitle' => 'Aperçu des listings France. Le format exact (article, publirédactionnel, guest post) est sur la fiche.',
                'intro' => [
                    'Un article sponsorisé est un contenu payant publié sur un média qui n’est pas le vôtre. Un publirédactionnel est le même geste dans le vocabulaire des médias français : un texte commandé, souvent signalé comme contenu sponsorisé. Un guest post est le terme SEO courant pour cette publication lorsqu’elle porte un backlink.',
                    'Un communiqué de presse n’est pas automatiquement un article sponsorisé. S’il vous faut une diffusion de marque plutôt qu’un lien sur un listing, voyez la page digital PR. Ici, vous achetez la publication que l’éditeur accepte sur sa fiche.',
                ],
                'points' => [
                    [
                        'title' => 'Ce que vous commandez',
                        'body' => 'Le site, le prix en euros, les règles de contenu et le type de lien. Pas un barème national d’« article sponsorisé ».',
                    ],
                    [
                        'title' => 'Publier le texte',
                        'body' => 'Vous fournissez le texte ou un brief. L’éditeur publie selon ses consignes et renvoie l’URL live dans la commande.',
                    ],
                    [
                        'title' => 'Contenu sponsorisé médias',
                        'body' => 'Le catalogue mélange blogs et médias. Lisez la fiche : certains acceptent un guest post, d’autres un publirédactionnel plus contraint.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Tarif d’un article sponsorisé',
                        'body' => 'Le tarif est le prix du listing, en euros, au moment de la commande. Il change avec l’autorité, la thématique et les exigences de rédaction. La page tarifs explique le modèle ; elle ne publie pas une grille figée.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Article sponsorisé, publirédactionnel ou guest post ?',
                        'a' => 'Les trois noms décrivent une publication payante. Le guest post est le terme le plus utilisé en netlinking. Le publirédactionnel et l’article sponsorisé sont les termes des médias. La fiche du site dit ce qui est accepté.',
                    ],
                    [
                        'q' => 'Puis-je acheter un communiqué de presse ici ?',
                        'a' => 'Seulement si un éditeur du catalogue le propose comme publication. Une campagne de digital PR se décrit sur la page dédiée, sans confondre chaque mention avec un backlink SEO.',
                    ],
                ],
                'see_also' => [
                    ['label' => 'Acheter un guest post', 'url' => $guest],
                    ['label' => 'Digital PR', 'url' => $pr],
                    ['label' => 'Prix', 'url' => $tarifs],
                    ['label' => 'Catalogue', 'url' => $marche],
                ],
                'cta_primary' => ['label' => 'Voir les médias', 'url' => $marche],
                'cta_secondary' => ['label' => 'Comment ça marche', 'url' => $how],
            ],
            'acheter-backlinks' => [
                'kicker' => 'Liens éditoriaux',
                'h1' => 'Acheter des backlinks en France',
                'subtitle' => 'Des liens placés dans un contenu publié chez un éditeur du catalogue, filtrables par pays, thématique et métriques affichées.',
                'meta_title' => 'Acheter des backlinks en France | SEOLinkBuildings',
                'meta_description' => 'Acheter des liens SEO sur des sites français : pertinence, trafic déclaré, dofollow ou sponsored, prix en euros sur le listing. Pas de promesse de positions.',
                'teaser_countries' => ['fr'],
                'teaser_title' => 'Sites .fr pour des backlinks',
                'teaser_subtitle' => 'Aperçu des listings dont le pays principal est la France.',
                'intro' => [
                    'Acheter des backlinks, acheter des liens SEO ou chercher des backlinks de qualité recouvre ici la même action : choisir un site, faire publier un contenu et obtenir l’URL où le lien est en ligne. « Acheter des backlinks France » restreint le filtre au pays France.',
                    'La qualité se juge sur la thématique, la langue, le trafic déclaré et les règles du média — pas sur une promesse de classement. Un backlink thématique est un lien sur un site dont la niche correspond à la vôtre. Un backlink avec trafic réel signifie que le listing montre un trafic déclaré, pas une visite garantie.',
                ],
                'points' => [
                    [
                        'title' => 'Dofollow et liens sponsored',
                        'body' => 'Backlinks dofollow et liens dofollow existent lorsque le listing l’indique. Beaucoup de publications payantes sont en rel sponsored. C’est écrit avant le paiement.',
                    ],
                    [
                        'title' => 'Sites .fr',
                        'body' => 'Filtrez la France pour viser des éditeurs dont le pays principal est la France. Cela n’exclut pas le reste du catalogue européen dans le même portefeuille.',
                    ],
                    [
                        'title' => 'Pas de pack anonyme',
                        'body' => 'Vous voyez le site avant de payer. Nous ne vendons pas un sac de domaines masqués ni un forfait « pas cher » à prix fixe.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Pertinence plutôt que volume',
                        'body' => 'Un lien hors sujet sur un site fort reste un mauvais lien. Choisissez la thématique dans le catalogue. Les niches (e-commerce, finance, tech, santé) sont des filtres, pas des pages doorway.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Les backlinks sont-ils permanents ?',
                        'a' => 'La durée est sur le listing. Certaines publications restent en ligne, d’autres ont une date de fin. Nous ne garantissons pas la permanence au-delà de ce que l’éditeur écrit.',
                    ],
                    [
                        'q' => 'Puis-je acheter des backlinks pas cher ?',
                        'a' => 'Le prix bas est celui d’un listing moins cher, visible au checkout. Ce n’est pas un produit séparé et ce n’est pas une promesse de résultat.',
                    ],
                ],
                'see_also' => [
                    ['label' => 'Netlinking', 'url' => $netlinking],
                    ['label' => 'Guest post', 'url' => $guest],
                    ['label' => 'Niche edits', 'url' => $niche],
                    ['label' => 'Guide', 'url' => $guide],
                ],
                'cta_primary' => ['label' => 'Filtrer le catalogue', 'url' => $marche],
                'cta_secondary' => ['label' => 'Créer un compte', 'url' => $register],
            ],
            'netlinking' => [
                'kicker' => 'Service et marketplace',
                'h1' => 'Netlinking en France',
                'subtitle' => 'La marketplace pour choisir vos liens, ou un cadre clair pour les agences qui passent commande pour leurs clients.',
                'meta_title' => 'Plateforme de netlinking | SEOLinkBuildings',
                'meta_description' => 'Netlinking France : marketplace de guest posts et de backlinks en euros, forfaits selon les listings, white label pour les agences sans logo sur le site public.',
                'teaser_countries' => ['fr'],
                'teaser_title' => 'Éditeurs pour une campagne de netlinking',
                'teaser_subtitle' => 'Les sites français du catalogue. Le netlinking Europe reste dans le même compte.',
                'intro' => [
                    'Le netlinking, c’est l’obtention de liens depuis d’autres sites. Sur SEOLinkBuildings, le service netlinking est la marketplace : vous composez la campagne site par site. Un forfait netlinking ou un pack netlinking n’est pas un PDF figé — c’est la somme des listings que vous validez.',
                    '« Netlinking pas cher » décrit un budget, pas une qualité inférieure garantie. « Netlinking Europe » décrit le catalogue au-delà de la France, toujours en euros. Nous ne créons pas une page par pays ni par ville.',
                ],
                'points' => [
                    [
                        'title' => 'Stratégie, pas une liste d’ancres',
                        'body' => 'Vous choisissez les sites, les ancres et le rythme. Le guide rappelle les risques, le rel sponsored et la différence entre PBN et guest post.',
                    ],
                    [
                        'title' => 'White label pour les agences',
                        'body' => 'Vous commandez pour un client et vous gardez la relation. Le site public reste SEOLinkBuildings : ce n’est pas un programme où votre logo remplace le nôtre.',
                    ],
                    [
                        'title' => 'Netlinking France',
                        'body' => 'Filtrez les éditeurs français, puis élargissez si la campagne a besoin d’autres marchés européens.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Marketplace, pas une agence opaque',
                        'body' => 'Vous voyez le prix, le pays et les métriques présentes avant de payer. Il n’y a pas de livraison « 50 liens sous 7 jours » ni de taux d’approbation inventé.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Proposez-vous un forfait netlinking ?',
                        'a' => 'Le montant est celui des sites choisis, plus les forfaits de digital PR publiés sur la page tarifs lorsqu’ils s’appliquent. Il n’y a pas de grille unique « pack netlinking ».',
                    ],
                    [
                        'q' => 'Le netlinking white label change-t-il la facturation ?',
                        'a' => 'La facturation de l’annonceur reste celle de Topurlz Ltd, à Londres. Vous refacturez votre client de votre côté. Nous n’ajoutons pas votre marque sur le site public.',
                    ],
                ],
                'see_also' => [
                    ['label' => 'Pour les agences', 'url' => $agencies],
                    ['label' => 'Prix', 'url' => $tarifs],
                    ['label' => 'Acheter des backlinks', 'url' => $links],
                    ['label' => 'Guide', 'url' => $guide],
                ],
                'cta_primary' => ['label' => 'Ouvrir le catalogue', 'url' => $marche],
                'cta_secondary' => ['label' => 'Page agences', 'url' => $agencies],
            ],
            'agences' => [
                'kicker' => 'Agences SEO',
                'h1' => 'Netlinking pour les agences',
                'subtitle' => 'Passez commande pour vos clients sur le même catalogue, avec le brief et l’URL live dans chaque commande.',
                'meta_title' => 'Netlinking pour agences SEO | SEOLinkBuildings',
                'meta_description' => 'Guest posts pour agences et service de netlinking : commande en euros, white label sans logo sur le site public, pas de programme revendeur inventé.',
                'teaser_countries' => ['fr'],
                'teaser_title' => 'Sites que vos clients peuvent comparer',
                'teaser_subtitle' => 'Le même aperçu France que sur les pages annonceur.',
                'intro' => [
                    'Une agence SEO off-page utilise la marketplace comme un service de netlinking : elle choisit les éditeurs, envoie les briefs et suit les URL. « Guest post pour agences » n’est pas un catalogue séparé.',
                    'L’outreach reste le vôtre si vous préférez présenter les sites vous-même. Nous ne prétendons pas remplacer votre relation client, et nous ne disons pas que cette plateforme est meilleure que d’autres.',
                ],
                'points' => [
                    [
                        'title' => 'White label, limites claires',
                        'body' => 'Vous pouvez travailler le netlinking white label au sens opérationnel : vos clients ne sont pas obligés de voir chaque échange. Le site public et la facturation restent ceux de SEOLinkBuildings / Topurlz Ltd.',
                    ],
                    [
                        'title' => 'Plusieurs commandes',
                        'body' => 'Chaque placement est une commande avec son brief. Vous ne mélangez pas les ancres de deux clients sur une ligne opaque.',
                    ],
                    [
                        'title' => 'Pas de revendeur fantôme',
                        'body' => 'Il n’y a pas de grille partenaire secrète ni de remise inventée. Le prix affiché est le prix du listing.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Ce que l’agence voit',
                        'body' => 'Pays, langue, métriques présentes, prix en euros, règles de lien. Après connexion, le catalogue complet et le suivi de commande. Le tableau de bord reste en anglais.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Puis-je facturer mes clients en mon nom ?',
                        'a' => 'Oui, de votre côté. Notre facture annonceur est émise par la société britannique. Nous n’émettons pas une facture à votre marque.',
                    ],
                    [
                        'q' => 'Y a-t-il un outreach netlinking inclus ?',
                        'a' => 'Non. Vous choisissez des éditeurs déjà listés. Nous ne prospectons pas des médias hors catalogue en votre nom.',
                    ],
                ],
                'see_also' => [
                    ['label' => 'Netlinking', 'url' => $netlinking],
                    ['label' => 'Prix', 'url' => $tarifs],
                    ['label' => 'Guest post', 'url' => $guest],
                    ['label' => 'Devenir éditeur', 'url' => $publisher],
                ],
                'cta_primary' => ['label' => 'Créer un compte agence', 'url' => $register],
                'cta_secondary' => ['label' => 'Voir les tarifs', 'url' => $tarifs],
            ],
            'digital-pr' => [
                'kicker' => 'Mentions et contenus',
                'h1' => 'Digital PR en France',
                'subtitle' => 'Des publications et des mentions de marque sur des médias du catalogue, sans confondre chaque retombée avec un backlink SEO.',
                'meta_title' => 'Digital PR en France | SEOLinkBuildings',
                'meta_description' => 'Digital PR France : communiqué, mention de marque ou contenu sponsorisé lorsque le média le propose. Un placement PR n’est pas toujours un backlink.',
                'teaser_countries' => ['fr'],
                'teaser_title' => 'Médias du catalogue France',
                'teaser_subtitle' => 'Les fiches disent si le média accepte un article, une mention ou un lien.',
                'intro' => [
                    'Le digital PR cherche une présence sur des médias : article, mention de marque, parfois un lien. Acheter un communiqué de presse ou un contenu sponsorisé n’équivaut pas à acheter un backlink dofollow. Lisez la fiche.',
                    '« Acheter des mentions de marque » correspond aux placements où le média cite la marque. Si le listing ne promet pas de lien, n’en attendez pas un. Les forfaits chiffrés, lorsqu’ils existent, sont sur la page tarifs — nous n’en inventons pas ici.',
                ],
                'points' => [
                    [
                        'title' => 'Communiqué et article',
                        'body' => 'Un communiqué de presse SEO n’est utile que si le média le publie vraiment. Sinon, restez sur un guest post ou un article sponsorisé dont l’URL est suivie dans la commande.',
                    ],
                    [
                        'title' => 'Pas de publicité native inventée',
                        'body' => 'Nous ne vendons pas un réseau display. Uniquement les formats que l’éditeur a mis sur son listing.',
                    ],
                    [
                        'title' => 'Même portefeuille EUR',
                        'body' => 'Le paiement reste le portefeuille en euros. Pas de devise séparée pour la France.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Quand choisir le digital PR',
                        'body' => 'Choisissez cette page si l’objectif est la mention ou la publication de marque. Choisissez le guest post ou les backlinks si l’objectif est un lien dont l’attribut est écrit sur la fiche.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Chaque placement digital PR est-il un backlink ?',
                        'a' => 'Non. Certaines publications citent la marque sans lien, ou avec un lien sponsored ou nofollow. La fiche fait foi.',
                    ],
                    [
                        'q' => 'Couvrez-vous les agences de presse ?',
                        'a' => 'Seulement si un éditeur du catalogue propose ce format. Nous ne prétendons pas diffuser un communiqué sur un fil national.',
                    ],
                ],
                'see_also' => [
                    ['label' => 'Article sponsorisé', 'url' => $sponsored],
                    ['label' => 'Guest post', 'url' => $guest],
                    ['label' => 'Prix', 'url' => $tarifs],
                ],
                'cta_primary' => ['label' => 'Voir les médias', 'url' => $marche],
                'cta_secondary' => ['label' => 'Tarifs', 'url' => $tarifs],
            ],
            'niche-edits' => [
                'kicker' => 'Insertion de lien',
                'h1' => 'Niche edits et insertion de lien',
                'subtitle' => 'Un lien ajouté dans une page déjà en ligne, lorsque l’éditeur l’accepte — pas un produit de volume.',
                'meta_title' => 'Niche edits et insertion de lien | SEOLinkBuildings',
                'meta_description' => 'Niche edits France : insertion de lien sur une page existante si l’éditeur le propose. Ce n’est pas un SKU de volume ni une promesse de positions.',
                'teaser_countries' => ['fr'],
                'teaser_title' => 'Éditeurs du catalogue',
                'teaser_subtitle' => 'Vérifiez sur la fiche si l’insertion dans un article existant est acceptée.',
                'intro' => [
                    'Une niche edit, ou insertion de lien, place un lien dans un contenu déjà publié. Ce n’est pas un nouvel article. En France comme ailleurs, nous ne le vendons pas comme un SKU de volume : seulement si l’éditeur l’indique.',
                    'Si la fiche ne propose que des guest posts, commandez un guest post. N’attendez pas qu’un article ancien soit modifié.',
                ],
                'points' => [
                    [
                        'title' => 'Page existante',
                        'body' => 'Le lien va sur une URL déjà indexée par l’éditeur, selon ses règles. Vous ne choisissez pas une phrase au hasard sur le site.',
                    ],
                    [
                        'title' => 'Même suivi de commande',
                        'body' => 'L’URL mise à jour revient dans la commande, comme pour un guest post.',
                    ],
                    [
                        'title' => 'Pas un raccourci',
                        'body' => 'Une insertion hors sujet reste un mauvais lien. La thématique compte autant que pour un article nouveau.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Niche edits France',
                        'body' => 'Filtrez la France si vous voulez des éditeurs français. Il n’y a pas d’URL séparée par ville ni par secteur. Nous ne le vendons pas comme un pack.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Vendez-vous des niche edits en volume ?',
                        'a' => 'Non. Nous ne le vendons pas comme un SKU de volume. Chaque insertion dépend d’un éditeur qui l’accepte sur sa fiche.',
                    ],
                    [
                        'q' => 'Quelle est la différence avec un guest post ?',
                        'a' => 'Le guest post est un article nouveau. L’insertion de lien modifie une page existante. Les deux ne sont pas interchangeables.',
                    ],
                ],
                'see_also' => [
                    ['label' => 'Acheter un guest post', 'url' => $guest],
                    ['label' => 'Acheter des backlinks', 'url' => $links],
                    ['label' => 'Guide', 'url' => $guide],
                ],
                'cta_primary' => ['label' => 'Voir le catalogue', 'url' => $marche],
                'cta_secondary' => ['label' => 'Guest post', 'url' => $guest],
            ],
            'guide' => [
                'kicker' => 'Comprendre avant d’acheter',
                'h1' => 'Guide du netlinking',
                'subtitle' => 'Guest post, backlinks, ancres, dofollow et risques — une seule page, puis les pages commerciales si vous voulez commander.',
                'meta_title' => 'Guide du netlinking | SEOLinkBuildings',
                'meta_description' => 'Qu’est-ce que le netlinking, qu’est-ce qu’un guest post, comment acheter des backlinks, dofollow et nofollow, ancres, PBN et rel sponsored.',
                'teaser_countries' => ['fr'],
                'teaser_title' => 'Exemples de sites du catalogue',
                'teaser_subtitle' => 'Pour voir comment une fiche présente le prix et les métriques. Ce n’est pas un classement.',
                'intro' => [
                    'Le netlinking consiste à obtenir des liens depuis d’autres sites vers le vôtre. Un guest post est un article publié sur le site de quelqu’un d’autre, en général avec un lien. Acheter des backlinks sur cette plateforme, c’est choisir cet éditeur dans le catalogue plutôt que d’envoyer des e-mails un par un.',
                    'Cette page regroupe les questions qui n’ont pas besoin d’une URL chacune : comment faire du netlinking, stratégie, risques, texte d’ancre, dofollow face au nofollow, PBN face au guest post, rel sponsored. Nous ne publions pas huit articles minces pour les mêmes intentions.',
                ],
                'points' => [
                    [
                        'title' => 'Dofollow et nofollow',
                        'body' => 'Dofollow (ou l’absence de nofollow) transmet le signal classique. Nofollow et sponsored demandent aux moteurs de traiter le lien comme un lien commercial. Google attend rel sponsored sur beaucoup de liens payants. Le listing indique l’attribut réel.',
                    ],
                    [
                        'title' => 'Texte d’ancre',
                        'body' => 'Variez les ancres. Une ancre exacte répétée sur tous les liens est un risque, pas une tactique. Le brief que vous envoyez doit respecter aussi les règles de l’éditeur.',
                    ],
                    [
                        'title' => 'PBN et guest post',
                        'body' => 'Un PBN est un réseau de sites contrôlés pour placer des liens. Nous n’en vendons pas. Un guest post est une publication chez un éditeur indépendant, avec ses propres règles.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Comment acheter des backlinks ici',
                        'body' => 'Créez un compte, filtrez le pays et la thématique, lisez le prix en euros et le type de lien, envoyez le brief, validez l’URL live. Les risques restent ceux de tout lien payant : site hors sujet, attribut sponsored, page retirée plus tard. Rien ici ne garantit une position.',
                    ],
                    [
                        'h2' => 'Stratégie de netlinking',
                        'body' => 'Commencez par des sites dont la thématique et la langue correspondent à la page cible. Mélangez guest posts et, seulement si la fiche le permet, insertions de lien. Évitez les promesses de « guide netlinking 2026 » chiffrées : les volumes et les difficultés de mots-clés de recherche ne sont pas des volumes Semrush.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Qu’est-ce qu’un guest post ?',
                        'a' => 'Un article que vous faites publier sur un autre site, le plus souvent avec un lien vers une page à vous. Les règles de longueur, d’ancre et de dofollow sont celles de l’éditeur.',
                    ],
                    [
                        'q' => 'Quels sont les risques d’acheter des backlinks ?',
                        'a' => 'Lien hors thématique, réseau de mauvaise qualité, attribut masqué, ou page supprimée. Choisissez un listing vérifiable et lisez le type de lien. Nous ne promettons pas que Google ignorera un lien payant.',
                    ],
                    [
                        'q' => 'Rel sponsored est-il obligatoire ?',
                        'a' => 'Pour un lien payant, Google demande en général rel sponsored. Si l’éditeur l’applique, c’est indiqué. Ne commandez pas en supposant un dofollow silencieux.',
                    ],
                ],
                'see_also' => [
                    ['label' => 'Acheter des backlinks', 'url' => $links],
                    ['label' => 'Acheter un guest post', 'url' => $guest],
                    ['label' => 'Netlinking', 'url' => $netlinking],
                    ['label' => 'Niche edits', 'url' => $niche],
                ],
                'cta_primary' => ['label' => 'Voir le catalogue', 'url' => $marche],
                'cta_secondary' => ['label' => 'Comment ça marche', 'url' => $how],
            ],
        ];
    }
}
