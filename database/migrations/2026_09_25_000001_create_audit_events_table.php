<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Twstec\Kit\Foundation\Audit\Support\AuditEventTrigger;

/**
 * Trilha de auditoria de AÇÕES (append-only) — ver Twstec\Kit\Foundation\Audit\Models\AuditEvent.
 *
 * - `changes`: JSON JÁ REDIGIDO (AuditChanges): campos alterados com
 *   antes/depois, nunca senha, hash, token, segredo ou código; e-mail e
 *   nome mascarados.
 * - `correlation_id`: o MESMO da linha de `request_logs` da requisição que
 *   executou a ação (nulo no console).
 * - `context`: admin | panel | api | console (AuditContext).
 * - Índices para as três perguntas de auditoria: o que FULANO fez, o que
 *   aconteceu com ESTE registro e onde aconteceu ESTA ação — todas por
 *   período.
 * - No PostgreSQL, o gatilho AuditEventTrigger recusa UPDATE/TRUNCATE e
 *   todo DELETE fora da poda. Em produção, complementar com REVOKE UPDATE
 *   da role da aplicação (docs/logs-lgpd.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            // Identificadores: id interno nunca exposto; uuid externo.
            $table->id();
            $table->uuid('uuid')->unique();
            $table->timestampTz('occurred_at')->useCurrent()->index();

            // De onde e quem.
            $table->string('context', 20)->index();
            $table->uuid('actor_uuid')->nullable();
            $table->boolean('actor_is_admin')->nullable();

            // O quê, com qual resultado, em qual registro.
            $table->string('action', 120);
            $table->string('outcome', 20)->index();
            $table->string('subject_type', 60)->nullable();
            $table->uuid('subject_uuid')->nullable();
            $table->json('changes')->nullable();
            $table->string('reason', 500)->nullable();

            // Amarração com a trilha de requisições e origem da chamada.
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->index(['actor_uuid', 'occurred_at']);
            $table->index(['subject_type', 'subject_uuid', 'occurred_at']);
            $table->index(['action', 'occurred_at']);
        });

        AuditEventTrigger::install();
    }

    public function down(): void
    {
        AuditEventTrigger::drop();

        Schema::dropIfExists('audit_events');
    }
};
