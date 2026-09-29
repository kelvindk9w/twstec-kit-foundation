<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Logging;

use Illuminate\Contracts\Config\Repository;
use Monolog\Formatter\JsonFormatter;

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
 */
final class RequestLogChannel
{
    public const NAME = 'request_log';

    /**
     * A definição padrão do canal.
     *
     * @return array<string, mixed>
     */
    public static function definition(): array
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
