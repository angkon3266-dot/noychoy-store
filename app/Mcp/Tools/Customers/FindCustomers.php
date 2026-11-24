<?php

namespace App\Mcp\Tools\Customers;

use App\Models\Customer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class FindCustomers extends CustomerTool
{
    protected string $name = 'find_customers';

    protected string $description = 'Look customers up by phone number (any format: 017…, +88017…), name or email. Returns order count, total spent, loyalty points and when they last ordered. Without a query, lists the most recent customers.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Phone, name or email.'),
            'limit' => $schema->integer()->description('1–50, default 10.'),
        ];
    }

    protected function run(Request $request): Response
    {
        $q = trim((string) $request->get('query', ''));
        $digits = preg_replace('/\D/', '', $q);

        $customers = Customer::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")
                ->when(strlen($digits) >= 5, fn ($p) => $p->orWhere('phone', 'like', '%'.(strlen($digits) >= 10 ? substr(bd_phone($digits), -10) : $digits).'%'))))
            ->latest('id')
            ->take(max(1, min(50, (int) ($request->get('limit') ?? 10))))
            ->get();

        return $this->json(['customers' => $customers->map(fn (Customer $c) => self::summary($c))->all()]);
    }
}
