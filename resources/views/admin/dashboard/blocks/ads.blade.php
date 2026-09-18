@if($deep['ads'] !== null)
    {{-- Which ad sent the traffic. Starts from visits, not orders, so a
         campaign that spent all week sending people who bought nothing is
         visible — that is the one worth switching off.

         Folds on a phone. When open there, the Ad and Channel columns move
         under the campaign name so the four figures fit without the old
         560px sideways scroll. --}}
    <div class="card p-3 sm:p-5 group" x-data="{ expanded: false }" :data-open="expanded">
        <div class="flex items-center gap-2 cursor-pointer md:cursor-auto" @click="expanded = !expanded">
            <h2 class="min-w-0 flex-1 font-semibold text-sm sm:text-base">Ads &amp; campaigns</h2>
            <button type="button" class="md:hidden shrink-0 -mr-1 p-1 text-ink-700/40" :aria-expanded="expanded" aria-label="Show or hide ads and campaigns">
                <svg class="h-4 w-4 transition" :class="expanded && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </button>
        </div>
        <div class="hidden md:block group-data-[open]:block">
            <p class="text-[11px] sm:text-xs text-ink-700/55 mt-1 mb-2 sm:mb-3">
                Read from <code>utm_campaign</code> and <code>utm_content</code> on your ad links — {{ $per }}.
            </p>

            {{-- The table can only ever be as good as the links. Meta fills these
                 macros in for you, so the campaign shows as its name rather than a
                 seventeen-digit id. --}}
            <details class="mb-2 sm:mb-3 text-xs">
                <summary class="cursor-pointer text-gold-700 hover:underline select-none">How to tag your ad links</summary>
                <div class="mt-2 rounded-lg bg-ink-50 border border-ink-100 p-3 space-y-2">
                    <p class="text-ink-700/70">
                        In Meta Ads Manager, put this in <strong>URL parameters</strong> at the ad level. Meta replaces each
                        <code>&#123;&#123;…&#125;&#125;</code> with the real name when someone clicks:
                    </p>
                    <pre class="overflow-x-auto text-[11px] leading-relaxed text-ink-800">utm_source=&#123;&#123;site_source_name&#125;&#125;&amp;utm_medium=paid_social&amp;utm_campaign=&#123;&#123;campaign.name&#125;&#125;&amp;utm_content=&#123;&#123;ad.name&#125;&#125;&amp;ad_id=&#123;&#123;ad.id&#125;&#125;</pre>
                    <p class="text-ink-700/70">
                        For a boosted post or a plain link, add <code>?utm_source=facebook&amp;utm_campaign=eid-sale</code>
                        to the end of the URL — any name you'll recognise later.
                    </p>
                </div>
            </details>
            @if($deep['ads']->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="w-full text-xs sm:text-sm sm:min-w-[560px]">
                        <thead class="text-left text-[10px] sm:text-xs uppercase tracking-wide text-ink-700/50">
                            <tr>
                                <th class="py-1 pr-2">Campaign</th><th class="hidden sm:table-cell py-1 pr-2">Ad</th><th class="hidden sm:table-cell py-1 pr-2">Channel</th>
                                <th class="py-1 pl-2 text-right">Visitors</th><th class="py-1 pl-2 text-right">Orders</th>
                                <th class="py-1 pl-2 text-right">Convert</th><th class="py-1 pl-2 text-right">Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($deep['ads'] as $a)
                                <tr class="border-t border-ink-100 {{ $a['orders'] === 0 && $a['visitors'] >= 20 ? 'bg-red-50/60' : '' }}">
                                    <td class="py-1.5 pr-2 truncate w-full max-w-0 sm:w-auto sm:max-w-[200px]" title="{{ $a['campaign'] }}">
                                        <div class="truncate">{{ $a['campaign'] }}</div>
                                        <div class="sm:hidden truncate text-[11px] text-ink-700/55">{{ $a['ad'] ?? '—' }} · {{ \App\Support\TrafficSource::label($a['channel']) }}</div>
                                    </td>
                                    <td class="hidden sm:table-cell py-1.5 pr-2 truncate max-w-[180px] text-ink-700/70" title="{{ $a['ad'] }}">{{ $a['ad'] ?? '—' }}</td>
                                    <td class="hidden sm:table-cell py-1.5 pr-2"><span class="badge {{ \App\Support\TrafficSource::badgeClass($a['channel']) }}">{{ \App\Support\TrafficSource::label($a['channel']) }}</span></td>
                                    <td class="py-1.5 pl-2 text-right tabular-nums">{{ number_format($a['visitors']) }}</td>
                                    <td class="py-1.5 pl-2 text-right tabular-nums">{{ number_format($a['orders']) }}</td>
                                    <td class="py-1.5 pl-2 text-right tabular-nums {{ $a['orders'] === 0 ? 'text-red-600' : '' }}">{{ $a['rate'] === null ? '—' : $a['rate'].'%' }}</td>
                                    <td class="py-1.5 pl-2 text-right tabular-nums whitespace-nowrap font-medium">{{ money($a['revenue']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="text-[11px] text-ink-700/45 mt-2">
                    A row shaded red sent real traffic and made no sale. Ads only appear once your links carry
                    <code>utm_content</code> — until then each campaign shows as one row.
                </p>
            @else
                <p class="text-sm text-ink-700/50">
                    No tagged links seen yet. Add <code>utm_campaign</code> to your ad and post links and this fills in.
                </p>
            @endif
        </div>
    </div>
@endif
