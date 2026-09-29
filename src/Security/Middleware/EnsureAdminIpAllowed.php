<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Security\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Twstec\Kit\Foundation\Security\AdminIpAllowlist;

/**
 * Barreira de ORIGEM das superfícies administrativas — `/admin` (Filament) e
 * `/horizon` (dashboard de filas).
 *
 * É a SEGUNDA barreira: a primeira é `is_admin` + conta ativa. Esta responde a
 * uma pergunta diferente — "de onde esta requisição está vindo" — e é a que
 * continua valendo quando a primeira falha (sessão roubada, senha vazada).
 *
 * TODA a regra e a justificativa das decisões moram em
 * Twstec\Kit\Foundation\Security\AdminIpAllowlist. Este arquivo só aplica o veredito, em
 * três ramos:
 *
 *   PRODUÇÃO SEM NADA DECLARADO → 403. Antes, lista vazia liberava geral, e
 *   vazia era o padrão do compose de produção: a barreira que a documentação
 *   prometia simplesmente não existia. Ver a classe da regra.
 *
 *   SEM RESTRIÇÃO EM VIGOR → passa. Fora de produção (conveniência de
 *   desenvolvimento) ou em produção com o opt-out declarado.
 *
 *   COM RESTRIÇÃO EM VIGOR → passa se o IP casar (exato, faixa CIDR ou IPv6).
 *
 * POR QUE 403 NOS DOIS CASOS DE RECUSA, e por que a explicação não vai na tela:
 * ao cliente, falta de configuração e IP fora da lista têm de ser
 * indistinguíveis — dizer "o painel está sem allowlist" a quem foi barrado
 * entrega justamente a informação que interessa a quem sonda. A explicação
 * inteira vai para o LOG, que é onde está a pessoa capaz de corrigir. Por isso
 * também não há string nova de interface para traduzir: quem lê a mensagem é o
 * operador, no log, e o log do kit é em português como o resto.
 *
 * (Nota: aqui 403 e não 404 como no DemoSurface. Lá o objetivo era não
 * confirmar a existência de uma rota que estava a uma flag de distância de
 * abrir. Aqui a existência do `/admin` não é segredo — ele é a rota padrão do
 * Filament — e o que precisa ser indistinguível é o MOTIVO da recusa.)
 */
final class EnsureAdminIpAllowed
{
    public function handle(Request $request, Closure $next): Response
    {
        if (AdminIpAllowlist::missingInProduction()) {
            $this->announceMissingConfiguration();

            abort(403);
        }

        if (! AdminIpAllowlist::permits($request->ip())) {
            $this->announceRejectedOrigin($request);

            abort(403);
        }

        return $next($request);
    }

    /**
     * Recusa por falta de configuração: a mensagem carrega o que falta e como
     * voltar, porque é ela que destranca o administrador.
     */
    private function announceMissingConfiguration(): void
    {
        Log::warning(
            'Superfície administrativa RECUSADA: APP_ENV=production e a allowlist de IP não foi '
            .'declarada. O /admin e o /horizon não respondem enquanto a origem permitida for '
            .'desconhecida — antes, lista vazia liberava qualquer origem, com a documentação do '
            .'kit afirmando que a allowlist era obrigatória. PARA VOLTAR: defina '
            .'ADMIN_ALLOWED_IPS com os IPs ou faixas CIDR de quem administra (ex.: '
            .'"203.0.113.10,198.51.100.0/24"; IPv6 e prefixo IPv6 também valem) no ambiente dos '
            .'serviços PHP e reinicie-os. O resto da aplicação — site, API, painel do usuário, '
            .'filas e /up — segue atendendo normalmente. Se esta instalação NÃO deve ter '
            .'allowlist na aplicação (IP dinâmico, ou segunda barreira na rede: VPN, Cloudflare '
            .'Access, WAF), declare ADMIN_ALLOW_ANY_IP=true — a liberação passa a ser uma '
            .'decisão registrada, com aviso no log a cada boot, em vez de um esquecimento. '
            .'Detalhes em Twstec\Kit\Foundation\Security\AdminIpAllowlist e em docs/admin-e-dashboards.md (barreira de origem do /admin).'
        );
    }

    /**
     * Recusa por IP fora da lista. Só grava aviso no caso que indica
     * CONFIGURAÇÃO ERRADA (requisição vindo por proxy que a aplicação não
     * reconhece) — um 403 legítimo de sondagem não precisa de linha no log,
     * porque o RequestLogging já registra a requisição inteira.
     */
    private function announceRejectedOrigin(Request $request): void
    {
        if (! AdminIpAllowlist::proxyBlind($request)) {
            return;
        }

        Log::warning(
            'Superfície administrativa RECUSADA por IP fora da allowlist, e a requisição chegou '
            .'com header de proxy (X-Forwarded-For/Forwarded) enquanto a aplicação não confia em '
            .'nenhum proxy. Nesse estado o endereço comparado é o do proxy, NÃO o do cliente: a '
            .'allowlist tranca todo mundo fora, inclusive quem está listado. NÃO resolva isso '
            .'colocando o IP do proxy/load balancer na allowlist — todas as requisições chegam '
            .'com aquele endereço, e a barreira ficaria aberta para a internet inteira parecendo '
            .'configurada. A correção é declarar os proxies confiáveis (trustProxies), e só '
            .'então a allowlist volta a comparar o cliente de verdade.'
        );
    }
}
