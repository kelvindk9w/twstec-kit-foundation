<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Logging;

/**
 * Redaction de dados sensíveis antes de persistir logs (LGPD: log nunca guarda dado sensível).
 *
 * Regras:
 * - Chaves sensíveis (senhas, tokens, segredos, chaves de API, dados de
 *   cartão) são substituídas por '[REDACTED]' — casamento exato do nome
 *   da chave (case-insensitive) ou sufixo _token/_secret/_password/_api_key.
 * - CPF/CNPJ são mascarados parcialmente em QUALQUER valor string
 *   (mantém os 3 primeiros e 2 últimos dígitos).
 * - E-mails são mascarados parcialmente (primeira letra + domínio).
 * - Números de cartão (PAN) são mascarados em QUALQUER valor string,
 *   mantendo só os 4 últimos dígitos (PCI DSS): sequência de 13 a 19
 *   dígitos, com ou sem espaço/hífen entre eles, que passa no Luhn.
 * - Strings são truncadas (logs não são storage de payload gigante).
 *
 * A redaction é recursiva e cobre também as CHAVES do array (uma chave
 * maliciosa não vaza dado sensível como nome de campo): o nome é testado
 * contra a lista de segredos e o texto da própria chave passa pelas mesmas
 * máscaras de CPF/CNPJ/e-mail/cartão dos valores.
 */
final class Redactor
{
    /**
     * Valor persistido no lugar de qualquer segredo.
     */
    public const MASK = '[REDACTED]';

    /**
     * Tamanho máximo de cada string persistida no log.
     */
    private const MAX_STRING_LENGTH = 1000;

    /**
     * Chaves (minúsculas) cujo valor é sempre mascarado.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        'password', 'password_confirmation', 'current_password', 'transaction_password',
        'senha', 'senha_confirmacao', 'senha_atual', 'senha_transacao',
        'token', 'access_token', 'refresh_token', 'id_token', 'api_key', 'api_secret',
        'secret', 'client_secret', 'authorization', 'private_key', 'webhook_secret',
        'card_number', 'card_cvv', 'cvv', 'cvc', 'card_expiry',
        // Códigos de verificação (2FA) também são credenciais temporárias.
        'code', 'verification_code', 'codigo', 'codigo_verificacao',
    ];

    /**
     * Sufixos de chave que denotam segredo (ex.: webhook_token, hmac_secret).
     *
     * @var list<string>
     */
    private const SENSITIVE_SUFFIXES = ['_token', '_secret', '_password', '_api_key'];

    /**
     * Faixa de comprimento de um PAN (ISO/IEC 7812): 13 a 19 dígitos.
     */
    private const PAN_MIN_DIGITS = 13;

    private const PAN_MAX_DIGITS = 19;

    /**
     * Dígitos finais que podem permanecer visíveis (PCI DSS 3.4.1: no
     * máximo os 6 primeiros e os 4 últimos; aqui, só os 4 últimos).
     */
    private const PAN_VISIBLE_DIGITS = 4;

    /**
     * Redige recursivamente um array de dados (payload de requisição).
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function redactArray(array $data): array
    {
        $redacted = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $redacted[$key] = self::MASK;

                continue;
            }

            $key = $this->redactKey($key);

            $redacted[$key] = match (true) {
                is_array($value) => $this->redactArray($value),
                is_string($value) => $this->truncate($this->redactString($value)),
                is_scalar($value) || $value === null => $value,
                default => '['.get_debug_type($value).']',
            };
        }

        return $redacted;
    }

    /**
     * Mascara CPF, CNPJ, e-mails e números de cartão dentro de uma string livre.
     *
     * Mantém os 3 primeiros e os 2 últimos dígitos do documento; o resto
     * vira '*', preservando a pontuação original. Exemplos:
     * CPF:  123.456.789-09    → 123.xxx.xxx-09 (x = dígito mascarado)
     * CNPJ: 12.345.678/0001-90 → 12.3xx.xxx/xxxx-90
     * E-mail: kelvin@exemplo.com → kXXX@exemplo.com (X = caracteres mascarados)
     * Cartão: 4111 1111 1111 1111 → **** **** **** 1111 (ver maskCardNumbers)
     */
    public function redactString(string $value): string
    {
        // Cartão por último: CPF/CNPJ já mascarados não voltam a casar (os
        // dígitos viraram '*'), e um CNPJ que por acaso passe no Luhn mantém
        // a máscara própria de documento.
        // CNPJ antes do CPF (14 dígitos conteriam um CPF no meio).
        $value = (string) preg_replace_callback(
            '/\b\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}\b/',
            fn (array $m): string => $this->maskDigits($m[0]),
            $value,
        );

        $value = (string) preg_replace_callback(
            '/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/',
            fn (array $m): string => $this->maskDigits($m[0]),
            $value,
        );

        $value = (string) preg_replace(
            '/\b([A-Za-z0-9._%+-])[A-Za-z0-9._%+-]*(@[A-Za-z0-9.-]+\.[A-Za-z]{2,})\b/',
            '$1***$2',
            $value,
        );

        return $this->maskCardNumbers($value);
    }

