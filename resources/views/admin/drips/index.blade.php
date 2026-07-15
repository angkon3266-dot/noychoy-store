@extends('layouts.admin')
@section('title', 'Drip campaigns')
@section('heading', 'Drip campaigns')

@section('content')
@if(session('success'))<div class="mb-4 rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-2.5 text-sm">{{ session('success') }}</div>@endif

<p class="text-sm text-ink-700/70 mb-5 max-w-3xl">
    Automated push sequences that fire over time — e.g. a welcome series. Members are enrolled on registration
    (or manually from a group), and each step sends once its delay elapses. Needs web push enabled.
</p>

<div class="grid lg:grid-cols-3 gap-6">
    {{-- Builder --}}
    <div class="card p-6 h-fit lg:col-span-2"
         x-data="{
            steps: {{ Illuminate\Support\Js::from($editing?->steps->map(fn($s)=>['delay_hours'=>$s->delay_hours,'title'=>$s->title,'body'=>$s->body,'url'=>$s->url,'image'=>$s->image])->values() ?? [['delay_hours'=>0,'title'=>'','body'=>'','url'=>'','image'=>'']]) }},
            add() { this.steps.push({delay_hours:24,title:'',body:'',url:'',image:''}); },
            remove(i) { if(this.steps.length>1) this.steps.splice(i,1); }
         }">
        <h2 class="font-semibold mb-4">{{ $editing ? 'Edit campaign' : 'New campaign' }}</h2>
        @if($errors->any())<div class="rounded bg-red-50 text-red-700 text-sm px-3 py-2 mb-3">{{ $errors->first() }}</div>@endif
        <form action="{{ $editing ? route('admin.drips.update', $editing) : route('admin.drips.store') }}" method="POST" class="space-y-3">
            @csrf
            @if($editing) @method('PUT') @endif
            <div class="grid sm:grid-cols-2 gap-2">
                <div><label class="label">Campaign name *</label><input name="name" value="{{ old('name', $editing->name ?? '') }}" class="input" placeholder="Welcome series" required></div>
                <div><label class="label">Enrol on</label>
                    <select name="trigger" class="input">
                        <option value="registration" @selected(old('trigger', $editing->trigger ?? 'registration')==='registration')>Registration (auto)</option>
                        <option value="manual" @selected(old('trigger', $editing->trigger ?? '')==='manual')>Manual (enrol a group)</option>
                    </select>
                </div>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $editing->is_active ?? true))> Active</label>

            <div class="space-y-3">
                <template x-for="(step, i) in steps" :key="i">
                    <div class="rounded-lg border border-ink-100 p-3 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium">Step <span x-text="i+1"></span></span>
                            <button type="button" @click="remove(i)" class="text-red-600 text-xs hover:underline" x-show="steps.length>1">Remove</button>
                        </div>
                        <div class="grid grid-cols-3 gap-2">
                            <div><label class="label text-xs">Send after (hours from enrol)</label><input type="number" min="0" :name="`steps[${i}][delay_hours]`" x-model="step.delay_hours" class="input py-1.5 text-sm"></div>
                            <div class="col-span-2"><label class="label text-xs">Title *</label><input :name="`steps[${i}][title]`" x-model="step.title" class="input py-1.5 text-sm" required></div>
                        </div>
                        <textarea :name="`steps[${i}][body]`" x-model="step.body" rows="2" class="input py-1.5 text-sm" placeholder="Message"></textarea>
                        <div class="grid grid-cols-2 gap-2">
                            <input :name="`steps[${i}][url]`" x-model="step.url" class="input py-1.5 text-sm" placeholder="Link (/shop)">
                            <input :name="`steps[${i}][image]`" x-model="step.image" class="input py-1.5 text-sm" placeholder="Image URL (optional)">
                        </div>
                    </div>
                </template>
                <button type="button" @click="add()" class="btn-outline text-sm">+ Add step</button>
            </div>

            <div class="flex gap-2 pt-1">
                <button class="btn-primary flex-1">{{ $editing ? 'Save campaign' : 'Create campaign' }}</button>
                @if($editing)<a href="{{ route('admin.drips.index') }}" class="btn-outline">Cancel</a>@endif
            </div>
        </form>
    </div>

    {{-- List --}}
    <div class="space-y-4">
        @forelse($campaigns as $c)
            <div class="card p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="font-medium">{{ $c->name }} @if(! $c->is_active)<span class="badge bg-ink-100 text-ink-700 text-[10px]">paused</span>@endif</p>
                        <p class="text-xs text-ink-700/50">{{ ucfirst($c->trigger) }} · {{ $c->steps_count }} step(s) · {{ number_format($c->enrollments_count) }} enrolled</p>
                    </div>
                    <div class="text-right whitespace-nowrap">
                        <a href="{{ route('admin.drips.index', ['edit'=>$c->id]) }}" class="text-gold-700 hover:underline text-sm">Edit</a>
                        <form action="{{ route('admin.drips.destroy', $c) }}" method="POST" class="inline" onsubmit="return confirm('Delete this campaign?')">@csrf @method('DELETE')<button class="text-red-600 hover:underline text-sm ml-2">Delete</button></form>
                    </div>
                </div>
                @if($c->trigger === 'manual' && $segments->isNotEmpty())
                    <form action="{{ route('admin.drips.enroll', $c) }}" method="POST" class="mt-2 flex items-center gap-2">
                        @csrf
                        <select name="segment_id" class="input py-1.5 text-sm flex-1"><option value="">Enrol a group…</option>@foreach($segments as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select>
                        <button class="btn-outline text-sm">Enrol</button>
                    </form>
                @endif
            </div>
        @empty
            <div class="card p-8 text-center text-ink-700/50 text-sm">No drip campaigns yet.</div>
        @endforelse
    </div>
</div>
@endsection
