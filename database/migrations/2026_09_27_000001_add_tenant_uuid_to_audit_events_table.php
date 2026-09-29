<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A CONTA (tenant) em que a ação aconteceu — `audit_events.tenant_uuid`.
 *
 * O mesmo nome e o mesmo valor de `request_logs.tenant_uuid`: o uuid da
 * conta. Nulo quando a ação não é de uma conta (o /admin mexendo num
 * usuário, uma configuração, um comando). Responde à quarta pergunta de
 * auditoria: o que aconteceu NESTA conta, por período.
 *
 * Só acrescenta uma coluna nula e um índice: nenhuma linha antiga é
 * reescrita (o gatilho de append-only do PostgreSQL recusa UPDATE, não DDL).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('audit_events', 'tenant_uuid')) {
            return;
        }

        Schema::table('audit_events', function (Blueprint $table) {
            $table->uuid('tenant_uuid')->nullable()->after('subject_uuid');
            $table->index(['tenant_uuid', 'occurred_at']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('audit_events', 'tenant_uuid')) {
            return;
        }

        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropIndex(['tenant_uuid', 'occurred_at']);
            $table->dropColumn('tenant_uuid');
        });
    }
};
