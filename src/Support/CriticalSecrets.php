<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Support;

use Illuminate\Support\Facades\Log;
use Twstec\Kit\Foundation\Support\Exceptions\MissingApplicationKeyException;

/**
 * A REGRA dos segredos que NÃO podem ser inventados, em um lugar só.
 *
 * PROBLEMA: um starter kit precisa subir na primeira tentativa, e o jeito de
 * conseguir isso é dar valor padrão para tudo. Só que existe uma classe de
 * variável para a qual valor padrão é a própria falha de segurança: as que
 * carregam segredo. Dois sintomas do mesmo bug foram encontrados no kit:
 *
 *   CHAVE GERADA NA SUBIDA — o entrypoint de produção gerava a APP_KEY quando
 *   ela vinha vazia. Como a imagem é a mesma para app, migrate, horizon e
 *   scheduler, cada container ficava com uma chave DIFERENTE; e como a chave
 *   era escrita no `.env` da camada gravável, ela também não sobrevivia ao
 *   restart. Nada disso dava erro: o dado simplesmente deixava de
 *   descriptografar.
 *
 *   SENHA PADRÃO FUNCIONAL — o docker-compose.prod.yml oferecia
 *   `troque-esta-senha` como fallback de POSTGRES_PASSWORD e REDIS_PASSWORD.
 *   Uma senha padrão que FUNCIONA é a mesma armadilha da flag com padrão
 *   ligado: quem não leu o comentário sobe em produção com a senha que está
 *   publicada no repositório, e nada avisa.
 *
 * SOLUÇÃO EM TRÊS CAMADAS, porque as três falham por motivos diferentes:
 *
 *   NO SHELL (docker/php/entrypoint-prod.sh) — ausência da APP_KEY em produção
 *   para a subida com código 78. É a camada que age ANTES de o php-fpm existir.
 *
 *   NO COMPOSE (docker-compose.prod.yml) — os fallbacks funcionais de senha
 *   viraram `${VAR:?...}`: sem a variável, o Compose se recusa a resolver o
 *   arquivo. A senha padrão deixou de ser inventável no ponto onde era
 *   inventada.
 *
 *   AQUI, no boot da aplicação — o que o shell e o compose não conseguem ver:
 *   chave PRESENTE mas de placeholder, e segredo de infraestrutura com valor de
 *   placeholder numa instalação que não subiu pelo compose do kit.
 *
 * -----------------------------------------------------------------------------
 * ONDE A RECUSA VALE, E POR QUÊ NÃO VALE EM TODO LUGAR
 * -----------------------------------------------------------------------------
 * A primeira versão deste guard recusava o boot em produção, ponto. Ela
 * derrubava o `composer install`.
 *
 * O motivo é uma sutileza do Laravel que vale registrar: `config/app.php`
 * resolve `env('APP_ENV', 'production')` — SEM `.env`, a aplicação se considera
 * em PRODUÇÃO. E `composer install` dispara `artisan package:discover` no
 * `post-autoload-dump`, que boota a aplicação. Ou seja: no CI (que instala
 * dependências antes de criar o `.env`), no build da imagem de produção (que
 * nunca tem `.env`, porque o `.dockerignore` o exclui) e no PRIMEIRO
 * `composer install` de quem acabou de clonar o kit, a aplicação bootava
 * "em produção" sem chave — e um guard de recusa larga transformava isso em
 * build vermelho. Fechar o vazamento não pode custar o caminho de entrada do
 * projeto.
 *
 * CRITÉRIO ADOTADO: a recusa vale quando este processo vai SERVIR TRÁFEGO ou
 * PROCESSAR TRABALHO — é onde chave pública ou inconsistente causa dano real e
 * silencioso. Nos demais casos o guard AVISA ALTO e deixa passar.
 *
 *   SERVE TRÁFEGO → qualquer coisa que não seja console (`runningInConsole()`
 *   falso): php-fpm, servidor embutido, Octane. Aqui não há meio-termo — é a
 *   requisição do usuário sendo atendida com o segredo errado.
 *
 *   PROCESSA TRABALHO → a lista `security.secrets.processing_commands`
 *   (`queue:work`, `horizon` e companhia, `schedule:run`). São processos
 *   longos que leem e gravam dado real do mesmo jeito que o HTTP, e são
 *   exatamente os comandos que o compose do kit roda como serviço.
 *
 *   INSTALAÇÃO E MANUTENÇÃO → `package:discover`, `config:cache`,
 *   `vendor:publish`, `about`, `key:generate`, `migrate`, e todo o resto.
 *   AVISO, não recusa.
 *
 * POR QUE A LISTA É DE QUEM PROCESSA, E NÃO DE QUEM É LIBERADO: uma lista de
 * liberados tem o padrão errado. Todo comando de manutenção que o Laravel, o
 * Filament ou o Horizon acrescentarem amanhã no `post-autoload-dump` voltaria a
 * derrubar build até alguém lembrar de incluí-lo — a regressão que acabou de
 * acontecer, repetida. A lista de quem PROCESSA, ao contrário, é fechada e
 * estável: ela não é definida pelas dependências, é definida pelo DEPLOY, e são
 * os nomes que estão escritos no `docker-compose.prod.yml`. O risco que sobra —
 * um comando novo que toque dado criptografado sem estar na lista — é pequeno e
 * tem rede de proteção: com chave ausente o próprio encrypter do Laravel
 * estoura, e com chave de placeholder o aviso está no log de cada boot.
 *
 * ONDE FICA O `migrate`, e por quê: no grupo do AVISO. Ele escreve no banco,
 * mas escreve ESQUEMA — não lê nem grava atributo criptografado, então uma
 * chave errada não corrompe nada ali. E ele é o primeiro container do compose
 * de produção: recusá-lo derrubaria o deploy num ponto em que nada está em
 * risco, enquanto o aviso no log dele é o alerta MAIS PRECOCE que a operação
 * recebe. O deploy segue e para na porta que importa: o container `app`, que
 * serve tráfego, recusa. (Se algum dia existir migration que reescreva dado
 * criptografado, ela precisa da chave certa por mérito próprio — e aí o
 * encrypter é quem recusa.)
 *
 * -----------------------------------------------------------------------------
 * DUAS RESPOSTAS PARA DUAS NATUREZAS DE SEGREDO
 * -----------------------------------------------------------------------------
 * Dentro do grupo que recusa, o critério que separa chave de senha é "existe
 * valor seguro a forçar?":
 *
 *   CHAVE DA APLICAÇÃO → RECUSA. Não há valor seguro a forçar: gerar uma é o
 *   bug, e seguir com a pública é vazar tudo em silêncio.
 *
 *   SEGREDOS DE INFRAESTRUTURA → AVISO alto no log, a cada boot, em qualquer
 *   processo, sem nunca recusar. Não é leniência, é proporcionalidade: se o
 *   Postgres já foi provisionado com aquela senha, derrubar a aplicação não
 *   troca a senha — só troca um problema de segurança por uma indisponibilidade,
 *   mantendo o problema de segurança. O remédio de verdade é a rotação, que é um
 *   procedimento com janela, e o log recorrente é o que impede que a instalação
 *   esqueça que o deve. A causa raiz (o fallback funcional) já está fechada no
 *   compose.
 *
 * NADA HARDCODED: o vocabulário de placeholders e a lista de comandos que
 * processam trabalho são configuráveis em `security.secrets`. Já a LISTA de
 * segredos inspecionados mora aqui, em código documentado, do mesmo jeito que a
 * lógica do SafeRedirect: é estrutura do kit, não ajuste de operação.
 */
