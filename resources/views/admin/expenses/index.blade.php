@extends('layouts.admin')
@section('title', 'Expenses')
@section('heading', 'Expenses')

@section('content')
{{-- The layout prints session flashes; validation errors are this page's to show. --}}
@if($errors->any())<div class="mb-4 rounded-md bg-red-50 border border-red-200 text-red-700 px-4 py-2.5 text-sm">{{ $errors->first() }}</div>@endif

@php
    $r = $report;
    // A loss reads "−৳5,000", not money()'s "৳-5,000".
    $signed = fn ($v) => ($v < 0 ? '−' : '').money(abs($v));
    // The window links keep the list's filters.
    $keep = array_filter(request()->only(['category', 'q']), 'filled');
@endphp

{{-- Window: the dashboard's presets, "This month" by default. On a phone
     the strip scrolls sideways rather than wrapping into four rows. --}}
<div class="mb-4" x-data="{ custom: @js($range->isCustom()) }">
    <div class="flex items-start gap-2">
        <div class="min-w-0 flex-1 flex gap-1.5 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden sm:flex-wrap sm:gap-2 sm:overflow-visible">
            @foreach(\App\Support\DateRange::PRESETS as $key => $label)
                <a href="{{ route('admin.expenses.index', ['period' => $key] + $keep) }}"
                   @if($range->key === $key) aria-current="page" @endif
                   class="shrink-0 whitespace-nowrap rounded-lg px-2.5 sm:px-3 py-1.5 text-xs font-medium border transition
                          {{ $range->key === $key ? 'bg-ink-900 text-white border-ink-900' : 'bg-white text-ink-700/70 border-ink-100 hover:border-ink-300' }}">{{ $label }}</a>
            @endforeach
            <button type="button" @click="custom = !custom" :aria-expanded="custom"
                    class="shrink-0 whitespace-nowrap rounded-lg px-2.5 sm:px-3 py-1.5 text-xs font-medium border transition
                           {{ $range->isCustom() ? 'bg-ink-900 text-white border-ink-900' : 'bg-white text-ink-700/70 border-ink-100 hover:border-ink-300' }}">
                {{ $range->isCustom() ? $range->label : 'Custom…' }}
            </button>
        </div>
        <a href="{{ route('admin.expenses.export', request()->query()) }}" class="btn-outline shrink-0 py-1.5 px-3 text-xs"
           title="Download the expenses in this window as a CSV — opens in Excel">Export CSV</a>
    </div>
    <form x-show="custom" x-cloak method="GET" action="{{ route('admin.expenses.index') }}" class="mt-2 flex flex-wrap items-center gap-2">
        <input type="hidden" name="period" value="custom">
        @foreach($keep as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
        <input type="date" name="from" value="{{ $range->isCustom() ? $range->start->toDateString() : '' }}" class="input py-1 text-xs min-w-0 flex-1 sm:flex-none sm:w-auto" required>
        <span class="text-xs text-ink-700/40">to</span>
        <input type="date" name="to" value="{{ $range->isCustom() ? $range->end->toDateString() : '' }}" class="input py-1 text-xs min-w-0 flex-1 sm:flex-none sm:w-auto" required>
        <button class="btn-primary py-1.5 px-3 text-xs">Apply</button>
    </form>
</div>

{{-- The four figures. --}}
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4 sm:mb-6">
    <div class="card min-w-0 p-3 sm:p-4">
        <div class="text-[11px] sm:text-xs text-ink-700/60">Spent</div>
        <div class="text-lg sm:text-xl font-semibold tabular-nums">{{ money($r['spent']) }}</div>
        <div class="text-[11px] sm:text-xs text-ink-700/50">{{ $r['count'] }} {{ \Illuminate\Support\Str::plural('entry', $r['count']) }} · {{ strtolower($range->label) }}</div>
    </div>
    <div class="card min-w-0 p-3 sm:p-4">
        <div class="text-[11px] sm:text-xs text-ink-700/60">Comes off profit</div>
        <div class="text-lg sm:text-xl font-semibold tabular-nums">{{ money($r['expenses']) }}</div>
        <div class="text-[11px] sm:text-xs text-ink-700/50">{{ $r['stock_bought'] > 0 ? 'stock purchases not included' : 'everything logged' }}</div>
    </div>
    <div class="card min-w-0 p-3 sm:p-4">
        <div class="text-[11px] sm:text-xs text-ink-700/60">Sales</div>
        <div class="text-lg sm:text-xl font-semibold tabular-nums">{{ money($r['sales']) }}</div>
        <div class="text-[11px] sm:text-xs text-ink-700/50">{{ $r['orders'] }} {{ \Illuminate\Support\Str::plural('order', $r['orders']) }}, cancelled left out</div>
    </div>
    <div class="card min-w-0 p-3 sm:p-4">
        <div class="text-[11px] sm:text-xs text-ink-700/60">Net profit</div>
        <div class="text-lg sm:text-xl font-semibold tabular-nums {{ $r['net'] < 0 ? 'text-red-600' : 'text-green-700' }}">{{ $signed($r['net']) }}</div>
        <div class="text-[11px] sm:text-xs text-ink-700/50">after product cost &amp; expenses</div>
    </div>
</div>

<div class="grid lg:grid-cols-3 gap-4 sm:gap-6">
    {{-- Log one. First on a phone: it is what the page is opened for. --}}
    <div class="lg:col-span-1">
        <div class="card p-4 sm:p-5 lg:sticky lg:top-4">
            <h2 class="font-semibold mb-3">Log an expense</h2>
            <form action="{{ route('admin.expenses.store') }}" method="POST" enctype="multipart/form-data" class="space-y-2.5">
                @csrf
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="label" for="ex-date">Date *</label>
                        <input id="ex-date" type="date" name="spent_on" value="{{ old('spent_on', $today) }}" class="input" required>
                    </div>
                    <div>
                        <label class="label" for="ex-amount">Amount (৳) *</label>
                        <input id="ex-amount" type="number" name="amount" value="{{ old('amount') }}" min="0.01" step="0.01" inputmode="decimal" class="input" placeholder="0" required>
                    </div>
                </div>
                <div>
                    <label class="label" for="ex-category">Category *</label>
                    <input id="ex-category" name="category" value="{{ old('category') }}" list="expense-categories" class="input" placeholder="Pick one or type your own" required maxlength="60">
                </div>
                <div>
                    <label class="label" for="ex-description">What for</label>
                    <input id="ex-description" name="description" value="{{ old('description') }}" class="input" maxlength="255" placeholder="e.g. Boost for the Eid post">
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="label" for="ex-paid-to">Paid to</label>
                        <input id="ex-paid-to" name="paid_to" value="{{ old('paid_to') }}" class="input" maxlength="120" placeholder="Meta, Steadfast…">
                    </div>
                    <div>
                        <label class="label" for="ex-paid-via">Paid by</label>
                        <select id="ex-paid-via" name="paid_via" class="input">
                            <option value="">—</option>
                            @foreach($paidVia as $via)<option value="{{ $via }}" @selected(old('paid_via') === $via)>{{ $via }}</option>@endforeach
                        </select>
                    </div>
                </div>
                <div>
                    <label class="label" for="ex-receipt">Receipt <span class="font-normal text-ink-700/40">(photo or PDF, optional)</span></label>
                    <input id="ex-receipt" type="file" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf" class="input text-sm">
                </div>
                <button class="btn-primary w-full">Log expense</button>
                <p class="text-[11px] text-ink-700/50 leading-snug">
                    <strong>Stock purchase</strong> is kept as money spent but not taken off profit again: each product's cost price already
                    comes off when it sells. Log courier bills under <strong>Courier &amp; delivery</strong> — the delivery charges customers pay are counted in Sales.
                </p>
            </form>
            <datalist id="expense-categories">
                @foreach($categories as $c)<option value="{{ $c }}"></option>@endforeach
            </datalist>
        </div>
    </div>

    <div class="lg:col-span-2 space-y-4 sm:space-y-6 min-w-0">
        <div class="grid sm:grid-cols-2 gap-4 sm:gap-6">
            {{-- What is left once the bills are paid. --}}
            <div class="card p-4 sm:p-5">
                <h2 class="font-semibold text-sm sm:text-base">Profit after expenses</h2>
                <p class="text-[11px] sm:text-xs text-ink-700/55 mb-2">{{ $range->label }} · orders by the day they were placed</p>
                <dl class="text-[13px] sm:text-sm divide-y divide-ink-50">
                    <div class="flex justify-between gap-3 py-1.5">
                        <dt>Sales <span class="block text-[11px] text-ink-700/50">after discounts, with delivery charges</span></dt>
                        <dd class="tabular-nums whitespace-nowrap">{{ money($r['sales']) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3 py-1.5 text-ink-700/80"><dt>− Product cost</dt><dd class="tabular-nums whitespace-nowrap">{{ money($r['product_cost']) }}</dd></div>
                    <div class="flex justify-between gap-3 py-1.5 font-medium"><dt>= Gross profit</dt><dd class="tabular-nums whitespace-nowrap">{{ $signed($r['gross']) }}</dd></div>
                    <div class="flex justify-between gap-3 py-1.5 text-ink-700/80"><dt>− Expenses</dt><dd class="tabular-nums whitespace-nowrap">{{ money($r['expenses']) }}</dd></div>
                    <div class="flex justify-between gap-3 py-2 text-base font-semibold {{ $r['net'] < 0 ? 'text-red-600' : 'text-green-700' }}"><dt>= Net profit</dt><dd class="tabular-nums whitespace-nowrap">{{ $signed($r['net']) }}</dd></div>
                </dl>
                @if($r['stock_bought'] > 0)
                    <p class="mt-2 text-[11px] sm:text-xs text-ink-700/60">
                        {{ money($r['stock_bought']) }} of stock bought is not in Expenses here — each piece's cost comes off as Product cost when it sells.
                    </p>
                @endif
            </div>

            {{-- Where it went. --}}
            <div class="card p-4 sm:p-5">
                <h2 class="font-semibold text-sm sm:text-base mb-2">By category</h2>
                @forelse($r['by_category'] as $c)
                    <a href="{{ route('admin.expenses.index', array_filter(['period' => request('period'), 'from' => request('from'), 'to' => request('to'), 'category' => $c['category']])) }}"
                       class="block py-1.5 border-b border-ink-50 last:border-0 text-[13px] sm:text-sm hover:text-gold-700">
                        <span class="flex justify-between gap-3">
                            <span class="min-w-0 truncate">{{ $c['category'] }}@unless($c['deducted'])<span class="text-[11px] text-ink-700/50"> · not off profit</span>@endunless</span>
                            <span class="shrink-0 tabular-nums font-medium">{{ money($c['amount']) }}</span>
                        </span>
                        <span class="mt-1 flex items-center gap-2">
                            <span class="h-1.5 flex-1 rounded-full bg-ink-50 overflow-hidden"><span class="block h-full rounded-full {{ $c['deducted'] ? 'bg-gold-500' : 'bg-ink-300' }}" style="width: {{ max(2, $c['pct']) }}%"></span></span>
                            <span class="w-10 text-right text-[11px] text-ink-700/50 tabular-nums">{{ round($c['pct']) }}%</span>
                        </span>
                    </a>
                @empty
                    <p class="text-sm text-ink-700/50">Nothing logged in this window yet.</p>
                @endforelse
            </div>
        </div>

        {{-- The entries. --}}
        <div class="card">
            <form method="GET" action="{{ route('admin.expenses.index') }}" class="flex flex-wrap items-center gap-2 border-b border-ink-100 p-3 sm:p-4">
                @foreach(array_filter(request()->only(['period', 'from', 'to']), 'filled') as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                <select name="category" onchange="this.form.submit()" class="input py-1.5 text-sm w-auto max-w-[12rem]" aria-label="Category">
                    <option value="">All categories</option>
                    @foreach($categories as $c)<option value="{{ $c }}" @selected(request('category') === $c)>{{ $c }}</option>@endforeach
                </select>
                <input name="q" value="{{ request('q') }}" class="input py-1.5 text-sm min-w-0 flex-1" placeholder="Search what for / paid to…" aria-label="Search">
                <button class="btn-outline py-1.5 px-3 text-sm">Search</button>
                @if($filtering)
                    <a href="{{ route('admin.expenses.index', array_filter(request()->only(['period', 'from', 'to']), 'filled')) }}" class="text-xs text-ink-700/60 hover:underline">Clear</a>
                    <span class="w-full text-xs text-ink-700/60">These come to <strong class="text-ink-900">{{ money($listTotal) }}</strong>.</span>
                @endif
            </form>

            @forelse($expenses as $e)
                <div class="border-b border-ink-50 last:border-0 px-3 sm:px-4 py-3 text-sm" x-data="{ edit: false }">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                <span class="font-medium">{{ $e->category }}</span>
                                @unless($e->isDeductible())<span class="badge bg-ink-100 text-ink-700 text-[10px]">not off profit</span>@endunless
                                <span class="text-xs text-ink-700/50 tabular-nums">{{ $e->spent_on->format('j M Y') }}</span>
                            </div>
                            @if($e->description)<div class="text-ink-700/80 mt-0.5 [overflow-wrap:anywhere]">{{ $e->description }}</div>@endif
                            <div class="text-xs text-ink-700/50 mt-0.5 flex flex-wrap gap-x-2">
                                @if($e->paid_to)<span>to {{ $e->paid_to }}</span>@endif
                                @if($e->paid_via)<span>by {{ $e->paid_via }}</span>@endif
                                @if($e->user)<span>logged by {{ $e->user->name }}</span>@endif
                                @if($e->receipt_path)<a href="{{ route('admin.expenses.receipt', $e) }}" target="_blank" rel="noopener" class="text-gold-700 hover:underline">📎 Receipt</a>@endif
                            </div>
                        </div>
                        <div class="shrink-0 text-right">
                            <div class="font-semibold tabular-nums">{{ money($e->amount) }}</div>
                            <div class="mt-1 flex justify-end gap-3 text-xs">
                                <button type="button" @click="edit = !edit" class="text-gold-700 hover:underline" x-text="edit ? 'Close' : 'Edit'">Edit</button>
                                <form action="{{ route('admin.expenses.destroy', $e) }}" method="POST" onsubmit="return confirm('Delete this expense of {{ money($e->amount) }}?')">
                                    @csrf @method('DELETE')
                                    <button class="text-red-600 hover:underline">Delete</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <form x-show="edit" x-cloak action="{{ route('admin.expenses.update', $e) }}" method="POST" enctype="multipart/form-data"
                          class="mt-3 grid grid-cols-2 gap-2 border-t border-ink-100 pt-3">
                        @csrf @method('PUT')
                        <input type="date" name="spent_on" value="{{ $e->spent_on->toDateString() }}" class="input" required aria-label="Date">
                        <input type="number" name="amount" value="{{ (float) $e->amount }}" min="0.01" step="0.01" inputmode="decimal" class="input" required aria-label="Amount">
                        <input name="category" value="{{ $e->category }}" list="expense-categories" class="input col-span-2" required maxlength="60" aria-label="Category">
                        <input name="description" value="{{ $e->description }}" class="input col-span-2" maxlength="255" placeholder="What for" aria-label="What for">
                        <input name="paid_to" value="{{ $e->paid_to }}" class="input" maxlength="120" placeholder="Paid to" aria-label="Paid to">
                        <select name="paid_via" class="input" aria-label="Paid by">
                            <option value="">—</option>
                            @foreach(collect($paidVia)->push($e->paid_via)->filter()->unique() as $via)<option value="{{ $via }}" @selected($e->paid_via === $via)>{{ $via }}</option>@endforeach
                        </select>
                        <div class="col-span-2">
                            <input type="file" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf" class="input text-sm" aria-label="{{ $e->receipt_path ? 'Replace receipt' : 'Add receipt' }}">
                            @if($e->receipt_path)
                                <label class="mt-1 flex items-center gap-2 text-xs text-ink-700/70"><input type="checkbox" name="remove_receipt" value="1"> Remove the receipt on file</label>
                            @endif
                        </div>
                        <button class="btn-primary col-span-2">Save changes</button>
                    </form>
                </div>
            @empty
                <p class="px-4 py-10 text-center text-sm text-ink-700/50">
                    {{ $filtering ? 'No expenses match.' : 'Nothing logged in this window yet.' }}
                </p>
            @endforelse
        </div>
        <div>{{ $expenses->links() }}</div>
    </div>
</div>
@endsection
