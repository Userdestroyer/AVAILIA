<?php

return [

    /*
    | Источники курсов по порядку: если первый недоступен — берётся следующий.
    | cbr         — ЦБ РФ (официальный курс, но режет иностранные IP)
    | open_er_api — open.er-api.com (без ключа, только текущий курс)
    */
    'sources' => explode(',', env('EXCHANGE_RATES_SOURCES', 'cbr,open_er_api')),

    // Валюта по умолчанию для показа стоимости
    'default_currency' => env('DEFAULT_CURRENCY', 'RUB'),

];
