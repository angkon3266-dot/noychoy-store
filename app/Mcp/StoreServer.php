<?php

namespace App\Mcp;

use App\Mcp\Tools;
use Laravel\Mcp\Server;

/**
 * The Claude connector — this store as an MCP server at /mcp.
 *
 * Every tool extends {@see Tools\StoreTool}: it is listed only when the
 * token's admin user can reach its section, and every call is audited.
 * Destructive and customer-facing tools say so in their descriptions and
 * carry destructiveHint, so Claude asks the owner before calling them.
 */
class StoreServer extends Server
{
    protected string $name = 'Store connector';

    protected string $version = '2.0.0';

    /** All tools fit on one tools/list page; some clients never page. */
    public int $maxPaginationLength = 100;

    public int $defaultPaginationLength = 100;

    protected array $tools = [
        Tools\StoreInfo::class,

        Tools\Products\SearchProducts::class,
        Tools\Products\GetProduct::class,
        Tools\Products\CreateProduct::class,
        Tools\Products\UpdateProduct::class,
        Tools\Products\SetProductVariants::class,
        Tools\Products\PublishProduct::class,
        Tools\Products\UnpublishProduct::class,
        Tools\Products\DeleteProduct::class,
        Tools\Products\UploadProductImage::class,
        Tools\Products\SetPrimaryImage::class,
        Tools\Products\DeleteProductImage::class,
        Tools\Reports\StockSummary::class,

        Tools\Catalog\ListCategories::class,
        Tools\Catalog\CreateCategory::class,
        Tools\Catalog\UpdateCategory::class,
        Tools\Catalog\ListCollections::class,
        Tools\Catalog\CreateCollection::class,
        Tools\Catalog\UpdateCollection::class,
        Tools\Catalog\GetMenu::class,
        Tools\Catalog\UpdateMenu::class,

        Tools\Orders\ListOrders::class,
        Tools\Orders\GetOrder::class,
        Tools\Orders\UpdateOrderStatus::class,

        Tools\Customers\FindCustomers::class,
        Tools\Customers\GetCustomer::class,
        Tools\Customers\SendCustomerSms::class,

        Tools\Marketing\ListCoupons::class,
        Tools\Marketing\CreateCoupon::class,
        Tools\Marketing\ListOffers::class,
        Tools\Marketing\CreateOffer::class,

        Tools\Reviews\ListReviews::class,
        Tools\Reviews\ModerateReview::class,
        Tools\Reviews\DeleteReview::class,

        Tools\Reports\SalesSummary::class,
    ];

    protected function boot(): void
    {
        $this->name = store_name().' store';
        $this->instructions = 'You are managing the online store '.store_name().' ('.url('/').') through its admin connector. '
            .'Prices are in '.config('store.currency', 'BDT').'; times are '.config('store.timezone', 'Asia/Dhaka').'. '
            .'Call store_info first to see which admin user this connection acts as and what that role may do. '
            .'New products start as drafts: create_product → upload_product_image → set_product_variants (sizes/colours) → publish_product. '
            .'Tools marked DESTRUCTIVE or CUSTOMER-FACING (deleting, changing an order status, texting a customer, replacing the menu) '
            .'need the owner\'s explicit go-ahead in this conversation before you call them, and take confirm=true. '
            .'Nothing here books a courier. Every call is recorded in the store\'s audit log.';
    }
}
