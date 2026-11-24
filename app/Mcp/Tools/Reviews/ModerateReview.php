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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

/** Through ReviewController::updateStatus, so approving awards the reviewer's points as the admin does. */
#[IsIdempotent]
class ModerateReview extends StoreTool
{
    protected ?string $section = 'reviews';

    protected string $name = 'moderate_review';

    protected string $description = 'Approve a review (it appears on the product page, and a registered reviewer gets their loyalty points, as in the admin panel), hide it, or put it back to pending.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'review_id' => $schema->integer()->required(),
            'status' => $schema->string()->enum(array_keys(Review::STATUSES))->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $review = Review::find((int) $this->need($request, 'review_id'))
            ?? throw new ToolError('No review with that id. Call list_reviews.');

        $message = AdminAction::run(ReviewController::class, 'updateStatus', ['status' => $request->get('status')], ['review' => $review]);

        return $this->json(['message' => $message, 'id' => $review->id, 'status' => $review->fresh()->status]);
    }
}
