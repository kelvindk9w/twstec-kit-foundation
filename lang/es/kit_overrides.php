<?php

declare(strict_types=1);

// Salida de `php artisan kit:overrides` (Customization\Console\ListOverrides).

return [

    'none' => 'Ningún archivo de la aplicación sobrescribe textos, correos, configuraciones o pantallas del kit: vale el predeterminado de los paquetes.',
    'copy' => 'igual al predeterminado del kit — se puede borrar',
    'customized' => '{1} personaliza 1 clave: :keys|[2,*] personaliza :count claves: :keys',
    'same' => '{1} 1 clave igual al predeterminado puede salir|[2,*] :count claves iguales al predeterminado pueden salir',
    'own' => '{1} 1 clave que el kit no tiene: :keys|[2,*] :count claves que el kit no tiene: :keys',
    'customized_view' => 'personalizado — revise el archivo del paquete en cada actualización',
    'hint' => 'Un archivo igual al predeterminado se puede borrar: el kit sigue enviando lo mismo y las actualizaciones empiezan a llegar. En un archivo personalizado, deje solo las claves que cambió. Ver docs/personalizacao.md.',

];
