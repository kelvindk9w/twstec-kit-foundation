<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quem fez, por qual caminho de rede e com quais cabeçalhos (#121).
 *
 * - actor_type (`user`, `api_key`, `system`, `anonymous`) e actor_uuid: o
 *   uuid da pessoa, nunca o `id` interno. api_key_uuid quando a chamada veio
 *   por chave (mesmo com a pessoa por trás dela). Preenchidos no FIM do
 *   ciclo, junto com o status: o ator só é conhecido depois da autenticação.
 * - session_ref: HMAC curto do id da sessão (com a APP_KEY) — liga as linhas
 *   da mesma sessão sem guardar o id, que abriria a sessão.
 * - route_name: o nome da rota (o `endpoint` já guarda o padrão).
 * - ip_chain: o X-Forwarded-For como chegou, só com caracteres de endereço
 *   e com limite; forwarded_trusted diz se o REMOTE_ADDR era um proxy
 *   confiável (sem isso, a cadeia é só o que o cliente afirmou).
 * - headers: lista FECHADA de cabeçalhos (security.request_logging.headers),
 *   valores redigidos; credencial (Authorization, Cookie, X-Api-Key...) nunca.
 *
 * Tudo nulo: as linhas antigas e as gravadas antes do fim do ciclo seguem
 * válidas. As colunas do fim do ciclo entram no UPDATE permitido ao papel
 * da aplicação (Twstec\Kit\Foundation\Database\AppendOnlyTables).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_logs', function (Blueprint $table) {
            $table->string('actor_type', 20)->nullable()->after('tenant_uuid');
            $table->string('actor_uuid', 36)->nullable()->after('actor_type');
            $table->string('api_key_uuid', 36)->nullable()->after('actor_uuid');
            $table->string('session_ref', 32)->nullable()->after('api_key_uuid');
            $table->string('route_name', 255)->nullable()->after('endpoint');
            $table->string('ip_chain', 1000)->nullable()->after('ip');
            $table->boolean('forwarded_trusted')->nullable()->after('ip_chain');
            $table->json('headers')->nullable()->after('user_agent');

            $table->index('actor_uuid');
            $table->index('api_key_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('request_logs', function (Blueprint $table) {
            $table->dropIndex(['actor_uuid']);
            $table->dropIndex(['api_key_uuid']);
            $table->dropColumn(['actor_type', 'actor_uuid', 'api_key_uuid', 'session_ref', 'route_name', 'ip_chain', 'forwarded_trusted', 'headers']);
        });
    }
};
