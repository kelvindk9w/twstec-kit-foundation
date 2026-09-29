<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Audit;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Twstec\Kit\Foundation\Audit\Enums\AuditContext;
use Twstec\Kit\Foundation\Logging\CorrelationId;

/**
 * O "de onde" e o "quem" de uma ação auditada — capturado UMA vez, no ponto
 * de entrada (a chamada do Livewire no /admin, o comando de console), e
 * carimbado em toda linha que a ação gerar.
 *
 * - `verb`: o verbo estável da ação em curso (ex.: `blocked`, `revoked`),
 *   quando o ponto de entrada sabe qual é. Sem ele, a linha usa o evento do
 *   model (`created`/`updated`/`deleted`). Ver AuditTrail::modelEvent().
 * - IP e User-Agent seguem a regra da trilha de requisições: IP como o
 *   Laravel o resolve (TrustProxies) e User-Agent cortado em 500 caracteres.
 */
final class AuditScope
{
    /**
     * Mesmo corte do User-Agent em `request_logs` (RequestLogging).
     */
    public const USER_AGENT_MAX = 500;

    public function __construct(
        public readonly AuditContext $context,
        public readonly ?string $actorUuid,
        public readonly ?bool $actorIsAdmin,
        public readonly ?string $correlationId,
        public readonly ?string $ip,
        public readonly ?string $userAgent,
        public ?string $verb = null,
    ) {}

    /**
     * Escopo de uma requisição HTTP (o /admin hoje; o painel e a API quando
     * passarem a gravar). O ator é quem está autenticado AGORA.
     */
    public static function fromRequest(AuditContext $context, ?string $verb = null, ?Request $request = null): self
    {
        $request ??= request();
        $actor = auth()->user();

        // O ator conta quando é o usuário da plataforma — o model configurado
        // na autenticação (a trilha não importa o módulo de autenticação).
        $userModel = (string) config('auth.providers.users.model');
        $isUser = $actor !== null && $userModel !== '' && $actor instanceof $userModel;

        return new self(
            context: $context,
            actorUuid: $isUser ? $actor->uuid : null,
            actorIsAdmin: $isUser ? (bool) $actor->is_admin : null,
            correlationId: CorrelationId::resolve($request),
            ip: $request->ip(),
            userAgent: Str::limit((string) $request->userAgent(), self::USER_AGENT_MAX, '') ?: null,
            verb: $verb,
        );
    }

    /**
     * Escopo de um comando de console. Não há usuário da aplicação nem
     * requisição: o "cliente" que agiu é o comando, registrado no lugar do
     * User-Agent junto do usuário do sistema operacional que o rodou.
     */
    public static function console(string $command, ?string $verb = null): self
    {
        return new self(
            context: AuditContext::Console,
            actorUuid: null,
            actorIsAdmin: null,
            correlationId: null,
            ip: null,
            userAgent: Str::limit('console: '.$command.' (os-user: '.self::osUser().')', self::USER_AGENT_MAX, ''),
            verb: $verb,
        );
    }

    /**
     * Escopo quando ninguém abriu um: comando/tinker no console, ou uma
     * requisição fora dos pontos de entrada auditados.
     */
    public static function ambient(): self
    {
        return app()->runningInConsole()
            ? self::console('artisan')
            : self::fromRequest(AuditContext::Panel);
    }

    private static function osUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());

            if (is_array($info) && isset($info['name'])) {
                return (string) $info['name'];
            }
        }

        return get_current_user() ?: 'unknown';
    }
}
