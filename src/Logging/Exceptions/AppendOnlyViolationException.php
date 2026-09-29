<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Logging\Exceptions;

use RuntimeException;

/**
 * Violação da imutabilidade de uma trilha append-only.
 *
 * Lançada quando código da aplicação tenta UPDATE ou DELETE arbitrário:
 * - em request_logs via Eloquent — as únicas mutações permitidas são as
 *   transições controladas de ciclo de vida (RequestLog::markFinished()
 *   e RequestLog::bindTenant());
 * - em audit_events, por model OU por query em massa — nenhuma alteração
 *   é permitida, e a única remoção é a poda por idade do comando
 *   `audit:prune` (AuditEvent::pruneOlderThan()).
 */
final class AppendOnlyViolationException extends RuntimeException
{
    public static function updateAttempted(): self
    {
        return new self(
            'request_logs é append-only: UPDATE arbitrário é proibido. '
            .'Use RequestLog::markFinished() ou RequestLog::bindTenant() para as transições de ciclo de vida.',
        );
    }

    public static function deleteAttempted(): self
    {
        return new self('request_logs é append-only: DELETE é proibido (trilha de auditoria imutável).');
    }

    public static function auditUpdateAttempted(): self
    {
        return new self('audit_events é append-only: UPDATE é proibido (trilha de auditoria imutável).');
    }

    public static function auditDeleteAttempted(): self
    {
        return new self(
            'audit_events é append-only: DELETE é proibido. '
            .'A única remoção permitida é a poda por idade do comando audit:prune (AuditEvent::pruneOlderThan()).',
        );
    }
}
