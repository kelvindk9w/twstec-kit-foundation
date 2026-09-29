<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Middleware\TrustProxies as FrameworkTrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Factory as ViewFactory;
use LogicException;
use Spatie\Backup\Commands\BackupCommand;
use Twstec\Kit\Foundation\Backup\Console\GuardedBackupCommand;
use Twstec\Kit\Foundation\Http\Middleware\TrustHosts;
use Twstec\Kit\Foundation\Http\Middleware\TrustProxies;
use Twstec\Kit\Foundation\Localization\PackageTranslations;
use Twstec\Kit\Foundation\Logging\Middleware\RequestLogging;
use Twstec\Kit\Foundation\Logging\RequestLogChannel;
use Twstec\Kit\Foundation\Security\ApiRateLimit;
use Twstec\Kit\Foundation\Security\Middleware\EdgeRateLimit;
use Twstec\Kit\Foundation\Security\Middleware\SecurityHeaders;
use Twstec\Kit\Foundation\Security\Middleware\SecurityValidation;
use Twstec\Kit\Foundation\Support\Platform;
use Twstec\Kit\Foundation\Support\ProductionHardening;

/**
 * O que a base do kit instala numa aplicação Laravel: configuração padrão,
 * o canal de log `request_log` (se a aplicação não tiver o dela),
 * migrations, views e traduções do e-mail, o singleton da plataforma, a troca
 * do `backup:run`, os limitadores `api` e `sensitive`, as guardas de produção
 * e — o principal — a pilha GLOBAL de middlewares de segurança, na ordem
 * certa. Tudo sozinho: nenhuma proteção depende de a aplicação lembrar de
 * chamar alguma coisa. As duas que se desligam (guardas de produção e
 * limitadores) só se desligam por config explícita, e deixam aviso no log.
 *
 * Os demais providers do pacote (auditoria, e-mail, configurações editáveis)
 * moram nos próprios módulos e são descobertos junto com este.
 */
final class FoundationServiceProvider extends ServiceProvider
{
    /**
     * Pilha global de segurança, da MAIS EXTERNA para a mais interna. Fica na
     * frente de todo o resto da pilha global (a do framework e a de outros
     * pacotes):
     *
     * 0º TrustProxies (quem pode dizer QUEM É O CLIENTE — ver abaixo);
     * 1º SecurityHeaders (até respostas de bloqueio/erro carregam os
     *    headers de segurança — inclusive o 400 de host recusado);
     * 1ºb EdgeRateLimit (TETO de requisições por cliente — IP ou prefixo
     *    IPv6 — para TUDO que chega ao PHP: páginas, Livewire, /admin, /up,
     *    API e rotas inexistentes). Vem depois do TrustProxies (precisa do
     *    IP real) e do SecurityHeaders (o 429 sai com os headers), e ANTES
     *    de todo trabalho caro: host, varredura de ataque sobre o corpo e
     *    INSERT/UPDATE na trilha. Acima do limite o corpo nem é lido;
     * 1ºc TrustHosts (quais valores de `Host` são aceitos — ver abaixo);
     * 2º SecurityValidation (PRIMEIRA validação de payload: rejeita
     *    conteúdo malicioso antes de qualquer outro processamento,
     *    registrando a tentativa);
     * 3º RequestLogging (INICIADA imediato → CONCLUIDA/ERRO no terminate).
     *    Global de propósito: middleware de grupo NÃO executa em rota não
     *    encontrada, e requisições para endpoints inexistentes são exatamente
     *    o sinal de varredura/ataque que precisa ficar registrado. Exclusões
     *    e resumos (assets, health checks, updates Livewire) em
     *    config/security.php.
     *
     * TrustProxies e TrustHosts entram JUNTAS de propósito: declarar proxy
     * confiável é o que faz o `X-Forwarded-Host` (e, atrás de CDN, o próprio
     * `Host`) virar open redirect real — a validação de host é o que tranca o
     * que passava por ali. Toda a regra e a justificativa moram em
     * Http\TrustedProxies e Http\TrustedHosts.
     *
     * TrustProxies É A MAIS EXTERNA DE TODAS. Na posição padrão do framework
     * ela rodaria DEPOIS dos middlewares do kit, e aí o `ip()` que o
     * RequestLogging grava na trilha de auditoria e o que o SecurityValidation
     * registra numa tentativa de ataque ainda seriam o endereço do PROXY. Ela
     * também precisa vir antes do TrustHosts, porque é ela que decide se um
     * `X-Forwarded-Host` entra no host validado, e antes do SecurityHeaders,
     * que só envia HSTS sob HTTPS detectado.
     *
     * TrustHosts vem logo depois do SecurityHeaders (para o 400 sair com os
     * headers) e ANTES de tudo que lê o host — SecurityValidation,
     * RequestLogging, sessão, rotas: a requisição com host forjado para ali.
     *
     * @var list<class-string>
     */
    public const GLOBAL_MIDDLEWARE = [
        TrustProxies::class,
        SecurityHeaders::class,
        EdgeRateLimit::class,
        TrustHosts::class,
        SecurityValidation::class,
        RequestLogging::class,
    ];

