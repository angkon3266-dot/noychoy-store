<?php

namespace App\Mcp\Tools\Reviews;

use App\Mcp\Tools\StoreTool;
use App\Models\Review;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListReviews extends StoreTool
{
    protected ?string $section = 'reviews';

    protected string $name = 'list_reviews';

    protected string $description = 'Product reviews, newest first. Filter by status (pending = waiting for moderation, approved = shown on the storefront, hidden) or by product. Use moderate_review to approve or hide.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(array_keys(Review::STATUSES)),
            'product' => $schema->string()->description('Product id, slug or SKU.'),
            'limit' => $schema->integer()->description('1–100, default 20.'),
        ];
    }

    protected function run(Request $request): Response
    {
        $productId = null;
        if (filled($ref = $request->get('product'))) {
            $productId = app(\App\Services\Api\CatalogApi::class)->find((string) $ref)?->id
                ?? throw new \App\Mcp\ToolError("No product matches \"{$ref}\". Try search_products.");
        }

        $reviews = Review::query()->with('product:id,name,slug')
            ->when($request->get('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($productId, fn ($q, $id) => $q->where('product_id', $id))
            ->latest('id')
            ->take(max(1, min(100, (int) ($request->get('limit') ?? 20))))
            ->get();

        $tz = config('store.timezone', 'Asia/Dhaka');

        return $this->json([
            'pending_total' => Review::where('status', 'pending')->count(),
            'reviews' => $reviews->map(fn (Review $r) => [
                'id' => $r->id,
                'status' => $r->status,
                'rating' => (int) $r->rating,
                'title' => $r->title,
                'body' => $r->body,
                'author' => $r->author_name,
                'verified_buyer' => (bool) $r->is_verified_buyer,
                'photos' => count((array) $r->photos),
                'product' => $r->product ? ['id' => $r->product->id, 'name' => $r->product->name] : null,
                'created_at' => $r->created_at?->timezone($tz)->toDateTimeString(),
            ])->all(),
        ]);
    }
}
