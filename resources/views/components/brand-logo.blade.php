@props(['compact' => false])

<div {{ $attributes->merge(['class' => 'flex items-center gap-3']) }}>
    <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-brand-500 text-xl font-black text-white shadow-lg shadow-brand-500/25" aria-hidden="true">+</span>
    @unless($compact)
        <span class="text-xl font-extrabold tracking-wide text-white">SYNC HOSP</span>
    @endunless
</div>
