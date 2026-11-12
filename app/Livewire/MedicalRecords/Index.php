<?php

namespace App\Livewire\MedicalRecords;

use App\Enums\Role;
use App\Enums\VisitStatus;
use App\Models\Patient;
use App\Models\Visit;
use App\Support\BranchContext;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * 04 Rekam medis: daftar pasien hari ini untuk diperiksa + pencarian riwayat lintas cabang.
 */
class Index extends Component
{
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: true)]
    public bool $mine = true;

    public function render(BranchContext $context)
    {
        $user = auth()->user();
        $isDoctor = $context->is(Role::Dokter);
        $doctorId = $user->doctor?->id;

        $today = Visit::query()
            ->forBranch($context->id())
            ->whereDate('visit_date', today())
            ->where('status', '!=', VisitStatus::Batal)
            ->when($isDoctor && $this->mine && $doctorId, fn ($q) => $q->where('doctor_id', $doctorId))
            ->with(['patient', 'doctor', 'branch', 'medicalRecord'])
            ->orderByRaw('FIELD(status, ?, ?, ?, ?, ?)', ['diperiksa', 'dipanggil', 'menunggu', 'menunggu_pembayaran', 'selesai'])
            ->orderBy('queue_number')
            ->get();

        $patients = mb_strlen(trim($this->search)) >= 2
            ? Patient::where('clinic_id', $user->clinic_id)
                ->search($this->search)
                ->with(['visits' => fn ($q) => $q->whereHas('medicalRecord')->with('branch')->latest('visit_date')->limit(3)])
                ->limit(10)
                ->get()
            : collect();

        return view('livewire.medical-records.index', compact('today', 'patients', 'isDoctor', 'context'))
            ->title('Rekam medis')
            ->layoutData(['breadcrumb' => ['Operasional', 'Rekam medis'], 'active' => 'rekam-medis']);
    }
}
