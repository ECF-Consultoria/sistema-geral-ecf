<?php

namespace App\Http\Controllers;

use App\Http\Requests\SalvarFaixasFaturamentoRequest;
use App\Jobs\ConsolidarMesFechamentoJob;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\Servico;
use App\Models\ServicoFaixaFaturamento;
use App\Services\Fechamento\GravarTabelaEmpresaService;
use App\Services\Fechamento\GravarTabelaGrupoService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FechamentoController — superfície HTTP do fechamento mensal (Fase 137
 * Plano 06, D-04/D-11/D-12/D-13).
 *
 * Dois grupos de responsabilidade, ambos protegidos pelo guard duplo já
 * padrão do módulo administrativo — middleware `role:admin` do grupo de
 * rotas (`routes/web.php:1393`) + checagem própria (`authorize()` no
 * FormRequest ou `abort_unless` inline nos métodos sem FormRequest):
 *
 *  1. Cadastro manual da tabela de faixas (D-04), por serviço, por
 *     empresa ou por GRUPO de empresas (Fase 138, D-01 — o grupo é o
 *     terceiro caso do mesmo padrão, com precedência sobre a tabela da
 *     empresa-âncora e a do serviço). Sempre ALL-OR-NOTHING — a tabela
 *     inteira é substituída, nunca uma linha isolada (D-13). Molde:
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
    // Fase 142 (142-01) — escrita centralizada: as duas telas que gravam
    // `empresa_faixas_faturamento` (esta e `TabelasContratoController::confirmar()`) passam
    // pela mesma porta única, com precedência e trilha de auditoria numa só implementação.
    public function salvarFaixasEmpresa(SalvarFaixasFaturamentoRequest $request, Company $company, GravarTabelaEmpresaService $servico)
    {
        $servico->gravar(
            $company,
            $request->validated('faixas'),
            EmpresaFaixaFaturamento::ORIGEM_MANUAL,
            null,
            $request->user(),
            'fechamento',
        );

        return back()->with('success', 'Tabela própria da empresa salva.');
    }

    /**
     * DELETE /administrativo/financeiro/faixas/empresa/{company} — apaga a
     * exceção própria da empresa ("Voltar a usar a tabela do serviço" do
     * UI-SPEC). Sem FormRequest (não há payload a validar) — guard próprio
     * via `abort_unless`.
     */
    public function removerFaixasEmpresa(Request $request, Company $company, GravarTabelaEmpresaService $servico)
    {
        abort_unless($request->user()?->isAdmin() === true, 403);

        $servico->remover($company, $request->user(), 'fechamento');

        return back()->with('success', 'Empresa voltou a usar a tabela do serviço.');
    }

    /**
     * POST /administrativo/financeiro/faixas/grupo/{grupo} — substitui a
     * tabela INTEIRA de faixas próprias de UM GRUPO de empresas (Fase 138,
     * D-01 — terceiro caso do padrão, com precedência sobre a tabela da
     * empresa-âncora e a do serviço).
     *
     * Mesma disciplina all-or-nothing: a existência de qualquer linha aqui
     * já basta para `FechamentoFaixaResolver::paraGrupo()` devolver
     * `origem = 'grupo'` e ignorar tanto a tabela da empresa-âncora quanto
     * a do serviço.
     */
    public function salvarFaixasGrupo(SalvarFaixasFaturamentoRequest $request, CompanyGroup $grupo, GravarTabelaGrupoService $servico)
    {
        // Fase 143 Plano 03 (T1): passou a delegar para a porta unica. Antes disto, o
        // `delete()` de query builder abaixo apagava a tabela anterior sem disparar evento de
        // model nenhum — ou seja, sem rastro. Mesma correcao ja feita no caminho de EMPRESA
        // (`removerFaixasEmpresa` acima) e no gemeo do modulo de contratos.
        $servico->gravar($grupo, $request->validated('faixas'), $request->user(), 'fechamento');

        return back()->with('success', 'Tabela do grupo salva.');
    }

    /**
     * DELETE /administrativo/financeiro/faixas/grupo/{grupo} — apaga a
     * tabela própria do grupo ("Voltar a usar a tabela da empresa" do UI).
     * Sem FormRequest (não há payload a validar) — guard próprio via
     * `abort_unless`, mesma forma de `removerFaixasEmpresa`.
     */
    public function removerFaixasGrupo(Request $request, CompanyGroup $grupo, GravarTabelaGrupoService $servico)
    {
        abort_unless($request->user()?->isAdmin() === true, 403);

        $servico->remover($grupo, $request->user(), 'fechamento');

        return back()->with('success', 'Grupo voltou a usar a tabela da empresa.');
    }

    // ═══ Fechar / refazer competência (D-11/D-12) ════════════════════════

    /**
     * POST /administrativo/financeiro/competencia/fechar — congela a
     * competência informada, delegando o cálculo para
     * `fechamento:consolidar-mes` (mesmo comando do cron).
     *
     * O veredito HTTP vem do EXIT CODE do comando (`Artisan::call()`
     * devolve o exit code diretamente) — nunca da saída de texto. Exit
     * diferente de 0 cobre tanto falha real (exceção) quanto a trava de
     * "já fechado sem motivo" do writer, e sempre devolve 409 com a copy
     * literal do UI-SPEC.
     */
    public function fecharCompetencia(Request $request)
    {
        abort_unless($request->user()?->isAdmin() === true, 403);

        $validated = $request->validate([
            'mes' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        try {
            // Âncora no dia 01 explícito — sem isso o Carbon preenche com
            // o dia de hoje e pode estourar pro mês seguinte (mesmo
            // cuidado de ConsolidarMesFechamento::handle()).
            $mes = Carbon::createFromFormat('Y-m-d', $validated['mes'].'-01')->startOfMonth();
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Formato de mês inválido — use AAAA-MM.'], 422);
        }

        if ($mes->gt(Carbon::now()->startOfMonth())) {
            return response()->json(['message' => 'Não é possível fechar uma competência futura.'], 422);
        }

        $mesLabel = ucfirst($mes->locale('pt_BR')->translatedFormat('F Y'));

        // Consolidação percorre ~190 empresas — mesmo precedente de
        // AdminController::syncFaturamento.
        set_time_limit(0);

        $exitCode = Artisan::call('fechamento:consolidar-mes', [
            '--mes' => $validated['mes'],
            '--por' => $request->user()->id,
        ]);

        if ($exitCode !== 0) {
            Log::error("[Fechamento] Falha ao fechar a competência {$validated['mes']} (exit {$exitCode}).", [
                'saida' => Artisan::output(),
            ]);

            return response()->json([
                'message' => "Não foi possível fechar {$mesLabel}. Nada foi alterado — tente novamente ou avise o time técnico.",
            ], 409);
        }

        return response()->json([
            'message' => "Competência {$mesLabel} fechada com sucesso.",
        ]);
    }

    /**
     * POST /administrativo/financeiro/competencia/refazer — reconsolida uma
     * competência já fechada (D-12 revisado). `motivo` é obrigatório — o
     * comando/writer recusam sem ele.
     *
     * Quick 260916-ejt — o cálculo NÃO roda mais dentro desta requisição. Ele
     * busca o faturamento de ~200 empresas na Adman e estourava o
     * `memory_limit` de 512M do PHP do site (fatal em `Http/Client/Response.php`,
     * 500 na tela, NADA gravado — e a pessoa seguia vendo os números antigos
     * achando que o cálculo estava errado). Agora vai para a fila, onde não há
     * esse limite, e esta resposta só diz "aceitei, está rodando" (202). Quem
     * acompanha o fim é `statusRefazerCompetencia()`.
     *
     * ⚠️ O antigo `set_time_limit(0)` daqui não protegia disso — o que
     * estourava era memória, não tempo.
     */
    public function refazerCompetencia(Request $request)
    {
        abort_unless($request->user()?->isAdmin() === true, 403);

        $validated = $request->validate([
            'mes'    => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'motivo' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        try {
            $mes = Carbon::createFromFormat('Y-m-d', $validated['mes'].'-01')->startOfMonth();
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Formato de mês inválido — use AAAA-MM.'], 422);
        }

        $mesLabel = ucfirst($mes->locale('pt_BR')->translatedFormat('F Y'));

        $chave     = ConsolidarMesFechamentoJob::statusCacheKeyFor($validated['mes']);
        $andamento = Cache::get($chave);

        // Trava anti-duplo-disparo: dois cliques (ou duas abas) regravariam a
        // MESMA competência em paralelo, cada um deixando a sua linha na
        // trilha de auditoria.
        if (is_array($andamento) && ($andamento['status'] ?? null) === 'running') {
            return response()->json([
                'message' => 'Este fechamento já está sendo refeito — aguarde terminar.',
            ], 409);
        }

        // Marcado como em andamento JÁ na entrega, não só quando o worker
        // pegar o job: entre um e outro cabem vários cliques.
        Cache::put($chave, [
            'status'       => 'running',
            'mes'          => $validated['mes'],
            'started_at'   => now()->toIso8601String(),
            'completed_at' => null,
            'error'        => null,
        ], ConsolidarMesFechamentoJob::ttlDoAndamento());

        ConsolidarMesFechamentoJob::dispatch(
            $validated['mes'],
            $validated['motivo'],
            $request->user()->id,
        );

        return response()->json([
            'message' => "Refazendo o fechamento de {$mesLabel}. Isso leva alguns minutos — a tela avisa quando terminar.",
            'status'  => 'running',
        ], 202);
    }

    /**
     * GET /administrativo/financeiro/competencia/refazer/status?mes=AAAA-MM —
     * andamento do refazer daquele mês, para a tela acompanhar sem ficar
     * recarregando (Quick 260916-ejt).
     *
     * ⚠️ Andamento vazio NÃO é erro: é o estado normal de quem nunca refez
     * aquele mês (`idle`).
     */
    public function statusRefazerCompetencia(Request $request)
    {
        abort_unless($request->user()?->isAdmin() === true, 403);

        $validated = $request->validate([
            'mes' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $andamento = Cache::get(ConsolidarMesFechamentoJob::statusCacheKeyFor($validated['mes']));

        if (! is_array($andamento) || empty($andamento['status'])) {
            return response()->json([
                'status'       => 'idle',
                'started_at'   => null,
                'completed_at' => null,
                'error'        => null,
            ]);
        }

        return response()->json([
            'status'       => $andamento['status'],
            'started_at'   => $andamento['started_at']   ?? null,
            'completed_at' => $andamento['completed_at'] ?? null,
            'error'        => $andamento['error']        ?? null,
        ]);
    }
}
