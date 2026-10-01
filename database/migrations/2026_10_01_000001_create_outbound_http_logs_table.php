<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Twstec\Kit\Foundation\Tracing\Support\OutboundHttpLogTrigger;

/**
 * Trilha append-only das CHAMADAS HTTP DE SAÍDA (uma linha por tentativa).
 *
 * - Sem updated_at: a linha nasce pronta, quando a resposta (ou a falha de
 *   conexão) chega.
 * - Destino como pode ser guardado: host + rota NORMALIZADA (segmentos que
 *   parecem valor viram `{n}`, `{uuid}`, `{token}`…) + só os NOMES dos
 *   parâmetros da query. Nunca a URL crua, nunca cabeçalho.
 * - `request_body`/`response_body`: nulos, salvo quando a chamada pediu
 *   (`withBodyInTrail()`) — e então já redigidos (Redactor).
 * - Índices para as perguntas de investigação: tudo desta operação
 *   (`correlation_id`), tudo deste destino por período (`host` +
 *   `created_at`), tudo desta conta por período e a poda (`created_at`).
 * - No PostgreSQL, o gatilho OutboundHttpLogTrigger recusa UPDATE/TRUNCATE e
 *   todo DELETE fora da poda (`outbound-http:prune`).
 *
 * Conexão: a de `tracing.http.trail.connection` (vazio = a padrão) — a mesma
 * que o model usa.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        $configured = config('tracing.http.trail.connection');

        return is_string($configured) && $configured !== '' ? $configured : parent::getConnection();
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create(OutboundHttpLogTrigger::TABLE, function (Blueprint $table) {
            // Identificadores: id interno nunca exposto; uuid externo.
            $table->id();
            $table->uuid('uuid')->unique();

            // Amarração com a operação (requisição → job → chamada) e a conta.
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('correlation_origin', 20)->nullable();
            $table->uuid('tenant_uuid')->nullable();

            // Destino.
            $table->string('method', 10);
            $table->string('host', 255);
            $table->string('path', 600);
            $table->json('query_keys')->nullable();

            // Resultado.
            $table->unsignedSmallInteger('attempt')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->unsignedBigInteger('request_bytes')->nullable();
            $table->unsignedBigInteger('response_bytes')->nullable();
            $table->string('error', 500)->nullable();

            // Corpo: só quando a chamada pediu, e já redigido.
            $table->json('request_body')->nullable();
            $table->json('response_body')->nullable();

            // Append-only: somente created_at (UTC).
            $table->timestampTz('created_at')->useCurrent()->index();

            $table->index(['host', 'created_at']);
            $table->index(['tenant_uuid', 'created_at']);
        });

        OutboundHttpLogTrigger::install(DB::connection($this->getConnection()));
    }

    public function down(): void
    {
        OutboundHttpLogTrigger::drop(DB::connection($this->getConnection()));

        Schema::connection($this->getConnection())->dropIfExists(OutboundHttpLogTrigger::TABLE);
    }
};
