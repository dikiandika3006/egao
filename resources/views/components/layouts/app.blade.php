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
<body class="min-h-dvh font-sans">
<div class="lg:flex">
    {{-- Sidebar desktop (SRS §2.3) --}}
    {{-- Dibuat ringkas agar seluruh menu muat satu layar; scroll hanya cadangan untuk layar sangat pendek (tanpa scrollbar terlihat). --}}
    <aside class="sticky top-0 hidden h-dvh w-[248px] shrink-0 flex-col overflow-y-auto border-r border-line bg-white px-4 [scrollbar-width:none] lg:flex [&::-webkit-scrollbar]:hidden">
        <a href="{{ route('dashboard') }}" wire:navigate class="block px-2 pt-6">
            <x-logo size="sm" />
        </a>

        <nav class="mt-6 flex-1">
            @foreach (['klinik' => 'Ruang klinik', 'manajemen' => 'Manajemen'] as $group => $heading)
                @if ($items = Navigation::visible($role, $group))
                    <p @class(['eyebrow px-3 text-[10px]', 'mt-4' => ! $loop->first])>{{ $heading }}</p>
                    <ul class="mt-1.5 space-y-0.5">
                        @foreach ($items as $key => $item)
                            <li>
                                <a href="{{ route($item['route']) }}" wire:navigate @class([
                                    'flex h-[38px] items-center gap-3 rounded-xl px-2 text-[14px] transition',
                                    'bg-leaf-100 font-semibold text-brand-600' => $active === $key,
                                    'font-medium text-brand-700 hover:bg-mist' => $active !== $key,
                                ])>
                                    <x-nav-icon :name="$key" :active="$active === $key" compact />
                                    {{ $item['label'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endforeach
        </nav>

        <div class="mt-4 space-y-0.5 border-t border-line pt-3 pb-4">
            <a href="{{ route('tentang') }}" wire:navigate @class([
                'flex h-[38px] items-center gap-3 rounded-xl px-2 text-[14px] transition',
                'bg-leaf-100 font-semibold text-brand-600' => $active === 'tentang',
                'font-medium text-brand-700 hover:bg-mist' => $active !== 'tentang',
            ])>
                <x-nav-icon name="tentang" :active="$active === 'tentang'" compact />
                Bantuan & aturan
            </a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="flex h-[38px] w-full items-center gap-3 rounded-xl px-2 text-[14px] font-medium text-rose-ink transition hover:bg-rose-soft">
                    <x-nav-icon name="keluar" compact />
                    Keluar
                </button>
            </form>
        </div>
    </aside>

    <div class="min-w-0 flex-1">
        {{-- Topbar desktop --}}
        <header class="sticky top-0 z-30 hidden h-[76px] items-center justify-between bg-white px-8 lg:flex">
            <nav aria-label="Breadcrumb" class="flex items-center gap-2 text-sm text-muted">
                @foreach ($breadcrumb as $crumb)
                    @if (! $loop->first)<span>/</span>@endif
                    <span @class(['text-brand-700' => $loop->last])>{{ $crumb }}</span>
                @endforeach
            </nav>
            <livewire:branch-switcher />
        </header>

        {{-- Header ponsel --}}
        <header class="bg-white px-5 pt-[max(1.25rem,env(safe-area-inset-top))] pb-3 lg:hidden">
            <div class="flex items-start justify-between">
                <a href="{{ route('dashboard') }}" wire:navigate><x-logo size="sm" /></a>
                <livewire:branch-switcher compact />
            </div>
            <div class="mt-3 flex items-center justify-between text-[10px] text-muted">
                <p>{{ $role?->label() }} / {{ last($breadcrumb) ?: 'Dashboard' }}</p>
                <span class="flex items-center gap-3">
                    <a href="{{ route('tentang') }}" wire:navigate class="underline">Tentang</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="font-medium text-rose-ink underline">Keluar</button>
                    </form>
                </span>
            </div>
        </header>

        <main class="px-5 pt-6 pb-28 lg:px-8 lg:pt-10 lg:pb-12">
            {{ $slot }}
        </main>
    </div>
</div>

{{-- Bottom bar ponsel (SRS §2.3, tombol min 44px) --}}
<nav class="fixed inset-x-0 bottom-0 z-40 border-t border-line bg-white px-3 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] lg:hidden">
    <ul class="mx-auto flex max-w-md items-center justify-between gap-2">
        @foreach (Navigation::mobile($role) as $item)
            @php $on = in_array($active, $item['active'], true); @endphp
            <li class="flex-1">
                <a href="{{ route($item['route']) }}" wire:navigate @class([
                    'flex min-h-12 flex-col items-center justify-center gap-0.5 rounded-xl py-1 text-[11.5px] transition',
                    'bg-leaf-100 font-semibold text-brand-600' => $on,
                    'font-medium text-brand-700' => ! $on,
                ])>
                    <x-nav-icon :name="$item['active'][0]" :tile="false" />
                    {{ $item['label'] }}
                </a>
            </li>
        @endforeach
    </ul>
</nav>

{{-- Notifikasi singkat dari komponen: $this->dispatch('toast', message: '...') --}}
<div x-data="{ show: false, msg: '', t: null }" x-cloak
     @if (session('toast')) x-init="$nextTick(() => $dispatch('toast', { message: @js(session('toast')) }))" @endif
     @toast.window="msg = $event.detail.message; show = true; clearTimeout(t); t = setTimeout(() => show = false, 2800)"
     x-show="show" x-transition.opacity
     class="fixed inset-x-0 bottom-24 z-[60] flex justify-center px-5 lg:bottom-8" role="status">
    <p class="rounded-xl bg-brand-700 px-5 py-3 text-sm text-white shadow-lg" x-text="msg"></p>
</div>

@include('components.layouts.partials.scripts')
</body>
</html>
