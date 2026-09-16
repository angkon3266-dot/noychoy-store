{{--
    The "why buy from us" list editor, shared by Appearance (the store-wide
    list), the category form and the product form (overrides).

    @param array|null $points     the list saved on this record (null = inherits)
    @param array      $inherited  what shows when this record has no list of its own
    @param string     $scope      'store' | 'override'
    @param string     $inheritsFrom  human label for where the inherited list comes from
--}}
@php
    $scope ??= 'override';
    $own = \App\Support\Storefront\PdpPoints::clean($points ?? []);
    $state = [
        'custom' => $scope === 'store' || ! empty($own),
        'rows' => $scope === 'store' ? $own : ($own ?: []),
        'inherited' => $inherited ?? [],
        'max' => \App\Support\Storefront\PdpPoints::MAX,
    ];
@endphp
<div x-data="{
        ...@js($state),
        start() { if (!this.rows.length) this.rows = JSON.parse(JSON.stringify(this.inherited)); },
        move(i, d) { const j = i + d; if (j < 0 || j >= this.rows.length) return; [this.rows[i], this.rows[j]] = [this.rows[j], this.rows[i]]; },
     }">
    <datalist id="pdp-point-icons">
        @foreach(\App\Support\StorefrontIcons::names() as $iconName)
            <option value="{{ $iconName }}"></option>
        @endforeach
    </datalist>

    @if($scope === 'override')
        <input type="hidden" name="pdp_points_custom" value="0">
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="pdp_points_custom" value="1" x-model="custom" @change="custom && start()">
            Use its own list here
        </label>
        <div x-show="!custom" class="mt-3 rounded-md bg-ink-50 p-3">
            <p class="text-xs text-ink-700/60 mb-2">Showing the list from <strong>{{ $inheritsFrom ?? 'the store-wide setting' }}</strong>:</p>
            <ul class="space-y-1 text-sm text-ink-800">
                <template x-for="(r, i) in inherited" :key="i">
                    <li><span class="text-ink-700/50 text-xs mr-1" x-text="r.icon"></span><span x-text="r.title"></span><span class="text-ink-700/60" x-show="r.text" x-text="' — ' + r.text"></span></li>
                </template>
                <li x-show="!inherited.length" class="text-ink-700/50">Nothing — the list is hidden.</li>
            </ul>
        </div>
    @endif

    <div x-show="custom" class="space-y-2 mt-3">
        <template x-for="(r, i) in rows" :key="i">
            <div class="flex flex-wrap sm:flex-nowrap gap-2 items-start">
                <input :name="`pdp_points[${i}][icon]`" x-model="r.icon" list="pdp-point-icons" class="input sm:w-32" placeholder="diamond" maxlength="24">
                <input :name="`pdp_points[${i}][title]`" x-model="r.title" class="input flex-1" placeholder="Bold part (e.g. ক্যাশ অন ডেলিভারি)" maxlength="80">
                <input :name="`pdp_points[${i}][text]`" x-model="r.text" class="input flex-1" placeholder="After the dash (optional)" maxlength="140">
                <div class="flex shrink-0">
                    <button type="button" @click="move(i, -1)" class="px-1.5 text-ink-700/50 hover:text-ink-900" title="Move up">▲</button>
                    <button type="button" @click="move(i, 1)" class="px-1.5 text-ink-700/50 hover:text-ink-900" title="Move down">▼</button>
                    <button type="button" @click="rows.splice(i, 1)" class="text-red-500 px-2 text-xl leading-none" title="Remove">&times;</button>
                </div>
            </div>
        </template>
        <div class="flex flex-wrap items-center gap-3">
            <button type="button" x-show="rows.length < max" @click="rows.push({icon: 'check', title: '', text: ''})" class="btn-outline text-sm">+ Add point</button>
            @if($scope === 'override')
                <button type="button" x-show="rows.length" @click="rows = JSON.parse(JSON.stringify(inherited))" class="text-xs text-gold-700 underline">Reset to the inherited list</button>
            @endif
        </div>
        <p class="text-xs text-ink-700/50">
            Up to {{ \App\Support\Storefront\PdpPoints::MAX }} points. Shown as “<strong>title</strong> — text” with the icon in front.
            Icons: {{ implode(', ', \App\Support\StorefrontIcons::suggested()) }}, tag, cash, trackBox…
            @if($scope === 'override') Untick “Use its own list” to go back to inheriting. @endif
        </p>
    </div>
</div>
