<?php

namespace App\Services\Images;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

class ImageProcessor
{
    private ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(Driver::class);
    }

    /**
     * Square product photo: optional crop rectangle in source pixels, then 600×600 WebP.
     *
     * @param  array{x: int, y: int, width: int, height: int}|null  $crop
     */
    public function productPhoto(UploadedFile $file, ?array $crop = null): string
    {
        $image = $this->manager->decodePath($file->getRealPath())->orient();

        if ($crop !== null && $crop['width'] > 10 && $crop['height'] > 10) {
            $image->crop($crop['width'], $crop['height'], $crop['x'], $crop['y']);
        }

        $image->cover(600, 600);
        $path = 'products/'.Str::uuid().'.webp';
        Storage::disk('public')->put($path, $image->encode(new WebpEncoder(quality: 82))->toString());

        return $path;
    }

    public function logo(UploadedFile $file): string
    {
        $image = $this->manager->decodePath($file->getRealPath())->orient()->scaleDown(576, 300);
        $path = 'branding/logo-'.Str::random(8).'.png';
        Storage::disk('public')->put($path, (string) $image->encodeUsingFileExtension('png'));

        return $path;
    }

    /**
     * Supplier invoice photo already cropped/enhanced in the browser: normalise orientation
     * and size, keep it as JPEG on the private disk.
     */
    public function document(UploadedFile $file, string $directory): string
    {
        $image = $this->manager->decodePath($file->getRealPath())->orient()->scaleDown(2400, 2400);
        $path = $directory.'/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, $image->encode(new JpegEncoder(quality: 85))->toString());

        return $path;
    }
}
