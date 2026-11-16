<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Database;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Os comandos que APAGAM o banco, recusados em produção.
 *
 * PROBLEMA: `db:wipe`, `migrate:fresh`, `migrate:refresh`, `migrate:reset` e
 * `migrate:rollback` apagam tabelas. Com `--force` (o que todo deploy não
 * interativo passa), o Laravel não pergunta nada. E o kit desvia esses
 * comandos para o papel de MIGRAÇÃO, o dono do banco (ver DatabaseRoles):
 * no container que recebe esse papel — o `migrate` do
 * docker-compose.prod.yml —, um `migrate:fresh --force` digitado no servidor
 * errado, ou um `docker compose run migrate <outro comando>`, apaga tudo. Nos
 * containers de longa duração (app, horizon, scheduler) quem recusa é o
 * próprio PostgreSQL, porque o papel da aplicação não é dono de nada; no
 * `migrate`, nada recusava.
 *
 * O QUE PASSA A ACONTECER: em APP_ENV=production, os cinco comandos são
 * recusados pelo próprio Laravel (DB::prohibitDestructiveCommands): saem com
 * código 1 e "This command is prohibited from running in this environment",
 * mesmo com --force e com o papel de migração, sem tocar no banco. A
 * tentativa grava aviso no log e a saída do comando diz como liberar. O
 * `migrate` de sempre (só acrescenta) continua funcionando. Fora de produção,
 * nada muda: o desenvolvimento e a suíte de testes (RefreshDatabase usa
 * `migrate:fresh`) seguem como antes.
 *
 * QUEM CHAMA é o boot do FoundationServiceProvider, sozinho, a cada boot — a
 * proteção não depende de o aplicativo lembrar de nada. Ela não fica dentro
 * das guardas de produção (ProductionHardening): desligar aquelas
 * (SECURITY_PRODUCTION_GUARDS=false) não libera apagar o banco. Um provider
 * do aplicativo que chame DB::prohibitDestructiveCommands() depois deste
 * vence (os providers do aplicativo sobem depois dos pacotes).
 *
 * ESCAPE HATCH: `DB_ALLOW_DESTRUCTIVE_COMMANDS_IN_PRODUCTION=true`
 * (`security.database.allow_destructive_commands_in_production`), passado só
 * no comando que precisa — o rollback planejado de uma migration (com backup
 * antes) ou a demo hospedada que recria o banco de propósito. Barulhento:
 * cada comando destrutivo que roda com ele grava aviso no log.
 */
final class DestructiveCommands
{
    /**
     * Os comandos que DB::prohibitDestructiveCommands() recusa (Laravel 13:
     * FreshCommand, RefreshCommand, ResetCommand, RollbackCommand e
     * WipeCommand).
     *
     * @var list<string>
     */
    public const COMMANDS = ['db:wipe', 'migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback'];

    /**
     * Liga (em produção, sem opt-out) ou desliga a recusa. Sempre com o valor
     * calculado, para um boot fora de produção desfazer o de um boot anterior
     * no mesmo processo (a flag do Laravel é estática).
     */
    public static function apply(Application $app): void
    {
        DB::prohibitDestructiveCommands(self::prohibited($app));
    }

    /**
     * Os comandos destrutivos estão recusados nesta instalação?
     */
    public static function prohibited(Application $app): bool
    {
        return self::production($app) && ! self::allowedInProductionByOptOut();
    }

    /**
     * APP_ENV=production? (o contrato da aplicação não tem `isProduction()`.)
     */
    private static function production(Application $app): bool
    {
        return $app->environment('production') === true;
    }

    /**
     * O opt-out explícito está declarado?
     * (`DB_ALLOW_DESTRUCTIVE_COMMANDS_IN_PRODUCTION`)
     */
    public static function allowedInProductionByOptOut(): bool
    {
        return config('security.database.allow_destructive_commands_in_production') === true;
    }

    /**
     * Na largada de um comando destrutivo em produção: recusado, o aviso no
     * log e, na saída do comando, como liberar; liberado pelo opt-out, o
     * aviso de que ele vai rodar.
     */
    public static function commandStarting(Application $app, CommandStarting $event): void
    {
        if (! self::production($app) || ! in_array($event->command, self::COMMANDS, true)) {
            return;
        }

        if (self::allowedInProductionByOptOut()) {
            Log::warning(sprintf(
                'O comando destrutivo `%s` vai rodar em APP_ENV=production porque DB_ALLOW_DESTRUCTIVE_COMMANDS_IN_PRODUCTION está ligado: ele apaga tabelas (e os dados delas). Use o opt-out só no comando que precisa, com backup antes. Ver Twstec\Kit\Foundation\Database\DestructiveCommands.',
                $event->command,
            ));

            return;
        }

        Log::warning(sprintf(
            'O comando destrutivo `%s` foi RECUSADO em APP_ENV=production (mesmo com --force): ele apagaria tabelas. Ver Twstec\Kit\Foundation\Database\DestructiveCommands.',
            $event->command,
        ));

        $event->output->writeln(sprintf(
            '<comment>`%s` apaga tabelas e está recusado em APP_ENV=production. Para um comando planejado (com backup antes), passe DB_ALLOW_DESTRUCTIVE_COMMANDS_IN_PRODUCTION=true só nele. Ver docs/producao.md.</comment>',
            $event->command,
        ));
    }
}
