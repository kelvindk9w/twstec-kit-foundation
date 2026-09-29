<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Identifiers;

use Illuminate\Database\Eloquent\Builder;

/**
 * Roteamento por UUID (o `id` interno NUNCA aparece na URL: impede enumeração).
 *
 * Por que a trait existe: no Filament 5 o `$recordRouteKeyName` do Resource
 * é usado APENAS para RESOLVER o registro a partir da URL
 * (Resource::resolveRecordRouteBinding → Model::resolveRouteBindingQuery).
 * A GERAÇÃO da URL, ao contrário, passa por route()/UrlGenerator, que chama
 * `$model->getRouteKey()` — ou seja, a chave declarada no MODEL. Com o model
 * ainda apontando para `id`, os links das tabelas saíam com o id numérico e
 * a rota, que resolve por uuid, devolvia 404 (bug de QA #2).
 *
 * Declarar o uuid AQUI resolve os dois lados de uma vez (geração e
 * resolução) e vale para qualquer rota do app, não só para o Filament.
 *
 * Use SEMPRE junto com `HasUuids` + `uniqueIds(): ['uuid']`: é o HasUuids que
 * recusa (404) um uuid malformado na URL ANTES de ir ao banco. Sem ele, no
 * PostgreSQL (coluna `uuid` nativa) o texto malformado derruba a consulta e a
 * rota responde 500.
 *
 * Para buscas manuais por uuid vindo de fora (ação do Livewire, parâmetro de
 * rota da API), use o escopo `byUuid()` — mesma proteção (ver UuidColumn).
 */
trait RoutesByUuid
{
    /**
     * Chave usada em rotas e route model binding: sempre o uuid público.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Filtra pelo uuid público; valor que não é uuid não encontra nada (em
     * vez de derrubar a consulta no PostgreSQL — ver UuidColumn).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByUuid(Builder $query, string $uuid): Builder
    {
        return UuidColumn::where($query, $query->qualifyColumn('uuid'), $uuid);
    }
}
