<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A purchase order as a document to send a supplier: our letterhead, their
 * details, the lines with photos and variant breakdown, and the totals.
 *
 * dompdf is kept off the network (no isRemoteEnabled). Photos and the logo are
 * fetched here instead — in parallel, with a short timeout — then shrunk and
 * embedded as data URIs, so one dead Alibaba image costs a blank square, not a
 * failed export. Internal-only figures (target price, received qty, the BDT
 * rate) stay out of it.
 */
class PurchaseOrderPdf
{
    /** Chinese, Japanese, Korean and full-width forms — none of which DejaVu has. */
    public const CJK = '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\x{3000}-\x{303F}\x{FF00}-\x{FFEF}]/u';

    public static function hasCjk(?string $text): bool
    {
        return $text !== null && preg_match(self::CJK, $text) === 1;
    }

    public function download(PurchaseOrder $po)
    {
        return $this->make($po)->download($po->po_number.'.pdf');
    }

    public function make(PurchaseOrder $po)
    {
        $po->loadMissing('supplier', 'items');

        // dompdf caches the metrics of the CJK font it installs from @font-face
        // here; storage/ is not in git, so a fresh deploy has no such folder.
        File::ensureDirectoryExists(config('dompdf.options.font_cache', storage_path('fonts')));

        // The CJK font is ~10 MB a weight and dompdf holds its metrics in memory
        // (~120 MB peak), so it is only declared when the order needs it, and the
        // render gets headroom over PHP's usual 128M.
        $cjk = $this->needsCjk($po);
        if ($cjk && $this->bytes(ini_get('memory_limit')) < 512 * 1048576) {
            @ini_set('memory_limit', '512M');
        }

        $images = $this->embedImages($po->items->pluck('image_url', 'id')->filter()->all(), 160);

        return Pdf::loadView('admin.purchase-orders.pdf', [
            'order' => $po,
            'images' => $images,
            'cjk' => $cjk,
            'store' => [
                'name' => store_name(),
                'address' => Setting::get('store_address', config('store.address')),
                'phone' => Setting::get('store_phone', config('store.phone')),
                'email' => Setting::get('store_email', config('store.email')),
                'logo' => $this->logo(),
            ],
        ])->setPaper('a4')->setOption('isFontSubsettingEnabled', true);
    }

    protected function needsCjk(PurchaseOrder $po): bool
    {
        $texts = [
            $po->ship_to, $po->courier_name, $po->courier_tracking, $po->notes,
            $po->supplier?->name, $po->supplier?->contact, $po->supplier?->country, $po->supplier?->wechat,
            store_name(), Setting::get('store_address', config('store.address')),
        ];
        foreach ($po->items as $it) {
            $texts[] = $it->product_name;
            $texts[] = $it->sku;
            $texts[] = json_encode($it->variants ?: [], JSON_UNESCAPED_UNICODE);
        }

        return collect($texts)->contains(fn ($t) => self::hasCjk($t));
    }

    /** "128M" → bytes; -1 (no limit) counts as plenty. */
    protected function bytes(string|false $limit): int
    {
        $limit = trim((string) $limit);
        if ($limit === '' || $limit === '-1') {
            return PHP_INT_MAX;
        }
        $n = (int) $limit;

        return match (strtoupper(substr($limit, -1))) {
            'G' => $n * 1073741824, 'M' => $n * 1048576, 'K' => $n * 1024, default => $n,
        };
    }

    /** @param  array<int|string, string>  $urls  key => image URL */
    protected function embedImages(array $urls, int $size): array
    {
        $urls = array_filter($urls, fn ($u) => Str::startsWith($u, ['http://', 'https://']));
        if (! $urls) {
            return [];
        }

        $responses = Http::pool(fn (Pool $pool) => collect($urls)->map(
            fn ($url, $key) => $pool->as((string) $key)->timeout(6)->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get($url)
        )->all());

        $out = [];
        foreach ($urls as $key => $url) {
            $res = $responses[(string) $key] ?? null;
            if ($res instanceof \Illuminate\Http\Client\Response && $res->successful()) {
                $out[$key] = $this->toDataUri($res->body(), $size);
            }
        }

        return array_filter($out);
    }

    /** The theme logo, read from our own disk when it lives there. */
    protected function logo(): ?string
    {
        $path = theme('logo');
        if (blank($path)) {
            return null;
        }

        $local = Str::after((string) $path, '/storage/');
        $disk = Storage::disk('public');
        if ($disk->exists($local)) {
            return $this->toDataUri($disk->get($local), 360, true);
        }

        return Str::startsWith($path, ['http://', 'https://'])
            ? ($this->embedImages(['logo' => $path], 360)['logo'] ?? null)
            : null;
    }

    /** Decode any GD-readable image (webp included), fit it in $size px, re-encode. */
    protected function toDataUri(string $bytes, int $size, bool $keepAlpha = false): ?string
    {
        $src = @imagecreatefromstring($bytes);
        if (! $src) {
            return null;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, $size / max($w, $h));
        $dst = imagecreatetruecolor(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));

        if ($keepAlpha) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        } else {
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);

        ob_start();
        $keepAlpha ? imagepng($dst) : imagejpeg($dst, null, 82);
        $data = ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        return 'data:image/'.($keepAlpha ? 'png' : 'jpeg').';base64,'.base64_encode($data);
    }
}
