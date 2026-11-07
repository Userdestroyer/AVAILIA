<?php

namespace App\Services\Ai;

use App\Enums\MessageRole;
use App\Models\AiModel;
use App\Models\AppSetting;
use App\Models\Message;
use App\Models\ServiceCall;
use App\Models\User;
use App\Services\Ai\Data\ChatOptions;
use Throwable;

/**
 * Сервисная модель — невидимая обслуга: короткие фоновые задачи (название чата, позже — теги,
 * подбор картриджей, диффы). Никогда не на пути к первому токену: вызывается только из очереди.
 * Каждый вызов учитывается (service_calls) — со стоимостью, как и обычные ответы.
 */
class ServiceModel
{
    public const SETTING = 'ai.service_model';

    public function __construct(private ProviderRegistry $providers) {}

    public function model(): ?AiModel
    {
        $id = AppSetting::get(self::SETTING);

        return ($id ? AiModel::find($id) : null) ?? AiModel::where('slug', config('ai.service_model'))->first();
    }

    /**
     * Короткий ответ сервисной модели или null (модель не подключена у провайдера, провайдер упал).
     * Фоновая задача без ответа просто ничего не меняет — интерфейс от неё не зависит.
     */
    public function complete(?User $user, string $purpose, string $system, string $prompt, int $maxTokens = 64): ?string
    {
        $model = $this->model();
        $offer = $model ? $this->providers->offering($model) : null;
        if (! $offer) {
            return null;
        }

        $provider = $this->providers->provider($offer->provider->slug);
        $messages = [
            new Message(['role' => MessageRole::System, 'content' => $system]),
            new Message(['role' => MessageRole::User, 'content' => $prompt]),
        ];
        $started = hrtime(true);
        $call = ['user_id' => $user?->id, 'purpose' => $purpose, 'provider_model_id' => $offer->id];

        try {
            // Фоновой задаче незачем долго ждать лёгшего провайдера — воркер нужен и другим
            $completion = $provider->complete($offer, $messages, new ChatOptions(maxTokens: $maxTokens), timeout: 20);
        } catch (Throwable $e) {
            report($e);
            ServiceCall::create([...$call, 'status' => 'failed', 'duration_ms' => $this->since($started)]);

            return null;
        }

        $usage = $completion->usage;
        $reported = $provider->reportedCost($completion->rawUsage);
        ServiceCall::create([
            ...$call,
            'status' => 'done',
            'input_tokens' => $usage->input + $usage->cacheRead + $usage->cacheWrite,
            'output_tokens' => $usage->output,
            'cost' => $reported['amount'] ?? $offer->calculateCost($usage->input, $usage->output, $usage->cacheRead, $usage->cacheWrite),
            'cost_currency' => $reported['currency'] ?? $offer->price_currency,
            'duration_ms' => $this->since($started),
        ]);

        return trim($completion->content);
    }

    private function since(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
