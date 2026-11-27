@props(['active' => 'dashboard', 'breadcrumb' => [], 'title' => null])
@php
    use App\Support\Navigation;

    $context = app(\App\Support\BranchContext::class);
    $role = $context->role();
    $user = auth()->user();
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    @include('components.layouts.partials.head')
</head>
<body class="min-h-dvh font-sans" x-data="{ navOpen: false, sidebarOpen: $persist(true).as('sidebar-open') }" @keydown.escape.window="navOpen = false" x-on:livewire:navigating.window="navOpen = false">
<div class="lg:flex">
    {{-- Sidebar layar lebar (SRS §2.3) --}}
    {{-- Dibuat ringkas agar seluruh menu muat satu layar; scroll hanya cadangan untuk layar sangat pendek (tanpa scrollbar terlihat). --}}
    {{-- Bisa dilipat lewat tombol ☰ di topbar; pilihan diingat di browser. --}}
    <aside x-show="sidebarOpen" class="sticky top-0 hidden h-dvh w-[248px] shrink-0 flex-col overflow-y-auto border-r border-line bg-white px-4 [scrollbar-width:none] lg:flex [&::-webkit-scrollbar]:hidden">
        @include('components.layouts.partials.sidebar-nav')
    </aside>

    {{-- Laci menu layar sempit: dibuka dari tombol hamburger di header; isinya sama dengan sidebar. --}}
    <div x-show="navOpen" x-cloak class="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true" aria-label="Menu">
        <div x-show="navOpen" x-transition.opacity @click="navOpen = false" class="absolute inset-0 bg-brand-700/40"></div>
        <aside x-show="navOpen"
               x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
               x-transition:leave="transition duration-150 ease-in" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full"
               class="relative flex h-dvh w-[272px] max-w-[85vw] flex-col overflow-y-auto bg-white px-4 shadow-xl">
            <button type="button" @click="navOpen = false" class="absolute top-5 right-3 grid size-10 place-items-center rounded-xl text-brand-700 hover:bg-mist" aria-label="Tutup menu">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" class="size-5" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
            @include('components.layouts.partials.sidebar-nav')
        </aside>
    </div>

    <div class="min-w-0 flex-1">
        {{-- Topbar desktop --}}
        <header class="sticky top-0 z-30 hidden h-[76px] items-center justify-between bg-white px-8 lg:flex">
            <div class="flex items-center gap-3">
                <button type="button" @click="sidebarOpen = ! sidebarOpen" :aria-expanded="sidebarOpen" class="-ml-3 grid size-10 place-items-center rounded-xl text-brand-700 hover:bg-mist" :aria-label="sidebarOpen ? 'Sembunyikan menu' : 'Tampilkan menu'" data-sidebar-toggle>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" class="size-6" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <x-logo size="sm" x-show="! sidebarOpen" x-cloak />
                <nav aria-label="Breadcrumb" class="flex items-center gap-2 text-sm text-muted">
                    @foreach ($breadcrumb as $crumb)
                        @if (! $loop->first)<span>/</span>@endif
                        <span @class(['text-brand-700' => $loop->last])>{{ $crumb }}</span>
                    @endforeach
                </nav>
            </div>
            <div class="flex items-center gap-4">
                <livewire:attendance-button />
                <livewire:branch-switcher />
            </div>
        </header>

        {{-- Header layar sempit --}}
        <header class="sticky top-0 z-30 bg-white px-5 pt-[max(1.25rem,env(safe-area-inset-top))] pb-3 lg:hidden">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <button type="button" @click="navOpen = true" class="-ml-2 grid size-11 place-items-center rounded-xl text-brand-700 hover:bg-mist" aria-label="Buka menu" data-nav-toggle>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" class="size-6" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                    <a href="{{ route('dashboard') }}" wire:navigate><x-logo size="sm" /></a>
                </div>
                <div class="flex items-center gap-2">
                    <livewire:attendance-button compact />
                    <livewire:branch-switcher compact />
                </div>
            </div>
            <p class="mt-2 text-[10px] text-muted">{{ $role?->label() }} / {{ last($breadcrumb) ?: 'Dashboard' }}</p>
        </header>

        <main class="px-5 pt-6 pb-12 lg:px-8 lg:pt-10">
            {{ $slot }}
        </main>
    </div>
</div>

<livewire:change-password />

{{-- Notifikasi singkat dari komponen: $this->dispatch('toast', message: '...') --}}
<div x-data="{ show: false, msg: '', t: null }" x-cloak
     @if (session('toast')) x-init="$nextTick(() => $dispatch('toast', { message: @js(session('toast')) }))" @endif
     @toast.window="msg = $event.detail.message; show = true; clearTimeout(t); t = setTimeout(() => show = false, 2800)"
     x-show="show" x-transition.opacity
     class="fixed inset-x-0 bottom-8 z-[60] flex justify-center px-5" role="status">
    <p class="rounded-xl bg-brand-700 px-5 py-3 text-sm text-white shadow-lg" x-text="msg"></p>
</div>

@include('components.layouts.partials.scripts')
</body>
</html>
