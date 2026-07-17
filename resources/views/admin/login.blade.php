<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login — {{ store_name() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-ink-900 flex items-center justify-center px-4">
    <div class="w-full max-w-sm">
        <div class="text-center mb-6">
            <div class="font-display text-3xl font-bold text-gold-300">{{ store_name() }}</div>
            <p class="text-gold-100/50 text-sm mt-1">Admin Panel</p>
        </div>
        <div class="card p-8">
            @if($errors->any())
                <div class="rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-2 text-sm mb-4">{{ $errors->first() }}</div>
            @endif
            <form action="{{ route('admin.login.post') }}" method="POST" class="space-y-4">
                @csrf
                <div><label class="label">Email</label><input type="email" name="email" value="{{ old('email') }}" class="input" required autofocus></div>
                <div><label class="label">Password</label><input type="password" name="password" class="input" required></div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remember"> Remember me</label>
                <button class="btn-primary w-full">Log in</button>
            </form>
        </div>
    </div>
</body>
</html>
