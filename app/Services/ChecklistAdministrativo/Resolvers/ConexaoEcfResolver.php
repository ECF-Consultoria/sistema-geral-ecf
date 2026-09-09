<?php

namespace App\Services\ChecklistAdministrativo\Resolvers;

use App\Contracts\ChecklistResolver;
use App\Models\Company;
use App\Models\OnboardingLink;
use App\Services\ChecklistAdministrativo\ChecklistAdministrativoDefinicao;
use App\Services\ChecklistAdministrativo\ChecklistResolverResultado;

/**
 * Resolver do item 8 — "Conexão com o sistema ECF gerada" (Fase 139, D-14).
 *
 * Leitura pura de EXISTÊNCIA da linha de conexão da empresa — nunca cria
 * nada.
 *
 * ⚠️ Decisão explícita do planejador: este resolver NÃO chama o método de
 * fábrica idempotente do serviço de link de onboarding (o que o
 * `139-PATTERNS.md` cita como análogo), embora aquele método também seja
 * seguro de chamar (é `firstOrCreate`, sem rede). O motivo é o efeito
 * colateral, não o custo: chamar um `firstOrCreate` de dentro de um
 * resolver que roda a cada carregamento da ficha CRIARIA a linha e
 * fecharia o item 8 sozinho na primeira renderização, para toda empresa,
 * tornando o item decorativo e violando o ADMIN-03 ("os itens automáticos
 * são gerados pelo próprio checklist, que marca o item ao gerar"). A D-14
 * continua valendo na letra — "fecha por existência, não por clique" — mas
 * quem CRIA a linha é a ação explícita do usuário (endpoint do plano
 * 139-08, que sim chama aquele método de fábrica); este resolver só
 * observa. A idempotência medida pela D-14 é o que permite o botão daquele
 * endpoint ser acionado quantas vezes for, sem duplicar nada.
 */
class ConexaoEcfResolver implements ChecklistResolver
{
    public function chave(): string
    {
        return ChecklistAdministrativoDefinicao::AUTO_FONTE_CONEXAO_ECF;
    }

    public function label(): string
    {
        return 'Conexão com o sistema ECF gerada';
    }

    public function ajuda(): string
    {
        return 'Confere se já existe uma linha de conexão com o sistema ECF para a empresa — leitura '
            . 'pura de existência, nunca gera a linha.';
    }

    public function resolver(Company $company): ChecklistResolverResultado
    {
        $existe = OnboardingLink::where('company_id', $company->id)->exists();

        if ($existe) {
            return ChecklistResolverResultado::concluido(['token_existe' => true]);
        }

        return ChecklistResolverResultado::naoColetado('Conexão com o sistema ECF ainda não foi gerada');
    }
}
