<?php

namespace Tests\Feature\Chat;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\AiModel;
use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CapturesStreamJobs;
use Tests\TestCase;

class AnswerVariantsTest extends TestCase
{
    use CapturesStreamJobs, RefreshDatabase;

    protected string $seeder = DatabaseSeeder::class;

    protected bool $seed = true;

    #[Group('D1')]
    #[TestDox('Перегенерация: новый вариант с контекстом, ушедшим с вопросом, без прежних ответов на него; в историю идёт выбранный вариант; не больше 5 перегенераций')]
    public function test_regenerated_variant_and_selected_history(): void
    {
        $jobs = $this->captureStreamJobs();
        [$user, $chat, $answer, $model] = $this->answeredQuestion();
        $this->actingAs($user);

        $this->postJson("/text/messages/{$answer->id}/regenerate", ['max_tokens' => 1024])
            ->assertCreated()
            ->assertJsonPath('message.is_current', true);

        $messages = $jobs()[0][1]['body']['messages'];
        // Картридж в той версии, что ушла с вопросом, а не в правленой после
        $this->assertSame('system', $messages[0]['role']);
        $this->assertStringContainsString('Пишу на Go', $messages[0]['content']);
        $this->assertStringNotContainsString('Rust', $messages[0]['content']);
        $this->assertSame(['Как дела?'], array_column(array_slice($messages, 1), 'content'));
        $this->assertFalse($answer->fresh()->is_current);

        // Вернулись к первому варианту — его модель и увидит дальше
        $this->patchJson("/text/messages/{$answer->id}/current")->assertNoContent();
        $this->postJson('/text/messages', ['chat_id' => $chat->id, 'ai_model_ids' => [$model->id], 'content' => 'Дальше'])->assertCreated();
        $this->assertSame(['Как дела?', 'Первый', 'Дальше'], array_column($jobs()[1][1]['body']['messages'], 'content'));

        // Исходный и 5 перегенераций — шестой раз не даёт
        Message::where('parent_id', $answer->parent_id)->update(['status' => MessageStatus::Done]);
        foreach (range(1, 4) as $n) {
            $chat->messages()->create(['parent_id' => $answer->parent_id, 'role' => MessageRole::Assistant, 'status' => MessageStatus::Done, 'ai_model_id' => $model->id, 'is_current' => false]);
        }
        $this->postJson("/text/messages/{$answer->id}/regenerate")->assertUnprocessable();
    }

    #[Group('D1')]
    #[TestDox('Вопрос целиком отдаёт картриджи в версиях, ушедших с ним; чужому — 404 и на него, и на выбор и перегенерацию варианта')]
    public function test_question_context_and_foreign_variants(): void
    {
        $jobs = $this->captureStreamJobs();
        [$user, , $answer] = $this->answeredQuestion();

        $this->actingAs($user)->getJson("/text/messages/{$answer->parent_id}/context")
            ->assertOk()
            ->assertJsonPath('plates.0.title', 'Работа')
            ->assertJsonPath('plates.0.content', 'Пишу на Go');

        $this->actingAs(User::factory()->create());
        $this->getJson("/text/messages/{$answer->parent_id}/context")->assertNotFound();
        $this->patchJson("/text/messages/{$answer->id}/current")->assertNotFound();
        $this->postJson("/text/messages/{$answer->id}/regenerate")->assertNotFound();
        $this->assertSame([], $jobs());
        $this->assertSame(1, Message::where('role', MessageRole::Assistant)->count());
    }

    /**
     * Вопрос с картриджем и ответ Claude Haiku; после вопроса картридж поправили.
     *
     * @return array{0: User, 1: Chat, 2: Message, 3: AiModel}
     */
    private function answeredQuestion(): array
    {
        $model = AiModel::where('slug', 'claude-haiku-5-5')->first();
        $user = User::factory()->create();
        $plate = $user->plates()->create(['title' => 'Работа', 'content' => 'Пишу на Go']);
        $version = $plate->recordVersion();

        $chat = $user->chats()->create(['ai_model_id' => $model->id]);
        $question = $chat->messages()->create([
            'role' => MessageRole::User,
            'content' => 'Как дела?',
            'context' => [['plate_id' => $plate->id, 'version_id' => $version->id, 'title' => 'Работа']],
        ]);
        $answer = $chat->messages()->create([
            'parent_id' => $question->id, 'role' => MessageRole::Assistant, 'status' => MessageStatus::Done,
            'ai_model_id' => $model->id, 'content' => 'Первый',
        ]);

        $plate->update(['content' => 'Пишу на Rust']);
        $plate->recordVersion();

        return [$user, $chat, $answer, $model];
    }
}
