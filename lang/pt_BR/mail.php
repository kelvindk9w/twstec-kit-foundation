<?php

declare(strict_types=1);

// Strings comuns a todos os e-mails transacionais do kit (pt-BR): o rodapé
// do layout <x-email::layouts.kit>. O corpo de cada e-mail e as strings dele
// são do módulo que o envia.

return [

    // Rodapé COMUM a todos os e-mails (o layout monta o resto com platform()).
    'footer' => [
        'transactional' => 'Este é um e-mail automático relacionado à sua conta — não é divulgação, e por isso não tem link de descadastro.',
        'rights' => '© :year :company. Todos os direitos reservados.',
        'cnpj' => 'CNPJ',
    ],

];
