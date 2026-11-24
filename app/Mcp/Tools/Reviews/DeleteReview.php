<?php

namespace App\Mcp\Tools\Reviews;

use App\Http\Controllers\Admin\ReviewController;
use App\Mcp\Support\AdminAction;
use App\Mcp\ToolError;
use App\Mcp\Tools\StoreTool;
use App\Models\Review;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class DeleteReview extends StoreTool
{
    protected ?string $section = 'reviews';

    protected string $name = 'delete_review';

    protected string $description = 'DESTRUCTIVE — ask the store owner first. Permanently deletes a review; it cannot be recovered. To take a review off the storefront but keep it, use moderate_review with status=hidden instead. Requires confirm=true.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'review_id' => $schema->integer()->required(),
            'confirm' => $schema->boolean()->description('Must be true — only after the owner agreed.')->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $this->requireConfirm($request, 'delete this review');
        $review = Review::find((int) $this->need($request, 'review_id'))
            ?? throw new ToolError('No review with that id. Call list_reviews.');

        $message = AdminAction::run(ReviewController::class, 'destroy', [], ['review' => $review]);

        return $this->json(['message' => $message, 'deleted' => true, 'id' => $review->id]);
    }
}
