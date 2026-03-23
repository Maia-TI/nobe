<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('export_lancamentos', function (Blueprint $table) {
            $table->string('tipo', 50)->nullable()->index()->after('NVALIMPOSTOCALC');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('export_lancamentos', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
    }
};
