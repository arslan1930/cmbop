<?php

namespace App\Support;

/**
 * French advertiser guide: buying guest posts on SEOLinkBuildings.
 */
class AcheterGuestPostsFrBlogPost
{
    public const SLUG = 'acheter-des-guest-posts-sur-seolinkbuildings-guide-annonceur';

    public const FEATURED_ASSET = 'assets/img/blog/market-fr-buy-featured.jpg';

    public const FEATURED_STORAGE = 'blogs/featured/market-fr-buy-featured.jpg';

    public const IMAGE_CATALOG = 'market-fr-buy-catalog.jpg';

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
            'title' => 'Acheter des guest posts sur SEOLinkBuildings : guide annonceur',
            'slug' => self::SLUG,
            'primary_locale' => 'fr',
            'excerpt' => 'Parcours concret pour annonceurs : créer un compte, créditer le portefeuille EUR, filtrer le catalogue, préparer les articles, payer et suivre les commandes jusqu’à l’URL live.',
            'content' => self::contentHtml(),
            'author' => 'Arslan Jason',
            'tags' => [
                'Guide annonceur',
                'Guest posts',
                'Marketplace',
                'Portefeuille',
                'Catalogue',
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
                'question' => 'Faut-il uploader le contenu avant d’acheter une place ?',
                'answer' => 'Vous pouvez parcourir le catalogue et remplir le panier sans article. À la commande, chaque site attend un article approuvé. Mieux vaut uploader tôt dans la bibliothèque de contenus pour ne pas bloquer le paiement.',
            ],
            [
                'question' => 'Comment fonctionne le portefeuille en euros ?',
                'answer' => 'Vous créditez un solde dépensable (carte, virement ou autre méthode proposée). Lorsqu’une offre de bienvenue est active, un crédit dépensable uniquement peut s’appliquer sous les règles de la plateforme. Au paiement, le total de la commande est prélevé sur ce portefeuille.',
            ],
            [
                'question' => 'Que faire si l’URL live ne correspond pas au brief ?',
                'answer' => 'Ouvrez le fil de la commande, notez l’URL et les écarts (ancre, attributs, page). Demandez la correction à l’éditeur. Gardez une courte trace : elle sert si vous devez escalader ou demander un remboursement selon les règles de séquestre.',
            ],
            [
                'question' => 'Puis-je cibler uniquement la France et la Belgique ?',
                'answer' => 'Oui. Filtrez par pays et langue dans le catalogue. Pour le marché francophone, privilégiez le français et les sites dont le trafic ou le pays principal correspondent à FR ou BE — pas seulement une étiquette « Europe ».',
            ],
        ];
    }

    public static function contentHtml(): string
    {
        $register = '/register';
        $marketplace = '/fr/marche';
        $howItWorks = '/fr/comment-ca-marche';
        $walletBlog = '/blog/wallet-escrow-and-refunds-explained';
        $liveCheck = '/blog/what-to-check-after-the-live-link-indexation-attributes-rankings';
        $imgCatalog = BlogInlineImages::publicUrl(self::IMAGE_CATALOG);
        $imgFunds = BlogInlineImages::publicUrl(self::IMAGE_FUNDS);
        $imgContent = BlogInlineImages::publicUrl(self::IMAGE_CONTENT);

        return <<<HTML
<p>Acheter un guest post ne devrait pas ressembler à un jeu de hasard. Sur SEOLinkBuildings, le parcours est volontairement opérationnel : compte vérifié, solde en euros, sites filtrés, articles prêts, commande suivie jusqu’à l’URL live.</p>
<p>Ce guide suit exactement cet ordre. Si vous voulez d’abord le modèle marketplace en une page, lisez <a href="{$howItWorks}">comment ça marche</a>. Ensuite, revenez ici pour passer à l’action.</p>

<h2>1. Créer un compte annonceur et vérifier l’e-mail</h2>
<p>Inscrivez-vous sur <a href="{$register}">/register</a> en choisissant le rôle annonceur. Confirmez l’e-mail avant d’attendre un accès complet : sans vérification, la session normale ne s’ouvre pas.</p>
<p>Après validation, vous arrivez dans l’espace annonceur. Lorsqu’une offre de bienvenue est active, tout crédit accordé reste du pouvoir d’achat pour des placements — pas un retrait en espèces.</p>
<p>La navigation reste stable : Catalogue, Bibliothèque de contenus, Commandes, Ajouter des fonds, Facturation. Gardez ce schéma en tête — presque tout le reste s’y rattache.</p>

<h2>2. Créditer le portefeuille EUR</h2>
<p>Ouvrez <strong>Ajouter des fonds</strong> dès que le panier risque de dépasser le solde. Choisissez la méthode affichée pour votre compte (carte via Stripe, virement, etc.). Les données carte passent par Stripe ; elles ne restent pas sur nos serveurs.</p>

<figure>
<img src="{$imgFunds}" alt="Page Ajouter des fonds pour créditer le portefeuille annonceur" loading="lazy" width="1200" height="675">
<figcaption>Ajouter des fonds — rechargez le solde dépensable avant ou pendant le paiement.</figcaption>
</figure>

<p>Le portefeuille est en euros. Même si vous ciblez la Belgique ou un éditeur facturé autrement en interne, vous voyez et payez en EUR sur la plateforme. Pour le détail du séquestre et des remboursements, lisez <a href="{$walletBlog}">portefeuille, séquestre et remboursements</a>.</p>
<p>Astuce simple : rechargez avec une marge. Payer site par site ralentit les campagnes hebdomadaires.</p>

<h2>3. Filtrer le catalogue (pas seulement trier par DR)</h2>
<p>Ouvrez le <strong>Catalogue</strong>. Filtrez pays, langue, niche, puis des planchers raisonnables de métriques. Ajoutez un site au panier seulement si le type de lien, le statut sponsorisé et le délai de livraison vous conviennent.</p>

<figure>
<img src="{$imgCatalog}" alt="Catalogue SEOLinkBuildings avec filtres et sites éditeurs" loading="lazy" width="1200" height="675">
<figcaption>Catalogue — filtrez d’abord, comparez ensuite DR, trafic et pertinence sur la liste courte.</figcaption>
</figure>

<p>Pour une campagne FR/BE, commencez par français + France ou Belgique. Un site « fort » en anglais avec audience US n’aidera presque jamais une landing régionale en français.</p>
<p>L’inventaire public est résumé sur le <a href="{$marketplace}">catalogue français</a> ; l’achat réel se fait dans le catalogue connecté. Vous pouvez laisser des sites dans le panier et continuer à chercher. Remplissez un panier de vingt sites sans contenu prêt, et vous payez surtout du retard.</p>

<h2>4. Préparer les articles dans la bibliothèque de contenus</h2>
<p>Chaque site d’une commande a besoin de son propre article approuvé. Uploadez tôt. Attendre que le panier soit plein est le classique qui fait rater les délais.</p>

<figure>
<img src="{$imgContent}" alt="Bibliothèque de contenus pour uploader les articles guest post" loading="lazy" width="1200" height="675">
<figcaption>Bibliothèque de contenus — uploadez, attendez l’approbation, assignez à la commande.</figcaption>
</figure>

<p>Nommez clairement les fichiers. Un article par placement prévu. Une fois approuvé, vous l’attachez au site au moment du paiement. Si l’approbation est encore en cours, continuez à parcourir le catalogue — mais ne comptez pas finaliser le paiement sans contenu prêt.</p>
<p>Brief minimal utile : URL cible, ancre souhaitée, sujets interdits, ton, et ce que l’éditeur peut refuser. Un brief flou produit des allers-retours. Les allers-retours retardent l’URL live.</p>

<h2>5. Paiement : assigner, confirmer, payer</h2>
<p>À la commande, assignez un article approuvé à chaque site, vérifiez le total, payez depuis le portefeuille. Les factures restent sous Facturation si la compta en a besoin plus tard.</p>
<p>Si le solde ne couvre pas, rechargez puis revenez. Le flux guidé (Marché → Éditeurs → Contenu → Paiement) aide les débutants ; les utilisateurs réguliers restent souvent dans le catalogue et ouvrent le panier quand la liste courte est prête.</p>

<h2>6. Suivre les commandes jusqu’à l’URL live</h2>
<p><strong>Commandes</strong> est la vue opérationnelle après paiement. Surveillez les statuts, utilisez le chat de commande pour clarifier, et contrôlez l’URL quand l’éditeur marque le placement comme livré.</p>
<p>Checklist courte le jour J :</p>
<ul>
<li>bonne page, bonne URL cible</li>
<li>ancre attendue</li>
<li>attributs de lien (dofollow / nofollow / sponsored) conformes à l’annonce</li>
<li>indexation — ou au moins une page crawlable et cohérente</li>
</ul>
<p>Notre guide <a href="{$liveCheck}">que vérifier après le lien live</a> est écrit pour exactement ce moment. Acheter bien ne sert à rien si personne ne contrôle la livraison.</p>

<h2>Habitudes qui évitent le chaos</h2>
<ul>
<li>Uploader le contenu avant de remplir un gros panier</li>
<li>Sauvegarder filtres et listes courtes pour les commandes suivantes</li>
<li>Garder une marge sur le portefeuille</li>
<li>Enregistrez l’URL et les attributs le jour de la livraison, pas un mois plus tard</li>
</ul>
<p>C’est cette discipline qui sépare un compte marketplace d’un tableur de liens oubliés. <a href="{$register}">Créez votre compte annonceur</a>, vérifiez l’e-mail, et passez la première commande avec des articles déjà approuvés.</p>

<h2>Foire aux questions</h2>
<h3>Faut-il uploader le contenu avant d’acheter une place ?</h3>
<p>Vous pouvez parcourir le catalogue et remplir le panier sans article. À la commande, chaque site attend un article approuvé. Mieux vaut uploader tôt dans la bibliothèque de contenus pour ne pas bloquer le paiement.</p>
<h3>Comment fonctionne le portefeuille en euros ?</h3>
<p>Vous créditez un solde dépensable (carte, virement ou autre méthode proposée). Lorsqu’une offre de bienvenue est active, un crédit dépensable uniquement peut s’appliquer sous les règles de la plateforme. Au paiement, le total de la commande est prélevé sur ce portefeuille.</p>
<h3>Que faire si l’URL live ne correspond pas au brief ?</h3>
<p>Ouvrez le fil de la commande, notez l’URL et les écarts (ancre, attributs, page). Demandez la correction à l’éditeur. Gardez une courte trace : elle sert si vous devez escalader ou demander un remboursement selon les règles de séquestre.</p>
<h3>Puis-je cibler uniquement la France et la Belgique ?</h3>
<p>Oui. Filtrez par pays et langue dans le catalogue. Pour le marché francophone, privilégiez le français et les sites dont le trafic ou le pays principal correspondent à FR ou BE — pas seulement une étiquette « Europe ».</p>
HTML;
    }
}
