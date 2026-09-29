<?php

declare(strict_types=1);

use Twstec\Kit\Foundation\Tests\ProductionAppTestCase;
use Twstec\Kit\Foundation\Tests\TestCase;

// Todos os testes do pacote sobem a aplicação mínima do Testbench com os
// providers do pacote (config padrão, traduções, views, middlewares).
pest()->extend(TestCase::class)->in('Unit', 'Feature', 'Architecture');

// Aplicação limpa (só framework + pacote) que sobe em APP_ENV=production: as
// guardas de produção têm de valer sem nada do starter.
pest()->extend(ProductionAppTestCase::class)->in('Production');
