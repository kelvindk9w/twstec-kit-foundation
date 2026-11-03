<?php

declare(strict_types=1);

// Saída do `php artisan kit:overrides` (Customization\Console\ListOverrides).

return [

    'none' => 'Nenhum arquivo do aplicativo sobrescreve textos, e-mails ou configurações do kit: vale o padrão dos pacotes.',
    'copy' => 'igual ao padrão do kit — pode apagar',
    'customized' => '{1} personaliza 1 chave: :keys|[2,*] personaliza :count chaves: :keys',
    'same' => '{1} 1 chave igual ao padrão pode sair|[2,*] :count chaves iguais ao padrão podem sair',
    'own' => '{1} 1 chave que o kit não tem: :keys|[2,*] :count chaves que o kit não tem: :keys',
    'customized_view' => 'personalizado — confira o arquivo do pacote a cada atualização',
    'hint' => 'Arquivo igual ao padrão pode ser apagado: o kit continua mandando o mesmo, e as atualizações passam a chegar. Num arquivo personalizado, deixe só as chaves que você mudou. Ver docs/personalizacao.md.',

];
