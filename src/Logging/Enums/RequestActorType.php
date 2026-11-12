<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Logging\Enums;

/**
 * Quem fez a requisição, na trilha (`request_logs.actor_type`).
 *
 * - User: pessoa autenticada pela sessão (painel, /admin).
 * - ApiKey: chamada autenticada por chave de API (actor_uuid = a pessoa por
 *   trás da chave; api_key_uuid = a chave).
 * - System: outro sistema numa rota de corpo assinado (webhook recebido) —
 *   a credencial é a assinatura; o status HTTP diz se ela passou.
 * - Anonymous: ninguém autenticado.
 */
enum RequestActorType: string
{
    case User = 'user';
    case ApiKey = 'api_key';
    case System = 'system';
    case Anonymous = 'anonymous';
}
