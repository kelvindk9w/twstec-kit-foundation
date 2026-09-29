<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Security;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Twstec\Kit\Foundation\Security\Contracts\RateLimitSubjectResolver;

/**
 * A REGRA do limite de requisições da API, em um lugar só.
 *
 * PROBLEMA: o `throttle:api` rodava ANTES do `resolve.tenant`. Nesse ponto
 * ninguém sabe ainda quem é o cliente, então o limite que se chamava "por
 * usuário" contava, na prática, sempre por IP. Dois efeitos ruins de uma vez:
 *
 *   INTEGRAÇÕES ATRÁS DO MESMO IP DIVIDIAM O ORÇAMENTO — dois clientes da
 *   API saindo pelo mesmo NAT (ou dois serviços do mesmo servidor) se
 *   derrubavam mutuamente, sem que nenhum dos dois tivesse passado do seu
 *   próprio limite.
 *
 *   O LIMITE NÃO ACOMPANHAVA A CREDENCIAL — uma integração que trocasse de
 *   saída de rede (vários workers, várias regiões) ganhava orçamento novo a
 *   cada IP, com a mesma chave.
 *
 * SOLUÇÃO EM DOIS BALDES, porque há duas perguntas diferentes:
 *
 *   1. REQUISIÇÃO AUTENTICADA → conta pela CHAVE DE API (ou pelo tenant,
 *      com RATE_LIMIT_API_BY=tenant). É o limiter nomeado `api`, aplicado
 *      pelo `throttle:api` do grupo da API — que agora roda DEPOIS do
 *      `resolve.tenant` (a ordem é garantida pela lista de prioridade de
 *      middleware — quem instala o `resolve.tenant` o põe antes do
 *      ThrottleRequests —, não pela posição na rota). Rota da
 *      API sem autenticação (`/api/health`) continua contando por IP.
 *
 *   2. FALHA DE AUTENTICAÇÃO → dois baldes, consultados pelo próprio
 *      ResolveTenant ANTES de verificar a credencial (o `throttle:api` por
 *      chave roda DEPOIS dele e nunca vê o 401):
 *
 *      a) por CLIENTE + CREDENCIAL (IP, IPv6 por prefixo, + impressão da
 *         chave pública apresentada) — RATE_LIMIT_API_AUTH_FAILURES, 20/min.
 *         É o throttle de login clássico (conta + IP): quem erra a secreta de
 *         UMA chave pública é barrado naquela chave, naquele IP, até a janela
 *         passar — inclusive se acertar depois (sem isso, intercalar a secreta
 *         certa zeraria o balde). As OUTRAS chaves que saem do mesmo IP não
 *         são afetadas.
 *
 *      b) TETO POR CLIENTE (IP), bem mais alto —
 *         RATE_LIMIT_API_AUTH_FAILURES_PER_IP, 100/min. Sem ele, trocar a
 *         chave pública a cada tentativa daria um balde (a) novo por
 *         requisição. Passado o teto, o IP só autentica com chave que JÁ
 *         autenticou com sucesso a partir dele recentemente
 *         (RATE_LIMIT_API_AUTH_KNOWN_CLIENT_TTL_SECONDS, 7 dias); as demais
 *         recebem 429 sem verificação nenhuma.
 *
 * POR QUE DOIS BALDES (a troca, documentada): a versão anterior tinha um
 * balde só, por IP, e o 429 valia para todas as chaves daquele IP. Num NAT
 * de empresa, escola ou CGNAT de operadora, qualquer um atrás do mesmo
 * endereço — com 20 chaves inválidas por minuto — derrubava as integrações
 * legítimas dos vizinhos. Agora o erro de terceiros não bloqueia a chave
 * válida de ninguém: o balde (a) é por credencial, e o teto (b) deixa passar
 * as chaves já conhecidas daquele IP. O que sobra, e é aceito:
 *
 *   - uma integração NOVA (que nunca autenticou daquele IP) atrás de um NAT
 *     sob ataque espera a janela do teto passar;
 *   - quem conhece a chave pública de outro cliente e sai do MESMO IP que ele
 *     pode esgotar o balde (a) daquela chave naquele IP — é o limite
 *     inerente a todo throttle de login por conta + IP. A chave pública é
 *     identificador, não segredo; a secreta tem ~285 bits e não se adivinha.
 *
 * O teto da borda (EdgeRateLimit, por IP) continua valendo por fora de
 * tudo isto: é ele que segura o volume bruto que chega ao PHP.
 *
 * Todos os contadores e marcas vivem no cache com TTL (a janela de cada
 * balde, ou o TTL da marca de cliente conhecido): nada cresce sem limite,
 * mesmo com chaves públicas inventadas a cada requisição.
 *
 * Toda resposta 429 da API sai no envelope de erro padrão com Retry-After
 * (ApiErrorRenderer). Valores em config/security.php (`rate_limit.api*`).
 */
