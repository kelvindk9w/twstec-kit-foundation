<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Logging;

/**
 * ONDE nasceu o correlation_id que está valendo agora.
 *
 * O id nasce uma vez e segue a operação: uma requisição HTTP que despacha um
 * job e o job que chama um serviço externo levam o MESMO id, com a origem
 * `http`. Só ganha id novo o que não veio de lugar nenhum:
 *
 * - `http`: a requisição (gerado pelo RequestLogging — nunca vem do cliente);
 * - `scheduler`: cada tarefa do agendador (`schedule:run`), inclusive o
 *   comando que ela roda em outro processo;
 * - `queue`: job que chegou ao worker SEM id no payload (enfileirado antes
 *   desta versão, ou por um produtor de fora do Laravel);
 * - `console`: comando artisan rodado à mão (ou outro processo de console).
 */
enum CorrelationOrigin: string
{
    case Http = 'http';
    case Scheduler = 'scheduler';
    case Queue = 'queue';
    case Console = 'console';
}
