<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Audit\Contracts;

/**
 * Model que fica SEMPRE fora da captura automática da trilha de auditoria,
 * qualquer que seja o `audit.ignored_models` da aplicação.
 *
 * Para trilhas e efeitos, não para ações: a linha de uma chamada HTTP de
 * saída feita durante uma ação do /admin é consequência da ação (que já tem a
 * linha dela) e tem a trilha própria. Model de negócio não implementa isto —
 * o teste de arquitetura do painel exige que escrita feita no /admin seja
 * auditada.
 */
interface NotAudited {}
