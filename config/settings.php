<?php

declare(strict_types=1);

// =============================================================================
// Configurações editáveis pelo super admin pela UI — padrão do pacote
// twstec/kit-foundation (só as chaves da própria base: os limites de
// requisição). Os módulos opcionais acrescentam as deles sozinhos quando
// instalados (contas: expiração de chaves de API; uploads: limites de
// tamanho); o aplicativo acrescenta as próprias em config/settings.php.
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

    // Chave da tabela settings => [tipo, limites, grupo e largura].
    // As labels das telas vivem em lang/*/admin.php (toda string via __()).
    //
    // `type`   int (com `min` e `max`), bool (liga/desliga), enum (um dos
    //          valores de `options`) ou string (texto curto: `max_length`,
    //          padrão 255, e `pattern` opcional, uma regex). Valor fora do
    //          tipo é recusado no servidor (SettingsManager). Exemplos:
    //            'app.maintenance_banner' => ['type' => 'bool', 'group' => 'other'],
    //            'mail.default' => ['type' => 'enum', 'options' => ['smtp', 'log'], 'group' => 'other'],
    //            'platform.support_url' => ['type' => 'string', 'max_length' => 120, 'pattern' => '#^https://#', 'group' => 'other'],
    //          Rótulo de cada opção do enum (opcional):
    //          admin.settings.option_<campo>_<valor> em lang/*/admin.php.
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
