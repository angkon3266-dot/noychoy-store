<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── Admin account ───────────────────────────────────────────────
        User::updateOrCreate(
            ['email' => 'admin@noychoy.com'],
            ['name' => 'Store Admin', 'password' => 'password', 'role' => 'admin'],
        );

        // ── Categories ──────────────────────────────────────────────────
        $categories = collect([
            'Rings', 'Necklaces', 'Earrings', 'Bracelets', 'Bangles', 'Anklets',
        ])->map(fn ($name, $i) => Category::updateOrCreate(
            ['slug' => Str::slug($name)],
            ['name' => $name, 'is_active' => true, 'position' => $i],
        ));

        // ── Sample products ─────────────────────────────────────────────
        $samples = [
            ['Rose Gold Solitaire Ring', 'Rings', 2450, 3200, true, true],
            ['Pearl Drop Necklace', 'Necklaces', 1850, null, true, false],
            ['Crystal Stud Earrings', 'Earrings', 950, 1400, true, true],
            ['Gold Chain Bracelet', 'Bracelets', 1650, null, false, false],
            ['Kundan Bangle Set', 'Bangles', 3400, 4200, true, true],
            ['Silver Anklet Pair', 'Anklets', 1200, null, false, false],
            ['Emerald Statement Ring', 'Rings', 4800, null, true, false],
            ['Layered Coin Necklace', 'Necklaces', 2200, 2800, false, true],
            ['Hoop Earrings', 'Earrings', 780, null, false, false],
            ['Charm Bracelet', 'Bracelets', 1350, 1700, false, true],
            ['Bridal Bangle Stack', 'Bangles', 5600, null, true, false],
            ['Beaded Anklet', 'Anklets', 650, null, false, false],
        ];

        foreach ($samples as $i => [$name, $catName, $price, $compare, $featured, $sale]) {
            $product = Product::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'category_id' => $categories->firstWhere('name', $catName)->id,
                    'short_description' => 'A beautifully crafted '.strtolower($name).', perfect for everyday elegance or special occasions.',
                    'description' => "Handpicked and quality-checked.\n\nMaterial: premium alloy with anti-tarnish finish.\nDelivery across Bangladesh with cash on delivery.",
                    'price' => $price,
                    'compare_at_price' => $sale ? $compare : null,
                    'stock_quantity' => rand(5, 40),
                    'manage_stock' => true,
                    'in_stock' => true,
                    'status' => 'published',
                    'is_featured' => $featured,
                ],
            );

            if ($product->images()->count() === 0) {
                $product->images()->create([
                    'path' => "https://picsum.photos/seed/noychoy{$i}/800/800",
                    'alt' => $name,
                    'is_primary' => true,
                    'position' => 0,
                ]);
            }
        }

        // One product with variants (ring sizes).
        $ring = Product::where('slug', 'rose-gold-solitaire-ring')->first();
        if ($ring && $ring->variants()->count() === 0) {
            foreach (['Size 6', 'Size 7', 'Size 8'] as $size) {
                $ring->variants()->create([
                    'attributes' => ['Option' => $size],
                    'stock_quantity' => rand(2, 10),
                    'is_active' => true,
                ]);
            }
            $ring->update(['has_variants' => true]);
        }
    }
}
