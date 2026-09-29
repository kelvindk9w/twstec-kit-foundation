<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Security;

use Illuminate\Http\Request;

/**
 * "Quem é este cliente" para fins de LIMITE (rate limit da borda e amostragem
 * do tráfego de varredura na trilha de auditoria).
 *
 * IPv4: o endereço exato. É o que o `ip()` devolve depois do TrustProxies —
 * por isso o limite só é justo quando TRUSTED_PROXIES está certo (sem ele,
 * atrás de proxy, o mundo inteiro cai num balde só; ver
 * Twstec\Kit\Foundation\Http\TrustedProxies).
 *
 * IPv6: o PREFIXO, não o endereço. Um único host doméstico recebe tipicamente
 * um /64 inteiro — 2^64 endereços que ele pode trocar a cada requisição sem
 * custo nenhum. Contar por endereço exato daria a esse cliente um orçamento
 * novo por requisição, ou seja, nenhum limite. O prefixo é configurável
 * (`security.rate_limit.ipv6_prefix`, padrão 64) porque há provedor que
 * entrega /56 ou /48.
 *
 * Endereço ausente ou ilegível vira um balde fixo: não pode escapar do
 * limite por não ter identidade.
 */
final class ClientBucket
{
    public static function for(Request $request): string
    {
        $ip = (string) $request->ip();

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : 'unknown';
        }

        $prefix = max(1, min(128, (int) config('security.rate_limit.ipv6_prefix', 64)));

        $packed = inet_pton($ip);

        if ($packed === false) {
            return 'unknown';
        }

        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        $masked = substr($packed, 0, $fullBytes);

        if ($remainingBits > 0) {
            $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
            $masked .= chr(ord($packed[$fullBytes]) & $mask);
        }

        $masked = str_pad($masked, 16, "\0");

        return inet_ntop($masked).'/'.$prefix;
    }
}
