<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Audit;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Twstec\Kit\Foundation\Logging\Redactor;
use UnitEnum;

/**
 * O RESUMO DO QUE MUDOU, já redigido — o conteúdo da coluna
 * `audit_events.changes`.
 *
 * Formato: `{campo: {before: valor, after: valor}}`. Na criação `before` é
 * nulo; na exclusão, `after`.
 *
 * O QUE NUNCA ENTRA EM CLARO (regra LGPD do kit — a mesma do Redactor, mais
 * o que o próprio model declara como sensível):
 * - SEGREDO → `[REDACTED]`: nome de campo que o Redactor já trata como
 *   segredo (password, token, code, api_key...), qualquer campo cujo nome
 *   contenha password/secret/token/hash/pepper/salt/private/otp/cvv/cvc, e
 *   todo atributo que o model esconde (`$hidden` — ex.: `secret_hash` da
 *   chave de API, `remember_token`). O campo aparece (fica registrado QUE
 *   mudou), o valor não. Nulo continua nulo: "não havia" não é segredo.
 * - DADO PESSOAL CRIPTOGRAFADO EM REPOUSO (cast `encrypted*` — ex.: o nome
 *   do usuário) → só as iniciais: `Maria Silva` → `M*** S***`. Se o model
 *   decidiu que o dado precisa de cifra no banco, ele não vai decifrado para
 *   a trilha.
 * - e-mail, CPF/CNPJ e número de cartão em QUALQUER texto → mascarados pelo
 *
 *   Redactor (`k***@dominio.com`, `123.***.***-09`, `**** **** **** 1111`).
 * - textos longos são cortados (a trilha guarda evidência, não conteúdo).
 *
 * Ficam de fora do resumo `id`, `created_at` e `updated_at` (ruído: toda
 * gravação muda o `updated_at`).
 */
final class AuditChanges
{
    public const MASK = Redactor::MASK;

    private const IGNORED_ATTRIBUTES = ['id', 'created_at', 'updated_at'];

    /**
     * Pedaços de nome de campo que denotam segredo, além das regras do
     * Redactor (que olha nome exato e sufixo).
     *
     * @var list<string>
     */
    private const SECRET_FRAGMENTS = ['password', 'secret', 'token', 'hash', 'pepper', 'salt', 'private', 'otp', 'cvv', 'cvc'];

    private const MAX_STRING_LENGTH = 500;

    public function __construct(
        private readonly Redactor $redactor,
    ) {}

    /**
     * @return array<string, array{before: mixed, after: mixed}>
     */
    public function forCreated(Model $model): array
    {
        $changes = [];

        foreach ($this->attributeNames($model, array_keys($model->getAttributes())) as $key) {
            $changes[$key] = [
                'before' => null,
                'after' => $this->value($key, $model->getAttribute($key), $model),
            ];
        }

        return $changes;
    }

    /**
     * Só os campos que de fato mudaram nesta gravação. Chamado no evento
     * `updated`: `getChanges()` já tem o novo valor e `getOriginal()` ainda
     * guarda o anterior (o Eloquent só sincroniza depois do `saved`).
     *
     * @return array<string, array{before: mixed, after: mixed}>
     */
    public function forUpdated(Model $model): array
    {
        $changes = [];

        foreach ($this->attributeNames($model, array_keys($model->getChanges())) as $key) {
            // Atributo que não estava carregado no model (ex.: uma FK nula que
            // o factory não preencheu) sai "sujo" mesmo gravando nulo sobre
            // nulo. Mesmo valor cru antes e depois = não houve mudança.
            if ($model->getRawOriginal($key) === ($model->getAttributes()[$key] ?? null)) {
                continue;
            }

            $changes[$key] = [
                'before' => $this->value($key, $model->getOriginal($key), $model),
                'after' => $this->value($key, $model->getAttribute($key), $model),
            ];
        }

        return $changes;
    }

    /**
     * O retrato do registro no momento em que saiu (redigido).
     *
     * @return array<string, array{before: mixed, after: mixed}>
     */
    public function forDeleted(Model $model): array
    {
        $changes = [];

        foreach ($this->attributeNames($model, array_keys($model->getAttributes())) as $key) {
            $changes[$key] = [
                'before' => $this->value($key, $model->getAttribute($key), $model),
                'after' => null,
            ];
        }

        return $changes;
    }

    /**
     * Mudanças declaradas à mão por quem grava (ex.: configurações), no mesmo
     * formato e sob as MESMAS regras de redação.
     *
     * @param  array<string, array{before?: mixed, after?: mixed}>  $changes
     * @return array<string, array{before: mixed, after: mixed}>
     */
    public function sanitize(array $changes, ?Model $model = null): array
    {
        $sanitized = [];

        foreach ($changes as $key => $pair) {
            $key = (string) $key;

            $sanitized[$key] = [
                'before' => $this->value($key, $pair['before'] ?? null, $model),
                'after' => $this->value($key, $pair['after'] ?? null, $model),
            ];
        }

        return $sanitized;
    }

    /**
     * O nome do campo denota segredo (para este model, se houver)?
     */
    public function isSecret(string $key, ?Model $model = null): bool
    {
        if ($this->redactor->isSensitiveKey($key)) {
            return true;
        }

        $lower = mb_strtolower($key);

        foreach (self::SECRET_FRAGMENTS as $fragment) {
            if (str_contains($lower, $fragment)) {
                return true;
            }
        }

        return $model !== null && in_array($key, $model->getHidden(), true);
    }

    /**
     * O atributo é dado pessoal cifrado em repouso?
     */
    public function isEncryptedPersonalData(string $key, ?Model $model): bool
    {
        if ($model === null) {
            return false;
        }

        $cast = $model->getCasts()[$key] ?? null;

        return is_string($cast) && str_starts_with($cast, 'encrypted');
    }

    /**
     * `Maria da Silva` → `M*** d*** S***`: sobra o suficiente para o
     * operador reconhecer a conta que já está vendo, e nada para quem só
     * tem a trilha.
     */
    public static function maskPersonal(string $value): string
    {
        $words = preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return '';
        }

        return implode(' ', array_map(
            fn (string $word): string => mb_substr($word, 0, 1).'***',
            $words,
        ));
    }

    /**
     * @param  list<int|string>  $keys
     * @return list<string>
     */
    private function attributeNames(Model $model, array $keys): array
    {
        $names = [];

        foreach ($keys as $key) {
            $key = (string) $key;

            if ($key === $model->getKeyName() || in_array($key, self::IGNORED_ATTRIBUTES, true)) {
                continue;
            }

            $names[] = $key;
        }

        return $names;
    }

    private function value(string $key, mixed $value, ?Model $model): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($this->isSecret($key, $model)) {
            return self::MASK;
        }

        if ($this->isEncryptedPersonalData($key, $model)) {
            return is_string($value) ? self::maskPersonal($value) : self::MASK;
        }

        return $this->normalize($value);
    }

    private function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => Carbon::instance($value)->utc()->toIso8601String(),
            is_array($value) => $this->redactor->redactArray($value),
            is_string($value) => $this->truncate($this->redactor->redactString($value)),
            is_scalar($value) => $value,
            default => '['.get_debug_type($value).']',
        };
    }

    private function truncate(string $value): string
    {
        return mb_strlen($value) > self::MAX_STRING_LENGTH
            ? mb_substr($value, 0, self::MAX_STRING_LENGTH).'…'
            : $value;
    }
}
