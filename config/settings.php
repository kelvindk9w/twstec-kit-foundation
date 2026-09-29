<?php

declare(strict_types=1);

// =============================================================================
// Configurações editáveis pelo super admin pela UI — padrão do pacote
// twstec/kit-foundation (só as chaves da própria base: os limites de
// requisição). O aplicativo publica a própria cópia com as chaves dos
// módulos que instalou; a lista dele substitui esta.
//
// WHITELIST (lei): somente as chaves abaixo podem ser editadas no painel
// /admin → Configurações. Cada chave mapeia para um caminho de config: um
// valor presente na tabela `settings` SOBRESCREVE em runtime o valor do .env
// (ver SettingsServiceProvider). Ausente na tabela = .env vigente.
//
// NUNCA adicionar aqui chaves de segredos/credenciais (APP_KEY, DB, mail...):
// a tabela settings não é cofre. Apenas parâmetros operacionais ajustáveis.
// =============================================================================

return [

    // Chave do cache das sobreposições (invalidada a cada escrita).
    'cache_key' => 'settings.db_overrides',

    // Chave da tabela settings => [tipo, limites de UI, grupo e largura].
    // As labels das telas vivem em lang/*/admin.php (toda string via __()).
    //
    // `group`  agrupa os campos por ASSUNTO na tela (uma lista plana de seis
    //          números não diz a ninguém o que é de quê);
    // `span`   é a largura do campo em colunas de 12 — a largura de um
    //          campo é informação: um número de 2 dígitos não pede 1000px
    //          (crítica de design, /admin/settings).
    'overrides' => [
        // Rate limits: requisições por minuto.
        'security.rate_limit.api' => ['type' => 'int', 'min' => 1, 'max' => 10000, 'group' => 'rate_limit', 'span' => 3],
        'security.rate_limit.sensitive' => ['type' => 'int', 'min' => 1, 'max' => 100, 'group' => 'rate_limit', 'span' => 3],
    ],

];
