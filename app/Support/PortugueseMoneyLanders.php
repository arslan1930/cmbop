<?php

namespace App\Support;

/**
 * Portugal-only money / B2B marketing landers (European Portuguese, PT-PT).
 * Not registered as shared LocalizedPublicPath keys. Italy keeps /it; Spain is separate.
 */
class PortugueseMoneyLanders
{
    public const LOCALE = 'pt';

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_keys(self::pages());
    }

    /**
     * Research URL aliases → Portuguese canonicals (no extra indexable twins).
     *
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return [
            'mercado' => '/pt/marketplace',
            'catalogo-de-meios' => '/pt/marketplace',
            'guest-post-portugal' => '/pt/comprar-guest-post',
            'publicar-guest-post' => '/pt/comprar-guest-post',
            'guest-posting-sites-portugal' => '/pt/comprar-guest-post',
            'comprar-artigo-patrocinado' => '/pt/artigo-patrocinado',
            'publicar-artigo-patrocinado' => '/pt/artigo-patrocinado',
            'comprar-links-seo' => '/pt/comprar-backlinks',
            'linkbuilding' => '/pt/link-building',
            'preco-guest-post' => '/pt/precos',
            'precos-guest-post' => '/pt/precos',
            'custo-linkbuilding' => '/pt/precos',
            'white-label-linkbuilding' => '/pt/agencias',
            'nota-de-imprensa' => '/pt/digital-pr',
            'comprar-nota-de-imprensa' => '/pt/digital-pr',
            'guia-guest-post' => '/pt/guia',
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
        return ['pt'];
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
        return url('/pt/'.$slug);
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
            ['slug' => 'home', 'label' => 'Marketplace Portugal', 'url' => url('/pt')],
            ['slug' => 'comprar-guest-post', 'label' => 'Comprar guest post', 'url' => self::url('comprar-guest-post')],
            ['slug' => 'artigo-patrocinado', 'label' => 'Artigo patrocinado', 'url' => self::url('artigo-patrocinado')],
            ['slug' => 'marketplace', 'label' => 'Catálogo de meios', 'url' => url('/pt/marketplace')],
            ['slug' => 'link-building', 'label' => 'Link building', 'url' => self::url('link-building')],
            ['slug' => 'comprar-backlinks', 'label' => 'Comprar backlinks', 'url' => self::url('comprar-backlinks')],
            ['slug' => 'precos', 'label' => 'Preços guest post', 'url' => url('/pt/precos')],
            ['slug' => 'agencias', 'label' => 'Para agências', 'url' => self::url('agencias')],
            ['slug' => 'digital-pr', 'label' => 'Digital PR', 'url' => self::url('digital-pr')],
            ['slug' => 'niche-edits', 'label' => 'Niche edits', 'url' => self::url('niche-edits')],
            ['slug' => 'guia', 'label' => 'Guia', 'url' => self::url('guia')],
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
        $marketplace = '/pt/marketplace';
        $precos = '/pt/precos';
        $how = '/pt/como-funciona';
        $register = '/register';
        $guest = '/pt/comprar-guest-post';
        $sponsored = '/pt/artigo-patrocinado';
        $links = '/pt/comprar-backlinks';
        $lb = '/pt/link-building';
        $agencies = '/pt/agencias';
        $pr = '/pt/digital-pr';
        $niche = '/pt/niche-edits';
        $guia = '/pt/guia';
        $publisher = '/pt/tornar-se-publisher';

        return [
            'comprar-guest-post' => [
                'kicker' => 'Guest post com backlink',
                'h1' => 'Comprar guest posts em sites portugueses e europeus',
                'subtitle' => 'Compre guest posts em publishers portugueses e europeus verificados: compare nicho, DA/DR e preço em euros, envie o briefing e acompanhe o URL em vivo na encomenda.',
                'meta_title' => 'Comprar guest post em Portugal | SEOLinkBuildings',
                'meta_description' => 'Comprar guest post em sites portugueses verificados. Filtre nicho, DA/DR e preço em EUR, escolha dofollow ou sponsored e acompanhe o URL em vivo.',
                'teaser_countries' => ['pt'],
                'teaser_title' => 'Exemplo de sites para guest posts em Portugal',
                'teaser_subtitle' => 'Pré-visualização mascarada de listings ativos de Portugal. Os domínios vêem-se depois do registo.',
                'intro' => [
                    'O SEOLinkBuildings é um marketplace self-service para anunciantes em Portugal, não um pacote opaco de guest posts. Escolha o site — muitas vezes .pt, muitas vezes em português europeu —, pague em euros a partir da carteira e mantenha briefing, chat e URL em vivo numa só encomenda. Sede em Londres (Topurlz Ltd); não há NIF português inventado.',
                    '«Comprar guest post», «guest post em blogs portugueses» e «guest posting sites Portugal» são a mesma intenção: uma publicação paga num site que não é seu, com regras escritas de extensão, ligações e prazos.',
                ],
                'points' => [
                    [
                        'title' => 'Publishers, não uma lista fantasma',
                        'body' => 'Cada linha é um site com nicho, idioma, país, DA/DR, tráfego declarado e preço de checkout. Não vendemos PBN nem «pacotes de 50 links».',
                    ],
                    [
                        'title' => 'Como encomendar',
                        'body' => 'Registe-se, filtre Portugal, adicione o site ao carrinho e envie título, texto ou briefing mais a âncora. O publisher entrega o URL em vivo para aprovação.',
                    ],
                    [
                        'title' => 'Dofollow e sponsored',
                        'body' => 'O atributo da ligação está no listing. Muitos sites marcam publicações pagas. Leia o tipo de link antes de encomendar — não há «dofollow a qualquer preço».',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Guest post com backlink em Portugal',
                        'body' => 'No mercado português, «comprar guest post», «guest post permanente» e «guest post com tráfego» coincidem na intenção de compra. Pague uma publicação com as regras do listing. A permanência é a que o publisher declara — não é uma promessa de ranking. Verticais (finanças, saúde, imobiliário, seguros, tecnologia) e cidades (Lisboa, Porto) são temas desta página, não landings /pt/nichos.',
                    ],
                    [
                        'h2' => 'Sites com DA ou DR elevado',
                        'body' => 'Filtre DA e DR depois do registo para separar sites com DA elevado ou DR elevado. As métricas ajudam a triar; não decidem sozinhas. O catálogo público está em <a href="'.$marketplace.'">/pt/marketplace</a>. Preços: <a href="'.$precos.'">quanto custa um guest post</a>. Se publica um site .pt: <a href="'.$publisher.'">tornar-se publisher</a>.',
                    ],
                    [
                        'h2' => 'Para agências e equipas SEO',
                        'body' => 'A agência continua a escolher os sites e entrega o URL em vivo ao cliente. Fluxo B2B: <a href="'.$agencies.'">guest post para agências</a>. Conceitos: <a href="'.$guia.'">guia de guest post e link building</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Posso comprar só guest posts de Portugal?',
                        'a' => 'Sim. No catálogo filtre o país Portugal. A mesma carteira EUR serve para outros mercados europeus se precisar deles.',
                    ],
                    [
                        'q' => 'A ligação é sempre dofollow?',
                        'a' => 'Não. Depende do listing. Confirme o tipo de link e, depois da publicação, o atributo rel.',
                    ],
                    [
                        'q' => 'Inclui o Brasil ou outros mercados lusófonos?',
                        'a' => 'Não nesta landing. O Brasil e outros países têm o seu próprio filtro de país depois do registo.',
                    ],
                ],
                'cta_primary' => ['label' => 'Criar conta e abrir o catálogo', 'url' => $register],
                'cta_secondary' => ['label' => 'Como funciona a encomenda', 'url' => $how],
                'see_also' => [
                    ['label' => 'Catálogo de meios em Portugal', 'url' => $marketplace],
                    ['label' => 'Quanto custa um guest post', 'url' => $precos],
                    ['label' => 'Artigo patrocinado', 'url' => $sponsored],
                ],
            ],
            'artigo-patrocinado' => [
                'kicker' => 'Publicação paga',
                'h1' => 'Artigo patrocinado em Portugal',
                'subtitle' => 'Artigo patrocinado, advertorial ou conteúdo nativo em publishers portugueses: o mesmo marketplace dos guest posts, com preço em EUR, briefing e URL em vivo.',
                'meta_title' => 'Artigo patrocinado em Portugal | SEOLinkBuildings',
                'meta_description' => 'Comprar artigo patrocinado em Portugal: filtre publishers, pague em EUR, envie o briefing e acompanhe o URL em vivo — sem ranking garantido.',
                'teaser_countries' => ['pt'],
                'teaser_title' => 'Sites para publicações pagas',
                'teaser_subtitle' => 'O mesmo catálogo Portugal dos guest posts. Aqui conta o vocabulário comercial: advertorial, sponsored, conteúdo patrocinado.',
                'intro' => [
                    '«Comprar artigo patrocinado», «advertorial Portugal» e «conteúdo patrocinado» descrevem uma publicação paga num terceiro. No SEOLinkBuildings não é um SKU à parte: é a mesma encomenda de catálogo, com disclosure e atributo de link conforme o listing.',
                    'Não vendemos ranking nem uma nota de imprensa num teletipo nacional. Vendemos uma publicação com regras visíveis, checkout em euro e entrega rastreável.',
                ],
                'points' => [
                    [
                        'title' => 'O mesmo catálogo, outro vocabulário',
                        'body' => 'Filtre nicho, idioma e preço. O media kit do publisher está no listing: temas aceites, número de ligações, prazos.',
                    ],
                    [
                        'title' => 'Patrocinado vs guest post',
                        'body' => 'Se paga para aparecer, trate-o como sponsored — mesmo que a fatura diga guest post. O Google espera uma qualificação (rel sponsored ou nofollow) em colocações pagas.',
                    ],
                    [
                        'title' => 'Conteúdo editorial, não um banner',
                        'body' => 'Entrega texto ou briefing. O publisher publica no CMS dele. Não é display nem uma menção de marca num diário, salvo se esse site o for e estiver no catálogo.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Publicar um artigo patrocinado',
                        'body' => 'Registe-se, escolha sites, pague a partir da carteira e envie o material. O estado permanece na encomenda até ao URL em vivo. Se procura guest post: <a href="'.$guest.'">comprar guest post em Portugal</a>.',
                    ],
                    [
                        'h2' => 'Nota de imprensa e PR',
                        'body' => 'Uma nota de imprensa SEO não é o mesmo produto que um guest post. Essa intenção está em <a href="'.$pr.'">digital PR</a> — sem garantias de Google News.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'É outro produto que o guest post?',
                        'a' => 'Não como produto. Sim como etiqueta e na forma como se declara a ligação paga.',
                    ],
                    [
                        'q' => 'Posso exigir dofollow num artigo patrocinado?',
                        'a' => 'Só se o listing o permitir. Muitos publishers marcam as publicações pagas.',
                    ],
                    [
                        'q' => 'Vendem notas de imprensa em meios nacionais?',
                        'a' => 'Só se esse domínio estiver no catálogo. Não prometemos teletipo nem Google News.',
                    ],
                ],
                'cta_primary' => ['label' => 'Abrir o catálogo depois do registo', 'url' => $register],
                'cta_secondary' => ['label' => 'Comprar guest post', 'url' => $guest],
                'see_also' => [
                    ['label' => 'Catálogo', 'url' => $marketplace],
                    ['label' => 'Digital PR Portugal', 'url' => $pr],
                    ['label' => 'Preços', 'url' => $precos],
                ],
            ],
            'link-building' => [
                'kicker' => 'Plataforma, não um pacote fechado',
                'h1' => 'Link building em Portugal',
                'subtitle' => 'Link building self-service: escolha publicações portuguesas, pague em euros e acompanhe os URL em vivo. Sem ranking garantido e sem pacotes misteriosos.',
                'meta_title' => 'Link building em Portugal | SEOLinkBuildings',
                'meta_description' => 'Link building em Portugal: catálogo self-service, DA/DR e preços em EUR, publicações com acompanhamento. Não é um pacote opaco de links.',
                'teaser_countries' => ['pt'],
                'teaser_title' => 'Inventário para campanhas de link building',
                'teaser_subtitle' => 'Exemplo de sites Portugal. Para fintech ou outros nichos, filtre depois do registo — não há doorway por vertical nem por cidade.',
                'intro' => [
                    '«Link building Portugal», «pacotes linkbuilding» e «agência linkbuilding Portugal» costumam ser páginas de agência. O SEOLinkBuildings é uma plataforma: monta a campanha a partir do catálogo, com uma carteira EUR para PT e o resto da Europa.',
                    'White label ou faturas para várias marcas: <a href="'.$agencies.'">link building para agências</a>. Guia: <a href="'.$guia.'">como fazer link building</a>.',
                ],
                'points' => [
                    [
                        'title' => 'Self-service, não um PDF',
                        'body' => 'Veja o site, o preço de checkout e as métricas antes de pagar. Não se compra um «pacote de 20 DR50» sem nomes.',
                    ],
                    [
                        'title' => 'Custo do link building',
                        'body' => 'O custo é a soma das publicações escolhidas mais pacotes de digital PR geridos na tarifa. Detalhe: <a href="'.$precos.'">preços de guest post</a>.',
                    ],
                    [
                        'title' => 'Guest post, PR, niche edit',
                        'body' => 'Link building manual aqui significa escolher os publishers. Guest posts e artigos patrocinados são encomendas de catálogo. Niche edits não são um SKU — explicação: <a href="'.$niche.'">niche edits</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Estratégia numa plataforma',
                        'body' => 'Compare agências e outros marketplaces com factos verificáveis: carteira EUR, URL na encomenda, catálogo filtrável, sociedade UK. Não afirmamos ser «a melhor agência SEO de Lisboa».',
                    ],
                    [
                        'h2' => 'Montar a campanha',
                        'body' => 'Defina URLs de destino, filtre sites portugueses, varie a âncora e mantenha um ritmo sustentável. Compra: <a href="'.$links.'">comprar backlinks</a>. Marque as colocações pagas.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Vendem pacotes de link building?',
                        'a' => 'Não como SKU opaco. Compre publicações avulsas. Os pacotes numerados em Preços são campanhas de digital PR geridas, não um saco de URLs anónimos.',
                    ],
                    [
                        'q' => 'É link building white-hat?',
                        'a' => 'O catálogo é inventário editorial com regras. O Google trata links comprados para manipular rankings como spam se não estiverem qualificados.',
                    ],
                    [
                        'q' => 'Preciso de uma agência em Lisboa?',
                        'a' => 'Não para usar o catálogo. Sim se alguém tiver de escolher os sites por si — ou usa a conta de agência e continua a decidir.',
                    ],
                ],
                'cta_primary' => ['label' => 'Começar pelo catálogo', 'url' => $register],
                'cta_secondary' => ['label' => 'Preços', 'url' => $precos],
                'see_also' => [
                    ['label' => 'Comprar guest post', 'url' => $guest],
                    ['label' => 'Para agências', 'url' => $agencies],
                    ['label' => 'Guia', 'url' => $guia],
                ],
            ],
            'comprar-backlinks' => [
                'kicker' => 'Backlinks editoriais',
                'h1' => 'Comprar backlinks em Portugal',
                'subtitle' => 'Backlinks portugueses e links SEO a partir do catálogo: preços em euro, DA/DR, atributo declarado. Sem PBN e sem ranking garantido.',
                'meta_title' => 'Comprar backlinks em Portugal | SEOLinkBuildings',
                'meta_description' => 'Comprar backlinks em Portugal em publishers verificados. Compare dofollow, nicho e preço EUR; acompanhe o URL em vivo — sem garantia de ranking.',
                'teaser_countries' => ['pt'],
                'teaser_title' => 'Sites para backlinks em Portugal (pré-visualização)',
                'teaser_subtitle' => 'Os hosts continuam mascarados até ter conta. «Backlinks de qualidade» aqui significa filtrável, não um ranking objetivo.',
                'intro' => [
                    '«Comprar backlinks», «backlinks Portugal» e «links dofollow» são a mesma pergunta comercial. No SEOLinkBuildings o backlink nasce de uma publicação no site do publisher, não de uma rede de domínios expirados.',
                    'O Google trata links comprados para manipular rankings como spam de links se não estiverem qualificados. Trate as publicações pagas como sponsored/nofollow quando o site o exigir.',
                ],
                'points' => [
                    [
                        'title' => 'Ligação no conteúdo, não no footer',
                        'body' => 'O briefing pede a ligação no corpo do artigo. Não vendemos sitewide nem directórios SEO.',
                    ],
                    [
                        'title' => 'Dofollow só conforme o listing',
                        'body' => 'Filtre o tipo de link depois do registo. «Comprar links dofollow» não substitui nicho, idioma e audiência.',
                    ],
                    [
                        'title' => 'Sem SKU de niche edit',
                        'body' => 'Não inserimos uma ligação num artigo alheio que já posiciona, salvo se o publisher oferecer um extra de homepage no listing. Explicação: <a href="'.$niche.'">niche edits</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Backlinks temáticos e com tráfego',
                        'body' => 'Filtre o país Portugal. A vista pública mascara domínios. Catálogo: <a href="'.$marketplace.'">catálogo de meios</a>. Ligações permanentes são as que o publisher declara, não uma garantia vitalícia.',
                    ],
                    [
                        'h2' => 'Qualidade, não promessas',
                        'body' => 'Temática, contexto editorial, qualidade do publisher, audiência, conteúdo, colocação, âncoras transparentes e o perfil global — fatores que pode verificar. Um único backlink não «melhora rankings» sozinho. Riscos: <a href="'.$guia.'">guia</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Posso comprar só dofollow?',
                        'a' => 'Pode filtrar ofertas que o declarem. O publisher continua responsável pelo HTML em vivo.',
                    ],
                    [
                        'q' => 'Fazem inserções em artigos existentes?',
                        'a' => 'Não como SKU de niche edit. Alguns sites vendem um extra de homepage com prazo.',
                    ],
                    [
                        'q' => 'Quanto custam os backlinks em Portugal?',
                        'a' => 'Depende do site. A página de preços explica o modelo (por publicação, em EUR); o catálogo, os preços em vivo.',
                    ],
                ],
                'cta_primary' => ['label' => 'Comparar sites no catálogo', 'url' => $register],
                'cta_secondary' => ['label' => 'Link building Portugal', 'url' => $lb],
                'see_also' => [
                    ['label' => 'Preços', 'url' => $precos],
                    ['label' => 'Comprar guest post', 'url' => $guest],
                    ['label' => 'Niche edits', 'url' => $niche],
                ],
            ],
            'agencias' => [
                'kicker' => 'Conta B2B',
                'h1' => 'Link building para agências em Portugal',
                'subtitle' => 'Catálogo self-service para agências SEO, revendedores e equipas que refaturam. Carteira EUR, encomendas com acompanhamento, faturas na faturação do anunciante.',
                'meta_title' => 'Link building para agências em Portugal | SEOLinkBuildings',
                'meta_description' => 'White label e guest posts para agências em Portugal: catálogo EUR, faturas, encomendas por marca — sem um frontend de revenda com o seu logotipo.',
                'teaser_countries' => ['pt'],
                'teaser_title' => 'Inventário que pode refaturar',
                'teaser_subtitle' => 'Os mesmos listings que um anunciante interno. A conta é sua; as marcas vivem nos seus projetos e encomendas.',
                'intro' => [
                    '«Agência linkbuilding Portugal», «guest post para agências» e «outreach linkbuilding» procuram um fornecedor que execute. Aqui a agência mantém o comando: escolhe sites, paga e entrega o URL em vivo ao cliente.',
                    'White label operacional significa: o cliente final não tem de criar conta no marketplace. Não é um programa de revenda com o seu logotipo no site público.',
                ],
                'points' => [
                    [
                        'title' => 'Uma carteira, várias campanhas',
                        'body' => 'Carregue em euros (cartão ou transferência, se estiver ativo) e distribua o saldo entre encomendas.',
                    ],
                    [
                        'title' => 'Encomenda e fatura',
                        'body' => 'As faturas de encargos de carteira ou encomenda descarregam-se na faturação do anunciante quando o produto as gera. Dados da empresa UK (Topurlz Ltd) em <a href="/pt/sobre-nos">Quem somos</a> — não há entidade legal portuguesa inventada.',
                    ],
                    [
                        'title' => 'Plataforma para equipas SEO',
                        'body' => 'Filtros, métricas, chat da encomenda e URL em vivo. O painel depois do registo está em inglês para todos os papéis.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Agência vs marketplace',
                        'body' => 'Uma agência escolhe os sites para o cliente. Um marketplace mostra os sites ao comprador. O SEOLinkBuildings é o segundo. Se a sua equipa é a agência, a escolha dos sites fica convosco e o catálogo é a fonte.',
                    ],
                    [
                        'h2' => 'Alta',
                        'body' => 'Crie conta de anunciante, carregue saldo, filtre Portugal e encomende. Fluxo: <a href="'.$how.'">como funciona</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Posso ocultar o marketplace ao cliente?',
                        'a' => 'Sim, operando a conta. Não entregamos um portal white-label com a sua marca.',
                    ],
                    [
                        'q' => 'Emitem faturas com IVA português?',
                        'a' => 'A faturação segue a sociedade britânica do produto. Descarregue os comprovativos e esclareça com a contabilidade se precisar de mais integrações. Não há NIF português inventado.',
                    ],
                    [
                        'q' => 'Há uma tarifa de agência à parte?',
                        'a' => 'O preço de checkout é o do listing. Não há um segundo «catálogo agência».',
                    ],
                ],
                'cta_primary' => ['label' => 'Criar conta de agência', 'url' => $register],
                'cta_secondary' => ['label' => 'Catálogo de meios', 'url' => $marketplace],
                'see_also' => [
                    ['label' => 'Link building', 'url' => $lb],
                    ['label' => 'Preços', 'url' => $precos],
                    ['label' => 'Digital PR', 'url' => $pr],
                ],
            ],
            'digital-pr' => [
                'kicker' => 'PR em meios digitais',
                'h1' => 'Digital PR em Portugal: publicações e menções',
                'subtitle' => 'Campanhas de digital PR como publicações em sites do marketplace, mais pacotes geridos em preços. Sem promessa de Google News nem notas de imprensa inventadas.',
                'meta_title' => 'Digital PR em Portugal | SEOLinkBuildings',
                'meta_description' => 'Digital PR em Portugal: publicações de catálogo, carteira EUR, URL em vivo, pacotes geridos — sem garantias de News nem teletipo.',
                'teaser_countries' => ['pt'],
                'teaser_title' => 'Sites Portugal no catálogo (pré-visualização)',
                'teaser_subtitle' => 'Alguns publishers parecem um media kit; nem todos são um diário. Nicho e idioma filtram-se depois do registo.',
                'intro' => [
                    '«Digital PR Portugal», «nota de imprensa SEO» e «menções de marca» misturam PR e link building. Aqui compra publicações em sites que estão mesmo no catálogo. Se um domínio não está listado, não o vendemos.',
                    'Os pacotes geridos de digital PR (valores em Preços, hoje a partir de 499 €/mês no plano base se continuar listado) são outreach da equipa, não um botão «apareça num diário nacional».',
                ],
                'points' => [
                    [
                        'title' => 'Meios só se estiverem no catálogo',
                        'body' => 'Não temos um canal de Google News nem uma quota de teletipo. Um guest post «News» existe só se esse site for um listing e aceitar o briefing.',
                    ],
                    [
                        'title' => 'Menções de marca',
                        'body' => 'Uma menção pode nascer de uma publicação. Não vendemos «comprar brand mention» como SKU sem URL.',
                    ],
                    [
                        'title' => 'Campanhas, não um comunicado às cegas',
                        'body' => 'Self-service: escolha sites. Gerido: vendas através dos pacotes em Preços.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'PR e SEO, sem inflacionar',
                        'body' => 'Uma publicação útil tem leitores, contexto e uma ligação (ou uma menção) que faz sentido. Não substitui uma notícia real. Catálogo: <a href="'.$marketplace.'">lista de meios</a>. Pacotes: <a href="'.$precos.'">preços</a>.',
                    ],
                    [
                        'h2' => 'Guest post vs digital PR',
                        'body' => 'O guest post é um artigo no site anfitrião. O digital PR aponta a uma história que um editor quereria por si. No marketplace paga na mesma a publicação: trate-a como sponsored se houver contraprestação. <a href="'.$sponsored.'">Artigo patrocinado</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Publicam no Google News ou num teletipo?',
                        'a' => 'Não como produto. Se um site do catálogo está no News ou num serviço de notas, depende do publisher, não de nós.',
                    ],
                    [
                        'q' => 'Posso comprar só uma menção sem artigo?',
                        'a' => 'Só se um listing o oferecer. O padrão é um artigo com ligação no corpo.',
                    ],
                    [
                        'q' => 'Quanto custa uma campanha?',
                        'a' => 'Self-service: soma dos listings. Gerido: os pacotes em Preços (valores em EUR da tarifa atual).',
                    ],
                ],
                'cta_primary' => ['label' => 'Ver pacotes e catálogo', 'url' => $precos],
                'cta_secondary' => ['label' => 'Registar', 'url' => $register],
                'see_also' => [
                    ['label' => 'Artigo patrocinado', 'url' => $sponsored],
                    ['label' => 'Para agências', 'url' => $agencies],
                    ['label' => 'Comprar guest post', 'url' => $guest],
                ],
            ],
            'niche-edits' => [
                'kicker' => 'Não é um produto à parte',
                'h1' => 'Niche edits em Portugal — e o que vendemos em alternativa',
                'subtitle' => 'Os niche edits (inserção de link em artigos existentes) não são um SKU no SEOLinkBuildings. Aqui a fronteira com guest posts, extras de homepage e os riscos.',
                'meta_title' => 'Niche edits em Portugal, explicados | SEOLinkBuildings',
                'meta_description' => 'O que são niche edits e inserções de link, quando são arriscados, e porque em Portugal vendemos publicações editoriais — não um insert em rankings alheios.',
                'teaser_countries' => ['pt'],
                'teaser_title' => 'Sites editoriais, não redes de inserts',
                'teaser_subtitle' => 'Pré-visualização de listings Portugal ativos. O produto padrão é um artigo novo com ligação no corpo, não um insert silencioso.',
                'intro' => [
                    'Um niche edit é uma ligação metida num artigo já publicado — muitas vezes porque o URL já está indexado. «Inserção de link» e «links em artigos existentes» procuram exactamente isso.',
                    'O SEOLinkBuildings não o vende como produto. A encomenda padrão é uma publicação nova (guest post ou artigo patrocinado) com briefing e URL em vivo. Alguns publishers oferecem um extra de homepage com prazo; está visível no listing, não é um insert silencioso num artigo que já posiciona.',
                ],
                'points' => [
                    [
                        'title' => 'Verifique a relevância',
                        'body' => 'Um insert em conteúdo antigo de outro tema costuma ser pior do que um artigo novo num site português adequado.',
                    ],
                    [
                        'title' => 'Riscos',
                        'body' => 'Titularidade pouco clara, âncoras alteradas a posteriori, falta de disclosure, redes de inserts com o mesmo padrão de saída.',
                    ],
                    [
                        'title' => 'O que compra aqui',
                        'body' => 'Uma publicação com regras, checkout EUR e URL em vivo. <a href="'.$guest.'">Comprar guest post</a> ou <a href="'.$links.'">comprar backlinks</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Quando faria sentido um niche edit',
                        'body' => 'Só se o artigo existente encaixar tematicamente, o publisher responder editorialmente pelo insert e a ligação continuar transparente. Não o orquestramos como SKU em massa.',
                    ],
                    [
                        'h2' => 'O extra de homepage é outra coisa',
                        'body' => 'Se o listing oferece homepage, é um extra visível e muitas vezes temporário — não é o mesmo que uma ligação num artigo de nicho já publicado.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Posso pedir uma ligação num artigo existente?',
                        'a' => 'Só se um listing concreto o oferecer. O padrão é um artigo novo.',
                    ],
                    [
                        'q' => 'Porque não há uma landing de venda de niche edits?',
                        'a' => 'Porque não temos esse produto. Uma landing de venda seria enganosa.',
                    ],
                    [
                        'q' => 'Qual é a alternativa?',
                        'a' => 'Guest posts ou artigos patrocinados temáticos do catálogo Portugal, com briefing e URL em vivo.',
                    ],
                ],
                'cta_primary' => ['label' => 'Escolher guest posts no catálogo', 'url' => $register],
                'cta_secondary' => ['label' => 'Comprar backlinks', 'url' => $links],
                'see_also' => [
                    ['label' => 'Link building', 'url' => $lb],
                    ['label' => 'Comprar guest post', 'url' => $guest],
                    ['label' => 'Guia', 'url' => $guia],
                ],
            ],
            'guia' => [
                'kicker' => 'Conceitos, não uma loja extra',
                'h1' => 'Guia de guest post e link building em Portugal',
                'subtitle' => 'O que é um guest post, como comprar backlinks com menos risco, dofollow vs nofollow, âncora e a diferença entre PBN e publicação editorial. Uma página, sem dezenas de doorways.',
                'meta_title' => 'Guia de guest post e link building | SEOLinkBuildings',
                'meta_description' => 'Guia em português europeu: o que é um guest post, como fazer link building, dofollow vs nofollow, riscos de comprar backlinks e rel sponsored.',
                'teaser_countries' => ['pt'],
                'teaser_title' => 'O catálogo por trás do guia',
                'teaser_subtitle' => 'A teoria fica nesta página. A compra faz-se no catálogo Portugal, com preço em EUR e URL em vivo.',
                'intro' => [
                    'Um guest post é um artigo publicado num site de terceiro, normalmente com uma ligação para o seu. Link building é o trabalho de obter essas ligações de forma deliberada. Comprar backlinks, neste marketplace, significa pagar uma publicação editorial — não um pacote anónimo.',
                    'Não prometemos rankings. O Google trata links pagos para manipular resultados como spam se não estiverem qualificados (rel sponsored ou nofollow).',
                ],
                'points' => [
                    [
                        'title' => 'Dofollow, nofollow, sponsored',
                        'body' => 'Dofollow (sem rel restritivo) passa sinais. Nofollow e sponsored qualificam a ligação. O tipo está no listing; confirme o HTML em vivo.',
                    ],
                    [
                        'title' => 'Âncora',
                        'body' => 'Varie o texto âncora. Um perfil só com a palavra-chave exacta é um padrão fácil de reconhecer. O briefing pede a âncora; o publisher publica.',
                    ],
                    [
                        'title' => 'PBN vs guest post',
                        'body' => 'Uma PBN é uma rede de sites criada para passar links. Um guest post editorial vive num site com audiência própria. Não vendemos PBN.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Como comprar sem inventar métricas',
                        'body' => 'Filtre país Portugal, idioma, nicho e preço. Use DA e DR para triar sites com DA elevado ou DR elevado, não como garantia. Tráfego declarado é o do listing — não inventamos visitas. Compra: <a href="'.$guest.'">comprar guest post</a> e <a href="'.$links.'">comprar backlinks</a>.',
                    ],
                    [
                        'h2' => 'Riscos de comprar backlinks',
                        'body' => 'Sites sem audiência, âncoras idênticas, falta de disclosure, links que desaparecem, e a expectativa de que um único link «sobe o ranking». O acompanhamento do URL em vivo reduz o risco operacional; não elimina o risco de política do Google. Processo: <a href="'.$how.'">como funciona a encomenda</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Este guia substitui o catálogo?',
                        'a' => 'Não. Explica os termos. Os sites e os preços estão no marketplace depois do registo.',
                    ],
                    [
                        'q' => 'Há uma página por cada conceito?',
                        'a' => 'Não. Dofollow, âncora, PBN e riscos ficam nesta página para não canibalizar a intenção de compra.',
                    ],
                    [
                        'q' => 'Onde encomendo?',
                        'a' => 'Na página comprar guest post ou no catálogo /pt/marketplace, depois do registo.',
                    ],
                ],
                'cta_primary' => ['label' => 'Abrir o catálogo', 'url' => $register],
                'cta_secondary' => ['label' => 'Comprar guest post', 'url' => $guest],
                'see_also' => [
                    ['label' => 'Link building', 'url' => $lb],
                    ['label' => 'Artigo patrocinado', 'url' => $sponsored],
                    ['label' => 'Niche edits', 'url' => $niche],
                ],
            ],
        ];
    }
}
