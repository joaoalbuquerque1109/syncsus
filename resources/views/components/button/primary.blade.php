@props(['type' => 'submit'])

<button
    type="{{ $type }}"
    {{ $attributes->class('inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-brand-700 focus-visible:outline-brand-500 disabled:cursor-not-allowed disabled:opacity-60') }}
>
    {{ $slot }}
</button>
