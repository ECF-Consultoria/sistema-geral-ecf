<?php

namespace App\Http\Controllers;

use App\Http\Requests\SalvarFaixasFaturamentoRequest;
use App\Models\Company;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\Servico;
use App\Models\ServicoFaixaFaturamento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FechamentoController — superfície HTTP do fechamento mensal (Fase 137
 * Plano 06, D-04/D-11/D-12/D-13).
 *
 * Dois grupos de responsabilidade, ambos protegidos pelo guard duplo já
 * padrão do módulo administrativo — middleware `role:admin` do grupo de
 * rotas (`routes/web.php:1393`) + checagem própria (`authorize()` no
 * FormRequest ou `abort_unless` inline nos métodos sem FormRequest):
 *
 *  1. Cadastro manual da tabela de faixas (D-04), por serviço ou por
 *     empresa. Sempre ALL-OR-NOTHING — a tabela inteira é substituída,
 *     nunca uma linha isolada (D-13). Molde:
 *     `App\Http\Controllers\DesempenhoConfigController`.
 *  2. Fechar/refazer uma competência (D-11/D-12) — delega o cálculo
 *     inteiro para `php artisan fechamento:consolidar-mes` (mesmo comando
 *     usado pelo cron) e decide a resposta HTTP PELO EXIT CODE, nunca pelo
 *     texto impresso (`Artisan::output()` só entra no `Log::error`).
 *
 * Este controller NÃO calcula faturamento, NÃO resolve faixa e NÃO grava
 * direto em `fechamento_snapshots`/`fechamento_grupo_snapshots` — quem faz
 * isso é `ConsolidarMesFechamento` + `FechamentoSnapshotWriter` (planos
 * 03/05), sempre pelo mesmo caminho usado pelo agendador.
 *
 * @see .planning/phases/137-fechamento-mensal-faturamento-por-empresa-grupo-contra-a-tab/137-CONTEXT.md §D-04, D-11, D-12, D-13
 * @see app/Http/Requests/SalvarFaixasFaturamentoRequest.php
 * @see app/Console/Commands/ConsolidarMesFechamento.php
 */
class FechamentoController extends Controller
{
    // ═══ Cadastro manual da tabela de faixas (D-04) ══════════════════════

    /**
     * POST /administrativo/financeiro/faixas/servico/{servico} — substitui
     * a tabela INTEIRA de faixas de um serviço.
     *
     * All-or-nothing dentro de uma transação: apaga todas as linhas do
     * `servico_id` e recria as recebidas. Nunca toca nas faixas de outro
     * serviço.
     */
    public function salvarFaixasServico(SalvarFaixasFaturamentoRequest $request, Servico $servico)
    {
        DB::transaction(function () use ($request, $servico) {
            ServicoFaixaFaturamento::where('servico_id', $servico->id)->delete();

            foreach ($request->validated('faixas') as $faixa) {
                ServicoFaixaFaturamento::create([
                    'servico_id'      => $servico->id,
                    'ordem'           => $faixa['ordem'],
                    'limite_superior' => $faixa['limite_superior'] ?? null,
                    'valor'           => $faixa['valor'],
                    'valor_e_piso'    => $faixa['valor_e_piso'] ?? false,
                ]);
            }
        });

        return back()->with('success', 'Tabela de faixas salva.');
    }

    /**
     * POST /administrativo/financeiro/faixas/empresa/{company} — substitui
     * a tabela INTEIRA de faixas próprias de UMA empresa (exceção D-13).
     *
     * Mesma disciplina all-or-nothing: a existência de qualquer linha aqui
     * já basta para `FechamentoFaixaResolver::paraEmpresa()` devolver
     * `origem = 'propria'` e ignorar a tabela do serviço por inteiro.
     */
    public function salvarFaixasEmpresa(SalvarFaixasFaturamentoRequest $request, Company $company)
    {
        DB::transaction(function () use ($request, $company) {
            EmpresaFaixaFaturamento::where('company_id', $company->id)->delete();

            foreach ($request->validated('faixas') as $faixa) {
                EmpresaFaixaFaturamento::create([
                    'company_id'      => $company->id,
                    'ordem'           => $faixa['ordem'],
                    'limite_superior' => $faixa['limite_superior'] ?? null,
                    'valor'           => $faixa['valor'],
                    'valor_e_piso'    => $faixa['valor_e_piso'] ?? false,
                ]);
            }
        });

        return back()->with('success', 'Tabela própria da empresa salva.');
    }

    /**
     * DELETE /administrativo/financeiro/faixas/empresa/{company} — apaga a
     * exceção própria da empresa ("Voltar a usar a tabela do serviço" do
     * UI-SPEC). Sem FormRequest (não há payload a validar) — guard próprio
     * via `abort_unless`.
     */
    public function removerFaixasEmpresa(Request $request, Company $company)
    {
        abort_unless($request->user()?->isAdmin() === true, 403);

        EmpresaFaixaFaturamento::where('company_id', $company->id)->delete();

        return back()->with('success', 'Empresa voltou a usar a tabela do serviço.');
    }

}
