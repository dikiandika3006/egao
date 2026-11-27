<div class="flex items-center gap-4">
    {{-- Pemilih cabang --}}
    <div x-data="{ open: false }" class="relative" @click.outside="open = false">
        <button type="button" @click="open = !open" @class([
            'flex items-center justify-between gap-3 rounded-full bg-leaf-100 text-brand-600',
            'h-9 min-w-[150px] px-4 text-[13px] font-medium' => ! $compact,
            'h-7 min-w-[110px] px-4 text-[13px] font-medium' => $compact,
        ])>
            {{ $compact ? str($context->label())->replace('Cabang ', '') : $context->label() }}
            <span class="text-[10px]" aria-hidden="true">v</span>
        </button>
        <div x-cloak x-show="open" x-transition.origin.top class="absolute right-0 z-50 mt-2 w-52 rounded-xl border border-line bg-white p-1.5 shadow-lg">
            @if ($context->is(\App\Enums\Role::Owner))
                <button type="button" wire:click="switchBranch('all')" @class(['flex min-h-11 w-full items-center rounded-lg px-3 text-left text-sm hover:bg-mist', 'font-semibold text-brand-600' => $context->isAll()])>Semua cabang</button>
            @endif
            @foreach ($branches as $b)
                <button type="button" wire:click="switchBranch('{{ $b->id }}')" @class(['flex min-h-11 w-full items-center rounded-lg px-3 text-left text-sm hover:bg-mist', 'font-semibold text-brand-600' => $context->id() === $b->id])>{{ $b->name }}</button>
            @endforeach
        </div>
    </div>

    {{-- Peran / profil --}}
    @unless ($compact)
        <div x-data="{ open: false }" class="relative" @click.outside="open = false">
            <button type="button" @click="open = !open" class="flex h-9 items-center gap-2.5 rounded-full bg-mist py-1 pr-4 pl-1 text-[13px] text-brand-700 hover:bg-line">
                <span class="flex size-7 items-center justify-center rounded-full bg-brand-600 text-xs font-semibold text-white" aria-hidden="true">{{ str(auth()->user()->firstName())->substr(0, 1)->upper() }}</span>
                <span class="font-semibold">{{ auth()->user()->firstName() }}</span>
                <span class="text-muted">· {{ $context->role()?->label() }}</span>
                <span class="text-[10px] text-muted" aria-hidden="true">v</span>
            </button>
            <div x-cloak x-show="open" x-transition.origin.top class="absolute right-0 z-50 mt-2 w-56 rounded-xl border border-line bg-white p-1.5 shadow-lg">
                <p class="px-3 pt-2 pb-1 text-sm font-semibold">{{ auth()->user()->name }}</p>
                <p class="px-3 pb-2 text-xs text-muted">{{ '@'.auth()->user()->username }}</p>
                @if ($roles->count() > 1)
                    <p class="eyebrow border-t border-line px-3 pt-2.5 pb-1">Ganti peran</p>
                    @foreach ($roles as $r)
                        <button type="button" wire:click="switchRole('{{ $r->value }}')" @class(['flex min-h-10 w-full items-center rounded-lg px-3 text-left text-sm hover:bg-mist', 'font-semibold text-brand-600' => $context->role() === $r])>{{ $r->label() }}</button>
                    @endforeach
                @endif
                @if ($payslip)
                    {{-- Slip gaji milik sendiri (FR-GJI: dokter dapat melihat slip miliknya) --}}
                    <p class="eyebrow border-t border-line px-3 pt-2.5 pb-1">Slip gaji saya</p>
                    @foreach ($payslip['months'] as $m)
                        <a href="{{ route('gaji.slip', ['type' => $payslip['type'], 'id' => $payslip['id'], 'bulan' => $m->format('Y-m')]) }}" target="_blank" class="flex min-h-10 w-full items-center rounded-lg px-3 text-left text-sm hover:bg-mist">{{ $m->translatedFormat('F Y') }}</a>
                    @endforeach
                @endif
                <button type="button" @click="open = false; $dispatch('buka-ubah-sandi')" class="flex min-h-10 w-full items-center border-t border-line rounded-lg px-3 text-left text-sm hover:bg-mist" data-ubah-sandi>Ubah kata sandi</button>
                <form method="POST" action="{{ route('logout') }}" class="border-t border-line pt-1.5">
                    @csrf
                    <button class="flex min-h-10 w-full items-center rounded-lg px-3 text-left text-sm text-rose-ink hover:bg-rose-soft">Keluar</button>
                </form>
            </div>
        </div>
    @endunless
</div>
