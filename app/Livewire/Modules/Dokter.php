<?php

namespace App\Livewire\Modules;

use App\Enums\Role;
use App\Livewire\Concerns\ModulePage;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Doctor;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use App\Support\BranchContext;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * 07 Data dokter: daftar dokter klinik beserta tanggal mulai bergabung (Owner & Admin).
 */
class Dokter extends Component
{
    use ModulePage;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: 'aktif')]
    public string $status = 'aktif'; // aktif | nonaktif | semua

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $str_number = '';

    public string $sip_number = '';

    /** NIK dokter: wajib untuk SATUSEHAT (pencocokan data tenaga kesehatan). */
    public string $nik = '';

    public ?string $primary_branch_id = null;

    /** Email Google: janji temu dokter ini masuk ke Google Calendar-nya (Pengaturan → Google Calendar). */
    public string $calendar_email = '';

    public ?string $joined_at = null;

    public bool $is_active = true;

    /**
     * Akun login dokter: '' = tanpa akun, 'new' = buat akun baru, angka = id user yang sudah ada.
     * Dokter butuh akun untuk mengisi rekam medis & melihat slip gaji sendiri.
     */
    public string $account = '';

    public string $new_username = '';

    public string $new_password = '';

    public function create(): void
    {
        $this->resetForm();
        $this->joined_at = today()->toDateString();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $doctor = $this->doctorQuery()->findOrFail($id);

        $this->resetValidation();
        $this->editingId = $doctor->id;
        $this->name = $doctor->name;
        $this->str_number = (string) $doctor->str_number;
        $this->sip_number = (string) $doctor->sip_number;
        $this->nik = (string) $doctor->nik;
        $this->calendar_email = (string) $doctor->calendar_email;
        $this->primary_branch_id = $doctor->primary_branch_id ? (string) $doctor->primary_branch_id : null;
        $this->joined_at = $doctor->joined_at?->toDateString();
        $this->is_active = $doctor->is_active;
        $this->account = $doctor->user_id ? (string) $doctor->user_id : '';
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'str_number' => ['nullable', 'string', 'max:50'],
            'sip_number' => ['nullable', 'string', 'max:50'],
            'nik' => ['nullable', 'digits:16', Rule::unique('doctors', 'nik')->where('clinic_id', $this->clinicId())->ignore($this->editingId)],
            'primary_branch_id' => ['nullable', Rule::exists('branches', 'id')->where('clinic_id', $this->clinicId())],
            'calendar_email' => ['nullable', 'email', 'max:255'],
            'joined_at' => ['required', 'date', 'before_or_equal:today'],
            'is_active' => ['boolean'],
        ], [], [
            'name' => 'nama', 'str_number' => 'nomor STR', 'sip_number' => 'nomor SIP', 'nik' => 'NIK', 'calendar_email' => 'email Google Calendar',
            'primary_branch_id' => 'cabang utama', 'joined_at' => 'tanggal bergabung',
        ]);

        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);

        // Akun login hanya boleh diatur Owner (sama seperti Pengaturan › Pengguna & peran).
        $userId = $this->isOwner()
            ? $this->resolveAccount($data['name'])
            : ($this->editingId ? $this->doctorQuery()->whereKey($this->editingId)->value('user_id') : null);

        $calendarChanged = false;

        DB::transaction(function () use ($data, $userId, &$calendarChanged) {
            $doctor = $this->editingId
                ? $this->doctorQuery()->findOrFail($this->editingId)
                : new Doctor(['clinic_id' => $this->clinicId()]);

            $previousUserId = $doctor->user_id;
            $doctor->fill($data + ['user_id' => $userId])->save();
            $calendarChanged = $doctor->wasChanged(['calendar_email', 'is_active']) || ($doctor->wasRecentlyCreated && $doctor->calendar_email);

            // Akun yang dilepas dari dokter kehilangan peran dokter (bila masih punya peran lain).
            if ($previousUserId && $previousUserId !== $userId) {
                $old = User::find($previousUserId);
                $remaining = array_values(array_diff($old->roles ?? [], [Role::Dokter->value]));
                if ($remaining) {
                    $old->update(['roles' => $remaining]);
                }
            }

            AuditLog::record($this->editingId ? 'doctor.updated' : 'doctor.created', $doctor, ['user_id' => $userId, 'nik_diubah' => $doctor->wasChanged('nik')]);
        });

        $message = $this->editingId ? 'Data dokter diperbarui.' : 'Dokter baru ditambahkan.';

        // Email Google berubah → langsung kirim undangan ke email baru & cabut akses lama (tanpa menunggu putaran 1 menit).
        if ($calendarChanged) {
            $sync = app(GoogleCalendarSync::class);
            if ($sync->enabled()) {
                try {
                    $message .= $sync->syncCalendars($this->clinicId())
                        ? ' Undangan Google Calendar gagal dikirim — cek Pengaturan › Google Calendar.'
                        : (filled($this->calendar_email) && $this->is_active
                            ? ' Undangan Google Calendar sudah dikirim ke '.mb_strtolower(trim($this->calendar_email)).'.'
                            : ' Akses Google Calendar dokter ini dicabut.');
                } catch (\Throwable $e) {
                    report($e);
                    $message .= ' Undangan Google Calendar akan dicoba lagi otomatis.';
                }
            }
        }

        $this->toast($message);
        $this->closeForm();
    }

    private function isOwner(): bool
    {
        return app(BranchContext::class)->is(Role::Owner);
    }

    /** Validasi & siapkan akun login: pakai akun yang ada atau buat baru; pastikan berperan Dokter di semua cabang. */
    private function resolveAccount(string $doctorName): ?int
    {
        if ($this->account === '') {
            return null;
        }

        $branchIds = Branch::where('clinic_id', $this->clinicId())->pluck('id');

        if ($this->account === 'new') {
            $this->validate([
                'new_username' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')],
                'new_password' => ['required', 'string', Password::min(8)],
            ], ['new_username.regex' => 'Username hanya huruf kecil, angka, titik, strip.'], [
                'new_username' => 'username', 'new_password' => 'kata sandi',
            ]);

            $user = User::create([
                'clinic_id' => $this->clinicId(), 'name' => $doctorName, 'username' => $this->new_username,
                'password' => $this->new_password, 'roles' => [Role::Dokter->value],
            ]);
            $user->branches()->sync($branchIds);

            return $user->id;
        }

        $user = User::where('clinic_id', $this->clinicId())->findOrFail((int) $this->account);

        $takenBy = Doctor::where('user_id', $user->id)->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))->value('name');
        if ($takenBy) {
            throw ValidationException::withMessages(['account' => "Akun ini sudah dipakai oleh {$takenBy}."]);
        }

        if (! $user->hasRole(Role::Dokter)) {
            $user->update(['roles' => array_values(array_unique([...($user->roles ?? []), Role::Dokter->value]))]);
        }
        $user->branches()->syncWithoutDetaching($branchIds);

        return $user->id;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'name', 'str_number', 'sip_number', 'nik', 'calendar_email', 'primary_branch_id', 'joined_at', 'is_active', 'account', 'new_username', 'new_password');
    }

    private function doctorQuery()
    {
        return Doctor::where('clinic_id', $this->clinicId());
    }

    public function render()
    {
        $term = trim($this->search);

        $doctors = $this->doctorQuery()
            ->with(['primaryBranch', 'user'])
            ->when($this->status !== 'semua', fn ($q) => $q->where('is_active', $this->status === 'aktif'))
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$term}%")
                ->orWhere('str_number', 'like', "%{$term}%")
                ->orWhere('sip_number', 'like', "%{$term}%")))
            ->orderByRaw('joined_at IS NULL, joined_at')
            ->orderBy('name')
            ->get();

        $branches = Branch::where('clinic_id', $this->clinicId())->orderBy('name')->get();

        // Akun yang bisa dihubungkan: belum dipakai dokter lain (atau milik dokter yang sedang diubah).
        $linked = Doctor::where('clinic_id', $this->clinicId())->whereNotNull('user_id')
            ->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))->pluck('user_id');
        $accounts = User::where('clinic_id', $this->clinicId())->where('is_active', true)
            ->whereNotIn('id', $linked)->orderBy('name')->get();

        $isOwner = $this->isOwner();

        return $this->page('dokter', 'livewire.modules.dokter', compact('doctors', 'branches', 'accounts', 'isOwner'));
    }
}
