<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Twstec\Kit\Foundation\Logging\Redactor;
use Twstec\Kit\Foundation\Security\Middleware\SecurityValidation;

/**
 * Evidência de uma tentativa de ataque para a trilha (request_logs), a mesma
 * nos dois modos do filtro: a linha BLOQUEADA do modo `block` e a linha
 * marcada do modo `observe` (e da rota delegada) guardam o payload do mesmo
 * jeito.
 *
 * Regra de lei: payload de ataque é gravado SANITIZADO/escapado e
 * redigido — nunca em forma executável, nunca com dado sensível cru. Quando a
 * tentativa estava num cabeçalho, ele entra na evidência (neutralizado);
 * quando estava no caminho, só a indicação — o caminho real nunca é gravado
 * (pode carregar segredo posicional, ver EndpointSignature).
 */
final class AttackEvidence
{
    public function __construct(
        private readonly PayloadSanitizer $sanitizer,
        private readonly Redactor $redactor,
    ) {}

    /**
     * @return array<array-key, mixed>
     */
    public function payload(Request $request): array
    {
        $data = RequestInputs::extract($request);

        $source = $request->attributes->get(SecurityValidation::ATTACK_SOURCE_ATTRIBUTE);

        if ($source === 'headers') {
            $data['_cabecalhos'] = RequestInputs::envelope($request)['headers'];
        } elseif ($source === 'path') {
            $data['_detectado_em'] = 'caminho';
        }

        /** @var array<array-key, mixed> $sanitized */
        $sanitized = $this->sanitizer->sanitize($data);

        return $this->redactor->redactArray($sanitized);
    }

    /**
     * User-Agent para a linha da tentativa: também é dado do atacante, então
     * sai escapado como o resto da evidência.
     */
    public function userAgent(Request $request): string
    {
        return Str::limit($this->sanitizer->sanitizeString((string) $request->userAgent()), 500, '');
    }
}
