@props(['title' => 'SYNC HOSP'])

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · SYNC HOSP</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div x-data="{ sidebarOpen: false }" @keydown.escape.window="sidebarOpen = false">
        <div x-cloak x-show="sidebarOpen" class="fixed inset-0 z-30 bg-slate-950/50 lg:hidden" @click="sidebarOpen = false"></div>
        <x-layout.sidebar />
        <div class="min-h-screen lg:pl-64">
            <x-layout.topbar :title="$title" />
            <main class="p-5 lg:p-8">
                @if(session('success'))
                    <x-alert type="success" class="mb-5">{{ session('success') }}</x-alert>
                @endif
                @if(session('warning'))
                    <x-alert type="warning" class="mb-5">{{ session('warning') }}</x-alert>
                @endif
                {{ $slot }}
            </main>
        </div>
    </div>
    <x-encounter.action-modal />
</body>
</html>
