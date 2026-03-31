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
        Schema::create('export_acordos_lancamentos_origem', function (Blueprint $table) {
            $table->integer('IID_ACORDO')->index();
            $table->date('DDTCADASTRO')->nullable();
            $table->string('IID_LANCAMENTOORIGEM')->nullable();
            $table->string('VANOEXERCICIO')->nullable();
            $table->string('VMESEXERCICIO')->nullable();
            $table->string('IID_RECEITA')->nullable();
            $table->string('VESPECIFICACAO')->nullable();
            $table->string('DDTVENCIMENTO')->nullable();
            $table->string('NSUBTOTAL')->nullable();
            $table->string('NCMONETARIA')->nullable();
            $table->string('NJUROS')->nullable();
            $table->string('NMULTA')->nullable();
            $table->string('NDESCONTO')->nullable();
            $table->string('NTOTEXERCICIO')->nullable();

            $table->boolean('synced')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('export_acordos_lancamentos_origem');
    }
};
