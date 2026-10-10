<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TicketStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class TicketAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_upload_or_download(): void
    {
        $this->postJson('/api/tickets/1/attachments')->assertUnauthorized();
        $this->getJson('/api/tickets/1/attachments/1')->assertUnauthorized();
    }

    public function test_authorized_upload_stores_private_file_with_random_name(): void
    {
        Storage::fake('attachments');
        $user = User::factory()->create();
        $this->mock(TicketStore::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('call')->with('TicketShow', [$user->id, 7])->once()->andReturn([(object) ['id' => 7]]);
            $mock->shouldReceive('call')->withArgs(function ($name, $args) use ($user) {
                return $name === 'TicketAttachmentAdd' && $args[0] === $user->id && $args[1] === 7
                    && $args[2] === 'notes.txt' && str_starts_with($args[3], 'tickets/7/')
                    && ! str_contains($args[3], 'notes.txt') && $args[4] === 'text/plain';
            })->once()->andReturn([(object) ['id' => 1]]);
        });
        $this->actingAs($user)->post('/api/tickets/7/attachments', [
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'Ticket attachment contents'),
        ])->assertCreated();
        $this->assertCount(1, Storage::disk('attachments')->allFiles());
    }

    public function test_unauthorized_upload_writes_no_file(): void
    {
        Storage::fake('attachments');
        $this->mock(TicketStore::class, function (MockInterface $mock) {
            $mock->shouldReceive('call')->with('TicketShow', \Mockery::any())->andThrow(new NotFoundHttpException);
        });
        $this->actingAs(User::factory()->create())->post('/api/tickets/7/attachments', [
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'Not permitted'),
        ])->assertNotFound();
        $this->assertCount(0, Storage::disk('attachments')->allFiles());
    }

    public function test_failed_metadata_write_removes_uploaded_file(): void
    {
        Storage::fake('attachments');
        $this->mock(TicketStore::class, function (MockInterface $mock) {
            $mock->shouldReceive('call')->with('TicketShow', \Mockery::any())->andReturn([(object) ['id' => 7]]);
            $mock->shouldReceive('call')->with('TicketAttachmentAdd', \Mockery::any())->andThrow(new NotFoundHttpException);
        });
        $this->actingAs(User::factory()->create())->post('/api/tickets/7/attachments', [
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'Temporary bytes'),
        ])->assertNotFound();
        $this->assertCount(0, Storage::disk('attachments')->allFiles());
    }

    public function test_disallowed_and_oversized_files_are_rejected(): void
    {
        Storage::fake('attachments');
        $this->mock(TicketStore::class, function (MockInterface $mock) {
            $mock->shouldReceive('call')->with('TicketShow', \Mockery::any())->andReturn([(object) ['id' => 7]]);
            $mock->shouldNotReceive('call')->with('TicketAttachmentAdd', \Mockery::any());
        });
        $this->actingAs(User::factory()->create());
        $spoofed = UploadedFile::fake()->createWithContent('spoofed.png', '<html>Not an image</html>');
        foreach ([
            UploadedFile::fake()->createWithContent('payload.php', '<?php echo "unsafe";'),
            new UploadedFile($spoofed->getPathname(), 'spoofed.png', 'image/png', null, true),
            UploadedFile::fake()->create('large.txt', 2049, 'text/plain'),
        ] as $file) {
            $this->post('/api/tickets/7/attachments', ['file' => $file])->assertJsonValidationErrors('file');
        }
        $this->assertCount(0, Storage::disk('attachments')->allFiles());
    }

    public function test_download_is_authorized_and_forced_as_private_attachment(): void
    {
        Storage::fake('attachments');
        Storage::disk('attachments')->put('tickets/7/random', 'Private bytes');
        $user = User::factory()->create();
        $this->mock(TicketStore::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('call')->with('TicketAttachmentGet', [$user->id, 7, 9])->once()
                ->andReturn([(object) ['path' => 'tickets/7/random', 'filename' => 'notes.txt']]);
        });
        $this->actingAs($user)->get('/api/tickets/7/attachments/9')
            ->assertDownload('notes.txt')->assertHeader('Content-Type', 'application/octet-stream')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private')
            ->assertStreamedContent('Private bytes');
    }

    public function test_unauthorized_download_returns_not_found(): void
    {
        $this->mock(TicketStore::class, fn (MockInterface $mock) => $mock->shouldReceive('call')
            ->with('TicketAttachmentGet', \Mockery::any())->andThrow(new NotFoundHttpException));
        $this->actingAs(User::factory()->create())->get('/api/tickets/7/attachments/9')->assertNotFound();
    }
}
