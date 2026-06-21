@extends('layouts.admin')
@section('title', 'Staff & roles')
@section('heading', 'Staff & roles')

@section('content')
@if(session('success'))<div class="mb-4 rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-2.5 text-sm">{{ session('success') }}</div>@endif
@if(session('error'))<div class="mb-4 rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-2.5 text-sm">{{ session('error') }}</div>@endif
@if($errors->any())<div class="mb-4 rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-2.5 text-sm">{{ $errors->first() }}</div>@endif

<div class="grid lg:grid-cols-3 gap-6">
    {{-- Create --}}
    <div class="card p-6 h-fit">
        <h2 class="font-semibold mb-4">Add staff member</h2>
        <form action="{{ route('admin.users.store') }}" method="POST" class="space-y-3">
            @csrf
            <div><label class="label">Name *</label><input name="name" value="{{ old('name') }}" class="input" required></div>
            <div><label class="label">Email (login) *</label><input name="email" type="email" value="{{ old('email') }}" class="input" required></div>
            <div><label class="label">Password *</label><input name="password" type="text" class="input" required placeholder="min 8 characters"></div>
            <div>
                <label class="label">Role *</label>
                <select name="role" class="input">
                    @foreach($roles as $key => $label)<option value="{{ $key }}" @selected(old('role')==$key)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <button class="btn-primary w-full">Create user</button>
        </form>
        <div class="mt-4 text-xs text-ink-700/60 space-y-1">
            <p><strong>Administrator</strong> — full access incl. settings &amp; users.</p>
            <p><strong>Manager</strong> — products, orders, offers, etc. No settings/users.</p>
            <p><strong>Staff</strong> — dashboard &amp; orders only.</p>
        </div>
    </div>

    {{-- List --}}
    <div class="lg:col-span-2 card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                <tr><th class="px-4 py-3">Name</th><th class="px-4 py-3">Email</th><th class="px-4 py-3">Role</th><th></th></tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @foreach($users as $u)
                    <tr x-data="{ edit: false }">
                        <td class="px-4 py-3">
                            <span x-show="!edit">{{ $u->name }} @if($u->id===auth()->id())<span class="badge bg-gold-100 text-gold-700 text-[10px]">you</span>@endif</span>
                        </td>
                        <td class="px-4 py-3 text-ink-700/70" x-show="!edit">{{ $u->email }}</td>
                        <td class="px-4 py-3" x-show="!edit"><span class="badge bg-ink-100 text-ink-700 capitalize">{{ $u->role }}</span></td>
                        <td class="px-4 py-3 text-right whitespace-nowrap" x-show="!edit">
                            <button @click="edit=true" class="text-gold-700 hover:underline">Edit</button>
                            @if($u->id!==auth()->id())
                                <form action="{{ route('admin.users.destroy', $u) }}" method="POST" class="inline" onsubmit="return confirm('Delete this user?')">@csrf @method('DELETE')<button class="text-red-600 hover:underline ml-2">Delete</button></form>
                            @endif
                        </td>
                        {{-- Inline edit row --}}
                        <td colspan="4" class="px-4 py-3" x-show="edit" x-cloak>
                            <form action="{{ route('admin.users.update', $u) }}" method="POST" class="flex flex-wrap gap-2 items-center">
                                @csrf @method('PUT')
                                <input name="name" value="{{ $u->name }}" class="input py-1.5 w-36" placeholder="Name">
                                <input name="email" value="{{ $u->email }}" class="input py-1.5 w-48" placeholder="Email">
                                <select name="role" class="input py-1.5 w-32">@foreach($roles as $key => $label)<option value="{{ $key }}" @selected($u->role==$key)>{{ ucfirst($key) }}</option>@endforeach</select>
                                <input name="password" type="text" class="input py-1.5 w-40" placeholder="New password (optional)">
                                <button class="btn-primary py-1.5 px-3 text-xs">Save</button>
                                <button type="button" @click="edit=false" class="text-xs text-ink-700/60">Cancel</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
