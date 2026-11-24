<?php

namespace App\Mcp\Tools\Marketing;

use App\Mcp\Tools\StoreTool;
use App\Models\Offer;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListOffers extends StoreTool
{
    protected ?string $section = 'offers';

    protected string $name = 'list_offers';

    protected string $description = 'Automatic offers (Admin → Offers): store-wide or category/product percentage discounts and free-shipping deals that apply without a code. While a live offer covers a product, the storefront shows the discounted price everywhere and counts down to ends_at.';

    protected function run(Request $request): Response
    {
        $tz = config('store.timezone', 'Asia/Dhaka');

        return $this->json(['offers' => Offer::orderBy('sort')->orderByDesc('id')->get()->map(fn (Offer $o) => [
            'id' => $o->id,
            'title' => $o->title,
            'description' => $o->description,
            'type' => $o->type,
            'percent' => $o->percent !== null ? (float) $o->percent : null,
            'applies_to' => $o->applies_to,
            'category_ids' => $o->category_ids,
            'product_ids' => $o->product_ids,
            'min_subtotal' => $o->min_subtotal !== null ? (float) $o->min_subtotal : null,
            'min_qty' => $o->min_qty,
            'members_only' => (bool) $o->members_only,
            'badge_label' => $o->badge_label,
            'show_on_pdp' => (bool) $o->show_on_pdp,
            'is_active' => (bool) $o->is_active,
            'ends_at' => $o->ends_at?->timezone($tz)->toDateTimeString(),
            'expired' => (bool) ($o->ends_at && $o->ends_at->isPast()),
        ])->all()]);
    }
}
