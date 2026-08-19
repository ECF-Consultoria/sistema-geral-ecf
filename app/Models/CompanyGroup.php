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

    /**
     * Links de NPS de GRUPO gerados para este grupo (Fase 119.1 Plan 05).
     */
    public function npsGroupSurveys(): HasMany
    {
        return $this->hasMany(NpsGroupSurvey::class);
    }

    /**
     * Grupos que este usuário pode ver e usar (hoje: o seletor "Um grupo de
     * empresas" do NPS e a autorização de `NpsGrupoController`). Admin vê
     * todos.
     *
     * Régua para não-admin — 2026-08-18, bug reportado: uma estrategista não
     * conseguia gerar NPS do grupo MaxiGold, que o admin via normalmente.
     *
     *  (a) pelo menos UMA empresa do grupo tem que estar na carteira dele —
     *      senão um grupo de outra pessoa apareceria para todo mundo;
     *  (b) nenhuma empresa COM responsável pode estar fora da carteira dele.
     *
     * O (b) preserva a intenção original (tudo-ou-nada, para não vazar nem a
     * existência de um grupo que é de outra pessoa). O que mudou é que
     * empresa SEM NENHUM responsável atribuído não conta mais nesse teste:
     * ela é um cadastro ainda não distribuído, não a carteira de outra
     * pessoa, e travar o grupo inteiro por causa dela punia quem cuida das
     * demais. Foi exatamente o caso medido: o grupo tinha 5 empresas, 4 dela
     * e 1 órfã (duplicata recém-importada) — e o grupo sumia para TODOS os
     * não-admins (0 pessoas o enxergavam).
     *
     * A órfã não entra no link por tabela: `NpsGrupoCoberturaService` a
     * exclui com o motivo `sem_responsavel`, visível na prévia de cobertura.
     */
    public function scopeVisivelPara($query, User $user)
    {
        if ($user->isAdmin()) {
            return $query;
        }

        $daCarteira = $user->companies()->pluck('companies.id');

        return $query
            ->whereHas('companies', fn ($q) => $q->whereIn('companies.id', $daCarteira))
            ->whereDoesntHave('companies', fn ($q) => $q
                ->whereNotIn('companies.id', $daCarteira)
                ->whereHas('users'));
    }

    /**
     * Versão por instância da mesma régua de `scopeVisivelPara()` — as duas
     * NUNCA podem divergir (uma lista o seletor, a outra autoriza o POST;
     * divergir significa oferecer um grupo que devolve 403).
     */
    public function visivelPara(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $daCarteira = $user->companies()->pluck('companies.id');
        $doGrupo    = $this->companies()->pluck('companies.id');

        if ($doGrupo->intersect($daCarteira)->isEmpty()) {
            return false;
        }

        return $this->companies()
            ->whereNotIn('companies.id', $daCarteira)
            ->whereHas('users')
            ->doesntExist();
    }
}
