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
        Schema::create('export_contribuintes', function (Blueprint $table) {
            $table->bigInteger('IID_CONTRIBUINTE')->primary();
            $table->string('VCPF_CNPJ', 18)->nullable();
            $table->date('DDATA_INICIO_ATIVIDADE')->nullable();
            $table->string('VRAZAO_SOCIAL')->nullable();
            $table->string('VNOME_FANTASIA')->nullable();
            $table->string('VDDD_TELEFONE_1')->nullable();
            $table->string('VEMAIL')->nullable();
            $table->integer('ICODIGO_MUNICIPIO_IBGE')->nullable();
            $table->string('VCEP', 15)->nullable();
            $table->string('VBAIRRO')->nullable();
            $table->string('VNUMERO')->nullable();
            $table->string('VDESCRICAO_TIPO_DE_LOGRADOURO')->nullable();
            $table->string('VLOGRADOURO')->nullable();
            $table->string('VCOMPLEMENTO')->nullable();
            $table->string('VINSCESTADUAL', 12)->nullable();
            $table->boolean('LOPCAO_PELO_MEI')->default(false);
            $table->boolean('LOPCAO_PELO_SIMPLES')->default(false);
            $table->string('VREGIME_TRIBUTARIO')->nullable();
            $table->string('VNATUREZA_JURIDICA')->nullable();
            $table->string('VPORTE')->nullable();
            $table->boolean('synced')->default(false);
            
            // Colunas de suporte (IDs para joins posteriores no PostgreSQL)
            $table->integer('CODCIDADE')->nullable();
            $table->integer('CODBAIRRO')->nullable();
            $table->integer('CODLOGRADOURO')->nullable();
            $table->char('PESSOA', 1)->nullable(); // F ou J

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('export_contribuintes');
    }
};
