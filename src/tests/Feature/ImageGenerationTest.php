<?php

namespace Tests\Feature;

use App\Enums\AiModelType;
use App\Enums\FileSource;
use App\Enums\MessageStatus;
use App\Models\AiModel;
use App\Models\Message;
use App\Models\Provider;
use App\Models\TokenTransaction;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class ImageGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected string $seeder = DatabaseSeeder::class;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // Задание и опрос статуса — сразу, в том же запросе
        config(['queue.default' => 'sync', 'images.poll_seconds' => 0]);
    }

    #[Group('D1')]
    #[TestDox('Картинка: токены удерживаются по прайсу, референсы уходят base64, результат — в «Сгенерированное» и к ответу (не скачалась — качается снова), токены пересчитываются по факту')]
    public function test_generates_image_and_settles_tokens(): void
    {
        $model = $this->imageModel();
        $user = User::factory()->create(['content_tokens' => 50]);
        $this->actingAs($user);
        $reference = $this->post('/files', ['file' => UploadedFile::fake()->image('ref.png', 64, 64)])->json('file.id');
        $this->fakePolza([['status' => 'processing'], ['status' => 'completed', 'data' => ['url' => 'https://cdn.polza.test/result.png'], 'usage' => ['cost_rub' => 2.9]]], cdnFailures: 2);

        $response = $this->postJson('/images/messages', [
            'ai_model_id' => $model->id,
            'prompt' => 'Кот в скафандре',
            'file_ids' => [$reference],
            'params' => ['aspect_ratio' => '16:9', 'image_resolution' => '2K', 'quality' => 'ultra'],
        ])->assertCreated();

        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0])->first(fn (Request $request) => $request->method() === 'POST');
        $this->assertSame('google/nano-banana', $sent['model']);
        $this->assertTrue($sent['async']);
        // Только то, что модель принимает: качества у неё нет
        $this->assertSame(['prompt' => 'Кот в скафандре', 'aspect_ratio' => '16:9', 'image_resolution' => '2K'], array_diff_key($sent['input'], ['images' => 1]));
        $this->assertStringStartsWith('data:image/png;base64,', $sent['input']['images'][0]['data']);

        // Готовую картинку не скачали с первого раза — качаем снова, статус второй раз не спрашиваем
        $this->assertSame(2, collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), '/media/aig_1'))->count());
        $answer = Message::find($response->json('answer.id'));
        $this->assertSame(MessageStatus::Done, $answer->status);
        $generated = $answer->files->sole();
        $this->assertSame([$user->id, FileSource::Generated], [$generated->user_id, $generated->source]);
        $this->assertSame(AiModelType::Image, $answer->chat->mode);

        // Удержано 5 (2K — 4,50 ₽), по факту 2,90 ₽ → 3 токена, 2 вернулись
        $this->assertSame(47, $user->fresh()->content_tokens);
        $this->assertSame([-5, 2], TokenTransaction::where('user_id', $user->id)->orderBy('id')->pluck('amount')->all());
        $this->getJson("/images/messages/{$answer->id}")->assertOk()
            ->assertJsonPath('message.files.0.id', $generated->id)
            ->assertJsonPath('message.image.tokens', 3);
    }

    #[Group('D1')]
    #[TestDox('Не хватает токенов — генерация не начинается; не удалась — удержанное возвращается целиком')]
    public function test_no_tokens_no_generation_and_failure_refunds(): void
    {
        $model = $this->imageModel();
        $poor = User::factory()->create(['content_tokens' => 2]);
        $this->actingAs($poor)->postJson('/images/messages', ['ai_model_id' => $model->id, 'prompt' => 'Кот'])
            ->assertJsonValidationErrors('tokens');
        $this->assertSame([0, 2], [$poor->chats()->count(), $poor->fresh()->content_tokens]);

        $user = User::factory()->create(['content_tokens' => 10]);
        $this->fakePolza([['status' => 'failed', 'error' => ['message' => 'запрещённый контент']]]);
        $answer = $this->actingAs($user)->postJson('/images/messages', ['ai_model_id' => $model->id, 'prompt' => 'Кот'])->assertCreated()->json('answer.id');

        $this->assertSame(MessageStatus::Failed, Message::find($answer)->status);
        $this->assertSame(10, $user->fresh()->content_tokens);
    }

    #[Group('D1')]
    #[TestDox('Чужое не трогается: ни статус чужой картинки, ни чужой референс, ни чужой чат')]
    public function test_foreign_images_are_isolated(): void
    {
        $model = $this->imageModel();
        $owner = User::factory()->create(['content_tokens' => 50]);
        $this->fakePolza([['status' => 'completed', 'data' => ['url' => 'https://cdn.polza.test/result.png']]]);
        $reference = $this->actingAs($owner)->post('/files', ['file' => UploadedFile::fake()->image('ref.png', 64, 64)])->json('file.id');
        $started = $this->postJson('/images/messages', ['ai_model_id' => $model->id, 'prompt' => 'Кот'])->assertCreated();

        $this->actingAs(User::factory()->create(['content_tokens' => 50]));
        $this->getJson('/images/messages/'.$started->json('answer.id'))->assertNotFound();
        $this->get('/images/'.$started->json('chat.id'))->assertNotFound();
        $this->postJson('/images/messages', ['ai_model_id' => $model->id, 'prompt' => 'Кот', 'file_ids' => [$reference]])->assertUnprocessable();
        $this->postJson('/images/messages', ['ai_model_id' => $model->id, 'prompt' => 'Кот', 'chat_id' => $started->json('chat.id')])->assertNotFound();
        $this->assertSame(1, Message::where('role', 'assistant')->count());
    }

    /**
     * Nano Banana у Polza: пропорции и разрешение, до 2 референсов, цена ступенями по разрешению.
     */
    private function imageModel(): AiModel
    {
        $model = AiModel::create([
            'vendor_id' => Vendor::where('slug', 'google')->value('id'),
            'type' => AiModelType::Image,
            'slug' => 'nano-banana',
            'name' => 'Nano Banana',
        ]);
        $model->offerings()->create([
            'provider_id' => Provider::where('slug', 'polza')->value('id'),
            'external_id' => 'google/nano-banana',
            'parameters' => [
                'prompt' => ['max_length' => 5000],
                'aspect_ratio' => ['values' => ['1:1', '16:9'], 'default' => '1:1'],
                'image_resolution' => ['values' => ['1K', '2K'], 'default' => '1K'],
                'images' => ['max' => 2],
            ],
            'pricing' => ['tiers' => [
                ['conditions' => [], 'cost_rub' => '6.75'],
                ['conditions' => ['image_resolution=1K'], 'cost_rub' => '3.00'],
                ['conditions' => ['image_resolution=2K'], 'cost_rub' => '4.50'],
            ]],
        ]);

        return $model;
    }

    /**
     * Polza: задание принято, дальше — статусы по очереди; картинка с CDN — настоящий PNG
     * (первые $cdnFailures раз CDN не отвечает).
     *
     * @param  list<array<string, mixed>>  $statuses
     */
    private function fakePolza(array $statuses, int $cdnFailures = 0): void
    {
        $image = imagecreatetruecolor(32, 32);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        Http::fake([
            'cdn.polza.test/*' => Http::sequence(array_fill(0, $cdnFailures, Http::response('', 503)))->whenEmpty(Http::response($png, 200, ['Content-Type' => 'image/png'])),
            '*/media/aig_1' => Http::sequence(array_map(fn ($status) => Http::response(['id' => 'aig_1', ...$status]), $statuses)),
            '*/media' => Http::response(['id' => 'aig_1', 'status' => 'pending']),
        ]);
    }
}