final class CriticalSecrets
{
    /**
     * Segredos de infraestrutura inspecionados: nome da variável de ambiente
     * (é por ele que o operador conhece o valor) => caminho de configuração.
     *
     * Lidos de configuração, e não de `env()` direto, para continuar
     * funcionando com o config cacheado do deploy.
     *
     * @var array<string, string>
     */
    private const INFRASTRUCTURE_SECRETS = [
        'DB_PASSWORD' => 'database.connections.pgsql.password',
        'REDIS_PASSWORD' => 'database.redis.default.password',
        'BACKUP_ARCHIVE_PASSWORD' => 'backup.backup.password',
        'API_KEYS_HASH_PEPPER' => 'api_keys.hash_pepper',
        'AWS_SECRET_ACCESS_KEY' => 'filesystems.disks.s3.secret',
    ];

    /**
     * Portão chamado no boot, em produção.
     *
     * Só faz a fiação: lê os dois sinais do processo atual, pergunta ao
     * contrato se a recusa se aplica e aplica o resultado.
     *
     * @throws MissingApplicationKeyException
     */
    public static function guard(): void
    {
        self::apply(self::refusalRequired(
            app()->runningInConsole(),
            self::currentCommand(),
        ));
    }

    /**
     * O CONTRATO, como função pura dos dois sinais do processo: este processo
     * vai servir tráfego ou processar trabalho?
     *
     * É função pura de propósito — é a regra que decide entre "recusa" e
     * "avisa", e ela precisa ser verificável nos dois ramos sem que o teste
     * tenha de fingir ser php-fpm.
     *
     * @param  bool  $runningInConsole  Falso = está atendendo requisição.
     * @param  string|null  $command  Comando artisan em execução, quando houver.
     */
    public static function refusalRequired(bool $runningInConsole, ?string $command): bool
    {
        // Atender requisição com o segredo errado não tem meio-termo.
        if (! $runningInConsole) {
            return true;
        }

        return self::isProcessingCommand($command);
    }

