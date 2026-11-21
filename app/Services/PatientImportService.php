<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Patient;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Impor data pasien dari ekspor Assist.id ("Data Pasien.xlsx").
 * Aman dijalankan ulang: No. RM yang sudah ada dilewati.
 * Dipakai oleh perintah egao:import-pasien dan tombol impor di Data pasien.
 */
class PatientImportService
{
    /** Tanggal lahir pengisi Assist.id saat data tidak diketahui. */
    private const PLACEHOLDER_BIRTH = '31-12-1999';

    private const COLUMNS = [
        'name' => 'nama',
        'rm' => 'nomor rekam medis',
        'nik' => 'nomor identitas (nik)',
        'ttl' => 'tempat tanggal lahir',
        'gender' => 'jenis kelamin',
        'address' => 'alamat',
        'phone' => 'nomor telepon',
    ];

    /**
     * @return array{stats: array<string,int>, report: array<int,array<int,string>>, reportPath: string, error: ?string}
     */
    public function import(string $file, int $clinicId, bool $dryRun = false): array
    {
        $fail = fn (string $msg) => ['stats' => [], 'report' => [], 'reportPath' => '', 'error' => $msg];

        if (! is_file($file)) {
            return $fail("File tidak ditemukan: {$file}");
        }

        try {
            $rows = IOFactory::load($file)->getActiveSheet()->toArray(null, false, false, false);
        } catch (\Throwable $e) {
            return $fail('File tidak bisa dibaca. Pastikan file Excel (.xlsx) dari Assist.id.');
        }

        $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), array_shift($rows) ?? []);
        $col = [];
        foreach (self::COLUMNS as $key => $label) {
            $i = array_search($label, $header, true);
            if ($i === false) {
                return $fail("Kolom \"{$label}\" tidak ada di file.");
            }
            $col[$key] = $i;
        }

        $existingRm = Patient::where('clinic_id', $clinicId)->pluck('medical_record_number')->map(fn ($v) => (string) $v)->flip()->all();
        $existingNik = Patient::where('clinic_id', $clinicId)->whereNotNull('nik')->pluck('nik')->flip()->all();

        // Lintasan 1: RM unik di file. Baris kedua dengan RM yang sama ditandai untuk diberi nomor baru.
        $records = [];
        $report = [];
        $seen = [];
        foreach ($rows as $n => $r) {
            $line = $n + 2;
            $name = trim(ltrim(trim((string) ($r[$col['name']] ?? '')), '`'));
            if ($name === '') {
                continue;
            }
            $rawRm = trim((string) ($r[$col['rm']] ?? ''));
            $rm = Patient::normalizeRm($rawRm);

            if ($rm !== '' && isset($existingRm[$rm])) {
                $report[] = [$line, $rawRm, $name, 'dilewati', 'No. RM sudah ada di aplikasi'];

                continue;
            }

            $renumber = $rm === '' || isset($seen[$rm]);
            if (! $renumber) {
                $seen[$rm] = true;
            }

            $records[] = compact('line', 'name', 'rawRm', 'rm', 'renumber') + ['row' => $r];
        }

        // Nomor tertinggi per huruf (dari aplikasi + file) untuk RM ganda/kosong.
        $max = [];
        foreach (array_merge(array_keys($existingRm), array_keys($seen)) as $v) {
            if (preg_match('/^([A-Z])-(\d+)$/', (string) $v, $m)) {
                $max[$m[1]] = max($max[$m[1]] ?? 0, (int) $m[2]);
            }
        }

        $insert = [];
        $now = now();
        $stats = ['baru' => 0, 'rm_baru' => 0, 'nik_kosong' => 0, 'tgl_lahir_kosong' => 0, 'dilewati' => count($report)];
        foreach ($records as $rec) {
            $r = $rec['row'];
            $rm = $rec['rm'];
            if ($rec['renumber']) {
                $letter = Patient::rmLetter($rec['name']);
                $max[$letter] = ($max[$letter] ?? 0) + 1;
                $rm = $letter.'-'.str_pad((string) $max[$letter], 3, '0', STR_PAD_LEFT);
                $report[] = [$rec['line'], $rec['rawRm'], $rec['name'], 'No. RM diganti', "{$rec['rawRm']} sudah dipakai → {$rm}"];
                $stats['rm_baru']++;
            }

            [$place, $birth] = $this->parseTtl((string) ($r[$col['ttl']] ?? ''));
            if (! $birth) {
                $stats['tgl_lahir_kosong']++;
            }

            $rawNik = trim((string) ($r[$col['nik']] ?? ''));
            $nik = preg_replace('/\D/', '', $rawNik);
            if ($nik !== '' && (strlen($nik) !== 16 || isset($existingNik[$nik]))) {
                $reason = strlen($nik) !== 16 ? 'NIK bukan 16 digit' : 'NIK sudah dipakai pasien lain';
                $report[] = [$rec['line'], $rm, $rec['name'], 'NIK dikosongkan', "{$reason}: {$rawNik}"];
                $stats['nik_kosong']++;
                $nik = '';
            }
            if ($nik !== '') {
                $existingNik[$nik] = true;
            }

            $phone = preg_replace('/[^0-9+]/', '', (string) ($r[$col['phone']] ?? ''));
            $address = trim((string) ($r[$col['address']] ?? ''));

            $insert[] = [
                'clinic_id' => $clinicId,
                'medical_record_number' => $rm,
                'name' => $rec['name'],
                'gender' => str_starts_with(mb_strtolower(trim((string) ($r[$col['gender']] ?? ''))), 'l') ? 'L' : 'P',
                'birth_place' => $place,
                'birth_date' => $birth,
                'phone' => $phone !== '' ? mb_substr($phone, 0, 20) : null,
                'nik' => $nik !== '' ? $nik : null,
                'address' => $address !== '' && $address !== '-' ? $address : null,
                'allergies' => 'Tidak ada',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $stats['baru']++;
        }

        $reportPath = 'imports/pasien-assist-'.$now->format('Ymd-His').'.csv';
        $this->writeReport($reportPath, $report);

        if ($dryRun) {
            return ['stats' => $stats, 'report' => $report, 'reportPath' => $reportPath, 'error' => null];
        }

        DB::transaction(function () use ($insert) {
            foreach (array_chunk($insert, 200) as $chunk) {
                Patient::insert($chunk);
            }
        });

        AuditLog::record('patient.imported', null, ['source' => 'Assist.id', 'file' => basename($file)] + $stats);

        return ['stats' => $stats, 'report' => $report, 'reportPath' => $reportPath, 'error' => null];
    }

    /** "Malang, 18-09-2003" → ['Malang', '2003-09-18']; "-, 31-12-1999" → [null, null]. */
    private function parseTtl(string $value): array
    {
        $value = trim($value);
        $pos = strrpos($value, ',');
        $place = trim($pos === false ? '' : substr($value, 0, $pos));
        $date = trim($pos === false ? $value : substr($value, $pos + 1));

        $place = $place === '' || $place === '-' ? null : mb_substr($place, 0, 100);

        $birth = null;
        if ($date !== self::PLACEHOLDER_BIRTH && preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $date, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            $d = Carbon::createFromDate((int) $m[3], (int) $m[2], (int) $m[1]);
            $birth = $d->isFuture() || $d->year < 1900 ? null : $d->toDateString();
        }

        return [$place, $birth];
    }

    private function writeReport(string $path, array $report): void
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['Baris', 'No. RM', 'Nama', 'Tindakan', 'Keterangan'], ';');
        usort($report, fn ($a, $b) => $a[0] <=> $b[0]);
        foreach ($report as $row) {
            fputcsv($fh, $row, ';');
        }
        rewind($fh);
        Storage::disk('local')->put($path, stream_get_contents($fh));
        fclose($fh);
    }
}
