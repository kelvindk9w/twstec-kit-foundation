<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela append-only de logs de requisição.
 *
 * - Sem updated_at: a linha nasce no recebimento (status INICIADA) e só
 *   sofre as transições controladas de ciclo de vida (ver model RequestLog).
 * - tenant_uuid nullable: preenchido quando o tenant é resolvido (ResolveTenant);
 *   log sem tenant = possível ataque.
 * - payload: JSON já sanitizado/redigido (LGPD) — NUNCA dado sensível cru.
 * - Em produção, complementar com REVOKE UPDATE/DELETE da role da aplicação
 *   (retificações só por superuser) e particionamento mensal quando o
 *   volume crescer (ver seção 2.3 da pesquisa de stack).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_logs', function (Blueprint $table) {
            // Identificadores: id interno nunca exposto; uuid externo.
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('correlation_id')->unique();
            $table->uuid('tenant_uuid')->nullable()->index();

            // Quem / de onde.
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();

            // O quê.
            $table->string('method', 10);
            $table->string('endpoint', 2048);
            $table->json('payload')->nullable();

            // Resultado / ciclo de vida.
            $table->string('status', 20)->index();
            $table->string('attack_type', 50)->nullable();
            $table->unsignedSmallInteger('http_status_response')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            // Append-only: somente created_at (UTC).
            $table->timestampTz('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_logs');
    }
};
