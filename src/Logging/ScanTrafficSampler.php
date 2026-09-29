<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Logging;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;
use Twstec\Kit\Foundation\Security\ClientBucket;

/**
 * Amostragem do tráfego ANÔNIMO DE VARREDURA na trilha de auditoria em banco
 * (a trilha continua registrando varredura, sem gravar cada requisição).
 *
 * O PROBLEMA: toda requisição gerava INSERT + UPDATE em `request_logs`,
 * inclusive a rota inexistente de um robô procurando `/wp-login.php` e
 * `/.env`. Um flood anônimo virava escrita no banco na velocidade da rede —
 * DoS barato, e a tabela de auditoria soterrada por ruído.
 *
 * A ESTRATÉGIA: por cliente (ClientBucket — IP, ou prefixo IPv6) e por
 * janela, a PRIMEIRA ocorrência de cada tipo de ruído vai para o banco como
 * sempre foi; as seguintes, dentro da mesma janela, ficam só no log de
 * arquivo (ou nem isso, no caso do 429 — ver EdgeRateLimit). A escrita no
 * banco deixa de ser uma por requisição e passa a ser, no máximo, uma por
 * cliente por janela por tipo.
 *
 * Por que amostrar e não simplesmente não gravar: a primeira sondagem de um
 * IP é exatamente o sinal que a trilha existe para registrar ("esse endereço
 * começou a varrer às 03:12"), e o /admin precisa enxergá-la. Por que não
 * agregar numa contagem: a linha amostrada é uma linha normal da trilha
 * (mesmo formato, mesmo ciclo, mesmo payload redigido), sem tabela nova nem
 * job de consolidação — e o volume exato do flood já está no limiter e no log
 * de arquivo.
 *
 * O QUE NUNCA É AMOSTRADO (a trilha de auditoria é o coração do kit):
 * - requisição bloqueada pelo SecurityValidation (ataque detectado): é
 *   gravada por ele, antes de chegar aqui, sempre;
 * - requisição que casa com uma rota: pode ser autenticada, e o desfecho só
 *   se conhece no fim — a linha INICIADA é gravada na chegada, como sempre;
 * - tudo o que não for ruído declarado aqui.
 *
 * "Rota não casada" é, por construção, anônima: sem rota não há middleware
 * de sessão nem de chave de API, então não existe requisição autenticada que
 * termine em 404/405 de rota inexistente.
 *
 * Falha do cache = grava no banco (o lado da auditoria, não o do silêncio).
 */
final class ScanTrafficSampler
{
    /**
     * Rota inexistente ou método não permitido (404/405 de varredura).
     */
    public const UNMATCHED = 'unmatched';

    /**
     * Requisição recusada pelo limite da borda (429).
     */
    public const THROTTLED = 'throttled';

    /**
     * Esta ocorrência deve ir para o banco? Verdadeiro para a primeira de
     * cada cliente/tipo dentro da janela configurada.
     */
    public static function shouldPersist(string $kind, Request $request): bool
    {
        $window = (int) config('security.request_logging.scan_sample_window_seconds', 60);

        // Janela <= 0: amostragem desligada — toda ocorrência vai ao banco
        // (o comportamento antigo, para quem prefere volume a contenção).
        if ($window <= 0) {
            return true;
        }

        try {
            return Cache::add('request-log-sample:'.$kind.':'.ClientBucket::for($request), 1, $window);
        } catch (Throwable) {
            return true;
        }
    }
}
