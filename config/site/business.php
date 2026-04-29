<?php

return [

    'business_types' => [

        ['id' => 1, 'name' => 'Meat'],
        ['id' => 2, 'name' => 'Milk'],
        ['id' => 3, 'name' => 'Bread'],
        ['id' => 4, 'name' => 'Fish'],
        ['id' => 5, 'name' => 'Beverages'],
        ['id' => 6, 'name' => 'Sweets'],
        ['id' => 7, 'name' => 'Prepared Food'],
        ['id' => 8, 'name' => 'Fruits/Vegetables'],
        ['id' => 9, 'name' => 'Agro-Processing'],

    ],
    'business_ids' => [
        1 => [
            /*
            |--------------------------------------------------------------------------
            | URL SCORING
            |--------------------------------------------------------------------------
            */

            'url' => [
                'positive' => [
                    5 => [
                        'fleischerei',
                        'metzgerei',
                        'schlachter',
                        'fleischwaren',
                        'wurstwaren',
                    ],

                    3 => [
                        'fleisch',
                        'metzger',
                        'verarbeitung',
                        'produktion',
                        'manufaktur',
                    ],

                    1 => [
                        'lebensmittel',
                        'food',
                        'agrar',
                    ],
                ],

                'negative' => [
                    5 => [
                        'maschinen',
                        'anlagenbau',
                        'industrie',
                        'grosshandel',
                    ],

                    3 => [
                        'shop',
                        'online',
                        'logistik',
                        'spedition',
                        'liefer',
                    ],

                    4 => [
                        'verband',
                        'verein',
                        'immobilien',
                    ],
                ],
            ],

            /*
            |--------------------------------------------------------------------------
            | HTML SCORING
            |--------------------------------------------------------------------------
            */

            'html' => [
                'positive' => [
                    5 => [
                        'fleischverarbeitung',
                        'fleischproduktion',
                        'schlachtbetrieb',
                        'metzgerei',
                        'fleischwaren',
                    ],

                    3 => [
                        'produktion',
                        'herstellung',
                        'verarbeitung',
                        'wurst',
                        'fleisch',
                    ],

                    1 => [
                        'haccp',
                        'brc',
                        'ifs',
                        'iso 22000',
                        'lebensmittelsicherheit',
                        'qualitätsmanagement',
                    ],
                ],

                'negative' => [
                    3 => [
                        'restaurant',
                        'cafe',
                        'imbiss',
                        'takeaway',
                        'speisekarte',
                        'lieferservice',
                    ],

                    5 => [
                        'maschinen',
                        'anlagenbau',
                        'logistik',
                        'spedition',
                        'immobilien',
                    ],
                ],
            ],
        ],
        2 => [

            /*
            |--------------------------------------------------------------------------
            | URL SCORING (кто они по домену)
            |--------------------------------------------------------------------------
            */

            'url' => [

                'positive' => [

                    // +5 — прямые производители молока / молочной продукции
                    5 => [
                        'milchhof',
                        'molkerei',
                        'dairy',
                        'kaeserei',
                        'sennerei',
                        'butterei',
                    ],

                    // +3 — переработка / производство
                    3 => [
                        'milch',
                        'kaese',
                        'joghurt',
                        'quark',
                        'verarbeitung',
                        'produktion',
                        'manufaktur',
                    ],

                    // +1 — агро / пищевая отрасль (слабый сигнал)
                    1 => [
                        'landwirtschaft',
                        'agrar',
                        'lebensmittel',
                        'food',
                    ],
                ],

                'negative' => [

                    // -5 — не производство
                    5 => [
                        'maschinen',
                        'anlagenbau',
                        'industrie',
                        'grosshandel',
                        'technik',
                    ],

                    // -3 — коммерция / логистика / e-commerce
                    3 => [
                        'shop',
                        'online',
                        'logistik',
                        'spedition',
                        'liefer',
                    ],

                    // -4 — организации / ассоциации / нерелевант
                    4 => [
                        'verband',
                        'verein',
                        'immobilien',
                        'beratung',
                    ],
                ],
            ],

            /*
            |--------------------------------------------------------------------------
            | HTML SCORING (что реально на сайте)
            |--------------------------------------------------------------------------
            */

            'html' => [

                'positive' => [

                    // +5 — явное молочное производство
                    5 => [
                        'molkerei',
                        'milchverarbeitung',
                        'kaeserei',
                        'sennerei',
                        'milchproduktion',
                        'butterherstellung',
                        'joghurtproduktion',
                    ],

                    // +3 — процессы переработки
                    3 => [
                        'verarbeitung',
                        'produktion',
                        'herstellung',
                        'milch',
                        'kaese',
                        'joghurt',
                        'quark',
                    ],

                    // +1 — стандарты качества (косвенный, но важный сигнал)
                    1 => [
                        'haccp',
                        'ifs',
                        'brc',
                        'iso 22000',
                        'lebensmittelsicherheit',
                        'qualitätsmanagement',
                        'milchqualität',
                    ],
                ],

                'negative' => [

                    // -3 — retail / horeca
                    3 => [
                        'restaurant',
                        'cafe',
                        'bäckerei',
                        'speisekarte',
                        'takeaway',
                        'lieferservice',
                    ],

                    // -5 — не производство вообще
                    5 => [
                        'maschinen',
                        'anlagenbau',
                        'logistik',
                        'spedition',
                        'immobilien',
                    ],
                ],
            ],
    ],
        3 => [

            /*
            |--------------------------------------------------------------------------
            | URL SCORING (кто они по домену)
            |--------------------------------------------------------------------------
            */

            'url' => [

                'positive' => [

                    // +5 — хлебозаводы / промышленное производство
                    5 => [
                        'baeckerei',
                        'bäckerei',
                        'backerei',
                        'brot',
                        'backwaren',
                        'brotfabrik',
                        'baeckereibetrieb',
                    ],

                    // +3 — производство / переработка / хлебные продукты
                    3 => [
                        'back',
                        'brot',
                        'konditorei',
                        'gebäck',
                        'produktion',
                        'manufaktur',
                        'herstellung',
                    ],

                    // +1 — пищевая отрасль (слабый сигнал)
                    1 => [
                        'lebensmittel',
                        'food',
                        'agrar',
                        'handel',
                    ],
                ],

                'negative' => [

                    // -5 — точно не производство хлеба
                    5 => [
                        'maschinen',
                        'anlagenbau',
                        'industrie',
                        'grosshandel',
                        'technik',
                    ],

                    // -3 — торговля / доставка / сервис
                    3 => [
                        'shop',
                        'online',
                        'logistik',
                        'spedition',
                        'liefer',
                        'versand',
                    ],

                    // -4 — организации / ассоциации / нерелевант
                    4 => [
                        'verband',
                        'verein',
                        'immobilien',
                        'beratung',
                    ],
                ],
            ],

            /*
            |--------------------------------------------------------------------------
            | HTML SCORING (что реально на сайте)
            |--------------------------------------------------------------------------
            */

            'html' => [

                'positive' => [

                    // +5 — промышленное хлебопроизводство
                    5 => [
                        'baeckerei',
                        'bäckerei',
                        'brotproduktion',
                        'backwarenproduktion',
                        'brotfabrik',
                        'teigverarbeitung',
                        'backbetrieb',
                    ],

                    // +3 — производство / процессы
                    3 => [
                        'produktion',
                        'herstellung',
                        'verarbeitung',
                        'backwaren',
                        'brot',
                        'teig',
                        'gebäck',
                        'konditorei',
                    ],

                    // +1 — качество / стандарты (косвенный сигнал)
                    1 => [
                        'haccp',
                        'ifs',
                        'brc',
                        'iso 22000',
                        'lebensmittelsicherheit',
                        'qualitätsmanagement',
                        'backqualität',
                    ],
                ],

                'negative' => [

                    // -3 — retail / horeca (пекарни-магазины, кафе)
                    3 => [
                        'restaurant',
                        'cafe',
                        'imbiss',
                        'speisekarte',
                        'takeaway',
                        'lieferservice',
                        'bäckerei-café',
                    ],

                    // -5 — не производство вообще
                    5 => [
                        'maschinen',
                        'anlagenbau',
                        'logistik',
                        'spedition',
                        'immobilien',
                    ],
                ],
            ],
        ],
        4 => [

            /*
            |--------------------------------------------------------------------------
            | URL SCORING
            |--------------------------------------------------------------------------
            */

            'url' => [

                'positive' => [

                    // +5 — прямые производители / переработка рыбы
                    5 => [
                        'fischerei',
                        'fischverarbeitung',
                        'fischerei-betrieb',
                        'räucherei',
                        'seafood',
                        'fischfarm',
                        'fischzucht',
                        'fischproduktion',
                    ],

                    // +3 — переработка / продукты
                    3 => [
                        'fisch',
                        'seafood',
                        'krabben',
                        'garnelen',
                        'lachs',
                        'forelle',
                        'verarbeitung',
                        'produktion',
                        'manufaktur',
                    ],

                    // +1 — пищевая отрасль (слабый сигнал)
                    1 => [
                        'lebensmittel',
                        'food',
                        'agrar',
                        'frozen',
                    ],
                ],

                'negative' => [

                    // -5 — не производство еды
                    5 => [
                        'maschinen',
                        'anlagenbau',
                        'industrie',
                        'grosshandel',
                        'technik',
                    ],

                    // -3 — торговля / логистика / e-commerce
                    3 => [
                        'shop',
                        'online',
                        'logistik',
                        'spedition',
                        'liefer',
                        'versand',
                    ],

                    // -4 — организации / ассоциации / нерелевант
                    4 => [
                        'verband',
                        'verein',
                        'immobilien',
                        'beratung',
                    ],
                ],
            ],

            /*
            |--------------------------------------------------------------------------
            | HTML SCORING
            |--------------------------------------------------------------------------
            */

            'html' => [

                'positive' => [

                    // +5 — промышленная переработка рыбы
                    5 => [
                        'fischverarbeitung',
                        'fischproduktion',
                        'räucherei',
                        'fischfabrik',
                        'seafood verarbeitung',
                        'fischfiletierung',
                        'fischzucht',
                    ],

                    // +3 — процессы / продукты
                    3 => [
                        'verarbeitung',
                        'produktion',
                        'herstellung',
                        'fisch',
                        'lachs',
                        'forelle',
                        'garnelen',
                        'krabben',
                        'seafood',
                    ],

                    // +1 — стандарты качества / регуляция
                    1 => [
                        'haccp',
                        'ifs',
                        'brc',
                        'iso 22000',
                        'lebensmittelsicherheit',
                        'qualitätsmanagement',
                        'fischqualität',
                        'rückverfolgbarkeit',
                    ],
                ],

                'negative' => [

                    // -3 — horeca / retail
                    3 => [
                        'restaurant',
                        'cafe',
                        'imbiss',
                        'speisekarte',
                        'takeaway',
                        'lieferservice',
                        'sushi bar',
                    ],

                    // -5 — не производство еды
                    5 => [
                        'maschinen',
                        'anlagenbau',
                        'logistik',
                        'spedition',
                        'immobilien',
                    ],
                ],
            ],
        ],
        5 => [

    /*
    |--------------------------------------------------------------------------
    | URL SCORING
    |--------------------------------------------------------------------------
    */

    'url' => [

        'positive' => [

            // +5 — промышленное производство напитков
            5 => [
                'brauerei',
                'bier',
                'mineralwasser',
                'wasserwerk',
                'abfüllung',
                'getränkeproduktion',
                'getränkefabrik',
                'softdrink',
                'saftfabrik',
                'weinproduktion',
            ],

            // +3 — типы напитков / переработка
            3 => [
                'getränke',
                'bier',
                'wein',
                'saft',
                'cola',
                'limonade',
                'energy',
                'alkohol',
                'brau',
                'abfüllung',
                'produktion',
                'manufaktur',
            ],

            // +1 — пищевая / агро база
            1 => [
                'lebensmittel',
                'food',
                'agrar',
                'beverage',
            ],
        ],

        'negative' => [

            // -5 — не производство напитков
            5 => [
                'maschinen',
                'anlagenbau',
                'industrie',
                'grosshandel',
                'technik',
            ],

            // -3 — торговля / доставка / e-commerce
            3 => [
                'shop',
                'online',
                'logistik',
                'spedition',
                'liefer',
                'versand',
            ],

            // -4 — организации / ассоциации / нерелевант
            4 => [
                'verband',
                'verein',
                'immobilien',
                'beratung',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTML SCORING
    |--------------------------------------------------------------------------
    */

    'html' => [

        'positive' => [

            // +5 — промышленное производство напитков
            5 => [
                'brauerei',
                'bierproduktion',
                'getränkeproduktion',
                'abfüllanlage',
                'mineralwasserabfüllung',
                'weinproduktion',
                'saftproduktion',
                'softdrink produktion',
                'getränkefabrik',
            ],

            // +3 — процессы / категории напитков
            3 => [
                'brau',
                'bier',
                'wein',
                'saft',
                'limonade',
                'cola',
                'energy drink',
                'wasser',
                'verarbeitung',
                'produktion',
                'herstellung',
                'abfüllung',
            ],

            // +1 — стандарты качества / безопасность
            1 => [
                'haccp',
                'ifs',
                'brc',
                'iso 22000',
                'lebensmittelsicherheit',
                'qualitätsmanagement',
                'rückverfolgbarkeit',
                'trinkwasserqualität',
            ],
        ],

        'negative' => [

            // -3 — horeca / retail / бары
            3 => [
                'restaurant',
                'cafe',
                'bar',
                'pub',
                'speisekarte',
                'takeaway',
                'lieferservice',
            ],

            // -5 — не производство еды/напитков
            5 => [
                'maschinen',
                'anlagenbau',
                'logistik',
                'spedition',
                'immobilien',
            ],
        ],
    ],
],
        6 => [

    /*
    |--------------------------------------------------------------------------
    | SWEETS / CONFECTIONERY / BAKERY SWEETS
    |--------------------------------------------------------------------------
    */

    'positive_url' => [

        // VERY STRONG (+3)
        'konditorei'       => 3,
        'confiserie'       => 3,
        'confiserie-'      => 3,
        'confiseur'        => 3,
        'chocolat'         => 3,
        'schokolade'       => 3,
        'praline'          => 3,
        'bonbon'           => 3,
        'süsswaren'        => 3,
        'suesswaren'       => 3,
        'candy'            => 3,
        'confectionery'    => 3,
        'confection'       => 3,
        'dessert'          => 3,
        'marzipan'         => 3,
        'nougat'           => 3,
        'lebkuchen'        => 3,

        // STRONG (+2)
        'baeckerei'        => 2,
        'bäckerei'         => 2,
        'backstube'        => 2,
        'backwaren'        => 2,
        'gebäck'           => 2,
        'gebaeck'          => 2,
        'torte'            => 2,
        'keks'             => 2,
        'kekse'            => 2,
        'waffel'           => 2,
        'croissant'        => 2,
        'muffin'           => 2,
        'donut'            => 2,
        'cake'             => 2,
        'pastry'           => 2,
        'cookies'          => 2,
        'biscuit'          => 2,
        'bakery'           => 2,
        'patisserie'       => 2,

        // WEAK (+1)
        'sweets'           => 1,
        'sweet'            => 1,
        'snack'            => 1,
        'food'             => 1,
        'manufaktur'       => 1,
        'feinkost'         => 1,
    ],

    'negative_url' => [

        // VERY STRONG (-5)
        'maschinenbau'     => -5,
        'anlagenbau'       => -5,
        'industriebedarf'  => -5,
        'automation'       => -5,
        'engineering'      => -5,

        // STRONG (-4)
        'shop-system'      => -4,
        'agentur'          => -4,
        'marketing'        => -4,
        'consulting'       => -4,
        'software'         => -4,
        'hosting'          => -4,
        'verband'          => -4,
        'verein'           => -4,

        // MEDIUM (-3)
        'restaurant'       => -3,
        'cafe'             => -3,
        'catering'         => -3,
        'lieferservice'    => -3,
        'hotel'            => -3,
        'immobilien'       => -3,
        'logistik'         => -3,
        'spedition'        => -3,

        // WEAK (-2)
        'job'              => -2,
        'karriere'         => -2,
        'blog'             => -2,
        'news'             => -2,
    ],

    'positive_html' => [

        // HACCP / QUALITY (+3)
        'haccp'                    => 3,
        'ifs food'                 => 3,
        'brc'                      => 3,
        'iso 22000'                => 3,
        'lebensmittelsicherheit'   => 3,
        'qualitätsmanagement'      => 3,
        'qualitaetsmanagement'     => 3,

        // PRODUCTION (+3)
        'produktion'               => 3,
        'herstellung'              => 3,
        'fertigung'                => 3,
        'lebensmittelproduktion'   => 3,

        // SWEETS INDUSTRY (+3)
        'schokolade'               => 3,
        'pralinen'                 => 3,
        'süsswarenproduktion'      => 3,
        'suesswarenproduktion'     => 3,
        'konfekt'                  => 3,
        'marzipan'                 => 3,
        'nougat'                   => 3,
        'desserts'                 => 3,
        'confiserie'               => 3,

        // STRONG (+2)
        'konditorei'               => 2,
        'backwaren'                => 2,
        'gebäck'                   => 2,
        'gebaeck'                  => 2,
        'kekse'                    => 2,
        'waffeln'                  => 2,
        'torten'                   => 2,
        'croissants'               => 2,
        'cookies'                  => 2,
        'cakes'                    => 2,
        'dessertproduktion'        => 2,
        'feinbäckerei'             => 2,
        'feinbaeckerei'            => 2,

        // WEAK (+1)
        'manufaktur'               => 1,
        'tradition'                => 1,
        'handwerk'                 => 1,
        'familienbetrieb'          => 1,
    ],

    'negative_html' => [

        // VERY STRONG (-5)
        'maschinenbau'             => -5,
        'industrieanlagen'         => -5,
        'automation'               => -5,

        // STRONG (-4)
        'softwarelösung'           => -4,
        'softwarelösung für'       => -4,
        'crm-system'               => -4,
        'seo agentur'              => -4,
        'marketingagentur'         => -4,
        'webdesign'                => -4,

        // HORECA / RETAIL (-3)
        'restaurant'               => -3,
        'speisekarte'              => -3,
        'lieferservice'            => -3,
        'takeaway'                 => -3,
        'online bestellen'         => -3,
        'reservierung'             => -3,
        'cafe'                     => -3,
        'hotel'                    => -3,
        'food delivery'            => -3,

        // ECOMMERCE (-3)
        'warenkorb'                => -3,
        'checkout'                 => -3,
        'paypal'                   => -3,
        'shopping cart'            => -3,
        'jetzt bestellen'          => -3,

        // OFFTOPIC (-2)
        'stellenangebote'          => -2,
        'karriereportal'           => -2,
        'immobilien'               => -2,
        'versicherung'             => -2,
    ],

],
        7 => [

    /*
    |--------------------------------------------------------------------------
    | READY MEALS / CONVENIENCE FOOD / FERTIGGERICHTE
    |--------------------------------------------------------------------------
    */

    'positive_url' => [

        // VERY STRONG (+3)
        'fertiggerichte'      => 3,
        'fertigmenue'         => 3,
        'fertigmenüs'         => 3,
        'fertigmahlzeit'      => 3,
        'readymeal'           => 3,
        'ready-meal'          => 3,
        'conveniencefood'     => 3,
        'convenience-food'    => 3,
        'cookandchill'        => 3,
        'cook-chill'          => 3,
        'cookfreeze'          => 3,
        'cook-freeze'         => 3,
        'tiefkühlkost'        => 3,
        'tk-kost'             => 3,
        'menueservice'        => 3,
        'menüservice'         => 3,
        'grosskueche'         => 3,
        'großküche'           => 3,

        // STRONG (+2)
        'cateringproduktion'  => 2,
        'feinkost'            => 2,
        'delikatessen'        => 2,
        'mealprep'            => 2,
        'meal-prep'           => 2,
        'küche'               => 2,
        'kueche'              => 2,
        'lebensmittel'        => 2,
        'foodservice'         => 2,
        'gastronomiebedarf'   => 2,
        'frischekueche'       => 2,
        'frischeküche'        => 2,
        'kantinenservice'     => 2,
        'betriebsgastronomie' => 2,

        // WEAK (+1)
        'manufaktur'          => 1,
        'produktion'          => 1,
        'freshfood'           => 1,
        'fresh-food'          => 1,
        'nutrition'           => 1,
    ],

    'negative_url' => [

        // VERY STRONG (-5)
        'maschinenbau'        => -5,
        'anlagenbau'          => -5,
        'automation'          => -5,
        'industriebedarf'     => -5,
        'engineering'         => -5,

        // STRONG (-4)
        'software'            => -4,
        'erp'                 => -4,
        'crm'                 => -4,
        'agentur'             => -4,
        'consulting'          => -4,
        'verband'             => -4,
        'verein'              => -4,
        'hosting'             => -4,

        // MEDIUM (-3)
        'restaurant'          => -3,
        'hotel'               => -3,
        'lieferservice'       => -3,
        'delivery'            => -3,
        'pizza-service'       => -3,
        'immobilien'          => -3,
        'logistik'            => -3,
        'spedition'           => -3,

        // WEAK (-2)
        'blog'                => -2,
        'karriere'            => -2,
        'jobs'                => -2,
        'news'                => -2,
    ],

    'positive_html' => [

        // HACCP / FOOD SAFETY (+3)
        'haccp'                       => 3,
        'ifs food'                    => 3,
        'brc'                         => 3,
        'iso 22000'                   => 3,
        'lebensmittelsicherheit'      => 3,
        'qualitätsmanagement'         => 3,
        'qualitaetsmanagement'        => 3,

        // INDUSTRIAL FOOD PRODUCTION (+3)
        'produktion'                  => 3,
        'herstellung'                 => 3,
        'fertigung'                   => 3,
        'lebensmittelproduktion'      => 3,
        'großküchenproduktion'        => 3,
        'grosskuechenproduktion'      => 3,

        // READY MEALS (+3)
        'fertiggerichte'              => 3,
        'fertigmahlzeiten'            => 3,
        'convenience food'            => 3,
        'ready meals'                 => 3,
        'cook & chill'                => 3,
        'cook and chill'              => 3,
        'cook & freeze'               => 3,
        'tiefkühlgerichte'            => 3,
        'portionierte mahlzeiten'     => 3,
        'gemeinschaftsverpflegung'    => 3,
        'betriebsgastronomie'         => 3,

        // STRONG (+2)
        'kantinenversorgung'          => 2,
        'menüproduktion'              => 2,
        'menueproduktion'             => 2,
        'frischeküche'                => 2,
        'frischekueche'               => 2,
        'verpflegungssysteme'         => 2,
        'cateringproduktion'          => 2,
        'feinkostproduktion'          => 2,
        'portionierung'               => 2,
        'schalenmenüs'                => 2,

        // WEAK (+1)
        'manufaktur'                  => 1,
        'tradition'                   => 1,
        'familienbetrieb'             => 1,
        'handwerk'                    => 1,
    ],

    'negative_html' => [

        // VERY STRONG (-5)
        'maschinenbau'                => -5,
        'industrieanlagen'            => -5,
        'automation'                  => -5,

        // STRONG (-4)
        'softwarelösung'              => -4,
        'crm-system'                  => -4,
        'erp-system'                  => -4,
        'marketingagentur'            => -4,
        'seo agentur'                 => -4,
        'webdesign'                   => -4,

        // HORECA / DELIVERY (-3)
        'online bestellen'            => -3,
        'lieferservice'               => -3,
        'pizza bestellen'             => -3,
        'restaurant'                  => -3,
        'speisekarte'                 => -3,
        'reservierung'                => -3,
        'takeaway'                    => -3,
        'food delivery'               => -3,

        // ECOMMERCE (-3)
        'warenkorb'                   => -3,
        'checkout'                    => -3,
        'paypal'                      => -3,
        'shopping cart'               => -3,
        'jetzt bestellen'             => -3,

        // OFFTOPIC (-2)
        'stellenangebote'             => -2,
        'karriereportal'              => -2,
        'versicherung'                => -2,
        'immobilien'                  => -2,
    ],

],
        8 => [

    /*
    |--------------------------------------------------------------------------
    | FRUITS / VEGETABLES / PRODUCE / FRESH-CUT
    |--------------------------------------------------------------------------
    */

    'positive_url' => [

        // VERY STRONG (+3)
        'obst'                 => 3,
        'gemuese'              => 3,
        'gemüse'               => 3,
        'frucht'               => 3,
        'fruechte'             => 3,
        'früchte'              => 3,
        'frischgemuese'        => 3,
        'frischgemüse'         => 3,
        'freshcut'             => 3,
        'fresh-cut'            => 3,
        'salatproduktion'      => 3,
        'obstbau'              => 3,
        'gartenbau'            => 3,
        'agrar'                => 3,
        'landwirtschaft'       => 3,
        'fruchthandel'         => 3,
        'gemuesehandel'        => 3,
        'gemüsehandel'         => 3,

        // STRONG (+2)
        'biohof'               => 2,
        'bio-obst'             => 2,
        'bio-gemuese'          => 2,
        'bio-gemüse'           => 2,
        'frischeprodukte'      => 2,
        'feinkost'             => 2,
        'salat'                => 2,
        'kartoffel'            => 2,
        'apfel'                => 2,
        'beeren'               => 2,
        'tomaten'              => 2,
        'zitrus'               => 2,
        'freshproduce'         => 2,
        'produce'              => 2,
        'vegetables'           => 2,
        'fruits'               => 2,

        // WEAK (+1)
        'manufaktur'           => 1,
        'naturkost'            => 1,
        'organic'              => 1,
        'bio'                  => 1,
        'regional'             => 1,
    ],

    'negative_url' => [

        // VERY STRONG (-5)
        'maschinenbau'         => -5,
        'anlagenbau'           => -5,
        'automation'           => -5,
        'industriebedarf'      => -5,
        'engineering'          => -5,

        // STRONG (-4)
        'software'             => -4,
        'erp'                  => -4,
        'crm'                  => -4,
        'agentur'              => -4,
        'consulting'           => -4,
        'verband'              => -4,
        'verein'               => -4,
        'hosting'              => -4,

        // MEDIUM (-3)
        'restaurant'           => -3,
        'hotel'                => -3,
        'lieferservice'        => -3,
        'delivery'             => -3,
        'catering'             => -3,
        'immobilien'           => -3,
        'logistik'             => -3,
        'spedition'            => -3,

        // WEAK (-2)
        'karriere'             => -2,
        'jobs'                 => -2,
        'blog'                 => -2,
        'news'                 => -2,
    ],

    'positive_html' => [

        // HACCP / QUALITY (+3)
        'haccp'                        => 3,
        'ifs food'                     => 3,
        'globalgap'                    => 3,
        'brc'                          => 3,
        'iso 22000'                    => 3,
        'lebensmittelsicherheit'       => 3,
        'qualitätsmanagement'          => 3,
        'qualitaetsmanagement'         => 3,

        // PRODUCTION / PROCESSING (+3)
        'produktion'                   => 3,
        'verarbeitung'                 => 3,
        'lebensmittelproduktion'       => 3,
        'abpackung'                    => 3,
        'sortierung'                   => 3,
        'frischverarbeitung'           => 3,
        'kühlkette'                    => 3,

        // FRUIT / VEGETABLE INDUSTRY (+3)
        'obstverarbeitung'             => 3,
        'gemüseverarbeitung'           => 3,
        'gemueseverarbeitung'          => 3,
        'fresh cut'                    => 3,
        'fresh-cut'                    => 3,
        'salatmischungen'              => 3,
        'fruchtzubereitung'            => 3,
        'tiefkühlgemüse'               => 3,
        'tiefkuehlgemuese'             => 3,
        'obsthandel'                   => 3,
        'gemüsehandel'                 => 3,
        'gemuesehandel'                => 3,

        // STRONG (+2)
        'kartoffelverarbeitung'        => 2,
        'bio gemüse'                   => 2,
        'bio gemuese'                  => 2,
        'frischeprodukte'              => 2,
        'salatproduktion'              => 2,
        'obstlagerung'                 => 2,
        'kühlhaus'                     => 2,
        'ernte'                        => 2,
        'landwirtschaftlicher betrieb' => 2,

        // WEAK (+1)
        'manufaktur'                   => 1,
        'familienbetrieb'              => 1,
        'regionalität'                 => 1,
        'regionalitaet'                => 1,
        'nachhaltigkeit'               => 1,
    ],

    'negative_html' => [

        // VERY STRONG (-5)
        'maschinenbau'                 => -5,
        'industrieanlagen'             => -5,
        'automation'                   => -5,

        // STRONG (-4)
        'softwarelösung'               => -4,
        'crm-system'                   => -4,
        'erp-system'                   => -4,
        'marketingagentur'             => -4,
        'seo agentur'                  => -4,
        'webdesign'                    => -4,

        // HORECA / DELIVERY (-3)
        'restaurant'                   => -3,
        'speisekarte'                  => -3,
        'lieferservice'                => -3,
        'online bestellen'             => -3,
        'takeaway'                     => -3,
        'food delivery'                => -3,
        'cafe'                         => -3,

        // ECOMMERCE (-3)
        'warenkorb'                    => -3,
        'checkout'                     => -3,
        'paypal'                       => -3,
        'shopping cart'                => -3,
        'jetzt bestellen'              => -3,

        // OFFTOPIC (-2)
        'stellenangebote'              => -2,
        'karriereportal'               => -2,
        'versicherung'                 => -2,
        'immobilien'                   => -2,
    ],

],
        9 => [

    /*
    |--------------------------------------------------------------------------
    | AGRI PROCESSING / FOOD PROCESSING / AGRARVERARBEITUNG
    |--------------------------------------------------------------------------
    */

    'positive_url' => [

        // VERY STRONG (+3)
        'agrar'                    => 3,
        'agrarverarbeitung'        => 3,
        'lebensmittelproduktion'   => 3,
        'lebensmitteltechnik'      => 3,
        'foodprocessing'           => 3,
        'food-processing'          => 3,
        'verarbeitung'             => 3,
        'rohstoffverarbeitung'     => 3,
        'muehle'                   => 3,
        'mühle'                    => 3,
        'getreide'                 => 3,
        'getreideverarbeitung'     => 3,
        'oelmuehle'                => 3,
        'ölmühle'                  => 3,
        'molkerei'                 => 3,
        'schlachthof'              => 3,
        'fleischverarbeitung'      => 3,
        'obstverarbeitung'         => 3,
        'gemueseverarbeitung'      => 3,
        'gemüseverarbeitung'       => 3,

        // STRONG (+2)
        'landwirtschaft'           => 2,
        'futtermittel'             => 2,
        'futterproduktion'         => 2,
        'silage'                   => 2,
        'mischfutter'              => 2,
        'agrartechnik'             => 2,
        'frischeprodukte'          => 2,
        'bioenergie'               => 2,
        'biogas'                   => 2,
        'manufaktur'               => 2,
        'produktion'               => 2,
        'veredelung'               => 2,

        // WEAK (+1)
        'bio'                      => 1,
        'regional'                 => 1,
        'organic'                  => 1,
        'rohstoffe'                => 1,
        'silo'                     => 1,
    ],

    'negative_url' => [

        // VERY STRONG (-5)
        'maschinenbau'             => -5,
        'automation'               => -5,
        'industrieanlagen'         => -5,
        'engineering'              => -5,
        'anlagenbau'               => -5,

        // STRONG (-4)
        'software'                 => -4,
        'erp'                      => -4,
        'crm'                      => -4,
        'agentur'                  => -4,
        'consulting'               => -4,
        'verband'                  => -4,
        'verein'                   => -4,
        'hosting'                  => -4,

        // MEDIUM (-3)
        'restaurant'               => -3,
        'hotel'                    => -3,
        'lieferservice'            => -3,
        'delivery'                 => -3,
        'catering'                 => -3,
        'immobilien'               => -3,
        'logistik'                 => -3,
        'spedition'                => -3,

        // WEAK (-2)
        'karriere'                 => -2,
        'jobs'                     => -2,
        'blog'                     => -2,
        'news'                     => -2,
    ],

    'positive_html' => [

        // HACCP / QUALITY (+3)
        'haccp'                           => 3,
        'ifs food'                        => 3,
        'brc'                             => 3,
        'iso 22000'                       => 3,
        'fssc 22000'                      => 3,
        'globalgap'                       => 3,
        'lebensmittelsicherheit'          => 3,
        'qualitätsmanagement'             => 3,
        'qualitaetsmanagement'            => 3,

        // PRODUCTION / PROCESSING (+3)
        'produktion'                      => 3,
        'verarbeitung'                    => 3,
        'herstellung'                     => 3,
        'rohstoffverarbeitung'            => 3,
        'lebensmittelproduktion'          => 3,
        'agrarproduktion'                 => 3,
        'industrielle verarbeitung'       => 3,
        'weiterverarbeitung'              => 3,

        // AGRI INDUSTRY (+3)
        'getreideverarbeitung'            => 3,
        'mühlenbetrieb'                   => 3,
        'muehlenbetrieb'                  => 3,
        'futtermittelproduktion'          => 3,
        'mischfutterproduktion'           => 3,
        'milchverarbeitung'               => 3,
        'fleischverarbeitung'             => 3,
        'obstverarbeitung'                => 3,
        'gemüseverarbeitung'              => 3,
        'gemueseverarbeitung'             => 3,
        'ölmühle'                         => 3,
        'oelmuehle'                       => 3,
        'landwirtschaftliche erzeugnisse' => 3,

        // STRONG (+2)
        'rohstoffe'                       => 2,
        'siloanlagen'                     => 2,
        'lagerung'                        => 2,
        'kühlkette'                       => 2,
        'abfüllung'                       => 2,
        'abpackung'                       => 2,
        'veredelung'                      => 2,
        'bioenergie'                      => 2,
        'biogas'                          => 2,
        'agrarbetrieb'                    => 2,

        // WEAK (+1)
        'manufaktur'                      => 1,
        'familienbetrieb'                 => 1,
        'regionalität'                    => 1,
        'regionalitaet'                   => 1,
        'nachhaltigkeit'                  => 1,
    ],

    'negative_html' => [

        // VERY STRONG (-5)
        'maschinenbau'                    => -5,
        'industrieanlagen'                => -5,
        'automation'                      => -5,

        // STRONG (-4)
        'softwarelösung'                  => -4,
        'crm-system'                      => -4,
        'erp-system'                      => -4,
        'marketingagentur'                => -4,
        'seo agentur'                     => -4,
        'webdesign'                       => -4,

        // HORECA / DELIVERY (-3)
        'restaurant'                      => -3,
        'speisekarte'                     => -3,
        'lieferservice'                   => -3,
        'online bestellen'                => -3,
        'takeaway'                        => -3,
        'food delivery'                   => -3,

        // ECOMMERCE (-3)
        'warenkorb'                       => -3,
        'checkout'                        => -3,
        'paypal'                          => -3,
        'shopping cart'                   => -3,
        'jetzt bestellen'                 => -3,

        // OFFTOPIC (-2)
        'stellenangebote'                 => -2,
        'karriereportal'                  => -2,
        'versicherung'                    => -2,
        'immobilien'                      => -2,
    ],

],
    ],
];
