<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Twstec\Kit\Foundation\Audit\AuditScope;
use Twstec\Kit\Foundation\Audit\AuditTrail;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;
use Twstec\Kit\Foundation\Settings\Models\Setting;
use Twstec\Kit\Foundation\Settings\SettingsManager;

// =============================================================================
// CONFIGURAÇÕES EDITÁVEIS COM TIPO (config/settings.php → SettingsManager):
// int, bool, enum e string. Cada tipo grava, volta do cache no tipo certo,
// recusa no servidor o valor que não serve e deixa `setting.changed` na
// trilha, com o antes e o depois.
// =============================================================================

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('settings.overrides', [
        'demo.limit' => ['type' => 'int', 'min' => 1, 'max' => 10],
        'demo.banner' => ['type' => 'bool'],
        'demo.channel' => ['type' => 'enum', 'options' => ['email', 'sms']],
        'demo.tagline' => ['type' => 'string', 'max_length' => 12],
        'demo.support_url' => ['type' => 'string', 'pattern' => '#^https://#'],
    ]);
    config()->set('demo', ['limit' => 5, 'banner' => false, 'channel' => 'email', 'tagline' => 'do .env', 'support_url' => null]);
});

function settingsSet(string $key, int|bool|string|null $value): void
{
    $trail = app(AuditTrail::class);
    $trail->within(AuditScope::console('teste'), fn () => app(SettingsManager::class)->set($key, $value));
}

function settingsRow(string $key): ?AuditEvent
{
    return AuditEvent::query()->where('action', 'setting.changed')->get()
        ->first(fn (AuditEvent $event): bool => array_key_exists($key, (array) $event->changes));
}

it('cada tipo grava, volta do cache no tipo certo e deixa o antes e o depois na trilha', function (string $key, int|bool|string $value): void {
    settingsSet($key, $value);

    // Lido uma vez, depois só do cache (a tabela não é lida de novo), e
    // aplicado ao config.
    app(SettingsManager::class)->all();
    DB::enableQueryLog();
    $read = app(SettingsManager::class)->get($key);
    expect(DB::getQueryLog())->toBe([]);
    app(SettingsManager::class)->applyToConfig();

    $row = settingsRow($key);

    expect($read)->toBe($value)
        ->and(config($key))->toBe($value)
        ->and($row)->not->toBeNull()
        ->and($row?->changes[$key])->toBe(['before' => null, 'after' => $value]);
})->with([
    'int' => ['demo.limit', 7],
    'bool' => ['demo.banner', true],
    'bool desligado' => ['demo.banner', false],
    'enum' => ['demo.channel', 'sms'],
    'string' => ['demo.tagline', 'Olá, mundo'],
    'string com formato' => ['demo.support_url', 'https://ajuda.example.com'],
]);

it('recusa no servidor o valor que não serve para o tipo, sem gravar nem registrar', function (string $key, int|bool|string $value, string $reason): void {
    expect(fn () => settingsSet($key, $value))
        ->toThrow(InvalidArgumentException::class, $reason);

    expect(Setting::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);
})->with([
    'int acima do máximo' => ['demo.limit', 11, 'maior que o máximo'],
    'int abaixo do mínimo' => ['demo.limit', 0, 'menor que o mínimo'],
    'int como texto' => ['demo.limit', '7', 'inteiro'],
    'bool como número' => ['demo.banner', 1, 'liga/desliga'],
    'bool como texto' => ['demo.banner', 'true', 'liga/desliga'],
    'enum fora das opções' => ['demo.channel', 'pombo', 'email, sms'],
    'string longa demais' => ['demo.tagline', 'treze letras!', 'mais de 12'],
    'string com quebra de linha' => ['demo.tagline', "a\nb", 'controle'],
    'string fora do formato' => ['demo.support_url', 'http://ajuda.example.com', 'formato'],
]);

it('tipo desconhecido na declaração é recusado', function (): void {
    // As chaves da whitelist têm pontos: a lista é trocada inteira.
    config()->set('settings.overrides', [...config('settings.overrides'), 'demo.color' => ['type' => 'color']]);

    settingsSet('demo.color', '#fff');
})->throws(InvalidArgumentException::class, 'tipo desconhecido');

it('gravar o mesmo valor não gera linha; remover (null) volta ao .env e registra', function (): void {
    settingsSet('demo.banner', true);
    settingsSet('demo.banner', true);
    settingsSet('demo.banner', null);

    $rows = AuditEvent::query()->where('action', 'setting.changed')->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[1]->changes['demo.banner'])->toBe(['before' => true, 'after' => null])
        ->and(app(SettingsManager::class)->get('demo.banner'))->toBeFalse();
});

it('valor gravado que a declaração deixou de aceitar é ignorado: vale o .env', function (): void {
    settingsSet('demo.channel', 'sms');

    // A opção saiu da declaração depois da gravação.
    config()->set('settings.overrides', [...config('settings.overrides'), 'demo.channel' => ['type' => 'enum', 'options' => ['email', 'push']]]);
    cache()->forget((string) config('settings.cache_key', 'settings.db_overrides'));

    expect(app(SettingsManager::class)->all())->not->toHaveKey('demo.channel')
        ->and(app(SettingsManager::class)->get('demo.channel'))->toBe('email');
});

it('gravação antiga de int como texto numérico continua lida como inteiro', function (): void {
    Setting::query()->create(['key' => 'demo.limit', 'value' => ['v' => '8']]);

    expect(app(SettingsManager::class)->get('demo.limit'))->toBe(8);
});
