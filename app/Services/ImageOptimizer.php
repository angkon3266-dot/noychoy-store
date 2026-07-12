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
 *
 * Transparency is preserved (logos on PNG keep their see-through background),
 * and high-quality resampling is used so downscaled art stays crisp.
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

            $src = $this->prepare($src, $maxWidth);

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

            $src = $this->prepare($src, $maxWidth);

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

            $src = $this->prepare($src, $maxWidth);
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

    /**
     * Transcode an existing JPG/PNG on the public disk to a new WebP file
     * (same folder + basename, .webp extension). Does NOT delete the original or
     * update any references — the caller repoints DB rows then deletes the source.
     *
     * @return array{old_path:string,new_path:string,old_size:int,new_size:int}|null
     */
    public function convertToWebp(string $relativePath, int $maxWidth = 1600, int $quality = 82): ?array
    {
        $disk = Storage::disk('public');
        $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

        if (! $disk->exists($relativePath) || ! $this->canConvert() || ! in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            return null;
        }

        try {
            $binary = $disk->get($relativePath);
            $oldSize = strlen((string) $binary);

            $src = @imagecreatefromstring($binary);
            if ($src === false) {
                return null;
            }
            $src = $this->prepare($src, $maxWidth);

            ob_start();
            imagewebp($src, null, $quality);
            $out = ob_get_clean();
            imagedestroy($src);

            // Target: same directory + filename, .webp extension (de-duplicated).
            $dir = trim((string) pathinfo($relativePath, PATHINFO_DIRNAME), '.');
            $name = pathinfo($relativePath, PATHINFO_FILENAME);
            $newPath = ($dir !== '' ? $dir.'/' : '').$name.'.webp';
            if ($newPath !== $relativePath && $disk->exists($newPath)) {
                $newPath = ($dir !== '' ? $dir.'/' : '').$name.'-'.Str::lower(Str::random(6)).'.webp';
            }

            $disk->put($newPath, $out);

            return [
                'old_path' => $relativePath,
                'new_path' => $newPath,
                'old_size' => $oldSize,
                'new_size' => strlen((string) $out),
            ];
        } catch (\Throwable $e) {
            Log::warning('convertToWebp failed', ['path' => $relativePath, 'error' => $e->getMessage()]);

            return null;
        }
    }

    protected function canConvert(): bool
    {
        return function_exists('imagewebp') && function_exists('imagecreatefromstring');
    }

    /**
     * Convert to truecolor (so palette/indexed PNGs downscale cleanly), resize
     * with high-quality resampling if needed, and flag the image so its alpha
     * channel is written out — keeping transparent backgrounds transparent.
     *
     * @param  \GdImage  $img
     * @return \GdImage
     */
    protected function prepare($img, int $maxWidth)
    {
        // Palette → truecolor BEFORE resizing, or indexed logos come out blocky.
        imagepalettetotruecolor($img);

        $img = $this->downscale($img, $maxWidth);

        // Write the alpha channel as-is (no compositing onto a black canvas).
        imagealphablending($img, false);
        imagesavealpha($img, true);

        return $img;
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
        $newH = max(1, (int) round($h * ($maxWidth / $w)));

        // Transparent destination + resampled copy = sharp, alpha-preserving resize.
        $dst = imagecreatetruecolor($newW, $newH);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $newW, $newH, $transparent);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);
        imagedestroy($img);

        return $dst;
    }
}
