<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\User;
use App\Models\UserFile;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CapturesStreamJobs;
use Tests\TestCase;

class FilesTest extends TestCase
{
    use CapturesStreamJobs, RefreshDatabase;

    protected string $seeder = DatabaseSeeder::class;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
    }

    #[Group('D1')]
    #[TestDox('Чужой файл не виден в галерее, не открывается и не прикрепляется к вопросу; одинаковые файлы разных юзеров не склеиваются')]
    public function test_foreign_files_are_isolated(): void
    {
        $owner = User::factory()->create();
        $id = $this->actingAs($owner)->post('/files', ['file' => $this->screenshot()])->assertCreated()->json('file.id');

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->getJson('/files')->assertOk()->assertJsonCount(0, 'files');
        $this->get("/files/{$id}")->assertNotFound();
        $this->get("/files/{$id}/thumb")->assertNotFound();

        $jobs = $this->captureStreamJobs();
        $this->postJson('/text/messages', [
            'ai_model_ids' => [AiModel::where('slug', 'claude-sonnet-5-5')->value('id')],
            'content' => 'Что тут?',
            'file_ids' => [$id],
        ])->assertUnprocessable();
        $this->assertSame([], $jobs());
        $this->assertSame(0, $stranger->chats()->count());

        // Тот же файл у другого юзера — своя копия, а не ссылка на чужую
        $own = $this->post('/files', ['file' => $this->screenshot()])->assertCreated()->json('file.id');
        $this->assertNotSame($id, $own);
        $this->assertSame([$owner->id, $stranger->id], UserFile::orderBy('created_at')->pluck('user_id')->all());
    }

    #[Group('D1')]
    #[TestDox('Картинка и PDF из вопроса уходят модели частями её формата и сохраняются с вопросом; повторная загрузка не плодит копий')]
    public function test_attached_files_reach_the_model(): void
    {
        $this->actingAs($user = User::factory()->create());
        $image = $this->post('/files', ['file' => $this->screenshot()])->assertCreated()
            ->assertJsonPath('file.kind', 'image')
            ->assertJsonPath('file.width', 800)
            ->json('file');
        $this->assertNotNull($image['thumb_url']);
        // Тот же файл ещё раз — тот же id, без новой записи
        $this->post('/files', ['file' => $this->screenshot()])->assertOk()->assertJsonPath('file.id', $image['id']);
        $pdf = $this->post('/files', ['file' => UploadedFile::fake()->createWithContent('Договор.pdf', "%PDF-1.4\n%%EOF")])->assertCreated()->json('file');

        $jobs = $this->captureStreamJobs();
        $this->postJson('/text/messages', [
            'ai_model_ids' => [AiModel::where('slug', 'claude-sonnet-5-5')->value('id')],
            'content' => '',
            'file_ids' => [$image['id'], $pdf['id']],
        ])->assertCreated()->assertJsonPath('user_message.files.1.name', 'Договор.pdf');

        $content = $jobs()[0][1]['body']['messages'][0]['content'];
        $this->assertSame(['image_url', 'file'], array_column($content, 'type'));
        $this->assertStringStartsWith('data:image/png;base64,', $content[0]['image_url']['url']);
        $this->assertSame('Договор.pdf', $content[1]['file']['filename']);
        // Вопрос без подписи — чат назван по файлам
        $this->assertSame('shot.png, Договор.pdf', $user->chats()->sole()->title);
        $this->assertSame([$image['id'], $pdf['id']], $user->chats()->sole()->messages()->first()->files->pluck('id')->all());
        $this->assertSame(2, $user->files()->count());
    }

    #[Group('D2')]
    public function test_unsupported_and_oversized_files_are_rejected(): void
    {
        config(['files.max_kb' => 50]);
        $this->actingAs(User::factory()->create());

        $this->post('/files', ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'текст')], ['Accept' => 'application/json'])->assertJsonValidationErrors('file');
        $this->post('/files', ['file' => UploadedFile::fake()->image('big.png')->size(60)], ['Accept' => 'application/json'])->assertJsonValidationErrors('file');
    }

    private function screenshot(): UploadedFile
    {
        // Одинаковые байты при каждом вызове — для проверки дедупликации
        $image = imagecreatetruecolor(800, 600);
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return UploadedFile::fake()->createWithContent('shot.png', (string) ob_get_clean());
    }
}
