<?php

namespace App\Livewire;

use App\Services\AttendanceService;
use App\Support\Access;
use App\Support\BranchContext;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Tombol Check in / Clock out di topbar (semua halaman). Hanya tampil untuk akun yang terhubung ke data dokter/karyawan
 * dan punya izin Edit menu Absensi. Validasi lokasi, jam, dan cabang tetap di AttendanceService.
 */
class AttendanceButton extends Component
{
    /** Izin Edit menu Absensi dicek hook di AppServiceProvider untuk setiap aksi. */
    public const MENU = 'absensi';

    public const READ_ACTIONS = ['attendanceChanged'];

    public bool $compact = false;

    public function checkIn(?float $lat, ?float $lng, ?float $accuracy, BranchContext $context, AttendanceService $service): void
    {
        try {
            $row = $service->checkIn(auth()->user(), $lat, $lng, $accuracy, $service->testBranchFor($context));
        } catch (ValidationException $e) {
            $this->dispatch('toast', message: collect($e->errors())->flatten()->first());

            return;
        }
        $this->dispatch('toast', message: 'Check in '.$row->branch->shortName().' · shift '.$service->label($row->shift).' tercatat.');
        $this->dispatch('absensi-diperbarui');
    }

    public function clockOut(?float $lat, ?float $lng, ?float $accuracy, AttendanceService $service): void
    {
        try {
            $row = $service->clockOut(auth()->user(), $lat, $lng, $accuracy);
        } catch (ValidationException $e) {
            $this->dispatch('toast', message: collect($e->errors())->flatten()->first());

            return;
        }
        $over = (int) ($row->overtime_minutes ?? 0);
        $this->dispatch('toast', message: 'Clock out shift '.$service->label($row->shift).' tercatat'.($over > 0 ? ', lembur '.($over / 60).' jam.' : '.'));
        $this->dispatch('absensi-diperbarui');
    }

    /** Absen dari halaman Absensi → perbarui status tombol. */
    #[On('absensi-diperbarui')]
    public function attendanceChanged(): void {}

    public function render(AttendanceService $service)
    {
        $user = auth()->user();
        $person = $user && Access::can('absensi', 'edit') ? $service->personFor($user) : null;

        return view('livewire.attendance-button', [
            'person' => $person,
            'open' => $person ? $service->openRecord($person, now())?->load('branch') : null,
            'testMode' => $person && $service->isTestMode(),
        ]);
    }
}
