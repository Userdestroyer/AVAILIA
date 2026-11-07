<?php

return [

    // Провайдер по умолчанию, пока в админке не выбран другой (app_settings: ai.provider)
    'provider' => env('AI_PROVIDER', 'proxyapi'),

    // Go-гейтвей стримит ответы: забирает задание из Redis по одноразовому токену
    // и по завершении вызывает внутренний эндпоинт Laravel с этим секретом
    'gateway' => [
        'secret' => env('GATEWAY_SECRET'),
        'stream_ttl' => 120,
    ],

    'providers' => [
        'proxyapi' => [
            'url' => env('PROXY_API_URL', 'https://api.proxyapi.ru/v1'),
            'key' => env('PROXY_API_KEY'),
        ],
        'polza' => [
            'url' => env('POLZA_API_URL', 'https://polza.ai/api/v1'),
            'key' => env('POLZA_API_KEY'),
        ],
    ],

];
