@props(['eyebrow' => null, 'title', 'description' => null])

<div {{ $attributes->class(['flex flex-wrap items-start justify-between gap-4']) }}>
    <div>
        @if($eyebrow)<p class="text-sm font-bold uppercase tracking-wide text-brand-700">{{ $eyebrow }}</p>@endif
        <h1 class="mt-1 text-2xl font-extrabold text-slate-950">{{ $title }}</h1>
        @if($description)<p class="mt-1 max-w-3xl text-sm text-slate-600">{{ $description }}</p>@endif
    </div>
    @if(trim((string) $slot) !== '')
        <div class="flex flex-wrap gap-3">{{ $slot }}</div>
    @endif
</div>
