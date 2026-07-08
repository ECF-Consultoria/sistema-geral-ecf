<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreNpsTemplateRequest;
use App\Http\Requests\UpdateNpsTemplateRequest;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Controller CRUD dos templates NPS — Phase 70 Plan 70-01 v15.0.
 *
 * Alicerce da UI de Configuração admin: expõe 4 endpoints REST protegidos
 * por `role:admin` (grupo em routes/web.php linhas 104-127) para listar,
 * criar, editar e ativar/desativar templates. Perguntas e opções vêm em
 * plans separados (70-02 e 70-03), aninhados sob `/templates/{template}/…`.
 *
 * **Invariante crítico:** o template com `is_default=true` (seed "NPS Padrão"
 * criado pela migration 2026_07_07_100004) NUNCA pode ser desativado nem
 * ter o flag `is_default` alterado via UI — ele é o fallback do
 * `NpsTemplateService::resolveForCompany` (Phase 69) e do disparo mensal
 * automatizado. Guard duplo em `update()` e `toggleActive()`.
 *
 * Isolamento: ZERO mudança no `NpsController` (CRUD legado de perguntas
 * extras da Phase 33 continua funcionando em paralelo).
 *
 * Referências:
 *  - .planning/phases/70-ui-de-configuracao-admin/70-01-PLAN.md
 *  - .planning/research/v15-nps-templates-schema.md §4 (precedência)
 *  - app/Models/NpsTemplate.php (fillable, scopes, relations, casts)
 *  - app/Services/Nps/NpsTemplateService.php (consumidor do is_default)
 */
class NpsTemplateController extends Controller
{
    /**
     * GET /nps/configuracao/templates — lista todos os templates (ativos +
     * inativos) para a UI multi-template.
     *
     * Ordenação: `is_default DESC` traz o seed "NPS Padrão" no topo,
     * `priority DESC` prioriza os templates com maior precedência, `id ASC`
     * é tiebreak determinístico (bate no índice composto
     * `nps_templates_active_priority_idx` criado no Plan 68-01).
     *
     * Não faz eager-load de perguntas/opções aqui — payload pesado vem só
     * quando o admin abre o editor (endpoints da Plan 70-02/03). Aqui basta
     * as contagens (`withCount`) para o resumo da listagem.
     */
    public function index(Request $request)
    {
        $templates = NpsTemplate::withCount(['questions', 'servicos'])
            ->orderByDesc('is_default')
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        // Serviços disponíveis para o picker de service scopes (Plan 70-04
        // consome via prop — mantemos aqui para o payload já vir pronto e
        // evitar 1 request extra do frontend).
        $servicosDisponiveis = Servico::active()
            ->orderBy('nome')
            ->get(['id', 'nome', 'setor']);

        return Inertia::render('Nps/Configuracao', [
            'templates'            => $templates,
            'tipos_pergunta'       => NpsTemplateQuestion::TIPOS,
            'dimensoes_labels'     => NpsTemplateQuestion::dimensoesLabels(),
            'servicos_disponiveis' => $servicosDisponiveis,
        ]);
    }

    /**
     * POST /nps/configuracao/templates — cria template novo.
     *
     * Sempre nasce `active=true` e `is_default=false` — o admin pode
     * desativar depois via toggleActive, mas jamais promover a default via
     * UI (só o seed pode ter is_default=true, protegido pelo unique parcial
     * do Plan 68-01 + guard do controller).
     */
    public function store(StoreNpsTemplateRequest $request)
    {
        $data = $request->validated();

        // Invariantes canônicos — nunca respeitar payload nesses 2 campos.
        $data['is_default'] = false;
        $data['active']     = true;

        // Defaults dos campos opcionais (o form pode omitir).
        $data['priority']                ??= 0;
        $data['envio_automatico_mensal'] ??= true;

        $template = NpsTemplate::create($data);

        return back()->with('success', 'Template "' . $template->nome . '" criado.');
    }

    /**
     * PUT /nps/configuracao/templates/{template} — edita template existente.
     *
     * Route model binding resolve `{template}` automaticamente (findOrFail →
     * 404 se não existir). Guard is_default protege o seed contra desativação
     * — se o admin tentar mandar `active=false` no template default, aborta
     * 422 com mensagem clara.
     *
     * `is_default` está deliberadamente FORA do UpdateNpsTemplateRequest —
     * mesmo que venha no payload, o Laravel ignora no `validated()`.
     */
    public function update(UpdateNpsTemplateRequest $request, NpsTemplate $template)
    {
        // Guard: proibir desativar o template padrão (invariante do seed
        // NPS Padrão — sem ele, NpsTemplateService::resolveForCompany
        // explode com RuntimeException).
        $activeInput = $request->input('active');
        $tentandoDesativar = $request->has('active')
            && ($activeInput === false || $activeInput === 0 || $activeInput === '0' || $activeInput === 'false');

        if ($template->is_default && $tentandoDesativar) {
            abort(422, 'O template padrão não pode ser desativado.');
        }

        $template->update($request->validated());

        return back()->with('success', 'Template atualizado.');
    }

    /**
     * PATCH /nps/configuracao/templates/{template}/toggle-active — alterna
     * flag `active` preservando toda a árvore (perguntas/opções/scopes
     * intactos — não deletamos nada, só marcamos o template como inativo).
     *
     * PATCH é o verbo correto para operação idempotente de alternância de
     * estado (RFC 5789 — modificação parcial de recurso).
     *
     * Guard: template padrão que já está ativo NUNCA pode ir para inativo —
     * quebraria o fallback do NpsTemplateService::resolveForCompany.
     */
    public function toggleActive(NpsTemplate $template)
    {
        // Guard: is_default=true + active=true → tentativa de desativar
        // (o toggle inverte o valor atual) → bloqueia.
        if ($template->is_default && $template->active) {
            abort(422, 'O template padrão não pode ser desativado.');
        }

        $template->update(['active' => ! $template->active]);

        return back()->with(
            'success',
            $template->active ? 'Template ativado.' : 'Template desativado.'
        );
    }
}
