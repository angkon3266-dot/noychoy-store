<?php

namespace App\Mcp\Tools\Customers;

use App\Mcp\Tools\Orders\OrderTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetCustomer extends CustomerTool
{
    protected string $name = 'get_customer';

    protected string $description = 'One customer with their notes and recent orders (up to 20). Accepts a customer id or phone number.';

    public function schema(JsonSchema $schema): array
    {
        return ['customer' => $schema->string()->description('Customer id or phone number.')->required()];
    }

    protected function run(Request $request): Response
    {
        $customer = $this->customer((string) $this->need($request, 'customer'));
        $orders = $customer->orders()->withCount('items')->latest('id')->take(20)->get();

        return $this->json(self::summary($customer) + [
            'notes' => $customer->notes,
            'recent_orders' => $orders->map(fn ($o) => OrderTool::summary($o))->all(),
            'admin_url' => route('admin.customers.show', $customer),
        ]);
    }
}
