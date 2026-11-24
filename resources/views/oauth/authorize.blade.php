<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Connect {{ $client->name }} — {{ store_name() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-ink-900 flex items-center justify-center px-4 py-10">
    <div class="w-full max-w-md">
        <div class="text-center mb-6">
            <div class="font-display text-3xl font-bold text-gold-300">{{ store_name() }}</div>
            <p class="text-gold-100/50 text-sm mt-1">Admin Panel</p>
        </div>

        <div class="card p-8">
            <h1 class="text-lg font-semibold mb-2">Connect <span class="whitespace-nowrap">{{ $client->name }}</span> to your store?</h1>
            <p class="text-sm text-ink-700/80 mb-4">
                Claude will be able to work in this store as <strong>{{ $user->name }}</strong>
                ({{ \App\Models\User::ROLES[$user->role] ?? $user->role }}) — the same things you can do in the admin panel:
            </p>

            <ul class="text-sm text-ink-700 mb-4 flex flex-wrap gap-1.5">
                @foreach($sections as $section)
                    <li class="rounded-full bg-ink-50 border border-ink-100 px-2.5 py-0.5">{{ ucfirst($section) }}</li>
                @endforeach
            </ul>

            <p class="text-xs text-ink-700/60 mb-6">
                Claude asks you before deleting anything, changing an order's status or texting a customer.
                Every action is recorded in Admin → Claude connector, where you can also disconnect it at any time.
            </p>

            <form method="POST" action="{{ route('oauth.approve') }}" class="flex gap-3">
                @csrf
                @foreach($params as $key => $value)
                    @if(is_string($value))
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endif
                @endforeach
                <button name="decision" value="deny" class="btn-outline flex-1">Cancel</button>
                <button name="decision" value="approve" class="btn-primary flex-1">Allow</button>
            </form>
        </div>

        <p class="text-center text-xs text-gold-100/40 mt-4">
            Not {{ $user->name }}? <a href="{{ route('admin.dashboard') }}" class="underline">Open the admin panel</a> and log out first.
        </p>
    </div>
</body>
</html>
