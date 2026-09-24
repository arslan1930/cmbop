<?php

namespace App\Support;

/**
 * Spain-only money / B2B marketing landers (Spanish search behaviour).
 * Not registered as shared LocalizedPublicPath keys — other locales 301 here
 * unless that locale already owns the same slug (IT/DE/AT/CH digital-pr, etc.).
 */
class SpanishMoneyLanders
{
    public const LOCALE = 'es';

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_keys(self::pages());
    }

    /**
     * Research URL aliases → Spanish canonicals (no extra indexable twins).
     *
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return [
            'marketplace' => '/es/mercado',
            'catalogo-de-medios' => '/es/mercado',
            'catalogo-de-medios-seo' => '/es/mercado',
            'comprar-enlaces-seo' => '/es/comprar-backlinks',
            'comprar-articulo-patrocinado' => '/es/articulo-patrocinado',
            'publicar-guest-post' => '/es/comprar-guest-post',
            'linkbuilding' => '/es/link-building',
            'precio-guest-post' => '/es/precios',
            'precios-guest-post' => '/es/precios',
            'coste-linkbuilding' => '/es/precios',
            'guia' => '/es/como-funciona',
            'white-label-linkbuilding' => '/es/agencias',
            'nota-de-prensa' => '/es/digital-pr',
            'comprar-nota-de-prensa' => '/es/digital-pr',
            'publisher' => '/es/convertirse-en-publisher',
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
        return ['es'];
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
        return url('/es/'.$slug);
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
            ['slug' => 'home', 'label' => 'Marketplace España', 'url' => url('/es')],
            ['slug' => 'comprar-guest-post', 'label' => 'Comprar guest post', 'url' => self::url('comprar-guest-post')],
            ['slug' => 'articulo-patrocinado', 'label' => 'Artículo patrocinado', 'url' => self::url('articulo-patrocinado')],
            ['slug' => 'mercado', 'label' => 'Catálogo de medios', 'url' => url('/es/mercado')],
            ['slug' => 'link-building', 'label' => 'Link building', 'url' => self::url('link-building')],
            ['slug' => 'comprar-backlinks', 'label' => 'Comprar backlinks', 'url' => self::url('comprar-backlinks')],
            ['slug' => 'precios', 'label' => 'Precios guest post', 'url' => url('/es/precios')],
            ['slug' => 'agencias', 'label' => 'Para agencias', 'url' => self::url('agencias')],
            ['slug' => 'digital-pr', 'label' => 'PR digital', 'url' => self::url('digital-pr')],
            ['slug' => 'niche-edits', 'label' => 'Niche edits', 'url' => self::url('niche-edits')],
            ['slug' => 'alternativas', 'label' => 'Alternativas', 'url' => self::url('alternativas')],
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
        $mercado = '/es/mercado';
        $precios = '/es/precios';
        $how = '/es/como-funciona';
        $register = '/register';
        $guest = '/es/comprar-guest-post';
        $sponsored = '/es/articulo-patrocinado';
        $links = '/es/comprar-backlinks';
        $lb = '/es/link-building';
        $agencies = '/es/agencias';
        $pr = '/es/digital-pr';
        $niche = '/es/niche-edits';
        $alts = '/es/alternativas';
        $publisher = '/es/convertirse-en-publisher';

        return [
            'comprar-guest-post' => [
                'kicker' => 'Guest post con backlink',
                'h1' => 'Comprar guest post en España',
                'subtitle' => 'Compre guest posts en publishers españoles y europeos verificados: compare nicho, DA/DR y precio en euros, envíe el briefing y siga la URL en vivo en el pedido.',
                'meta_title' => 'Comprar guest post en España | SEOLinkBuildings',
                'meta_description' => 'Comprar guest post en España en publishers verificados. Filtre nicho, DA/DR y precio en EUR, pida dofollow o sponsored y siga la URL en vivo.',
                'teaser_countries' => ['es'],
                'teaser_title' => 'Ejemplo de sitios para guest posts en España',
                'teaser_subtitle' => 'Vista previa enmascarada de listings activos de España. Los dominios se ven tras registrarse.',
                'intro' => [
                    'SEOLinkBuildings es un marketplace self-service para anunciantes en España, no un paquete opaco de guest posts. Elija el sitio — a menudo .es, a menudo en español —, pague en euros desde el monedero y mantenga el briefing, el chat y la URL en vivo en un solo pedido. Sede en Londres (Topurlz Ltd); no hay CIF español inventado.',
                    '«Comprar guest post», «publicar artículo invitado» y «guest post España» son la misma intención: una publicación de pago en un sitio que no es suyo, con reglas escritas de longitud, enlaces y plazos.',
                ],
                'points' => [
                    [
                        'title' => 'Publishers, no un listado fantasma',
                        'body' => 'Cada fila es un sitio con nicho, idioma, país, DA/DR, tráfico declarado y precio de checkout. No vendemos PBN ni «paquetes de 50 enlaces».',
                    ],
                    [
                        'title' => 'Cómo pedir',
                        'body' => 'Regístrese, filtre España, añada el sitio al carrito, envíe título, texto o briefing más el ancla. El publisher entrega la URL en vivo para aprobación.',
                    ],
                    [
                        'title' => 'Dofollow y sponsored',
                        'body' => 'El atributo del enlace está en el listing. Muchos sitios marcan las publicaciones de pago. Lea el tipo de enlace antes de pedir — no hay «dofollow a cualquier precio».',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Guest post con backlink en España',
                        'body' => 'En el mercado español, «comprar guest post», «guest post permanente» y «guest post de calidad» coinciden en intención de compra. Pague una publicación con las reglas del listing. La permanencia es la que declara el publisher — no es una promesa de ranking. Verticales (finanzas, salud, inmobiliaria, seguros, tecnología) y ciudades (Madrid, Barcelona) son H2 de esta página, no landings /es/nichos.',
                    ],
                    [
                        'h2' => 'Catálogo de medios, no un doorway',
                        'body' => 'El catálogo público está en <a href="'.$mercado.'">/es/mercado</a>. Precios: <a href="'.$precios.'">qué cuesta un guest post</a>. Si publica un sitio .es: <a href="'.$publisher.'">convertirse en publisher</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => '¿Puedo comprar solo guest posts de España?',
                        'a' => 'Sí. En el catálogo filtre país España. El mismo monedero EUR sirve para otros mercados europeos si los necesita.',
                    ],
                    [
                        'q' => '¿El enlace es siempre dofollow?',
                        'a' => 'No. Depende del listing. Compruebe el tipo de enlace y, tras la publicación, el atributo rel.',
                    ],
                    [
                        'q' => '¿Incluye Latinoamérica?',
                        'a' => 'No en esta landing. México y otros mercados LATAM tienen su propio filtro de país tras el login.',
                    ],
                ],
                'cta_primary' => ['label' => 'Crear cuenta y abrir el catálogo', 'url' => $register],
                'cta_secondary' => ['label' => 'Cómo funciona el pedido', 'url' => $how],
                'see_also' => [
                    ['label' => 'Catálogo de medios en España', 'url' => $mercado],
                    ['label' => 'Qué cuesta un guest post', 'url' => $precios],
                    ['label' => 'Artículo patrocinado', 'url' => $sponsored],
                ],
            ],
            'articulo-patrocinado' => [
                'kicker' => 'Publicación de pago',
                'h1' => 'Artículo patrocinado en España',
                'subtitle' => 'Artículo patrocinado, advertorial o contenido nativo en publishers españoles: el mismo marketplace que los guest posts, con precio en EUR, briefing y URL en vivo.',
                'meta_title' => 'Artículo patrocinado en España | SEOLinkBuildings',
                'meta_description' => 'Comprar artículo patrocinado en España: filtre publishers, pague en EUR, envíe el briefing y siga la URL en vivo — sin ranking garantizado.',
                'teaser_countries' => ['es'],
                'teaser_title' => 'Sitios para publicaciones de pago',
                'teaser_subtitle' => 'El mismo catálogo España que en guest posts. Aquí cuenta el vocabulario comercial: advertorial, sponsored, contenido patrocinado.',
                'intro' => [
                    '«Comprar artículo patrocinado», «advertorial España» y «contenido patrocinado» describen una publicación de pago en un tercero. En SEOLinkBuildings no es un SKU aparte: es el mismo pedido de catálogo, con disclosure y atributo de enlace según el listing.',
                    'No vendemos ranking ni una nota de prensa en un teletipo nacional. Vendemos una publicación con reglas visibles, checkout en euro y entrega rastreable.',
                ],
                'points' => [
                    [
                        'title' => 'Mismo catálogo, otro vocabulario',
                        'body' => 'Filtre nicho, idioma y precio. El media kit del publisher está en el listing: temas aceptados, número de enlaces, plazos.',
                    ],
                    [
                        'title' => 'Patrocinado vs guest post',
                        'body' => 'Si paga por aparecer, trátelo como sponsored — aunque la factura diga guest post. Google espera una cualificación (rel sponsored o nofollow) en colocaciones de pago.',
                    ],
                    [
                        'title' => 'Contenido editorial, no un banner',
                        'body' => 'Entrega texto o briefing. El publisher publica en su CMS. No es display ni una mención de marca en un diario, salvo que ese sitio lo sea y esté en el catálogo.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Publicar un artículo invitado o patrocinado',
                        'body' => 'Regístrese, elija sitios, pague desde el monedero, envíe el material. El estado permanece en el pedido hasta la URL en vivo. Si busca guest post: <a href="'.$guest.'">comprar guest post en España</a>.',
                    ],
                    [
                        'h2' => 'Nota de prensa y PR',
                        'body' => 'Una nota de prensa SEO no es el mismo producto que un guest post. Mapeamos esa intención a <a href="'.$pr.'">PR digital</a> — sin garantías de Google News.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => '¿Es otro producto que el guest post?',
                        'a' => 'No como producto. Sí como etiqueta y en cómo se declara el enlace de pago.',
                    ],
                    [
                        'q' => '¿Puedo exigir dofollow en un artículo patrocinado?',
                        'a' => 'Solo si el listing lo permite. Muchos publishers marcan las publicaciones de pago.',
                    ],
                    [
                        'q' => '¿Vendéis notas de prensa en medios nacionales?',
                        'a' => 'Solo si ese dominio está en el catálogo. No prometemos teletipo ni Google News.',
                    ],
                ],
                'cta_primary' => ['label' => 'Abrir el catálogo tras registrarse', 'url' => $register],
                'cta_secondary' => ['label' => 'Comprar guest post', 'url' => $guest],
                'see_also' => [
                    ['label' => 'Catálogo', 'url' => $mercado],
                    ['label' => 'PR digital España', 'url' => $pr],
                    ['label' => 'Precios', 'url' => $precios],
                ],
            ],
            'link-building' => [
                'kicker' => 'Plataforma, no un paquete cerrado',
                'h1' => 'Link building en España',
                'subtitle' => 'Linkbuilding self-service: elija publicaciones españolas, pague en euros, siga las URL en vivo. Sin ranking garantizado y sin paquetes misteriosos.',
                'meta_title' => 'Link building en España | SEOLinkBuildings',
                'meta_description' => 'Linkbuilding en España: catálogo self-service, DA/DR y precios en EUR, publicaciones con seguimiento. No es un paquete opaco de enlaces.',
                'teaser_countries' => ['es'],
                'teaser_title' => 'Inventario para campañas de linkbuilding',
                'teaser_subtitle' => 'Ejemplo de sitios España. Para fintech u otros nichos, filtre tras el login — no hay doorway por vertical ni por ciudad.',
                'intro' => [
                    '«Link building España», «paquetes linkbuilding» y «agencia linkbuilding España» suelen ser páginas de agencia. SEOLinkBuildings es una plataforma: arma la campaña desde el catálogo, con un monedero EUR para ES y el resto de Europa.',
                    'White label o facturas para varias marcas: <a href="'.$agencies.'">linkbuilding para agencias</a>. Comparar marketplaces: <a href="'.$alts.'">alternativas</a>.',
                ],
                'points' => [
                    [
                        'title' => 'Self-service, no un PDF',
                        'body' => 'Ve sitio, precio de checkout y métricas antes de pagar. No compra un «paquete de 20 DR50» sin nombres.',
                    ],
                    [
                        'title' => 'Coste del linkbuilding',
                        'body' => 'El coste es la suma de las publicaciones elegidas más paquetes de PR digital gestionados en la tarifa. Detalle: <a href="'.$precios.'">precios de guest post</a>.',
                    ],
                    [
                        'title' => 'Guest post, PR, niche edit',
                        'body' => 'Linkbuilding manual aquí significa: usted elige publishers. Guest posts y artículos patrocinados son pedidos de catálogo. Niche edits no son un SKU — explicación: <a href="'.$niche.'">niche edits</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Estrategia en una plataforma',
                        'body' => 'Compare agencias y otros marketplaces con hechos comprobables: monedero EUR, URL en el pedido, catálogo filtrable, sociedad UK. No afirmamos ser «la mejor agencia SEO de Madrid».',
                    ],
                    [
                        'h2' => 'Montar la campaña',
                        'body' => 'Fije URLs destino, filtre sitios españoles, varíe el ancla, mantenga un ritmo sostenible. Compra: <a href="'.$links.'">comprar backlinks</a>. Marque las colocaciones de pago.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => '¿Vendéis paquetes de linkbuilding?',
                        'a' => 'No como SKU opaco. Compre publicaciones sueltas. Los paquetes numerados en Precios son campañas de PR digital gestionadas, no un saco de URLs anónimas.',
                    ],
                    [
                        'q' => '¿Es linkbuilding white-hat?',
                        'a' => 'El catálogo es inventario editorial con reglas. Google trata los enlaces comprados para manipular rankings como spam si no están cualificados.',
                    ],
                    [
                        'q' => '¿Necesito una agencia en Madrid?',
                        'a' => 'No para usar el catálogo. Sí si alguien debe elegir los sitios por usted — o usa la cuenta de agencia y sigue decidiendo.',
                    ],
                ],
                'cta_primary' => ['label' => 'Empezar con el catálogo', 'url' => $register],
                'cta_secondary' => ['label' => 'Precios', 'url' => $precios],
                'see_also' => [
                    ['label' => 'Comprar guest post', 'url' => $guest],
                    ['label' => 'Para agencias', 'url' => $agencies],
                    ['label' => 'Alternativas', 'url' => $alts],
                ],
            ],
            'comprar-backlinks' => [
                'kicker' => 'Backlinks editoriales',
                'h1' => 'Comprar backlinks en España',
                'subtitle' => 'Backlinks españoles y enlaces SEO desde el catálogo: precios en euro, DA/DR, atributo declarado. Sin PBN y sin ranking garantizado.',
                'meta_title' => 'Comprar backlinks en España | SEOLinkBuildings',
                'meta_description' => 'Comprar backlinks en España en publishers verificados. Compare dofollow, nicho y precio EUR; siga la URL en vivo — sin garantía de ranking.',
                'teaser_countries' => ['es'],
                'teaser_title' => 'Sitios para backlinks en España (vista previa)',
                'teaser_subtitle' => 'Los hosts siguen enmascarados hasta que tenga cuenta. «Backlinks de calidad» aquí significa filtrable, no un ranking objetivo.',
                'intro' => [
                    '«Comprar backlinks», «backlinks España» y «enlaces dofollow» son la misma pregunta comercial. En SEOLinkBuildings el backlink nace de una publicación en el sitio del publisher, no de una red de dominios caducados.',
                    'Google trata los enlaces comprados para manipular rankings como spam de enlaces si no están cualificados. Trate las publicaciones de pago como sponsored/nofollow cuando el sitio lo exija.',
                ],
                'points' => [
                    [
                        'title' => 'Enlace en el contenido, no en el footer',
                        'body' => 'El briefing pide el enlace en el cuerpo del artículo. No vendemos sitewide ni directorios SEO.',
                    ],
                    [
                        'title' => 'Dofollow solo según el listing',
                        'body' => 'Filtre el tipo de enlace tras el login. «Comprar enlaces dofollow» no sustituye nicho, idioma y audiencia.',
                    ],
                    [
                        'title' => 'Sin SKU de niche edit',
                        'body' => 'No insertamos un enlace en un artículo ajeno que ya posiciona, salvo que el publisher ofrezca un extra de homepage en el listing. Explicación: <a href="'.$niche.'">niche edits</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Backlinks temáticos y con tráfico',
                        'body' => 'Filtre país España. La vista pública enmascara dominios. Catálogo: <a href="'.$mercado.'">catálogo de medios</a>. Enlaces permanentes son los que declara el publisher, no una garantía de por vida.',
                    ],
                    [
                        'h2' => 'Calidad, no promesas',
                        'body' => 'Temática, contexto editorial, calidad del publisher, audiencia, contenido, colocación, anclas transparentes y el perfil global — factores que puede comprobar. Un solo backlink no «mejora rankings» solo.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => '¿Puedo comprar solo dofollow?',
                        'a' => 'Puede filtrar ofertas que lo declaren. El publisher sigue siendo responsable del HTML en vivo.',
                    ],
                    [
                        'q' => '¿Hacéis inserciones en artículos existentes?',
                        'a' => 'No como SKU de niche edit. Algunos sitios venden un extra de homepage con plazo.',
                    ],
                    [
                        'q' => '¿Cuánto cuestan los backlinks en España?',
                        'a' => 'Depende del sitio. La página de precios explica el modelo (por publicación, en EUR); el catálogo, los precios en vivo.',
                    ],
                ],
                'cta_primary' => ['label' => 'Comparar sitios en el catálogo', 'url' => $register],
                'cta_secondary' => ['label' => 'Link building España', 'url' => $lb],
                'see_also' => [
                    ['label' => 'Precios', 'url' => $precios],
                    ['label' => 'Comprar guest post', 'url' => $guest],
                    ['label' => 'Niche edits', 'url' => $niche],
                ],
            ],
            'agencias' => [
                'kicker' => 'Cuenta B2B',
                'h1' => 'Linkbuilding para agencias en España',
                'subtitle' => 'Catálogo self-service para agencias SEO, resellers y equipos que refacturan. Monedero EUR, pedidos con seguimiento, facturas donde Billing las emite.',
                'meta_title' => 'Linkbuilding para agencias en España | SEOLinkBuildings',
                'meta_description' => 'White label y guest posts para agencias en España: catálogo EUR, facturas, pedidos por marca — sin un frontend reseller con su logo.',
                'teaser_countries' => ['es'],
                'teaser_title' => 'Inventario que puede refacturar',
                'teaser_subtitle' => 'Los mismos listings que un anunciante interno. Usted es la cuenta; las marcas viven en sus proyectos y pedidos.',
                'intro' => [
                    '«Agencia linkbuilding España», «guest post para agencias» y «proveedor linkbuilding agencias» buscan un proveedor que ejecute. Aquí la agencia sigue al mando: elige sitios, paga, entrega la URL en vivo al cliente.',
                    'White label operativo significa: el cliente final no tiene que crear cuenta en el marketplace. No es un programa reseller con su logo en el sitio público.',
                ],
                'points' => [
                    [
                        'title' => 'Un monedero, varias campañas',
                        'body' => 'Recarga en euros (tarjeta o transferencia, si está activo) y reparte el saldo entre pedidos.',
                    ],
                    [
                        'title' => 'Pedido y factura',
                        'body' => 'Las facturas de cargos de monedero/pedido se descargan en Billing del anunciante cuando el producto las genera. Datos de empresa UK (Topurlz Ltd) en Quiénes somos — no hay entidad legal española inventada.',
                    ],
                    [
                        'title' => 'Plataforma para equipos SEO',
                        'body' => 'Filtros, métricas, chat del pedido y URL en vivo. El panel tras el login está en inglés para todos los roles.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Agencia vs marketplace',
                        'body' => 'Una agencia elige los sitios para el cliente. Un marketplace muestra los sitios al comprador. SEOLinkBuildings es lo segundo. Si usted es la agencia, pone lo primero encima de lo segundo.',
                    ],
                    [
                        'h2' => 'Alta',
                        'body' => 'Cree cuenta de anunciante, recargue, filtre España, pida. Soporte: Contacto. Flujo: <a href="'.$how.'">cómo funciona</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => '¿Puedo ocultar el marketplace al cliente?',
                        'a' => 'Sí, operando la cuenta usted. No entregamos un portal white-label con su marca.',
                    ],
                    [
                        'q' => '¿Emitís facturas con IVA español?',
                        'a' => 'La facturación sigue a la sociedad británica del producto. Descargue los justificantes y aclare con su contabilidad si necesita más integraciones. No hay CIF español inventado.',
                    ],
                    [
                        'q' => '¿Hay una tarifa de agencia aparte?',
                        'a' => 'El precio de checkout es el del listing. No hay un segundo «catálogo agencia».',
                    ],
                ],
                'cta_primary' => ['label' => 'Crear cuenta de agencia', 'url' => $register],
                'cta_secondary' => ['label' => 'Catálogo de medios', 'url' => $mercado],
                'see_also' => [
                    ['label' => 'Link building', 'url' => $lb],
                    ['label' => 'Precios', 'url' => $precios],
                    ['label' => 'PR digital', 'url' => $pr],
                ],
            ],
            'digital-pr' => [
                'kicker' => 'PR en medios digitales',
                'h1' => 'PR digital en España: publicaciones y menciones',
                'subtitle' => 'Campañas de digital PR como publicaciones en sitios del marketplace, más paquetes gestionados en precios. Sin promesa de Google News ni notas de prensa inventadas.',
                'meta_title' => 'PR digital en España | SEOLinkBuildings',
                'meta_description' => 'Digital PR y PR digital en España: publicaciones de catálogo, monedero EUR, URL en vivo, paquetes gestionados — sin garantías de News ni teletipo.',
                'teaser_countries' => ['es'],
                'teaser_title' => 'Sitios España en el catálogo (vista previa)',
                'teaser_subtitle' => 'Algunos publishers se parecen a un media kit; no todos son un diario. Nicho e idioma se filtran tras el login.',
                'intro' => [
                    '«Digital PR España», «PR digital» y «comprar nota de prensa» mezclan PR y linkbuilding. Aquí compra publicaciones en sitios que están de verdad en el catálogo. Si un dominio no está listado, no lo vendemos.',
                    'Los paquetes gestionados de PR digital (importes en Precios, hoy desde 499 €/mes en el plan base si sigue listado) son outreach del equipo, no un botón «salga en un diario nacional».',
                ],
                'points' => [
                    [
                        'title' => 'Medios solo si están en el catálogo',
                        'body' => 'No tenemos un canal de Google News ni un cupo de teletipo. Un guest post «News» existe solo si ese sitio es un listing y acepta el briefing.',
                    ],
                    [
                        'title' => 'Menciones de marca',
                        'body' => 'Una mención puede nacer de una publicación. No vendemos «comprar brand mention» como SKU sin URL.',
                    ],
                    [
                        'title' => 'Campañas, no un comunicado a ciegas',
                        'body' => 'Self-service: elija sitios. Gestionado: ventas a través de los paquetes en Precios.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'PR y SEO, sin inflar',
                        'body' => 'Una publicación útil tiene lectores, contexto y un enlace (o una mención) que tiene sentido. No sustituye una noticia real. Catálogo: <a href="'.$mercado.'">lista de medios</a>. Paquetes: <a href="'.$precios.'">precios</a>.',
                    ],
                    [
                        'h2' => 'Guest post vs PR digital',
                        'body' => 'El guest post es un artículo en el sitio anfitrión. La PR digital apunta a una historia que un editor querría por sí solo. En el marketplace igual paga la publicación: trátela como sponsored si hay contraprestación. <a href="'.$sponsored.'">Artículo patrocinado</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => '¿Publicáis en Google News o en un teletipo?',
                        'a' => 'No como producto. Si un sitio del catálogo está en News o en un servicio de notas, depende del publisher, no de nosotros.',
                    ],
                    [
                        'q' => '¿Puedo comprar solo una mención sin artículo?',
                        'a' => 'Solo si un listing lo ofrece. El default es un artículo con enlace en el cuerpo.',
                    ],
                    [
                        'q' => '¿Cuánto cuesta una campaña?',
                        'a' => 'Self-service: suma de listings. Gestionado: los paquetes en Precios (importes en EUR de la tarifa actual).',
                    ],
                ],
                'cta_primary' => ['label' => 'Ver paquetes y catálogo', 'url' => $precios],
                'cta_secondary' => ['label' => 'Registrarse', 'url' => $register],
                'see_also' => [
                    ['label' => 'Artículo patrocinado', 'url' => $sponsored],
                    ['label' => 'Para agencias', 'url' => $agencies],
                    ['label' => 'Comprar guest post', 'url' => $guest],
                ],
            ],
            'niche-edits' => [
                'kicker' => 'No es un producto aparte',
                'h1' => 'Niche edits en España — y qué vendemos en su lugar',
                'subtitle' => 'Los niche edits (inserción de enlace en artículos existentes) no son un SKU en SEOLinkBuildings. Aquí la frontera con guest posts, extras de homepage y los riesgos.',
                'meta_title' => 'Niche edits en España, explicados | SEOLinkBuildings',
                'meta_description' => 'Qué son niche edits e inserciones de enlace, cuándo son arriesgados, y por qué en España vendemos publicaciones editoriales — no un insert en rankings ajenos.',
                'teaser_countries' => ['es'],
                'teaser_title' => 'Sitios editoriales, no redes de inserts',
                'teaser_subtitle' => 'Vista previa de listings España activos. El producto estándar es un artículo nuevo con enlace en el cuerpo, no un insert silencioso.',
                'intro' => [
                    'Un niche edit es un enlace metido en un artículo ya publicado — a menudo porque la URL ya está indexada. «Inserción de enlace» y «insertar enlace en artículo» buscan exactamente eso.',
                    'SEOLinkBuildings no lo vende como producto. El pedido estándar es una publicación nueva (guest post o artículo patrocinado) con briefing y URL en vivo. Algunos publishers ofrecen un extra de homepage con plazo; está visible en el listing, no es un insert silencioso en un artículo que ya posiciona.',
                ],
                'points' => [
                    [
                        'title' => 'Compruebe la relevancia',
                        'body' => 'Un insert en contenido antiguo de otro tema suele ser peor que un artículo nuevo en un sitio español adecuado.',
                    ],
                    [
                        'title' => 'Riesgos',
                        'body' => 'Titularidad poco clara, anclas cambiadas a posteriori, falta de disclosure, redes de inserts con el mismo patrón de salida.',
                    ],
                    [
                        'title' => 'Qué compra aquí',
                        'body' => 'Una publicación con reglas, checkout EUR y URL en vivo. <a href="'.$guest.'">Comprar guest post</a> o <a href="'.$links.'">comprar backlinks</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Cuándo tendría sentido un niche edit',
                        'body' => 'Solo si el artículo existente encaja temáticamente, el publisher responde editorialmente del insert y el enlace sigue siendo transparente. No lo orquestamos como SKU masivo.',
                    ],
                    [
                        'h2' => 'El extra de homepage es otra cosa',
                        'body' => 'Si el listing ofrece homepage, es un extra visible y a menudo temporal — no es lo mismo que un enlace en un artículo de nicho ya publicado.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => '¿Puedo pedir un enlace en un artículo existente?',
                        'a' => 'Solo si un listing concreto lo ofrece. El default es un artículo nuevo.',
                    ],
                    [
                        'q' => '¿Por qué no hay una landing de venta de niche edits?',
                        'a' => 'Porque no tenemos ese producto. Una landing de venta sería engañosa.',
                    ],
                    [
                        'q' => '¿Cuál es la alternativa?',
                        'a' => 'Guest posts o artículos patrocinados temáticos del catálogo España, con briefing y URL en vivo.',
                    ],
                ],
                'cta_primary' => ['label' => 'Elegir guest posts en el catálogo', 'url' => $register],
                'cta_secondary' => ['label' => 'Comprar backlinks', 'url' => $links],
                'see_also' => [
                    ['label' => 'Link building', 'url' => $lb],
                    ['label' => 'Comprar guest post', 'url' => $guest],
                    ['label' => 'Artículo patrocinado', 'url' => $sponsored],
                ],
            ],
            'alternativas' => [
                'kicker' => 'Comparar plataformas',
                'h1' => 'Alternativas a marketplaces de guest post en España',
                'subtitle' => 'Compare SEOLinkBuildings con Publisuites, Getlinko, Growwer, Prensalink y otros por hechos comprobables: monedero EUR, URL en el pedido, catálogo filtrable. Sin afirmaciones no soportadas.',
                'meta_title' => 'Alternativas guest post en España | SEOLinkBuildings',
                'meta_description' => 'Publisuites alternativa, Getlinko, Growwer, Unancor: compare con SEOLinkBuildings por monedero EUR, URL en vivo y catálogo — sin claims inventados.',
                'teaser_countries' => ['es'],
                'teaser_title' => 'El mismo catálogo España',
                'teaser_subtitle' => 'No clonamos el inventario de un competidor. Tras el login ve listings propios con precio de checkout en euro.',
                'intro' => [
                    'Las búsquedas «Publisuites alternativa», «Getlinko alternativa» o «mejores plataformas linkbuilding» quieren un comparador. Esta página no afirma ser «mejor» en métricas que no publicamos. Enumera lo que sí puede comprobar en SEOLinkBuildings.',
                    'Sede: Londres, Topurlz Ltd. Catálogo europeo, checkout en EUR. No inventamos una sede española, un CIF ni un monedero en otra divisa.',
                ],
                'points' => [
                    [
                        'title' => 'Qué puede verificar',
                        'body' => 'Precio de anunciante por sitio, monedero EUR, URL en vivo en el pedido, filtros de país/idioma/nicho, sociedad UK en Quiénes somos.',
                    ],
                    [
                        'title' => 'Qué no afirmamos',
                        'body' => 'No publicamos recuentos inventados de medios, ni «más barato que X», ni rankings de competidores sin fuente.',
                    ],
                    [
                        'title' => 'Conquest, no doorway',
                        'body' => 'Una página de alternativas. No hay /es/publisuites ni landings por cada marca. WhitePress, Enlazator, Linkatomic, PrensaRank y Unancor caben aquí como H2, no como URLs extra.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Publisuites, Getlinko, Growwer y el resto',
                        'body' => 'Son marketplaces o agencias del mercado .es. Si ya busca su marca más «alternativa», aterrice aquí y compare el flujo (registro, monedero, pedido). El catálogo: <a href="'.$mercado.'">/es/mercado</a>.',
                    ],
                    [
                        'h2' => 'Después de comparar',
                        'body' => 'Compre guest posts: <a href="'.$guest.'">/es/comprar-guest-post</a>. Precios: <a href="'.$precios.'">/es/precios</a>. Agencias: <a href="'.$agencies.'">/es/agencias</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => '¿Sois más baratos que Publisuites?',
                        'a' => 'No lo afirmamos. El precio es el del listing en EUR. Compárelo usted en el catálogo tras registrarse.',
                    ],
                    [
                        'q' => '¿Tenéis los mismos medios?',
                        'a' => 'El inventario es el nuestro. No copiamos ni garantizamos el catálogo de un tercero.',
                    ],
                    [
                        'q' => '¿Hay una página por cada competidor?',
                        'a' => 'No. Una sola landing de alternativas evita doorways débiles y canibalización.',
                    ],
                ],
                'cta_primary' => ['label' => 'Ver el catálogo tras registrarse', 'url' => $register],
                'cta_secondary' => ['label' => 'Comprar guest post', 'url' => $guest],
                'see_also' => [
                    ['label' => 'Catálogo de medios', 'url' => $mercado],
                    ['label' => 'Link building', 'url' => $lb],
                    ['label' => 'Precios', 'url' => $precios],
                ],
            ],
        ];
    }
}
