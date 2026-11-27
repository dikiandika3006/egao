{{-- Isi sidebar (grup menu + Bantuan & Keluar). Dipakai sidebar layar lebar dan laci hamburger layar sempit. --}}
@php use App\Support\Navigation; @endphp
<a href="{{ route('dashboard') }}" wire:navigate class="block px-2 pt-6">
    <x-logo size="sm" />
</a>

<nav class="mt-6 flex-1">
    @foreach (Navigation::GROUPS as $group => $heading)
        @if ($items = Navigation::visible($role, $group))
            @php
                $collapsible = $group !== 'klinik';
                $groupActive = collect($items)->contains(fn ($item, $key) => Navigation::isActive($key, $item, $active));
            @endphp
            {{-- Grup selain "Ruang klinik" bisa dilipat; status buka/tutup diingat di browser per grup, dan selalu terbuka bila isinya sedang aktif. --}}
            <div @if ($collapsible) x-data="{ open: $persist(true).as('nav-{{ $group }}-open') }" @if ($groupActive) x-init="open = true" @endif @endif>
                @if ($collapsible)
                    <button type="button" @click="open = ! open" :aria-expanded="open" @class(['eyebrow flex w-full items-center justify-between px-3 text-[10px]', 'mt-4' => ! $loop->first])>
                        {{ $heading }}
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-3.5 transition-transform" :class="open ? '' : '-rotate-90'" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                @else
                    <p @class(['eyebrow px-3 text-[10px]', 'mt-4' => ! $loop->first])>{{ $heading }}</p>
                @endif
                <ul class="mt-1.5 space-y-0.5" @if ($collapsible) x-show="open" @endif>
                    @foreach ($items as $key => $item)
                        @php $on = Navigation::isActive($key, $item, $active); @endphp
                        <li>
                            <a href="{{ Navigation::url($item) }}" wire:navigate @class([
                                'flex h-[38px] items-center gap-3 rounded-xl px-2 text-[14px] transition',
                                'bg-leaf-100 font-semibold text-brand-600' => $on,
                                'font-medium text-brand-700 hover:bg-mist' => ! $on,
                            ])>
                                <x-nav-icon :name="$key" :active="$on" compact />
                                {{ $item['label'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
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
    <button type="button" @click="navOpen = false; $dispatch('buka-ubah-sandi')" class="flex h-[38px] w-full items-center gap-3 rounded-xl px-2 text-[14px] font-medium text-brand-700 transition hover:bg-mist lg:hidden">
        <x-nav-icon name="sandi" compact />
        Ubah kata sandi
    </button>
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button class="flex h-[38px] w-full items-center gap-3 rounded-xl px-2 text-[14px] font-medium text-rose-ink transition hover:bg-rose-soft">
            <x-nav-icon name="keluar" compact />
            Keluar
        </button>
    </form>
</div>
