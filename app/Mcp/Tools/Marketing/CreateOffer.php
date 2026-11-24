<?php

namespace App\Mcp\Tools\Marketing;

use App\Http\Controllers\Admin\OfferController;
use App\Mcp\Support\AdminAction;
use App\Mcp\Tools\StoreTool;
use App\Models\Offer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/** Saved through the admin OfferController: ends_at is read as Dhaka time there. */
class CreateOffer extends StoreTool
{
    use MarketingInput;

    protected ?string $section = 'offers';

    protected string $name = 'create_offer';

    protected string $description = 'CUSTOMER-FACING — ask the owner first: an active offer changes the price every shopper sees immediately. Creates an automatic offer that needs no code: a percentage off the whole order, some categories or some products (type=order_percent), or free shipping (type=free_shipping), optionally above a minimum subtotal or quantity and ending at ends_at (Bangladesh time; the storefront counts down to it). Pass is_active=false to create it switched off.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Shown to shoppers, max 120 characters.')->required(),
            'description' => $schema->string(),
            'type' => $schema->string()->enum(array_keys(Offer::TYPES))->required(),
            'percent' => $schema->number()->description('For order_percent: 0.1–90.'),
            'applies_to' => $schema->string()->enum(array_keys(Offer::SCOPES))->description('Default all.'),
            'categories' => $schema->array()->items($schema->string())->description('With applies_to=categories: ids, slugs or names.'),
            'products' => $schema->array()->items($schema->string())->description('With applies_to=products: ids, slugs or SKUs.'),
            'min_subtotal' => $schema->number(),
            'min_qty' => $schema->integer(),
            'members_only' => $schema->boolean(),
            'badge_label' => $schema->string()->description('Short badge on product cards, e.g. "Eid 15% off".'),
            'show_on_pdp' => $schema->boolean()->description('Show the deal on product pages (default true).'),
            'ends_at' => $schema->string()->description('YYYY-MM-DD HH:MM in Bangladesh time.'),
            'is_active' => $schema->boolean()->description('Default true.'),
        ];
    }

    protected function run(Request $request): Response
    {
        $input = self::flags(array_merge(
            ['applies_to' => 'all', 'is_active' => true, 'show_on_pdp' => true],
            self::scopeIds($request->all()),
        ), ['is_active', 'show_on_pdp', 'members_only']);

        $before = (int) Offer::max('id');
        $message = AdminAction::run(OfferController::class, 'store', $input);
        $offer = Offer::where('id', '>', $before)->latest('id')->first();

        return $this->json([
            'message' => $message,
            'offer' => $offer?->only(['id', 'title', 'type', 'percent', 'applies_to', 'category_ids', 'product_ids', 'is_active', 'ends_at']),
        ]);
    }
}
