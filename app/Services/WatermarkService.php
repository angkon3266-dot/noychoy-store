<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Stamps a text or logo watermark onto images (in place) using PHP-GD.
 * Settings live in the 'watermark' Setting key and are edited from the Media
 * library. Text watermarks need GD FreeType (imagettftext) + an uploaded font;
 * logo watermarks work with any GD build.
 */
class WatermarkService
{
    public const DEFAULTS = [
        'type' => 'text',            // 'text' | 'logo'
        'text' => 'Meridian Éclat',
        'font_path' => null,         // public-disk path to an uploaded .ttf/.otf
        'logo_path' => null,         // public-disk path to an uploaded PNG
        'position' => 'top-right',   // top-left|top-right|bottom-left|bottom-right|center
        'mode' => 'single',          // 'single' | 'multiple' (stamp at several positions)
        'positions' => ['top-right'], // used when mode = 'multiple'
        'opacity' => 60,             // 0-100
        'size' => 6,                 // % of image width (text: font size · logo: width)
        'color' => '#ffffff',        // text colour
        'margin' => 4,               // % of image width, distance from the edge
        'auto_products' => false,    // auto-stamp newly uploaded product images
    ];

    public const POSITIONS = ['top-left', 'top-right', 'bottom-left', 'bottom-right', 'center'];

    public function settings(): array
    {
        $s = Setting::get('watermark', []);

        return array_merge(self::DEFAULTS, is_array($s) ? $s : []);
    }

    public function canApply(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagepng');
    }

    /** Is the current configuration usable (font present for text, logo for logo)? */
    public function isReady(?array $cfg = null): bool
    {
        $cfg = $cfg ?: $this->settings();
        if (! $this->canApply()) {
            return false;
        }
        if (($cfg['type'] ?? 'text') === 'logo') {
            return ! empty($cfg['logo_path']) && Storage::disk('public')->exists($cfg['logo_path']);
        }

        return function_exists('imagettftext')
            && ! empty($cfg['font_path'])
            && is_file(Storage::disk('public')->path($cfg['font_path']));
    }

    /** Apply the configured watermark to an image on the public disk, in place. */
    public function applyToPath(string $relativePath, ?array $cfg = null): bool
    {
        $disk = Storage::disk('public');
        $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        if (! $disk->exists($relativePath) || ! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return false;
        }

        $out = $this->render($disk->get($relativePath), $cfg ?: $this->settings(), $ext);
        if ($out === null) {
            return false;
        }
        $disk->put($relativePath, $out);

        return true;
    }

