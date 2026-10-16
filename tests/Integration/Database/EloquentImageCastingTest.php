<?php

namespace Illuminate\Tests\Integration\Database\EloquentImageCastingTest;

use Illuminate\Database\Eloquent\Casts\AsImage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Image\Image as ImageInstance;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Tests\Integration\Database\DatabaseTestCase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

#[RequiresPhpExtension('gd')]
class EloquentImageCastingTest extends DatabaseTestCase
{
    /** {@inheritdoc} */
    protected function afterRefreshingDatabase()
    {
        Schema::create('image_models', function (Blueprint $table) {
            $table->increments('id');
            $table->string('avatar')->nullable();
            $table->text('thumbnail')->nullable();
        });
    }

    public function testAssigningAnImageStoresItAndPersistsThePath()
    {
        Storage::fake('images');

        $model = ImageModel::create([
            'avatar' => Image::fromUpload($this->fakeUpload()),
        ]);

        $path = $model->getRawOriginal('avatar');

        $this->assertMatchesRegularExpression('#^avatars/[A-Za-z0-9]{40}\.(png|jpg)$#', $path);

        Storage::disk('images')->assertExists($path);
    }

    public function testStoredImagesAreReadBackFromTheDisk()
    {
        Storage::fake('images');

        $model = ImageModel::create([
            'avatar' => Image::fromUpload($this->fakeUpload(200, 200)),
        ]);

        $image = ImageModel::findOrFail($model->id)->avatar;

        $this->assertInstanceOf(ImageInstance::class, $image);

        [$width, $height] = getimagesizefromstring($image->cover(100, 100)->toBytes());

        $this->assertSame(100, $width);
        $this->assertSame(100, $height);
    }

    public function testReadingAnAttributeDoesNotStoreASecondFile()
    {
        Storage::fake('images');

        $model = ImageModel::create([
            'avatar' => Image::fromUpload($this->fakeUpload()),
        ]);

        $this->assertCount(1, Storage::disk('images')->allFiles());

        $fresh = ImageModel::findOrFail($model->id);
        $path = $fresh->getRawOriginal('avatar');

        $this->assertInstanceOf(ImageInstance::class, $fresh->avatar);

        $fresh->save();

        $this->assertCount(1, Storage::disk('images')->allFiles());
        $this->assertSame($path, $fresh->fresh()->getRawOriginal('avatar'));
    }

    public function testTransformedImagesAreStoredAsNewFiles()
    {
        Storage::fake('images');

        $model = ImageModel::create([
            'avatar' => Image::fromUpload($this->fakeUpload(200, 200)),
        ]);

        $original = $model->getRawOriginal('avatar');

        $model->avatar = $model->avatar->cover(50, 50);
        $model->save();

        $this->assertNotSame($original, $model->getRawOriginal('avatar'));
        $this->assertCount(2, Storage::disk('images')->allFiles());

        [$width] = getimagesizefromstring(
            Storage::disk('images')->get($model->getRawOriginal('avatar'))
        );

        $this->assertSame(50, $width);
    }

    public function testBase64AttributesRoundTrip()
    {
        $image = Image::fromUpload($this->fakeUpload());
        $bytes = $image->toBytes();

        $model = ImageModel::create(['thumbnail' => $image]);

        $this->assertSame(base64_encode($bytes), $model->getRawOriginal('thumbnail'));
        $this->assertSame($bytes, ImageModel::findOrFail($model->id)->thumbnail->toBytes());
    }

    public function testStringsAreWrittenVerbatim()
    {
        Storage::fake('images');

        $model = ImageModel::create(['avatar' => 'avatars/jane.webp']);

        $this->assertSame('avatars/jane.webp', $model->getRawOriginal('avatar'));
        $this->assertEmpty(Storage::disk('images')->allFiles());
    }

    public function testNullAttributesAreNotCast()
    {
        $model = ImageModel::create(['avatar' => null]);

        $this->assertNull($model->avatar);
        $this->assertNull($model->toArray()['avatar']);
    }

    public function testToArrayReturnsTheRawValue()
    {
        Storage::fake('images');

        $model = ImageModel::create([
            'avatar' => Image::fromUpload($this->fakeUpload()),
        ]);

        $this->assertSame($model->getRawOriginal('avatar'), $model->toArray()['avatar']);
    }

    /**
     * Get a fake uploaded image file.
     */
    protected function fakeUpload(int $width = 100, int $height = 100): UploadedFile
    {
        return UploadedFile::fake()->image('test.png', $width, $height);
    }
}

class ImageModel extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    public function casts(): array
    {
        return [
            'avatar' => AsImage::storage('images', 'avatars'),
            'thumbnail' => AsImage::base64(),
        ];
    }
}
