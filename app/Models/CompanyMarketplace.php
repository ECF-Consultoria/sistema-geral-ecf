<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot N:N Company <-> Marketplace (Phase 57 v13.0).
 *
 * Nao usa `\Illuminate\Database\Eloquent\Relations\Pivot` porque tem PK
 * propria + campos ricos (store_id, adman_id, is_primary, active,
 * integracao_status) — comportamento de Model comum eh mais adequado.
 *
 * Fonte-de-verdade para IDs de marketplace por empresa. Consumidores
 * existentes acessam via accessors legacy do Company (`adman_account_id`,
 * `ml_store_id`) que fazem sync com colunas flat.
 *
 * @property int $id
 * @property int $company_id
 * @property string $marketplace
 * @property string|null $store_id
 * @property string|null $adman_id
 * @property bool $is_primary
 * @property bool $active
 * @property string $integracao_status
 *
 * @see .planning/adrs/DATA-01-multi-marketplace-model.md
 */
class CompanyMarketplace extends Model
{
    use HasFactory;

    protected $table = 'company_marketplaces';

    protected $fillable = [
        'company_id',
        'marketplace',
        'store_id',
        'adman_id',
        'is_primary',
        'active',
        'integracao_status',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'active'     => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
