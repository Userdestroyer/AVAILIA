<?php

namespace App\Services\Ai;

use App\Models\AiModel;

/**
 * proxyapi.ru — единый OpenAI-совместимый эндпоинт, модели в формате vendor/model.
 */
class ProxyApiProvider extends AiProvider
{
    // Где префикс вендора у proxyapi отличается от нашего slug
    private const VENDOR_PREFIXES = [
        'xai' => 'x-ai',
    ];

    public function name(): string
    {
        return 'proxyapi';
    }

    public function modelId(AiModel $model): string
    {
        $vendor = $model->vendor->slug;

        return (self::VENDOR_PREFIXES[$vendor] ?? $vendor).'/'.$model->slug;
    }
}
