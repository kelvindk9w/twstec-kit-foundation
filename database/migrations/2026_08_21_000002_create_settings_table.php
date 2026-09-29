<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// =============================================================================
// Configurações editáveis pelo super admin (sem tocar no .env).
//
// Tabela chave→valor (JSON). Somente as chaves da WHITELIST de
// config/settings.php podem ser escritas/lidas pela UI — elas sobrescrevem
// em runtime os valores vindos do .env (ver SettingsManager::applyToConfig,
// aplicado no boot pelo SettingsServiceProvider). O .env continua sendo o
// default/fallback: uma chave ausente na tabela = valor do .env vigente.
// =============================================================================
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
