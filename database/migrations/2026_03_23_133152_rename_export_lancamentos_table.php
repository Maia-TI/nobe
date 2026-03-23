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
        Schema::rename('export_lancamentos', 'export_lancamento_alvaras');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::rename('export_lancamento_alvaras', 'export_lancamentos');
    }
};
