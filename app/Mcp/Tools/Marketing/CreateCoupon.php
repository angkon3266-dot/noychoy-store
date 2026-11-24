<?php

namespace App\Mcp\Tools\Marketing;

use App\Http\Controllers\Admin\CouponController;
use App\Mcp\Support\AdminAction;
use App\Mcp\Tools\StoreTool;
use App\Models\Coupon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/** Saved through the admin CouponController: same validation, same phone-list handling. */
class CreateCoupon extends StoreTool
{
    use MarketingInput;

    protected ?string $section = 'coupons';

    protected string $name = 'create_coupon';

    protected string $description = 'Create a discount code shoppers type at checkout (active immediately unless is_active=false). Fixed amount (type=fixed, value in BDT) or percentage (type=percent). Restrict it to categories or products, a minimum order, a usage limit, dates, or to a list of customer phone numbers (audience=phones) — such codes apply themselves at checkout for exactly those numbers. Nobody is texted.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'code' => $schema->string()->description('What shoppers type, e.g. EID20. Stored upper-case; must be unique.')->required(),
            'type' => $schema->string()->enum(['fixed', 'percent'])->required(),
            'value' => $schema->number()->description('BDT for fixed, 1–100 for percent.')->required(),
            'applies_to' => $schema->string()->enum(['all', 'categories', 'products'])->description('Default all.'),
            'categories' => $schema->array()->items($schema->string())->description('With applies_to=categories: ids, slugs or names.'),
            'products' => $schema->array()->items($schema->string())->description('With applies_to=products: ids, slugs or SKUs.'),
            'exclude_sale_items' => $schema->boolean(),
            'min_order' => $schema->number()->description('Minimum order subtotal in BDT.'),
            'min_qty' => $schema->integer(),
            'usage_limit' => $schema->integer()->description('Total uses across everyone.'),
            'per_customer_limit' => $schema->integer(),
            'free_shipping' => $schema->boolean(),
            'starts_at' => $schema->string()->description('YYYY-MM-DD or YYYY-MM-DD HH:MM.'),
            'expires_at' => $schema->string()->description('YYYY-MM-DD or YYYY-MM-DD HH:MM.'),
            'is_active' => $schema->boolean(),
            'auto_apply' => $schema->boolean()->description('Apply itself at checkout for whoever qualifies, without typing the code.'),
            'audience' => $schema->string()->enum(['all', 'phones', 'rule']),
            'phones' => $schema->array()->items($schema->string())->description('With audience=phones: Bangladeshi mobile numbers (01XXXXXXXXX).'),
        ];
    }

    protected function run(Request $request): Response
    {
        $args = self::scopeIds($request->all());
        if (isset($args['phones'])) {
            $args['recipient_phones'] = implode("\n", array_map('strval', (array) $args['phones']));
            $args['recipient_phones_mode'] = 'sync';
            $args['audience'] = $args['audience'] ?? 'phones';
        }
        unset($args['phones']);

        $input = self::flags(array_merge(['applies_to' => 'all', 'is_active' => true, 'audience' => 'all'], $args),
            ['is_active', 'auto_apply', 'free_shipping', 'exclude_sale_items']);

        $message = AdminAction::run(CouponController::class, 'store', $input);
        $coupon = Coupon::where('code', strtoupper((string) $input['code']))->first();

        return $this->json([
            'message' => $message,
            'coupon' => $coupon?->only(['id', 'code', 'type', 'value', 'applies_to', 'min_order', 'usage_limit', 'expires_at', 'is_active', 'audience', 'auto_apply']),
            'phone_list' => $coupon?->audience === 'phones' ? $coupon->recipients()->count() : null,
        ]);
    }
}
