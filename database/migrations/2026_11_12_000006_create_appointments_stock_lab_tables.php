<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permintaan klien (chat 12 Nov 2026):
 *  1. Jadwal harian pasien  → appointments (janji temu & kontrol)
 *  5/8. Jual-beli alat, bahan, obat + stok → items, item_stocks, stock_movements, purchases
 *  7. Pembayaran lab gigi → lab_orders
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Janji temu ────────────────────────────────────────────
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->time('time')->nullable();
            $table->string('purpose')->nullable();
            $table->string('note')->nullable();
            $table->string('status', 20)->default('terjadwal'); // terjadwal | datang | batal | tidak_datang
            $table->string('source', 20)->default('manual');    // manual | kontrol
            $table->foreignId('source_visit_id')->nullable()->constrained('visits')->nullOnDelete(); // kunjungan yang menyarankan kontrol
            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete(); // kunjungan saat pasien datang
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'date', 'status']);
            $table->index(['patient_id', 'date']);
        });

        // ── Stok obat, bahan, alat ────────────────────────────────
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('category', 20); // obat | bahan | alat
            $table->string('unit', 20);     // tablet, strip, botol, pcs, box
            $table->unsignedBigInteger('buy_price')->default(0);  // harga beli terakhir
            $table->unsignedBigInteger('sell_price')->nullable(); // null = tidak dijual ke pasien
            $table->unsignedInteger('min_stock')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['clinic_id', 'code']);
        });

        Schema::create('item_stocks', function (Blueprint $table) {
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity')->default(0);
            $table->unsignedBigInteger('avg_cost')->default(0); // HPP rata-rata tertimbang
            $table->primary(['item_id', 'branch_id']);
        });

        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->string('supplier')->nullable();
            $table->string('reference', 50)->nullable(); // no. faktur
            $table->unsignedBigInteger('total')->default(0);
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'date']);
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_cost');
            $table->unsignedBigInteger('amount');
        });

        // Buku stok: setiap perubahan stok tercatat (jejak audit stok).
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('type', 20); // masuk | jual | batal_jual | pakai | penyesuaian
            $table->integer('quantity'); // + masuk, − keluar
            $table->unsignedBigInteger('unit_cost')->default(0);
            $table->unsignedBigInteger('unit_price')->nullable();
            $table->foreignId('purchase_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['branch_id', 'occurred_at']);
            $table->index(['item_id', 'branch_id']);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('item_id')->nullable()->after('procedure_id')->constrained()->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('stock_posted_at')->nullable()->after('paid_at');
        });

        Schema::table('prescriptions', function (Blueprint $table) {
            $table->foreignId('item_id')->nullable()->after('visit_id')->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('item_quantity')->nullable()->after('quantity');
        });

        // ── Order lab gigi ────────────────────────────────────────
        Schema::create('lab_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('lab_name');
            $table->string('work');               // mis. Mahkota PFM, gigi tiruan akrilik
            $table->string('tooth', 30)->nullable();
            $table->date('sent_at');
            $table->date('due_at')->nullable();
            $table->date('received_at')->nullable();
            $table->unsignedBigInteger('cost');   // tagihan lab ke klinik
            $table->date('paid_at')->nullable();  // dibayar klinik ke lab
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('dikirim'); // dikirim | diterima | dipasang | batal
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_orders');
        Schema::table('prescriptions', fn (Blueprint $t) => $t->dropConstrainedForeignId('item_id'));
        Schema::table('prescriptions', fn (Blueprint $t) => $t->dropColumn('item_quantity'));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('stock_posted_at'));
        Schema::table('invoice_items', fn (Blueprint $t) => $t->dropConstrainedForeignId('item_id'));
        foreach (['stock_movements', 'purchase_items', 'purchases', 'item_stocks', 'items', 'appointments'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
