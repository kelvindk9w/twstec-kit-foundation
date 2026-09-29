<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separa a correlação do CLIENTE do identificador interno da trilha.
 *
 * `correlation_id` é UNIQUE e passou a ser SEMPRE gerado pelo servidor. Até
 * aqui ele aceitava o header X-Correlation-Id de entrada — e isso era um
 * interruptor da auditoria: bastava repetir um UUID já usado para que o
 * INSERT seguinte violasse a constraint e, como a gravação da trilha roda
 * dentro de try/catch (o log não pode derrubar a requisição), a requisição
 * saísse sem nenhuma linha. Inclusive as linhas BLOQUEADA das tentativas de
 * ataque.
 *
 * O valor enviado pelo cliente continua sendo útil — é assim que um cliente
 * de API amarra a chamada dele à nossa — e passa a morar aqui: coluna
 * própria, SEM unicidade (repetição é esperada e legítima), nullable (a
 * maioria das requisições não manda o header) e com índice comum, porque a
 * busca por ele é justamente o caso de uso de suporte.
 *
 * 255 é o teto da coluna; o limite efetivo de saneamento é configurável em
 * config/security.php (ver Twstec\Kit\Foundation\Logging\CorrelationId).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_logs', function (Blueprint $table) {
            $table->string('client_correlation_id', 255)->nullable()->after('correlation_id');

            $table->index('client_correlation_id');
        });
    }

    public function down(): void
    {
        Schema::table('request_logs', function (Blueprint $table) {
            $table->dropIndex(['client_correlation_id']);
            $table->dropColumn('client_correlation_id');
        });
    }
};
