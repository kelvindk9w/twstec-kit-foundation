<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

// Limitador `sensitive` (login, códigos, recuperação de senha): visitante
// conta pelo balde do cliente (ClientBucket), não pelo endereço exato — um
// host IPv6 recebe um /64 inteiro e ganharia orçamento novo a cada
// requisição trocando de endereço.

function sensitiveLimitFor(Request $request): Limit
{
    $limiter = RateLimiter::limiter('sensitive');

    expect($limiter)->not->toBeNull();

    $limit = $limiter($request);

    expect($limit)->toBeInstanceOf(Limit::class);

    /** @var Limit $limit */
    return $limit;
}

function sensitiveGuestRequest(string $ip): Request
{
    return Request::create('/login', 'POST', server: ['REMOTE_ADDR' => $ip]);
}

it('visitante IPv6 conta pelo prefixo: trocar de endereço no mesmo /64 não dá orçamento novo', function () {
    $first = sensitiveLimitFor(sensitiveGuestRequest('2001:db8:1:2::1'));
    $rotated = sensitiveLimitFor(sensitiveGuestRequest('2001:db8:1:2:dead:beef:0:7'));
    $otherHost = sensitiveLimitFor(sensitiveGuestRequest('2001:db8:1:3::1'));

    expect($first->key)->toBe('2001:db8:1:2::/64')
        ->and($rotated->key)->toBe($first->key)
        ->and($otherHost->key)->not->toBe($first->key)
        ->and($first->maxAttempts)->toBe((int) config('security.rate_limit.sensitive', 5));
});

it('visitante IPv4 conta pelo endereço exato', function () {
    expect(sensitiveLimitFor(sensitiveGuestRequest('203.0.113.9'))->key)->toBe('203.0.113.9');
});

it('autenticado conta pelo usuário, qualquer que seja o endereço', function () {
    $user = new User;
    $user->forceFill(['id' => 42]);

    $request = sensitiveGuestRequest('2001:db8:1:2::1');
    $request->setUserResolver(fn () => $user);

    expect(sensitiveLimitFor($request)->key)->toBe('42');
});
