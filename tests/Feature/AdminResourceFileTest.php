<?php

namespace Tests\Feature;

use App\Enums\LandingPage;
use App\Filament\Resources\ResourceFiles\Pages\CreateResourceFile;
use App\Filament\Resources\ResourceFiles\Pages\ListResourceFiles;
use App\Filament\Resources\ResourceFiles\ResourceFileResource;
use App\Models\Permission;
use App\Models\PermissionGroup;
use App\Models\ResourceFile;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AdminResourceFileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake(ResourceFile::Disk);
    }

    public function test_authenticated_user_can_list_resources(): void
    {
        $user = User::factory()->admin()->create();
        $resource = $this->storedResource(['display_name' => 'Emergency lighting guide']);

        $this->actingAs($user);

        Livewire::test(ListResourceFiles::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$resource])
            ->assertSee('Emergency lighting guide')
            ->assertSee($resource->original_filename);
    }

    public function test_guest_cannot_access_resource_pages_or_files(): void
    {
        $resource = $this->storedResource();

        $this->get(ResourceFileResource::getUrl('index'))->assertRedirect();
        $this->get(route('resource-files.view', $resource))->assertRedirect();
    }

    public function test_user_without_resource_view_permission_cannot_access_page_or_file(): void
    {
        $user = $this->userWithResourcePermissions([]);
        $resource = $this->storedResource();

        $this->actingAs($user);

        Livewire::test(ListResourceFiles::class)->assertForbidden();
        $this->get(route('resource-files.view', $resource))->assertForbidden();
        $this->assertFalse(LandingPage::Resources->isAccessibleTo($user));
    }

    public function test_view_only_user_cannot_add_edit_or_delete_resources(): void
    {
        $user = $this->userWithResourcePermissions(['resources.view']);
        $resource = $this->storedResource();

        $this->actingAs($user);

        Livewire::test(ListResourceFiles::class)
            ->assertSuccessful()
            ->assertActionHidden(TestAction::make('edit')->table($resource))
            ->assertActionHidden(TestAction::make(DeleteAction::class)->table($resource));

        Livewire::test(CreateResourceFile::class)->assertForbidden();

        $this->assertFalse(ResourceFileResource::canCreate());
        $this->assertFalse(ResourceFileResource::canEdit($resource));
        $this->assertFalse(ResourceFileResource::canDelete($resource));
        $this->assertModelExists($resource);
        Storage::disk(ResourceFile::Disk)->assertExists($resource->file_path);
    }

    public function test_authenticated_user_can_upload_an_allowed_resource(): void
    {
        $user = User::factory()->admin()->create();
        $upload = UploadedFile::fake()->createWithContent('training-guide.pdf', "%PDF-1.4\nresource test");

        $this->actingAs($user);

        Livewire::test(CreateResourceFile::class)
            ->fillForm([
                'display_name' => 'Training guide',
                'file_path' => $upload,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $resource = ResourceFile::query()->sole();

        $this->assertSame('Training guide', $resource->display_name);
        $this->assertSame('training-guide.pdf', $resource->original_filename);
        $this->assertSame('pdf', $resource->extension);
        $this->assertSame($user->id, $resource->uploaded_by_id);
        $this->assertNotSame(ResourceFile::Directory.'/training-guide.pdf', $resource->file_path);
        $this->assertTrue(ResourceFile::isManagedFilePath($resource->file_path));
        Storage::disk(ResourceFile::Disk)->assertExists($resource->file_path);
    }

    public function test_executable_upload_is_rejected(): void
    {
        $user = User::factory()->admin()->create();
        $upload = UploadedFile::fake()->createWithContent('unsafe.php', '<?php echo "unsafe";');

        $this->actingAs($user);

        Livewire::test(CreateResourceFile::class)
            ->fillForm([
                'display_name' => 'Unsafe file',
                'file_path' => $upload,
            ])
            ->call('create')
            ->assertHasFormErrors(['file_path']);

        $this->assertDatabaseCount('resource_files', 0);
    }

    public function test_authenticated_user_can_only_edit_the_display_name(): void
    {
        $user = User::factory()->admin()->create();
        $resource = $this->storedResource();
        $originalPath = $resource->file_path;
        $originalFilename = $resource->original_filename;

        $this->actingAs($user);

        Livewire::test(ListResourceFiles::class)
            ->callAction(
                TestAction::make('edit')->table($resource),
                data: ['display_name' => 'Updated display name'],
            )
            ->assertHasNoFormErrors();

        $resource->refresh();

        $this->assertSame('Updated display name', $resource->display_name);
        $this->assertSame($originalPath, $resource->file_path);
        $this->assertSame($originalFilename, $resource->original_filename);
    }

    public function test_preview_opens_in_a_lightbox_modal(): void
    {
        $user = User::factory()->admin()->create();
        $resource = $this->storedResource([
            'display_name' => 'Safety guide',
            'original_filename' => 'safety-guide.pdf',
        ]);

        $this->actingAs($user);

        Livewire::test(ListResourceFiles::class)
            ->mountAction(TestAction::make('view')->table($resource))
            ->assertMountedActionModalSee('safety-guide.pdf')
            ->assertMountedActionModalSeeHtml('<iframe');
    }

    public function test_non_previewable_resource_shows_download_fallback_in_lightbox(): void
    {
        $user = User::factory()->admin()->create();
        $resource = $this->storedResource([
            'original_filename' => 'instructions.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'extension' => 'docx',
        ]);

        $this->actingAs($user);

        Livewire::test(ListResourceFiles::class)
            ->mountAction(TestAction::make('view')->table($resource))
            ->assertMountedActionModalSee('Preview unavailable')
            ->assertMountedActionModalSee('Download file');
    }

    public function test_authenticated_user_can_view_a_private_resource(): void
    {
        $user = User::factory()->admin()->create();
        $resource = $this->storedResource([
            'original_filename' => 'Safety Guide.pdf',
            'file_size' => strlen('private resource'),
        ], 'private resource');

        $response = $this->actingAs($user)->get(route('resource-files.view', $resource));

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('inline;', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('private resource', $response->streamedContent());
    }

    public function test_missing_private_resource_returns_not_found(): void
    {
        $user = User::factory()->admin()->create();
        $resource = ResourceFile::factory()->create();

        $this->actingAs($user)
            ->get(route('resource-files.view', $resource))
            ->assertNotFound();
    }

    public function test_deleting_a_resource_removes_its_private_file(): void
    {
        $user = User::factory()->admin()->create();
        $resource = $this->storedResource();
        $filePath = $resource->file_path;

        $this->actingAs($user);

        Livewire::test(ListResourceFiles::class)
            ->callAction(TestAction::make(DeleteAction::class)->table($resource));

        $this->assertModelMissing($resource);
        Storage::disk(ResourceFile::Disk)->assertMissing($filePath);
    }

    public function test_resources_are_available_as_a_group_landing_page(): void
    {
        $this->assertSame('Resources', LandingPage::Resources->label());
        $this->assertArrayHasKey(
            LandingPage::Resources->value,
            LandingPage::groupedOptions()['Admin'],
        );
        $this->assertFalse(LandingPage::Resources->isAccessibleTo(User::factory()->create()));
        $this->assertTrue(LandingPage::Resources->isAccessibleTo(User::factory()->admin()->create()));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function storedResource(array $attributes = [], string $contents = 'test resource'): ResourceFile
    {
        $resource = ResourceFile::factory()->create($attributes);
        Storage::disk(ResourceFile::Disk)->put($resource->file_path, $contents);

        return $resource;
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    private function userWithResourcePermissions(array $permissionKeys): User
    {
        $group = PermissionGroup::create([
            'name' => 'Custom resource access',
            'slug' => 'custom-resource-access-'.fake()->unique()->numerify('####'),
            'is_system' => false,
        ]);

        $group->permissions()->attach(
            Permission::query()->whereIn('key', $permissionKeys)->pluck('id'),
        );

        return User::factory()->create(['permission_group_id' => $group->id]);
    }
}
