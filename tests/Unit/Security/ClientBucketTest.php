<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Twstec\Kit\Foundation\Security\ClientBucket;

// Balde de cliente do limite da borda e da amostragem da trilha: IPv4 exato,
// IPv6 pelo prefixo (um host recebe um /64 inteiro e trocaria de endereço a
// cada requisição para ganhar orçamento novo).

function bucketFor(string $ip): string
{
    return ClientBucket::for(Request::create('/', 'GET', server: ['REMOTE_ADDR' => $ip]));
}

it('IPv4 é o próprio endereço', function () {
    expect(bucketFor('203.0.113.9'))->toBe('203.0.113.9');
});

it('IPv6 agrupa pelo /64 por padrão', function () {
    expect(bucketFor('2001:db8:1:2::1'))->toBe('2001:db8:1:2::/64')
        ->and(bucketFor('2001:db8:1:2:ffff:ffff:ffff:ffff'))->toBe('2001:db8:1:2::/64')
        ->and(bucketFor('2001:db8:1:3::1'))->toBe('2001:db8:1:3::/64');
});

it('o prefixo IPv6 é configurável, inclusive fora de fronteira de byte', function () {
    config()->set('security.rate_limit.ipv6_prefix', 56);

    expect(bucketFor('2001:db8:1:2ff::1'))->toBe('2001:db8:1:200::/56');

    config()->set('security.rate_limit.ipv6_prefix', 60);

    expect(bucketFor('2001:db8:1:2ff::1'))->toBe('2001:db8:1:2f0::/60');
});

it('endereço ausente ou inválido cai num balde fixo, sem escapar do limite', function () {
    expect(bucketFor('isto-nao-e-ip'))->toBe('unknown');
});
