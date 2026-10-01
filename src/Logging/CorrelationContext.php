<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Logging;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * O correlation_id que está valendo AGORA neste processo — na requisição, no
 * job que o worker está processando ou na tarefa do agendador.
 *
 * É uma pilha: a requisição põe o id dela na base; um job processado na fila
 * `sync` (dentro da mesma requisição) ou uma tarefa do agendador empilham o
 * deles e desempilham no fim, devolvendo o anterior. O topo é o que vale, e
 * é ele que vai:
 *
 * - para o contexto compartilhado do log (`correlation_id` e
 *   `correlation_origin` em toda linha de `Log::*`);
 * - para o payload de todo job despachado (Tracing\Queue\QueueCorrelation);
 * - para o cabeçalho e a trilha de toda chamada HTTP de saída
 *   (Tracing\Http\OutboundCorrelation);
 * - para o processo filho de uma tarefa do agendador (Tracing\Scheduling\
 *   ScheduleCorrelation).
 *
 * Registrado como `scoped`: o worker da fila e o Octane descartam a instância
 * entre um job/requisição e outro — nada de um vaza para o seguinte.
 */
final class CorrelationContext
{
    /**
     * Chaves no contexto compartilhado do log.
     */
    public const LOG_ID = 'correlation_id';

    public const LOG_ORIGIN = 'correlation_origin';

    /**
     * @var list<array{id: string, origin: CorrelationOrigin}>
     */
    private array $frames = [];

    /**
     * O id que vale agora, ou null quando nada abriu um (comando de console
     * que ainda não despachou job nem chamou serviço externo, por exemplo).
     * Não cria id.
     */
    public function current(): ?string
    {
        return $this->top()['id'] ?? null;
    }

    public function origin(): ?CorrelationOrigin
    {
        return $this->top()['origin'] ?? null;
    }

    /**
     * O id que vale agora — ou um novo, quando nada abriu um. Numa requisição
     * HTTP é o da própria requisição; fora dela, um id de origem `console`
     * que passa a valer para o resto do processo.
     */
    public function ensure(): string
    {
        $current = $this->current();

        if ($current !== null) {
            return $current;
        }

        if (! app()->runningInConsole() && app()->bound('request')) {
            $request = app('request');

            if ($request instanceof Request) {
                return CorrelationId::resolve($request);
            }
        }

        $id = self::newId();
        $this->push($id, CorrelationOrigin::Console);

        return $id;
    }

    /**
     * O id da requisição vira a BASE da pilha (uma requisição nova nunca
     * herda o que sobrou da anterior no mesmo processo).
     */
    public function enterRequest(string $id): void
    {
        $this->frames = [];
        $this->push($id, CorrelationOrigin::Http);
    }

    /**
     * Empilha um id. Devolve a profundidade ANTERIOR, para o popTo().
     */
    public function push(string $id, CorrelationOrigin $origin): int
    {
        $depth = count($this->frames);

        $this->frames[] = ['id' => $id, 'origin' => $origin];
        $this->syncLog();

        return $depth;
    }

    /**
     * Volta a pilha à profundidade informada (o que o push() devolveu).
     */
    public function popTo(int $depth): void
    {
        $this->frames = array_slice($this->frames, 0, max(0, $depth));
        $this->syncLog();
    }

    /**
     * O que viaja para o job e para o processo filho: id e origem, nada
     * mais — nenhum dado da requisição.
     *
     * @return array{id: string, origin: string}|null
     */
    public function snapshot(): ?array
    {
        $top = $this->top();

        return $top === null ? null : ['id' => $top['id'], 'origin' => $top['origin']->value];
    }

    /**
     * Lê um snapshot vindo de fora (payload do job, ambiente do processo
     * filho). Só sai id que é UUID e origem conhecida — o payload da fila
     * mora no Redis/banco e não é dado confiável para ir ao log como veio.
     *
     * @return array{id: string, origin: CorrelationOrigin}|null
     */
    public static function parseSnapshot(mixed $snapshot): ?array
    {
        if (! is_array($snapshot)) {
            return null;
        }

        $id = $snapshot['id'] ?? null;
        $origin = is_string($snapshot['origin'] ?? null) ? CorrelationOrigin::tryFrom($snapshot['origin']) : null;

        if (! is_string($id) || ! Str::isUuid($id) || $origin === null) {
            return null;
        }

        return ['id' => strtolower($id), 'origin' => $origin];
    }

    public static function newId(): string
    {
        return (string) Str::uuid7();
    }

    /**
     * @return array{id: string, origin: CorrelationOrigin}|null
     */
    private function top(): ?array
    {
        return $this->frames === [] ? null : $this->frames[array_key_last($this->frames)];
    }

    /**
     * O topo da pilha no contexto compartilhado do log; pilha vazia tira as
     * duas chaves (dos canais já abertos e dos que abrirem depois).
     */
    private function syncLog(): void
    {
        $top = $this->top();

        if ($top !== null) {
            Log::shareContext([self::LOG_ID => $top['id'], self::LOG_ORIGIN => $top['origin']->value]);

            return;
        }

        $keys = [self::LOG_ID, self::LOG_ORIGIN];
        $shared = array_diff_key(Log::sharedContext(), array_flip($keys));

        Log::withoutContext($keys);
        Log::flushSharedContext();

        if ($shared !== []) {
            Log::shareContext($shared);
        }
    }
}
