<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Visit;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * FR-RM-24: ringkasan rekam medis A4 dengan kop klinik dan tanda tangan dokter.
 */
class MedicalRecordPrintController extends Controller
{
    public function __invoke(Visit $visit)
    {
        abort_unless($visit->patient()->value('clinic_id') === auth()->user()->clinic_id, 404);

        $visit->load([
            'patient.clinic', 'doctor', 'branch', 'medicalRecord',
            'findings.condition', 'diagnoses.icd10', 'prescriptions', 'invoice.items',
        ]);

        abort_unless($visit->medicalRecord, 404, 'Rekam medis belum diisi.');

        AuditLog::record('rekam_medis.cetak', $visit);

        $name = 'rekam-medis-'.$visit->patient->rmCode().'-'.$visit->visit_date->format('Ymd').'.pdf';

        return Pdf::loadView('pdf.medical-record', ['visit' => $visit, 'record' => $visit->medicalRecord])
            ->setPaper('a4')
            ->stream($name);
    }
}
