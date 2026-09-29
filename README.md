# twstec/kit-foundation

> **Parte do [TWS Laravel Starter Kit](https://github.com/kelvindk9w/tws-laravel-starter-kit).** O código, as issues e os
> pull requests ficam no monorepo
> [kelvindk9w/tws-laravel-starter-kit](https://github.com/kelvindk9w/tws-laravel-starter-kit) (pasta `packages/foundation`); este
> repositório é o espelho só-leitura publicado a cada versão.
> Documentação: [docs/](https://github.com/kelvindk9w/tws-laravel-starter-kit/tree/desenvolvimento/docs) · Segurança:
> [SECURITY.md](SECURITY.md) · Licença: MIT ([LICENSE](LICENSE)).

A base de segurança e infraestrutura do **TWS Laravel Starter Kit**, como pacote
Laravel. É a camada de baixo do kit: não conhece autenticação, contas, uploads
nem interface — e um teste de arquitetura na suíte do pacote garante isso.

- **Requisitos:** PHP 8.4+, Laravel 13, extensões `bcmath` e `mbstring`,
  `spatie/laravel-backup` 10 (instalado junto).
- **Licença:** MIT.

## O que o pacote traz

| Módulo | O que faz |
| --- | --- |
| `Security` | Filtro de ataques (XSS, SQLi, null byte, path traversal) com modos observar/bloquear e teto de inspeção; limite de requisições na borda (por IP ou prefixo IPv6) e da API; cabeçalhos de segurança e CSP; allowlist de IP do `/admin` |
| `Http` | Proxies confiáveis e hosts confiáveis (lidos de configuração), redirecionamento seguro, envelope de erro da API, recursos base de API e o health check |
| `Logging` | Trilha de requisições (`request_logs`, só-acréscimo), correlation id, redação LGPD de payload, mascaramento de cartão nos logs, amostragem de tráfego de varredura |
| `Audit` | Trilha de auditoria de ações (`audit_events`, só-acréscimo, com gatilho no banco), escopo de auditoria e o comando `audit:prune` |
| `Mail` | Base dos e-mails (`KitMailable`, `KitMailMessage`), o layout e os componentes `<x-email::…>`, texto puro automático, galeria `/mail-preview` (registro e controller) e a recusa dos transportes que não entregam em produção |
| `Settings` | Configurações editáveis no banco (`settings`) por cima do `.env`, com lista fechada de chaves, e o helper `setting()` |
| `Localization` | Idioma da requisição (cookie, preferência da conta, padrão da plataforma) e a troca de idioma |
| `Support` | `Platform` (a configuração da plataforma, tipada) e o helper `platform()`; guarda de segredos críticos; guardas de produção |
| `Backup` | `backup:run` que recusa backup sem criptografia em produção |
| `Money`, `Identifiers` | Dinheiro em centavos (formatação e cast) e identificadores públicos (UUID nas rotas, código público) |
| `Kit` (raiz) | Quais módulos do kit estão instalados — o ponto ÚNICO de detecção: `Kit::has('accounts')`, a diretiva `@kit('uploads') … @else … @endkit` nas views, as dependências entre módulos (`uploads` exige `accounts`) e, só para testes, `Kit::pretendAbsent()`. Instalado = registrado pelo Composer **e** com o provider carregável. Ver [docs/instalacao.md](https://github.com/kelvindk9w/tws-laravel-starter-kit/blob/desenvolvimento/docs/instalacao.md) |

## Instalação

Pelo Packagist:

```bash
composer require "twstec/kit-foundation:^2.0@beta"   # durante o beta; na 2.0.0 estável, ^2.0
```

Durante o beta, cada pacote do kit que você requerer leva o `@beta` (ou o
projeto declara `"minimum-stability": "beta"` com `"prefer-stable": true`) —
ver [docs/instalacao.md](https://github.com/kelvindk9w/tws-laravel-starter-kit/blob/desenvolvimento/docs/instalacao.md).

**No monorepo** (desenvolvimento do próprio kit), o starter instala o pacote por
*path repository*. No `composer.json` do aplicativo:

```json
"repositories": [
    {
        "type": "path",
        "url": "../../packages/foundation",
        "options": {
            "versions": { "twstec/kit-foundation": "2.x-dev" },
            "reference": "config"
        }
    }
],
"require": {
    "twstec/kit-foundation": "2.x-dev"
}
```

Os providers são descobertos automaticamente (`extra.laravel.providers`):
`FoundationServiceProvider`, e os dos módulos de auditoria, e-mail e
configurações editáveis. Em seguida:

```bash
php artisan vendor:publish --tag=foundation-config   # opcional: security, audit, settings, platform
php artisan migrate
```

## O que ele instala sozinho

- **Pilha global de segurança, na frente de toda a pilha global e nesta ordem:**
  `TrustProxies` → `SecurityHeaders` → `EdgeRateLimit` → `TrustHosts` →
  `SecurityValidation` → `RequestLogging`. O `TrustProxies` do framework sai da
  lista (o do pacote é uma subclasse dele que lê a configuração). O porquê de
  cada posição está no docblock de `FoundationServiceProvider::GLOBAL_MIDDLEWARE`.
- **Aliases de middleware:** `security.validation`, `security.headers`,
  `request.logging` (um alias de mesmo nome declarado pelo aplicativo
  prevalece).
- **Configuração padrão** (`mergeConfigFrom`) de `security`, `audit`,
  `settings` e `platform`. O aplicativo pode publicar a própria cópia
  (`--tag=foundation-config`); as chaves de primeiro nível dela prevalecem —
  é o arranjo de todo pacote Laravel. O starter mantém as quatro publicadas.
- **Migrations** de `request_logs`, `settings` e `audit_events`, rodadas direto
  do pacote, com os **mesmos nomes de arquivo** que tinham no aplicativo na
  1.x: um banco que já as rodou não vê nada pendente, e um banco novo as roda
  na mesma ordem. Não publique essas migrations.
- **Views do e-mail** com os nomes de sempre: `<x-email::layouts.kit>`,
  `<x-email::button>` e os demais componentes, e `mail.text.auto`. Um arquivo
  de mesmo nome em `resources/views/mail/` do aplicativo prevalece. Também
  respondem pelo namespace `foundation::` (ex.: `foundation::mail.layouts.kit`).
- **Traduções** (pt-BR, en, es) com as chaves de sempre, sem namespace:
  `security.*`, `api.errors.*`, `mail.footer.*` e `audit.*`. **O aplicativo
  vence:** a pasta do pacote entra no carregador logo ANTES do `lang/` do
  aplicativo, então numa mesma chave vale o texto do aplicativo, e o pacote só
  preenche o que ele não definiu — em qualquer grupo, nos três idiomas e no
  idioma de reserva. Para trocar o rodapé dos e-mails, basta pôr
  `footer.transactional` no `lang/pt_BR/mail.php` do aplicativo. (Não se usa
  namespace `foundation::` porque ele mudaria as chaves que o código e os
  aplicativos já chamam.)
- **Guardas de produção** (`ProductionHardening`), aplicadas no boot do
  pacote, sozinhas: em `APP_ENV=production` recusa subir sem `APP_KEY`
  utilizável (nos processos que servem tráfego ou processam trabalho), força
  HTTPS nas URLs geradas, desliga `APP_DEBUG` e registra os avisos dos
  opt-outs de segurança. Opt-out: `SECURITY_PRODUCTION_GUARDS=false`
  (`security.production_guards.enabled`) — em produção, aviso no log a cada boot.
- **Limitadores nomeados** `api` (por chave de API ou tenant, com balde
  próprio para falha de autenticação por IP) e `sensitive` (login, códigos,
  recuperação de senha). Um `RateLimiter::for` de mesmo nome no aplicativo
  substitui o do pacote. Opt-out: `RATE_LIMIT_DEFINE_LIMITERS=false`
  (`security.rate_limit.define_limiters`) — em produção, aviso no log a cada boot.
- **Recusas que já moram nos módulos:** `backup:run` sem criptografia em
  produção é recusado, e os transportes de e-mail `log`/`array` recusam enviar
  em produção.
- **Canal de log `request_log`** — a segunda camada das trilhas (JSON
  diário em `storage/logs/request-*.log`, com os cartões mascarados, nível em
  `LOG_LEVEL` e retenção em `REQUEST_LOG_DAYS`), onde vão `request.*`,
  `security.*`, `request.throttled` e `audit.*`. É registrado **só se o
  aplicativo não tiver** um `request_log` no `config/logging.php`: o do
  aplicativo vence. Sem isso, essas linhas cairiam no logger de emergência.
- **Singleton da plataforma** (`platform()`) e o helper `setting()`.
- **Comando** `audit:prune`.

## O que o aplicativo liga

Nenhuma proteção depende de o aplicativo chamar algo. Ficam na composição do
aplicativo só as peças que são decisão dele — e que, registradas pelo pacote,
mudariam ordem ou efeito do que o starter já faz:

| Onde | O quê | Por que não no pacote |
| --- | --- | --- |
| `bootstrap/app.php` | `SetLocale` no grupo `web` | Tem de vir antes do middleware de status da conta (auth); o pacote só conseguiria anexar ao fim do grupo, depois dele. |
| `bootstrap/app.php` | Envelope de erro da API (`ApiErrorRenderer`) para `api/*` e o `report` que guarda a mensagem da exceção para o `RequestLogging` | É o contrato de erro da API do aplicativo; registrado pelo pacote, entraria em outra posição na fila de callbacks de exceção. |
| `routes/` | Health check (`HealthController`), troca de idioma (`LocaleController`) e a galeria `/mail-preview` (`MailPreviewController` + a view `mail.preview`, que é da interface) | Endereços e middlewares de rota são do aplicativo. |
| `routes/console.php` | Agendamento de `audit:prune` e de `backup:run`/`backup:clean`/`backup:monitor` | Registrado pelo pacote, mudaria a ordem dos eventos do agendador. |
| um provider do aplicativo | `RateLimitSubjectResolver`: quem é o cliente numa requisição autenticada da API | Depende do modelo de chaves/contas do aplicativo; sem ele, o limitador `api` conta por IP. |

## Nomes antigos → nomes novos

Na 1.x estas classes moravam no aplicativo, em `App\Core\<Módulo>\…`. Na 2.0
são deste pacote, em `Twstec\Kit\Foundation\<Módulo>\…` — o resto do nome não
muda (ex.: `App\Core\Security\Middleware\SecurityValidation` →
`Twstec\Kit\Foundation\Security\Middleware\SecurityValidation`).

| Na 1.x | Na 2.0 |
| --- | --- |
| `App\Core\Identifiers\…` | `Twstec\Kit\Foundation\Identifiers\…` |
| `App\Core\Money\…` | `Twstec\Kit\Foundation\Money\…` |
| `App\Core\Http\…` | `Twstec\Kit\Foundation\Http\…` |
| `App\Core\Security\…` | `Twstec\Kit\Foundation\Security\…` |
| `App\Core\Logging\…` | `Twstec\Kit\Foundation\Logging\…` |
| `App\Core\Localization\…` | `Twstec\Kit\Foundation\Localization\…` |
| `App\Core\Settings\…` | `Twstec\Kit\Foundation\Settings\…` |
| `App\Core\Mail\…` | `Twstec\Kit\Foundation\Mail\…` |
| `App\Core\Support\…` | `Twstec\Kit\Foundation\Support\…` |
| `App\Core\Backup\…` | `Twstec\Kit\Foundation\Backup\…` |
| `App\Core\Audit\…` | `Twstec\Kit\Foundation\Audit\…` |
| Providers `AuditServiceProvider`, `MailServiceProvider`, `SettingsServiceProvider` em `bootstrap/providers.php` | Descobertos automaticamente pelo pacote — tire-os de `bootstrap/providers.php` |
| Pilha global de segurança e os aliases `security.*`/`request.logging` em `bootstrap/app.php` | Instalados pelo `FoundationServiceProvider` — tire-os de `bootstrap/app.php` |
| Bloco de produção no `AppServiceProvider` (segredos, HTTPS, `APP_DEBUG`, avisos) | Aplicado pelo pacote sozinho — tire-o do `AppServiceProvider` (chamar de novo duplicaria os avisos) |
| `RateLimiter::for('api' / 'sensitive')` no `AppServiceProvider` | Registrados pelo pacote — tire-os do `AppServiceProvider` |
| `app/Core/Support/helpers.php` e `app/Core/Settings/helpers.php` no `autoload.files` | Carregados pelo pacote — tire-os do `composer.json` |
| Tradução `admin.audit.prune_disabled` / `admin.audit.pruned` | `audit.prune_disabled` / `audit.pruned` (do pacote) |

**Compatibilidade por uma versão.** Os nomes antigos `App\Core\<Módulo>\…` dos
onze módulos acima continuam resolvendo, como apelidos das classes novas
(`src/Compat/legacy-aliases.php`): é a mesma classe, então `instanceof` e type
hints aceitam os dois nomes. Isso protege o que está gravado fora do código —
payload de fila serializado antes da atualização, config publicada e
migrations antigas que ainda usam o nome antigo. O apelido só é criado quando
alguém pede o nome antigo e o aplicativo não tem mais o arquivo em
`app/Core/<Módulo>`. **Os apelidos saem na 3.0**: troque os `use` do seu código.

## Testes

A suíte do pacote é isolada do aplicativo (Pest + Orchestra Testbench):

```bash
composer update
vendor/bin/pest
vendor/bin/pint --test
```

Ela cobre as peças que não dependem de rotas e telas (filtro de ataques,
redação, balde de cliente, dinheiro, sanitização), a ordem da pilha de
segurança, os apelidos de compatibilidade, a fiação do provider (config,
migrations, views, traduções — com o aplicativo vencendo o pacote), as guardas
de produção numa aplicação limpa (só framework + pacote, em `APP_ENV=production`)
e a arquitetura do pacote. Os testes de ponta a
ponta dessas peças — com as rotas, o painel e o banco do aplicativo — ficam na
suíte do starter.
