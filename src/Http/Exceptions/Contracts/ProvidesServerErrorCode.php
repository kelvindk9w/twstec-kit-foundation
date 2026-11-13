<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Http\Exceptions\Contracts;

/**
 * Exceção 5xx com `code` PRÓPRIO e público no envelope de erro da API — por
 * exemplo, um 503 `provider_not_configured` em vez do genérico
 * `service_unavailable`:
 *
 *     final class ProviderNotConfigured extends HttpException implements ProvidesServerErrorCode
 *     {
 *         public function __construct() { parent::__construct(503); }
 *
 *         public function apiErrorCode(): string { return 'provider_not_configured'; }
 *     }
 *
 * É uma escolha explícita (só quem implementa esta interface): exceção não
 * tratada, ou só com ProvidesApiErrorCode, segue com o código e a mensagem
 * genéricos num 5xx. Mesmo aqui, a mensagem da exceção NUNCA sai: a do
 * envelope é `api.errors.<code>` (traduzida pelo aplicativo) ou, sem ela, a
 * genérica. O código tem de ser um identificador estável
 * (`^[a-z][a-z0-9_]{0,63}$`); fora disso, vale o genérico.
 */
interface ProvidesServerErrorCode extends ProvidesApiErrorCode {}
