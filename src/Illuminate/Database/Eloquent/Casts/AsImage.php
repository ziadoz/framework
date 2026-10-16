<?php

namespace Illuminate\Database\Eloquent\Casts;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Image\Image;
use Illuminate\Image\ImageException;
use Illuminate\Support\Facades\Image as ImageFacade;
use InvalidArgumentException;

class AsImage implements Castable
{
    /**
     * Get the caster class to use when casting from / to this cast target.
     *
     * @param  array{0?: string, 1?: string, 2?: string}  $arguments
     * @return \Illuminate\Contracts\Database\Eloquent\CastsAttributes<\Illuminate\Image\Image, \Illuminate\Image\Image|string>
     *
     * @throws \InvalidArgumentException
     */
    public static function castUsing(array $arguments)
    {
        return new class($arguments) implements CastsAttributes, SerializesCastableAttributes
        {
            /**
             * Indicates that the resolved image should not be cached on the model.
             */
            public bool $withoutObjectCaching = true;

            /**
             * The format the attribute is stored in.
             */
            protected string $format;

            /**
             * The disk the image is stored on.
             */
            protected ?string $disk;

            /**
             * The folder the image is stored in.
             */
            protected ?string $folder;

            /**
             * Create a new caster instance.
             *
             * @param  array{0?: string, 1?: string, 2?: string}  $arguments
             *
             * @throws \InvalidArgumentException
             */
            public function __construct(array $arguments)
            {
                $this->format = ($arguments[0] ?? '') ?: 'storage';
                $this->disk = ($arguments[1] ?? '') ?: null;
                $this->folder = ($arguments[2] ?? '') ?: null;

                if (! in_array($this->format, ['storage', 'base64', 'bytes'], true)) {
                    throw new InvalidArgumentException(
                        "Unsupported image cast format [{$this->format}]. Allowed formats are: storage, base64, bytes."
                    );
                }
            }

            public function get($model, $key, $value, $attributes)
            {
                if (! isset($value)) {
                    return null;
                }

                return match ($this->format) {
                    'storage' => ImageFacade::fromStorage($value, $this->disk),
                    'base64' => ImageFacade::fromBase64($value),
                    'bytes' => ImageFacade::fromBytes($value),
                };
            }

            public function set($model, $key, $value, $attributes)
            {
                if (! $value instanceof Image) {
                    return $value;
                }

                return match ($this->format) {
                    'storage' => $this->store($value, $key),
                    'base64' => $value->toBase64(),
                    'bytes' => $value->toBytes(),
                };
            }

            public function serialize($model, $key, $value, $attributes)
            {
                return $attributes[$key] ?? null;
            }

            /**
             * Store the image on the configured disk and get its path.
             *
             * @throws \Illuminate\Image\ImageException
             */
            protected function store(Image $image, string $key): string
            {
                $path = $image->store($this->folder ?? '', $this->disk);

                if ($path === false) {
                    throw new ImageException("Unable to store the image for the [{$key}] attribute.");
                }

                return $path;
            }
        };
    }

    /**
     * Store the image on a filesystem disk and persist its path.
     */
    public static function storage(?string $disk = null, ?string $folder = null): string
    {
        return static::class.':storage,'.$disk.','.$folder;
    }

    /**
     * Store the image as a base64 encoded string.
     */
    public static function base64(): string
    {
        return static::class.':base64';
    }

    /**
     * Store the image as raw bytes.
     */
    public static function bytes(): string
    {
        return static::class.':bytes';
    }
}
