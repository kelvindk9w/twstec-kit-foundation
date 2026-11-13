<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Logging;

use Illuminate\Contracts\Config\Repository;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\NullHandler;

/**
 * O canal de log `request_log` — a SEGUNDA camada das trilhas.
 *
 * A primeira camada é o banco (`request_logs`, `audit_events`). Esta é o
 * arquivo: JSON estruturado, uma linha por evento, com os números de cartão
 * mascarados. Nele o pacote grava o que precisa sobreviver a uma falha do
 * banco ou a quem tem acesso de dono à tabela — `request.started` /
 * `request.finished`, `security.observed` / `security.blocked`,
 * `request.throttled`, `audit.event` / `audit.persist_failed` e as falhas de
 * gravação de cada um.
 *
 * O pacote registra o canal SÓ quando a aplicação não definiu o dela: o da
 * aplicação sempre vence (a mesma regra das traduções). Sem isto, numa
 * aplicação que não copiou o canal para o config/logging.php, essas linhas
 * iriam para o logger de emergência do Laravel — a segunda camada sumiria em
 * silêncio.
 *
 * Variáveis: LOG_LEVEL (nível, padrão info) e REQUEST_LOG_DAYS (retenção dos
 * arquivos diários, padrão 30).
 *
 * NOS TESTES, DESCARTA: com REQUEST_LOG_DISCARD=true (o phpunit.xml dos
 * starters liga) E o ambiente `testing`, o canal vira um NullHandler — nada
 * vai para storage/logs (a suíte inteira, rodada a cada verificação, enchia o
 * disco com dezenas de MB por dia). O evento de log continua sendo disparado:
 * Log::spy(), Log::shouldReceive() e Log::listen() seguem vendo cada linha.
 * Fora do ambiente `testing` a variável é ignorada: produção e
 * desenvolvimento gravam sempre. O teste que precisa do ARQUIVO usa
 * fileDefinition() com um caminho próprio.
 */
final class RequestLogChannel
{
    public const NAME = 'request_log';

    /**
     * Variável que liga o descarte nos testes.
     */
    public const DISCARD_ENV = 'REQUEST_LOG_DISCARD';

    /**
     * A definição padrão do canal: o arquivo diário — ou, nos testes com
     * REQUEST_LOG_DISCARD=true, o descarte.
     *
     * @return array<string, mixed>
     */
    public static function definition(): array
    {
        return self::discards() ? self::discardDefinition() : self::fileDefinition();
    }

    /**
     * Descartar nesta execução? Só com a variável ligada E no ambiente
     * `testing` — nunca em produção nem em desenvolvimento.
     */
    public static function discards(): bool
    {
        return filter_var(env(self::DISCARD_ENV, false), FILTER_VALIDATE_BOOL)
            && app()->runningUnitTests();
    }

    /**
     * O canal que descarta tudo (nos testes).
     *
     * @return array<string, mixed>
     */
    public static function discardDefinition(): array
    {
        return [
            'name' => self::NAME,
            'driver' => 'monolog',
            'handler' => NullHandler::class,
            'level' => env('LOG_LEVEL', 'info'),
        ];
    }

    /**
     * O canal de verdade: JSON em arquivo diário.
     *
     * @return array<string, mixed>
     */
    public static function fileDefinition(): array
    {
        return [
            'name' => self::NAME,
            'driver' => 'daily',
            'path' => storage_path('logs/request.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => (int) env('REQUEST_LOG_DAYS', 30),
            // Arquivo diário nasce com escrita para o grupo: se outro processo
            // do mesmo grupo (scheduler, queue, artisan) o criar primeiro, o
            // php-fpm continua conseguindo escrever.
            'permission' => 0664,
            'formatter' => JsonFormatter::class,
            'replace_placeholders' => true,
            'tap' => [MaskCardNumbersInLogs::class],
        ];
    }

    /**
     * Registra o canal padrão se a aplicação não definiu um `request_log`.
     * Devolve se registrou.
     */
    public static function registerDefault(Repository $config): bool
    {
        if ($config->has('logging.channels.'.self::NAME)) {
            return false;
        }

        $config->set('logging.channels.'.self::NAME, self::definition());

        return true;
    }
}
