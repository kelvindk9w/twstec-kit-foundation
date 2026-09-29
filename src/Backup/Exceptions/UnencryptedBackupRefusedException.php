<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Backup\Exceptions;

use Exception;

/**
 * Backup recusado em produção porque o zip sairia sem criptografia (ou com
 * senha de fachada). Ver Twstec\Kit\Foundation\Backup\BackupEncryption.
 *
 * Estende `Exception` porque é o tipo que o evento `BackupHasFailed` do
 * pacote aceita — e é por esse evento que a recusa chega ao e-mail e ao
 * webhook de falha de backup.
 */
final class UnencryptedBackupRefusedException extends Exception
{
    public static function because(string $reason): self
    {
        return new self(
            'Backup RECUSADO em APP_ENV=production. '.$reason
            .' Defina BACKUP_ARCHIVE_PASSWORD com uma senha forte e própria (guarde-a fora do servidor: '
            .'sem ela o backup não restaura). Para aceitar backup sem criptografia conscientemente, '
            .'BACKUP_ALLOW_UNENCRYPTED_IN_PRODUCTION=true. Ver Twstec\Kit\Foundation\Backup\BackupEncryption.'
        );
    }
}
