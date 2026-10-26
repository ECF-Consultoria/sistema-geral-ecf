<?php

namespace App\Services\Portal;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * As empresas dos Polos na aba "Portal do cliente" de `/companies` (26/10/2026).
 *
 * O Portal do Cliente é todo por `Company` (login, passagem da equipe, módulos),
 * e as empresas dos Polos moram em `mlb_empresas` — quase nenhuma tem `Company`
 * (4 de 464 em produção, 26/10/2026). Para a equipe dar acesso a uma delas, o
 * cadastro nasce NA HORA, pelo botão "Liberar portal" ({@see liberar()}), e
 * só para a empresa escolhida. Criar para todas de uma vez foi descartado pelo
 * usuário: 460 empresas a mais em toda lista de seleção do sistema.
 *
 * ### O cadastro que nasce aqui é MÍNIMO, de propósito
 *  - **sem Cust ID**: o sync da Adman, o cache de faturamento e o faturamento
 *    mensal só pegam `Company` com `adman_account_id`/`ml_store_id`. O Cust da
 *    empresa Polos continua em `mlb_empresas.cust_id`, de onde o módulo Polos lê.
 *  - **sem contrato**: Fechamento, Dashboard, `/companies` (aba Empresas) e o
 *    painel de contratos exigem contrato; contrato também dispararia o
 *    Onboarding e o roteador operacional, que criaria OUTRA ficha de Polos.
 *  - **sem etapa**: a fila de entrada do Comercial/Administrativo é por etapa.
 *  - **`empresa_nova = false`**: não vira pendência de triagem.
 *
 * A listagem "Cadastro de Empresas" do Comercial mostra toda `Company` ativa;
 * ela deixa de fora este cadastro pela marca estrutural — ligado a uma
 * empresa Polos e sem NENHUM contrato ({@see Company::scopeExcetoCadastroDoPortal()}).
 */
class PolosNoPortalService
{
    /**
     * Linhas da aba Portal — sem os logins, que o controller junta numa
     * consulta só com as empresas de Performance.
     *
     * `id` é o da `Company` (o que o portal entende) e vem `null` enquanto a
     * empresa não tem cadastro; aí a linha só oferece "Liberar portal".
     *
     * @return list<array{
     *     chave: string,
     *     id: ?int,
     *     mlb_empresa_id: int,
     *     nome: string,
     *     ativa: bool,
     *     grupo: ?array,
     *     polos: array{fase: ?string, regiao: ?string},
     * }>
     */
    public function linhas(): array
    {
        $vistas = [];

        return $this->consulta()
            ->with(['company:id,name,active,company_group_id', 'company.grupo:id,name,color'])
            ->orderBy('nome')
            ->get(['id', 'nome', 'polo', 'fase', 'company_id'])
            ->filter(function (MlbEmpresa $e) use (&$vistas) {
                // Uma Company pode ter mais de uma ficha de Polos: uma linha por
                // Company, senão a mesma empresa apareceria duas vezes no portal.
                if ($e->company === null) {
                    return true;
                }
                if (isset($vistas[$e->company->id])) {
                    return false;
                }

                return $vistas[$e->company->id] = true;
            })
            ->map(fn (MlbEmpresa $e) => [
                'chave'          => $e->company ? "company-{$e->company->id}" : "polos-{$e->id}",
                'id'             => $e->company?->id,
                'mlb_empresa_id' => $e->id,
                'nome'           => $e->nome,
                'ativa'          => $e->company ? (bool) $e->company->active : true,
                'grupo'          => $e->company?->grupo ? [
                    'id'    => $e->company->grupo->id,
                    'name'  => $e->company->grupo->name,
                    'color' => $e->company->grupo->color,
                ] : null,
                'polos'          => [
                    'fase'   => $e->fase,
                    'regiao' => MlbImplementacao::poloOficial($e->polo) ?? (trim((string) $e->polo) ?: null),
                ],
            ])
            ->values()
            ->all();
    }

    /** A empresa está no recorte da aba (e portanto pode ser liberada)? */
    public function noRecorte(MlbEmpresa $empresa): bool
    {
        return $this->consulta()->whereKey($empresa->id)->exists();
    }

    /**
     * Garante o cadastro (`Company`) da empresa Polos e devolve-o.
     *
     * Idempotente: se a empresa já tem cadastro, devolve o existente. A trava
     * na linha de `mlb_empresas` impede que dois cliques (ou duas pessoas) criem
     * dois cadastros para a mesma empresa.
     */
    public function liberar(MlbEmpresa $empresa, User $quem): Company
    {
        return DB::transaction(function () use ($empresa, $quem) {
            $empresa = MlbEmpresa::whereKey($empresa->id)->lockForUpdate()->firstOrFail();

            if ($empresa->company_id && ($existente = Company::find($empresa->company_id))) {
                return $existente;
            }

            $company = Company::create([
                'name'         => $empresa->nome,
                // Sugestão para o "Dar acesso", que pré-preenche o contato do
                // cliente a partir do cadastro. Só o nome: a ficha não guarda
                // e-mail nem telefone do cliente em coluna.
                'nome_contato' => $empresa->implementacao?->nome_contato ?: null,
                'active'       => true,
                'status'       => 'ativo',
                'empresa_nova' => false,
                'notes'        => 'Cadastro criado em '.now()->format('d/m/Y')
                    ." para liberar o Portal do Cliente da empresa Polos #{$empresa->id}. "
                    .'Sem Cust ID e sem contrato de propósito: o Cust e o operacional continuam na empresa Polos.',
            ]);

            $empresa->company_id = $company->id;
            $empresa->save();

            activity('portal')
                ->causedBy($quem)
                ->performedOn($company)
                ->withProperties(['mlb_empresa_id' => $empresa->id])
                ->log("Cadastro criado para liberar o portal da empresa Polos \"{$empresa->nome}\"");

            return $company;
        });
    }

    /**
     * Empresas dos Polos ativas, menos as em Churn (pedido de 26/10/2026: a
     * empresa já saiu). "Protocolo Churn" fica — ainda está em tratativa.
     * Mesma normalização do `scopePrograma` (caixa e espaço); fase vazia fica:
     * `NULL <> 'churn'` daria falso e sumiria com ela.
     */
    private function consulta(): Builder
    {
        return MlbEmpresa::ativas()
            ->programa(MlbEmpresa::PROGRAMA_POLOS)
            ->where(fn ($q) => $q->whereNull('fase')->orWhereRaw('LOWER(TRIM(fase)) <> ?', ['churn']));
    }
}
