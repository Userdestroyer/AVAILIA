<?php

namespace App\Http\Resources;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Services\Limits\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RuntimeException;

/**
 * @mixin \App\Models\Message
 */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAnswer = $this->role === MessageRole::Assistant;
        $lost = $this->isLost();

        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'role' => $this->role,
            'status' => $lost ? MessageStatus::Failed : $this->status,
            'content' => $this->content ?? '',
            'error' => $lost ? 'Ответ не был получен' : $this->error,
            'model' => $isAnswer && $this->aiModel ? [
                'name' => $this->aiModel->name,
                'vendor' => $this->aiModel->vendor->slug,
            ] : null,
            'cost' => $isAnswer && $this->status === MessageStatus::Done ? $this->cost() : null,
            // Энергия — то, что видит юзер (₽ себестоимости × курс энергии); рубли — при наведении
            'energy' => $isAnswer && $this->status === MessageStatus::Done && $this->cost !== null
                ? round((float) Money::toRub($this->cost, $this->cost_currency, $this->created_at) * config('limits.energy_per_rub'), 2)
                : null,
            // Поиски модели — пузырьки над ответом
            'searches' => $isAnswer ? ($this->search_log ?? []) : [],
            // Какие картриджи ушли с вопросом — пластинки над ним
            'context' => $isAnswer ? [] : array_map(
                fn (array $entry) => ['plate_id' => $entry['plate_id'], 'title' => $entry['title']],
                $this->context ?? [],
            ),
        ];
    }

    /**
     * Гейтвей так и не отчитался (упал или не достучался до Laravel) — генерация давно бы закончилась.
     */
    private function isLost(): bool
    {
        return in_array($this->status, [MessageStatus::Pending, MessageStatus::Streaming], true)
            && $this->created_at?->lt(now()->subMinutes(15));
    }

    /**
     * Себестоимость в валюте владельца чата; без курса — в исходной валюте; неизвестна — null.
     */
    private function cost(): ?array
    {
        // Расценок не было, а провайдер стоимость не сообщил — не показываем
        if ($this->cost === null) {
            return null;
        }

        $currency = $this->chat?->user?->currency ?? 'RUB';

        try {
            return ['amount' => $this->costIn($currency), 'currency' => $currency];
        } catch (RuntimeException) {
            return ['amount' => $this->cost, 'currency' => $this->cost_currency];
        }
    }
}