final class ApiRateLimit
{
    /**
     * Prefixos das chaves no cache: falhas por cliente + credencial, falhas
     * por cliente (teto) e marca de credencial já autenticada pelo cliente.
     */
    private const CREDENTIAL_FAILURE_PREFIX = 'api-auth-failures:credential:';

    private const CLIENT_FAILURE_PREFIX = 'api-auth-failures:client:';

    private const KNOWN_CLIENT_PREFIX = 'api-auth-known:';

    /**
     * Header da chave pública — o mesmo que o ResolveTenant lê.
     */
    private const PUBLIC_KEY_HEADER = 'X-Api-Key';

    /**
     * Limite do limiter nomeado `api` para esta requisição.
     */
    public static function limit(Request $request): Limit
    {
        return Limit::perMinute(max(1, (int) config('security.rate_limit.api', 60)))
            ->by(self::subject($request));
    }

    /**
     * QUEM está sendo contado: a chave (ou o tenant) quando a requisição foi
     * autenticada pelo `resolve.tenant`; o IP quando não foi.
     *
     * Quem sabe se a requisição foi autenticada, e por quem, é o módulo que
     * autentica — ele se apresenta registrando um RateLimitSubjectResolver no
     * container. Sem nenhum registrado (ou sem sujeito nesta requisição),
     * conta pelo IP.
     *
     * Os prefixos separam os espaços de nome — um IP nunca colide com o uuid
     * de uma chave.
     */
    public static function subject(Request $request): string
    {
        $subject = app()->bound(RateLimitSubjectResolver::class)
            ? app(RateLimitSubjectResolver::class)->resolve($request)
            : null;

        if (is_string($subject) && $subject !== '') {
            return $subject;
        }

        return 'ip:'.ClientBucket::for($request);
    }

    /**
     * Recusa com 429 quando esta requisição não pode nem tentar autenticar.
     * Chamado pelo ResolveTenant ANTES de ler a credencial no banco.
     *
     *   1. esta credencial já errou vezes demais a partir deste cliente; ou
     *   2. este cliente passou do teto de falhas e a credencial não é uma
     *      que já autenticou com sucesso a partir dele.
     *
     * @throws ThrottleRequestsException
     */
    public static function ensureAuthenticationAllowed(Request $request): void
    {
        $credentialKey = self::credentialFailureKey($request);

        if (RateLimiter::tooManyAttempts($credentialKey, self::maxCredentialFailures())) {
            self::throttle($credentialKey, self::maxCredentialFailures());
        }

        $clientKey = self::clientFailureKey($request);

        if (RateLimiter::tooManyAttempts($clientKey, self::maxClientFailures())
            && ! self::store()->has(self::knownClientKey($request))) {
            self::throttle($clientKey, self::maxClientFailures());
        }
    }

