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

    {{-- Reset darurat tersembunyi: Alt+F7 menyingkap kolom PIN. Pengecekan PIN sepenuhnya di server. --}}
    <div x-data="{ open: false }"
         @keydown.window.prevent.f7.alt="open = true"
         x-show="open"
         x-cloak
         class="mt-6 rounded-lg border border-line bg-white p-4">
        <div class="flex items-center justify-between">
            <span class="text-sm font-medium text-brand-700">Reset darurat</span>
            <button type="button" @click="open = false" class="text-xs text-muted hover:text-brand-700">Tutup</button>
        </div>
        <p class="mt-1 text-xs text-muted">Masukkan PIN untuk mereset kata sandi akun <b>{{ $username ?: '…' }}</b> menjadi <code>password</code>.</p>
        <div class="mt-3 flex gap-2">
            <input wire:model="resetPin" type="password" autocomplete="off" class="input" placeholder="PIN">
            <button type="button" wire:click="resetPassword" class="btn-soft shrink-0" wire:loading.attr="disabled" wire:target="resetPassword">Reset</button>
        </div>
        @error('resetPin') <p class="mt-1.5 text-sm text-rose-ink">{{ $message }}</p> @enderror
    </div>
</div>
