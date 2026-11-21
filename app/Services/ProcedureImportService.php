<?php

namespace App\Services;

use App\Models\Procedure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Impor daftar tindakan & tarif dari Excel (mis. "Data Prosedur.xlsx" ekspor Assist.id).
 * Dicocokkan per nama: tindakan yang sudah ada hanya diperbarui tarifnya di cabang terpilih,
 * yang belum ada dibuat baru dengan kode otomatis. Aman diulang.
 */
class ProcedureImportService
{
    private const NAME_HEADERS = ['nama prosedur', 'nama tindakan', 'nama', 'tindakan', 'prosedur'];

    private const PRICE_HEADERS = ['total harga', 'harga', 'tarif', 'harga (rp)', 'tarif (rp)'];

    /**
     * @param  array<int,int>  $branchIds  cabang yang tarifnya diisi
     * @return array{stats: array{baru:int, diperbarui:int, dilewati:int}, skipped: array<int,string>, error: ?string}
     */
    public function import(string $file, int $clinicId, array $branchIds): array
    {
        $fail = fn (string $msg) => ['stats' => ['baru' => 0, 'diperbarui' => 0, 'dilewati' => 0], 'skipped' => [], 'error' => $msg];

        try {
            $rows = IOFactory::load($file)->getActiveSheet()->toArray(null, true, false, false);
        } catch (\Throwable) {
            return $fail('File tidak bisa dibaca. Pastikan file Excel (.xlsx, .xls) atau CSV.');
        }

        $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), array_shift($rows) ?? []);
        $find = function (array $labels) use ($header) {
            foreach ($labels as $label) {
                $i = array_search($label, $header, true);
                if ($i !== false) {
                    return $i;
                }
            }

            return null;
        };
        $nameCol = $find(self::NAME_HEADERS);
        $priceCol = $find(self::PRICE_HEADERS);
        $codeCol = $find(['kode']);
        $categoryCol = $find(['kategori']);

        if ($nameCol === null) {
            return $fail('Kolom nama tindakan tidak ditemukan (mis. "Nama Prosedur").');
        }
        if ($priceCol === null) {
            return $fail('Kolom harga tidak ditemukan (mis. "Total Harga").');
        }
        if ($branchIds === []) {
            return $fail('Pilih cabang tujuan tarif.');
        }

        // Kumpulkan baris valid; nama dobel di file → baris terakhir dipakai.
        $items = [];
        $skipped = [];
        foreach ($rows as $n => $r) {
            $line = $n + 2;
            $name = preg_replace('/\s+/u', ' ', trim((string) ($r[$nameCol] ?? '')));
            if ($name === '') {
                continue;
            }
            $price = $this->parsePrice($r[$priceCol] ?? null);
            if ($price === null) {
                $skipped[] = "Baris {$line} ({$name}): harga kosong/tidak valid";

                continue;
            }
            $items[mb_strtolower($name)] = [
                'name' => Str::limit($name, 120, ''),
                'price' => $price,
                'code' => $codeCol !== null ? strtoupper(trim((string) ($r[$codeCol] ?? ''))) : '',
                'category' => $categoryCol !== null ? trim((string) ($r[$categoryCol] ?? '')) : '',
            ];
        }

        $stats = ['baru' => 0, 'diperbarui' => 0, 'dilewati' => count($skipped)];

        DB::transaction(function () use ($items, $clinicId, $branchIds, &$stats) {
            $existing = Procedure::where('clinic_id', $clinicId)->lockForUpdate()->get()
                ->keyBy(fn (Procedure $p) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($p->name))));
            $codes = $existing->pluck('code')->map(fn ($c) => strtoupper($c))->flip()->all();

            foreach ($items as $key => $item) {
                $p = $existing->get($key);
                if ($p) {
                    $stats['diperbarui']++;
                } else {
                    $code = $this->uniqueCode($item['code'] !== '' ? $item['code'] : $this->initials($item['name']), $codes);
                    $codes[$code] = true;
                    $p = Procedure::create([
                        'clinic_id' => $clinicId, 'code' => $code, 'name' => $item['name'],
                        'category' => $item['category'] ?: null, 'is_active' => true,
                    ]);
                    $existing->put($key, $p);
                    $stats['baru']++;
                }
                foreach ($branchIds as $branchId) {
                    $p->prices()->updateOrCreate(['branch_id' => $branchId], ['price' => $item['price']]);
                }
            }
        });

        return ['stats' => $stats, 'skipped' => $skipped, 'error' => null];
    }

    private function parsePrice(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            return $value >= 0 && $value <= 1_000_000_000 ? (int) round($value) : null;
        }
        // Teks seperti "Rp 150.000" atau "150,000".
        $digits = preg_replace('/[^\d]/', '', preg_replace('/[,.]\d{1,2}$/', '', trim((string) $value)));
        if ($digits === '' || strlen($digits) > 10) {
            return null;
        }
        $n = (int) $digits;

        return $n <= 1_000_000_000 ? $n : null;
    }

    /** Inisial huruf/angka tiap kata, mis. "Insisi & Drainase Abses" → "IDA". */
    private function initials(string $name): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', Str::ascii($name), -1, PREG_SPLIT_NO_EMPTY);
        $code = count($words) === 1
            ? substr($words[0], 0, 4)
            : implode('', array_map(fn ($w) => ctype_digit($w) ? $w : $w[0], $words));

        return strtoupper(substr($code, 0, 16)) ?: 'TDK';
    }

    /** @param array<string,bool> $taken */
    private function uniqueCode(string $base, array $taken): string
    {
        $base = substr(preg_replace('/[^A-Z0-9_-]/', '', strtoupper($base)) ?: 'TDK', 0, 16);
        $code = $base;
        for ($i = 2; isset($taken[$code]); $i++) {
            $code = $base.$i;
        }

        return $code;
    }
}
