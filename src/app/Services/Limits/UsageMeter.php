<?php

namespace App\Services\Limits;

use App\Enums\UsageScope;
use App\Models\UsageEntry;
use App\Models\UsageWindow;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Денежные лимиты в окнах «сессия + неделя», как у Claude (docs/LIMITS.md).
 * Проверка перед запросом читает открытые окна по индексу — без SUM, путь к первому токену не удлиняется.
 */
class UsageMeter
{
    /**
     * Можно ли тратить в сфере. Открывает окна, если их нет: сессия и неделя начинаются с первого запроса.
     *
     * @return array{kind: string, resets_at: CarbonInterface}|null null — можно; иначе какое окно исчерпано и когда сброс
     */
    public function check(User $user, UsageScope $scope): ?array
    {
        $plan = $user->effectivePlan();

        foreach ($this->open($user, $scope) as $kind => $window) {
            $limit = $plan->windowLimit($scope, $kind);
            if ($limit !== null && (float) $window->spent >= $limit) {
                return ['kind' => $kind, 'resets_at' => $window->ends_at];
            }
        }

        return null;
    }

    /**
     * Записать трату: строка в журнал и прибавка к открытым окнам — в одной транзакции.
     */
    public function charge(User $user, UsageScope $scope, float|string $amount, ?Model $source = null, bool $estimated = false): void
    {
        if ((float) $amount <= 0) {
            return;
        }

        DB::transaction(function () use ($user, $scope, $amount, $source, $estimated) {
            UsageEntry::create([
                'user_id' => $user->id,
                'scope' => $scope,
                'amount' => $amount,
                'estimated' => $estimated,
                'source_type' => $source ? class_basename($source) : null,
                'source_id' => $source?->getKey(),
            ]);

            foreach ($this->open($user, $scope) as $window) {
                // Атомарно: параллельные ответы мульти-запроса не теряют трату друг друга
                UsageWindow::whereKey($window->id)->increment('spent', $amount);
            }
        });
    }

    /**
     * Заполнение окон для интерфейса: доля, трата и лимит в ₽, когда сброс. Окно, которого нет, — пустое.
     *
     * @return array<string, array<string, array{spent: float, limit: ?float, resets_at: ?string}>>
     */
    public function summary(User $user): array
    {
        $plan = $user->effectivePlan();
        $windows = UsageWindow::where('user_id', $user->id)->where('ends_at', '>', now())->get()
            ->keyBy(fn (UsageWindow $w) => "{$w->scope->value}.{$w->kind}");

        $summary = [];
        foreach ([UsageScope::Text, UsageScope::Agent] as $scope) {
            foreach (UsageWindow::KINDS as $kind) {
                $window = $windows["{$scope->value}.{$kind}"] ?? null;
                $summary[$scope->value][$kind] = [
                    'spent' => round((float) ($window?->spent ?? 0), 4),
                    'limit' => ($limit = $plan->windowLimit($scope, $kind)) === null ? null : round($limit, 4),
                    'resets_at' => $window?->ends_at?->toIso8601String(),
                    'hours' => $plan->windowHours($kind),
                ];
            }
        }

        return $summary;
    }

    /**
     * Открытые сейчас окна сферы; закончившиеся заменяются новыми, начинающимися сейчас.
     *
     * @return array<string, UsageWindow>
     */
    private function open(User $user, UsageScope $scope): array
    {
        $plan = $user->effectivePlan();
        $open = UsageWindow::where('user_id', $user->id)
            ->where('scope', $scope)
            ->where('ends_at', '>', now())
            ->get()
            ->keyBy('kind');

        $windows = [];
        foreach (UsageWindow::KINDS as $kind) {
            $windows[$kind] = $open[$kind] ?? UsageWindow::create([
                'user_id' => $user->id,
                'scope' => $scope,
                'kind' => $kind,
                'started_at' => now(),
                'ends_at' => now()->addHours($plan->windowHours($kind)),
            ]);
        }

        return $windows;
    }
}
