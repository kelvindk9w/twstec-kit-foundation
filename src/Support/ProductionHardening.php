<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Support;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Twstec\Kit\Foundation\Http\TrustedProxies;
use Twstec\Kit\Foundation\Mail\NonDeliveringMailers;
use Twstec\Kit\Foundation\Security\AdminIpAllowlist;

/**
 * As guardas de PRODUÇÃO da base do kit, numa ordem só: segredo crítico
 * (recusa o boot sem chave), HTTPS nas URLs geradas, APP_DEBUG forçado para
 * false e os avisos de boot dos opt-outs de segurança (allowlist do /admin,
 * proxies confiáveis, e-mail que não entrega).
 *
 * Quem chama é o boot do FoundationServiceProvider, sozinho — a proteção não
 * depende de a aplicação lembrar de nada. Opt-out só por config explícita
 * (`security.production_guards.enabled`), com aviso no log em produção.
 * Precisa rodar UMA vez por boot (cada aviso sai uma vez): a aplicação não a
 * chama de novo.
 * Fora de produção, não faz nada.
 */
final class ProductionHardening
{
    public static function apply(Application $app): void
    {
        // HTTPS forçado em produção. O redirect 80→443 e o
        // HSTS na borda são do nginx; aqui garantimos que TODA URL gerada
        // pela aplicação (e-mails, webhooks, links assinados) saia em https.
        if ($app->isProduction()) {
            // Segredos que não podem ser inventados (ver CriticalSecrets). A
            // chave da aplicação RECUSA o boot quando este processo vai servir
            // tráfego ou processar trabalho, e AVISA em voz alta nos comandos
            // de instalação e manutenção — sem `.env`, o Laravel resolve
            // APP_ENV como `production`, e uma recusa larga derrubaria o
            // `composer install`. Segredo de infraestrutura com valor de
            // fachada sempre avisa, nunca recusa. Toda essa decisão mora no
            // guard, não aqui.
            CriticalSecrets::guard();

            URL::forceHttps();

            // APP_DEBUG em produção é vazamento de configuração, caminho de
            // servidor e trecho de código na tela de erro. O .env.example
            // entrega APP_DEBUG=true (é o arquivo de DESENVOLVIMENTO, e debug
            // desligado em dev só faz a pessoa ligar de volta), então o caminho
            // normal — copiar o exemplo e trocar o APP_ENV — chegaria em
            // produção com debug ligado.
            //
            // Aqui ele é FORÇADO a desligar, em vez de a aplicação recusar
            // subir: recusar o boot transformaria uma configuração errada num
            // site fora do ar, e o objetivo é fechar o vazamento, não derrubar
            // o deploy. O aviso no log é o que permite descobrir e corrigir a
            // origem. (O docker-compose.prod.yml já injeta APP_DEBUG=false;
            // isto cobre quem não sobe pelo compose do kit.)
            if (config('app.debug') === true) {
                config(['app.debug' => false]);

                Log::warning('APP_DEBUG estava ligado em APP_ENV=production e foi forçado para false. Corrija o ambiente: em produção o debug expõe configuração, caminhos do servidor e trechos de código nas telas de erro.');
            }

            // Opt-out da barreira de ORIGEM das superfícies administrativas
            // (ADMIN_ALLOW_ANY_IP, ou faixa universal escrita na própria
            // allowlist). Um opt-out de segurança que ninguém vê deixa de ser
            // decisão e volta a ser esquecimento: sem allowlist, o /admin e o
            // /horizon ficam com UMA barreira só (is_admin + conta ativa), e
            // quem decidiu isso tem de reencontrar a decisão no log.
            //
            // Note que NÃO existe aviso de boot para o caso da allowlist
            // AUSENTE: sem `.env`, o Laravel resolve APP_ENV como `production`,
            // então um aviso ali sairia em todo `composer install` do CI, de
            // todo build de imagem e de todo primeiro clone — ruído que ensina
            // a ignorar avisos. Esse caso quem relata é o próprio middleware,
            // no instante em que recusa uma requisição real, onde a mensagem é
            // sempre verdadeira e sempre acionável.
            if (AdminIpAllowlist::anyIpAllowedInProductionByOptOut()) {
                Log::warning('A allowlist de IP do /admin e do /horizon está DESLIGADA por decisão explícita (ADMIN_ALLOW_ANY_IP, ou faixa universal na própria lista): as superfícies administrativas aceitam qualquer origem e contam apenas com is_admin + conta ativa. Isto só é seguro se a restrição de origem estiver na borda (VPN, Cloudflare Access, WAF, regra de firewall). Ver Twstec\Kit\Foundation\Security\AdminIpAllowlist.');
            }

            // Opt-out de QUEM PODE DIZER QUEM É O CLIENTE (TRUSTED_PROXIES=*).
            // Mesmo princípio: confiar em qualquer origem para reescrever o IP
            // deixa a allowlist do admin, o rate limiting e a trilha de
            // auditoria à mercê de um header que o cliente escolhe. Existe
            // instalação em que é a resposta honesta (origem fechada por
            // firewall no ASN da CDN), e é por isso que é opt-out e não recusa —
            // mas ele tem de ser reencontrável no log.
            if (TrustedProxies::trustsEverythingInProduction()) {
                Log::warning('TRUSTED_PROXIES=* está declarado: a aplicação obedece X-Forwarded-For de QUALQUER origem, então o IP que a allowlist do /admin compara, o que agrupa o rate limiting e o que a trilha de auditoria grava são o que o cliente disser. Isto só é seguro se nada além do proxy alcançar a aplicação (firewall na origem). Estreite a lista para as faixas do seu proxy. Ver Twstec\Kit\Foundation\Http\TrustedProxies.');
            }

            // O CONSERTO INTUITIVO ERRADO, reconhecido pela própria aplicação:
            // IP de proxy confiável escrito na allowlist do admin. Quem faz isso
            // está reagindo ao 403 que aparece atrás de um load balancer, e o
            // resultado é uma barreira que aceita a internet inteira com cara de
            // configurada — o único jeito de descobrir sozinho seria notar que
            // TODA requisição casa. Por isso o aviso nomeia os endereços.
            if (AdminIpAllowlist::proxyEntriesInProduction()) {
                Log::warning(sprintf(
                    'A allowlist do /admin contém endereços que são proxies confiáveis (%s): como TODA requisição chega com o endereço do proxy, a barreira de origem aceita qualquer visitante — configurada na aparência, inexistente na prática. Remova esses endereços de ADMIN_ALLOWED_IPS e liste os IPs de quem ADMINISTRA. Ver Twstec\Kit\Foundation\Security\AdminIpAllowlist.',
                    implode(', ', AdminIpAllowlist::proxyEntries()),
                ));
            }

            // E-mail que não é entregue (ver NonDeliveringMailers). Em produção,
            // `log` e `array` RECUSAM o envio — o job de e-mail falha com a
            // instrução do conserto. Aqui ficam os dois avisos de boot:
            //
            //   OPT-OUT declarado → aviso a cada boot, como todo opt-out de
            //   segurança deste bloco.
            //
            //   Mailer padrão que não entrega, SEM opt-out → aviso só na subida
            //   dos processos que processam trabalho (horizon, queue:work,
            //   schedule:run), porque são eles que enviam o e-mail e a subida
            //   deles é o sinal mais precoce que a operação lê. Não em todo
            //   processo: sem `.env` o mailer padrão é `log` e o Laravel se
            //   considera em produção, então um aviso geral sairia em todo
            //   `composer install` do CI e de todo build de imagem — ruído que
            //   ensina a ignorar avisos. E não no php-fpm, onde viraria uma
            //   linha por requisição; ali quem relata é a própria recusa, no
            //   job que falhou.
            if (NonDeliveringMailers::allowedInProductionByOptOut()) {
                Log::warning('MAIL_ALLOW_NON_DELIVERING_IN_PRODUCTION está ligado: em produção, os transportes de e-mail `log` e `array` estão LIBERADOS. Com `log`, cada e-mail — código de verificação, link de redefinição de senha, dados pessoais — é gravado inteiro no arquivo de log; com `array`, é descartado. Só use isto numa instalação descartável. Ver Twstec\Kit\Foundation\Mail\NonDeliveringMailers.');
            } elseif (
                NonDeliveringMailers::defaultIsNonDelivering()
                && $app->runningInConsole()
                && CriticalSecrets::isProcessingCommand(CriticalSecrets::currentCommand())
            ) {
                Log::warning(sprintf(
                    'O mailer padrão (MAIL_MAILER=%s) não entrega e-mail, e em APP_ENV=production todo envio por ele será RECUSADO — cada e-mail da plataforma (código de verificação, redefinição de senha, contato) vai falhar no Horizon. Configure um mailer de verdade (smtp, ses, postmark, resend). Ver Twstec\Kit\Foundation\Mail\NonDeliveringMailers e docs/producao.md.',
                    (string) config('mail.default'),
                ));
            }
        }
    }
}
