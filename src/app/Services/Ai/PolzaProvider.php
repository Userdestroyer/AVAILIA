<?php

namespace App\Services\Ai;

use App\Models\ProviderModel;
use App\Services\Ai\Data\ChatOptions;
use App\Services\Ai\Data\ChatRequest;

/**
 * polza.ai — OpenAI-совместимый chat/completions для всех моделей (docs/polza).
 * Сам сообщает стоимость (usage.cost_rub), умеет выбирать источник внутри себя
 * (provider.order) и искать в интернете своим инструментом polza:web_search.
 */
class PolzaProvider extends AiProvider
{
    public function name(): string
    {
        return 'polza';
    }

    /**
     * Поиск Polza (polza:web_search) работает с любой моделью прямо в chat/completions.
     */
    protected function searchFormat(ProviderModel $offer): ?string
    {
        return ChatRequest::FORMAT_CHAT;
    }

    public function reportedCost(array $usage): ?array
    {
        $cost = $usage['cost_rub'] ?? $usage['cost'] ?? null;

        return is_numeric($cost) ? ['amount' => number_format((float) $cost, 8, '.', ''), 'currency' => 'RUB'] : null;
    }

    protected function extraBody(ProviderModel $offer, ChatOptions $options, bool $stream): array
    {
        $extra = [];

        // Закреплённый источник — первым, но с фолбэком: запрос не упадёт, если источник лёг
        if ($offer->upstream) {
            $extra['provider'] = ['order' => [$offer->upstream], 'allow_fallbacks' => true];
        }

        if ($options->webSearch) {
            $extra['tools'] = [[
                'type' => 'polza:web_search',
                'parameters' => array_filter([
                    'max_uses' => $options->maxWebSearches,
                    'allowed_domains' => $options->allowedDomains ?: null,
                ]),
            ]];
            // Всех серверных вызовов за запрос по умолчанию не больше 10 — иначе лимит поиска выше не сработает
            if ($options->maxWebSearches > 10) {
                $extra['max_tool_calls'] = min(30, $options->maxWebSearches);
            }
        }

        if ($stream) {
            // События поиска (tool_call / tool_result) — для пузырьков в интерфейсе
            $extra['stream_options'] = ['include_usage' => true, 'include_server_tool_events' => $options->webSearch];
        }

        return $extra;
    }
}
