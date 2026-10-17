<?php

namespace Illuminate\Tests\Database;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem as FilesystemContract;
use Illuminate\Database\Eloquent\Casts\AsImage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Image\Image;
use Illuminate\Image\ImageException;
use Illuminate\Image\ImageManager;
use Illuminate\Support\Facades\Facade;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\TestCase;

class DatabaseEloquentAsImageCastTest extends TestCase
{
    protected Container $container;

    /**
     * The mocked filesystem disks, keyed by name.
     *
     * @var array<string, \Illuminate\Contracts\Filesystem\Filesystem>
     */
    protected array $disks = [];

    protected function setUp(): void
    {
        $this->container = new Container;
        $this->disks = [];

        $this->container->instance('config', new Repository([
            'filesystems' => ['default' => 'local'],
        ]));

        $this->container->singleton('image', fn ($app) => new ImageManager($app));

        Container::setInstance($this->container);
        Facade::setFacadeApplication($this->container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        Mockery::close();
    }

    public function testNullAttributesAreNotCast()
    {
        $model = new AsImageTestModel;
        $model->setRawAttributes([
            'img_disk_folder' => null,
            'img_base64' => null,
            'img_bytes' => null,
        ]);

        $this->assertNull($model->img_disk_folder);
        $this->assertNull($model->img_base64);
        $this->assertNull($model->img_bytes);
    }

    public function testGetResolvesAnImageForEveryFormat()
    {
        $this->fakeDisk('s3')->shouldReceive('get')->andReturn($this->pngBytes());

        $model = new AsImageTestModel;
        $model->setRawAttributes([
            'img_disk_folder' => 'avatars/jane.webp',
            'img_base64' => base64_encode($this->pngBytes()),
            'img_bytes' => $this->pngBytes(),
        ]);

        $this->assertInstanceOf(Image::class, $model->img_disk_folder);
        $this->assertInstanceOf(Image::class, $model->img_base64);
        $this->assertInstanceOf(Image::class, $model->img_bytes);
    }

    public function testGetResolvesStorageImagesOnTheConfiguredDisk()
    {
        $this->fakeDisk('s3')
            ->shouldReceive('get')
            ->once()
            ->with('avatars/jane.webp')
            ->andReturn($this->pngBytes());

        $model = new AsImageTestModel;
        $model->setRawAttributes(['img_disk_folder' => 'avatars/jane.webp']);

        $this->assertSame($this->pngBytes(), $model->img_disk_folder->toBytes());
    }

    public function testBareCastResolvesStorageImagesOnTheDefaultDisk()
    {
        $this->fakeDisk(null)
            ->shouldReceive('get')
            ->once()
            ->with('icons/star.png')
            ->andReturn($this->pngBytes());

        $model = new AsImageTestModel;
        $model->setRawAttributes(['img_bare' => 'icons/star.png']);

        $this->assertSame($this->pngBytes(), $model->img_bare->toBytes());
    }

    public function testToStorageWithoutArgumentsUsesTheDefaultDisk()
    {
        $this->fakeDisk(null)
            ->shouldReceive('get')
            ->once()
            ->with('defaults/star.png')
            ->andReturn($this->pngBytes());

        $model = new AsImageTestModel;
        $model->setRawAttributes(['img_disk_default' => 'defaults/star.png']);

        $this->assertSame($this->pngBytes(), $model->img_disk_default->toBytes());
    }

    public function testToStorageStoresInTheConfiguredFolderOnTheDefaultDisk()
    {
        $this->fakeDisk(null)
            ->shouldReceive('put')
            ->once()
            ->withArgs(fn ($path) => (bool) preg_match('#^nested/[A-Za-z0-9]{40}\.png$#', $path))
            ->andReturn(true);

        $model = new AsImageTestModel;

        $model->img_folder = new Image($this->pngBytes());

        $this->assertMatchesRegularExpression(
            '#^nested/[A-Za-z0-9]{40}\.png$#', $model->getAttributes()['img_folder']
        );
    }

    public function testGetDecodesBase64AndBytes()
    {
        $model = new AsImageTestModel;
        $model->setRawAttributes([
            'img_base64' => base64_encode($this->pngBytes()),
            'img_bytes' => $this->pngBytes(),
        ]);

        $this->assertSame($this->pngBytes(), $model->img_base64->toBytes());
        $this->assertSame($this->pngBytes(), $model->img_bytes->toBytes());
    }

    public function testStorageAttributesDoNotFollowUrls()
    {
        $this->fakeDisk('s3')
            ->shouldReceive('get')
            ->once()
            ->with('https://example.com/logo.png')
            ->andReturn($this->pngBytes());

        $model = new AsImageTestModel;
        $model->setRawAttributes(['img_disk_folder' => 'https://example.com/logo.png']);

        $this->assertSame($this->pngBytes(), $model->img_disk_folder->toBytes());
    }

    public function testCastThrowsOnAnUnsupportedFormat()
    {
        $this->expectExceptionObject(new InvalidArgumentException(
            'Unsupported image cast format [foobar]. Allowed formats are: storage, base64, bytes.'
        ));

        $model = new AsImageTestModel;
        $model->setRawAttributes(['img_invalid' => 'whatever']);
        $model->img_invalid;
    }

    public function testSetWritesStringsVerbatim()
    {
        $model = new AsImageTestModel;

        $model->img_disk_folder = 'avatars/jane.webp';

        $this->assertSame('avatars/jane.webp', $model->getAttributes()['img_disk_folder']);
    }

    public function testSetWritesNullVerbatim()
    {
        $model = new AsImageTestModel;

        $model->img_disk_folder = null;

        $this->assertNull($model->getAttributes()['img_disk_folder']);
    }

    public function testSetEncodesImagesForBase64AndBytes()
    {
        $model = new AsImageTestModel;

        $model->img_base64 = new Image($this->pngBytes());
        $model->img_bytes = new Image($this->pngBytes());

        $this->assertSame(base64_encode($this->pngBytes()), $model->getAttributes()['img_base64']);
        $this->assertSame($this->pngBytes(), $model->getAttributes()['img_bytes']);
    }

    public function testSetStoresImagesAndPersistsThePath()
    {
        $this->fakeDisk('s3')
            ->shouldReceive('put')
            ->once()
            ->withArgs(fn ($path) => (bool) preg_match('#^avatars/[A-Za-z0-9]{40}\.png$#', $path))
            ->andReturn(true);

        $model = new AsImageTestModel;

        $model->img_disk_folder = new Image($this->pngBytes());

        $this->assertMatchesRegularExpression(
            '#^avatars/[A-Za-z0-9]{40}\.png$#', $model->getAttributes()['img_disk_folder']
        );
    }

    public function testStorageCastThrowsWhenTheDiskWriteFails()
    {
        $this->fakeDisk('s3')
            ->shouldReceive('put')
            ->once()
            ->andReturn(false);

        $this->expectExceptionObject(new ImageException(
            'Unable to store the image for the [img_disk_folder] attribute.'
        ));

        $model = new AsImageTestModel;
        $model->img_disk_folder = new Image($this->pngBytes());
    }

    public function testReadingAnAttributeDoesNotRewriteItOnSave()
    {
        $this->fakeDisk('s3')->shouldReceive('get')->andReturn($this->pngBytes());
        $this->fakeDisk('s3')->shouldNotReceive('put');

        $model = new AsImageTestModel;
        $model->setRawAttributes(['img_disk_folder' => 'avatars/jane.webp']);

        $this->assertInstanceOf(Image::class, $model->img_disk_folder);

        // getAttributes() flushes cached class casts back through set()...
        $this->assertSame('avatars/jane.webp', $model->getAttributes()['img_disk_folder']);
    }

    public function testToArrayReturnsTheRawValue()
    {
        $model = new AsImageTestModel;
        $model->setRawAttributes(['img_disk_folder' => 'avatars/jane.webp']);

        $this->assertSame('avatars/jane.webp', $model->toArray()['img_disk_folder']);
    }

    /**
     * Bind a mocked disk onto the container and get it back for expectations.
     */
    protected function fakeDisk(?string $name): FilesystemContract|Mockery\MockInterface
    {
        if ($this->disks === []) {
            $factory = Mockery::mock(FilesystemFactory::class);

            $factory->shouldReceive('disk')->andReturnUsing(
                fn ($disk = null) => $this->disks[$disk ?? ''] ??= Mockery::mock(FilesystemContract::class)
            );

            $this->container->instance(FilesystemFactory::class, $factory);
        }

        return $this->disks[$name ?? ''] ??= Mockery::mock(FilesystemContract::class);
    }

    /**
     * Get the contents of a tiny valid PNG.
     */
    protected function pngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );
    }
}

class AsImageTestModel extends Model
{
    protected $guarded = [];

    public function casts(): array
    {
        return [
            'img_bare' => AsImage::class,
            'img_disk_default' => AsImage::storage(),
            'img_folder' => AsImage::storage(folder: 'nested'),
            'img_disk_folder' => AsImage::storage('s3', 'avatars'),
            'img_base64' => AsImage::base64(),
            'img_bytes' => AsImage::bytes(),
            'img_invalid' => AsImage::class.':foobar',
        ];
    }
}
