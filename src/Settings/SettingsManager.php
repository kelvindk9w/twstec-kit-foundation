<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Settings;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Twstec\Kit\Foundation\Audit\AuditTrail;
use Twstec\Kit\Foundation\Settings\Models\Setting;

/**
 * Gerenciador das configurações editáveis pelo super admin.
 *
 * Leitura/escrita restrita à WHITELIST de config/settings.php. Os valores
 * gravados sobrescrevem em runtime os configs vindos do .env (ver
 * applyToConfig, chamado no boot pelo SettingsServiceProvider). Ausente na
 * tabela = valor do .env vigente (fallback).
 *
 * TIPOS (o `type` de cada chave na whitelist):
 *
 * - `int`: inteiro entre `min` e `max`;
 * - `bool`: liga/desliga;
 * - `enum`: um dos valores de `options` (lista de textos);
 * - `string`: texto curto de até `max_length` caracteres (padrão 255), sem
 *   caracteres de controle, e casando com `pattern` quando declarado.
 *
 * Valor fora do tipo é RECUSADO na gravação (InvalidArgumentException) — a
 * tela valida antes, mas a barreira é aqui. Na leitura, um valor gravado que
 * deixou de valer para a declaração (ex.: a opção saiu de `options`) é
 * ignorado: vale o .env, nunca um valor que a declaração não aceita.
 *
 * Cache: a coleção inteira é cacheada (1 leitura por deploy/alteração) e
 * invalidada a cada escrita — a tabela nunca é consultada por requisição.
 */
final class SettingsManager
{
    public const TYPES = ['int', 'bool', 'enum', 'string'];

    public const DEFAULT_MAX_LENGTH = 255;

    /**
     * Todas as sobreposições gravadas (somente chaves da whitelist), com o
     * tipo declarado em config/settings.php.
     *
     * @return array<string, int|bool|string>
     */
    public function all(): array
    {
        /** @var array<string, int|bool|string> */
        return Cache::rememberForever($this->cacheKey(), function (): array {
            $whitelist = $this->whitelist();

            $stored = Setting::query()
                ->whereIn('key', array_keys($whitelist))
                ->pluck('value', 'key');

            $overrides = [];

            foreach ($whitelist as $key => $meta) {
                $raw = $stored->get($key);

                // value é JSON (cast array): gravamos sempre ['v' => valor].
                $value = is_array($raw) ? ($raw['v'] ?? null) : $raw;

                if ($value === null) {
                    continue;
                }

                $typed = self::typed($meta, $value);

                if ($typed !== null) {
                    $overrides[$key] = $typed;
                }
            }

            return $overrides;
        });
    }

    /**
     * Valor efetivo de uma chave da whitelist (DB → fallback do .env/config).
     */
    public function get(string $key): mixed
    {
        $overrides = $this->all();

        return array_key_exists($key, $overrides)
            ? $overrides[$key]
            : config($key);
    }

    /**
     * Grava (ou remove, com null) uma sobreposição. Fora da whitelist ou fora
     * do tipo declarado = exceção, e nada é gravado.
     *
     * Toda mudança EFETIVA fica na trilha de auditoria como `setting.changed`,
     * com a chave e o de/para da sobreposição (nulo = sem sobreposição, vale o
     * .env). Gravar o mesmo valor não gera linha. O ator e o contexto vêm do
     * escopo aberto (o /admin, um comando) — ver AuditTrail.
     */
    public function set(string $key, int|bool|string|null $value): void
    {
        $whitelist = $this->whitelist();

        if (! array_key_exists($key, $whitelist)) {
            throw new InvalidArgumentException("Chave de configuração não permitida: {$key}");
        }

        if ($value !== null) {
            $problem = self::problem($whitelist[$key], $value);

            if ($problem !== null) {
                throw new InvalidArgumentException("Valor inválido para a configuração {$key}: {$problem}");
            }
        }

        $before = $this->all()[$key] ?? null;

        if ($value === null) {
            Setting::query()->where('key', $key)->delete();
        } else {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => ['v' => $value]]);
        }

        Cache::forget($this->cacheKey());

        if ($before !== $value) {
            app(AuditTrail::class)->record(
                action: 'setting.changed',
                changes: [$key => ['before' => $before, 'after' => $value]],
                subjectType: 'setting',
            );
        }
    }

    /**
     * Aplica as sobreposições gravadas por cima dos configs em memória.
     * Chamado UMA vez no boot (SettingsServiceProvider).
     */
    public function applyToConfig(): void
    {
        foreach ($this->all() as $key => $value) {
            config()->set($key, $value);
        }
    }

    /**
     * A whitelist de chaves editáveis (para a UI de Settings).
     *
     * @return array<string, array<string, mixed>>
     */
    public function whitelist(): array
    {
        /** @var array<string, array<string, mixed>> */
        return (array) config('settings.overrides', []);
    }

    /**
     * Por que o valor não serve para a declaração, ou null se serve.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function problem(array $meta, mixed $value): ?string
    {
        $type = $meta['type'] ?? null;

        return match ($type) {
            'int' => match (true) {
                ! is_int($value) => 'esperado um número inteiro',
                isset($meta['min']) && is_int($meta['min']) && $value < $meta['min'] => "menor que o mínimo ({$meta['min']})",
                isset($meta['max']) && is_int($meta['max']) && $value > $meta['max'] => "maior que o máximo ({$meta['max']})",
                default => null,
            },
            'bool' => is_bool($value) ? null : 'esperado liga/desliga (true ou false)',
            'enum' => is_string($value) && in_array($value, self::options($meta), true)
                ? null
                : 'esperada uma das opções: '.implode(', ', self::options($meta)),
            'string' => match (true) {
                ! is_string($value) => 'esperado um texto',
                mb_strlen($value) > self::maxLength($meta) => 'mais de '.self::maxLength($meta).' caracteres',
                preg_match('/\p{Cc}/u', $value) !== 0 => 'caractere de controle (quebra de linha, tabulação…) não é aceito',
                isset($meta['pattern']) && is_string($meta['pattern']) && preg_match($meta['pattern'], $value) !== 1 => 'formato não aceito',
                default => null,
            },
            default => 'tipo desconhecido na declaração ('.(is_scalar($type) ? (string) $type : 'vazio').'; aceitos: '.implode(', ', self::TYPES).')',
        };
    }

    /**
     * As opções de uma chave `enum`.
     *
     * @param  array<string, mixed>  $meta
     * @return list<string>
     */
    public static function options(array $meta): array
    {
        return array_values(array_filter((array) ($meta['options'] ?? []), is_string(...)));
    }

    /**
     * O tamanho máximo de uma chave `string`.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function maxLength(array $meta): int
    {
        $max = $meta['max_length'] ?? null;

        return is_int($max) && $max > 0 ? $max : self::DEFAULT_MAX_LENGTH;
    }

    /**
     * O valor gravado no tipo declarado, ou null se ele não serve mais (ex.:
     * a declaração mudou depois da gravação).
     *
     * @param  array<string, mixed>  $meta
     */
    private static function typed(array $meta, mixed $raw): int|bool|string|null
    {
        $value = match ($meta['type'] ?? null) {
            // Gravações antigas (só havia int) podem vir como texto numérico.
            'int' => is_numeric($raw) ? (int) $raw : $raw,
            default => $raw,
        };

        if (! is_int($value) && ! is_bool($value) && ! is_string($value)) {
            return null;
        }

        return self::problem($meta, $value) === null ? $value : null;
    }

    private function cacheKey(): string
    {
        return (string) config('settings.cache_key', 'settings.db_overrides');
    }
}
