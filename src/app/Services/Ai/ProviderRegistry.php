<?php

namespace App\Services\Ai;

use App\Models\AiModel;
use App\Models\AppSetting;
use App\Models\Provider;
use App\Models\ProviderModel;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Куда идёт запрос: активный провайдер (настройка в админке) и предложение модели у него.
 * Единственное место выбора маршрута — сюда же потом ляжет фолбэк на «вторую линию».
 */
class ProviderRegistry
{
    public const SETTING = 'ai.provider';

    private const CLASSES = [
        'proxyapi' => ProxyApiProvider::class,
        'polza' => PolzaProvider::class,
    ];

    /** @var array<string, AiProvider> */
    private array $instances = [];

    private ?Provider $active = null;

    public function provider(string $slug): AiProvider
    {
        $class = self::CLASSES[$slug] ?? throw new InvalidArgumentException("Неизвестный AI-провайдер: {$slug}");
        $config = config("ai.providers.{$slug}");

        return $this->instances[$slug] ??= new $class($config['url'], $config['key']);
    }

    public function activeSlug(): string
    {
        return AppSetting::get(self::SETTING) ?? config('ai.provider');
    }

    public function active(): Provider
    {
        return $this->active ??= Provider::where('slug', $this->activeSlug())->firstOrFail();
    }

    public function activate(Provider $provider): void
    {
        AppSetting::put(self::SETTING, $provider->slug);
        $this->active = $provider;
    }

    /**
     * Предложения активного провайдера для этих моделей (модель без предложения недоступна).
     * Будущий фолбэк: здесь же вернуть упорядоченную цепочку предложений разных провайдеров.
     *
     * @param  iterable<int>  $modelIds
     * @return Collection<int, ProviderModel> по ai_model_id
     */
    public function offerings(iterable $modelIds): Collection
    {
        return $this->active()->offerings()
            ->where('is_active', true)
            ->whereIn('ai_model_id', collect($modelIds))
            ->with(['provider', 'aiModel.vendor', 'capabilities'])
            ->get()
            ->keyBy('ai_model_id');
    }

    public function offering(AiModel $model): ?ProviderModel
    {
        return $this->offerings([$model->id])->get($model->id);
    }
}
