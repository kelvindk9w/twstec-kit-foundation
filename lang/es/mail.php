<?php

declare(strict_types=1);

// Cadenas comunes a todos los correos transaccionales del kit (es): el pie
// del layout <x-email::layouts.kit>. El cuerpo de cada correo y sus cadenas
// son del módulo que lo envía.

return [

    // Pie COMÚN a todos los correos (el layout arma el resto con platform()).
    'footer' => [
        'transactional' => 'Este es un correo automático sobre tu cuenta — no es publicidad, por eso no tiene enlace para darse de baja.',
        'rights' => '© :year :company. Todos los derechos reservados.',
        'cnpj' => 'CNPJ',
    ],

];
