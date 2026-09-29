<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Support;

use Illuminate\Support\HtmlString;

/**
 * Marca padrão do kit (fallback do logo quando PLATFORM_LOGO_URL está vazio).
 *
 * Por que INLINE e não `<img src="…svg">`: a marca é monocromática e usa
 * `currentColor`. Dentro de um `<img>` o SVG vira um documento isolado, sem
 * acesso à cor do tema — sairia preto sobre a topbar escura. Inline, ela
 * herda a cor do painel e funciona nos dois temas com um arquivo só.
 *
 * O arquivo `public/img/brand-mark.svg` continua existindo para uso externo
 * (favicon, e-mail, README). Nada é hardcoded: definir
 * PLATFORM_LOGO_URL no .env sobrepõe esta marca.
 */
final class BrandMark
{
    public static function inlineSvg(): HtmlString
    {
        return new HtmlString(<<<'SVG'
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" fill="none"
                 style="height: 100%; width: auto;" aria-hidden="true">
                <rect x="1" y="1" width="30" height="30" rx="8" stroke="currentColor" stroke-width="2"/>
                <path d="M9 11h14M16 11v11" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
            </svg>
            SVG);
    }
}
