<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as BaseTrustProxies;
use Twstec\Kit\Foundation\Http\TrustedProxies;

/**
 * Proxies confiáveis — aplica o veredito de Twstec\Kit\Foundation\Http\TrustedProxies.
 *
 * TODA a regra e a justificativa das decisões moram na classe da regra; aqui só
 * a fiação, e ela existe por um motivo concreto: o jeito documentado de declarar
 * proxies no Laravel 11+ é `$middleware->trustProxies(at: [...])`, que recebe
 * uma LISTA PRONTA no `bootstrap/app.php`. Só que aquele closure é avaliado
 * antes de a configuração estar carregada — `config()` ali estoura com "Target
 * class [config] does not exist" —, então a lista teria de ser literal no
 * arquivo. Isso violaria a regra do projeto de não hardcodar (o valor certo
 * depende da infraestrutura de quem instala) e quebraria o config cacheado.
 *
 * Lendo a regra AQUI, no momento da requisição, a declaração continua vindo de
 * `config/security.php` + `.env` como todo o resto do kit.
 */
final class TrustProxies extends BaseTrustProxies
{
    /**
     * Os proxies em que esta requisição confia.
     *
     * @return list<string>|string
     */
    protected function proxies(): array|string
    {
        return TrustedProxies::at();
    }

    /**
     * Os headers de encaminhamento obedecidos (`X-Forwarded-Host` fica de fora
     * por padrão — ver a classe da regra).
     */
    protected function headers(): int
    {
        return TrustedProxies::headers();
    }
}
