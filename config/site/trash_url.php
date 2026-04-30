<?php

/**
 * Группы regex-фрагментов, по которым SearchJob отбрасывает заведомо
 * нерелевантные URL из выдачи DuckDuckGo (магазины, оборудование,
 * агрегаторы, документы, ассоциации, госорганы и т.п.).
 *
 * Все группы склеиваются через "|" в одну альтернацию и оборачиваются
 * в нечувствительный к регистру regex (флаг i). Pattern собирается
 * один раз на воркер — см. SearchJob::trashUrlPattern().
 *
 * ВАЖНО: /produkte/ и /products/ намеренно НЕ входят ни в одну группу —
 * у настоящих производителей это страница ассортимента, она нам нужна.
 */

return [
    'groups' => [

        // 1. Глобальные платформы и job-сайты — заведомо не производители.
        'global_platforms' => 'google\.|facebook\.|linkedin\.|youtube\.|amazon\.|wikipedia\.|instagram\.|tiktok\.|'
            . 'kununu\.|stepstone\.|indeed\.',

        // 2. B2B-каталоги, агрегаторы, бизнес-справочники, госстатистика.
        // Содержат списки компаний, а не сами производители.
        'aggregators' => 'gelbeseiten\.|wer-liefert-was\.|europages\.|kompass\.com|'
            . 'wlw\.de|firmenwissen\.de|northdata\.de|branchenbuch|'
            . 'bizdb\.|vdma-products\.|firmen-|unternehmen-|spherescout\.|'
            . 'creditreform\.|firmeneintrag|destatis\.|fleischbranche\.|'
            . 'remax\.|immobilien-|-immobilien\.|wizzair\.',

        // 3. Магазины (домены). Производитель может иметь онлайн-витрину,
        // но если он назвался "shop" в домене — это розничник, не производитель.
        // \.shop$ и \.shop/ — TLD .shop (типа torwegge.shop).
        'shop_domains' => '-shop\.|shop-|shop\d+\.|-store\.|store-|-versand\.|-direkt\.|'
            . '-katalog\.|-mall\.|-onlineshop\.|onlineshop-|'
            . '\.shop/|\.shop$|\.shop\?|'
            . '-grosshandel\.|grosshandel-|-grosshandel-',

        // 4. Магазины (пути). Признаки розничной площадки в URL-пути.
        'shop_paths' => '/shop/|/store/|/cart/|/warenkorb/|/checkout/|/kaufen/|/bestellen/|'
            . '/sortiment/|/category/|/categories/|/kategorie/|/kategorien/',

        // 5a. Оборудование и техника — пути. Не производители продуктов,
        // а поставщики машин/деталей (statt-shop.de/.../tumbler/tumbler и т.п.).
        'equipment_paths' => '/maschinen/|/equipment/|/zubehoer/|/ersatzteile/|/anlagen/|'
            . '/tumbler/|/mischmaschine|/verpackungsmaschine|/fleischwolf|/kutter|'
            . '/technik/|/technologie/|/teile/',

        // 5b. Оборудование — доменные паттерны (например mayer-maschinen.de).
        // Это equipment-производители, нам нужны те, кто делает САМО мясо.
        'equipment_domains' => '-maschinen\.|maschinen-|-anlagen\.|anlagen-|-anlagenbau\.|anlagenbau-|'
            . '-technik\.|technik-|-geraete\.|-systeme\.|-werkzeug\.|werkzeug-|'
            . '-edelstahl\.|edelstahl-|-pack\.|pack-|-verpackung\.|verpackung-|'
            . '-arbeitssicher|arbeitssicher-|asz-gmbh',

        // 6. Документы (PDF/DOC), регуляторы/образование, ассоциации/союзы.
        // PDF-каталог производителя ценен, но мы не парсим PDF.
        // Verband / Verein — это ассоциации (vdf.de, fleischerverband etc), не производители.
        'docs_and_gov' => '\.pdf$|\.doc$|\.docx$|\.xls$|\.xlsx$|'
            . 'wko\.at|bmel\.de|\.gov\.|\.gov$|/regierung|'
            . 'uni-|hochschule-|tu-berlin|tu-muenchen|tu-dresden|'
            . '-verband\.|verband-|-verein\.|verein-|v-d-f\.|fleischerverband|industrieverband',

    ],
];
