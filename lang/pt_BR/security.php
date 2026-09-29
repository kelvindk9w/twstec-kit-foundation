<?php

declare(strict_types=1);

return [

    // Resposta ao cliente quando a validação de segurança bloqueia a requisição.
    // Mensagem propositalmente genérica: não revela o que foi detectado
    // (erro genérico ao cliente: nunca vaza detalhe interno).
    'blocked' => 'Requisição rejeitada pela política de segurança.',

    // Mensagem interna gravada no request log (metadado da tentativa).
    'blocked_log' => 'Payload malicioso detectado (:type).',

    // Nota da tentativa que o filtro deixou SEGUIR (modo observe ou rota
    // delegada) — gravada na linha da trilha.
    'observed_log' => 'Padrão de ataque detectado (:type) — registrado, requisição não recusada (modo observar).',

    // Recusa por tamanho: o conteúdo enviado passa do teto que a validação de
    // segurança inspeciona (config security.validation.max_inspected_bytes).
    'payload_too_large' => 'O conteúdo enviado é grande demais para ser aceito.',

    'payload_too_large_log' => 'Conteúdo acima do teto de inspeção de segurança (:bytes bytes) — recusado sem inspeção.',

    // Página/resposta 429 — limite de requisições por cliente (borda e rotas
    // sensíveis). Tom de orientação, não de acusação: quem mais vê esta página
    // é gente de verdade atrás de uma rede compartilhada.
    'throttled' => [
        'title' => 'Muitas requisições',
        'heading' => 'Calma, vamos com mais devagar',
        'body' => 'Recebemos requisições demais vindas da sua conexão em pouco tempo. Aguarde um instante e tente de novo.',
        'retry' => 'Você pode tentar novamente em cerca de :seconds segundos.',
        'action' => 'Tentar novamente',
    ],

];
