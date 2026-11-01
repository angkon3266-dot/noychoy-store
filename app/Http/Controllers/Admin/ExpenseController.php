<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Support\DateRange;
use App\Support\ExpenseReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Admin → Expenses (owner, 2026-09-19: "log expenses related to business with
 * date and all"): what was spent, on which day, on what, paid to whom and how,
 * with a receipt when there is one — and what is left of the orders' profit
 * once it is paid (App\Support\ExpenseReport).
 *
 * Admin-only: User::sectionsFor() gives managers and staff no `expenses`
 * section, because salaries and the net result are the owner's business.
 * Receipts are kept on the private disk and only ever served through here.
 */
class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $range = $this->range($request);

        return view('admin.expenses.index', [
            'range' => $range,
            'expenses' => $this->filtered($request, $range)
                ->with('user:id,name')
                ->orderByDesc('spent_on')->orderByDesc('id')
                ->paginate(50)->withQueryString(),
            'listTotal' => (float) $this->filtered($request, $range)->sum(DB::raw(Expense::TOTAL_SQL)),
            'filtering' => $request->filled('category') || $request->filled('q'),
            'report' => ExpenseReport::for($range),
            'categories' => $this->categories(),
            'paidVia' => Expense::PAID_VIA,
            'defaultVat' => Expense::DEFAULT_VAT_RATE,
            // "Duplicate" on an entry: the log form starts from its details.
            'copy' => $request->filled('copy') ? Expense::find((int) $request->input('copy')) : null,
            'today' => now(config('store.timezone', 'Asia/Dhaka'))->toDateString(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['user_id'] = $request->user()?->id;
        $data['receipt_path'] = $this->storeReceipt($request);

        $expense = Expense::create($data);

        // Back to the page it was logged from, minus "copy": a duplicate is
        // logged once, and the form starts empty again.
        parse_str((string) parse_url(url()->previous(), PHP_URL_QUERY), $query);

        return redirect()->route('admin.expenses.index', Arr::except($query, 'copy'))->with('success', money($expense->total).($expense->hasVat() ? ' with VAT' : '').' for '.$expense->category.' logged.');
    }

    public function update(Request $request, Expense $expense)
    {
        $data = $this->validated($request);

        // A new file replaces the old one; "remove" clears it. Otherwise the
        // receipt already on file stays.
        if ($request->hasFile('receipt') || $request->boolean('remove_receipt')) {
            $this->deleteReceipt($expense);
            $data['receipt_path'] = $this->storeReceipt($request);
        }

        $expense->update($data);

        return back()->with('success', 'Expense updated.');
    }

    public function destroy(Expense $expense)
    {
        $this->deleteReceipt($expense);
        $expense->delete();

        return back()->with('success', 'Expense deleted.');
    }

    /** The receipt, shown in the browser (an image or a PDF). */
    public function receipt(Expense $expense)
    {
        abort_unless($expense->receipt_path && Storage::disk('local')->exists($expense->receipt_path), 404);

        return Storage::disk('local')->response($expense->receipt_path);
    }

    /** The list on screen — same window and filters — as a CSV for Excel. */
    public function export(Request $request)
    {
        $range = $this->range($request);
        $query = $this->filtered($request, $range)->with('user:id,name')->orderBy('spent_on')->orderBy('id');
        $filename = Str::slug(store_name()).'-expenses-'.now()->format('Y-m-d').'.csv';

        // A cell starting with = + - @ is a formula to Excel; text typed into a
        // description must never run as one.
        $cell = fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v;

        return response()->streamDownload(function () use ($query, $cell) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM, so Excel reads Bangla correctly
            fputcsv($out, ['date', 'category', 'amount', 'vat_rate', 'vat', 'total', 'description', 'paid_to', 'paid_via', 'counts_against_profit', 'logged_by', 'logged_at']);

            $query->chunk(500, function ($rows) use ($out, $cell) {
                foreach ($rows as $e) {
                    fputcsv($out, [
                        $e->spent_on->toDateString(),
                        $cell($e->category),
                        number_format((float) $e->amount, 2, '.', ''),
                        $e->vat_rate !== null ? (float) $e->vat_rate : '',
                        number_format((float) $e->vat_amount, 2, '.', ''),
                        number_format($e->total, 2, '.', ''),
                        $cell($e->description),
                        $cell($e->paid_to),
                        $e->paid_via,
                        $e->isDeductible() ? 'yes' : 'no',
                        $e->user?->name,
                        store_time($e->created_at)?->format('Y-m-d H:i'),
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * This month unless a window was picked: bills and salaries arrive by the
     * month, and that is how the owner will look at them.
     */
    protected function range(Request $request): DateRange
    {
        return $request->filled('period') ? DateRange::fromRequest($request) : DateRange::preset('month');
    }

    protected function filtered(Request $request, DateRange $range): Builder
    {
        return Expense::query()->within($range)
            ->when($request->filled('category'), fn ($q) => $q->where('category', (string) $request->input('category')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.addcslashes(trim((string) $request->input('q')), '%_\\').'%';
                $q->where(fn ($w) => $w->where('description', 'like', $term)->orWhere('paid_to', 'like', $term));
            });
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'spent_on' => ['required', 'date'],
            'category' => ['required', 'string', 'max:60'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'paid_to' => ['nullable', 'string', 'max:120'],
            'paid_via' => ['nullable', 'string', 'max:40'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'remove_receipt' => ['nullable', 'boolean'],
        ], [
            'receipt.mimes' => 'A receipt must be a photo (JPG, PNG, WebP) or a PDF.',
            'receipt.max' => 'A receipt can be up to 5 MB.',
        ]);

        $data['category'] = Expense::normaliseCategory($data['category']);

        // VAT is added on top of the amount, never taken out of it. An empty
        // or zero rate is no VAT.
        $data['vat_rate'] = (float) ($data['vat_rate'] ?? 0) > 0 ? (float) $data['vat_rate'] : null;
        $data['vat_amount'] = Expense::vatOn((float) $data['amount'], $data['vat_rate']);

        return Arr::except($data, ['receipt', 'remove_receipt']);
    }

    /** The suggestions first, then every category already used, each once. */
    protected function categories(): array
    {
        return collect(Expense::CATEGORIES)
            ->merge(Expense::query()->distinct()->orderBy('category')->pluck('category'))
            ->unique(fn ($c) => mb_strtolower((string) $c))
            ->values()->all();
    }

    protected function storeReceipt(Request $request): ?string
    {
        return $request->hasFile('receipt') ? $request->file('receipt')->store('expenses', 'local') : null;
    }

    protected function deleteReceipt(Expense $expense): void
    {
        if ($expense->receipt_path) {
            Storage::disk('local')->delete($expense->receipt_path);
        }
    }
}
