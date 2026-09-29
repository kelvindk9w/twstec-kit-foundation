<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Logging\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Twstec\Kit\Foundation\Identifiers\RoutesByUuid;
use Twstec\Kit\Foundation\Logging\Enums\RequestLogStatus;
use Twstec\Kit\Foundation\Logging\Exceptions\AppendOnlyViolationException;

/**
 * Log de requisição — trilha de auditoria/segurança, append-only.
 *
 * Finalidades: segurança/auditoria, validação de transação e detecção de bugs.
 *
 * Regras de imutabilidade (append-only):
 * - NUNCA UPDATE/DELETE arbitrário pela aplicação: update()/delete() via
 *   Eloquent lançam AppendOnlyViolationException.
 * - As ÚNICAS mutações permitidas são as transições de ciclo de vida:
 *   markFinished() (INICIADA → CONCLUIDA/ERRO) e bindTenant() (vincula o
 *   tenant quando o ResolveTenant o identifica). UPDATE/DELETE direto no banco fica
 *   restrito a operações de DBA (revogar permissões da role da app em
 *   produção — ver docs/logs-lgpd.md).
 * - Sem updated_at: a linha tem apenas created_at.
 *
 * tenant_uuid é nullable por desenho: o log é gravado ANTES da
 * identificação do cliente. Log que permanece sem tenant = possível
 * ataque/tentativa de burla.
 */
class RequestLog extends Model
{
    use HasUuids, RoutesByUuid;

    /**
     * Append-only: sem coluna updated_at.
     */
    public const UPDATED_AT = null;

    protected $table = 'request_logs';

    /**
     * Atributos graváveis APENAS na criação (INSERT). Mutabilidade posterior
     * exclusivamente via markFinished()/bindTenant().
     *
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'correlation_id',
        'client_correlation_id',
        'tenant_uuid',
        'ip',
        'user_agent',
        'method',
        'endpoint',
        'payload',
        'status',
        'attack_type',
        'http_status_response',
        'error_message',
        'duration_ms',
    ];

    /**
     * Trava interna: só permite UPDATE durante uma transição de ciclo de
     * vida controlada (markFinished/bindTenant).
     */
    private bool $allowLifecycleUpdate = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => RequestLogStatus::class,
            'http_status_response' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Coluna preenchida automaticamente com UUID v7 na criação (HasUuids). É
     * também o que faz a rota do /admin responder 404 — e não erro de banco —
     * para um uuid malformado na URL (ver RoutesByUuid).
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected static function booted(): void
    {
        static::updating(function (RequestLog $log): void {
            if (! $log->allowLifecycleUpdate) {
                throw AppendOnlyViolationException::updateAttempted();
            }
        });

        static::deleting(function (): void {
            throw AppendOnlyViolationException::deleteAttempted();
        });
    }

    /**
     * Transição controlada de fim de ciclo: INICIADA → CONCLUIDA/ERRO.
     * Chamada pelo middleware de request logging no terminate.
     */
    public function markFinished(
        RequestLogStatus $status,
        ?int $httpStatusResponse = null,
        ?string $errorMessage = null,
        ?int $durationMs = null,
    ): void {
        if ($status === RequestLogStatus::Iniciada || $status === RequestLogStatus::Bloqueada) {
            throw new InvalidArgumentException('markFinished() só aceita CONCLUIDA ou ERRO.');
        }

        $this->status = $status;
        $this->http_status_response = $httpStatusResponse;
        $this->error_message = $errorMessage;
        $this->duration_ms = $durationMs;

        $this->allowLifecycleUpdate = true;
        $this->save();
    }

    /**
     * Vincula o tenant ao log quando ele é resolvido DEPOIS da gravação
     * imediata (quem chama é o ResolveTenant, depois de autenticar a chave).
     */
    public function bindTenant(string $tenantUuid): void
    {
        $this->tenant_uuid = $tenantUuid;

        $this->allowLifecycleUpdate = true;
        $this->save();
    }

    /**
     * Vincula o tenant localizando o log pelo correlation_id — caminho
     * típico: middleware de autenticação resolve o tenant e chama este
     * método com o correlation_id da requisição corrente.
     *
     * @return bool false quando não existe log com o correlation_id.
     */
    public static function bindTenantByCorrelationId(string $correlationId, string $tenantUuid): bool
    {
        $log = static::query()->where('correlation_id', $correlationId)->first();

        if (! $log instanceof self) {
            return false;
        }

        $log->bindTenant($tenantUuid);

        return true;
    }
}
