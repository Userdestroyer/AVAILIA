<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\MessageRole;
use App\Enums\PlanCode;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['username', 'name', 'email', 'password', 'role', 'currency', 'plan_id', 'plan_expires_at', 'settings'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'plan_expires_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    /**
     * Настройки интерфейса с дефолтами — фронт всегда получает полный набор.
     *
     * @return array{theme: string}
     */
    public function interfaceSettings(): array
    {
        return [...self::DEFAULT_SETTINGS, ...($this->settings ?? [])];
    }

    public const DEFAULT_SETTINGS = [
        'theme' => 'light',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function chats(): HasMany
    {
        return $this->hasMany(Chat::class);
    }

    public function messages(): HasManyThrough
    {
        return $this->hasManyThrough(Message::class, Chat::class);
    }

    /**
     * Сколько запросов к моделям сделано сегодня — для дневного лимита тарифа.
     */
    public function requestsToday(): int
    {
        return $this->messages()
            ->where('messages.role', MessageRole::Assistant)
            ->where('messages.created_at', '>=', today())
            ->count();
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * Тариф, который действует сейчас: без тарифа или после истечения — гостевой.
     */
    public function effectivePlan(): Plan
    {
        if ($this->plan && ($this->plan_expires_at === null || $this->plan_expires_at->isFuture())) {
            return $this->plan;
        }

        return Plan::byCode(PlanCode::Guest);
    }
}
