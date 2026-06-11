<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Grupo nomeado de empresas (tipo carteira com nome livre) usado em /companies.
 *
 * Uma empresa pertence a no máximo um grupo via companies.company_group_id.
 * Independente da hierarquia parent_company_id (matriz/filiais).
 */
class CompanyGroup extends Model
{
    protected $fillable = ['name', 'color'];

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
}