    /**
     * Aliases para uso explícito em rotas e grupos. Um alias de mesmo nome
     * declarado pela aplicação prevalece.
     *
     * @var array<string, class-string>
     */
    public const MIDDLEWARE_ALIASES = [
        'security.validation' => SecurityValidation::class,
        'security.headers' => SecurityHeaders::class,
        'request.logging' => RequestLogging::class,
    ];

    /**
     * Arquivos de configuração que o pacote traz com os valores padrão. A
     * aplicação pode publicar a própria cópia (`vendor:publish --tag=
     * foundation-config`); as chaves de primeiro nível dela prevalecem.
     */
    private const CONFIG_FILES = ['security', 'audit', 'settings', 'platform'];

    public function register(): void
    {
        foreach (self::CONFIG_FILES as $name) {
            $this->mergeConfigFrom($this->path("config/{$name}.php"), $name);
        }

        // Canal de log `request_log` (a segunda camada das trilhas), só quando
        // a aplicação não definiu o dela — o da aplicação vence. Ver
        // Logging\RequestLogChannel.
        RequestLogChannel::registerDefault($this->app->make('config'));

        // Configuração centralizada da plataforma (nada hardcoded) — singleton tipado.
        $this->app->singleton(Platform::class, fn (): Platform => Platform::fromConfig());

        // `backup:run` com a regra da criptografia na frente: em produção,
        // backup que sairia sem criptografia é RECUSADO (ver
        // Backup\BackupEncryption). O spatie/laravel-backup resolve o comando
        // pelo container, então trocar a classe aqui cobre o comando manual, o
        // agendamento e o Artisan::call(). Nada disso roda no boot.
        $this->app->bind(BackupCommand::class, GuardedBackupCommand::class);

        $this->registerTranslations();
    }

    public function boot(): void
    {
        $this->registerSecurityMiddleware();
        $this->applyProductionGuards();
        $this->registerRateLimiters();

        // As migrations rodam direto daqui, com os MESMOS nomes de arquivo
        // que tinham quando moravam no aplicativo: um banco que já as rodou
        // não vê nada pendente, e um banco novo as roda na mesma ordem.
        $this->loadMigrationsFrom($this->path('database/migrations'));

        $this->registerViews();
        $this->registerModuleDirective();

        if ($this->app->runningInConsole()) {
            $this->publishes(
                array_combine(
                    array_map(fn (string $name): string => $this->path("config/{$name}.php"), self::CONFIG_FILES),
                    array_map(fn (string $name): string => config_path("{$name}.php"), self::CONFIG_FILES),
                ),
                'foundation-config',
            );
        }
    }

    /**
     * Guardas de produção (ver ProductionHardening): recusa sem APP_KEY
     * utilizável, HTTPS, APP_DEBUG desligado e os avisos dos opt-outs. Fora
     * de produção não fazem nada.
     *
     * Opt-out: `security.production_guards.enabled = false`. Desligar uma
     * guarda de segurança não pode ser silencioso — em produção, aviso a cada
     * boot.
     */
    private function applyProductionGuards(): void
    {
        if (config('security.production_guards.enabled', true) !== false) {
            ProductionHardening::apply($this->app);

            return;
        }

        if ($this->app->isProduction()) {
            Log::warning('SECURITY_PRODUCTION_GUARDS=false: as guardas de produção do twstec/kit-foundation estão DESLIGADAS — sem recusa de boot por APP_KEY ausente ou de fachada, sem HTTPS forçado nas URLs geradas, sem APP_DEBUG forçado para false e sem os avisos dos opt-outs de segurança. Só é seguro se a aplicação aplicar as mesmas guardas por conta própria. Ver Twstec\Kit\Foundation\Support\ProductionHardening.');
        }
    }

