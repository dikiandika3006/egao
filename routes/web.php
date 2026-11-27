<?php

use App\Http\Controllers\ManualDownloadController;
use App\Http\Controllers\MedicalRecordPrintController;
use App\Http\Controllers\PayslipController;
use App\Http\Controllers\ReportPdfController;
use App\Http\Controllers\ReceiptPdfController;
use App\Livewire\MedicalRecords;
use App\Livewire\ModulePlaceholder;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\SelectBranch;
use App\Livewire\Dashboard;
use App\Livewire\Modules;
use App\Livewire\MoreMenu;
use App\Support\BranchContext;
use App\Support\Navigation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/masuk', Login::class)->name('login');
});

Route::middleware('auth')->group(function () {
    Route::get('/pilih-cabang', SelectBranch::class)->name('select-branch');

    Route::post('/keluar', function (BranchContext $context) {
        $context->forget();
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');

    Route::middleware('branch')->group(function () {
        Route::get('/', Dashboard::class)->name('dashboard');
        Route::get('/lainnya', MoreMenu::class)->name('lainnya');

        // Komponen per modul; path & hak akses peran tetap bersumber dari Navigation.
        $modules = [
            'antrean' => Modules\Antrean::class,
            'janji' => Modules\JanjiTemu::class,
            'stok' => Modules\Stok::class,
            'lab' => Modules\OrderLab::class,
            'pasien' => Modules\Pasien::class,
            'rekam-medis' => MedicalRecords\Index::class,
            'kasir' => Modules\Kasir::class,
            'jadwal' => Modules\Jadwal::class,
            'absensi' => Modules\Absensi::class,
            'dokter' => Modules\Dokter::class,
            'laporan' => Modules\Laporan::class,
            'pengeluaran' => Modules\Pengeluaran::class,
            'gaji' => Modules\Gaji::class,
            'pengaturan' => Modules\Pengaturan::class,
            'tentang' => Modules\Tentang::class,
        ];

        foreach ($modules as $key => $component) {
            $item = Navigation::item($key);

            // Modul yang kelasnya belum dibuat tetap tampil sebagai placeholder, agar aplikasi tidak error.
            $route = class_exists($component)
                ? Route::get($item['path'], $component)
                : Route::get($item['path'], ModulePlaceholder::class)->defaults('module', $key);

            $route
                ->middleware(Navigation::roleMiddleware($key))
                ->name($item['route']);
        }

        // 04 Rekam medis › Kunjungan (FR-RM). Hak ubah dicek di App\Services\ExamService.
        Route::get('/rekam-medis/kunjungan/{visit}', MedicalRecords\Exam::class)
            ->middleware(Navigation::roleMiddleware('rekam-medis'))
            ->name('rekam-medis.periksa');

        Route::get('/rekam-medis/kunjungan/{visit}/cetak', MedicalRecordPrintController::class)
            ->middleware(Navigation::roleMiddleware('rekam-medis'))
            ->name('rekam-medis.cetak');

        // Slip gaji PDF: Owner semua slip; dokter/karyawan slip miliknya (dicek di controller).
        Route::get('/gaji/slip/{type}/{id}', PayslipController::class)
            ->whereIn('type', ['doctor', 'employee'])->whereNumber('id')
            ->name('gaji.slip');

        // Buku manual PDF per peran (hak akses dicek di controller).
        Route::get('/panduan/manual/{role}', ManualDownloadController::class)->name('manual.unduh');

        // Laporan PDF (FR-LAP-06).
        Route::get('/laporan/pdf', ReportPdfController::class)
            ->middleware(Navigation::roleMiddleware('laporan'))
            ->name('laporan.pdf');

        if (class_exists(ReceiptPdfController::class)) {
            Route::get('/kasir/kwitansi/{receipt}', ReceiptPdfController::class)
                ->middleware(Navigation::roleMiddleware('kasir'))
                ->name('kasir.kwitansi');
        }
    });
});
