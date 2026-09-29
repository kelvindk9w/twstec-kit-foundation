<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Tests;

/**
 * Aplicação Laravel LIMPA — só o framework e este pacote, nada do starter —
 * que sobe em APP_ENV=production. O cenário (config e argv) é aplicado antes
 * de os providers rodarem o boot, como num processo de verdade: é o boot do
 * pacote, sozinho, que tem de aplicar as guardas.
 */
abstract class ProductionAppTestCase extends TestCase
{
    /**
     * @var array{config?: array<string, mixed>, argv?: list<string>}
     */
    public static array $scenario = [];

    /**
     * @var list<string>|null
     */
    private static ?array $originalArgv = null;

    protected function defineEnvironment($app): void
    {
        $app->detectEnvironment(fn (): string => 'production');

        // Estado de partida saudável: chave própria. Cada teste estraga uma coisa.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        foreach (static::$scenario['config'] ?? [] as $key => $value) {
            $app['config']->set($key, $value);
        }

        if (isset(static::$scenario['argv'])) {
            self::$originalArgv ??= $_SERVER['argv'] ?? [];
            $_SERVER['argv'] = static::$scenario['argv'];
        }
    }

    /**
     * Sobe uma aplicação nova com o cenário dado.
     *
     * @param  array{config?: array<string, mixed>, argv?: list<string>}  $scenario
     */
    protected function bootProductionApp(array $scenario): void
    {
        static::$scenario = $scenario;

        try {
            $this->refreshApplication();
        } finally {
            static::$scenario = [];
            $this->restoreArgv();
        }
    }

    protected function tearDown(): void
    {
        static::$scenario = [];
        $this->restoreArgv();

        parent::tearDown();
    }

    private function restoreArgv(): void
    {
        if (self::$originalArgv !== null) {
            $_SERVER['argv'] = self::$originalArgv;
            self::$originalArgv = null;
        }
    }
}
