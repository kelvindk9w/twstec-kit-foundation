<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Logging;

use InvalidArgumentException;

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
 * - Segredos de provedor dentro de TEXTO LIVRE (chave do Asaas `$aact_…`,
 *   `sk_live_…` da Stripe, token do GitHub, chave de acesso da AWS, JWT,
 *   `Bearer …`, bloco de chave privada) viram '[REDACTED]' onde estiverem,
 *   em qualquer valor e na linha final dos logs de arquivo (#114).
 *
 * A redaction é recursiva e cobre também as CHAVES do array (uma chave
 * maliciosa não vaza dado sensível como nome de campo): o nome é testado
 * contra a lista de segredos e o texto da própria chave passa pelas mesmas
 * máscaras de CPF/CNPJ/e-mail/cartão dos valores.
 *
 * O APLICATIVO ACRESCENTA (#122), nunca tira: `security.redaction.keys`,
 * `.suffixes` e `.patterns` somam nomes de campo, sufixos e expressões do
 * domínio dele (chave Pix, conta bancária, nome do pagador...) às listas do
 * kit. A instância do contêiner (`fromConfig()`) é a mesma em todos os
 * canais — trilha de requisições, chamadas de saída, auditoria, webhooks e o
 * contexto dos logs de arquivo. `new Redactor` puro = só as listas do kit.
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
        // Assinatura de URL assinada (entrega de arquivo, link de e-mail):
        // dentro da validade, quem a tem abre o recurso.
        'signature',
    ];

    /**
     * Sufixos de chave que denotam segredo (ex.: webhook_token, hmac_secret).
     *
     * @var list<string>
     */
    private const SENSITIVE_SUFFIXES = ['_token', '_secret', '_password', '_api_key'];

    /**
     * Dados pessoais SEM formato reconhecível (o texto livre não os pega):
     * sensíveis por padrão — na dúvida, mascarar é o lado seguro (#122).
     *
     * @var list<string>
     */
    private const PERSONAL_KEYS = [
        'phone', 'phone_number', 'mobile_phone', 'telefone', 'celular',
        'birth_date', 'birthdate', 'data_nascimento',
        'mother_name', 'nome_mae',
        'bank_account', 'account_number', 'conta_bancaria', 'numero_conta',
        'pix_key', 'chave_pix', 'address_key',
    ];

    /**
     * Segredos de provedor reconhecíveis em TEXTO LIVRE (#114). Cada
     * expressão é específica o bastante para não pegar UUID, número de
     * pedido nem valor: prefixo conhecido + corpo longo.
     *
     * @var list<string>
     */
    private const SECRET_PATTERNS = [
        // Asaas: $aact_prod_… / $aact_hmlg_… (o '$' às vezes some no caminho).
        '/\$?aact_[A-Za-z0-9_\-:=+\/$]{8,}/',
        // Stripe e as chaves de API do próprio kit (`<prefixo>_sk_live_…`,
        // API_KEY_PREFIX): secretas, restritas, publicáveis; e o segredo de
        // webhook.
        '/\b(?:[a-z0-9]{1,16}_)?(?:sk|rk|pk)_(?:live|test)_[A-Za-z0-9]{8,}/',
        '/\bwhsec_[A-Za-z0-9+\/=]{8,}/',
        // GitHub: tokens clássicos e fine-grained.
        '/\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{20,}/',
        '/\bgithub_pat_[A-Za-z0-9_]{20,}/',
        // AWS: id de chave de acesso.
        '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
        // JWT (cabeçalho.corpo.assinatura).
        '/\beyJ[A-Za-z0-9_-]{5,}\.eyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}/',
        // Credencial em cabeçalho copiado para texto.
        '/\b(?:Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i',
        // Bloco PEM de chave privada.
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z ]*PRIVATE KEY-----/',
    ];

    /**
     * Nomes de campo (minúsculos) mascarados: os do kit + os do aplicativo.
     *
     * @var list<string>
     */
    private readonly array $keys;

    /**
     * @var list<string>
     */
    private readonly array $suffixes;

    /**
     * @var list<string>
     */
    private readonly array $patterns;

    /**
     * @param  iterable<mixed>  $keys  nomes de campo do aplicativo (somados aos do kit)
     * @param  iterable<mixed>  $suffixes  sufixos do aplicativo (somados aos do kit)
     * @param  iterable<mixed>  $patterns  expressões regulares PCRE completas, com delimitador
     *
     * @throws InvalidArgumentException nome vazio ou expressão inválida — a configuração errada falha no boot, não vira máscara que não mascara
     */
    public function __construct(iterable $keys = [], iterable $suffixes = [], iterable $patterns = [])
    {
        $this->keys = self::names([...self::SENSITIVE_KEYS, ...self::PERSONAL_KEYS], $keys, 'keys');
        $this->suffixes = self::names(self::SENSITIVE_SUFFIXES, $suffixes, 'suffixes');

        $extra = [];

        foreach ($patterns as $pattern) {
            if (! is_string($pattern) || $pattern === '' || @preg_match($pattern, '') === false) {
                throw new InvalidArgumentException('security.redaction.patterns: expressão regular inválida ('.var_export($pattern, true).').');
            }

            $extra[] = $pattern;
        }

        $this->patterns = array_values(array_unique([...self::SECRET_PATTERNS, ...$extra]));
    }

    /**
     * A instância do contêiner: as listas do kit + `security.redaction`.
     */
    public static function fromConfig(): self
    {
        $config = config('security.redaction', []);
        $config = is_array($config) ? $config : [];

        return new self(
            self::listFrom($config['keys'] ?? []),
            self::listFrom($config['suffixes'] ?? []),
            self::listFrom($config['patterns'] ?? []),
        );
    }

    /**
     * @return list<mixed>
     */
    private static function listFrom(mixed $value): array
    {
        if (is_string($value)) {
            return array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
        }

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param  list<string>  $kit
     * @param  iterable<mixed>  $extra
     * @return list<string>
     */
    private static function names(array $kit, iterable $extra, string $setting): array
    {
        $names = $kit;

        foreach ($extra as $name) {
            if (! is_string($name) || trim($name) === '') {
                throw new InvalidArgumentException("security.redaction.{$setting}: cada item precisa ser um nome não vazio.");
            }

            $names[] = mb_strtolower(trim($name));
        }

        return array_values(array_unique($names));
    }

    /**
     * Faixa de comprimento de um PAN (ISO/IEC 7812): 13 a 19 dígitos.
     */
    private const PAN_MIN_DIGITS = 13;

    private const PAN_MAX_DIGITS = 19;

    /**
     * Um UUID (8-4-4-4-12 hexadecimais), capturado para o preg_split
     * devolvê-lo intacto entre os trechos de texto livre.
     */
    private const UUID_PATTERN = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';

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
        // Segredos de provedor primeiro: o corpo de uma chave pode conter
        // dígitos que as máscaras seguintes cortariam pela metade.
        $value = $this->maskSecrets($value);

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
     * Troca por '[REDACTED]' os segredos reconhecíveis em texto livre: os
     * padrões do kit (#114) e os do aplicativo (`security.redaction.patterns`).
     */
    public function maskSecrets(string $value): string
    {
        foreach ($this->patterns as $pattern) {
            $value = (string) preg_replace($pattern, self::MASK, $value);
        }

        return $value;
    }

    /**
     * Só a regra por NOME de campo, recursiva: o valor de chave sensível
     * vira '[REDACTED]'; o resto fica como está. É o que o contexto dos logs
     * de arquivo recebe (as máscaras de texto livre de CPF/e-mail continuam
     * com quem monta o contexto; cartão e segredos são pegos na linha final).
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function redactSensitiveKeys(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $data[$key] = self::MASK;
            } elseif (is_array($value)) {
                $data[$key] = $this->redactSensitiveKeys($value);
            }
        }

        return $data;
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
     *
     * UUIDs passam intactos: os grupos só de dígitos de um UUID, emendados
     * pelos hífens ("…-9492-611262858b78"), formam uma corrida de 13+
     * dígitos que às vezes passa no Luhn, e a auditoria ou o log gravariam o
     * identificador corrompido. Um UUID nunca é um PAN (32 dígitos
     * hexadecimais contra no máximo 19 decimais).
     */
    public function maskCardNumbers(string $value): string
    {
        $parts = preg_split(self::UUID_PATTERN, $value, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$value];

        foreach ($parts as $index => $part) {
            // Índices ímpares são os UUIDs capturados pelo separador.
            if ($index % 2 === 0) {
                $parts[$index] = (string) preg_replace_callback(
                    '/(?<!\d)\d(?:[ -]?\d){'.(self::PAN_MIN_DIGITS - 1).',}(?!\d)/',
                    fn (array $m): string => $this->maskCardRun($m[0]),
                    $part,
                );
            }
        }

        return implode('', $parts);
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
        // O nome como veio e a forma snake de um nome camelCase (APIs de
        // provedor: `mobilePhone`, `apiKey`, `accessToken`).
        $candidates = array_unique([
            mb_strtolower($key),
            mb_strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key)),
        ]);

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $this->keys, true)) {
                return true;
            }

            foreach ($this->suffixes as $suffix) {
                if (str_ends_with($candidate, $suffix)) {
                    return true;
                }
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
