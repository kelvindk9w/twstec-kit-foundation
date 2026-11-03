<?php

declare(strict_types=1);

// Output of `php artisan kit:overrides` (Customization\Console\ListOverrides).

return [

    'none' => 'No application file overrides kit texts, e-mails or settings: the package defaults apply.',
    'copy' => 'same as the kit default — safe to delete',
    'customized' => '{1} customizes 1 key: :keys|[2,*] customizes :count keys: :keys',
    'same' => '{1} 1 key equal to the default can go|[2,*] :count keys equal to the default can go',
    'own' => '{1} 1 key the kit does not have: :keys|[2,*] :count keys the kit does not have: :keys',
    'customized_view' => 'customized — check the package file on every update',
    'hint' => 'A file equal to the default can be deleted: the kit keeps providing the same content, and updates start arriving. In a customized file, keep only the keys you changed. See docs/personalizacao.md.',

];
