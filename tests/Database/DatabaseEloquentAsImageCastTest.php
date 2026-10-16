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
            'avatar' => null,
            'thumbnail' => null,
            'blob' => null,
        ]);

        $this->assertNull($model->avatar);
        $this->assertNull($model->thumbnail);
        $this->assertNull($model->blob);
    }

    public function testGetResolvesAnImageForEveryFormat()
    {
        $this->fakeDisk('s3')->shouldReceive('get')->andReturn($this->pngBytes());

        $model = new AsImageTestModel;
        $model->setRawAttributes([
            'avatar' => 'avatars/jane.webp',
            'thumbnail' => base64_encode($this->pngBytes()),
            'blob' => $this->pngBytes(),
        ]);

        $this->assertInstanceOf(Image::class, $model->avatar);
        $this->assertInstanceOf(Image::class, $model->thumbnail);
        $this->assertInstanceOf(Image::class, $model->blob);
    }

    public function testGetResolvesStorageImagesOnTheConfiguredDisk()
    {
        $this->fakeDisk('s3')
            ->shouldReceive('get')
            ->once()
            ->with('avatars/jane.webp')
            ->andReturn($this->pngBytes());

        $model = new AsImageTestModel;
        $model->setRawAttributes(['avatar' => 'avatars/jane.webp']);

        $this->assertSame($this->pngBytes(), $model->avatar->toBytes());
    }

    public function testBareCastResolvesStorageImagesOnTheDefaultDisk()
    {
        $this->fakeDisk(null)
            ->shouldReceive('get')
            ->once()
            ->with('icons/star.png')
            ->andReturn($this->pngBytes());

        $model = new AsImageTestModel;
        $model->setRawAttributes(['icon' => 'icons/star.png']);

        $this->assertSame($this->pngBytes(), $model->icon->toBytes());
    }

    public function testToStorageWithoutArgumentsUsesTheDefaultDisk()
    {
        $this->fakeDisk(null)
            ->shouldReceive('get')
            ->once()
            ->with('defaults/star.png')
            ->andReturn($this->pngBytes());

        $model = new AsImageTestModel;
        $model->setRawAttributes(['defaultDisk' => 'defaults/star.png']);

        $this->assertSame($this->pngBytes(), $model->defaultDisk->toBytes());
    }

    public function testToStorageStoresInTheConfiguredFolderOnTheDefaultDisk()
    {
        $this->fakeDisk(null)
            ->shouldReceive('put')
            ->once()
            ->withArgs(fn ($path) => (bool) preg_match('#^nested/[A-Za-z0-9]{40}\.png$#', $path))
            ->andReturn(true);

        $model = new AsImageTestModel;

        $model->folderOnly = new Image($this->pngBytes());

        $this->assertMatchesRegularExpression(
            '#^nested/[A-Za-z0-9]{40}\.png$#', $model->getAttributes()['folderOnly']
        );
    }

    public function testGetDecodesBase64AndBytes()
    {
        $model = new AsImageTestModel;
        $model->setRawAttributes([
            'thumbnail' => base64_encode($this->pngBytes()),
            'blob' => $this->pngBytes(),
        ]);

        $this->assertSame($this->pngBytes(), $model->thumbnail->toBytes());
        $this->assertSame($this->pngBytes(), $model->blob->toBytes());
    }

    public function testStorageAttributesDoNotFollowUrls()
    {
        $this->fakeDisk('s3')
            ->shouldReceive('get')
            ->once()
            ->with('https://example.com/logo.png')
            ->andReturn($this->pngBytes());

        $model = new AsImageTestModel;
        $model->setRawAttributes(['avatar' => 'https://example.com/logo.png']);

        $this->assertSame($this->pngBytes(), $model->avatar->toBytes());
    }

    public function testCastThrowsOnAnUnsupportedFormat()
    {
        $this->expectExceptionObject(new InvalidArgumentException(
            'Unsupported image cast format [nonsense]. Allowed formats are: storage, base64, bytes.'
        ));

        $model = new AsImageTestModel;
        $model->setRawAttributes(['unsupported' => 'whatever']);
        $model->unsupported;
    }

    public function testSetWritesStringsVerbatim()
    {
        $model = new AsImageTestModel;

        $model->avatar = 'avatars/jane.webp';

        $this->assertSame('avatars/jane.webp', $model->getAttributes()['avatar']);
    }

    public function testSetWritesNullVerbatim()
    {
        $model = new AsImageTestModel;

        $model->avatar = null;

        $this->assertNull($model->getAttributes()['avatar']);
    }

    public function testSetEncodesImagesForBase64AndBytes()
    {
        $model = new AsImageTestModel;

        $model->thumbnail = new Image($this->pngBytes());
        $model->blob = new Image($this->pngBytes());

        $this->assertSame(base64_encode($this->pngBytes()), $model->getAttributes()['thumbnail']);
        $this->assertSame($this->pngBytes(), $model->getAttributes()['blob']);
    }

    public function testSetStoresImagesAndPersistsThePath()
    {
        $this->fakeDisk('s3')
            ->shouldReceive('put')
            ->once()
            ->withArgs(fn ($path) => (bool) preg_match('#^avatars/[A-Za-z0-9]{40}\.png$#', $path))
            ->andReturn(true);

        $model = new AsImageTestModel;

        $model->avatar = new Image($this->pngBytes());

        $this->assertMatchesRegularExpression(
            '#^avatars/[A-Za-z0-9]{40}\.png$#', $model->getAttributes()['avatar']
        );
    }

    public function testStorageCastThrowsWhenTheDiskWriteFails()
    {
        $this->fakeDisk('s3')
            ->shouldReceive('put')
            ->once()
            ->andReturn(false);

        $this->expectExceptionObject(new ImageException(
            'Unable to store the image for the [avatar] attribute.'
        ));

        $model = new AsImageTestModel;
        $model->avatar = new Image($this->pngBytes());
    }

    public function testReadingAnAttributeDoesNotRewriteItOnSave()
    {
        $this->fakeDisk('s3')->shouldReceive('get')->andReturn($this->pngBytes());
        $this->fakeDisk('s3')->shouldNotReceive('put');

        $model = new AsImageTestModel;
        $model->setRawAttributes(['avatar' => 'avatars/jane.webp']);

        $this->assertInstanceOf(Image::class, $model->avatar);

        // getAttributes() flushes cached class casts back through set()...
        $this->assertSame('avatars/jane.webp', $model->getAttributes()['avatar']);
    }

    public function testToArrayReturnsTheRawValue()
    {
        $model = new AsImageTestModel;
        $model->setRawAttributes(['avatar' => 'avatars/jane.webp']);

        $this->assertSame('avatars/jane.webp', $model->toArray()['avatar']);
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
            'avatar' => AsImage::storage('s3', 'avatars'),
            'icon' => AsImage::class,
            'defaultDisk' => AsImage::storage(),
            'folderOnly' => AsImage::storage(folder: 'nested'),
            'thumbnail' => AsImage::base64(),
            'blob' => AsImage::bytes(),
            'unsupported' => AsImage::class.':nonsense',
        ];
    }
}
