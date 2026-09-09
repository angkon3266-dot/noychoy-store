<?php

namespace Tests\Feature;

use App\Services\Meta\Exceptions\MetaApiException;
use App\Services\Meta\MetaGraphClient;
use App\Services\Meta\MetaSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `items_batch` identifies an item by `data.id`, and `id` there means the
 * retailer id. Sending it as a sibling `retailer_id` instead is not an error
 * Meta shouts about: it answers HTTP 200 with a `validation_status` body and
 * silently drops the whole batch.
 *
 * That combination is how this store's catalogue sync logged "success" on every
 * run for months while Commerce Manager never saw a single field we sent.
 */
class MetaItemsBatchWireFormatTest extends TestCase
{
    use RefreshDatabase;

    protected function client(): MetaGraphClient
    {
        $settings = app(MetaSettings::class);
        $settings->setToken('test-token');
        $settings->update(['enabled' => true, 'catalog_id' => 'cat-1']);

        return app(MetaGraphClient::class);
    }

    /** The `requests` array as it actually went over the wire. */
    protected function sentRequests(): array
    {
        $sent = null;
        Http::assertSent(function ($request) use (&$sent) {
            $sent = json_decode($request['requests'], true);

            return true;
        });

        return $sent;
    }

    public function test_the_retailer_id_is_sent_as_data_id(): void
    {
        Http::fake(['*/items_batch*' => Http::response(['handles' => ['h1']])]);

        $this->client()->itemsBatch('cat-1', [[
            'method' => 'UPDATE',
            'retailer_id' => 'prod-181',
            'data' => ['retailer_id' => 'prod-181', 'title' => 'Crimson Bloom'],
        ]]);

        $sent = $this->sentRequests();

        $this->assertSame('prod-181', $sent[0]['data']['id']);
        $this->assertSame('Crimson Bloom', $sent[0]['data']['title']);
        // The sibling form is what Meta rejects, so it must not survive.
        $this->assertArrayNotHasKey('retailer_id', $sent[0]);
        $this->assertArrayNotHasKey('retailer_id', $sent[0]['data']);
    }

    public function test_a_delete_carries_the_id_even_with_no_data_block(): void
    {
        Http::fake(['*/items_batch*' => Http::response(['handles' => ['h1']])]);

        $this->client()->itemsBatch('cat-1', [[
            'method' => 'DELETE',
            'retailer_id' => 'prod-9',
        ]]);

        $sent = $this->sentRequests();

        $this->assertSame('DELETE', $sent[0]['method']);
        $this->assertSame('prod-9', $sent[0]['data']['id']);
    }

    public function test_a_rejected_batch_is_an_error_even_though_meta_answers_200(): void
    {
        Http::fake(['*/items_batch*' => Http::response([
            'validation_status' => [['errors' => [['message' => 'Can not find required field id']]]],
        ])]);

        $this->expectException(MetaApiException::class);
        $this->expectExceptionMessage('Can not find required field id');

        $this->client()->itemsBatch('cat-1', [[
            'method' => 'UPDATE', 'retailer_id' => 'prod-1', 'data' => ['title' => 'x'],
        ]]);
    }

    public function test_a_validation_rejection_is_not_retried(): void
    {
        Http::fake(['*/items_batch*' => Http::response([
            'validation_status' => [['errors' => [['message' => 'Invalid price format']]]],
        ])]);

        try {
            $this->client()->itemsBatch('cat-1', [[
                'method' => 'UPDATE', 'retailer_id' => 'prod-1', 'data' => ['title' => 'x'],
            ]]);
            $this->fail('Expected a MetaApiException.');
        } catch (MetaApiException $e) {
            $this->assertSame(MetaApiException::VALIDATION, $e->category);
            // Re-sending the same malformed payload would fail identically.
            $this->assertFalse($e->isRetryable());
        }
    }

    /**
     * Meta acknowledges a batch by handing back a handle per accepted chunk. A
     * 200 with no handles queued nothing, so it cannot be recorded as synced.
     */
    public function test_a_response_with_no_handles_is_treated_as_a_failure(): void
    {
        Http::fake(['*/items_batch*' => Http::response([])]);

        $this->expectException(MetaApiException::class);
        $this->expectExceptionMessage('no batch handles');

        $this->client()->itemsBatch('cat-1', [[
            'method' => 'UPDATE', 'retailer_id' => 'prod-1', 'data' => ['title' => 'x'],
        ]]);
    }

    public function test_a_clean_batch_returns_the_handles(): void
    {
        Http::fake(['*/items_batch*' => Http::response(['handles' => ['h1', 'h2']])]);

        $response = $this->client()->itemsBatch('cat-1', [[
            'method' => 'UPDATE', 'retailer_id' => 'prod-1', 'data' => ['title' => 'x'],
        ]]);

        $this->assertSame(['h1', 'h2'], $response['handles']);
    }
}
