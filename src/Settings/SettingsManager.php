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
 * Cache: a coleção inteira é cacheada (1 leitura por deploy/alteração) e
 * invalidada a cada escrita — a tabela nunca é consultada por requisição.
 */
final class SettingsManager
{
    /**
     * Todas as sobreposições gravadas (somente chaves da whitelist), com
     * cast para o tipo declarado em config/settings.php.
     *
     * @return array<string, int>
     */
    public function all(): array
    {
        /** @var array<string, int> */
        return Cache::rememberForever($this->cacheKey(), function (): array {
            /** @var array<string, string> $whitelist */
            $whitelist = config('settings.overrides', []);

            $stored = Setting::query()
                ->whereIn('key', array_keys($whitelist))
                ->pluck('value', 'key');

            $overrides = [];

            foreach ($whitelist as $key => $meta) {
                $raw = $stored->get($key);

                if ($raw === null) {
                    continue;
                }

                // value é JSON (cast array): um int gravado vira [int]? Não —
                // gravamos sempre ['v' => valor]; ver set().
                $value = is_array($raw) ? ($raw['v'] ?? null) : $raw;

                if ($value === null) {
                    continue;
                }

                $overrides[$key] = $meta['type'] === 'int' ? (int) $value : $value;
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
     * Grava (ou remove, com null) uma sobreposição. Fora da whitelist = exceção.
     *
     * Toda mudança EFETIVA fica na trilha de auditoria como `setting.changed`,
     * com a chave e o de/para da sobreposição (nulo = sem sobreposição, vale o
     * .env). Gravar o mesmo valor não gera linha. O ator e o contexto vêm do
     * escopo aberto (o /admin, um comando) — ver AuditTrail.
     */
    public function set(string $key, ?int $value): void
    {
        if (! array_key_exists($key, (array) config('settings.overrides', []))) {
            throw new InvalidArgumentException("Chave de configuração não permitida: {$key}");
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
     * @return array<string, array{type: string, min: int, max: int}>
     */
    public function whitelist(): array
    {
        /** @var array<string, array{type: string, min: int, max: int}> */
        return (array) config('settings.overrides', []);
    }

    private function cacheKey(): string
    {
        return (string) config('settings.cache_key', 'settings.db_overrides');
    }
}
