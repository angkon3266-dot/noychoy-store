<?php

namespace App\Mcp\Tools\Marketing;

use App\Mcp\Tools\StoreTool;
use App\Models\Coupon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListCoupons extends StoreTool
{
    protected ?string $section = 'coupons';

    protected string $name = 'list_coupons';

    protected string $description = 'Discount codes, newest first: code, fixed or percent value, what they apply to, minimum order, usage so far and limits, expiry, and who can use them. Codes the shop minted itself (spin-wheel prizes) are left out unless include_generated=true.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Part of a code.'),
            'active_only' => $schema->boolean(),
            'include_generated' => $schema->boolean(),
            'limit' => $schema->integer()->description('1–100, default 30.'),
        ];
    }

    protected function run(Request $request): Response
    {
        $tz = config('store.timezone', 'Asia/Dhaka');

        $coupons = Coupon::query()
            ->when(! filter_var($request->get('include_generated', false), FILTER_VALIDATE_BOOLEAN),
                fn ($q) => $q->whereNull('source'))
            ->when($request->get('search'), fn ($q, $s) => $q->where('code', 'like', '%'.$s.'%'))
            ->when(filter_var($request->get('active_only', false), FILTER_VALIDATE_BOOLEAN), fn ($q) => $q->where('is_active', true)
                ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now())))
            ->latest('id')
            ->take(max(1, min(100, (int) ($request->get('limit') ?? 30))))
            ->get();

        return $this->json(['coupons' => $coupons->map(fn (Coupon $c) => [
            'id' => $c->id,
            'code' => $c->code,
            'type' => $c->type,
            'value' => (float) $c->value,
            'applies_to' => $c->applies_to,
            'category_ids' => $c->category_ids,
            'product_ids' => $c->product_ids,
            'min_order' => $c->min_order !== null ? (float) $c->min_order : null,
            'free_shipping' => (bool) $c->free_shipping,
            'used' => (int) $c->used_count,
            'usage_limit' => $c->usage_limit,
            'per_customer_limit' => $c->per_customer_limit,
            'starts_at' => $c->starts_at?->timezone($tz)->toDateTimeString(),
            'expires_at' => $c->expires_at?->timezone($tz)->toDateTimeString(),
            'is_active' => (bool) $c->is_active,
            'auto_apply' => (bool) $c->auto_apply,
            'audience' => $c->audience,
            'source' => $c->source,
        ])->all()]);
    }
}
