<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/** Ekspor tabel sederhana (judul kolom + baris) ke Excel. */
class ArrayExport implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    public function __construct(private array $headings, private array $rows, private string $title = 'Data') {}

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        return $this->title;
    }
}
