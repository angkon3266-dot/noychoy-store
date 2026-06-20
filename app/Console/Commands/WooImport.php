<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\ImageOptimizer;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * One-time migration importer from a live WooCommerce store via the REST API.
 *
 *   php artisan woo:import --all
 *   php artisan woo:import --products --no-images
 *   php artisan woo:import --orders
 *
 * Idempotent: every record is matched by its WooCommerce id (woo_id), so you can
 * re-run it safely — existing rows are updated, not duplicated.
 */
class WooImport extends Command
{
    protected $signature = 'woo:import
        {--all : Import categories, products, customers and orders}
        {--products : Import categories + products}
        {--customers : Import customers}
        {--orders : Import orders}
        {--no-images : Skip downloading product images}
        {--limit=0 : Stop after N items per type (0 = no limit, for testing)}';

    protected $description = 'Import products, customers and orders from WooCommerce';

    protected int $limit = 0;
    protected bool $withImages = true;

    public function handle(ImageOptimizer $optimizer): int
    {
        if (blank(config('woocommerce.store_url')) || blank(config('woocommerce.consumer_key'))) {
            $this->error('WooCommerce credentials missing. Set WC_STORE_URL, WC_CONSUMER_KEY, WC_CONSUMER_SECRET in .env.');
            return self::FAILURE;
        }

        $this->limit = (int) $this->option('limit');
        $this->withImages = ! $this->option('no-images');

        $all = $this->option('all') || (! $this->option('products') && ! $this->option('customers') && ! $this->option('orders'));

        // Quick connectivity check
        $test = $this->client()->get('products', ['per_page' => 1]);
        if ($test->failed()) {
            $this->error('Could not reach WooCommerce API (HTTP '.$test->status().'). Check the URL and keys.');
            $this->line($test->body());
            return self::FAILURE;
        }
        $this->info('Connected to '.config('woocommerce.store_url'));

        if ($all || $this->option('products')) {
            $this->importCategories();
            $this->importProducts($optimizer);
        }
        if ($all || $this->option('customers')) {
            $this->importCustomers();
        }
        if ($all || $this->option('orders')) {
            $this->importOrders();
        }

        $this->newLine();
        $this->info('✓ Import complete.');
        return self::SUCCESS;
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl(config('woocommerce.store_url').'/wp-json/wc/v3/')
            ->withBasicAuth(config('woocommerce.consumer_key'), config('woocommerce.consumer_secret'))
            ->timeout(config('woocommerce.timeout', 60))
            ->acceptJson();
    }

    /** Loop every page of an endpoint, yielding each item, respecting --limit. */
    protected function each(string $endpoint, array $query, callable $callback): int
    {
        $page = 1;
        $count = 0;
        $perPage = config('woocommerce.per_page', 50);

        do {
            $res = $this->client()->get($endpoint, array_merge($query, ['per_page' => $perPage, 'page' => $page]));
            if ($res->failed()) {
                $this->warn("  {$endpoint} page {$page} failed (HTTP {$res->status()})");
                break;
            }
            $items = $res->json();
            if (empty($items)) {
                break;
            }
            foreach ($items as $item) {
                $callback($item);
                $count++;
                if ($this->limit > 0 && $count >= $this->limit) {
                    return $count;
                }
            }
            $totalPages = (int) ($res->header('X-WP-TotalPages') ?: 1);
            $page++;
        } while ($page <= $totalPages);

        return $count;
    }

    protected function importCategories(): void
    {
        $this->line('Importing categories…');
        $n = $this->each('products/categories', [], function ($cat) {
            Category::updateOrCreate(
                ['woo_id' => $cat['id']],
                [
                    'name' => $cat['name'],
                    'slug' => $cat['slug'] ?: Str::slug($cat['name']),
                    'description' => strip_tags($cat['description'] ?? ''),
                    'is_active' => true,
                ],
            );
        });
        $this->info("  {$n} categories.");
    }

    protected function importProducts(ImageOptimizer $optimizer): void
    {
        $this->line('Importing products…');
        $n = $this->each('products', ['status' => 'publish'], function ($p) use ($optimizer) {
            $categoryId = null;
            if (! empty($p['categories'][0]['id'])) {
                $categoryId = Category::where('woo_id', $p['categories'][0]['id'])->value('id');
            }

            $regular = $p['regular_price'] !== '' ? (float) $p['regular_price'] : (float) ($p['price'] ?: 0);
            $sale = $p['sale_price'] !== '' ? (float) $p['sale_price'] : null;

            $product = Product::updateOrCreate(
                ['woo_id' => $p['id']],
                [
                    'name' => $p['name'],
                    'slug' => $p['slug'] ?: Str::slug($p['name']),
                    'sku' => $p['sku'] ?: null,
                    'category_id' => $categoryId,
                    'short_description' => strip_tags($p['short_description'] ?? ''),
                    'description' => strip_tags($p['description'] ?? ''),
                    'price' => $sale ?? $regular,
                    'compare_at_price' => $sale ? $regular : null,
                    'manage_stock' => (bool) ($p['manage_stock'] ?? false),
                    'stock_quantity' => (int) ($p['stock_quantity'] ?? 0),
                    'in_stock' => ($p['stock_status'] ?? 'instock') === 'instock',
                    'status' => 'published',
                    'is_featured' => (bool) ($p['featured'] ?? false),
                    'has_variants' => ($p['type'] ?? 'simple') === 'variable',
                ],
            );

            // Images (only first time, or if product has none yet)
            if ($this->withImages && $product->images()->count() === 0 && ! empty($p['images'])) {
                foreach ($p['images'] as $i => $img) {
                    if (empty($img['src'])) {
                        continue;
                    }
                    $path = $optimizer->storeWebpFromUrl($img['src'], 'products');
                    $product->images()->create([
                        'path' => $path,
                        'alt' => $img['alt'] ?? $product->name,
                        'position' => $i,
                        'is_primary' => $i === 0,
                    ]);
                }
            }

            // Variations
            if ($product->has_variants) {
                $this->importVariations($product);
            }
        });
        $this->info("  {$n} products.");
    }

