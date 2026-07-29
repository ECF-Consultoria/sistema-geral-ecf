<?php

namespace App\Http\Controllers;

use App\Models\CompanyGroup;
use App\Models\NpsGroupSurvey;
use App\Models\NpsTemplate;
use App\Models\User;
use App\Services\Nps\NpsGrupoCoberturaService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Controller do NPS de GRUPO — Fase 119.1 Plan 06.
 *
 * Controller NOVO de propósito: `NpsController.php` já é o arquivo mais
 * disputado do módulo NPS (tocado pelas Fases 116/118 e pelos Plans 02/04
 * desta mesma fase) — empilhar aqui geraria conflito de mesclagem sem
 * ganho nenhum, já que o fluxo de grupo tem regras próprias (cobertura,
 * fan-out em espelhos) que não fazem sentido dentro de `NpsController`.
 *
 * Fluxo: prévia de cobertura (`previewCobertura`) → geração do link
 * (`generate`, com o MESMO espírito do guard de duplicidade do individual —
 * Plan 02, só que na tabela do link de grupo) → formulário público
 * (`respond`) → resposta única que a `NpsGrupoReplicacaoService` espalha em
 * N surveys-espelho REAIS, um por empresa coberta (`submitResponse`).
 * Os dois últimos métodos chegam no Plan 06 Task 2 — as rotas públicas já
 * são declaradas nesta task (Task 1), apontando para eles.
 *
 * @see app/Services/Nps/NpsGrupoCoberturaService.php (Plan 05 — quem é coberto e por quê)
 */
class NpsGrupoController extends Controller
{
    public function __construct(
        private NpsGrupoCoberturaService $coberturaService,
    ) {
    }

    /**
     * Prévia de cobertura — GET autenticado. Mostra, ANTES de qualquer link
     * ser gerado, quais empresas entram e quais ficam de fora (e por quê),
     * consultando a MESMA fonte que o `generate()` vai usar (D4/NPSMAN-08).
     */
    public function previewCobertura(CompanyGroup $grupo, NpsTemplate $template)
    {
        $this->autorizarAcessoAoGrupo($grupo, request()->user());

        $cobertura = $this->coberturaService->calcular($grupo, $template, now()->startOfMonth());

        return response()->json([
            'referencia' => $cobertura['referencia'],
            'incluidas'  => $cobertura['incluidas'],
            'excluidas'  => $cobertura['excluidas'],
        ]);
    }

    /**
     * Gera o link de NPS do grupo — mesmo espírito do guard de duplicidade
     * do link individual (Plan 02), aplicado à tabela do link de GRUPO:
     * (grupo, modelo, mês) só pode ter 1 registro vivo.
     */
    public function generate(Request $request)
    {
        $data = $request->validate([
            'company_group_id' => 'required|exists:company_groups,id',
            'template_id'      => 'required|exists:nps_templates,id',
        ]);

        $user     = $request->user();
        $grupo    = CompanyGroup::findOrFail($data['company_group_id']);
        $template = NpsTemplate::findOrFail($data['template_id']);

        $this->autorizarAcessoAoGrupo($grupo, $user);

        if (!$template->active) {
            return back()->with('error', 'Este modelo de pesquisa está desativado e não pode gerar novos links.');
        }

        $mes = now()->startOfMonth();

        // Guard de duplicidade — mesmo espírito do Plan 02 ($jaExiste em
        // NpsController::generate()), mas na tabela do link de GRUPO:
        // (grupo, modelo, mês) só pode ter 1 registro. Diferente do link
        // individual, aqui NÃO precisamos do fallback `?? created_at` —
        // `month_reference` do link de grupo é SEMPRE preenchido (a origem
        // é rastreável, D-12 não se aplica — ver interfaces do plano).
        $existente = NpsGroupSurvey::where('company_group_id', $grupo->id)
            ->where('template_id', $template->id)
            ->whereDate('month_reference', $mes)
            ->first();

        if ($existente) {
            return back()->with([
                'nps_link_existente' => route('nps.grupo.respond', $existente->token),
                'error'              => 'Este grupo já tem um link deste modelo de pesquisa neste mês. '
                    .'Enviar um segundo link pode fazer o cliente responder duas vezes. Use o link que já existe.',
            ]);
        }

        // Cobertura calculada AGORA — decide se vale a pena gerar o link.
        // É recalculada de novo no submit (NpsGrupoReplicacaoService, Task 2,
        // nunca confia nesta prévia estática — T-119.1-25).
        $cobertura = $this->coberturaService->calcular($grupo, $template, $mes);

        if (empty($cobertura['incluidas'])) {
            return back()->with('error', 'Nenhuma empresa deste grupo pode receber este link agora. Veja o motivo de cada uma na lista.');
        }

        $groupSurvey = NpsGroupSurvey::create([
            'token'            => Str::uuid()->toString(),
            'company_group_id' => $grupo->id,
            'template_id'      => $template->id,
            'generated_by'     => $user->id,
            'month_reference'  => $mes,
            'expires_at'       => now()->endOfMonth(),
            'status'           => 'pending',
        ]);

        return back()->with([
            'success'  => 'Link de NPS do grupo gerado com sucesso.',
            'nps_link' => route('nps.grupo.respond', $groupSurvey->token),
        ]);
    }

    /**
     * Mesma verificação de acesso de `NpsController::generate()` (Plan 02),
     * adaptada para GRUPO: não-admin só pode gerar/consultar cobertura de
     * um grupo se TODAS as empresas do grupo estiverem na carteira dele —
     * nunca parcial, para não vazar (nem cobertura, nem existência) de
     * empresa fora do escopo do usuário.
     */
    private function autorizarAcessoAoGrupo(CompanyGroup $grupo, User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $permitidas = $user->companies()->pluck('companies.id');
        $doGrupo    = $grupo->companies()->pluck('companies.id');

        if ($doGrupo->isEmpty() || $doGrupo->diff($permitidas)->isNotEmpty()) {
            abort(403);
        }
    }
}
