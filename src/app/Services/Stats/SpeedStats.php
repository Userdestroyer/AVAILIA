<?php

namespace App\Services\Stats;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\AiModel;
use App\Models\Message;
use App\Models\Provider;
use App\Models\ServiceCall;
use Illuminate\Support\Collection;

/**
 * Скорость ответов и фоновые вызовы за последние дни — для админки.
 * Медианы считаются здесь, а не в SQL: одинаково на Postgres и в тестах.
 */
class SpeedStats
{
    public function __construct(private int $days = 7) {}

    /**
     * @return array{days: int, models: list<array<string, mixed>>, service: list<array<string, mixed>>}
     */
    public function summary(): array
    {
        $since = now()->subDays($this->days);

        return [
            'days' => $this->days,
            'models' => $this->models($since),
            'service' => $this->service($since),
        ];
    }

    private function models($since): array
    {
        // Удалённые юзером ответы тоже были — в скорости учитываем
        $rows = Message::withTrashed()
            ->where('role', MessageRole::Assistant)
            ->where('status', MessageStatus::Done)
            ->where('created_at', '>=', $since)
            ->whereNotNull('ttft_ms')
            ->get(['ai_model_id', 'provider_id', 'ttft_ms', 'client_ttft_ms', 'duration_ms']);

        $models = AiModel::whereIn('id', $rows->pluck('ai_model_id')->unique())->pluck('name', 'id');
        $providers = Provider::whereIn('id', $rows->pluck('provider_id')->unique())->pluck('name', 'id');

        return $rows->groupBy(fn (Message $m) => "{$m->provider_id}:{$m->ai_model_id}")
            ->map(fn (Collection $group) => [
                'model' => $models[$group->first()->ai_model_id] ?? '—',
                'provider' => $providers[$group->first()->provider_id] ?? '—',
                'count' => $group->count(),
                'ttft_p50' => self::percentile($group->pluck('ttft_ms'), 0.5),
                'ttft_p90' => self::percentile($group->pluck('ttft_ms'), 0.9),
                'client_p50' => self::percentile($group->pluck('client_ttft_ms'), 0.5),
                'client_p90' => self::percentile($group->pluck('client_ttft_ms'), 0.9),
                'duration_p50' => self::percentile($group->pluck('duration_ms'), 0.5),
            ])
            ->sortBy('ttft_p50')
            ->values()
            ->all();
    }

    private function service($since): array
    {
        return ServiceCall::where('created_at', '>=', $since)
            ->get(['purpose', 'status', 'cost', 'cost_currency', 'duration_ms'])
            ->groupBy('purpose')
            ->map(fn (Collection $group, string $purpose) => [
                'purpose' => $purpose,
                'count' => $group->count(),
                'failed' => $group->where('status', 'failed')->count(),
                // Обычно всё в рублях; разные валюты не складываем
                'cost' => $group->groupBy('cost_currency')
                    ->map(fn (Collection $byCurrency) => $byCurrency->reduce(fn (string $sum, ServiceCall $call) => bcadd($sum, $call->cost ?? '0', 8), '0'))
                    ->all(),
                'duration_p50' => self::percentile($group->pluck('duration_ms'), 0.5),
            ])
            ->values()
            ->all();
    }

    /**
     * Перцентиль по ближайшему рангу; пустой набор — null.
     *
     * @param  Collection<int, ?int>  $values
     */
    public static function percentile(Collection $values, float $p): ?int
    {
        $sorted = $values->filter(fn ($v) => $v !== null)->sort()->values();

        return $sorted->isEmpty() ? null : (int) $sorted[max(0, (int) ceil($p * $sorted->count()) - 1)];
    }
}