    /**
     * Conta uma falha de autenticação nos dois baldes. Na PRIMEIRA vez que um
     * deles atinge o limite na janela, registra um evento no log — o sinal de
     * adivinhação de chave que a operação precisa ver, sem uma linha por
     * tentativa. O log leva a impressão da chave pública, nunca o valor.
     */
    public static function recordAuthenticationFailure(Request $request): void
    {
        $decay = self::authFailureDecaySeconds();

        $credentialHits = RateLimiter::hit(self::credentialFailureKey($request), $decay);
        $clientHits = RateLimiter::hit(self::clientFailureKey($request), $decay);

        foreach ([
            'credential' => [$credentialHits, self::maxCredentialFailures()],
            'client' => [$clientHits, self::maxClientFailures()],
        ] as $bucket => [$hits, $max]) {
            if ($hits === $max) {
                Log::channel('request_log')->warning('api.auth_failures.throttled', [
                    'bucket' => $bucket,
                    'ip' => $request->ip(),
                    'credential' => self::credentialFingerprint($request),
                    'limit' => $max,
                    'decay_seconds' => $decay,
                ]);
            }
        }
    }

    /**
     * Marca a credencial como conhecida deste cliente depois de autenticar
     * com sucesso: acima do teto de falhas do IP, ela continua passando.
     * `add` não renova o TTL — uma escrita por janela, não por requisição.
     */
    public static function recordAuthenticationSuccess(Request $request): void
    {
        self::store()->add(self::knownClientKey($request), 1, self::knownClientTtlSeconds());
    }

    /**
     * O limite autenticado conta por tenant (em vez de por chave)?
     */
    public static function countsByTenant(): bool
    {
        return strtolower(trim((string) config('security.rate_limit.api_by', 'key'))) === 'tenant';
    }

    /**
     * O MESMO store do RateLimiter (`cache.limiter`, ou o padrão): a marca de
     * cliente conhecido mora junto dos contadores que ela complementa.
     */
    private static function store(): Repository
    {
        return Cache::store(config('cache.limiter'));
    }

    /**
     * @throws ThrottleRequestsException
     */
    private static function throttle(string $key, int $max): never
    {
        throw new ThrottleRequestsException(
            __('api.errors.too_many_requests'),
            null,
            [
                'Retry-After' => max(1, RateLimiter::availableIn($key)),
                'X-RateLimit-Limit' => $max,
                'X-RateLimit-Remaining' => 0,
            ],
        );
    }

    /**
     * Impressão curta da chave pública apresentada (ou `none`): separa os
     * baldes por credencial sem guardar no cache — nem no log — o valor
     * que o cliente mandou, de tamanho e conteúdo arbitrários.
     */
    private static function credentialFingerprint(Request $request): string
    {
        $publicKey = $request->header(self::PUBLIC_KEY_HEADER);

        if (! is_string($publicKey) || $publicKey === '') {
            return 'none';
        }

        return substr(hash('sha256', $publicKey), 0, 32);
    }

    private static function credentialFailureKey(Request $request): string
    {
        return self::CREDENTIAL_FAILURE_PREFIX.ClientBucket::for($request).'|'.self::credentialFingerprint($request);
    }

    private static function clientFailureKey(Request $request): string
    {
        return self::CLIENT_FAILURE_PREFIX.ClientBucket::for($request);
    }

    private static function knownClientKey(Request $request): string
    {
        return self::KNOWN_CLIENT_PREFIX.ClientBucket::for($request).'|'.self::credentialFingerprint($request);
    }

    private static function maxCredentialFailures(): int
    {
        return max(1, (int) config('security.rate_limit.api_auth_failures', 20));
    }

    private static function maxClientFailures(): int
    {
        return max(1, (int) config('security.rate_limit.api_auth_failures_per_ip', 100));
    }

    private static function knownClientTtlSeconds(): int
    {
        return max(1, (int) config('security.rate_limit.api_auth_known_client_ttl_seconds', 604800));
    }

    private static function authFailureDecaySeconds(): int
    {
        return max(1, (int) config('security.rate_limit.api_auth_failures_decay_seconds', 60));
    }
}
