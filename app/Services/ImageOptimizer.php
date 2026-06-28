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

    /**
     * Download a remote image and store it as optimized WebP.
     * Returns the stored relative path, or the original URL if the download fails
     * (ProductImage::url() serves http(s) paths as-is, so nothing breaks).
     */
    public function storeWebpFromUrl(string $url, string $dir = 'products', int $maxWidth = 1600, int $quality = 82): string
    {
        try {
            $binary = @file_get_contents($url);
            if ($binary === false || $binary === '') {
                return $url;
            }

            if (! $this->canConvert()) {
                $ext = pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'jpg';
                $path = $dir.'/'.Str::uuid()->toString().'.'.$ext;
                Storage::disk('public')->put($path, $binary);
                return $path;
            }

            $src = @imagecreatefromstring($binary);
            if ($src === false) {
                return $url;
            }

            $src = $this->downscale($src, $maxWidth);
            imagepalettetotruecolor($src);
            imagealphablending($src, true);
            imagesavealpha($src, true);

            $path = $dir.'/'.Str::uuid()->toString().'.webp';
            ob_start();
            imagewebp($src, null, $quality);
            $out = ob_get_clean();
            imagedestroy($src);

            Storage::disk('public')->put($path, $out);

            return $path;
        } catch (\Throwable $e) {
            Log::warning('Remote image import failed; keeping URL', ['url' => $url, 'error' => $e->getMessage()]);
            return $url;
        }
    }

    /**
     * Re-encode an existing public-disk image in place (same path/format) at a
     * smaller width and/or lower quality to shrink its file size. Keeps the path
     * so DB references (product images, branding, etc.) stay valid.
     *
     * @return array{old_size:int,new_size:int,width:int,height:int}|null
     */
    public function optimizeExisting(string $relativePath, int $maxWidth = 1600, int $quality = 80): ?array
    {
        $disk = Storage::disk('public');
        $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

        if (! $disk->exists($relativePath) || ! $this->canConvert() || ! in_array($ext, ['webp', 'jpg', 'jpeg', 'png'], true)) {
            return null;
        }

        try {
            $binary = $disk->get($relativePath);
            $oldSize = strlen((string) $binary);

            $src = @imagecreatefromstring($binary);
            if ($src === false) {
                return null;
            }

            $src = $this->downscale($src, $maxWidth);
            imagepalettetotruecolor($src);
            imagealphablending($src, true);
            imagesavealpha($src, true);
            $w = imagesx($src);
            $h = imagesy($src);

            ob_start();
            if ($ext === 'png') {
                imagepng($src, null, 6);
            } elseif ($ext === 'webp') {
                imagewebp($src, null, $quality);
            } else {
                imagejpeg($src, null, $quality);
            }
            $out = ob_get_clean();
            imagedestroy($src);

            // Never replace with a larger file (e.g. an already well-compressed image).
            if (strlen($out) >= $oldSize) {
                return ['old_size' => $oldSize, 'new_size' => $oldSize, 'width' => $w, 'height' => $h];
            }

            $disk->put($relativePath, $out);

            return ['old_size' => $oldSize, 'new_size' => strlen($out), 'width' => $w, 'height' => $h];
        } catch (\Throwable $e) {
            Log::warning('optimizeExisting failed', ['path' => $relativePath, 'error' => $e->getMessage()]);
            return null;
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