    /**
     * Aplica o resultado do contrato: recusa ou avisa sobre a chave, e sempre
     * avisa sobre segredo de infraestrutura de fachada.
     *
     * @param  bool  $refuse  Vindo de refusalRequired().
     *
     * @throws MissingApplicationKeyException
     */
    public static function apply(bool $refuse): void
    {
        $failure = self::applicationKeyFailure();

        if ($failure !== null) {
            if ($refuse) {
                throw $failure;
            }

            // Tolerado porque este processo não serve ninguém: um comando de
            // instalação ou manutenção não expõe dado a usuário nenhum. O aviso
            // existe para que o build/deploy mostre o problema ANTES de alguém
            // descobrir pelo container que não sobe.
            self::announce(
                'Chave de aplicação inutilizável em APP_ENV=production, tolerada apenas porque '
                .'este processo não serve tráfego nem processa trabalho. O processo que servir '
                .'(php-fpm, queue:work, horizon, schedule:run) vai RECUSAR subir. '
                .$failure->getMessage()
            );
        }

        $withPlaceholder = self::infrastructureSecretsWithPlaceholder();

        if ($withPlaceholder !== []) {
            self::announce(
                'Segredos com valor de exemplo/placeholder em APP_ENV=production: '
                .implode(', ', $withPlaceholder)
                .'. Estes valores estão publicados na documentação do kit — quem alcançar a rede '
                .'interna já os conhece. A aplicação NÃO foi recusada porque derrubá-la não troca '
                .'a senha: o remédio é rotacionar o segredo no serviço correspondente e entregar o '
                .'novo valor pelo ambiente. Este aviso volta a cada boot até que isso aconteça.'
            );
        }
    }

    /**
     * O comando é um dos que processam trabalho de verdade?
     *
     * A lista aceita `*` no fim, para que uma instalação com worker próprio
     * (`meu-consumidor:*`) o declare sem tocar em código.
     */
    public static function isProcessingCommand(?string $command): bool
    {
        if ($command === null || $command === '') {
            return false;
        }

        foreach (self::processingCommands() as $pattern) {
            if (str_ends_with($pattern, '*')) {
                if (str_starts_with($command, rtrim($pattern, '*'))) {
                    return true;
                }

                continue;
            }

            if ($command === $pattern) {
                return true;
            }
        }

        return false;
    }

    /**
     * Comandos que fazem este processo "processar trabalho".
     *
     * @return list<string>
     */
    public static function processingCommands(): array
    {
        /** @var list<string> $commands */
        $commands = (array) config('security.secrets.processing_commands', []);

        return array_values(array_filter(array_map(
            fn (mixed $command): string => trim((string) $command),
            $commands,
        )));
    }

    /**
     * A chave de aplicação é utilizável nesta instalação?
     *
     * Utilizável = existe e não é um valor conhecido. Note que ela pode ser
     * utilizável e AINDA ASSIM estar errada (chave própria, mas diferente da
     * que criptografou o dado): isso nenhum guard descobre de fora, e é por
     * isso que a documentação insiste em APP_PREVIOUS_KEYS.
     */
    public static function applicationKeyUsable(): bool
    {
        return self::applicationKeyFailure() === null;
    }

    /**
     * Nomes das variáveis de ambiente cujos segredos estão com valor de
     * placeholder. Segredo VAZIO não entra: ausência é uma decisão possível
     * (não há bucket R2, não há backup configurado), enquanto um valor público
     * é sempre um esquecimento.
     *
     * @return list<string>
     */
    public static function infrastructureSecretsWithPlaceholder(): array
    {
        $found = [];

        foreach (self::INFRASTRUCTURE_SECRETS as $variable => $path) {
            $value = config($path);

            if (is_string($value) && $value !== '' && self::isPlaceholder($value)) {
                $found[] = $variable;
            }
        }

        return $found;
    }

