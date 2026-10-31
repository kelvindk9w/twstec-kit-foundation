<?php

declare(strict_types=1);

use Twstec\Kit\Foundation\Support\BrandMark;
use Twstec\Kit\Foundation\Support\Platform;

// =============================================================================
// A MARCA DE RESERVA é do projeto: sem logotipo, a inicial do PLATFORM_NAME
// (no /admin e no ícone da aba gerado), nunca o desenho do kit; o ícone da
// aba segue PLATFORM_FAVICON_URL / PLATFORM_FAVICON_SVG_URL, depois o
// logotipo, depois a inicial.
// =============================================================================

/**
 * @param  array<string, mixed>  $platform
 */
function brandWith(array $platform): void
{
    config(collect($platform)->mapWithKeys(fn (mixed $value, string $key): array => ["platform.{$key}" => $value])->all());
    app()->forgetInstance(Platform::class);
}

beforeEach(function (): void {
    brandWith(['name' => 'Acme Pagamentos', 'logo_url' => null, 'favicon_url' => null, 'favicon_svg_url' => null, 'primary_color' => null]);
});

it('a inicial é a primeira letra ou algarismo do nome, em maiúscula', function (string $name, string $initial): void {
    brandWith(['name' => $name]);

    expect(platform()->initial())->toBe($initial);
})->with([
    ['Acme Pagamentos', 'A'],
    ['  ébano studio', 'É'],
    ['42 Labs', '4'],
    ['— !', '?'],
]);

it('sem logotipo, a marca do /admin é a inicial, não o desenho do kit', function (): void {
    $svg = (string) BrandMark::inlineSvg();

    expect($svg)->toContain('>A</text>')
        ->toContain('currentColor')
        ->not->toContain('M9 11h14M16 11v11');

    brandWith(['name' => 'Zeta']);
    expect((string) BrandMark::inlineSvg())->toContain('>Z</text>');
});

it('sem favicon nem logotipo, o ícone da aba é a inicial gerada, na cor da marca', function (): void {
    expect(platform()->favicons())->toHaveCount(1)
        ->and(platform()->favicons()[0]['type'])->toBe('image/svg+xml')
        ->and(platform()->favicons()[0]['href'])->toStartWith('data:image/svg+xml;base64,');

    $svg = base64_decode(substr(platform()->favicons()[0]['href'], strlen('data:image/svg+xml;base64,')));
    expect($svg)->toContain('>A</text>')->toContain('fill="'.BrandMark::NEUTRAL.'"');

    brandWith(['primary_color' => '#0284C7']);
    expect(BrandMark::iconSvg())->toContain('fill="#0284C7"');

    // Cor que não é hex não entra no SVG.
    brandWith(['primary_color' => 'red"/><script>']);
    expect(BrandMark::iconSvg())->not->toContain('<script>')->toContain('fill="'.BrandMark::NEUTRAL.'"');
});

it('com logotipo e sem favicon, o ícone da aba é o logotipo', function (): void {
    brandWith(['logo_url' => 'https://cdn.acme.test/logo.png']);

    expect(platform()->favicons())->toBe([['href' => 'https://cdn.acme.test/logo.png', 'type' => null]]);
});

it('com PLATFORM_FAVICON_URL e a variante SVG, o ícone da aba é o configurado', function (): void {
    brandWith([
        'logo_url' => 'https://cdn.acme.test/logo.png',
        'favicon_url' => 'https://cdn.acme.test/favicon.ico',
        'favicon_svg_url' => 'https://cdn.acme.test/favicon.svg',
    ]);

    expect(platform()->favicons())->toBe([
        ['href' => 'https://cdn.acme.test/favicon.ico', 'type' => null],
        ['href' => 'https://cdn.acme.test/favicon.svg', 'type' => 'image/svg+xml'],
    ])->and((string) BrandMark::faviconLinks())
        ->toContain('<link rel="icon" href="https://cdn.acme.test/favicon.ico" sizes="any">')
        ->toContain('<link rel="icon" href="https://cdn.acme.test/favicon.svg" type="image/svg+xml">');
});

it('config de projeto antiga, sem as chaves de favicon, continua funcionando', function (): void {
    config()->offsetUnset('platform.favicon_url');
    config()->offsetUnset('platform.favicon_svg_url');
    app()->forgetInstance(Platform::class);

    expect(platform()->faviconUrl)->toBeNull()
        ->and(platform()->favicons())->toHaveCount(1);
});
