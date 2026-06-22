@extends('layouts.admin')
@section('title', 'Import customers')
@section('heading', 'Import customers')

@section('content')
<a href="{{ route('admin.customers.index') }}" class="text-sm text-gold-700 hover:underline">← All customers</a>

<div class="card p-6 mt-4 max-w-xl">
    <h2 class="font-semibold mb-2">Upload a CSV</h2>
    <p class="text-sm text-ink-700/60 mb-4">First row must be a header. Recognised columns: <code>name, phone, email, notes</code>. Existing customers (matched by phone) are updated; new ones are created.</p>

    <form action="{{ route('admin.customers.import.store') }}" method="POST" enctype="multipart/form-data" class="space-y-4">
        @csrf
        <input type="file" name="file" accept=".csv,.txt" class="input" required>
        <button class="btn-primary">Import customers</button>
    </form>

    <div class="mt-6 text-xs text-ink-700/50">
        <p class="font-medium mb-1">Example:</p>
        <pre class="bg-ink-50 rounded-md p-3 overflow-x-auto">name,phone,email,notes
Shamim Mostafa,01712345678,shamim@example.com,VIP
Rahim Uddin,01898765432,,Prefers calls</pre>
    </div>
</div>
@endsection
