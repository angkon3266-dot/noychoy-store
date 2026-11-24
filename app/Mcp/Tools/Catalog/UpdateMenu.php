<?php

namespace App\Mcp\Tools\Catalog;

use App\Http\Controllers\Admin\MenuController;
use App\Mcp\Support\AdminAction;
use App\Mcp\ToolError;
use App\Mcp\Tools\StoreTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class UpdateMenu extends StoreTool
{
    protected ?string $section = 'menu';

    protected string $name = 'update_menu';

    protected string $description = 'CUSTOMER-FACING — ask the store owner first. REPLACES the whole storefront navigation menu with the items you pass; every shopper sees it immediately. Call get_menu first, change what you need, and send the complete list back. The previous menu is returned so it can be restored. Item shape: {"label":"Rings","type":"link|dropdown|mega","url":"https://…","new_tab":false,"badge":"New","children":[{"label":"…","url":"…"}],"columns":[{"heading":"…","links":[{"label":"…","url":"…"}]}]}. To link a collection use {"target":"collection","collection_id":12}. Requires confirm=true.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->items($schema->object())->description('The complete menu, top-level items in order.')->required(),
            'menu_desktop_trigger' => $schema->string()->enum(['hover', 'click'])->description('How desktop dropdowns open. Unchanged when omitted.'),
            'menu_show_search' => $schema->boolean(),
            'menu_cta_label' => $schema->string()->description('Optional highlighted button in the menu bar.'),
            'menu_cta_link' => $schema->string(),
            'confirm' => $schema->boolean()->description('Must be true — only after the owner agreed.')->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $this->requireConfirm($request, 'replace the storefront menu');
        $items = $request->get('items');
        if (! is_array($items) || $items === []) {
            throw new ToolError('items must be the complete, non-empty menu. Call get_menu to start from the current one.');
        }

        $previous = site_menu();
        $theme = theme();

        $input = [
            'menu_json' => json_encode(array_values($items)),
            'menu_desktop_trigger' => $request->get('menu_desktop_trigger') ?? ($theme['menu_desktop_trigger'] ?? 'hover'),
            'menu_show_search' => filter_var($request->get('menu_show_search') ?? ($theme['menu_show_search'] ?? false), FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'menu_cta_label' => $request->get('menu_cta_label') ?? ($theme['menu_cta_label'] ?? null),
            'menu_cta_link' => $request->get('menu_cta_link') ?? ($theme['menu_cta_link'] ?? null),
        ];

        $message = AdminAction::run(MenuController::class, 'update', $input);

        return $this->json(['message' => $message, 'items' => site_menu(), 'previous_items' => $previous]);
    }
}
