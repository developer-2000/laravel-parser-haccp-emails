<?php

return [

    'default' => 'en',

    /**
     * Языки + регионализация для DuckDuckGo.
     *
     * - kl              — параметр DDG (lower-case, формат "country-lang").
     * - accept_language — Accept-Language заголовок HTTP (RFC 5646).
     *
     * Для большинства языков kl = "{code}-{code}" работает; редкие исключения
     * (cs/Чехия = cz-cs, da/Дания = dk-da, el/Греция = gr-el и т.п.) учтены
     * явно, чтобы DDG отдавал локализованную выдачу.
     */
    'languages' => [
        ['code' => 'en', 'name' => 'English',     'kl' => 'us-en', 'accept_language' => 'en-US,en;q=0.9'],
        ['code' => 'ro', 'name' => 'Romania',     'kl' => 'ro-ro', 'accept_language' => 'ro-RO,ro;q=0.9'],
        ['code' => 'de', 'name' => 'Germany',     'kl' => 'de-de', 'accept_language' => 'de-DE,de;q=0.9'],
        ['code' => 'bg', 'name' => 'Bulgaria',    'kl' => 'bg-bg', 'accept_language' => 'bg-BG,bg;q=0.9'],
        ['code' => 'hr', 'name' => 'Croatia',     'kl' => 'hr-hr', 'accept_language' => 'hr-HR,hr;q=0.9'],
        ['code' => 'cs', 'name' => 'Czechia',     'kl' => 'cz-cs', 'accept_language' => 'cs-CZ,cs;q=0.9'],
        ['code' => 'da', 'name' => 'Denmark',     'kl' => 'dk-da', 'accept_language' => 'da-DK,da;q=0.9'],
        ['code' => 'et', 'name' => 'Estonia',     'kl' => 'ee-et', 'accept_language' => 'et-EE,et;q=0.9'],
        ['code' => 'fi', 'name' => 'Finland',     'kl' => 'fi-fi', 'accept_language' => 'fi-FI,fi;q=0.9'],
        ['code' => 'fr', 'name' => 'France',      'kl' => 'fr-fr', 'accept_language' => 'fr-FR,fr;q=0.9'],
        ['code' => 'el', 'name' => 'Greece',      'kl' => 'gr-el', 'accept_language' => 'el-GR,el;q=0.9'],
        ['code' => 'hu', 'name' => 'Hungary',     'kl' => 'hu-hu', 'accept_language' => 'hu-HU,hu;q=0.9'],
        ['code' => 'is', 'name' => 'Iceland',     'kl' => 'is-is', 'accept_language' => 'is-IS,is;q=0.9'],
        ['code' => 'it', 'name' => 'Italy',       'kl' => 'it-it', 'accept_language' => 'it-IT,it;q=0.9'],
        ['code' => 'lv', 'name' => 'Latvia',      'kl' => 'lv-lv', 'accept_language' => 'lv-LV,lv;q=0.9'],
        ['code' => 'lt', 'name' => 'Lithuania',   'kl' => 'lt-lt', 'accept_language' => 'lt-LT,lt;q=0.9'],
        ['code' => 'mt', 'name' => 'Malta',       'kl' => 'mt-mt', 'accept_language' => 'mt-MT,mt;q=0.9'],
        ['code' => 'nl', 'name' => 'Netherlands', 'kl' => 'nl-nl', 'accept_language' => 'nl-NL,nl;q=0.9'],
        ['code' => 'nb', 'name' => 'Norway',      'kl' => 'no-nb', 'accept_language' => 'nb-NO,nb;q=0.9'],
        ['code' => 'pl', 'name' => 'Poland',      'kl' => 'pl-pl', 'accept_language' => 'pl-PL,pl;q=0.9'],
        ['code' => 'pt', 'name' => 'Portugal',    'kl' => 'pt-pt', 'accept_language' => 'pt-PT,pt;q=0.9'],
        ['code' => 'sk', 'name' => 'Slovakia',    'kl' => 'sk-sk', 'accept_language' => 'sk-SK,sk;q=0.9'],
        ['code' => 'sl', 'name' => 'Slovenia',    'kl' => 'sl-sl', 'accept_language' => 'sl-SI,sl;q=0.9'],
        ['code' => 'es', 'name' => 'Spain',       'kl' => 'es-es', 'accept_language' => 'es-ES,es;q=0.9'],
        ['code' => 'sv', 'name' => 'Sweden',      'kl' => 'se-sv', 'accept_language' => 'sv-SE,sv;q=0.9'],
    ],

];
