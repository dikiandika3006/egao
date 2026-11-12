<div>
    <h1 class="text-[28px] font-semibold tracking-tight">Masuk</h1>
    <p class="mt-2 text-sm text-muted">Gunakan username dan kata sandi akun klinik Anda.</p>

    @if (session('status'))
        <p class="mt-6 rounded-lg bg-amber-soft px-4 py-3 text-sm text-amber-ink">{{ session('status') }}</p>
    @endif

    <form wire:submit="login" class="mt-8 space-y-5">
        <div>
            <label for="username" class="label">Username</label>
            <input wire:model="username" id="username" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" autofocus class="input" placeholder="mis. owner">
            @error('username') <p class="mt-1.5 text-sm text-rose-ink">{{ $message }}</p> @enderror
        </div>

        <div x-data="{ show: false }">
            <label for="password" class="label">Kata sandi</label>
            <div class="relative">
                <input wire:model="password" id="password" :type="show ? 'text' : 'password'" autocomplete="current-password" class="input pr-20">
                <button type="button" @click="show = !show" class="absolute inset-y-0 right-0 px-4 text-xs font-medium text-brand-600" x-text="show ? 'Sembunyikan' : 'Lihat'"></button>
            </div>
            @error('password') <p class="mt-1.5 text-sm text-rose-ink">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-muted">
            <input wire:model="remember" type="checkbox" class="size-4 rounded border-line accent-brand-600">
            Ingat saya di perangkat ini
        </label>

        <button type="submit" class="btn-primary w-full" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="login">Masuk</span>
            <span wire:loading wire:target="login">Memeriksa…</span>
        </button>
    </form>

    <p class="mt-10 text-xs text-muted">Sesi berakhir otomatis setelah 8 jam tidak aktif.</p>
</div>
