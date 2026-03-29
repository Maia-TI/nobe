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
        Schema::create('export_dam_iptu', function (Blueprint $table) {
            $table->bigInteger('IIDENTMIGRACAO')->primary();
            $table->bigInteger('IID_LANCAMENTO')->index();
            $table->date('DDTCADASTRO')->nullable();
            $table->time('THRCADASTRO')->nullable();
            $table->string('VPARCELA', 10)->nullable();
            $table->date('DDTEMISSAO')->nullable();
            $table->date('DDTVENCIMENTO')->nullable();
            $table->decimal('NSUBTOTAL', 15, 2)->default(0);
            $table->decimal('NCMONETARIA', 15, 2)->default(0);
            $table->decimal('NJUROS', 15, 2)->default(0);
            $table->decimal('NMULTA', 15, 2)->default(0);
            $table->decimal('NTXEXPEDIENTE', 15, 2)->default(0);
            $table->decimal('NDESCONTO', 15, 2)->default(0);
            $table->decimal('NTOTPAGAR', 15, 2)->default(0);
            $table->bigInteger('VNOSSONUMEROMIGRACAO')->nullable();
            $table->string('VTEXTOCODBARRAS', 100)->nullable();
            $table->string('VNUMCODBARRAS', 60)->nullable();
            
            $table->boolean('synced')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('export_dam_iptu');
    }
};
