<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Resizes and converts uploaded images to WebP using PHP-GD (no Composer dep,
 * works on shared cPanel). Falls back to storing the original if GD/WebP is
 * unavailable or the source isn't a supported raster image.
 */
class ImageOptimizer
{
    /**
     * Store an uploaded image as an optimized WebP on the public disk.
     * Returns the stored relative path.
     */
    public function storeWebp(UploadedFile $file, string $dir = 'products', int $maxWidth = 1600, int $quality = 82): string
    {
        if (! $this->canConvert()) {
            return $file->store($dir, 'public');
        }

        try {
            $src = @imagecreatefromstring(file_get_contents($file->getRealPath()));
            if ($src === false) {
                return $file->store($dir, 'public');
            }

            $src = $this->downscale($src, $maxWidth);

            // Flatten transparency onto white so JPEG-style sources stay clean,
            // while preserving alpha for PNGs converted to WebP.
            imagepalettetotruecolor($src);
            imagealphablending($src, true);
            imagesavealpha($src, true);

            $path = $dir.'/'.Str::uuid()->toString().'.webp';

            ob_start();
            imagewebp($src, null, $quality);
            $binary = ob_get_clean();
            imagedestroy($src);

            Storage::disk('public')->put($path, $binary);

            return $path;
        } catch (\Throwable $e) {
            Log::warning('Image WebP conversion failed; storing original', ['error' => $e->getMessage()]);
            return $file->store($dir, 'public');
        }
    }

    protected function canConvert(): bool
    {
        return function_exists('imagewebp') && function_exists('imagecreatefromstring');
    }

    /** @param \GdImage $img */
    protected function downscale($img, int $maxWidth)
    {
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w <= $maxWidth) {
            return $img;
        }

        $newW = $maxWidth;
        $newH = (int) round($h * ($maxWidth / $w));
        $resized = imagescale($img, $newW, $newH);
        if ($resized !== false) {
            imagedestroy($img);
            return $resized;
        }

        return $img;
    }
}
