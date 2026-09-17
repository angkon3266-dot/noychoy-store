<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Services\ImageOptimizer;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReviewController extends Controller
{
    /**
     * The review queue — store-wide, or one product's worth.
     *
     * With five hundred reviews across the catalogue, a single paginated list
     * newest-first is no way to find what one piece has been told. So the
     * screen takes a product search: a search that lands on one product opens
     * that product's reviews, a search that matches several lists them with
     * their counts to pick from, and `?product=` is a stable link other
     * screens can point at.
     */
    public function index(Request $request)
    {
        // Query values can arrive as arrays from a hand-edited URL; casting one
        // to a string is a 500, so anything that is not a plain string is
        // treated as absent.
        $q = $request->query('q');
        $term = is_string($q) ? trim($q) : '';
        $statusParam = $request->query('status');
        $statusParam = is_string($statusParam) ? $statusParam : null;
        $productParam = $request->query('product');
        $product = is_string($productParam) && ctype_digit($productParam)
            ? Product::withTrashed()->with('primaryImage')->find((int) $productParam)
            : null;

        $matches = null;
        if (! $product && $term !== '') {
            $matches = $this->findProducts($term);

            // One match is an answer, not a choice.
            if ($matches->count() === 1) {
                return redirect()->route('admin.reviews.index', array_filter([
                    'product' => $matches->first()->id,
                    'status' => $statusParam,
                ]));
            }
        }

        // One product's reviews open on all of them — its approved reviews are
        // usually the ones the owner came to see. The store-wide queue keeps
        // opening on what is waiting for moderation.
        $status = $statusParam ?? ($product ? 'all' : 'pending');

        $scope = fn () => Review::query()
            ->when($product, fn ($q) => $q->where('product_id', $product->id));

        $reviews = $scope()
            ->with('product')
            ->when(in_array($status, array_keys(Review::STATUSES), true), fn ($q) => $q->where('status', $status))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $byStatus = $scope()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('admin.reviews.index', [
            'reviews' => $reviews,
            'statuses' => Review::STATUSES,
            'current' => $status,
            'term' => $term,
            'product' => $product,
            'matches' => $matches,
            'summary' => $product ? $this->ratingSummary($product) : null,
            'products' => Product::orderBy('name')->get(['id', 'name']),
            'picker' => $this->pickerIndex(),
            'counts' => collect(array_keys(Review::STATUSES))
                ->mapWithKeys(fn ($s) => [$s => (int) ($byStatus[$s] ?? 0)])
                ->all(),
        ]);
    }

    /**
     * Products matching what the owner typed, best match first.
     *
     * A number is read as the Product ID the admin shows everywhere (#12), so
     * typing it off a courier label finds the piece — deleted pieces included,
     * since their reviews outlive them — or as a numeric SKU. It never falls
     * through to the word search: "12" once landed on the one product whose
     * description mentions "a set of 12 bangles", as if that were the answer.
     * Words go through the same search the storefront uses, loosening to
     * "any word" before giving up.
     */
    private function findProducts(string $term)
    {
        $withCounts = fn ($q) => $q->with('primaryImage')->withCount([
            'reviews',
            'reviews as pending_count' => fn ($r) => $r->where('status', 'pending'),
            'reviews as approved_count' => fn ($r) => $r->where('status', 'approved'),
        ]);

        $number = ltrim($term, '#');
        if ($number === '') {
            return collect(); // a lone "#" would match every colour code in every description
        }
        if (ctype_digit($number)) {
            // Tiered, first non-empty tier wins. On the live catalogue most SKUs
            // are the same digits as the Product ID, and deleted June imports
            // still carry them — so "#12" matched one live piece and two dead
            // copies, and offered a choice where there was an answer.
            $tiers = [
                fn () => Product::where('serial', (int) $number),
                fn () => Product::onlyTrashed()->where('serial', (int) $number),
                fn () => Product::where('sku', $number),
                fn () => Product::onlyTrashed()->where('sku', $number),
            ];
            foreach ($tiers as $tier) {
                $found = $withCounts($tier())->orderBy('name')->get();
                if ($found->isNotEmpty()) {
                    return $found;
                }
            }

            return collect();
        }

        foreach ([false, true] as $loose) {
            $query = $loose ? Product::searchLoosely($term) : Product::search($term);
            $found = $withCounts(\App\Support\ProductSearch::orderByRelevance($query, $term))
                ->orderBy('name')
                ->limit(30)
                ->get();

            if ($found->isNotEmpty()) {
                return $found;
            }
        }

        return collect();
    }

    /** What the product page shows shoppers: approved reviews only. */
    private function ratingSummary(Product $product): array
    {
        $byRating = Review::where('product_id', $product->id)
            ->where('status', 'approved')
            ->selectRaw('rating, COUNT(*) as n')
            ->groupBy('rating')
            ->pluck('n', 'rating');

        $total = (int) $byRating->sum();

        return [
            'avg' => $total ? round($byRating->reduce(fn ($sum, $n, $r) => $sum + $r * $n, 0) / $total, 1) : null,
            'total' => $total,
            'dist' => collect([5, 4, 3, 2, 1])->mapWithKeys(fn ($r) => [$r => (int) ($byRating[$r] ?? 0)])->all(),
        ];
    }

    /**
     * Every live product, light enough to filter as the owner types.
     *
     * A hundred-odd names with their counts is a few kilobytes — cheaper than
     * a round trip per keystroke, and the list opens instantly.
     */
    private function pickerIndex(): array
    {
        $counts = Review::selectRaw('product_id, status, COUNT(*) as n')
            ->groupBy('product_id', 'status')
            ->get()
            ->groupBy('product_id');

        return Product::orderBy('name')->get(['id', 'name', 'serial', 'sku'])
            ->map(function ($p) use ($counts) {
                $rows = $counts->get($p->id, collect());

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'serial' => $p->serial,
                    'sku' => $p->sku,
                    'total' => (int) $rows->sum('n'),
                    'pending' => (int) ($rows->firstWhere('status', 'pending')->n ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Write down the reviews the store was given somewhere else.
     *
     * Most feedback this store receives never reaches the review form — it
     * arrives in Messenger or WhatsApp, days after delivery, and the owner
     * catches up on a whole product's worth in one sitting. So this takes one
     * product and as many reviews as were typed into it, each with the date it
     * was actually given, and each lands on the product page in order.
     */
    public function store(Request $request, ImageOptimizer $optimizer)
    {
        // Rows the owner opened and left empty are not a validation failure —
        // an extra blank row at the bottom of a form is just how typing goes.
        $request->merge(['reviews' => array_filter(
            (array) $request->input('reviews', []),
            fn ($row) => filled($row['author_name'] ?? null) || filled($row['body'] ?? null),
        )]);

        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'status' => ['required', 'in:'.implode(',', array_keys(Review::STATUSES))],
            'reviews' => ['required', 'array', 'min:1', 'max:25'],
            'reviews.*.author_name' => ['required', 'string', 'max:120'],
            'reviews.*.phone' => ['nullable', 'string', 'max:20'],
            'reviews.*.rating' => ['required', 'integer', 'min:1', 'max:5'],
            'reviews.*.title' => ['nullable', 'string', 'max:150'],
            'reviews.*.body' => ['nullable', 'string', 'max:2000'],
            'reviews.*.reviewed_on' => ['nullable', 'date', 'before_or_equal:'.$this->storeToday()],
            'reviews.*.is_verified_buyer' => ['nullable', 'boolean'],
            'reviews.*.photos' => ['nullable', 'array', 'max:4'],
            'reviews.*.photos.*' => ['image', 'max:5120'],
        ], [
            'reviews.required' => 'Fill in at least one review — a name and what the customer wrote.',
            'reviews.*.author_name.required' => 'Every review needs the customer’s name.',
            'reviews.*.reviewed_on.before_or_equal' => 'A review cannot be dated in the future — check the date you typed.',
        ]);

        $productId = (int) $data['product_id'];
        $saved = 0;
        $seconds = 0;

        foreach ($data['reviews'] as $key => $row) {
            $photos = [];
            foreach ($request->file("reviews.$key.photos", []) as $file) {
                $photos[] = $optimizer->storeWebp($file, 'reviews', 1200, 80);
            }

            $review = new Review([
                'product_id' => $productId,
                'author_name' => $row['author_name'],
                'phone' => $row['phone'] ?? null,
                'rating' => $row['rating'],
                'title' => $row['title'] ?? null,
                'body' => $row['body'] ?? null,
                'photos' => $photos ?: null,
                'is_verified_buyer' => filter_var($row['is_verified_buyer'] ?? false, FILTER_VALIDATE_BOOLEAN)
                    || $this->boughtIt($row['phone'] ?? null, $productId),
                'status' => $data['status'],
            ]);

            // The date is the row's own timestamp rather than a second field
            // the rest of the site would have to learn about — and reviews
            // sharing a date step back a second each, so a batch typed in one
            // sitting keeps the order it was typed in.
            $review->created_at = $this->reviewedAt($row['reviewed_on'] ?? null)->subSeconds($seconds++);
            $review->updated_at = $review->created_at;
            $review->save();

            if ($review->status === 'approved') {
                $this->awardPoints($review);
            }

            $saved++;
        }

        $product = Product::find($productId);

        return back()
            ->with('success', $saved.' review'.($saved === 1 ? '' : 's').' added for '.$product?->name.'.')
            ->with('last_product_id', $productId);
    }

    /** The spreadsheet door: many products' reviews in one go. */
    public function importForm()
    {
        return view('admin.reviews.import', [
            'statuses' => Review::STATUSES,
            'products' => Product::orderBy('name')->get(['id', 'name', 'slug', 'sku']),
        ]);
    }

    /**
     * Import reviews for many products at once, from a CSV or a block pasted
     * straight out of Excel.
     *
     * The per-product form above is for one conversation's worth of feedback.
     * This is for the backlog: months of Messenger and WhatsApp praise across
     * the whole catalogue, already collected in a spreadsheet. Every row names
     * its own product and its own date, so nothing has to be done a product at
     * a time, and a row that cannot be placed is reported rather than guessed.
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => ['nullable', 'file', 'mimes:csv,txt', 'max:5120'],
            'paste' => ['nullable', 'string', 'max:500000'],
            'status' => ['required', 'in:'.implode(',', array_keys(Review::STATUSES))],
        ]);

        $rows = $request->hasFile('file')
            ? $this->rowsFromCsv($request->file('file')->getRealPath())
            : $this->rowsFromPaste((string) $request->input('paste', ''));

        if (is_string($rows)) {
            return back()->with('error', $rows);
        }

        $products = Product::withTrashed()->get(['id', 'name', 'slug', 'sku']);
        $index = [];
        foreach ($products as $p) {
            $index['id:'.$p->id] = $p;
            $index['slug:'.mb_strtolower((string) $p->slug)] = $p;
            $index['name:'.$this->loose($p->name)] = $p;
            if (filled($p->sku)) {
                $index['sku:'.mb_strtolower((string) $p->sku)] = $p;
            }
        }

        $status = $request->input('status');
        $created = 0;
        $reasons = ['no_product' => 0, 'no_name' => 0, 'bad_rating' => 0, 'bad_date' => 0, 'future_date' => 0, 'duplicate' => 0];
        $unknownProducts = [];
        $seconds = [];

        foreach ($rows as $line => $row) {
            $productKey = trim((string) $this->pick($row, ['product', 'product_name', 'product_id', 'item', 'sku', 'slug']));
            $author = trim((string) $this->pick($row, ['name', 'customer', 'customer_name', 'author', 'author_name']));
            $body = trim((string) $this->pick($row, ['review', 'body', 'comment', 'feedback', 'message']));
            $title = trim((string) $this->pick($row, ['title', 'headline']));
            $phone = trim((string) $this->pick($row, ['phone', 'mobile', 'number']));
            $ratingRaw = trim((string) $this->pick($row, ['rating', 'stars', 'star', 'score']));
            $dateRaw = trim((string) $this->pick($row, ['date', 'reviewed_on', 'review_date', 'created_at', 'day']));
            $verifiedRaw = trim((string) $this->pick($row, ['verified', 'verified_buyer', 'is_verified_buyer', 'buyer']));

            $product = $this->matchProduct($productKey, $index);
            if (! $product) {
                $reasons['no_product']++;
                if ($productKey !== '' && count($unknownProducts) < 8 && ! in_array($productKey, $unknownProducts, true)) {
                    $unknownProducts[] = $productKey;
                }

                continue;
            }

            if ($author === '') {
                $reasons['no_name']++;

                continue;
            }

            // A blank rating is the common case in a hand-kept sheet of praise,
            // so it reads as five rather than throwing the row away.
            $rating = $ratingRaw === '' ? 5 : (int) round((float) str_replace(['★', '*'], '', $ratingRaw));
            if ($rating < 1 || $rating > 5) {
                $reasons['bad_rating']++;

                continue;
            }

            $at = $this->parseSheetDate($dateRaw);
            if ($dateRaw !== '' && ! $at) {
                $reasons['bad_date']++;

                continue;
            }
            $at ??= now();

            // A date after today is a date that was misread, not one a customer
            // wrote. A month-first sheet is the usual cause: 09/10/2026 meaning
            // 9 October reads day-first here and lands months ahead, where it
            // sits at the top of the product page dated next winter. The row is
            // handed back rather than published into the future.
            if ($at->isFuture()) {
                $reasons['future_date']++;

                continue;
            }

            // Re-importing a corrected sheet should not double the reviews, so
            // the same person's same words on the same piece land once.
            $duplicate = Review::where('product_id', $product->id)
                ->where('author_name', $author)
                ->when($body !== '', fn ($q) => $q->where('body', $body), fn ($q) => $q->whereNull('body'))
                ->exists();
            if ($duplicate) {
                $reasons['duplicate']++;

                continue;
            }

            // Rows sharing a date step back a second each, so a day's worth of
            // feedback keeps the order the sheet listed it in.
            $key = $at->toDateString();
            $seconds[$key] = ($seconds[$key] ?? 0) + 1;

            $review = new Review([
                'product_id' => $product->id,
                'author_name' => mb_substr($author, 0, 120),
                'phone' => $phone !== '' ? mb_substr($phone, 0, 20) : null,
                'rating' => $rating,
                'title' => $title !== '' ? mb_substr($title, 0, 150) : null,
                'body' => $body !== '' ? mb_substr($body, 0, 2000) : null,
                'is_verified_buyer' => $this->truthy($verifiedRaw) || $this->boughtIt($phone ?: null, (int) $product->id),
                'status' => $status,
            ]);
            $review->created_at = $at->copy()->subSeconds($seconds[$key] - 1);
            $review->updated_at = $review->created_at;
            $review->save();

            if ($status === 'approved') {
                $this->awardPoints($review);
            }

            $created++;
        }

        // "Skipped 40" on its own gives the owner nothing to act on, so every
        // dropped row is accounted for by name.
        $notes = [];
        if ($reasons['no_product']) {
            $notes[] = $reasons['no_product'].' row(s) named a product that is not in the catalogue'
                .($unknownProducts ? ' — '.implode(' · ', $unknownProducts) : '')
                .'. Use the product name exactly as it appears in Products, or its ID.';
        }
        if ($reasons['no_name']) {
            $notes[] = $reasons['no_name'].' row(s) had no customer name.';
        }
        if ($reasons['bad_rating']) {
            $notes[] = $reasons['bad_rating'].' row(s) had a rating outside 1–5.';
        }
        if ($reasons['bad_date']) {
            $notes[] = $reasons['bad_date'].' row(s) had a date that could not be read. Use YYYY-MM-DD (day first for 01/09/2026).';
        }
        if ($reasons['future_date']) {
            $notes[] = $reasons['future_date'].' row(s) were dated after today and were left out.'
                .' Slash dates are read day first, so write 10/09/2026 for 10 September — not 09/10/2026.';
        }
        if ($reasons['duplicate']) {
            $notes[] = $reasons['duplicate'].' row(s) were already in the store and were left alone.';
        }

        $flash = redirect()->route('admin.reviews.index', ['status' => $status])
            ->with('success', $created.' review'.($created === 1 ? '' : 's').' imported.');

        return $notes ? $flash->with('import_errors', $notes) : $flash;
    }

    /** Rows from an uploaded CSV, or a sentence saying why there are none. */
    private function rowsFromCsv(string $path): array|string
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            return 'Could not read the file.';
        }

        $header = fgetcsv($handle);
        if (! $header) {
            fclose($handle);

            return 'The file appears to be empty.';
        }

        $cols = $this->headerColumns($header);
        $rows = [];

        while (($line = fgetcsv($handle)) !== false) {
            if (count(array_filter($line, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue; // the trailing blank line Excel leaves behind
            }
            $rows[] = array_combine($cols, array_pad(array_slice($line, 0, count($cols)), count($cols), null));
        }
        fclose($handle);

        return $rows ?: 'The file had a header but no rows.';
    }

    /**
     * Rows from a block pasted out of a spreadsheet.
     *
     * Copying cells out of Excel puts tab-separated text on the clipboard, so
     * pasting is a shorter road than Save As → CSV → upload, and the one most
     * of this backlog will actually travel.
     */
    private function rowsFromPaste(string $text): array|string
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($text)) ?: [];
        $lines = array_values(array_filter($lines, fn ($l) => trim($l) !== ''));

        if (count($lines) < 2) {
            return 'Paste the header row and at least one review, or choose a CSV file.';
        }

        $delimiter = str_contains($lines[0], "\t") ? "\t" : ',';
        $cols = $this->headerColumns(str_getcsv(array_shift($lines), $delimiter));
        $rows = [];

        foreach ($lines as $line) {
            $cells = str_getcsv($line, $delimiter);
            $rows[] = array_combine($cols, array_pad(array_slice($cells, 0, count($cols)), count($cols), null));
        }

        return $rows;
    }

    /** Header cells, lowercased and stripped of Excel's UTF-8 BOM. */
    private function headerColumns(array $header): array
    {
        $cols = [];
        foreach ($header as $i => $h) {
            $name = mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)));
            $name = str_replace(' ', '_', $name);
            $cols[] = $name !== '' ? $name : 'col_'.$i;
        }

        // Two columns with the same heading would collapse into one.
        return array_values(array_map(
            fn ($name, $i) => array_search($name, $cols, true) === $i ? $name : $name.'_'.$i,
            $cols,
            array_keys($cols),
        ));
    }

    /** The first of these columns the sheet actually has. */
    private function pick(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (filled($row[$key] ?? null)) {
                return (string) $row[$key];
            }
        }

        return null;
    }

    /** A product by id, slug, SKU or name — punctuation and case forgiven. */
    private function matchProduct(string $key, array $index): ?Product
    {
        if ($key === '') {
            return null;
        }

        $lower = mb_strtolower($key);

        return $index['id:'.$key]
            ?? $index['slug:'.$lower]
            ?? $index['sku:'.$lower]
            ?? $index['name:'.$this->loose($key)]
            ?? null;
    }

    /** A name reduced to its letters and digits, for forgiving comparison. */
    private function loose(?string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower((string) $name)));
    }

    /** Yes / y / 1 / true / ✓, in the spellings a spreadsheet carries them. */
    private function truthy(?string $value): bool
    {
        return in_array(mb_strtolower(trim((string) $value)), ['1', 'y', 'yes', 'true', 'verified', '✓', 'হ্যাঁ'], true);
    }

    /**
     * A date cell, read as a Dhaka date.
     *
     * Slash dates are read day-first (01/09/2026 is 1 September), because that
     * is how they are written here and Excel exports the sheet's own spelling.
     */
    private function parseSheetDate(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $tz = config('store.timezone', 'Asia/Dhaka');
        $noon = ' 12:00:00';

        $formats = [
            'Y-m-d', 'Y/m/d',
            'd/m/Y', 'j/n/Y', 'd-m-Y', 'j-n-Y', 'd.m.Y', 'j.n.Y', 'd/m/y', 'j/n/y',
            'd M Y', 'j M Y', 'd F Y', 'j F Y', 'M j, Y', 'F j, Y',
        ];

        foreach ($formats as $format) {
            try {
                $parsed = Carbon::createFromFormat($format.' H:i:s', $value.$noon, $tz);
            } catch (\Throwable) {
                continue;
            }
            // The round trip rejects a format that merely swallowed the string —
            // "13/09/2026" must not come back as a February date.
            if ($parsed && $parsed->format($format) === $value) {
                return $parsed->utc();
            }
        }

        try {
            return Carbon::parse($value, $tz)->startOfDay()->addHours(12)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Edit a review in place — the date included. */
    public function update(Request $request, Review $review)
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'author_name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:150'],
            'body' => ['nullable', 'string', 'max:2000'],
            'reviewed_on' => ['nullable', 'date', 'before_or_equal:'.$this->storeToday()],
            'status' => ['required', 'in:'.implode(',', array_keys(Review::STATUSES))],
            'is_verified_buyer' => ['nullable', 'boolean'],
        ], [
            'reviewed_on.before_or_equal' => 'A review cannot be dated in the future — check the date you typed.',
        ]);

        $wasApproved = $review->status === 'approved';

        $review->fill([
            'product_id' => $data['product_id'],
            'author_name' => $data['author_name'],
            'phone' => $data['phone'] ?? null,
            'rating' => $data['rating'],
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'is_verified_buyer' => $request->boolean('is_verified_buyer'),
            'status' => $data['status'],
        ]);

        // Keep the time of day the review already carries; only the calendar
        // date is the owner's to set, and reviews sharing a date still sort.
        $review->created_at = $this->reviewedAt(
            $data['reviewed_on'] ?? null,
            store_time($review->created_at)->format('H:i:s'),
        );
        $review->save();

        if (! $wasApproved && $review->status === 'approved') {
            $this->awardPoints($review);
        }

        return back()->with('success', 'Review updated.');
    }

    public function updateStatus(Request $request, Review $review)
    {
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', array_keys(Review::STATUSES))],
        ]);

        $review->update($data);

        if ($data['status'] === 'approved') {
            $this->awardPoints($review);
        }

        return back()->with('success', 'Review '.$data['status'].'.');
    }

    public function destroy(Review $review)
    {
        $review->delete();

        return back()->with('success', 'Review deleted.');
    }

    /**
     * A date the owner typed, read as a Dhaka date and stored as UTC.
     *
     * Without the timezone step an evening entry lands on the next day, which
     * is exactly the kind of wrong date this screen exists to fix.
     */
    private function reviewedAt(?string $date, ?string $time = null): Carbon
    {
        $tz = config('store.timezone', 'Asia/Dhaka');

        if (blank($date)) {
            return now();
        }

        return Carbon::parse($date.' '.($time ?: now($tz)->format('H:i:s')), $tz)->utc();
    }

    /**
     * Today's date in the shop's own timezone.
     *
     * The server keeps UTC, so between midnight and 6am in Dhaka "today" on
     * the server is still yesterday — and a review the owner dates today would
     * be refused as being in the future.
     */
    private function storeToday(): string
    {
        return now(config('store.timezone', 'Asia/Dhaka'))->toDateString();
    }

    /** Verified = this phone has an order containing this product. */
    private function boughtIt(?string $phone, int $productId): bool
    {
        if (blank($phone)) {
            return false;
        }

        return Order::where('customer_phone', bd_phone($phone))
            ->whereHas('items', fn ($q) => $q->where('product_id', $productId))
            ->exists();
    }

    /**
     * Loyalty points for an approved review (+ a bonus when it has a photo).
     * The award is keyed to the review, so calling this twice pays once.
     */
    private function awardPoints(Review $review): void
    {
        if (! $review->customer_id) {
            return;
        }

        $loyalty = app(LoyaltyService::class);
        if (! $loyalty->enabled() || ! ($customer = $review->customer)) {
            return;
        }

        $hasPhoto = filled($review->photos);
        $points = $loyalty->reviewPoints() + ($hasPhoto ? $loyalty->reviewPhotoBonus() : 0);
        $loyalty->award($customer, $points, 'earn_review', 'Approved review'.($hasPhoto ? ' (with photo)' : ''), $review);
    }
}
