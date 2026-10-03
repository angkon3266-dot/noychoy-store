<?php

use App\Models\ContentTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Three story rows under the buy box on every product (owner, 3 Oct 2026):
 * "build the section we have on the .com store for each and every product".
 *
 * The .com Shopify store keeps this copy per product (custom.story_N_title /
 * _text). None of it could be carried over: not one of the 110 NoyChoy
 * products is on .com — no SKU, title or handle in common — so every row in
 * database/data/product-story-rows.json was written for this catalogue,
 * against the product's own photos, in the .com format (a 2–3 word heading,
 * at most 30 words): row 1 the design, beside the product video; row 2 the
 * stones and finish, beside photo 2; row 3 the moment, beside photo 3, with
 * Bangladeshi occasions in place of the US ones.
 *
 * What the copy claims about materials stays inside what the product's own
 * specs and description say. Where those disagree with the photograph —
 * "Emerald Radiance" (#266) is aqua, "Bohemian Blue" (#242) is violet, the
 * "tassels" on #244, #324 and #325 are stone fringes — the copy describes the
 * photograph, because that is what the customer is buying.
 *
 * The rows are `auto`: the picture beside each comes from the product itself
 * (App\Support\Storefront\StorySections), so reordering the gallery or adding a
 * video needs no copy change.
 *
 * Writes only to the piece the copy was written for — same id AND same name —
 * and only while it has no story rows of its own, so anything the owner writes
 * in the builder first wins, and re-running changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->stories() as $story) {
            $product = DB::table('products')->where('id', $story['id'])->first(['name', 'content_sections']);

            if (! $product || $product->name !== $story['name']) {
                continue;
            }
            if (ContentTemplate::cleanSections(json_decode((string) $product->content_sections, true)) !== []) {
                continue;
            }

            // The query builder, not the model: a save would queue a knowledge
            // re-sync per product, and the story copy is not part of it.
            DB::table('products')->where('id', $story['id'])->update([
                'content_sections' => json_encode($this->sections($story['rows'])),
            ]);
        }
    }

    public function down(): void
    {
        // Only rows that are still exactly what up() wrote.
        foreach ($this->stories() as $story) {
            $stored = DB::table('products')->where('id', $story['id'])->value('content_sections');

            if ($stored !== null && json_decode($stored, true) === $this->sections($story['rows'])) {
                DB::table('products')->where('id', $story['id'])->update(['content_sections' => null]);
            }
        }
    }

    private function stories(): array
    {
        return json_decode(file_get_contents(database_path('data/product-story-rows.json')), true);
    }

    /** Picture left, right, left — the .com store's rhythm. */
    private function sections(array $rows): array
    {
        return collect($rows)->values()->map(fn ($row, $i) => [
            'media' => 'auto',
            'image' => '',
            'heading' => $row['heading'],
            'body' => $row['body'],
            'layout' => $i % 2 ? 'right' : 'left',
        ])->all();
    }
};
