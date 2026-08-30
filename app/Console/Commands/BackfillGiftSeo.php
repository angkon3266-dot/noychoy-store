<?php

namespace App\Console\Commands;

use App\Models\Collection;
use App\Models\Product;
use App\Support\Seo\Meta;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Puts every published product into at least one gift collection, and rewrites
 * the catalogue's meta descriptions for a Bangladeshi shopper.
 *
 * Two problems this closes.
 *
 * **The gift collections were empty.** Four smart collections hang off the
 * homepage's "shop by occasion" tiles, all four rule-matched on tags, and
 * almost nothing carried the tags: Birthday Gift held 1 product, Gift for Her
 * held 0, and Date Night matched on `birthday` — a copy-paste, so it was the
 * same collection as Birthday Gift under a different name. Four tiles, three
 * of them leading somewhere empty.
 *
 * **The meta descriptions were written for the wrong country.** Not one of the
 * 102 that existed mentioned Bangladesh, cash on delivery, or a price, and only
 * three said "gift" — while the queries this shop needs to win are "birthday
 * gift for girlfriend bd", "anniversary gift bd", "gift for her". Roughly forty
 * opened with the words "Discover our", spending the most valuable characters
 * in the snippet on nothing.
 *
 * Rules over hand-picked id lists, deliberately: the catalogue grows, and a
 * frozen list would leave every future product out of every collection. Run it
 * again after adding stock and the new pieces are placed.
 *
 * Idempotent. `--dry-run` prints the exact before/after and writes nothing.
 */
class BackfillGiftSeo extends Command
{
    protected $signature = 'catalog:gift-seo
        {--dry-run : Print every change and write nothing}
        {--tags-only : Skip the meta descriptions}
        {--descriptions-only : Skip the tags and the collection rule}
        {--samples=0 : Print this many products in full, exactly as Google would see them}';

    protected $description = 'Tag products into the gift collections and rewrite meta descriptions for Bangladesh';

    /**
     * Romance and milestones. Bridal and wedding pieces belong here too — in
     * Bangladesh they are bought for anniversaries as often as for the wedding.
     */
    private const ANNIVERSARY = [
        'heart', 'love', 'eternal', 'eternity', 'infinite', 'infinity', 'promise',
        'forever', 'romantic', 'engagement', 'bridal', 'wedding', 'halo',
        'solitaire', 'moonstone', 'rose quartz', 'tennis', 'anniversary',
    ];

    /**
     * Pieces that catch light across a table. Kept to shapes, not adjectives:
     * "radiant", "luminous" and "sparkle" appear in half the product names in
     * this catalogue, so matching on them put almost everything in here and
     * made the collection meaningless. "Drop" and "cluster" went the same way.
     */
    private const DATE_NIGHT = [
        'statement', 'cocktail', 'cascade', 'tassel', 'hoop', 'huggie',
        'dangle', 'chandelier',
    ];

    /**
     * The curated shortlist. "Gift for Her" is the one collection the owner
     * asked to stay hand-sized rather than hold the whole catalogue, so it is
     * the giftable middle: an obvious gift motif, in the price band people
     * actually spend on someone else.
     */
    private const GIFT_MOTIFS = [
        'pearl', 'heart', 'butterfly', 'blossom', 'bloom', 'floral', 'flower',
        'garden', 'tennis', 'hoop', 'stud',
    ];

    /** Wedding pieces: Anniversary's, not Birthday Gift's. */
    private const BRIDAL = ['bridal', 'wedding', 'engagement'];

    private const GIFT_MIN_PRICE = 850;

    private const GIFT_MAX_PRICE = 2000;

    /**
     * Above this a piece reads as a wedding or milestone purchase rather than a
     * birthday present — the bridal sets sit at ৳6,500 — so it goes to
     * Anniversary or Date Night on its own merits instead.
     */
    private const BIRTHDAY_MAX_PRICE = 2000;

