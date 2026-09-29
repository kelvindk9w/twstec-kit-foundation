<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\TrustHosts as BaseTrustHosts;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Response;
use Twstec\Kit\Foundation\Http\TrustedHosts;

/**
 * Validação do header `Host` — aplica o veredito de Twstec\Kit\Foundation\Http\TrustedHosts.
 *
 * TODA a regra e a justificativa das decisões moram na classe da regra; aqui só
 * a fiação. O middleware do framework é aproveitado (é ele que sabe conversar
 * com o `setTrustedHosts` do Symfony), e três coisas mudam:
 *
 * A LISTA vem da regra, lida no momento da requisição (a configuração não existe
 * ainda quando o `bootstrap/app.php` é avaliado).
 *
 * A VALIDAÇÃO VALE SEMPRE. O original desliga em `local` e em teste — ver o
 * bloco "vale em todo ambiente" na classe da regra.
 *
 * A RECUSA É IMEDIATA. O Symfony só valida o host quando alguém o lê, então sem
 * isto a recusa aconteceria em ponto imprevisível do pipeline (no primeiro
 * `url()`, ou nunca, numa rota que não gera URL). Lendo o host aqui, logo depois
 * de declarar a lista, a requisição hostil para NA ENTRADA com 400, antes de
 * qualquer middleware, rota ou log da aplicação ter visto o host forjado.
 */
final class TrustHosts extends BaseTrustHosts
{
    /**
     * Os padrões de host aceitos nesta requisição.
     *
     * @return list<string>
     */
    public function hosts(): array
    {
        return TrustedHosts::patterns();
    }

    /**
     * Declara a lista e valida o host da requisição na hora.
     */
    public function handle(Request $request, $next): Response
    {
        Request::setTrustedHosts($this->hosts());

        try {
            $request->getHost();
        } catch (SuspiciousOperationException $exception) {
            TrustedHosts::reportRejection();

            throw $exception;
        }

        /** @var Closure(Request): Response $next */
        return $next($request);
    }

    /**
     * A validação de host se aplica em todo ambiente.
     */
    protected function shouldSpecifyTrustedHosts(): bool
    {
        return true;
    }
}
