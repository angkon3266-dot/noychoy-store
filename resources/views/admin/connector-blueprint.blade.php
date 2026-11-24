@extends('layouts.admin')
@section('title', 'How the Claude connector is built')
@section('heading', 'Claude connector — how it’s built')

@section('content')
{{-- The admin has no Typography plugin, so the rendered Markdown gets a small
     scoped stylesheet here rather than new utility classes in every tag. --}}
<style>
    .bp h1 { font-size: 1.35rem; font-weight: 700; margin: 0 0 .75rem; }
    .bp h2 { font-size: 1.1rem; font-weight: 600; margin: 2rem 0 .6rem; padding-top: 1.25rem; border-top: 1px solid rgb(0 0 0 / .08); }
    .bp h2:first-of-type { border-top: 0; padding-top: 0; }
    .bp p, .bp li { font-size: .875rem; line-height: 1.6; color: rgb(54 54 59); }
    .bp p { margin: .5rem 0; }
    .bp ul, .bp ol { margin: .5rem 0 .5rem 1.25rem; }
    .bp ul { list-style: disc; } .bp ol { list-style: decimal; }
    .bp li { margin: .2rem 0; }
    .bp code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8em; background: rgb(0 0 0 / .05); padding: .1em .35em; border-radius: .25rem; }
    .bp pre { background: #17171a; color: #f3e7cf; padding: 1rem; border-radius: .5rem; overflow-x: auto; margin: .75rem 0; }
    .bp pre code { background: none; padding: 0; color: inherit; font-size: .78rem; }
    .bp blockquote { border-left: 3px solid #b8893a; background: rgb(184 137 58 / .07); padding: .6rem 1rem; margin: .75rem 0; border-radius: 0 .375rem .375rem 0; }
    .bp table { width: 100%; border-collapse: collapse; margin: .75rem 0; font-size: .8rem; display: block; overflow-x: auto; }
    .bp th, .bp td { text-align: left; vertical-align: top; padding: .45rem .6rem; border-bottom: 1px solid rgb(0 0 0 / .07); }
    .bp th { font-size: .7rem; text-transform: uppercase; letter-spacing: .04em; color: rgb(54 54 59 / .6); background: rgb(0 0 0 / .03); }
    .bp strong { font-weight: 600; }
</style>

<div class="max-w-5xl space-y-6">
    <div class="card p-5 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-ink-700/80">
            Everything needed to build the same connector into another Laravel store.
            The tool list below is read live from this store’s code.
        </p>
        <div class="flex gap-2">
            <a href="{{ route('admin.api-tokens') }}" class="btn-outline">← Back to connector</a>
            <a href="{{ route('admin.api-tokens.blueprint.download') }}" class="btn-primary">Download as Markdown</a>
        </div>
    </div>

    <div class="card p-6 md:p-8 bp">{!! $html !!}</div>

    <div class="card overflow-hidden bp">
        <div class="p-6 pb-2">
            <h2 style="border:0;padding:0;margin:0">The {{ count($tools) }} tools in this store</h2>
            <p>Section = the admin area a user’s role must open to see the tool (“any” = every admin user). Class names are under <code>app/Mcp/Tools/</code>.</p>
        </div>
        <table>
            <thead><tr><th>Tool</th><th>Section</th><th>Kind</th><th>What it does</th><th>Class</th></tr></thead>
            <tbody>
                @foreach($tools as $t)
                    <tr>
                        <td><code>{{ $t['name'] }}</code></td>
                        <td>{{ $t['section'] ?? 'any' }}</td>
                        <td style="white-space:nowrap;{{ $t['kind'] === 'destructive' ? 'color:#b91c1c;font-weight:600' : '' }}">{{ $t['kind'] }}</td>
                        <td>{{ $t['description'] }}</td>
                        <td><code>{{ $t['class'] }}</code></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
