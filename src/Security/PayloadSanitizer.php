<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Security;

/**
 * Sanitização de payload para PERSISTÊNCIA.
 *
 * Regra de lei: payload malicioso é armazenado SANITIZADO/escapado —
 * NUNCA em formato executável. Strings são escapadas em HTML
 * (htmlspecialchars), têm null bytes removidos e são truncadas.
 *
 * Uso: apenas para gravar a evidência da tentativa no request log
 * (AttackEvidence — linha BLOQUEADA ou tentativa observada). O sanitizado
 * NUNCA é usado para processamento de negócio: no modo observe a aplicação
 * segue recebendo o input original, e quem o neutraliza na saída é o Blade.
 */
final class PayloadSanitizer
{
    /**
     * Tamanho máximo de cada string persistida (evidência, não o payload inteiro).
     */
    private const MAX_STRING_LENGTH = 2000;

    /**
     * Profundidade máxima de arrays aninhados persistida.
     */
    private const MAX_DEPTH = 10;

    /**
     * Sanitiza recursivamente: strings escapadas + sem null bytes + truncadas.
     */
    public function sanitize(mixed $data, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return '[profundidade máxima excedida]';
        }

        if (is_string($data)) {
            return $this->sanitizeString($data);
        }

        if (is_array($data)) {
            $sanitized = [];

            foreach ($data as $key => $value) {
                $safeKey = is_string($key) ? $this->sanitizeString($key) : $key;
                $sanitized[$safeKey] = $this->sanitize($value, $depth + 1);
            }

            return $sanitized;
        }

        if (is_scalar($data) || $data === null) {
            return $data;
        }

        // Objetos/recursos nunca são persistidos crus.
        return '['.get_debug_type($data).']';
    }

    /**
     * Escapa HTML (nunca executável), remove null bytes e trunca.
     */
    public function sanitizeString(string $value): string
    {
        $value = str_replace("\0", '', $value);

        return mb_substr(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 0, self::MAX_STRING_LENGTH);
    }
}
