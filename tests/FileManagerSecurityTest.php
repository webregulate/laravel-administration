<?php

namespace WebRegulate\LaravelAdministration\Tests;

use Illuminate\Config\Repository;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportAttributes\AttributeLevel;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Mechanisms\DataStore;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\HttpKernel\Exception\HttpException;
use WebRegulate\LaravelAdministration\Livewire\FileManager;
use WebRegulate\LaravelAdministration\Tests\Fixtures\RuntimeSettings;

class FileManagerSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RuntimeSettings::useForApplication();
        RuntimeSettings::set(['file_manager.enabled' => true]);

        $path = getenv('WRLA_TEST_APP_PATH');
        if (! $path) {
            $this->markTestSkipped('Set WRLA_TEST_APP_PATH to a Laravel application with WRLA dependencies installed.');
        }

        $loader = require $path.'/vendor/autoload.php';
        $loader->addPsr4('WebRegulate\\LaravelAdministration\\', dirname(__DIR__).'/src', true);
        $loader->addClassMap([
            FileManager::class => dirname(__DIR__).'/src/Livewire/FileManager.php',
            \WebRegulate\LaravelAdministration\Livewire\WRLAPageComponent::class => dirname(__DIR__).'/src/Livewire/WRLAPageComponent.php',
        ]);
        $app = new Application($path);
        $app->instance('config', new Repository([
            'wrla' => [
                'file_manager' => [
                    'file_systems' => ['public' => ['enabled' => true], 'local' => ['enabled' => false]],
                    'can_upload_mime_types' => ['image/png'],
                ],
            ],
        ]));
        $app->singleton(DataStore::class);
        $app->register(FilesystemServiceProvider::class);
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    private function manager(): FileManager
    {
        $manager = new class extends FileManager
        {
            public function refresh() {}

            public function viewFile(string $directoryPath, ?string $filePath)
            {
                if ($filePath === 'marker.txt') {
                    parent::viewFile($directoryPath, $filePath);
                }
            }
        };
        $manager->currentFileSystemName = 'public';
        $manager->setErrorBag([]);

        return $manager;
    }

    public function test_disk_selection_is_locked_against_client_updates(): void
    {
        $property = new ReflectionProperty(FileManager::class, 'currentFileSystemName');
        $attributes = $property->getAttributes(Locked::class);
        $this->assertCount(1, $attributes);
        $lock = $attributes[0]->newInstance();
        $lock->__boot($this->manager(), AttributeLevel::PROPERTY, 'currentFileSystemName');

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $lock->update();
    }

    #[DataProvider('deniedAccess')]
    public function test_every_file_operation_rechecks_access(string $disk, bool $enabled, string $operation): void
    {
        RuntimeSettings::set(['file_manager.enabled' => $enabled]);
        Storage::shouldReceive('disk')->never();
        $manager = $this->manager();
        $manager->currentFileSystemName = $disk;

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage($enabled ? 'File system not available' : 'Access to file manager permission denied.');
        match ($operation) {
            'read' => $manager->viewFile('', 'marker.txt'),
            'delete' => $manager->deleteFile('', 'marker.txt'),
            'deleteDirectory' => $manager->deleteDirectory('', 'folder'),
            'createDirectory' => $manager->createDirectory('folder'),
            'upload' => $this->upload($manager, false),
            'replace' => $this->upload($manager, true),
        };
    }

    public static function deniedAccess(): array
    {
        $cases = [];
        foreach ([['local', true], ['public', false], ['', true]] as [$disk, $enabled]) {
            foreach (['read', 'delete', 'deleteDirectory', 'createDirectory', 'upload', 'replace'] as $operation) {
                $cases[] = [$disk, $enabled, $operation];
            }
        }

        return $cases;
    }

    private function upload(FileManager $manager, bool $replace): void
    {
        $temporary = UploadedFile::fake()->createWithContent('image.png', $this->png());
        $file = new UploadedFile($temporary->getPathname(), 'image.png', null, null, true);
        if ($replace) {
            $manager->replaceFilePath = 'image.png';
            $manager->updatedReplaceFile($file);
        } else {
            $manager->uploadFilePath = '';
            $manager->updatedUploadFile($file);
        }
    }

    public function test_server_side_disk_switch_still_works(): void
    {
        config(['wrla.file_manager.file_systems.local.enabled' => true]);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('directories')->once()->with('')->andReturn([]);
        $disk->shouldReceive('files')->once()->with('')->andReturn([]);
        Storage::shouldReceive('disk')->twice()->with('local')->andReturn($disk);
        $manager = $this->manager();

        $manager->switchFileSystem('local');

        $this->assertSame('local', $manager->currentFileSystemName);
    }

    public function test_disk_overview_refresh_does_not_resolve_a_default_disk(): void
    {
        Storage::shouldReceive('disk')->never();
        $manager = new FileManager();
        $manager->switchFileSystem('');

        $manager->refresh();

        $this->assertSame('', $manager->currentFileSystemName);
        $this->assertSame([], $manager->currentFiles);
        $this->assertSame([], $manager->currentDirectories);
    }

    #[DataProvider('invalidReplacements')]
    public function test_replacement_rejects_disallowed_content_and_extensions(string $source, string $destination, string $content): void
    {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldNotReceive('put');
        Storage::shouldReceive('disk')->once()->with('public')->andReturn($disk);
        $manager = $this->manager();
        $manager->replaceFilePath = $destination;
        $temporary = UploadedFile::fake()->createWithContent($source, $content === 'png' ? $this->png() : $content);
        $file = new UploadedFile($temporary->getPathname(), $source, null, null, true);

        $manager->updatedReplaceFile($file);

        $this->assertTrue($manager->getErrorBag()->has('replaceFile'));
    }

    public static function invalidReplacements(): array
    {
        return [
            ['image.png', 'image.png', '<html><body>marker</body></html>'],
            ['image.png', 'image.html', 'png'],
            ['image.png', 'image.php', 'png'],
            ['image.php', 'image.png', 'png'],
        ];
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aL1sAAAAASUVORK5CYII=');
    }

    public function test_replacement_requires_an_existing_target(): void
    {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('fileExists')->once()->with('/image.png')->andReturn(false);
        $disk->shouldNotReceive('put');
        Storage::shouldReceive('disk')->once()->with('public')->andReturn($disk);
        $manager = $this->manager();

        $this->upload($manager, true);

        $this->assertTrue($manager->getErrorBag()->has('replaceFile'));
    }

    public function test_valid_image_replacement_succeeds(): void
    {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('fileExists')->once()->with('/image.png')->andReturn(true);
        $disk->shouldReceive('put')->once()->with('/image.png', $this->png())->andReturn(true);
        Storage::shouldReceive('disk')->once()->with('public')->andReturn($disk);
        $manager = $this->manager();

        $this->upload($manager, true);

        $this->assertNull($manager->replaceFilePath);
        $this->assertFalse($manager->getErrorBag()->any());
    }

    public function test_valid_new_upload_succeeds(): void
    {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->with('/image.png', $this->png())->andReturn(true);
        Storage::shouldReceive('disk')->once()->with('public')->andReturn($disk);
        $manager = $this->manager();

        $this->upload($manager, false);

        $this->assertNull($manager->uploadFilePath);
        $this->assertFalse($manager->getErrorBag()->any());
    }
}
