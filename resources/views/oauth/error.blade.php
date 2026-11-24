<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Connection problem — {{ store_name() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-ink-900 flex items-center justify-center px-4">
    <div class="w-full max-w-md">
        <div class="text-center mb-6">
            <div class="font-display text-3xl font-bold text-gold-300">{{ store_name() }}</div>
        </div>
        <div class="card p-8">
            <h1 class="text-lg font-semibold mb-2">Claude could not be connected</h1>
            <p class="text-sm text-ink-700/80">{{ $message }}</p>
        </div>
    </div>
</body>
</html>
