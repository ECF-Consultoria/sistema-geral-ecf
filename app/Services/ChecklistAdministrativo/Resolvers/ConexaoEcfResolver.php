<?php

namespace App\Services\ChecklistAdministrativo\Resolvers;

use App\Contracts\ChecklistResolver;
use App\Models\Company;
use App\Models\PortalUsuario;
use App\Services\ChecklistAdministrativo\ChecklistAdministrativoDefinicao;
use App\Services\ChecklistAdministrativo\ChecklistResolverResultado;

/** O item é concluído quando há uma pessoa ativa autorizada para a empresa. */
class ConexaoEcfResolver implements ChecklistResolver
{
    public function chave(): string
    {
        return ChecklistAdministrativoDefinicao::AUTO_FONTE_CONEXAO_ECF;
    }

    public function label(): string
    {
        return 'Portal do Cliente';
    }

    public function ajuda(): string
    {
        return 'Confere se existe um contato ativo vinculado em Acessos do portal.';
    }

    public function resolver(Company $company): ChecklistResolverResultado
    {
        $existe = PortalUsuario::ativos()->whereHas('empresas', fn ($q) => $q->where('companies.id', $company->id))->exists();

        if ($existe) {
            return ChecklistResolverResultado::concluido(['acesso_ativo' => true]);
        }

        return ChecklistResolverResultado::naoColetado('Nenhum contato ativo autorizado em Acessos do portal');
    }
}
