<?php

namespace App\Services\Empresas;

use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\User;
use App\Support\Acessos\AcessoImplementacaoPolos;
use App\Support\Permissions;

/**
 * As empresas dos Polos na aba "Polos" de `/companies` (26/10/2026).
 *
 * `/companies` esconde de propósito toda empresa com vínculo Polos (Phase 35,
 * ver {@see EmpresasVisiveisService}) e só lista quem tem contrato de
 * Performance. As empresas dos Polos ficam em `mlb_empresas` e quase nenhuma
 * tem linha em `companies` — por isso elas entram numa ABA PRÓPRIA, em vez de
 * se misturar à lista de Performance: a aba Empresas, o total dela e a
 * ferramenta `listar_empresas` do MCP continuam exatamente como eram.
 *
 * A aba só LISTA. Editar fase, polo ou responsável continua no Painel Polos,
 * que é onde o time de Polos trabalha.
 */
class EmpresasPolosService
{
    /**
     * Quem vê a aba: admin e quem já enxerga o módulo de Polos — o operacional
     * (`mlb.projetos`, gate do Painel Polos) ou o financeiro
     * (`mlb.faturamento_polos`, gate de /polos). A aba não mostra nada que
     * essas pessoas já não vejam no Painel.
     */
    public function podeVer(?User $usuario): bool
    {
        if ($usuario === null) {
            return false;
        }

        return $usuario->isAdmin()
            || $usuario->hasPermission('mlb.projetos')
            || $usuario->hasPermission(Permissions::MLB_FATURAMENTO_POLOS);
    }

    /** Mesmo gate de `PolosController::painel()`. */
    public function podeAbrirPainel(User $usuario): bool
    {
        return $usuario->isAdmin() || $usuario->hasPermission('mlb.projetos');
    }

    /** Mesmo gate da ficha de onboarding (`mlb.implementacao.ficha`). */
    public function podeAbrirFicha(User $usuario): bool
    {
        return AcessoImplementacaoPolos::permite($usuario);
    }

    /**
     * Total de empresas da aba — o rótulo precisa dele antes de a lista chegar.
     */
    public function total(): int
    {
        return $this->consulta()->count();
    }

    /**
     * Empresas dos Polos ativas, em ordem alfabética.
     *
     * Recorte: `ativas()` (arquivada fica fora, como em toda listagem de Polos)
     * + `programa('polos')`, a régua em SQL que já trata `projeto` digitado com
     * caixa ou espaço diferente e cai na fase quando `projeto` está vazio,
     * menos a fase Churn (ver {@see consulta()}).
     *
     * @return list<array{
     *     id: int,
     *     nome: string,
     *     cust_id: ?string,
     *     regiao: ?string,
     *     fase: ?string,
     *     responsavel: ?string,
     *     entrada: ?string,
     *     problema: bool,
     *     company_id: ?int,
     *     implementacao_id: ?int,
     * }>
     */
    public function lista(): array
    {
        return $this->consulta()
            ->with([
                'responsavel:id,name',
                'implementacao:id,empresa_id,responsavel_id,data_solicitacao',
                'implementacao.responsavel:id,name',
            ])
            ->orderBy('nome')
            ->get(['id', 'nome', 'cust_id', 'polo', 'fase', 'responsavel_id', 'problema', 'company_id'])
            ->map(function (MlbEmpresa $e) {
                $impl = $e->implementacao;

                return [
                    'id'               => $e->id,
                    'nome'             => $e->nome,
                    'cust_id'          => $e->cust_id,
                    // Região no nome oficial; grafia que não casa com nenhuma
                    // das regiões passa como foi digitada, para não sumir.
                    'regiao'           => MlbImplementacao::poloOficial($e->polo) ?? (trim((string) $e->polo) ?: null),
                    'fase'             => $e->fase,
                    // Mesma regra da coluna Responsável do Painel Polos: com
                    // ficha, o da ficha; sem ficha, o da empresa.
                    'responsavel'      => $impl ? $impl->responsavel?->name : $e->responsavel?->name,
                    'entrada'          => $impl?->data_solicitacao?->format('Y-m-d'),
                    'problema'         => (bool) $e->problema,
                    'company_id'       => $e->company_id,
                    'implementacao_id' => $impl?->id,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Fora da aba também quem está em Churn (pedido do usuário, 26/10/2026): a
     * empresa já saiu. "Protocolo Churn" continua — ainda está em tratativa.
     * Mesma normalização do `scopePrograma` (caixa e espaço), e fase vazia
     * fica: `NULL <> 'churn'` daria falso e sumiria com "Sem fase".
     */
    private function consulta()
    {
        return MlbEmpresa::ativas()
            ->programa(MlbEmpresa::PROGRAMA_POLOS)
            ->where(fn ($q) => $q->whereNull('fase')->orWhereRaw('LOWER(TRIM(fase)) <> ?', ['churn']));
    }
}
