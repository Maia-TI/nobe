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
        Schema::table('export_acordos_lancamentos', function (Blueprint $table) {
            $table->date('DDTCADASTRO')->nullable();
            $table->integer('ICODCADASTRO')->nullable();
            $table->integer('IIDENTCADASTRO')->nullable();
            $table->integer('IID_LANCAMENTOORIGEM')->nullable();
            $table->string('VMESEXERCICIO', 2)->nullable();
            $table->integer('IID_RECEITA')->nullable();
            $table->string('VESPECIFICACAO', 200)->nullable();
            $table->date('DDTVENCIMENTO')->nullable();
            $table->string('VINSCRICAO', 25)->nullable();
            $table->decimal('NSUBTOTAL', 15, 2)->nullable();
            $table->decimal('NCMONETARIA', 15, 2)->nullable();
            $table->decimal('NJUROS', 15, 2)->nullable();
            $table->decimal('NMULTA', 15, 2)->nullable();
            $table->decimal('NDESCONTO', 15, 2)->nullable();
            $table->decimal('NTOTEXERCICIO', 15, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('export_acordos_lancamentos', function (Blueprint $table) {
            //
        });
    }
};
