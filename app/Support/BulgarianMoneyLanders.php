<?php

namespace App\Support;

/**
 * Bulgaria money / B2B landers (Bulgarian as used by SEO teams, plus genuine loanwords).
 * Marketplace stays /bg/pazar and pricing stays /bg/ceni.
 */
class BulgarianMoneyLanders
{
    use MoneyLanderPages;

    public const LOCALE = 'bg';

    /**
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return [
            'marketplace' => '/bg/pazar',
            'katalog-publishers' => '/bg/pazar',
            'guest-post-bulgaria' => '/bg/kupi-guest-post',
            'gost-post-blogove' => '/bg/kupi-guest-post',
            'kupi-gost-post' => '/bg/kupi-guest-post',
            'advertorial' => '/bg/sponsorirana-statiya',
            'platena-statiya' => '/bg/sponsorirana-statiya',
            'bg-backlinks' => '/bg/kupi-backlinks',
            'kupi-linkove' => '/bg/kupi-backlinks',
            'kolko-struva' => '/bg/ceni',
            'linkbuilding-tseni' => '/bg/ceni',
            'white-label-linkbuilding' => '/bg/agencii',
            'pressobshenie' => '/bg/digital-pr',
            'vmakni-link' => '/bg/niche-edits',
            'guide' => '/bg/rukovodstvo',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function ui(): array
    {
        return [
            'cluster_title' => 'Страници за гост пост, backlinks и linkbuilding в България',
            'from' => 'От',
            'price_note' => 'Най-ниската текуща цена в евро по активни, проверени каталожни правила с основна държава България. Не е фиксирана ценова листа.',
            'sites_preview' => 'Сайтове в предварителен преглед',
            'count_note' => 'Активни, проверени publishers с България като основна държава, когато броят е наличен.',
            'th_site' => 'Сайт',
            'th_country' => 'Държава',
            'th_language' => 'Език',
            'th_from' => 'От',
            'teaser_foot' => 'Показваме DA, DR и цената в евро, когато стоят на каталожното правило. Липсващите метрики остават празни.',
            'see_also' => 'Вижте също',
        ];
    }

    /**
     * @return list<array{slug: string, label: string, url: string}>
     */
    public static function clusterLinks(?string $current = null): array
    {
        return self::filterCluster([
            ['slug' => 'home', 'label' => 'Пазар България', 'url' => url('/bg')],
            ['slug' => 'kupi-guest-post', 'label' => 'Купи guest post', 'url' => self::url('kupi-guest-post')],
            ['slug' => 'sponsorirana-statiya', 'label' => 'Спонсорирана статия', 'url' => self::url('sponsorirana-statiya')],
            ['slug' => 'pazar', 'label' => 'Каталог publishers', 'url' => self::marketingUrl('marketplace')],
            ['slug' => 'linkbuilding', 'label' => 'Linkbuilding', 'url' => self::url('linkbuilding')],
            ['slug' => 'kupi-backlinks', 'label' => 'Купи backlinks', 'url' => self::url('kupi-backlinks')],
            ['slug' => 'ceni', 'label' => 'Цени', 'url' => self::marketingUrl('pricing')],
            ['slug' => 'agencii', 'label' => 'За агенции', 'url' => self::url('agencii')],
            ['slug' => 'digital-pr', 'label' => 'Digital PR', 'url' => self::url('digital-pr')],
            ['slug' => 'niche-edits', 'label' => 'Niche edits', 'url' => self::url('niche-edits')],
            ['slug' => 'rukovodstvo', 'label' => 'Ръководство', 'url' => self::url('rukovodstvo')],
        ], $current);
    }

    /**
     * @return list<array{slug: string, label: string, url: string}>
     */
    public static function footerLinks(): array
    {
        return array_values(array_filter(
            self::clusterLinks('home'),
            static fn (array $item) => ! in_array($item['slug'], ['pazar', 'ceni'], true)
        ));
    }

    /**
     * @return array{h2: string, body: string, links: string}
     */
    public static function marketplaceCopy(): array
    {
        return [
            'h2' => 'Каталог на български publishers',
            'body' => 'Това е публичният списък на publishers с България като основна държава: ниша, език, DA/DR и цена в евро. Не индексираме всяка комбинация от филтри, а градовете (София и други) нямат собствен URL. Пълният каталог с домейни се отваря след регистрация.',
            'links' => '<a href="'.self::url('kupi-guest-post').'">Купи guest post в България</a> · <a href="'.self::url('kupi-backlinks').'">Купи backlinks</a> · <a href="'.self::marketingUrl('pricing').'">Колко струва гост пост</a> · <a href="'.url('/guest-posts-bulgaria').'">Bulgaria inventory (English)</a>',
        ];
    }