    /**
     * Mascara números de cartão (PAN) numa string livre, mantendo só os 4
     * últimos dígitos e a pontuação original:
     * 4111 1111 1111 1111 → **** **** **** 1111
     * 5555-5555-5555-4444 → ****-****-****-4444
     *
     * Candidato = corrida de dígitos separados por no máximo UM espaço ou
     * hífen. Dentro da corrida, qualquer janela de grupos consecutivos com
     * 13 a 19 dígitos que passe no Luhn é mascarada — assim o cartão é pego
     * mesmo quando o usuário emenda outro número depois dele
     * ("4111 1111 1111 1111 123"). O Luhn é o que separa cartão de número de
     * pedido, protocolo ou telefone; ele não é infalível (cerca de 1 em cada
     * 10 sequências arbitrárias passa), e na dúvida mascarar é o lado seguro.
     */
    public function maskCardNumbers(string $value): string
    {
        return (string) preg_replace_callback(
            '/(?<!\d)\d(?:[ -]?\d){'.(self::PAN_MIN_DIGITS - 1).',}(?!\d)/',
            fn (array $m): string => $this->maskCardRun($m[0]),
            $value,
        );
    }

    /**
     * Processa uma corrida de dígitos/separadores: localiza as janelas de
     * grupos que formam um PAN válido e mascara cada uma.
     */
    private function maskCardRun(string $run): string
    {
        // Grupos de dígitos e os separadores entre eles, na ordem original.
        $parts = preg_split('/([ -])/', $run, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$run];
        $groups = array_values(array_filter($parts, fn (string $p): bool => $p !== ' ' && $p !== '-'));
        $separators = array_values(array_filter($parts, fn (string $p): bool => $p === ' ' || $p === '-'));
        $count = count($groups);

        $start = 0;

        while ($start < $count) {
            $end = $this->longestCardWindow($groups, $start);

            if ($end === null) {
                $start++;

                continue;
            }

            $groups = $this->maskWindow($groups, $start, $end);
            $start = $end + 1;
        }

        $masked = '';

        foreach ($groups as $index => $group) {
            $masked .= $group.($separators[$index] ?? '');
        }

        return $masked;
    }

    /**
     * Maior janela de grupos, a partir de $start, cujos dígitos formam um
     * PAN (13–19 dígitos + Luhn). Null quando não há nenhuma.
     *
     * @param  list<string>  $groups
     */
    private function longestCardWindow(array $groups, int $start): ?int
    {
        $digits = '';
        $found = null;

        for ($end = $start, $count = count($groups); $end < $count; $end++) {
            $digits .= $groups[$end];
            $length = strlen($digits);

            if ($length > self::PAN_MAX_DIGITS) {
                break;
            }

            if ($length >= self::PAN_MIN_DIGITS && $this->passesLuhn($digits)) {
                $found = $end;
            }
        }

        return $found;
    }

    /**
     * Troca por '*' todos os dígitos da janela, menos os 4 últimos.
     *
     * @param  list<string>  $groups
     * @return list<string>
     */
    private function maskWindow(array $groups, int $start, int $end): array
    {
        $total = strlen(implode('', array_slice($groups, $start, $end - $start + 1)));
        $toMask = $total - self::PAN_VISIBLE_DIGITS;

        for ($i = $start; $i <= $end && $toMask > 0; $i++) {
            $take = min($toMask, strlen($groups[$i]));
            $groups[$i] = str_repeat('*', $take).substr($groups[$i], $take);
            $toMask -= $take;
        }

        return $groups;
    }

    /**
     * Algoritmo de Luhn (mod 10) — o dígito verificador de todo PAN.
     */
    private function passesLuhn(string $digits): bool
    {
        $sum = 0;
        $double = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];

            if ($double) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }

    /**
     * Aplica as máscaras de texto livre ao NOME do campo. Chaves inteiras
     * também passam: o PHP converte a chave "4111111111111111" em int, e
     * esse int não pode sair em claro só por ter mudado de tipo.
     */
    private function redactKey(int|string $key): int|string
    {
        $redacted = $this->redactString((string) $key);

        return $redacted === (string) $key ? $key : $redacted;
    }

    /**
     * A chave (nome do campo) denota um segredo?
     */
    public function isSensitiveKey(string $key): bool
    {
        $key = mb_strtolower($key);

        if (in_array($key, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        foreach (self::SENSITIVE_SUFFIXES as $suffix) {
            if (str_ends_with($key, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mantém os 3 primeiros e 2 últimos dígitos; mascara o resto,
     * preservando a pontuação original (legibilidade para auditoria).
     */
    private function maskDigits(string $document): string
    {
        $digitIndex = 0;
        $total = strlen((string) preg_replace('/\D/', '', $document));

        return (string) preg_replace_callback(
            '/\d/',
            function (array $m) use (&$digitIndex, $total): string {
                $position = $digitIndex;
                $digitIndex++;

                return ($position < 3 || $position >= $total - 2) ? $m[0] : '*';
            },
            $document,
        );
    }

    /**
     * Trunca strings longas — logs guardam evidência, não o payload inteiro.
     */
    private function truncate(string $value): string
    {
        return mb_strlen($value) > self::MAX_STRING_LENGTH
            ? mb_substr($value, 0, self::MAX_STRING_LENGTH).'…[truncado]'
            : $value;
    }
}