    /**
     * Limitadores nomeados da base, usados com `throttle:<nome>`.
     *
     * - `api`: conta pela CHAVE de API (ou pelo tenant, RATE_LIMIT_API_BY)
     *   quando a requisição foi autenticada, e por IP quando não foi; as
     *   falhas de autenticação têm balde próprio por IP. A regra inteira mora
     *   em Security\ApiRateLimit.
     * - `sensitive`: rotas sensíveis (login, códigos de verificação,
     *   recuperação de senha), por usuário ou IP.
     *
     * Um RateLimiter::for de mesmo nome na aplicação (provider que sobe
     * depois deste) substitui o do pacote. Opt-out:
     * `security.rate_limit.define_limiters = false` — em produção, aviso a
     * cada boot.
     */
    private function registerRateLimiters(): void
    {
        if (config('security.rate_limit.define_limiters', true) === false) {
            if ($this->app->isProduction()) {
                Log::warning('RATE_LIMIT_DEFINE_LIMITERS=false: os limitadores `api` e `sensitive` do twstec/kit-foundation NÃO foram registrados. Rotas com throttle:api ou throttle:sensitive ficam sem limite do pacote — só é seguro se a aplicação registrar os próprios limitadores com esses nomes. Ver Twstec\Kit\Foundation\Security\ApiRateLimit.');
            }

            return;
        }

        RateLimiter::for('api', fn (Request $request): Limit => ApiRateLimit::limit($request));

        RateLimiter::for('sensitive', function (Request $request): Limit {
            return Limit::perMinute((int) config('security.rate_limit.sensitive', 5))
                ->by((string) ($request->user()?->getAuthIdentifier() ?: $request->ip()));
        });
    }

    /**
     * Traduções do pacote, sem namespace (`__('security.blocked')`,
     * `__('api.errors.…')`, `__('mail.footer.…')`, `__('audit.…')`).
     *
     * O APLICATIVO VENCE: a regra (a pasta do pacote entra no carregador logo
     * antes da lang/ do aplicativo) mora em Localization\PackageTranslations,
     * a mesma que os outros pacotes do kit usam.
     */
    private function registerTranslations(): void
    {
        PackageTranslations::register($this->app, $this->path('lang'));
    }

    /**
     * A lista de pastas do carregador com a do pacote logo antes da do
     * aplicativo (ou no fim, se a do aplicativo não estiver na lista).
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public static function packageBeforeApplication(array $paths, string $package, string $application): array
    {
        return PackageTranslations::packageBeforeApplication($paths, $package, $application);
    }

    /**
     * Coloca a pilha de segurança na frente da pilha global.
     *
     * Roda quando o kernel HTTP é resolvido — depois de a aplicação montar a
     * pilha dela (bootstrap/app.php) — ou na hora, se ele já foi resolvido.
     * O TrustProxies do framework sai da lista: o do kit é uma subclasse dele
     * que lê a declaração de configuração, e os dois juntos rodariam duas
     * vezes.
     */
    private function registerSecurityMiddleware(): void
    {
        $this->callAfterResolving(HttpKernelContract::class, function (HttpKernelContract $kernel): void {
            if (! $kernel instanceof HttpKernel) {
                throw new LogicException(sprintf(
                    'twstec/kit-foundation precisa de um kernel HTTP que estenda %s para instalar a pilha de segurança; recebeu %s.',
                    HttpKernel::class,
                    $kernel::class,
                ));
            }

            $rest = array_filter(
                $kernel->getGlobalMiddleware(),
                fn (mixed $middleware): bool => $middleware !== FrameworkTrustProxies::class,
            );

            $kernel->setGlobalMiddleware(array_values(array_unique([...self::GLOBAL_MIDDLEWARE, ...$rest], SORT_REGULAR)));
            $kernel->setMiddlewareAliases([...self::MIDDLEWARE_ALIASES, ...$kernel->getMiddlewareAliases()]);
        });
    }

    /**
     * Views do e-mail (o esqueleto `<x-email::layouts.kit>`, os componentes
     * `<x-email::…>` e o texto puro `mail.text.auto`).
     *
     * Os NOMES continuam os de antes: a pasta de views do pacote entra como
     * última opção de busca, então `view('mail.text.auto')` acha o arquivo do
     * pacote — e um arquivo de mesmo nome em resources/views do aplicativo
     * prevalece. O mesmo vale para os componentes `<x-email::…>`: primeiro
     * resources/views/mail do aplicativo (onde moram os corpos dos e-mails,
     * `mail.messages.*`), depois a pasta do pacote. Também ficam disponíveis
     * com o namespace `foundation::`.
     */
    private function registerViews(): void
    {
        $views = $this->path('resources/views');

        $this->loadViewsFrom($views, 'foundation');

        $this->callAfterResolving('view', function (ViewFactory $factory) use ($views): void {
            $factory->addLocation($views);
        });
    }

    /**
     * `@kit('uploads') … @else … @endkit` nas views: o bloco só aparece com o
     * módulo opcional instalado. A pergunta é a mesma de todo o resto do
     * kit — Kit::has() —, para uma tela nunca decidir por conta própria.
     */
    private function registerModuleDirective(): void
    {
        $this->callAfterResolving('blade.compiler', function (BladeCompiler $blade): void {
            $blade->if('kit', static fn (string $module): bool => Kit::has($module));
        });
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__).'/'.$relative;
    }
}
