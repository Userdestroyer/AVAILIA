<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\ContextPlate;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CapturesStreamJobs;
use Tests\TestCase;

class ContextTest extends TestCase
{
    use CapturesStreamJobs, RefreshDatabase;

    protected string $seeder = DatabaseSeeder::class;

    protected bool $seed = true;

    #[Group('D1')]
    #[TestDox('Картриджи только свои: чужой не увидеть, не изменить, не удалить и не подключить к запросу; свой уходит в системный промпт с версией')]
    public function test_plates_are_isolated_and_reach_system_prompt(): void
    {
        $jobs = $this->captureStreamJobs();
        $model = AiModel::where('slug', 'gemini-4-argon')->first();
        [$owner, $stranger] = User::factory()->count(2)->create();

        $plate = $this->actingAs($owner)
            ->postJson('/context/plates', ['title' => 'Работа', 'content' => 'Пишу на Laravel и Go.', 'tags' => ['Работа', 'IT']])
            ->assertCreated()
            ->json('plate');

        $this->actingAs($stranger);
        $this->getJson('/context/plates')->assertOk()->assertJsonCount(0, 'plates');
        $this->patchJson("/context/plates/{$plate['id']}", ['content' => 'Взлом'])->assertNotFound();
        $this->deleteJson("/context/plates/{$plate['id']}")->assertNotFound();
        $this->postJson('/text/messages', ['ai_model_ids' => [$model->id], 'content' => 'Кто я?', 'plate_ids' => [$plate['id']]])->assertCreated();

        $this->actingAs($owner);
        $this->postJson('/text/messages', ['ai_model_ids' => [$model->id], 'content' => 'Кто я?', 'plate_ids' => [$plate['id']]])
            ->assertCreated()
            ->assertJsonPath('user_message.context', [['plate_id' => $plate['id'], 'title' => 'Работа']]);

        [$foreign, $own] = array_map(fn ($job) => $job[1]['body']['messages'], $jobs());
        // Чужой картридж не найден по владельцу — в промпт ничего не ушло
        $this->assertSame([['role' => 'user', 'content' => 'Кто я?']], $foreign);
        $this->assertSame('system', $own[0]['role']);
        $this->assertStringContainsString('<cartridge title="Работа" tags="Работа, IT">'."\nПишу на Laravel и Go.\n</cartridge>", $own[0]['content']);

        $saved = ContextPlate::find($plate['id']);
        $this->assertSame('Пишу на Laravel и Go.', $saved->content);
        $this->assertSame(
            [['plate_id' => $saved->id, 'version_id' => $saved->current_version_id, 'title' => 'Работа']],
            $owner->messages()->where('role', 'user')->sole()->context,
        );
    }

    #[Group('D2')]
    public function test_edits_keep_history_and_tags_are_per_user(): void
    {
        $user = User::factory()->create();
        $plate = $this->actingAs($user)->postJson('/context/plates', ['title' => 'Здоровье', 'content' => 'v1'])->json('plate');

        // Каждое сохранение содержимого — версия; правка только тегов или закрепления — нет
        $this->patchJson("/context/plates/{$plate['id']}", ['content' => 'v2', 'tags' => ['спорт', ' Спорт ', '#здоровье']])->assertOk()
            ->assertJsonPath('plate.tags', ['спорт', 'здоровье']);
        $this->patchJson("/context/plates/{$plate['id']}", ['content' => 'v2', 'is_pinned' => true])->assertOk()->assertJsonPath('plate.versions', 2);
        $this->getJson('/context/plates')->assertOk()->assertJsonPath('plates.0.content', 'v2')->assertJsonPath('plates.0.is_pinned', true);

        $this->assertSame(['v1', 'v2'], ContextPlate::find($plate['id'])->versions()->orderBy('id')->pluck('content')->all());
        // Одноимённый тег другого юзера — свой, не общий
        $other = User::factory()->create();
        $this->actingAs($other)->postJson('/context/plates', ['title' => 'Бег', 'tags' => ['спорт']])->assertCreated();
        $this->assertSame([1, 1], [$user->tags()->where('slug', 'спорт')->count(), $other->tags()->where('slug', 'спорт')->count()]);
    }
}
