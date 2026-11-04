<?php

return [

    // Активный агрегатор: proxyapi (позже — poiza)
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
    ],

];
