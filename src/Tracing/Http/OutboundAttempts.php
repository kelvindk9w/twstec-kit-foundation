<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Tracing\Http;

/**
 * Contador de TENTATIVAS de um cliente `Http` (uma PendingRequest).
 *
 * O Laravel refaz a chamada (`retry()`) passando de novo pela pilha de
 * middlewares, sem dizer a ela qual é a tentativa. A regra daqui reproduz a
 * do próprio `retry()`: o Laravel só tenta de novo depois de uma tentativa
 * que FALHOU (status fora de 2xx ou falha de conexão), com o mesmo método e a
 * mesma URL. Então: mesmo destino logo depois de uma falha = tentativa
 * seguinte; qualquer outra coisa = tentativa 1.
 *
 * Um objeto por PendingRequest, entregue nas opções do Guzzle pelo
 * `Http::globalOptions` do pacote (ver OutboundCorrelation). Se a aplicação
 * trocar as opções globais do cliente depois do boot, o objeto não chega e a
 * tentativa fica nula na trilha — a chamada e o resto da linha seguem iguais.
 */
final class OutboundAttempts
{
    private ?string $lastKey = null;

    private bool $lastFailed = false;

    private int $lastAttempt = 0;

    public function next(string $key): int
    {
        $attempt = $this->lastKey === $key && $this->lastFailed ? $this->lastAttempt + 1 : 1;

        $this->lastKey = $key;
        $this->lastFailed = false;
        $this->lastAttempt = $attempt;

        return $attempt;
    }

    public function finished(bool $failed): void
    {
        $this->lastFailed = $failed;
    }
}