    /**
     * Watermark raw image bytes and return the encoded result (no disk writes).
     * Used by applyToPath and by the live preview. Returns null on failure.
     */
    public function render(string $binary, array $cfg, string $ext = 'png'): ?string
    {
        if (! $this->canApply()) {
            return null;
        }
        $ext = strtolower($ext);

        try {
            $base = @imagecreatefromstring($binary);
            if ($base === false) {
                return null;
            }
            imagepalettetotruecolor($base);
            imagealphablending($base, true);
            imagesavealpha($base, true);

            // Stamp at one position, or several when mode = 'multiple'.
            $isLogo = ($cfg['type'] ?? 'text') === 'logo';
            foreach ($this->positionsFor($cfg) as $pos) {
                $cfgPos = array_merge($cfg, ['position' => $pos]);
                $ok = $isLogo ? $this->stampLogo($base, $cfgPos) : $this->stampText($base, $cfgPos);
                if (! $ok) {
                    imagedestroy($base);

                    return null;
                }
            }

            ob_start();
            if ($ext === 'webp') {
                imagewebp($base, null, 85);
            } elseif (in_array($ext, ['jpg', 'jpeg'], true)) {
                imagejpeg($base, null, 85);
            } else {
                imagesavealpha($base, true);
                imagepng($base, null, 6);
            }
            $out = ob_get_clean();
            imagedestroy($base);

            return $out;
        } catch (\Throwable $e) {
            Log::warning('watermark render failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The list of positions to stamp: several in 'multiple' mode, one otherwise.
     *
     * @return array<int, string>
     */
    protected function positionsFor(array $cfg): array
    {
        if (($cfg['mode'] ?? 'single') === 'multiple' && ! empty($cfg['positions']) && is_array($cfg['positions'])) {
            $valid = array_values(array_intersect($cfg['positions'], self::POSITIONS));
            if (! empty($valid)) {
                return array_unique($valid);
            }
        }

        return [$cfg['position'] ?? 'top-right'];
    }

    /** @param \GdImage $base */
    protected function stampText($base, array $cfg): bool
    {
        $font = ! empty($cfg['font_path']) ? Storage::disk('public')->path($cfg['font_path']) : null;
        if (! $font || ! is_file($font) || ! function_exists('imagettftext')) {
            return false;
        }

        $w = imagesx($base);
        $h = imagesy($base);
        $size = max(8, (int) round($w * max(1, (float) $cfg['size']) / 100));
        $text = (string) ($cfg['text'] ?: 'Meridian Éclat');

        $bbox = imagettfbbox($size, 0, $font, $text);
        $tw = abs($bbox[2] - $bbox[0]);
        $th = abs($bbox[7] - $bbox[1]);
        $m = $this->marginPx($base, $cfg);
        [$x, $y] = $this->positionXY($w, $h, $tw, $th, $m, $cfg['position'] ?? 'top-right');
        $baseline = $y + $th; // imagettftext anchors on the baseline

        [$r, $g, $b] = $this->hexRgb($cfg['color'] ?? '#ffffff');
        $alpha = (int) round(127 * (1 - $this->opacity($cfg)));
        $col = imagecolorallocatealpha($base, $r, $g, $b, $alpha);
        $shadow = imagecolorallocatealpha($base, 0, 0, 0, min(127, $alpha + 30));

        imagettftext($base, $size, 0, $x + 2, $baseline + 2, $shadow, $font, $text);
        imagettftext($base, $size, 0, $x, $baseline, $col, $font, $text);

        return true;
    }

    /** @param \GdImage $base */
    protected function stampLogo($base, array $cfg): bool
    {
        $lp = $cfg['logo_path'] ?? null;
        if (! $lp || ! Storage::disk('public')->exists($lp)) {
            return false;
        }
        $logo = @imagecreatefromstring(Storage::disk('public')->get($lp));
        if ($logo === false) {
            return false;
        }
        imagepalettetotruecolor($logo);

        $w = imagesx($base);
        $h = imagesy($base);
        $lw0 = imagesx($logo);
        $lh0 = imagesy($logo);
        $tw = max(16, (int) round($w * max(1, (float) $cfg['size']) / 100));
        $th = max(1, (int) round($lh0 * $tw / $lw0));

        $wm = imagecreatetruecolor($tw, $th);
        imagealphablending($wm, false);
        imagesavealpha($wm, true);
        imagefilledrectangle($wm, 0, 0, $tw, $th, imagecolorallocatealpha($wm, 0, 0, 0, 127));
        imagecopyresampled($wm, $logo, 0, 0, 0, 0, $tw, $th, $lw0, $lh0);
        imagedestroy($logo);

        // Scale the logo's alpha channel down for the requested opacity.
        $op = $this->opacity($cfg);
        if ($op < 1) {
            for ($yy = 0; $yy < $th; $yy++) {
                for ($xx = 0; $xx < $tw; $xx++) {
                    $c = imagecolorat($wm, $xx, $yy);
                    $a = ($c >> 24) & 0x7F;
                    $na = 127 - (int) round((127 - $a) * $op);
                    imagesetpixel($wm, $xx, $yy, imagecolorallocatealpha($wm, ($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF, $na));
                }
            }
        }

        $m = $this->marginPx($base, $cfg);
        [$x, $y] = $this->positionXY($w, $h, $tw, $th, $m, $cfg['position'] ?? 'top-right');
        imagealphablending($base, true);
        imagecopy($base, $wm, $x, $y, 0, 0, $tw, $th);
        imagedestroy($wm);

        return true;
    }

    protected function opacity(array $cfg): float
    {
        return max(0, min(100, (float) ($cfg['opacity'] ?? 60))) / 100;
    }

    /** @param \GdImage $base */
    protected function marginPx($base, array $cfg): int
    {
        return (int) round(imagesx($base) * max(0, (float) ($cfg['margin'] ?? 4)) / 100);
    }

    protected function positionXY(int $W, int $H, int $w, int $h, int $m, string $pos): array
    {
        return match ($pos) {
            'top-left' => [$m, $m],
            'bottom-left' => [$m, $H - $h - $m],
            'bottom-right' => [$W - $w - $m, $H - $h - $m],
            'center' => [intdiv($W - $w, 2), intdiv($H - $h, 2)],
            default => [$W - $w - $m, $m], // top-right
        };
    }

    protected function hexRgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (strlen($hex) !== 6) {
            return [255, 255, 255];
        }

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
