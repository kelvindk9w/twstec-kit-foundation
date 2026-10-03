<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Twstec\Kit\Foundation\Idempotency\IdempotencyStore;

/**
 * Chaves de idempotência das escritas da API (`Idempotency-Key`).
 *
 * - A UNICIDADE (`scope_hash`, `key_hash`) é quem decide a corrida: das
 *   requisições simultâneas com a mesma chave, só uma consegue inserir a
 *   linha "em processamento" — o banco garante, não o cache.
 * - Nada em claro do que o cliente mandou: a chave e o escopo viram SHA-256,
 *   e o corpo da requisição nunca é guardado — só o hash da forma canônica
 *   (método, caminho, query e corpo normalizado).
 * - `response`: a resposta guardada para o replay, CIFRADA com a chave da
 *   aplicação (APP_KEY). Nula enquanto em processamento.
 * - `expires_at`: a validade da chave; a poda (`idempotency:prune`) remove o
 *   que venceu.
 *
 * Conexão: a de `idempotency.connection` (vazio = a padrão) — a mesma que o
 * IdempotencyStore usa.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        $configured = config('idempotency.connection');

        return is_string($configured) && $configured !== '' ? $configured : parent::getConnection();
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create(IdempotencyStore::TABLE, function (Blueprint $table) {
            $table->id();

            // Quem (escopo: conta + credencial ou pessoa) e qual chave — só hash.
            $table->char('scope_hash', 64);
            $table->char('key_hash', 64);

            // O que foi pedido: hash da forma canônica, método e rota (padrão).
            $table->char('request_hash', 64);
            $table->string('method', 10);
            $table->string('route', 255);

            // Ciclo: `processing` → `completed` (ou a linha é apagada quando a
            // execução falha e a chave fica livre de novo).
            $table->string('status', 20);
            $table->uuid('owner');
            $table->timestampTz('locked_until')->nullable();

            // A requisição original (o replay a referencia).
            $table->uuid('correlation_id')->nullable();

            // Resultado, cifrado.
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response')->nullable();
            $table->boolean('response_withheld')->default(false);

            $table->timestampTz('expires_at')->index();
            $table->timestampsTz();

            $table->unique(['scope_hash', 'key_hash']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists(IdempotencyStore::TABLE);
    }
};
