<div class="mx-auto max-w-[1152px]">
    <x-page-header :title="$item['title']" :subtitle="$item['subtitle']">
        <button type="button" wire:click="create" class="btn-primary">+ &nbsp;Tambah dokter</button>
    </x-page-header>

    <div class="mt-7 flex flex-wrap items-center gap-3">
        <input wire:model.live.debounce.300ms="search" type="search" class="input max-w-sm" placeholder="Cari nama, STR, atau SIP">
        <div class="flex gap-1 rounded-lg bg-white p-1 text-sm">
            @foreach (['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif', 'semua' => 'Semua'] as $key => $label)
                <button type="button" wire:click="$set('status', '{{ $key }}')" @class([
                    'rounded-md px-3 py-1.5 font-medium',
                    'bg-brand-600 text-white' => $status === $key,
                    'text-muted hover:text-brand-700' => $status !== $key,
                ])>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    <section class="card mt-5 overflow-hidden">
        {{-- Tabel desktop --}}
        <div class="hidden md:block">
            <table class="w-full text-left text-sm">
                <thead class="bg-canvas/70 text-[10px] font-medium text-muted uppercase">
                    <tr>
                        <th class="py-3.5 pl-[22px]">Dokter</th>
                        <th class="py-3.5">Cabang utama</th>
                        <th class="py-3.5">STR / SIP</th>
                        <th class="py-3.5">Bergabung sejak</th>
                        <th class="py-3.5">Status</th>
                        <th class="py-3.5 pr-[22px] text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($doctors as $doctor)
                        <tr wire:key="d-{{ $doctor->id }}" class="border-b border-line last:border-0 [&>td]:py-3.5">
                            <td class="pl-[22px]">
                                <p class="font-medium">{{ $doctor->name }}</p>
                                @if ($doctor->user)
                                    <p class="mt-1 text-[11px] text-muted">{{ '@'.$doctor->user->username }}</p>
                                @else
                                    <p class="mt-1 text-[11px] text-amber-ink">Belum punya akun login</p>
                                @endif
                            </td>
                            <td class="text-[13px]">{{ $doctor->primaryBranch?->shortName() ?? '—' }}</td>
                            <td class="text-[13px]">
                                <p>{{ $doctor->str_number ?: '—' }}</p>
                                <p class="mt-1 text-[11px] text-muted">{{ $doctor->sip_number ?: '—' }}</p>
                                <p class="mt-1 text-[11px] {{ $doctor->nik ? 'text-muted' : 'text-amber-ink' }}">{{ $doctor->maskedNik() ?? 'NIK belum diisi' }}</p>
                            </td>
                            <td class="text-[13px]">
                                @if ($doctor->joined_at)
                                    <p>{{ $doctor->joined_at->translatedFormat('j F Y') }}</p>
                                    <p class="mt-1 text-[11px] text-muted">{{ $doctor->tenureLabel() }}</p>
                                @else
                                    <span class="text-muted">Belum diisi</span>
                                @endif
                            </td>
                            <td>
                                <span @class(['pill', 'bg-leaf-100 text-brand-600' => $doctor->is_active, 'bg-mist text-muted' => ! $doctor->is_active])>
                                    {{ $doctor->is_active ? 'Aktif' : 'Nonaktif' }}
                                </span>
                            </td>
                            <td class="pr-[22px] text-right">
                                <button type="button" wire:click="edit({{ $doctor->id }})" class="link-arrow">Ubah</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-[22px] py-12 text-center text-sm text-muted">{{ $search ? 'Tidak ada dokter yang cocok.' : 'Belum ada data dokter.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Kartu ponsel --}}
        <ul class="divide-y divide-line md:hidden">
            @forelse ($doctors as $doctor)
                <li wire:key="dm-{{ $doctor->id }}" class="flex items-start justify-between gap-3 p-4">
                    <div class="min-w-0">
                        <p class="font-medium">{{ $doctor->name }}</p>
                        <p class="mt-1 text-xs text-muted">{{ $doctor->primaryBranch?->shortName() ?? 'Tanpa cabang utama' }}</p>
                        <p class="mt-2 text-xs">
                            Bergabung {{ $doctor->joined_at?->translatedFormat('j M Y') ?? '—' }}
                            @if ($doctor->joined_at)<span class="text-muted">• {{ $doctor->tenureLabel() }}</span>@endif
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-2">
                        <span @class(['pill', 'bg-leaf-100 text-brand-600' => $doctor->is_active, 'bg-mist text-muted' => ! $doctor->is_active])>
                            {{ $doctor->is_active ? 'Aktif' : 'Nonaktif' }}
                        </span>
                        <button type="button" wire:click="edit({{ $doctor->id }})" class="link-arrow">Ubah</button>
                    </div>
                </li>
            @empty
                <li class="p-8 text-center text-sm text-muted">{{ $search ? 'Tidak ada dokter yang cocok.' : 'Belum ada data dokter.' }}</li>
            @endforelse
        </ul>
    </section>

    @if ($showForm)
        <x-modal :title="$editingId ? 'Ubah data dokter' : 'Tambah dokter'">
            <form wire:submit="save" class="space-y-4">
                <div>
                    <label for="name" class="label">Nama lengkap</label>
                    <input wire:model="name" id="name" type="text" class="input" placeholder="mis. drg. Nadia Putri">
                    <x-error for="name" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="str_number" class="label">Nomor STR</label>
                        <input wire:model="str_number" id="str_number" type="text" class="input">
                        <x-error for="str_number" />
                    </div>
                    <div>
                        <label for="sip_number" class="label">Nomor SIP</label>
                        <input wire:model="sip_number" id="sip_number" type="text" class="input">
                        <x-error for="sip_number" />
                    </div>
                </div>

                <div>
                    <label for="nik" class="label">NIK <span class="font-normal text-muted">(untuk SATUSEHAT)</span></label>
                    <input wire:model="nik" id="nik" type="text" inputmode="numeric" maxlength="16" class="input" autocomplete="off">
                    <x-error for="nik" />
                    <p class="mt-1 text-xs text-muted">16 digit sesuai KTP. Dipakai mencocokkan dokter dengan data tenaga kesehatan di SATUSEHAT.</p>
                </div>

                <div>
                    <label for="calendar_email" class="label">Email Google Calendar <span class="font-normal text-muted">(opsional)</span></label>
                    <input wire:model="calendar_email" id="calendar_email" type="email" class="input" autocomplete="off" placeholder="nama@gmail.com">
                    <x-error for="calendar_email" />
                    <p class="mt-1 text-xs text-muted">Dokter menerima undangan kalender "Egao – nama dokter" di email ini (cukup dibuka sekali); janji temunya otomatis masuk ke sana.</p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="joined_at" class="label">Bergabung sejak</label>
                        <input wire:model="joined_at" id="joined_at" type="date" max="{{ today()->toDateString() }}" class="input">
                        <x-error for="joined_at" />
                    </div>
                    <div>
                        <label for="primary_branch_id" class="label">Cabang utama</label>
                        <select wire:model="primary_branch_id" id="primary_branch_id" class="input">
                            <option value="">— Tidak ada —</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                        <x-error for="primary_branch_id" />
                    </div>
                </div>

                @if ($isOwner)
                    {{-- Akun login: agar dokter bisa mengisi rekam medis & melihat slip gaji sendiri --}}
                    <div class="rounded-xl bg-mist p-3">
                        <label for="account" class="label">Akun login</label>
                        <select wire:model.live="account" id="account" class="input bg-white">
                            <option value="">— Tanpa akun (belum bisa login) —</option>
                            <option value="new">+ Buat akun baru</option>
                            @foreach ($accounts as $acc)
                                <option value="{{ $acc->id }}">{{ $acc->name }} ({{ '@'.$acc->username }}) · {{ $acc->roleList()->map->label()->implode(', ') }}</option>
                            @endforeach
                        </select>
                        <x-error for="account" />
                        @if ($account === 'new')
                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label class="label text-xs">Username</label>
                                    <input wire:model="new_username" type="text" autocapitalize="none" class="input bg-white" placeholder="mis. tika">
                                    <x-error for="new_username" />
                                </div>
                                <div>
                                    <label class="label text-xs">Kata sandi awal</label>
                                    <input wire:model="new_password" type="text" class="input bg-white" placeholder="min. 8 karakter">
                                    <x-error for="new_password" />
                                </div>
                            </div>
                        @endif
                        <p class="mt-2 text-xs text-muted">Akun yang dipilih otomatis mendapat peran <b>Dokter</b> di semua cabang, tanpa menghapus peran lain (mis. Owner yang juga praktik).</p>
                    </div>
                @endif

                <label class="flex items-center gap-2 text-sm">
                    <input wire:model="is_active" type="checkbox" class="size-4 rounded border-line accent-brand-600">
                    Dokter masih aktif praktik
                </label>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" wire:click="closeForm" class="btn-ghost">Batal</button>
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled">Simpan</button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