    /**
     * Two or three phrasings per combination, chosen by product id. One
     * sentence repeated 105 times reads like a form letter — to a shopper
     * scanning a results page, and to Google looking at a catalogue of
     * near-identical snippets.
     *
     * @var array<string, list<string>>
     */
    private const OCCASION_COPY = [
        'anniversary+datenight' => [
            'A romantic anniversary piece with enough sparkle for a night out.',
            'Made for anniversaries, dressed for date night.',
        ],
        'anniversary' => [
            'Made for anniversaries and the milestones worth marking.',
            'A romantic gift for the years you have counted together.',
        ],
        'birthday+datenight' => [
            'A birthday gift with date-night sparkle.',
            'Birthday present by day, date-night piece by night.',
        ],
        'birthday' => [
            'A birthday gift she will actually wear.',
            'The kind of birthday present that gets worn, not stored.',
        ],
        'datenight' => [
            'Built to catch the light on a night out.',
            'Quiet by day, unmistakable under low light.',
        ],
        'default' => [
            'A gift for her that needs no occasion.',
            'A thoughtful gift for her, whatever the day.',
        ],
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        if ($dry) {
            $this->warn('DRY RUN — nothing will be written.');
        }

        if (! $this->option('descriptions-only')) {
            $this->fixDateNightRule($dry);
        }

        $products = Product::where('status', 'published')->orderBy('id')->get();
        $this->info("Published products: {$products->count()}");

        $tagChanges = 0;
        $descChanges = 0;
        $rows = [];
        $projected = ['anniversary' => 0, 'birthday' => 0, 'datenight' => 0, 'giftforher' => 0];

        foreach ($products as $product) {
            $existing = $this->tagList($product);
            $tags = $existing;
            $occasions = [];

            if (! $this->option('descriptions-only')) {
                $tags = $this->occasionTags($product, $existing);
                $occasions = array_values(array_intersect(
                    ['anniversary', 'birthday', 'datenight', 'giftforher'],
                    array_map('strtolower', $tags),
                ));
            } else {
                $occasions = array_values(array_intersect(
                    ['anniversary', 'birthday', 'datenight', 'giftforher'],
                    array_map('strtolower', $existing),
                ));
            }

            foreach ($occasions as $occasion) {
                $projected[$occasion]++;
            }

            $dirty = [];

            if (! $this->option('descriptions-only') && $tags !== $existing) {
                $dirty['tags'] = implode(', ', $tags);
                $tagChanges++;
            }

            if (! $this->option('tags-only')) {
                $description = $this->description($product, $occasions);

                if ($description !== (string) $product->meta_description) {
                    $dirty['meta_description'] = $description;
                    $descChanges++;
                }
            }

            if (! $dirty) {
                continue;
            }

            $rows[] = [
                $product->id,
                Str::limit($product->name, 34),
                implode(',', $occasions) ?: '—',
                Str::limit($dirty['meta_description'] ?? '(tags only)', 74),
            ];

            if (! $dry) {
                // Quiet update: no model events, so this backfill does not
                // enqueue 105 Meta catalogue syncs or bust the homepage cache
                // once per product. Sync the catalogue deliberately afterwards.
                Product::whereKey($product->id)->update($dirty);
            }
        }

        $this->table(['ID', 'Product', 'Occasions', 'Meta description (stored — price is appended at render)'], $rows);

        if ($samples = (int) $this->option('samples')) {
            $this->sample($products, $samples);
        }

        $this->newLine();
        $this->info("Tag changes: {$tagChanges}   Description changes: {$descChanges}");
        $this->reportCollections($projected);

        if ($dry) {
            $this->newLine();
            $this->warn('Nothing was written. Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }

    /**
     * Date Night rule-matched on the tag `birthday`, which made it a duplicate
     * of Birthday Gift. Only corrected when it still says that, so an owner who
     * has since edited the rule is not overruled.
     */
    private function fixDateNightRule(bool $dry): void
    {
        $collection = Collection::where('slug', 'date-night')->first();

        if (! $collection) {
            return;
        }

        $rules = (array) ($collection->rules ?? []);
        $wrong = count($rules) === 1
            && ($rules[0]['field'] ?? null) === 'tag'
            && strtolower((string) ($rules[0]['value'] ?? '')) === 'birthday';

        if (! $wrong) {
            $this->line('Date Night rule: already corrected or hand-edited — left alone.');

            return;
        }

        $this->warn('Date Night matched on the tag "birthday" — the same rule as Birthday Gift. Correcting to "datenight".');

        if (! $dry) {
            $collection->update([
                'rules' => [['field' => 'tag', 'operator' => 'contains', 'value' => 'datenight']],
            ]);
        }
    }

    /**
     * The product's tags after placement. Existing tags are never dropped —
     * they carry the owner's own vocabulary (`kaner dul`, `Premium stone`) and
     * some feed the filter sidebar.
     *
     * @param  list<string>  $existing
     * @return list<string>
     */
    private function occasionTags(Product $product, array $existing): array
    {
        $tags = $existing;

        // One product was tagged `git`. It has been failing every rule that
        // looks for `gift` ever since.
        $tags = array_map(fn ($t) => strtolower($t) === 'git' ? 'gift' : $t, $tags);

        // The product NAME, and nothing else.
        //
        // Not the marketing copy: every description in this catalogue reaches
        // for the same register, so matching on it put a plain silver ring in
        // Anniversary because its blurb said "lovers". And not the tag list
        // either — around fifty products carry an identical bulk-applied set
        // ("Statement, gift, romantic"), which swept the same fifty into both
        // Anniversary and Date Night and left the two collections as copies of
        // each other. The name is the one field written per product.
        //
        // An occasion tag already set by hand is honoured below, separately.
        $haystack = mb_strtolower($product->name);
        $named = fn (string $tag) => $this->hasTag($existing, $tag);

        $price = (float) $product->price;
        $add = [];

        $anniversary = $this->matches($haystack, self::ANNIVERSARY)
            || $named('anniversary') || $named('love');

        if ($anniversary) {
            $add[] = 'anniversary';
        }

        if ($this->matches($haystack, self::DATE_NIGHT) || $named('datenight')) {
            $add[] = 'datenight';
        }

        // The broad one, and the catch-all: anything a person would plausibly
        // buy as a present. Bridal and wedding pieces are held out — they are
        // an Anniversary purchase, and a wedding set in "Birthday Gift" makes
        // the page read as an undifferentiated dump of the whole catalogue.
        if ($named('birthday')
            || ($price <= self::BIRTHDAY_MAX_PRICE && ! $this->matches($haystack, self::BRIDAL))) {
            $add[] = 'birthday';
        }

        if ($price >= self::GIFT_MIN_PRICE
            && $price <= self::GIFT_MAX_PRICE
            && $this->matches($haystack, self::GIFT_MOTIFS)) {
            $add[] = 'giftforher';
        }

        // Nothing may fall through every collection — an untagged product is
        // one the occasion tiles can never reach. Birthday is the catch-all
        // rather than Gift for Her, which the owner asked to keep curated.
        if (! $add) {
            $add[] = 'birthday';
        }

        foreach ($add as $tag) {
            if (! $this->hasTag($tags, $tag)) {
                $tags[] = $tag;
            }
        }

        return array_values($tags);
    }

    /** @param  list<string>  $needles */
    private function matches(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $tags */
    private function hasTag(array $tags, string $tag): bool
    {
        foreach ($tags as $existing) {
            if (strcasecmp(trim($existing), $tag) === 0) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function tagList(Product $product): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $product->tags)
        ), fn ($t) => $t !== ''));
    }

    /**
     * The stored description: the product's own sentence, then the occasion it
     * is being sold for. No price and no delivery promise — App\Support\Seo\Meta
     * appends those at render time from the live price, so a repricing cannot
     * leave 105 snippets quoting a number the page no longer charges.
     *
     * @param  list<string>  $occasions
     */
    private function description(Product $product, array $occasions): string
    {
        $lead = plain_copy(strip_tags((string) ($product->short_description ?: $product->description)));
        $lead = trim(preg_replace('/\s+/u', ' ', $lead) ?? '');

        // "Discover our stunning…" spent the first and most valuable words of
        // the snippet on nothing at all.
        $lead = preg_replace('/^discover (our |the )?/i', '', $lead) ?: $lead;
        $lead = Str::ucfirst($lead);

        $occasion = $this->occasionSentence($product, $occasions);

        // Budget against what the rendered snippet will be, not the stored
        // string: Meta::productDescription adds roughly 50 characters of price
        // and cash-on-delivery on top of this.
        $tail = 'Price '.config('store.currency_symbol', '৳')
            .number_format((float) $product->price).'. Cash on delivery all over Bangladesh.';

        $room = 158 - mb_strlen($tail) - mb_strlen($occasion) - 1;
        $lead = $this->trimToFit($lead, max(48, $room));

        return trim($lead.'. '.$occasion);
    }

    /**
     * Shorten to fit without cutting a word in half. Prefers ending on the
     * product's own first sentence, because a snippet that stops at a full stop
     * reads as written rather than as truncated.
     */
    private function trimToFit(string $text, int $limit): string
    {
        $clean = fn (string $s) => rtrim(trim($s), " \t.,;:—-");

        if (mb_strlen($text) <= $limit) {
            return $clean($text);
        }

        // First sentence, if there is one and it fits.
        if (preg_match('/^(.+?[.!?])\s/u', $text, $m) && mb_strlen($m[1]) <= $limit) {
            return $clean($m[1]);
        }

        $cut = mb_substr($text, 0, $limit);
        $space = mb_strrpos($cut, ' ');

        return $clean($space !== false && $space > 20 ? mb_substr($cut, 0, $space) : $cut);
    }

    /** @param  list<string>  $occasions */
    private function occasionSentence(Product $product, array $occasions): string
    {
        $has = fn (string $t) => in_array($t, $occasions, true);

        $key = match (true) {
            $has('anniversary') && $has('datenight') => 'anniversary+datenight',
            $has('anniversary') => 'anniversary',
            $has('birthday') && $has('datenight') => 'birthday+datenight',
            $has('birthday') => 'birthday',
            $has('datenight') => 'datenight',
            default => 'default',
        };

        $options = self::OCCASION_COPY[$key];

        return $options[$product->id % count($options)];
    }

    /**
     * A handful of products printed whole — the stored description, then the
     * snippet Google actually receives once the live price and the delivery
     * promise are appended. Reading the finished sentence is the only way to
     * catch copy that is grammatical in the template and wrong on the page.
     *
     * @param  \Illuminate\Support\Collection<int, Product>  $products
     */
    private function sample(\Illuminate\Support\Collection $products, int $count): void
    {
        $this->newLine();
        $this->info('Sample — what Google receives:');

        $step = max(1, intdiv($products->count(), $count));

        foreach ($products->values()->filter(fn ($p, $i) => $i % $step === 0)->take($count) as $product) {
            $occasions = array_values(array_intersect(
                ['anniversary', 'birthday', 'datenight', 'giftforher'],
                array_map('strtolower', $this->occasionTags($product, $this->tagList($product))),
            ));

            // Render through the same path the page uses, so what is printed
            // here is what ships — not an approximation of it.
            $product->meta_description = $this->description($product, $occasions);
            $rendered = Meta::productDescription($product);

            $this->newLine();
            $this->line("  <fg=yellow>{$product->name}</> — ".implode(', ', $occasions));
            $this->line('  '.$rendered.' <fg=gray>('.mb_strlen($rendered).' chars)</>');
        }
    }

    /**
     * What each collection holds now, and what it will hold. The projection is
     * the point of the dry run: a rule change and a tag change land together,
     * so counting the live database before either is applied tells you nothing.
     *
     * @param  array<string,int>  $projected
     */
    private function reportCollections(array $projected): void
    {
        $this->newLine();
        $service = app(\App\Services\CollectionService::class);
        $rows = [];

        foreach (Collection::orderBy('id')->get() as $collection) {
            $rules = (array) ($collection->rules ?? []);
            $tag = strtolower((string) ($rules[0]['value'] ?? ''));

            // The one rule this command corrects.
            if ($collection->slug === 'date-night' && $tag === 'birthday') {
                $tag = 'datenight';
            }

            $rows[] = [
                $collection->name,
                '/collection/'.$collection->slug,
                $tag,
                $service->query($collection)->count(),
                $projected[$tag] ?? '?',
            ];
        }

        $this->table(['Collection', 'URL', 'Matches tag', 'Now', 'After'], $rows);
    }
}
