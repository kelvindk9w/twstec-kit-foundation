<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Backup\Console;

use Illuminate\Support\Facades\Log;
use Spatie\Backup\Commands\BackupCommand;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Events\BackupHasFailed;
use Twstec\Kit\Foundation\Backup\BackupEncryption;
use Twstec\Kit\Foundation\Backup\Exceptions\UnencryptedBackupRefusedException;

/**
 * O `backup:run` do spatie/laravel-backup, com a regra da criptografia na
 * frente (Twstec\Kit\Foundation\Backup\BackupEncryption).
 *
 * É o MESMO comando — mesma assinatura, mesmas opções —, registrado no lugar
 * do original pelo container (AppServiceProvider::register). Por isso a regra
 * vale para toda forma de disparar o backup: o comando na mão, o agendamento
 * (`Schedule::command('backup:run --only-db')` roda este comando num processo
 * próprio) e `Artisan::call()`.
 *
 * A verificação acontece antes de qualquer trabalho: nenhum dump é gerado,
 * nenhum arquivo temporário é criado, nada sai para o disco de destino.
 */
class GuardedBackupCommand extends BackupCommand
{
    public function handle(): int
    {
        $config = $this->effectiveConfig();
        $problem = BackupEncryption::problem($config->backup);

        if ($problem === null) {
            return parent::handle();
        }

        $reason = BackupEncryption::describe($problem);

        if (BackupEncryption::refusalRequired($this->laravel->isProduction(), BackupEncryption::unencryptedAllowedByOptOut())) {
            return $this->refuse(UnencryptedBackupRefusedException::because($reason));
        }

        if ($this->laravel->isProduction()) {
            // Opt-out explícito: segue, mas a decisão fica no log a cada execução.
            $message = 'Backup SEM criptografia em APP_ENV=production, liberado por BACKUP_ALLOW_UNENCRYPTED_IN_PRODUCTION. '.$reason;
            Log::warning($message);
            $this->warn($message);

            return parent::handle();
        }

        // Dev/local/teste: o dump é do banco de desenvolvimento e vai para o
        // disco local. Avisa e segue.
        $this->warn('[aviso] '.$reason.' Permitido fora de produção; em APP_ENV=production este backup seria RECUSADO.');

        return parent::handle();
    }

    /**
     * A configuração que o pacote vai de fato usar: a padrão, ou a do
     * `--config=` quando informado (sem aplicá-la — o pacote faz isso logo
     * em seguida, do jeito dele).
     */
    private function effectiveConfig(): Config
    {
        $alternative = $this->option('config');

        if (is_string($alternative) && $alternative !== '') {
            return Config::fromArray((array) config($alternative));
        }

        return $this->config;
    }

    /**
     * Recusa ALTA: erro no console, erro no log e o evento de falha de
     * backup do pacote — que já está ligado ao e-mail e ao webhook de alerta
     * (config/backup.php, notifications). Sem isso a recusa seria só mais um
     * backup que "não aconteceu", e o primeiro a notar seria o monitor, um
     * dia depois.
     */
    private function refuse(UnencryptedBackupRefusedException $exception): int
    {
        $this->error($exception->getMessage());

        Log::error($exception->getMessage(), ['command' => 'backup:run']);

        if (! $this->option('disable-notifications')) {
            event(new BackupHasFailed($exception));
        }

        return self::FAILURE;
    }
}
