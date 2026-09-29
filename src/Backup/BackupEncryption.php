<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Backup;

use Spatie\Backup\Config\BackupConfig;
use Twstec\Kit\Foundation\Support\CriticalSecrets;

/**
 * A REGRA da criptografia do backup, em um lugar só.
 *
 * PROBLEMA: com `BACKUP_ARCHIVE_PASSWORD` vazia, o spatie/laravel-backup não
 * reclama — ele simplesmente monta o zip SEM criptografia. Em produção isso é
 * o dump do banco inteiro (usuários, hashes, logs de requisição, tudo) indo
 * para o R2 em claro, a cada hora, e nenhum sinal em lugar nenhum: o backup
 * "funciona", o webhook de sucesso dispara, o monitor diz que está saudável.
 * Quem descobre é quem conseguir ler o bucket.
 *
 * Há três jeitos de o zip sair em claro, e os três contam:
 *
 *   SEM SENHA         — a variável ausente ou vazia (o padrão do .env.example);
 *   SENHA DE FACHADA  — um valor do vocabulário de placeholders do kit
 *                       (`troque-esta-senha` e companhia) ou degenerado
 *                       (`AAAA…`): o zip é criptografado, mas com uma senha
 *                       publicada na documentação — é o mesmo que não ter;
 *   CIFRA DESLIGADA   — `encryption` = `none`, ou `default` num PHP cuja
 *                       libzip não tem AES (o pacote cai para "sem cifra" em
 *                       silêncio nesse caso também).
 *
 * ONDE A RECUSA VALE (critério herdado do CriticalSecrets: "recusa onde há
 * dano real, aviso onde não há"):
 *
 *   PRODUÇÃO → RECUSA. O `backup:run` (o comando e, portanto, o agendamento,
 *   que roda o mesmo comando) termina com erro, grava o motivo no log e
 *   dispara o evento de falha de backup — o mesmo que já manda e-mail e
 *   webhook quando o dump quebra. É onde está o dano: dado real saindo da
 *   máquina. Um backup que não roda é ruim, mas é ALTO (falha, alerta, e o
 *   `backup:monitor` acusa backup velho no dia seguinte); um backup em claro
 *   é um vazamento que ninguém vê.
 *
 *   DEV/LOCAL/TESTE → AVISO e segue. O dump é do banco de desenvolvimento,
 *   vai para o disco `local`, e exigir senha ali só faria a pessoa inventar
 *   uma para calar o erro.
 *
 * A recusa mora NO COMANDO, nunca no boot: `composer install`,
 * `package:discover`, `key:generate` e o php-fpm não fazem backup e não
 * devem nem saber que esta regra existe. (Sem `.env`, o Laravel se considera
 * em produção — uma verificação no boot quebraria a instalação, que é
 * exatamente a lição registrada no CriticalSecrets.)
 *
 * OPT-OUT: `BACKUP_ALLOW_UNENCRYPTED_IN_PRODUCTION=true`, para a instalação
 * que decidiu conscientemente que a criptografia é de outra camada (bucket
 * com criptografia do lado do servidor E acesso restrito, disco local
 * cifrado). Barulhento como os outros opt-outs do kit: cada execução grava um
 * aviso no log, para que a decisão continue reencontrável.
 */
final class BackupEncryption
{
    public const MISSING = 'missing';

    public const PLACEHOLDER = 'placeholder';

    public const DISABLED = 'disabled';

    /**
     * Por que este backup sairia sem proteção, ou nulo quando ele sai
     * criptografado com uma senha própria.
     *
     * Recebe a configuração JÁ RESOLVIDA pelo pacote (inclusive a de um
     * `--config=` alternativo), porque é ela que decide o que vai para o zip.
     */
    public static function problem(BackupConfig $config): ?string
    {
        $password = trim((string) $config->password);

        if ($password === '') {
            return self::MISSING;
        }

        if (CriticalSecrets::isPlaceholder($password)) {
            return self::PLACEHOLDER;
        }

        if (! $config->encryption->shouldEncrypt()) {
            return self::DISABLED;
        }

        return null;
    }

    /**
     * Um backup sem proteção deve ser RECUSADO nesta instalação?
     *
     * Função pura dos dois sinais, para que o teste exercite os dois ramos
     * sem fingir ambiente.
     */
    public static function refusalRequired(bool $production, bool $optOut): bool
    {
        return $production && ! $optOut;
    }

    /**
     * A instalação declarou que aceita backup sem criptografia em produção?
     */
    public static function unencryptedAllowedByOptOut(): bool
    {
        return (bool) config('security.backup.allow_unencrypted_in_production', false);
    }

    /**
     * Frase que explica o problema para quem vai corrigir.
     */
    public static function describe(string $problem): string
    {
        return match ($problem) {
            self::MISSING => 'BACKUP_ARCHIVE_PASSWORD está vazia: o zip do backup (o dump do banco inteiro) sairia SEM criptografia.',
            self::PLACEHOLDER => 'BACKUP_ARCHIVE_PASSWORD tem um valor de exemplo/placeholder, publicado na documentação do kit: o zip do backup sairia protegido por uma senha que qualquer pessoa conhece.',
            self::DISABLED => 'A criptografia do backup está desligada (backup.backup.encryption = none) ou o PHP desta máquina não tem AES na libzip: o zip do backup sairia SEM criptografia mesmo com senha definida.',
            default => 'O zip do backup sairia sem criptografia.',
        };
    }
}
