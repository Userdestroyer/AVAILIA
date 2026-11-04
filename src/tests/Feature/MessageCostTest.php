<?php

namespace Tests\Feature;

use App\Enums\AiModelType;
use App\Enums\MessageRole;
use App\Models\AiModel;
use App\Models\Chat;
use App\Models\ExchangeRate;
use App\Models\Message;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class MessageCostTest extends TestCase
{
    use RefreshDatabase;

    #[Group('D1')]
    #[TestDox('Стоимость сообщения переводится в валюту по курсу на дату сообщения')]
    public function test_message_cost_uses_rate_of_its_own_date(): void
    {
        ExchangeRate::insert([
            ['date' => '2026-11-01', 'currency' => 'RUB', 'rate' => '90', 'source' => 'test'],
            ['date' => '2026-11-03', 'currency' => 'RUB', 'rate' => '100', 'source' => 'test'],
        ]);

        $vendor = Vendor::create(['slug' => 'test-vendor', 'name' => 'Test']);
        $model = AiModel::create([
            'vendor_id' => $vendor->id, 'type' => AiModelType::Text, 'slug' => 'test', 'name' => 'Test',
        ]);
        $chat = Chat::create(['user_id' => User::factory()->create()->id, 'ai_model_id' => $model->id]);

        $message = Message::create([
            'chat_id' => $chat->id, 'role' => MessageRole::Assistant, 'cost_usd' => '2',
        ]);

        // 2 ноября — ближайший более ранний курс от 1-го
        $message->created_at = '2026-11-02 12:00:00';
        $this->assertSame('180.00000000', $message->costIn('RUB'));

        $message->created_at = '2026-11-04 12:00:00';
        $this->assertSame('200.00000000', $message->costIn('RUB'));

        $this->assertSame('2.00000000', $message->costIn('USD'));
    }
}
