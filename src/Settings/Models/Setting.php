<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Settings\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Configuração editável pelo super admin (ver config/settings.php).
 *
 * Somente chaves da whitelist de config/settings.php têm significado para a
 * aplicação; o model em si é um simples chave→valor JSON.
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
