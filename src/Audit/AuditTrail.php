<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use Twstec\Kit\Foundation\Audit\Enums\AuditOutcome;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;
use Twstec\Kit\Foundation\Identifiers\UuidColumn;
use Twstec\Kit\Foundation\Logging\Redactor;

/**
 * O ÚNICO ponto que grava a trilha de auditoria de ações (`audit_events`).
 *
 * DOIS jeitos de uma ação chegar aqui:
 *
 * 1. AUTOMÁTICO, por escopo. Um ponto de entrada (toda chamada Livewire do
 *    /admin — o AdminAudit do painel, no starter —, o comando
 *    `user:make-admin`) abre um AuditScope. Enquanto ele está aberto, TODO
 *    `created`/`updated`/`deleted` de model Eloquent vira uma linha, com o
 *    resumo redigido do que mudou (AuditChanges). É por isso que um resource
 *    novo do /admin já nasce auditado: ninguém precisa lembrar de chamar nada.
 *    O nome da ação é `<tipo>.<verbo>`: o tipo é o model em snake_case
 *    (`user`, `api_key`) e o verbo é o da ação em curso quando ela declarou
 *    um (`user.blocked`, `api_key.revoked`) ou o evento do model
 *    (`product.created`, `product.updated`, `product.deleted`).
 *
 * 2. EXPLÍCITO, por record()/denied(): para o que não é gravação de model
 *    (configurações, que o SettingsManager registra com a chave e o de/para)
 *    e para as TENTATIVAS RECUSADAS por guarda de servidor (nada mudou, mas
 *    a tentativa fica registrada com `outcome = denied` e o motivo).
 *
 * FALHA FECHADA: a linha é gravada na mesma conexão e, no /admin, dentro da
 * mesma transação da mudança (o painel liga `databaseTransactions()`). Se o
 * INSERT da trilha falhar, a exceção sobe, a transação desfaz a mudança e o
 * operador vê o erro — ação de admin sem registro não acontece.
 *
 * SEGUNDA CAMADA NO ARQUIVO: depois do commit, a mesma ação vira a linha
 * `audit.event` no canal `request_log` (arquivo), com o mesmo
 * `correlation_id`. Ela leva só os NOMES dos campos alterados — os valores
 * ficam no banco. Serve para o que o banco sozinho não cobre: sobreviver a
 * quem tem acesso de dono à tabela e alimentar agregadores de log. Se o
 * próprio INSERT falhar, o arquivo recebe `audit.persist_failed` com a ação
 * que não pôde ser registrada.
 */
final class AuditTrail
{
    public const LOG_MESSAGE = 'audit.event';

    public const PERSIST_FAILED_MESSAGE = 'audit.persist_failed';

    /**
     * Verbos que já são o nome do evento do model: o verbo da ação em curso
     * só renomeia um `updated` quando é um verbo de negócio (blocked,
     * revoked...). Criar um usuário que em seguida recebe a foto gera
     * `user.created` + `user.updated`, não dois `user.created`.
     *
     * @var list<string>
     */
    private const CRUD_VERBS = ['created', 'updated', 'deleted'];

    private ?AuditScope $scope = null;

    public function __construct(
        private readonly AuditChanges $changes,
        private readonly Redactor $redactor,
    ) {}

    public function current(): ?AuditScope
    {
        return $this->scope;
    }

    /**
     * Abre um escopo e devolve o anterior (para restore()).
     */
    public function begin(AuditScope $scope): ?AuditScope
    {
        $previous = $this->scope;
        $this->scope = $scope;

        return $previous;
    }

    public function restore(?AuditScope $previous): void
    {
        $this->scope = $previous;
    }

    /**
     * Executa `$callback` dentro de um escopo.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function within(AuditScope $scope, callable $callback): mixed
    {
        $previous = $this->begin($scope);

        try {
            return $callback();
        } finally {
            $this->restore($previous);
        }
    }

    /**
     * Dá nome à ação em curso (ex.: `two_factor_enabled`), quando o ponto de
     * entrada não tinha como saber (a mesma ação liga e desliga).
     */
    public function describeAs(string $verb): void
    {
        if ($this->scope !== null) {
            $this->scope->verb = $verb;
        }
    }

    /**
     * Ouvinte dos eventos Eloquent (AuditServiceProvider). Sem escopo aberto,
     * não faz nada.
     */
    public function modelEvent(string $event, Model $model): void
    {
        $scope = $this->scope;

        if ($scope === null || $this->ignores($model)) {
            return;
        }

        $changes = match ($event) {
            'created' => $this->changes->forCreated($model),
            'updated' => $this->changes->forUpdated($model),
            'deleted' => $this->changes->forDeleted($model),
            default => null,
        };

        // Um `save()` sem nada sujo não chega a disparar `updated`; um que só
        // mexeu em colunas fora do resumo (updated_at) também não é ação.
        if ($changes === null || ($event === 'updated' && $changes === [])) {
            return;
        }

        $verb = $event === 'updated' && $scope->verb !== null && ! in_array($scope->verb, self::CRUD_VERBS, true)
            ? $scope->verb
            : $event;

        $this->write(
            scope: $scope,
            action: self::subjectType($model).'.'.$verb,
            outcome: AuditOutcome::Success,
            subjectType: self::subjectType($model),
            subjectUuid: self::subjectUuid($model),
            changes: $changes,
            reason: null,
        );
    }

