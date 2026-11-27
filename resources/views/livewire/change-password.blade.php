<div>
    @if ($open)
        <x-modal title="Ubah kata sandi">
            <form wire:submit="save" class="space-y-4">
                <div>
                    <label class="label" for="cp-current">Kata sandi lama</label>
                    <input id="cp-current" type="password" wire:model="current_password" class="input" autocomplete="current-password">
                    <x-error for="current_password" />
                </div>
                <div>
                    <label class="label" for="cp-new">Kata sandi baru</label>
                    <input id="cp-new" type="password" wire:model="password" class="input" autocomplete="new-password">
                    <p class="mt-1 text-xs text-muted">Minimal 8 karakter.</p>
                    <x-error for="password" />
                </div>
                <div>
                    <label class="label" for="cp-confirm">Ulangi kata sandi baru</label>
                    <input id="cp-confirm" type="password" wire:model="password_confirmation" class="input" autocomplete="new-password">
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" wire:click="closeForm" class="btn-ghost">Batal</button>
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled">Simpan</button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
