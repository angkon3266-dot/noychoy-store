<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Api\CatalogApi;
use Illuminate\Http\Request;

/**
 * Model Context Protocol endpoint (Streamable HTTP transport).
 *
 * This is the piece that makes the store work the way Shopify does inside
 * Claude: Shopify is usable there because it publishes an MCP server, not
 * because it has a REST API. A REST API alone would leave an assistant
 * guessing at URLs; MCP hands it a typed tool list it can call directly.
 *
 * Speaks JSON-RPC 2.0 over a single POST. Every tool delegates to
 * {@see CatalogApi}, so the MCP surface and the REST surface cannot drift.
 */
class McpController extends Controller
{
    private const PROTOCOL_VERSION = '2025-06-18';

    public function __construct(private readonly CatalogApi $catalog) {}

    public function handle(Request $request)
    {
        $id = $request->input('id');
        $method = (string) $request->input('method');

        // Notifications carry no id and expect no body back.
        if ($id === null && str_starts_with($method, 'notifications/')) {
            return response()->noContent();
        }

        try {
            $result = match ($method) {
                'initialize' => $this->initialize(),
                'ping' => (object) [],
                'tools/list' => ['tools' => $this->tools()],
                'tools/call' => $this->call($request),
                default => throw new \DomainException("Unknown method: {$method}", -32601),
            };
        } catch (\DomainException $e) {
            return $this->error($id, (int) $e->getCode() ?: -32603, $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return $this->error($id, -32603, 'Internal error: '.$e->getMessage());
        }

        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function initialize(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => store_name().' storefront', 'version' => '1.0.0'],
            'instructions' => 'Manage the '.store_name().' catalogue end to end: list categories, create products '
                .'(draft by default — set status=published when ready), search and edit products, upload and '
                .'arrange product photos, and archive products (soft delete, restorable from the admin panel). '
                .'Image uploads take a URL — the store downloads, converts to WebP, applies the shop watermark if '
                .'one is configured, and generates the responsive variant, exactly as the admin panel would. '
                .'Typical flow for a new item: list_categories → create_product → upload_product_image (primary) → update_product status=published.',
        ];
    }

