<div>
    <h1 class="text-[28px] font-semibold tracking-tight">Halo, {{ auth()->user()->firstName() }}</h1>
    <p class="mt-2 text-sm text-muted">Pilih peran dan cabang yang akan Anda layani hari ini.</p>

    <form wire:submit="choose" class="mt-8 space-y-6">
        @if ($roles->count() > 1)
            <fieldset>
                <legend class="label">Masuk sebagai</legend>
                <div @class(['grid gap-2', 'grid-cols-2' => $roles->count() === 2, 'grid-cols-3' => $roles->count() >= 3])>
                    @foreach ($roles as $r)
                        <label @class([
                            'flex min-h-11 cursor-pointer items-center justify-center rounded-lg border text-sm font-medium transition',
                            'border-brand-600 bg-leaf-100 text-brand-600' => $role === $r->value,
                            'border-line bg-white hover:bg-mist' => $role !== $r->value,
                        ])>
                            <input type="radio" wire:model.live="role" value="{{ $r->value }}" class="sr-only">
                            {{ $r->label() }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
        @endif

        <fieldset>
            <legend class="label">Cabang aktif</legend>
            <div class="space-y-2">
                @if ($role === \App\Enums\Role::Owner->value)
                    <label @class([
                        'flex min-h-12 cursor-pointer items-center justify-between rounded-lg border px-4 text-sm transition',
                        'border-brand-600 bg-leaf-100 font-semibold text-brand-600' => $branch === 'all',
                        'border-line bg-white hover:bg-mist' => $branch !== 'all',
                    ])>
                        <input type="radio" wire:model.live="branch" value="all" class="sr-only">
                        Semua cabang
                        <span class="text-xs font-normal text-muted">Khusus owner</span>
                    </label>
                @endif
                @foreach ($branches as $b)
                    <label @class([
                        'flex min-h-12 cursor-pointer items-center justify-between rounded-lg border px-4 text-sm transition',
                        'border-brand-600 bg-leaf-100 font-semibold text-brand-600' => $branch === (string) $b->id,
                        'border-line bg-white hover:bg-mist' => $branch !== (string) $b->id,
                    ])>
                        <input type="radio" wire:model.live="branch" value="{{ $b->id }}" class="sr-only">
                        {{ $b->name }}
                        <span class="text-xs font-normal text-muted">{{ \App\Support\Format::jam($b->opens_at) }}–{{ \App\Support\Format::jam($b->closes_at) }}</span>
                    </label>
                @endforeach
            </div>
            @error('branch') <p class="mt-1.5 text-sm text-rose-ink">{{ $message }}</p> @enderror
        </fieldset>

        <button type="submit" class="btn-primary w-full">Lanjut</button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-6">
        @csrf
        <button class="text-sm text-muted hover:text-brand-700">Bukan Anda? Keluar</button>
    </form>
</div>
