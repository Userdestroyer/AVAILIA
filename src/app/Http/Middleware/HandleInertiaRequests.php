<?php

namespace App\Http\Middleware;

use App\Services\Limits\UsageMeter;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'username' => $user->username,
                    'email' => $user->email,
                    'role' => $user->role,
                    'currency' => $user->currency,
                    'plan' => $user->effectivePlan()->only('code', 'name'),
                    'settings' => $user->interfaceSettings(),
                ] : null,
            ],
            'recentChats' => fn () => $user?->chats()->latest('updated_at')->limit(30)->get(['id', 'title', 'updated_at']) ?? [],
            // Заполнение лимитов (окна текста и агентов, ₽) и баланс токенов — кольцо у кнопки отправки.
            // После каждого ответа фронт перезагружает только этот проп
            'usage' => fn () => $user ? [
                'windows' => app(UsageMeter::class)->summary($user),
                'tokens' => (int) $user->content_tokens,
            ] : null,
            // Энергия — единица для юзера: столько ⚡ за 1 ₽
            'energyRate' => (float) config('limits.energy_per_rub'),
        ];
    }
}
