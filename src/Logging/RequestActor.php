<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Logging;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;
use Twstec\Kit\Foundation\Logging\Enums\RequestActorType;
use Twstec\Kit\Foundation\Logging\Models\RequestLog;
use Twstec\Kit\Foundation\Security\SignedBodyPaths;

/**
 * O ator de uma requisição, resolvido no FIM do ciclo (depois da
 * autenticação): tipo, uuid da pessoa, uuid da chave de API, referência da
 * sessão e nome da rota. Nunca o `id` interno nem o id da sessão.
 *
 * A chave de API chega pelo atributo RequestLog::API_KEY_ATTRIBUTE, que o
 * middleware de autenticação por chave grava (o kit-foundation não conhece
 * o pacote de contas).
 */
final readonly class RequestActor
{
    public function __construct(
        public RequestActorType $type,
        public ?string $actorUuid,
        public ?string $apiKeyUuid,
        public ?string $sessionRef,
        public ?string $routeName,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $apiKeyUuid = self::uuidOrNull($request->attributes->get(RequestLog::API_KEY_ATTRIBUTE));
        $actorUuid = self::userUuid($request);

        $type = match (true) {
            $apiKeyUuid !== null => RequestActorType::ApiKey,
            $actorUuid !== null => RequestActorType::User,
            SignedBodyPaths::matches($request) => RequestActorType::System,
            default => RequestActorType::Anonymous,
        };

        $routeName = $request->route()?->getName();

        return new self(
            type: $type,
            actorUuid: $actorUuid,
            apiKeyUuid: $apiKeyUuid,
            sessionRef: self::sessionRef($request),
            routeName: is_string($routeName) && $routeName !== '' ? Str::limit($routeName, 255, '') : null,
        );
    }

    /**
     * O uuid da pessoa autenticada (atributo `uuid` do modelo), ou null.
     */
    private static function userUuid(Request $request): ?string
    {
        try {
            $user = $request->user();
        } catch (Throwable) {
            return null;
        }

        if (! $user instanceof Authenticatable) {
            return null;
        }

        return self::uuidOrNull(method_exists($user, 'getAttribute') ? $user->getAttribute('uuid') : null);
    }

    /**
     * HMAC curto do id da sessão: liga as linhas da mesma sessão sem guardar
     * o id (que, vazado, abriria a sessão).
     */
    private static function sessionRef(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        try {
            // Não depende de isStarted(): no terminate a sessão já foi salva
            // (o save() a marca como não iniciada), e o id é o da resposta.
            $id = (string) $request->session()->getId();
        } catch (Throwable) {
            return null;
        }

        $key = (string) config('app.key', '');

        return $id === '' || $key === '' ? null : substr(hash_hmac('sha256', $id, $key), 0, 32);
    }

    private static function uuidOrNull(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? strtolower($value) : null;
    }
}
