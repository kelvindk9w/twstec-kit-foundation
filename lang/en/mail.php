<?php

declare(strict_types=1);

// Strings shared by every transactional email of the kit (en): the footer
// of the <x-email::layouts.kit> layout. The body of each email and its own
// strings belong to the module that sends it.

return [

    // Footer shared by every email (the layout fills the rest from platform()).
    'footer' => [
        'transactional' => 'This is an automated message about your account — not marketing, which is why there is no unsubscribe link.',
        'rights' => '© :year :company. All rights reserved.',
        'cnpj' => 'Company ID',
    ],

];
