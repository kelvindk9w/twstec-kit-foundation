<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Tracing\Scheduling;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Context\Repository as LaravelContext;
use Twstec\Kit\Foundation\Logging\CorrelationContext;
use Twstec\Kit\Foundation\Logging\CorrelationOrigin;

/**
 * Cada tarefa do agendador ganha um correlation_id PRÓPRIO, de origem
 * `scheduler`, que segue para tudo o que ela fizer.
 *
 * - No processo do `schedule:run`: ao começar a tarefa, um id novo é
 *   empilhado; ao terminar (ou falhar), desempilhado. Job despachado e
 *   chamada HTTP feita por uma tarefa `call()`/`job()` levam esse id.
 * - No processo FILHO de uma tarefa `command()`/`exec()`: o Laravel já passa
 *   o seu Context ao filho (variável `__LARAVEL_CONTEXT`). O pacote põe ali,
 *   escondido (hidden — não vai para o log nem para o payload como dado), o
 *   id e a origem correntes; o filho os adota no boot. O mesmo vale para
 *   qualquer outro processo que o Laravel lance com o Context (o driver
 *   `process` do Concurrency).
 */
final class ScheduleCorrelation
{
    /**
     * Chave escondida no Context do Laravel que leva o id ao processo filho.
     */
    public const CONTEXT_KEY = 'tws_correlation';

    /**
     * Profundidade da pilha antes de cada tarefa em andamento.
     *
     * @var array<int, int>
     */
    private static array $depths = [];

    public static function register(Dispatcher $events, LaravelContext $context): void
    {
        $events->listen(ScheduledTaskStarting::class, static fn (ScheduledTaskStarting $event) => self::start($event->task));

        foreach ([ScheduledTaskFinished::class, ScheduledTaskFailed::class, ScheduledTaskSkipped::class] as $end) {
            $events->listen($end, static fn (object $event) => self::finish($event->task));
        }

        // Toda vez que o Context sai do processo (para o filho do agendador,
        // para o payload de um job), leva o id corrente — na CÓPIA que está
        // saindo, nunca no Context vivo do processo.
        $context->dehydrating(static function (LaravelContext $outgoing): void {
            $snapshot = app(CorrelationContext::class)->snapshot();

            if ($snapshot !== null) {
                $outgoing->addHidden(self::CONTEXT_KEY, $snapshot);
            }
        });
    }

    /**
     * Processo de console que nasceu de outro (filho do agendador): adota o
     * id que veio no Context. Chamado no boot, só no console.
     */
    public static function adoptFromParent(LaravelContext $context): void
    {
        $snapshot = CorrelationContext::parseSnapshot($context->getHidden(self::CONTEXT_KEY));

        if ($snapshot === null) {
            return;
        }

        $correlation = app(CorrelationContext::class);

        if ($correlation->current() === null) {
            $correlation->push($snapshot['id'], $snapshot['origin']);
        }
    }

    public static function start(ScheduledEvent $task): void
    {
        self::$depths[spl_object_id($task)] = app(CorrelationContext::class)
            ->push(CorrelationContext::newId(), CorrelationOrigin::Scheduler);
    }

    public static function finish(ScheduledEvent $task): void
    {
        $id = spl_object_id($task);

        if (! array_key_exists($id, self::$depths)) {
            return;
        }

        app(CorrelationContext::class)->popTo(self::$depths[$id]);

        unset(self::$depths[$id]);
    }
}
