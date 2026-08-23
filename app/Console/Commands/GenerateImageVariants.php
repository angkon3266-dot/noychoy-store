<?php

namespace App\Console\Commands;

use App\Models\ProductImage;
use App\Services\ImageOptimizer;
use Illuminate\Console\Command;

/**
 * Backfill the downscaled image variants that srcset serves.
 *
 * New uploads get their variant at upload time; this exists for everything
 * uploaded before the feature (and for media-library picks, which skip the
 * upload path). Idempotent — existing variants are detected by filename and
 * skipped, so re-running after adding products is always safe.
 */
class GenerateImageVariants extends Command
{
    protected $signature = 'images:variants
        {--card-width=450 : Width for product-card variants}
        {--mid-width=900 : Middle width, for high-DPR phones and wide cards}
        {--hero-width=900 : Width for hero/branding variants}';

    protected $description = 'Generate downscaled srcset variants for product images and the homepage hero';

    public function handle(ImageOptimizer $optimizer): int
    {
        $made = 0;
        $skipped = 0;

        // Product images → card-sized variants.
        foreach (ProductImage::pluck('path') as $path) {
            if (str_starts_with($path, 'http')) {
                $skipped++;

                continue;
            }
            $optimizer->variant($path, (int) $this->option('card-width')) ? $made++ : $skipped++;

            // A middle rung. With only 450 and the 1600px original to choose
            // from, a high-DPR phone or a wide card jumped straight to the
            // full-size image — which is what the srcset exists to avoid.
            $optimizer->variant($path, (int) $this->option('mid-width')) ? $made++ : $skipped++;
        }

        // Category tiles — these were handing out the full original.
        foreach (\App\Models\Category::whereNotNull('image')->pluck('image') as $path) {
            if (str_starts_with((string) $path, 'http')) {
                $skipped++;

                continue;
            }
            $optimizer->variant($path, (int) $this->option('card-width')) ? $made++ : $skipped++;
            $optimizer->variant($path, (int) $this->option('mid-width')) ? $made++ : $skipped++;
        }

        // Homepage hero assets → a mid-size variant for the hero panel.
        $heroPaths = collect([home_content('hero_image'), home_content('promise_image')])
            ->merge(collect(home_content('hero_slides') ?? [])->pluck('image'))
            ->filter(fn ($p) => filled($p) && ! str_starts_with((string) $p, 'http'));

        foreach ($heroPaths as $path) {
            $optimizer->variant($path, (int) $this->option('hero-width')) ? $made++ : $skipped++;
        }

        $this->info("Variants created: {$made} · already present / not needed: {$skipped}");

        return self::SUCCESS;
    }
}
