<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UsageScope;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\TokenTransaction;
use App\Models\UsageWindow;
use App\Models\User;
use App\Services\Limits\TokenWallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Админка: тарифы (лимиты по сферам) и пользователи (тариф, траты, токены). docs/LIMITS.md
 */
class LimitsController extends Controller
{
    public static function plans(): array
    {
        return Plan::orderBy('sort')->get()->map(fn (Plan $plan) => self::presentPlan($plan))->all();
    }

    /**
     * Последние пользователи с тарифом, заполнением текстовой недели и балансом токенов.
     */
    public static function users(): array
    {
        $users = User::with('plan')->latest()->limit(200)->get();
        $weeks = UsageWindow::whereIn('user_id', $users->pluck('id'))
            ->where('scope', UsageScope::Text)
            ->where('kind', UsageWindow::WEEK)
            ->where('ends_at', '>', now())
            ->pluck('spent', 'user_id');

        return $users->map(fn (User $user) => [
            ...$user->only('id', 'username', 'email', 'plan_id', 'content_tokens'),
            'role' => $user->role,
            'week_spent' => round((float) ($weeks[$user->id] ?? 0), 2),
            'week_limit' => ($limit = $user->effectivePlan()->windowLimit(UsageScope::Text, UsageWindow::WEEK)) === null ? null : round($limit, 2),
        ])->all();
    }

    public function updatePlan(Request $request, Plan $plan): JsonResponse
    {
        $money = ['nullable', 'numeric', 'min:0', 'max:10000000'];
        $plan->update($request->validate([
            'name' => ['sometimes', 'string', 'max:60'],
            'text_monthly' => $money,
            'agent_monthly' => $money,
            'content_tokens_monthly' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'sessions_per_week' => ['sometimes', 'numeric', 'between:0.5,50'],
            'session_hours' => ['sometimes', 'integer', 'between:1,72'],
        ]));

        return response()->json(['plan' => self::presentPlan($plan)]);
    }

    public function updateUser(Request $request, User $user, TokenWallet $wallet): JsonResponse
    {
        $validated = $request->validate(['plan_id' => ['required', Rule::exists('plans', 'id')]]);
        $user->update([...$validated, 'plan_expires_at' => null]);
        // Новый тариф с токенами — первое начисление сразу, не ждать ночи
        $wallet->grantMonthlyIfDue($user->refresh());

        return response()->json(['content_tokens' => $user->content_tokens]);
    }

    /**
     * Начислить токены контента вручную — любому, в том числе себе.
     */
    public function grantTokens(Request $request, User $user, TokenWallet $wallet): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'between:1,10000000'],
            'comment' => ['nullable', 'string', 'max:200'],
        ]);

        $transaction = $wallet->grant($user, $validated['amount'], TokenTransaction::ADMIN, $request->user(), $validated['comment'] ?? null);

        return response()->json(['content_tokens' => $transaction->balance_after]);
    }

    private static function presentPlan(Plan $plan): array
    {
        return [
            ...$plan->only('id', 'code', 'name', 'description', 'text_monthly', 'agent_monthly', 'content_tokens_monthly', 'sessions_per_week', 'session_hours'),
            'users_count' => $plan->users()->count(),
            // Во что месячные суммы превращаются в окнах — видно сразу при калибровке
            'windows' => collect([UsageScope::Text, UsageScope::Agent])->mapWithKeys(fn (UsageScope $scope) => [$scope->value => [
                'week' => self::round($plan->windowLimit($scope, UsageWindow::WEEK)),
                'session' => self::round($plan->windowLimit($scope, UsageWindow::SESSION)),
            ]]),
        ];
    }

    private static function round(?float $value): ?float
    {
        return $value === null ? null : round($value, 2);
    }
}