    /**
     * Registro explícito de uma ação que teve efeito.
     *
     * `$tenantUuid`: a CONTA em que a ação aconteceu (o mesmo valor de
     * `request_logs.tenant_uuid`), quando a ação é de uma conta.
     *
     * @param  array<string, array{before?: mixed, after?: mixed}>  $changes
     */
    public function record(
        string $action,
        ?Model $subject = null,
        array $changes = [],
        ?string $subjectType = null,
        ?string $subjectUuid = null,
        ?string $tenantUuid = null,
    ): AuditEvent {
        return $this->write(
            scope: $this->scope ?? AuditScope::ambient(),
            action: $action,
            outcome: AuditOutcome::Success,
            subjectType: $subjectType ?? ($subject !== null ? self::subjectType($subject) : null),
            subjectUuid: $subjectUuid ?? ($subject !== null ? self::subjectUuid($subject) : null),
            changes: $this->changes->sanitize($changes, $subject),
            reason: null,
            tenantUuid: $tenantUuid,
        );
    }

    /**
     * Registro de uma TENTATIVA RECUSADA. `$reason` é a mesma mensagem que o
     * operador viu (passa pelo Redactor antes de gravar).
     */
    public function denied(
        string $action,
        ?Model $subject,
        string $reason,
        ?string $subjectType = null,
        ?string $tenantUuid = null,
        ?string $subjectUuid = null,
    ): AuditEvent {
        return $this->write(
            scope: $this->scope ?? AuditScope::ambient(),
            action: $action,
            outcome: AuditOutcome::Denied,
            subjectType: $subjectType ?? ($subject !== null ? self::subjectType($subject) : null),
            subjectUuid: $subjectUuid ?? ($subject !== null ? self::subjectUuid($subject) : null),
            changes: [],
            reason: $reason,
            tenantUuid: $tenantUuid,
        );
    }

    /**
     * Nome estável do tipo do registro: o model em snake_case (`User` →
     * `user`, `ApiKey` → `api_key`). Não depende do namespace PHP: mover a
     * classe não quebra a consulta de uma linha antiga.
     *
     * @param  Model|class-string<Model>  $model
     */
    public static function subjectType(Model|string $model): string
    {
        return Str::snake(class_basename($model));
    }

    /**
     * Esta classe de model fica FORA da captura automática?
     */
    public function ignores(Model $model): bool
    {
        // A própria trilha nunca se audita (recursão).
        if ($model instanceof AuditEvent) {
            return true;
        }

        foreach ((array) config('audit.ignored_models', []) as $class) {
            if ($model instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private static function subjectUuid(Model $model): ?string
    {
        $uuid = $model->getAttribute('uuid');

        return UuidColumn::isValid($uuid) ? $uuid : null;
    }

    /**
     * @param  array<string, array{before: mixed, after: mixed}>  $changes
     */
    private function write(
        AuditScope $scope,
        string $action,
        AuditOutcome $outcome,
        ?string $subjectType,
        ?string $subjectUuid,
        array $changes,
        ?string $reason,
        ?string $tenantUuid = null,
    ): AuditEvent {
        $attributes = [
            'context' => $scope->context,
            'actor_uuid' => $scope->actorUuid,
            'actor_is_admin' => $scope->actorIsAdmin,
            'action' => Str::limit($action, 120, ''),
            'outcome' => $outcome,
            'subject_type' => $subjectType,
            'subject_uuid' => $subjectUuid,
            'tenant_uuid' => UuidColumn::isValid($tenantUuid) ? $tenantUuid : null,
            'changes' => $changes === [] ? null : $changes,
            'reason' => $reason === null ? null : Str::limit($this->redactor->redactString($reason), 500, ''),
            'correlation_id' => UuidColumn::isValid($scope->correlationId) ? $scope->correlationId : null,
            'ip' => $scope->ip,
            'user_agent' => $scope->userAgent,
        ];

        $line = [
            'action' => $attributes['action'],
            'outcome' => $outcome->value,
            'context' => $scope->context->value,
            'actor_uuid' => $scope->actorUuid,
            'subject_type' => $subjectType,
            'subject_uuid' => $subjectUuid,
            'tenant_uuid' => $attributes['tenant_uuid'],
            'fields' => array_keys($changes),
            'correlation_id' => $attributes['correlation_id'],
        ];

        try {
            $event = AuditEvent::query()->create($attributes);
        } catch (Throwable $exception) {
            Log::channel('request_log')->error(self::PERSIST_FAILED_MESSAGE, [
                ...$line,
                'exception' => $exception::class,
            ]);

            throw $exception;
        }

        DB::afterCommit(function () use ($line, $event): void {
            Log::channel('request_log')->notice(self::LOG_MESSAGE, [...$line, 'audit_uuid' => $event->uuid]);
        });

        return $event;
    }
}
