<?php

namespace App\Mcp\Tools\Customers;

use App\Mcp\ToolError;
use App\Services\SmsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

/**
 * The admin "Send SMS" box on a customer's page, through the same
 * SmsService::send — logged in SMS logs like any other.
 *
 * Only ever a one-off message the owner asked for. The store's automatic texts
 * (order received, review request) are not the connector's business, and there
 * is no bulk or marketing send here: broadcasts stay in Admin → SMS.
 */
#[IsDestructive]
#[IsOpenWorld]
class SendCustomerSms extends CustomerTool
{
    protected string $name = 'send_customer_sms';

    protected string $description = 'CUSTOMER-FACING — sends a real text message to one customer\'s phone and costs SMS credit; it cannot be recalled. Only send a message the store owner has approved word for word in this conversation. One customer at a time; no marketing blasts. Requires confirm=true.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'customer' => $schema->string()->description('Customer id or phone number.')->required(),
            'message' => $schema->string()->description('The exact text, max 500 characters. Bangla is fine.')->required(),
            'confirm' => $schema->boolean()->description('Must be true — only after the owner approved this exact message.')->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $this->requireConfirm($request, 'text this customer');
        $data = $request->validate(['message' => ['required', 'string', 'max:500']]);

        $customer = $this->customer((string) $this->need($request, 'customer'));
        if (blank($customer->phone)) {
            throw new ToolError('This customer has no phone number.');
        }

        $sms = app(SmsService::class);
        if (! $sms->isEnabled()) {
            throw new ToolError('SMS is switched off or not configured (Admin → Integrations), so nothing was sent.');
        }

        if (! $sms->send($customer->phone, $data['message'])) {
            throw new ToolError('The SMS gateway did not accept the message. Check Admin → SMS logs.');
        }

        return $this->json(['sent' => true, 'to' => $customer->phone, 'customer' => $customer->name, 'characters' => mb_strlen($data['message'])]);
    }
}
