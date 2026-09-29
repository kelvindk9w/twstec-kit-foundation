<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation;

use Composer\InstalledVersions;
use InvalidArgumentException;

/**
 * Quais MÓDULOS do kit estão instalados nesta aplicação — o ponto ÚNICO de
 * detecção.
 *
 * O kit é feito de pacotes: `foundation` e `auth` vêm sempre; `accounts`
 * (contas, projetos, chaves e a API), `uploads` (upload seguro e foto de
 * perfil) e `admin` (o painel /admin) são OPCIONAIS — quem cria o projeto
 * escolhe (`php artisan tws:install`) e quem já tem um aplicativo instala só
 * os que quiser. Todo lugar que mostra, registra ou chama algo de um módulo
 * opcional pergunta AQUI (`Kit::has('accounts')`), nunca por conta própria:
 * uma tela, uma rota ou um menu de módulo ausente simplesmente não existe.
 *
 * INSTALADO = o Composer o registrou (InstalledVersions, o mesmo registro do
 * `vendor/composer/installed.php`) E o provider dele pode ser carregado. As
 * duas coisas, porque cada uma sozinha engana: um pacote apagado do vendor
 * sem passar pelo Composer ainda consta no registro, e uma classe carregável
 * não prova que o pacote foi instalado (pode ser um arquivo esquecido).
 *
 * Os nomes das classes das camadas de cima aparecem só como TEXTO (esta é a
 * base do kit: ela não usa código de quem depende dela).
 *
 * TESTES: pretendAbsent() faz de conta que um módulo instalado não está —
 * para provar, na suíte de um aplicativo completo, que as telas somem e as
 * rotas não são registradas. Não descarrega classe nenhuma: a prova de que o
 * aplicativo SOBE sem o pacote é a suíte rodando sem ele (o CI testa as
 * combinações). flushFakes() desfaz.
 */
final class Kit
{
    /**
     * Módulo => pacote do Composer e o provider que a descoberta registra.
     *
     * @var array<string, array{package: string, provider: string}>
     */
    public const MODULES = [
        'foundation' => ['package' => 'twstec/kit-foundation', 'provider' => 'Twstec\\Kit\\Foundation\\FoundationServiceProvider'],
        'auth' => ['package' => 'twstec/kit-auth', 'provider' => 'Twstec\\Kit\\Auth\\Providers\\AuthServiceProvider'],
        'accounts' => ['package' => 'twstec/kit-accounts', 'provider' => 'Twstec\\Kit\\Accounts\\AccountsServiceProvider'],
        'uploads' => ['package' => 'twstec/kit-uploads', 'provider' => 'Twstec\\Kit\\Uploads\\UploadsServiceProvider'],
        'admin' => ['package' => 'twstec/kit-admin', 'provider' => 'Twstec\\Kit\\Admin\\AdminServiceProvider'],
    ];

    /**
     * Os que vêm sempre: o resto do kit depende deles.
     *
     * @var list<string>
     */
    public const REQUIRED = ['foundation', 'auth'];

    /**
     * Os que quem instala escolhe, um a um.
     *
     * @var list<string>
     */
    public const OPTIONAL = ['accounts', 'uploads', 'admin'];

    /**
     * Módulo opcional => os opcionais de que ele precisa. O upload pertence a
     * uma CONTA (dono, isolamento, exclusão junto com a conta), por isso
     * `uploads` exige `accounts`. O `admin` se adapta ao que estiver
     * instalado: sem `accounts`, não mostra contas, chaves nem projetos; sem
     * `uploads`, não mostra uploads nem o campo de foto.
     *
     * @var array<string, list<string>>
     */
    public const DEPENDS_ON = [
        'accounts' => [],
        'uploads' => ['accounts'],
        'admin' => [],
    ];

    /**
     * Módulos que a suíte manda fingir ausentes (pretendAbsent()).
     *
     * @var array<string, true>
     */
    private static array $absent = [];

    /**
     * Resultado da detecção por módulo (o registro do Composer não muda
     * durante o processo).
     *
     * @var array<string, bool>
     */
    private static array $detected = [];

    /**
     * O módulo está instalado nesta aplicação?
     */
    public static function has(string $module): bool
    {
        $definition = self::definition($module);

        if (isset(self::$absent[$module])) {
            return false;
        }

        return self::$detected[$module] ??= self::packageInstalled($definition['package'])
            && class_exists($definition['provider']);
    }

    /**
     * Os módulos instalados, na ordem de MODULES.
     *
     * @return list<string>
     */
    public static function installed(): array
    {
        return array_values(array_filter(array_keys(self::MODULES), self::has(...)));
    }

    /**
     * Os módulos OPCIONAIS instalados.
     *
     * @return list<string>
     */
    public static function installedOptional(): array
    {
        return array_values(array_filter(self::OPTIONAL, self::has(...)));
    }

    /**
     * O nome do pacote do Composer de um módulo.
     */
    public static function package(string $module): string
    {
        return self::definition($module)['package'];
    }

    /**
     * Os módulos opcionais que faltam numa escolha para cada módulo escolhido
     * funcionar (ex.: `uploads` sem `accounts`). Módulo => os que faltam.
     *
     * @param  list<string>  $modules
     * @return array<string, list<string>>
     */
    public static function missingDependencies(array $modules): array
    {
        $missing = [];

        foreach ($modules as $module) {
            self::definition($module);

            $faltam = array_values(array_diff(self::DEPENDS_ON[$module] ?? [], $modules));

            if ($faltam !== []) {
                $missing[$module] = $faltam;
            }
        }

        return $missing;
    }

    /**
     * SÓ PARA TESTES: faz de conta que estes módulos não estão instalados.
     * `foundation` e `auth` não podem faltar.
     */
    public static function pretendAbsent(string ...$modules): void
    {
        foreach ($modules as $module) {
            self::definition($module);

            if (in_array($module, self::REQUIRED, true)) {
                throw new InvalidArgumentException("O módulo [{$module}] é obrigatório: não pode ser dado como ausente.");
            }

            self::$absent[$module] = true;
        }
    }

    /**
     * Desfaz pretendAbsent() e esquece o que já foi detectado.
     */
    public static function flushFakes(): void
    {
        self::$absent = [];
        self::$detected = [];
    }

    /**
     * @return array{package: string, provider: string}
     */
    private static function definition(string $module): array
    {
        return self::MODULES[$module]
            ?? throw new InvalidArgumentException("Módulo do kit desconhecido: [{$module}]. Conhecidos: ".implode(', ', array_keys(self::MODULES)).'.');
    }

    private static function packageInstalled(string $package): bool
    {
        // O pacote RAIZ também consta do registro: na suíte do próprio pacote
        // (Testbench), ele é o projeto, não uma dependência.
        return InstalledVersions::isInstalled($package);
    }
}