    protected function importVariations(Product $product): void
    {
        $res = $this->client()->get("products/{$product->woo_id}/variations", ['per_page' => 100]);
        if ($res->failed()) {
            return;
        }
        foreach ($res->json() as $v) {
            $attrs = collect($v['attributes'] ?? [])->mapWithKeys(fn ($a) => [$a['name'] => $a['option']])->all();
            ProductVariant::updateOrCreate(
                ['woo_variation_id' => $v['id']],
                [
                    'product_id' => $product->id,
                    'sku' => $v['sku'] ?: null,
                    'attributes' => $attrs ?: ['Option' => 'Default'],
                    'price' => $v['price'] !== '' ? (float) $v['price'] : $product->price,
                    'stock_quantity' => (int) ($v['stock_quantity'] ?? 0),
                    'is_active' => ($v['status'] ?? 'publish') === 'publish',
                ],
            );
        }
    }

    protected function importCustomers(): void
    {
        $this->line('Importing customers…');
        $n = $this->each('customers', [], function ($c) {
            $name = trim(($c['first_name'] ?? '').' '.($c['last_name'] ?? '')) ?: ($c['username'] ?? 'Customer');
            $phone = $c['billing']['phone'] ?? null;
            Customer::updateOrCreate(
                ['woo_id' => $c['id']],
                [
                    'name' => $name,
                    'email' => $c['email'] ?: null,
                    'phone' => $phone,
                ],
            );
        });
        $this->info("  {$n} customers.");
    }

    protected function importOrders(): void
    {
        $this->line('Importing orders…');
        $statusMap = [
            'pending' => 'pending', 'processing' => 'processing', 'on-hold' => 'pending',
            'completed' => 'delivered', 'cancelled' => 'cancelled',
            'refunded' => 'returned', 'failed' => 'cancelled',
        ];

        $n = $this->each('orders', [], function ($o) use ($statusMap) {
            $billing = $o['billing'] ?? [];
            $customerId = ! empty($o['customer_id'])
                ? Customer::where('woo_id', $o['customer_id'])->value('id')
                : null;

            $order = Order::updateOrCreate(
                ['woo_id' => $o['id']],
                [
                    'order_number' => 'WC-'.$o['number'],
                    'customer_id' => $customerId,
                    'customer_name' => trim(($billing['first_name'] ?? '').' '.($billing['last_name'] ?? '')) ?: 'Customer',
                    'customer_phone' => $billing['phone'] ?? '',
                    'customer_email' => $billing['email'] ?? null,
                    'shipping_address' => trim(($billing['address_1'] ?? '').' '.($billing['address_2'] ?? '')),
                    'city' => $billing['city'] ?? null,
                    'district' => $billing['state'] ?? null,
                    'subtotal' => (float) ($o['total'] ?? 0) - (float) ($o['shipping_total'] ?? 0) + (float) ($o['discount_total'] ?? 0),
                    'shipping_cost' => (float) ($o['shipping_total'] ?? 0),
                    'discount' => (float) ($o['discount_total'] ?? 0),
                    'total' => (float) ($o['total'] ?? 0),
                    'payment_method' => $o['payment_method'] ?? 'cod',
                    'payment_status' => ($o['date_paid'] ?? null) ? 'paid' : 'unpaid',
                    'status' => $statusMap[$o['status']] ?? 'pending',
                    'coupon_code' => $o['coupon_lines'][0]['code'] ?? null,
                    'notes' => $o['customer_note'] ?? null,
                    'source' => 'woo',
                ],
            );

            // Preserve the original order date (created_at isn't mass-assignable).
            if (! empty($o['date_created'])) {
                $order->forceFill(['created_at' => $o['date_created']])->saveQuietly();
            }

            // Line items (replace to stay idempotent)
            $order->items()->delete();
            foreach ($o['line_items'] ?? [] as $li) {
                $order->items()->create([
                    'product_id' => Product::where('woo_id', $li['product_id'] ?? 0)->value('id'),
                    'name' => $li['name'],
                    'sku' => $li['sku'] ?: null,
                    'price' => (float) ($li['subtotal'] ?? 0) / max(1, (int) $li['quantity']),
                    'quantity' => (int) $li['quantity'],
                    'subtotal' => (float) ($li['total'] ?? $li['subtotal'] ?? 0),
                ]);
            }
        });
        $this->info("  {$n} orders.");
    }
}
