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
        Schema::create('export_acordos_dams', function (Blueprint $table) {
            $table->bigInteger('IID_DAM')->primary(); 
            $table->integer('IID_ACORDO')->index();
            $table->bigInteger('IID_LANCAMENTO')->index();
            $table->date('DDTCADASTRO')->nullable();
            $table->time('THRCADASTRO')->nullable();
            $table->string('VPARCELA', 5)->nullable();
            $table->date('DDTEMISSAO')->nullable();
            $table->date('DDTVENCIMENTO')->nullable();
            $table->decimal('NSUBTOTAL', 15, 2)->nullable();
            $table->decimal('NCMONETARIA', 15, 2)->nullable();
            $table->decimal('NJUROS', 15, 2)->nullable();
            $table->decimal('NMULTA', 15, 2)->nullable();
            $table->decimal('NTXEXPEDIENTE', 15, 2)->nullable();
            $table->decimal('NDESCONTO', 15, 2)->nullable();
            $table->decimal('NTOTPAGAR', 15, 2)->nullable();
            $table->string('VNOSSONUMEROMIGRACAO', 50)->nullable();
            $table->string('VTEXTOCODBARRAS', 75)->nullable();
            $table->string('VDAMNUMERO', 25)->nullable();
            
            $table->boolean('synced')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('export_acordos_dams');
    }
};