    /**
     * A recusa que a chave atual merece, ou nulo quando ela é utilizável.
     */
    private static function applicationKeyFailure(): ?MissingApplicationKeyException
    {
        $key = self::applicationKey();

        if ($key === '') {
            return MissingApplicationKeyException::missing();
        }

        if (self::isPlaceholder($key)) {
            return MissingApplicationKeyException::placeholder();
        }

        return null;
    }

    /**
     * Comando artisan em execução, quando há um.
     *
     * A leitura é do `argv` porque no boot do provider nenhum comando foi
     * resolvido ainda: o container só descobre qual comando roda depois que
     * todos os providers subiram. `argv[1]` é a posição do nome do comando em
     * `php artisan <comando> ...`.
     *
     * Público porque outras regras de boot usam o mesmo sinal para saber se o
     * processo atual é um dos que processam trabalho (ver o aviso do mailer que
     * não entrega, no AppServiceProvider).
     */
    public static function currentCommand(): ?string
    {
        /** @var list<string> $arguments */
        $arguments = (array) ($_SERVER['argv'] ?? []);

        return isset($arguments[1]) ? (string) $arguments[1] : null;
    }

    /**
     * Aviso em voz alta.
     *
     * Vai para o log e, quando há console, TAMBÉM para o stderr: um aviso que
     * só existe em `storage/logs` é invisível para quem está olhando a saída de
     * um `composer install` ou de um deploy, e é justamente essa pessoa que
     * pode corrigir. O log é best-effort porque durante um build a pasta de
     * logs pode não ser gravável — e um aviso NUNCA pode ser o motivo de o
     * build falhar, que é exatamente o erro que esta versão corrige.
     */
    private static function announce(string $message): void
    {
        try {
            Log::warning($message);
        } catch (\Throwable) {
            // Sem log disponível, o stderr abaixo é o canal que sobra.
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests() && defined('STDERR')) {
            fwrite(STDERR, '[segredos] '.$message.PHP_EOL);
        }
    }

    /**
     * Chave da aplicação como string normalizada (vazia quando ausente).
     *
     * `base64:` sozinho conta como ausente: é o que sobra quando alguém apaga
     * o valor e deixa o prefixo.
     */
    private static function applicationKey(): string
    {
        $key = trim((string) config('app.key'));

        return $key === 'base64:' ? '' : $key;
    }

    /**
     * O valor é um segredo de fachada?
     *
     * Duas formas de reconhecer, porque placeholder aparece de dois jeitos:
     *
     *   VOCABULÁRIO — o valor está na lista de
     *   `security.secrets.placeholders` (`troque-esta-senha` e companhia).
     *   Comparação sem diferenciar maiúsculas e ignorando o prefixo `base64:`,
     *   que só diz como o valor foi codificado, não o que ele é.
     *
     *   DEGENERADO — o valor decodifica para uma sequência de bytes TODOS
     *   IGUAIS (`base64:AAAA…`, o "preenchi com qualquer coisa"). É o
     *   placeholder que não tem nome para entrar em lista nenhuma, e a chance
     *   de uma chave aleatória de verdade cair aqui é desprezível.
     */
    public static function isPlaceholder(string $value): bool
    {
        $bare = str_starts_with($value, 'base64:')
            ? substr($value, strlen('base64:'))
            : $value;

        /** @var list<string> $vocabulary */
        $vocabulary = (array) config('security.secrets.placeholders', []);

        foreach ($vocabulary as $placeholder) {
            $placeholder = trim((string) $placeholder);

            if ($placeholder === '') {
                continue;
            }

            if (strcasecmp($bare, $placeholder) === 0 || strcasecmp($value, $placeholder) === 0) {
                return true;
            }
        }

        return self::isDegenerate($bare);
    }

    /**
     * Sequência de bytes todos iguais (depois de decodificar o base64, quando
     * o valor é base64 válido). Valores curtíssimos não são avaliados aqui:
     * eles ou estão no vocabulário, ou são recusados pelo próprio Laravel por
     * não terem o tamanho da cifra.
     */
    private static function isDegenerate(string $bare): bool
    {
        $decoded = base64_decode($bare, true);
        $bytes = is_string($decoded) && $decoded !== '' ? $decoded : $bare;

        if (strlen($bytes) < 8) {
            return false;
        }

        return count(array_unique(str_split($bytes))) === 1;
    }
}
