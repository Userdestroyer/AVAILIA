<?php

namespace App\Services\Images;

/**
 * Цена картинки по прайсу из каталога, ₽: за запрос или ступенями — по разрешению, качеству, числу
 * референсов («image_resolution=2K», «input_images_count=3»). Выбирается самая точная подходящая ступень.
 */
class ImagePrice
{
    /**
     * @param  array<string, mixed>  $pricing  прайс модели (top_provider.pricing)
     * @param  array<string, string>  $params  параметры запроса — уже с умолчаниями модели
     * @return float|null null — прайс не за картинку (по токенам) или пустой
     */
    public static function estimate(array $pricing, array $params, int $references = 0): ?float
    {
        if (isset($pricing['per_request'])) {
            return (float) $pricing['per_request'];
        }

        $facts = [...array_map('strval', $params), 'input_images_count' => (string) $references];
        $best = null;

        foreach ($pricing['tiers'] ?? [] as $tier) {
            $conditions = $tier['conditions'] ?? [];
            $matches = collect($conditions)->every(function (string $condition) use ($facts) {
                [$key, $value] = array_pad(explode('=', $condition, 2), 2, null);

                return ($facts[$key] ?? null) === $value;
            });

            if ($matches && ($best === null || count($conditions) > count($best['conditions'] ?? []))) {
                $best = $tier;
            }
        }

        return $best ? (float) $best['cost_rub'] : null;
    }

    /**
     * Подпись для выбора модели: «≈ 4 токена за картинку», «3–11 токенов за картинку».
     *
     * @param  array<string, mixed>  $pricing
     */
    public static function label(array $pricing): string
    {
        $prices = isset($pricing['per_request'])
            ? [(float) $pricing['per_request']]
            : array_map(fn (array $tier) => (float) $tier['cost_rub'], $pricing['tiers'] ?? []);
        if (! $prices) {
            return 'цена — по факту генерации';
        }

        [$min, $max] = [(int) ceil(min($prices)), (int) ceil(max($prices))];

        return ($min === $max ? "≈ {$min}" : "{$min}–{$max}").' ток. за картинку';
    }
}
