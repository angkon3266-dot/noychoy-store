{{-- Universal Section Builder region. Included by every homepage template so the
     admin's custom blocks render on any design (added below the template's own
     content, never replacing it). No-op when there are no blocks. --}}
@if(($sections ?? collect())->isNotEmpty())
    @foreach($sections as $block)
        <x-home-block :block="$block" />
    @endforeach
@endif
