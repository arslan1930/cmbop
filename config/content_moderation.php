<?php

/**
 * Content Compliance & Moderation — gambling + adult URL/keyword policy for articles.
 * Categories/keywords can be extended from Admin without code deploys
 * (admin overrides are stored in content_moderation_settings).
 */
return [

    'enabled' => env('CONTENT_MODERATION_ENABLED', true),

    /** Reject when any restricted category confidence >= this (0–100) */
    'confidence_threshold' => (int) env('CONTENT_MODERATION_THRESHOLD', 70),

    /** Cache successful fetches / scans briefly (seconds) */
    'scan_cache_seconds' => 900,

    /** Require a recent approved scan before order can proceed */
    'require_approved_scan' => true,

    'quality' => [
        'min_word_count' => 500,
        'warn_word_count' => 300,
        'max_external_links' => 15,
        'block_placeholder_text' => true,
        'require_headings' => false,
        // Quality remains advisory — gambling/adult/language gates block approval.
        'block_on_quality_failure' => false,
    ],

    /*
    | Restricted categories. Each has weight, keywords, phrases, domains, synonyms.
    | keywords_by_locale are merged into keywords at scan time.
    */
    'categories' => [

        'gambling' => [
            'label' => 'Casino / Poker / Gambling / Betting',
            'enabled' => true,
            'weight' => 1.0,
            'keywords' => [
                'casino', 'casinos', 'gambling', 'gamble', 'bettor', 'betting', 'sportsbook',
                'sports betting', 'sport betting', 'poker', 'blackjack', 'roulette', 'baccarat',
                'slot machine', 'slot machines', 'online slots', 'slots', 'jackpot', 'bookmaker',
                'bookie', 'wager', 'wagering', 'lottery', 'lotto', 'scratch card',
                'crypto casino', 'crypto gambling', 'bitcoin casino', 'online casino',
                'live dealer', 'free spins', 'no deposit bonus', 'casino bonus',
                'gambling site', 'betting site', 'poker room', 'texas holdem', 'holdem',
                'sports bet', 'parlay', 'betting odds', 'casino games', 'gambling addiction',
                'place bets', 'bet now', 'odds boost', 'betting tips',
            ],
            'keywords_by_locale' => [
                'de' => [
                    'glücksspiel', 'glücksspiele', 'wettanbieter', 'sportwetten', 'wetten', 'wette',
                    'spielothek', 'spielbank', 'online casino', 'pokerspiel', 'spielautomat',
                    'spielautomaten', 'buchmacher', 'wettquote', 'wettquoten', 'roulette',
                ],
                'fr' => [
                    'jeux d\'argent', 'paris sportifs', 'pari sportif', 'parieur', 'maison de jeu',
                    'casino en ligne', 'poker en ligne', 'bookmaker', 'cotes de paris',
                    'machines à sous', 'roulette', 'blackjack',
                ],
                'nl' => [
                    'gokken', 'kansspel', 'kansspelen', 'sportweddenschappen', 'wedden', 'weddenschap',
                    'online casino', 'goksite', 'speelautomaat', 'bookmaker', 'wedden op sport',
                ],
                'sk' => [
                    'kasíno', 'kasina', 'hazard', 'hazardné hry', 'stávkovanie', 'stávky', 'stávka',
                    'stávková kancelária', 'poker', 'ruleta', 'automaty', 'kurzy stávok',
                ],
                'cs' => [
                    'kasino', 'hazard', 'hazardní hry', 'sázení', 'sázky', 'sázka', 'sázková kancelář',
                    'poker', 'ruleta', 'automaty', 'kurzové sázení',
                ],
                'pl' => [
                    'kasyno', 'hazard', 'gry hazardowe', 'zakłady bukmacherskie', 'bukmacher',
                    'typowanie', 'poker', 'ruletka', 'automaty do gier', 'zakłady sportowe',
                ],
                'es' => [
                    'casino', 'juego de azar', 'juegos de azar', 'apuestas', 'apuesta', 'apuestas deportivas',
                    'casa de apuestas', 'póker', 'ruleta', 'tragaperras', 'cuotas de apuestas',
                ],
                'it' => [
                    'casinò', 'giochi d\'azzardo', 'scommesse', 'scommessa', 'scommesse sportive',
                    'bookmaker', 'poker', 'roulette', 'slot machine', 'quote scommesse',
                ],
                'pt' => [
                    'cassino', 'jogos de azar', 'apostas', 'aposta', 'apostas desportivas',
                    'casa de apostas', 'pôquer', 'roleta', 'caça-níqueis', 'odds de apostas',
                ],
                'hu' => [
                    'kaszinó', 'szerencsejáték', 'fogadás', 'fogadások', 'sportfogadás',
                    'póker', 'rulett', 'nyerőgépek', 'fogadási odds',
                ],
                'ro' => [
                    'cazinou', 'jocuri de noroc', 'pariuri', 'pariu', 'pariuri sportive',
                    'casă de pariuri', 'poker', 'ruletă', 'păcănele', 'cote pariuri',
                ],
                'sv' => [
                    'kasino', 'hasardspel', 'vadslagning', 'sportbetting', 'odds',
                    'poker', 'roulette', 'spelautomat', 'spelbolag',
                ],
                'da' => [
                    'kasino', 'hasardspil', 'væddemål', 'sportsbetting', 'odds',
                    'poker', 'roulette', 'spilleautomat', 'spillefirma',
                ],
                'fi' => [
                    'kasino', 'uhkapeli', 'vedonlyönti', 'urheiluvedonlyönti',
                    'pokeri', 'ruletti', 'peliautomaatti', 'vedonlyöntitoimisto',
                ],
                'el' => [
                    'καζίνο', 'τζόγος', 'στοιχήματα', 'στοίχημα', 'αθλητικό στοίχημα',
                    'πόκερ', 'ρουλέτα', 'κουλοχέρηδες',
                ],
                'bg' => [
                    'казино', 'хазарт', 'залагания', 'залог', 'спортни залози',
                    'покер', 'рулетка', 'слот машини',
                ],
                'hr' => [
                    'kasino', 'kockanje', 'klađenje', 'kladionica', 'sportske oklade',
                    'poker', 'rulet', 'automati',
                ],
                'sl' => [
                    'kazino', 'igre na srečo', 'stave', 'stava', 'športne stave',
                    'poker', 'ruleta', 'igralni avtomati',
                ],
                'lt' => [
                    'kazino', 'azartiniai lošimai', 'lažybos', 'statymas',
                    'pokeris', 'ruletė', 'lošimo automatai',
                ],
                'lv' => [
                    'kazino', 'azartspēles', 'derības', 'likme',
                    'pokers', 'rulete', 'spēļu automāti',
                ],
                'et' => [
                    'kasiino', 'hasartmängud', 'panustamine', 'panused',
                    'pokker', 'rulett', 'mänguautomaadid',
                ],
                'zh' => [
                    '赌场', '赌博', '博彩', '投注', '体育博彩', '扑克', '轮盘', '老虎机', '赌场在线',
                ],
                'ar' => [
                    'كازينو', 'قمار', 'مراهنة', 'مراهنات', 'مراهنات رياضية', 'بوكر', 'روليت', 'آلات القمار',
                ],
            ],
            'domains' => [
                'bet365', 'draftkings', 'fanduel', 'williamhill', 'pokerstars', '888casino',
                'betway', 'unibet', 'casumo', 'leovegas', 'stake.com', 'roobet', 'rollbit',
                'bwin', 'pinnacle', 'betfair', 'ladbrokes', 'coral.co.uk', 'paddypower',
                'skybet', 'betsson', 'mrgreen', 'party poker', 'partypoker', 'ggpoker',
                '888poker', 'casino.com', 'jackpotcity', 'spin casino', 'spincasino',
                'royalvegas', 'videoslots', 'bitcasino', 'cloudbet', 'bc.game', 'stake.bet',
                '1xbet', 'mostbet', 'melbet', 'pin-up', 'pinup', 'vulkan', 'joycasino',
                'tipico', 'interwetten', 'bet-at-home', 'mybet', 'oddschecker',
            ],
            'intent_phrases' => [
                'place a bet', 'claim your bonus', 'sign up and play', 'deposit bonus',
                'best online casino', 'top betting sites', 'win real money', 'play slots',
                'online casino bonus', 'sports betting tips',
            ],
        ],

        'adult' => [
            'label' => 'Adult / Erotic / 18+ / Porn',
            'enabled' => true,
            'weight' => 1.0,
            'keywords' => [
                'pornography', 'pornographic', 'porn', 'porno', 'xxx', 'nsfw', 'adult content', 'adult video',
                'erotic', 'erotica', 'escort', 'escorts', 'escort service', 'sex worker',
                'onlyfans', 'camgirl', 'webcam sex', 'nude', 'nudity', 'strip club',
                '18+', 'adults only', 'xxx video', 'porn site', 'porn tube', 'hentai',
                'sex tape', 'adult film', 'porn hub',
            ],
            'domains' => [
                'pornhub', 'xvideos', 'xnxx', 'onlyfans', 'chaturbate', 'stripchat',
                'xhamster', 'redtube', 'youporn', 'spankbang', 'brazzers', 'realitykings',
                'manyvids', 'fansly', 'loyalty.fans', 'cam4', 'livejasmin', 'bongacams',
                'myfreecams', 'adultfriendfinder', 'xtube', 'tube8', 'porn.com', 'sex.com',
            ],
            'intent_phrases' => [
                'watch free porn', 'adult entertainment', 'hire an escort', 'xxx videos',
                'best porn sites', 'free xxx',
            ],
        ],

        'cbd' => [
            'label' => 'CBD / Cannabis',
            'enabled' => false,
            'weight' => 0.9,
            'keywords' => ['cbd oil', 'cannabis', 'marijuana', 'thc', 'weed dispensary', 'hemp flower'],
            'domains' => [],
            'intent_phrases' => ['buy cbd', 'order cannabis'],
        ],
        'alcohol' => [
            'label' => 'Alcohol',
            'enabled' => false,
            'weight' => 0.8,
            'keywords' => ['buy vodka', 'cheap whiskey', 'online liquor store'],
            'domains' => [],
            'intent_phrases' => [],
        ],
        'tobacco' => [
            'label' => 'Tobacco / Vaping',
            'enabled' => false,
            'weight' => 0.8,
            'keywords' => ['buy cigarettes', 'vape juice wholesale', 'tobacco shop online'],
            'domains' => [],
            'intent_phrases' => [],
        ],
        'weapons' => [
            'label' => 'Weapons',
            'enabled' => false,
            'weight' => 0.95,
            'keywords' => ['buy firearms', 'ammunition for sale', 'ghost gun'],
            'domains' => [],
            'intent_phrases' => [],
        ],
        'crypto_promo' => [
            'label' => 'Cryptocurrency Promotions',
            'enabled' => false,
            'weight' => 0.7,
            'keywords' => ['guaranteed crypto profits', 'pump and dump', 'get rich with bitcoin'],
            'domains' => [],
            'intent_phrases' => [],
        ],
    ],

    'exceptions' => [
        'casino royale',
        'poker face',
        'slot' => ['time slot', 'parking slot', 'slot in'],
    ],
];