    /** @return array<int,array> */
    private function tools(): array
    {
        return [
            [
                'name' => 'search_products',
                'description' => 'Find products by name, SKU or slug. Returns id, price, stock and every image with its id.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Text to match against name, SKU or slug. Omit to list the newest.'],
                        'limit' => ['type' => 'integer', 'description' => 'Max results (1–100, default 20).'],
                    ],
                ],
            ],
            [
                'name' => 'get_product',
                'description' => 'Fetch one product by numeric id, slug or exact SKU.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['product'],
                    'properties' => ['product' => ['type' => 'string', 'description' => 'Product id, slug or SKU.']],
                ],
            ],
            [
                'name' => 'upload_product_image',
                'description' => 'Add a photo to a product from a publicly reachable image URL. Converted to WebP, watermarked if the shop has that on, and given a responsive variant.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['product', 'image_url'],
                    'properties' => [
                        'product' => ['type' => 'string', 'description' => 'Product id, slug or SKU.'],
                        'image_url' => ['type' => 'string', 'description' => 'Publicly reachable URL of the image to attach.'],
                        'alt' => ['type' => 'string', 'description' => 'Alt text for accessibility and SEO.'],
                        'primary' => ['type' => 'boolean', 'description' => 'Make this the main product photo.'],
                    ],
                ],
            ],
            [
                'name' => 'set_primary_image',
                'description' => 'Promote one of a product\'s existing images to be the main photo.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['product', 'image_id'],
                    'properties' => [
                        'product' => ['type' => 'string'],
                        'image_id' => ['type' => 'integer'],
                    ],
                ],
            ],
            [
                'name' => 'delete_product_image',
                'description' => 'Remove an image from a product and delete its files.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['product', 'image_id'],
                    'properties' => [
                        'product' => ['type' => 'string'],
                        'image_id' => ['type' => 'integer'],
                    ],
                ],
            ],
            [
                'name' => 'update_product',
                'description' => 'Change a product\'s details. Only the fields you pass are touched.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['product'],
                    'properties' => [
                        'product' => ['type' => 'string'],
                        'name' => ['type' => 'string'],
                        'short_description' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'price' => ['type' => 'number'],
                        'compare_at_price' => ['type' => 'number', 'description' => 'Was-price, for showing a discount.'],
                        'stock_quantity' => ['type' => 'integer'],
                        'status' => ['type' => 'string', 'enum' => ['published', 'draft']],
                        'is_featured' => ['type' => 'boolean'],
                        'is_bestseller' => ['type' => 'boolean'],
                        'manage_stock' => ['type' => 'boolean', 'description' => 'Track stock_quantity and hide the product at 0.'],
                        'sku' => ['type' => 'string'],
                        'category' => ['type' => 'string', 'description' => 'Category id, slug or exact name (see list_categories).'],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'weight' => ['type' => 'number'],
                        'meta_title' => ['type' => 'string'],
                        'meta_description' => ['type' => 'string'],
                    ],
                ],
            ],
            [
                'name' => 'list_categories',
                'description' => 'All product categories (id, name, slug, parent, active). Use before create_product.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
            ],
            [
                'name' => 'create_product',
                'description' => 'Create a new product. Created as a DRAFT unless status=published is passed; add photos with upload_product_image afterwards. Returns the new product incl. id and slug.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['name', 'price'],
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'price' => ['type' => 'number'],
                        'compare_at_price' => ['type' => 'number', 'description' => 'Was-price, for showing a discount.'],
                        'category' => ['type' => 'string', 'description' => 'Category id, slug or exact name (see list_categories).'],
                        'sku' => ['type' => 'string'],
                        'short_description' => ['type' => 'string', 'description' => 'One or two sentences shown under the title.'],
                        'description' => ['type' => 'string', 'description' => 'Full description (plain text, newlines kept).'],
                        'stock_quantity' => ['type' => 'integer'],
                        'status' => ['type' => 'string', 'enum' => ['published', 'draft']],
                        'is_featured' => ['type' => 'boolean'],
                        'is_bestseller' => ['type' => 'boolean'],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'weight' => ['type' => 'number'],
                        'meta_title' => ['type' => 'string'],
                        'meta_description' => ['type' => 'string'],
                    ],
                ],
            ],
            [
                'name' => 'delete_product',
                'description' => 'Archive a product (soft delete). It disappears from the storefront immediately; past orders keep their line items and an admin can restore it. Pass confirm=true.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['product', 'confirm'],
                    'properties' => [
                        'product' => ['type' => 'string', 'description' => 'Product id, slug or SKU.'],
                        'confirm' => ['type' => 'boolean', 'description' => 'Must be true — guards against accidental calls.'],
                    ],
                ],
            ],
        ];
    }

    private function call(Request $request): array
    {
        $name = (string) $request->input('params.name');
        $args = (array) $request->input('params.arguments', []);

        $product = fn () => $this->catalog->find((string) ($args['product'] ?? ''))
            ?: throw new \DomainException('No product matches "'.($args['product'] ?? '').'". Try search_products first.');

        $image = function ($p) use ($args) {
            $img = $p->images()->find($args['image_id'] ?? 0);

            return $img ?: throw new \DomainException('Image '.($args['image_id'] ?? '?').' does not belong to that product.');
        };

        $payload = match ($name) {
            'search_products' => ['products' => $this->catalog->search($args['query'] ?? null, (int) ($args['limit'] ?? 20))],

            'get_product' => $this->catalog->present($product()),

            'upload_product_image' => (function () use ($args, $product) {
                $p = $product();
                $img = $this->catalog->addImage($p, (string) $args['image_url'], (bool) ($args['primary'] ?? false), $args['alt'] ?? null);

                return ['uploaded_image_id' => $img->id, 'url' => $img->url, 'product' => $this->catalog->present($p->fresh('images'))];
            })(),

            'set_primary_image' => (function () use ($product, $image) {
                $p = $product();
                $this->catalog->setPrimary($p, $image($p));

                return $this->catalog->present($p->fresh('images'));
            })(),

            'delete_product_image' => (function () use ($product, $image) {
                $p = $product();
                $this->catalog->deleteImage($p, $image($p));

                return $this->catalog->present($p->fresh('images'));
            })(),

            'update_product' => $this->catalog->present(
                $this->catalog->updateProduct($product(), $this->withCategory(array_diff_key($args, array_flip(['product']))))
            ),

            'list_categories' => ['categories' => $this->catalog->categories()],

            'create_product' => (function () use ($args) {
                if (blank($args['name'] ?? null) || ! isset($args['price'])) {
                    throw new \DomainException('create_product needs at least name and price.', -32602);
                }

                return $this->catalog->present($this->catalog->createProduct($this->withCategory($args)));
            })(),

            'delete_product' => (function () use ($args, $product) {
                if (! filter_var($args['confirm'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    throw new \DomainException('Pass confirm=true to archive this product.', -32602);
                }
                $p = $product();
                $this->catalog->deleteProduct($p);

                return ['deleted' => true, 'id' => $p->id, 'name' => $p->name, 'restorable' => true];
            })(),

            default => throw new \DomainException("Unknown tool: {$name}", -32602),
        };

        // MCP returns tool output as content blocks; JSON in a text block is the
        // interoperable shape every client understands.
        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]],
            'isError' => false,
        ];
    }

    /** Map an agent's `category` (id / slug / name) onto `category_id`. */
    private function withCategory(array $args): array
    {
        if (filled($args['category'] ?? null)) {
            $cat = $this->catalog->findCategory((string) $args['category'])
                ?: throw new \DomainException('No category matches "'.$args['category'].'". Call list_categories to see them.', -32602);
            $args['category_id'] = $cat->id;
        }
        unset($args['category']);

        return $args;
    }

    private function error($id, int $code, string $message)
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }
}