    /**
     * @return array{h2: string, body: string}
     */
    public static function pricingCopy(): array
    {
        return [
            'h2' => 'Колко струва гост пост в България',
            'body' => 'Не публикуваме фиксирана PDF-ценова листа и не измисляме български ЕИК: цената е на сайта, в евро. «Колко струва гост пост», «цени за linkbuilding» и «цена на backlinks» следват каталожните правила, които вие избирате. Номерираните пакети по-горе са управлявани digital-PR кампании, не чувал с анонимни URL. Текущите суми стоят в <a href="'.self::marketingUrl('marketplace').'">българския каталог</a> след регистрация. Централата е в Лондон (Topurlz Ltd).',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function pages(): array
    {
        $market = self::marketingUrl('marketplace');
        $prices = self::marketingUrl('pricing');
        $how = self::marketingUrl('how-it-works');
        $register = '/register';
        $guest = self::url('kupi-guest-post');
        $sponsored = self::url('sponsorirana-statiya');
        $links = self::url('kupi-backlinks');
        $lb = self::url('linkbuilding');
        $agencies = self::url('agencii');
        $pr = self::url('digital-pr');
        $niche = self::url('niche-edits');
        $guide = self::url('rukovodstvo');
        $publisher = self::marketingUrl('become-a-publisher');

        return [
            'kupi-guest-post' => [
                'kicker' => 'Гост пост с backlink',
                'h1' => 'Гост пост в български блогове',
                'subtitle' => 'Изберете проверени български и европейски publishers, сравнете ниша, DA/DR и цената в евро, изпратете брифинга и следете live-URL в поръчката.',
                'meta_title' => 'Гост пост в български блогове | SEOLinkBuildings',
                'meta_description' => 'Купете гост пост в български блогове и .bg сайтове: филтър ниша, DA/DR и EUR-цена, dofollow или sponsored и live-URL в поръчката.',
                'teaser_countries' => ['bg'],
                'teaser_title' => 'Сайтове за гост пост в България',
                'teaser_subtitle' => 'Маскиран предварителен преглед на активни каталожни правила в България. Домейните виждате след регистрация.',
                'intro' => [
                    'SEOLinkBuildings е self-service marketplace, не непрозрачен пакет от гост постове. Вие избирате сайта — често .bg, често на български — плащате в евро от салдото и държите брифинга, чата и live-URL в същата поръчка. Централата е в Лондон (Topurlz Ltd); не измисляме ЕИК в България.',
                    '«Купи guest post», «гост пост в български блогове» и «купи гост пост» са едно и също намерение: платена публикация на сайт, който вие не притежавате, с писмени правила за дължина, линкове и доставка.',
                ],
                'points' => [
                    [
                        'title' => 'Publishers, не фантомен списък',
                        'body' => 'Всеки ред е сайт с ниша, език, държава, DA/DR, деклариран трафик и цена. Не продаваме PBN и не продаваме «пакети с 50 линка».',
                    ],
                    [
                        'title' => 'Как да поръчате',
                        'body' => 'Вие създавате акаунт, филтрирате България, слагате сайта в количката и изпращате заглавие, текст или брифинг плюс анкор. Publisher-ът доставя live-URL за одобрение.',
                    ],
                    [
                        'title' => 'Dofollow и sponsored',
                        'body' => 'Атрибутът на линка стои на каталожното правило. Много медии маркират платените публикации. Прочетете типа линк преди да поръчате — няма «dofollow на всяка цена».',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Трафик, ниша и постоянни линкове',
                        'body' => 'Гост пост «с трафик» означава, че каталожното правило показва трафика, който publisher-ът е декларирал — не гаранция за посещения. Нишите (здраве, финанси, tech, застраховане, имоти, travel, ecommerce) филтрирате в каталога, не на отделни URL. «Постоянно» зависи от правилата на сайта. Прочетете каталожното правило.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Мога ли да публикувам само на .bg сайтове?',
                        'a' => 'Да. Вие филтрирате държавата България. Европейският каталог е в същото EUR салдо. Румъния и Гърция са отделни филтри.',
                    ],
                    [
                        'q' => 'Пишете ли статията вие?',
                        'a' => 'Стандартната поръчка използва вашия брифинг. Някои каталожни правила предлагат редакция; това се вижда на сайта, не като измислена допълнителна услуга тук.',
                    ],
                    [
                        'q' => 'Има ли страници за София?',
                        'a' => 'Не. Градовете нямат собствен URL. Вие филтрирате държавата.',
                    ],
                ],
                'cta_primary' => ['label' => 'Създайте акаунт и вижте publishers', 'url' => $register],
                'cta_secondary' => ['label' => 'Каталог publishers', 'url' => $market],
                'see_also' => [
                    ['label' => 'Купи backlinks', 'url' => $links],
                    ['label' => 'Спонсорирана статия', 'url' => $sponsored],
                    ['label' => 'Цени', 'url' => $prices],
                    ['label' => 'Ръководство', 'url' => $guide],
                ],
            ],
            'sponsorirana-statiya' => [
                'kicker' => 'Advertorial',
                'h1' => 'Купете спонсорирана статия в България',
                'subtitle' => 'Купувате платена статия на сайт от каталога, с цена в евро и live-URL. Не продаваме абонамент за общи прессъобщения.',
                'meta_title' => 'Спонсорирана статия в България | SEOLinkBuildings',
                'meta_description' => 'Купете спонсорирана статия или advertorial на български сайтове. Цена в EUR по каталожно правило, брифинг и live-URL — без измислена пресагенция.',
                'teaser_countries' => ['bg'],
                'teaser_title' => 'Медии за гост пост и advertorial',
                'teaser_subtitle' => 'Същият предварителен преглед на publishers в България. Advertorial има само ако сайтът е в каталога.',
                'intro' => [
                    '«Купи спонсорирана статия», «платена статия SEO» и «advertorial» описват платена публикация, не преслиния, която нямаме. Ако домейнът не е в каталога, не го продаваме.',
                    'Native advertising за SEO тук е същият процес: вие избирате публикацията, плащате в EUR и получавате URL. Не обещаваме Google News.',
                ],
                'points' => [
                    [
                        'title' => 'Маркиране като спонсорирано',
                        'body' => 'Много сайтове изискват rel sponsored или видима маркировка. Следвайте правилото на каталожния ред.',
                    ],
                    [
                        'title' => 'Цената',
                        'body' => 'Колко струва спонсорирана статия зависи от сайта. Вижте <a href="'.$prices.'">цените</a> за модела и каталога за текущите суми.',
                    ],
                    [
                        'title' => 'Съдържание',
                        'body' => 'Вие изпращате текста или брифинга. Publisher-ът публикува на собствения си сайт и изпраща live-URL.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Advertorial срещу гост пост',
                        'body' => 'На практика и двете са платена публикация с линк. Разликата е редакционна. Разплащането е същото. <a href="'.$guest.'">Купи guest post</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Има ли една тарифа за advertorial?',
                        'a' => 'Не. Всяко каталожно правило има своя цена, в евро.',
                    ],
                    [
                        'q' => 'Публикувате ли във всички вестници?',
                        'a' => 'Само на сайтове, които са в каталога и приемат поръчката.',
                    ],
                ],
                'cta_primary' => ['label' => 'Вижте български сайтове', 'url' => $register],
                'cta_secondary' => ['label' => 'Digital PR', 'url' => $pr],
                'see_also' => [
                    ['label' => 'Digital PR', 'url' => $pr],
                    ['label' => 'Цени', 'url' => $prices],
                    ['label' => 'Купи guest post', 'url' => $guest],
                ],
            ],
            'kupi-backlinks' => [
                'kicker' => 'Редакционни backlinks',
                'h1' => 'Купете backlinks в България',
                'subtitle' => 'Backlinks тук са линкове от публикации, които купувате на реални сайтове — не анонимен чувал с URL.',
                'meta_title' => 'Купи backlinks в България | SEOLinkBuildings',
                'meta_description' => 'Български backlinks от гост пост на реални сайтове. Цена в EUR, dofollow или sponsored на каталожното правило, live-URL в поръчката.',
                'teaser_countries' => ['bg'],
                'teaser_title' => 'Сайтове .bg за backlinks',
                'teaser_subtitle' => 'Предварителен преглед на каталожни правила с основна държава България. Показаният трафик е деклариран, не обещан.',
                'intro' => [
                    '«Купи backlinks», «купи backlink» и «купи линкове SEO» търсят едно и също: линк на публикувана страница. При нас го получавате чрез гост пост или спонсорирана статия, с анкора от брифинга.',
                    'Тематични backlinks означава, че вие избирате нишата на сайта. «С реален трафик» означава, че гледате трафика на каталожното правило — не гарантираме посещения.',
                ],
                'points' => [
                    [
                        'title' => 'Качество, което четете',
                        'body' => 'Държава, език, ниша, DA/DR и цена стоят на правилото. Не продаваме «качествени backlinks» като етикет без сайт.',
                    ],
                    [
                        'title' => 'Dofollow не е стандарт',
                        'body' => 'Филтрирайте по тип линк. Publisher-ът отговаря за живия HTML.',
                    ],
                    [
                        'title' => 'Без PBN',
                        'body' => 'Не продаваме частни мрежи. Рисковете са в <a href="'.$guide.'">ръководството</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Как да изберете',
                        'body' => 'Започнете в <a href="'.$market.'">каталога</a>, филтрирайте България, сравнете цена и правила за анкор. После <a href="'.$guest.'">купите guest post</a> или <a href="'.$sponsored.'">спонсорирана статия</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Мога ли да купя само dofollow?',
                        'a' => 'Можете да филтрирате оферти, които споменават dofollow. Проверете атрибута на живата страница.',
                    ],
                    [
                        'q' => 'Вмъквате ли линк в вече публикувана статия?',
                        'a' => 'Не като SKU за niche edits. Някои сайтове продават добавка за начална страница със срок.',
                    ],
                    [
                        'q' => 'Колко струват backlinks в България?',
                        'a' => 'Зависи от сайта. <a href="'.$prices.'">Цени</a> обясняват модела; каталогът показва текущите суми.',
                    ],
                ],
                'cta_primary' => ['label' => 'Сравнете сайтове', 'url' => $register],
                'cta_secondary' => ['label' => 'Linkbuilding', 'url' => $lb],
                'see_also' => [
                    ['label' => 'Цени', 'url' => $prices],
                    ['label' => 'Купи guest post', 'url' => $guest],
                    ['label' => 'Niche edits', 'url' => $niche],
                ],
            ],
            'linkbuilding' => [
                'kicker' => 'Кампании',
                'h1' => 'Linkbuilding в България',
                'subtitle' => 'Вие изграждате кампанията от каталога, публикация по публикация, или избирате управляван digital-PR пакет под цените. Няма «евтини backlinks» без сайт.',
                'meta_title' => 'Linkbuilding в България | SEOLinkBuildings',
                'meta_description' => 'Linkbuilding България: self-service каталог в EUR, проследени поръчки и digital-PR пакети. Без анонимни линкпакети.',
                'teaser_countries' => ['bg'],
                'teaser_title' => 'Избор за кампании в България',
                'teaser_subtitle' => 'Същите каталожни правила като на страницата за гост пост. Европа филтрирате след вход.',
                'intro' => [
                    'Linkbuilding тук означава, че вие избирате publishers, плащате и следите URL. Това не е абонамент, който «прави SEO» вместо вас.',
                    'Европейският linkbuilding използва същото салдо. България е филтър по държава, не отделен продукт. Агенция, която работи вътрешно, може да ползва същия каталог.',
                ],
                'points' => [
                    [
                        'title' => 'Self-service',
                        'body' => 'Стратегията е вашият избор на сайтове, анкори и темпо. Каталогът е източникът. Месечният linkbuilding е темпото, което вие задавате.',
                    ],
                    [
                        'title' => 'Пакети за linkbuilding',
                        'body' => 'Номерираните пакети под цените са управлявани digital-PR кампании, не чувал с URL.',
                    ],
                    [
                        'title' => 'White label',
                        'body' => 'Агенцията може да поръчва от собствен акаунт. Не доставяме портал с ваше лого. Подробности: <a href="'.$agencies.'">за агенции</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Гост пост, niche edits и digital PR',
                        'body' => 'Гост постът е нова статия. Niche edits (линк в вече публикувано съдържание) не продаваме като SKU. Digital PR е кампанията; в marketplace все още плащате публикацията.',
                    ],
                    [
                        'h2' => 'Как започва кампания',
                        'body' => 'Акаунт, салдо в EUR, филтър България, поръчка. Процесът: <a href="'.$how.'">как работи</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Имате ли евтин linkbuilding?',
                        'a' => 'Цената е на каталожното правило. Нямаме отделен «евтин» слой до каталога.',
                    ],
                    [
                        'q' => 'Правите ли и стратегията?',
                        'a' => 'Ръководството обяснява рискове и анкори. Изпълнението в self-service е ваше.',
                    ],
                ],
                'cta_primary' => ['label' => 'Отворете каталога', 'url' => $register],
                'cta_secondary' => ['label' => 'Вижте цените', 'url' => $prices],
                'see_also' => [
                    ['label' => 'За агенции', 'url' => $agencies],
                    ['label' => 'Digital PR', 'url' => $pr],
                    ['label' => 'Ръководство', 'url' => $guide],
                ],
            ],
            'agencii' => [
                'kicker' => 'B2B акаунт',
                'h1' => 'Linkbuilding за агенции в България',
                'subtitle' => 'Self-service каталог за SEO агенции, resellers и екипи, които префактурират. EUR салдо, проследени поръчки, фактури в рекламодателското фактуриране.',
                'meta_title' => 'Linkbuilding за агенции | SEOLinkBuildings',
                'meta_description' => 'Гост пост за агенции в България: каталог в EUR, фактури, поръчки по бранд — без reseller портал с ваше лого.',
                'teaser_countries' => ['bg'],
                'teaser_title' => 'Избор, който префактурирате',
                'teaser_subtitle' => 'Същите каталожни правила като за вътрешен рекламодател. Акаунтът е ваш; брандовете стоят в проекти и поръчки.',
                'intro' => [
                    '«Гост пост за агенции», «white label linkbuilding България» и «reseller backlinks» търсят партньор, който изпълнява. Тук агенцията държи кормилото: вие избирате сайтове, плащате и предавате live-URL на клиента.',
                    'Оперативен white label означава, че крайният клиент не се нуждае от акаунт. Това не е reseller програма с вашия бранд на публичната страница.',
                ],
                'points' => [
                    [
                        'title' => 'Едно салдо, няколко кампании',
                        'body' => 'Слагате евро (карта или превод, когато методът е активен) и разпределяте салдото по поръчки.',
                    ],
                    [
                        'title' => 'Поръчка и фактура',
                        'body' => 'Фактури за вноски или поръчки сваляте от рекламодателското фактуриране, когато продуктът ги издава. Фирмените данни са британски (Topurlz Ltd). Не измисляме български ЕИК.',
                    ],
                    [
                        'title' => 'Работна среда за SEO екипи',
                        'body' => 'Филтри, метрики, чат по поръчката и live-URL. След вход таблото (dashboard) остава на английски за всички роли.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Агенция срещу marketplace',
                        'body' => 'Агенцията избира сайтове за клиента. Marketplace показва сайтове на купувача. SEOLinkBuildings е второто. Ако вашият екип е агенцията, изборът остава при вас, а каталогът е източникът.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Можем ли да скрием marketplace от клиента?',
                        'a' => 'Да, като работите от вашия акаунт. Не доставяме white-label портал с вашия бранд.',
                    ],
                    [
                        'q' => 'Издавате ли фактури с български ДДС номер?',
                        'a' => 'Фактурирането следва британското дружество. Свалете документите и уточнете със счетоводството. Не измисляме ЕИК или ДДС номер.',
                    ],
                ],
                'cta_primary' => ['label' => 'Създайте агенционен акаунт', 'url' => $register],
                'cta_secondary' => ['label' => 'Каталог', 'url' => $market],
                'see_also' => [
                    ['label' => 'Linkbuilding', 'url' => $lb],
                    ['label' => 'Цени', 'url' => $prices],
                    ['label' => 'Digital PR', 'url' => $pr],
                ],
            ],
            'digital-pr' => [
                'kicker' => 'PR в дигитални медии',
                'h1' => 'Digital PR в България',
                'subtitle' => 'Digital-PR кампании като публикации на сайтове в marketplace, плюс управлявани пакети под цените. Без обещание за Google News.',
                'meta_title' => 'Digital PR в България | SEOLinkBuildings',
                'meta_description' => 'Digital PR България: публикации от каталога, EUR салдо, live-URL и управлявани пакети — без гаранция за News или преса.',
                'teaser_countries' => ['bg'],
                'teaser_title' => 'Български сайтове в каталога',
                'teaser_subtitle' => 'Някои publishers приличат на mediakit; не всички са всекидневник. Ниша и език филтрирате след вход.',
                'intro' => [
                    '«Digital PR България», «купи прессъобщение» и «прессъобщение SEO» смесват PR с linkbuilding. Тук купувате публикации на сайтове, които реално са в каталога.',
                    'Управляваните пакети (сумите стоят под Цени; днес от 499 €/месец на базовия план, ако все още се показва) са изпълнение от екип, не бутон «влез в национален вестник».',
                ],
                'points' => [
                    [
                        'title' => 'Медии само ако са в каталога',
                        'body' => 'Нямаме канал към Google News. Гост пост «в пресата» съществува само ако този сайт е каталожно правило и приема брифинга.',
                    ],
                    [
                        'title' => 'Brand mentions',
                        'body' => 'Споменаване може да дойде от публикация. Не продаваме «brand mention» като SKU без URL.',
                    ],
                    [
                        'title' => 'Кампании',
                        'body' => 'Self-service: вие избирате сайтовете. Управлявано: пакетите под цените.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'PR и SEO, без надуване',
                        'body' => 'Полезната публикация има читатели, контекст и линк (или споменаване), който има смисъл. Тя не замества новина. Каталог: <a href="'.$market.'">списъкът със сайтове</a>. Пакети: <a href="'.$prices.'">цени</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Гарантирате ли статия в пресата?',
                        'a' => 'Не. Доставяме URL на сайта, който сте поръчали, ако publisher-ът приеме.',
                    ],
                    [
                        'q' => 'Различно ли е от advertorial?',
                        'a' => 'Advertorial е публикацията. Digital PR е кампанията. В marketplace все още плащате публикацията. <a href="'.$sponsored.'">Спонсорирана статия</a>.',
                    ],
                ],
                'cta_primary' => ['label' => 'Вижте пакети и каталог', 'url' => $prices],
                'cta_secondary' => ['label' => 'Регистрация', 'url' => $register],
                'see_also' => [
                    ['label' => 'Спонсорирана статия', 'url' => $sponsored],
                    ['label' => 'За агенции', 'url' => $agencies],
                    ['label' => 'Купи guest post', 'url' => $guest],
                ],
            ],
            'niche-edits' => [
                'kicker' => 'Не е отделен продукт',
                'h1' => 'Niche edits в България — и какво продаваме',
                'subtitle' => 'Niche edits (вмъкни линк в вече публикувана статия) не са SKU в SEOLinkBuildings. Тук е границата спрямо гост пост, плюс рисковете.',
                'meta_title' => 'Niche edits в България — обяснение | SEOLinkBuildings',
                'meta_description' => 'Какво са niche edits и «вмъкни линк в статия», кога е рисковано и защо в България продаваме редакционни публикации — не вмъкване в чужда статия.',
                'teaser_countries' => ['bg'],
                'teaser_title' => 'Редакционни сайтове, не мрежа за вмъкване',
                'teaser_subtitle' => 'Предварителен преглед на активни каталожни правила в България. Стандартният продукт е нова статия с линк в текста.',
                'intro' => [
                    'Niche edit е линк в вече публикувана статия, често защото URL вече е индексиран. «Вмъкни линк в статия» търси точно това.',
                    'Не го продаваме като продукт. Стандартната поръчка е нова публикация (гост пост или спонсорирана статия) с брифинг и live-URL. Някои сайтове предлагат добавка за начална страница със срок; това стои на каталожното правило.',
                ],
                'points' => [
                    [
                        'title' => 'Защо не го продаваме',
                        'body' => 'Линк в статия, която вие не сте писали, е по-труден за контрол и по-често в конфликт със собствените правила на сайта. Не искаме да обещаваме SKU, който не можем да доставяме еднакво.',
                    ],
                    [
                        'title' => 'Какво можете да купите вместо това',
                        'body' => '<a href="'.$guest.'">Гост пост</a> или <a href="'.$sponsored.'">спонсорирана статия</a> с анкора в новия текст.',
                    ],
                    [
                        'title' => 'Риск',
                        'body' => 'Вмъквания в стари статии могат да изчезнат, да сменят атрибут или да ударят нерелевантни анкори. Прочетете <a href="'.$guide.'">ръководството</a>.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Контекстуални backlinks',
                        'body' => 'Контекстен линк в нов гост пост все още е редакционен линк. Разликата е, че познавате брифинга и получавате live-URL в поръчката. <a href="'.$links.'">Купи backlinks</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Мога ли да помоля publisher-а да вмъкне в стара статия?',
                        'a' => 'Само ако каталожното правило го описва. Това не е нашият стандартен SKU.',
                    ],
                ],
                'cta_primary' => ['label' => 'Купете гост пост вместо това', 'url' => $guest],
                'cta_secondary' => ['label' => 'Каталог', 'url' => $market],
                'see_also' => [
                    ['label' => 'Linkbuilding', 'url' => $lb],
                    ['label' => 'Купи guest post', 'url' => $guest],
                    ['label' => 'Спонсорирана статия', 'url' => $sponsored],
                ],
            ],
            'rukovodstvo' => [
                'kicker' => 'Едно ръководство',
                'h1' => 'Ръководство: гост пост и linkbuilding',
                'subtitle' => 'Какво е гост пост, как се купуват backlinks, dofollow срещу nofollow, анкори, PBN и рискове — на една страница, не в тънки статии.',
                'meta_title' => 'Ръководство за гост пост | SEOLinkBuildings',
                'meta_description' => 'Кратко ръководство: какво е гост пост, как да купите backlinks, dofollow vs nofollow, анкори, rel sponsored и защо PBN не е наш продукт.',
                'teaser_countries' => ['bg'],
                'teaser_title' => 'От обяснение към каталог',
                'teaser_subtitle' => 'След ръководството реалните сайтове са в българския каталог, с цена на публикация.',
                'intro' => [
                    'Това ръководство покрива информационни търсения (какво е linkbuilding, как се купуват backlinks, законни ли са backlinks, анкор текст) без нова страница за всяко изречение.',
                    'Таблото след вход остава на английски. Публичната страница е на български.',
                ],
                'points' => [
                    [
                        'title' => 'Какво е гост пост?',
                        'body' => 'Статия на чужд сайт, обикновено с линк към вас, срещу плащане или обмен. При нас плащането е в EUR, по поръчка.',
                    ],
                    [
                        'title' => 'Dofollow, nofollow, sponsored',
                        'body' => 'Dofollow обикновено предава сигнал. Nofollow и sponsored казват, че линкът е маркиран. Google третира rel sponsored като платен линк. Изберете това, което каталожното правило посочва.',
                    ],
                    [
                        'title' => 'Анкори',
                        'body' => 'Точен анкор, повторен на много сайтове, е рискован модел. Варирайте формулировката и дръжте анкора релевантен към целевата страница.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Как се купуват backlinks',
                        'body' => 'Акаунт, салдо, филтър по държава и ниша, брифинг, одобрение на live-URL. Операции: <a href="'.$how.'">как работи</a>. Търговска страница: <a href="'.$links.'">купи backlinks</a>.',
                    ],
                    [
                        'h2' => 'PBN срещу гост пост',
                        'body' => 'PBN е мрежа, която управлявате, за да пращате линкове. Това не продаваме. Гост пост е публикация на сайт със собствени читатели. Ако не можете да назовете сайта, това не е този продукт.',
                    ],
                    [
                        'h2' => 'Рискове при покупка на backlinks',
                        'body' => 'Сайтове без реален трафик, агресивни анкори, линкове които изчезват, липсваща sponsored маркировка, надути метрики. Проверете каталожното правило и live-URL. Не обещаваме класирания.',
                    ],
                    [
                        'h2' => 'Стратегия, накратко',
                        'body' => 'Малко релевантни публикации бият обем линкове без контекст. За изпълнение: <a href="'.$lb.'">linkbuilding</a>, <a href="'.$guest.'">купи guest post</a>, <a href="'.$publisher.'">станете publisher</a> ако продавате място.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Това ли е linkbuilding гид 2026?',
                        'a' => 'Не. Това е продуктова страница, която обяснява текущите правила на marketplace, не календар на тенденции.',
                    ],
                    [
                        'q' => 'Къде виждам цените?',
                        'a' => 'Моделът е под <a href="'.$prices.'">цени</a>. Текущите суми са в каталога след регистрация.',
                    ],
                ],
                'cta_primary' => ['label' => 'Вижте избора', 'url' => $register],
                'cta_secondary' => ['label' => 'Купи guest post', 'url' => $guest],
                'see_also' => [
                    ['label' => 'Linkbuilding', 'url' => $lb],
                    ['label' => 'Купи backlinks', 'url' => $links],
                    ['label' => 'Цени', 'url' => $prices],
                ],
            ],
        ];
    }
}
