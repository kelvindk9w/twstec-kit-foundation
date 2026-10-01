<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Tracing\Queue;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Queue;
use Twstec\Kit\Foundation\Logging\CorrelationContext;
use Twstec\Kit\Foundation\Logging\CorrelationOrigin;

/**
 * Jobs CARREGAM o correlation_id de quem os despachou e o RESTAURAM no worker.
 *
 * - Ao enfileirar (qualquer driver: `database`, `redis`, `sync`…): o payload
 *   ganha `twsCorrelation` = {id, origin} — o id que vale no momento (o da
 *   requisição, o do job em andamento, o da tarefa do agendador) ou, se nada
 *   abriu um, um id novo. Só isso: nenhum dado da requisição, nenhum
 *   segredo.
 * - Ao processar: antes do job, o id do payload é empilhado no contexto
 *   (log e trilhas passam a usá-lo); depois — sucesso, exceção, falha ou
 *   devolução à fila —, desempilhado. Job que chega SEM id (enfileirado antes
 *   desta versão, ou por produtor de fora) recebe um id novo, de origem
 *   `queue`.
 *
 * Encadeados (`Bus::chain`) e lotes (`Bus::batch`) herdam sozinhos: o próximo
 * da cadeia é despachado de DENTRO do job anterior (com o id restaurado), e
 * os jobs do lote são enfileirados juntos, no contexto de quem despachou.
 *
 * A mesma forma da conta atual no twstec/kit-accounts (AccountJobContext).
 */
final class QueueCorrelation
{
    public const PAYLOAD_KEY = 'twsCorrelation';

    /**
     * Profundidade da pilha antes de cada job em processamento.
     *
     * @var array<int, int>
     */
    private static array $depths = [];

    public static function register(Dispatcher $events): void
    {
        // O gancho do payload é estático no Queue do Laravel (os testes do
        // framework o limpam entre um teste e outro): registrado a cada boot,
        // lendo o contexto do container da vez. Registrar de novo não duplica
        // nada — a chave é a mesma.
        Queue::createPayloadUsing(static fn (): array => [
            self::PAYLOAD_KEY => self::payload(),
        ]);

        $events->listen(JobProcessing::class, static fn (JobProcessing $event) => self::restore($event->job));

        foreach ([JobProcessed::class, JobExceptionOccurred::class, JobFailed::class, JobReleasedAfterException::class] as $end) {
            $events->listen($end, static fn (object $event) => self::release($event->job));
        }
    }

    /**
     * O que vai no payload: id e origem do contexto corrente (criado se
     * preciso).
     *
     * @return array{id: string, origin: string}
     */
    public static function payload(): array
    {
        $context = app(CorrelationContext::class);
        $context->ensure();

        /** @var array{id: string, origin: string} */
        return $context->snapshot();
    }

    public static function restore(Job $job): void
    {
        $snapshot = CorrelationContext::parseSnapshot($job->payload()[self::PAYLOAD_KEY] ?? null);

        $context = app(CorrelationContext::class);

        self::$depths[spl_object_id($job)] = $snapshot !== null
            ? $context->push($snapshot['id'], $snapshot['origin'])
            : $context->push(CorrelationContext::newId(), CorrelationOrigin::Queue);
    }

    public static function release(Job $job): void
    {
        $id = spl_object_id($job);

        if (! array_key_exists($id, self::$depths)) {
            return;
        }

        app(CorrelationContext::class)->popTo(self::$depths[$id]);

        unset(self::$depths[$id]);
    }
}
