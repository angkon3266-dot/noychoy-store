@extends('layouts.admin')
@section('title', 'My profile')
@section('heading', 'My profile')

@section('content')
<div class="max-w-lg">
    @if(session('success'))<div class="mb-4 rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-2.5 text-sm">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="mb-4 rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-2.5 text-sm"><ul class="list-disc list-inside">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

    <div class="card p-6">
        <form action="{{ route('admin.profile.update') }}" method="POST" class="space-y-4">
            @csrf @method('PUT')
            <div><label class="label">Name</label><input name="name" value="{{ old('name', $user->name) }}" class="input" required></div>
            <div><label class="label">Email (login)</label><input name="email" type="email" value="{{ old('email', $user->email) }}" class="input" required></div>

            <hr class="border-ink-100">
            <p class="text-sm text-ink-700/60">Leave password fields blank to keep your current password.</p>
            <div><label class="label">Current password</label><input name="current_password" type="password" class="input" autocomplete="current-password"></div>
            <div><label class="label">New password</label><input name="password" type="password" class="input" autocomplete="new-password"></div>
            <div><label class="label">Confirm new password</label><input name="password_confirmation" type="password" class="input" autocomplete="new-password"></div>

            <button class="btn-primary">Save changes</button>
        </form>
    </div>
</div>
@endsection
