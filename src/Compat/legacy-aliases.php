<?php

declare(strict_types=1);

// =============================================================================
// Nomes antigos (1.x) → nomes novos, por UMA versão (2.x).
//
// Na 1.x estas classes moravam no aplicativo, em App\Core\<Módulo>\…; na 2.0
// elas são deste pacote, em Twstec\Kit\Foundation\<Módulo>\…. O nome antigo
// continua resolvendo porque ele pode estar gravado fora do código:
//
//   - payload de fila serializado ANTES da atualização (um job de e-mail que
//     estava na fila no deploy carrega o nome antigo das classes dele);
//   - config publicada, migration ou código do projeto que ainda não trocou o
//     `use`.
//
// O alias é PREGUIÇOSO: nada é carregado até alguém pedir um nome antigo. O
// autoloader do Composer tenta primeiro (e um arquivo que o projeto tenha em
// app/Core/<Módulo> continua valendo); só quando ele não acha é que este
// entra, carrega a classe nova e registra o nome antigo como apelido dela —
// a MESMA classe, então `instanceof` e type hints aceitam os dois nomes.
//
// O código do kit usa só os nomes novos (um teste de arquitetura do starter
// garante). Estes apelidos saem na 3.0.
// =============================================================================

spl_autoload_register(static function (string $class): void {
    static $modules = [
        'Identifiers', 'Money', 'Http', 'Security', 'Logging', 'Localization',
        'Settings', 'Mail', 'Support', 'Backup', 'Audit',
    ];

    if (! str_starts_with($class, 'App\\Core\\')) {
        return;
    }

    $relative = substr($class, strlen('App\\Core\\'));
    $module = strstr($relative, '\\', true);

    if ($module === false || ! in_array($module, $modules, true)) {
        return;
    }

    $target = 'Twstec\\Kit\\Foundation\\'.$relative;

    if (class_exists($target) || interface_exists($target) || trait_exists($target)) {
        class_alias($target, $class);
    }
});
