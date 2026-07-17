<?php

namespace App\Http\Controllers;

use App\Http\Requests\PreviewNpsTemplateRequest;
use App\Http\Requests\StoreNpsTemplateRequest;
use App\Http\Requests\SyncNpsTemplateScopesRequest;
use App\Http\Requests\UpdateNpsTemplateRequest;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Services\Nps\NpsTemplateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use RuntimeException;

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
        // Phase 70 Plan 05 — props extras exigidas pelo frontend.
        // O editor (QuestionEditor + OptionsEditor + ServiceScopesPicker)
        // consome:
        //   - questions.options (para render + guard "min 1 opção em escala")
        //   - servicos (IDs atuais no pivot, para pre-marcar checkboxes)
        // withCount continua para o card compacto de TemplatesList (badge
        // "N perguntas · M serviços") — Laravel resolve N+1 corretamente:
        // eager-load + count no mesmo query builder.
        $templates = NpsTemplate::withCount(['questions', 'servicos'])
            ->with(['questions.options', 'servicos:id,nome,setor'])
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
            // Phase 72 Plan 01 — widget "Dia de cobrança" (DiaCobrancaWidget).
            // Cast + fallback 25 espelha a leitura do NpsPendingService::diaCobranca()
            // — se o valor no banco estiver corrompido/ausente, a UI mostra 25.
            'dia_cobranca'         => (int) Configuracao::get('nps_dia_cobranca', 25),
            // Phase 96 Plan 02 (AB-96-2) — widget "IPs internos" (IpsInternosWidget).
            // json_decode com fallback [] espelha a leitura defensiva que
            // NpsSuspicionService::isInternalIp() já faz na Fase 96.
            'ips_internos'         => json_decode(Configuracao::get('nps_internal_ips', '[]'), true) ?: [],
            'cidrs_internos'       => json_decode(Configuracao::get('nps_internal_cidrs', '[]'), true) ?: [],
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
            abort(422, 'O modelo principal não pode ser desativado. Defina outro modelo como principal antes.');
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
            abort(422, 'O modelo principal não pode ser desativado. Defina outro modelo como principal antes.');
        }

        $template->update(['active' => ! $template->active]);

        return back()->with(
            'success',
            $template->active ? 'Template ativado.' : 'Template desativado.'
        );
    }

    /**
     * PATCH /nps/configuracao/templates/{template}/set-principal (2026-07-13).
     *
     * Promove ESTE template a "modelo principal" — o único cujas respostas
     * contam nas métricas (dashboard NPS, widgets da home, desempenho, metas)
     * e o que o disparo automático mensal envia para todas as empresas.
     *
     * Reaproveita a flag `is_default` (unique parcial em is_default garante 1
     * só). A troca é ATÔMICA e ordenada: desmarca o principal atual ANTES de
     * marcar o novo, senão o INSERT/UPDATE do 2º is_default=true colide no
     * índice único (QueryException 23000). Ativa o novo principal se estiver
     * inativo — um principal precisa estar ativo para ser resolvido.
     */
    public function setPrincipal(NpsTemplate $template)
    {
        if ($template->is_default) {
            return back()->with('success', "\"{$template->nome}\" já é o modelo principal.");
        }

        DB::transaction(function () use ($template) {
            NpsTemplate::where('is_default', true)
                ->where('id', '!=', $template->id)
                ->get()
                ->each->update(['is_default' => false]);

            $template->update([
                'is_default' => true,
                'active'     => true,
            ]);
        });

        // Invalida o memo de principalId() caso algo mais nesta request agregue.
        NpsTemplate::resetPrincipalCache();

        return back()->with('success', "\"{$template->nome}\" agora é o modelo principal.");
    }

    /**
     * POST /nps/configuracao/templates/{template}/duplicar (Phase 81 Plan 01,
     * DEC-81-1).
     *
     * Clona um modelo existente por completo — config + perguntas + opções +
     * service scopes — num NOVO template (`is_default=false`). Uso principal:
     * duplicar o "NPS | Performance ECF" e trocar o serviço coberto pra Shopee,
     * dando ao admin controle direto sem montar tudo do zero.
     *
     * **Invariante crítico (Pitfall 2):** o clone NUNCA herda `is_default=true`.
     * Duplicar o modelo principal colidiria no unique parcial `is_default`
     * (QueryException 23000) — por isso forçamos `false` explicitamente, mesmo
     * que o `replicate()` copie o valor do original.
     *
     * Toda a clonagem roda em `DB::transaction` (molde do `setPrincipal`): um
     * erro no meio não deixa um template órfão sem perguntas. Eager-load de
     * `questions.options` antes do loop evita N+1 (Pitfall 5).
     */
    public function duplicate(NpsTemplate $template)
    {
        // Evita N+1 na clonagem da árvore (Pitfall 5).
        $template->load('questions.options');

        $clone = DB::transaction(function () use ($template) {
            // replicate() já ignora PK e timestamps; herda
            // active/priority/envio_automatico_mensal/mensagem_whatsapp/descricao.
            $clone = $template->replicate();

            // INVARIANTES — nunca respeitar o valor do original nesses campos.
            $clone->is_default = false;                       // Pitfall 2
            $clone->nome       = $template->nome . ' (cópia)'; // nome editável depois
            $clone->save();

            // Perguntas + opções (relations já ordenadas por ordem/id).
            foreach ($template->questions as $q) {
                $qClone              = $q->replicate();
                $qClone->template_id = $clone->id;
                $qClone->save();

                foreach ($q->options as $o) {
                    $oClone              = $o->replicate();
                    $oClone->question_id = $qClone->id;
                    $oClone->save();
                }
            }

            // Service scopes (pivot) — mesmos servico_id do original.
            $clone->servicos()->sync(
                $template->servicos()->pluck('servicos.id')->all()
            );

            return $clone;
        });

        return back()->with(
            'success',
            "Modelo \"{$clone->nome}\" criado a partir de \"{$template->nome}\"."
        );
    }

    /**
     * DELETE /nps/configuracao/templates/{template} (Phase 81 Plan 01,
     * DEC-81-2).
     *
     * Exclui um modelo descartável, delegando a limpeza da config (perguntas,
     * opções, service scopes) ao cascade das FKs. Duas guardas de negócio
     * ANTES do delete — a FK é só rede de segurança, não a política:
     *
     * **Guarda 1 (is_default):** o modelo principal NUNCA pode ser excluído
     * (espelha `update`/`toggleActive`) — sem ele, o
     * `NpsTemplateService::resolveForCompany` e o disparo mensal ficam sem
     * fallback. Troque o principal antes.
     *
     * **Guarda 2 (tem respostas — Pitfall 1):** se houver QUALQUER survey com
     * resposta, bloqueia. Apagar zeraria `nps_surveys.template_id`
     * (nullOnDelete) e, embora o snapshot per-row sobreviva, o
     * `NpsScoreCalculator::compute()` retorna null quando `!template_id` — as
     * notas daquele histórico SUMIRIAM do dashboard NPS. Caminho não-destrutivo:
     * `toggleActive` (arquivar).
     */
    public function destroy(NpsTemplate $template)
    {
        // Guardas devolvem `back()->with('error')` (302 + toast do AppLayout), NÃO
        // abort(422): o Inertia não converte abort() em flash — renderiza a página
        // de erro crua do Symfony ("Oops! An Error Occurred / 422"). Convenção do
        // projeto: flash.error → toast (HandleInertiaRequests :48 + AppLayout :413).

        // Guarda 1 — nunca apagar o principal.
        if ($template->is_default) {
            return back()->with('error', 'O modelo principal não pode ser excluído. Defina outro modelo como principal antes.');
        }

        // Guarda 2 — preservar histórico E leitura do dashboard (Pitfall 1).
        if ($template->surveys()->whereHas('response')->exists()) {
            return back()->with('error', 'Este modelo tem respostas associadas. Desative-o (arquivar) em vez de excluir — '
                . 'assim o histórico e as métricas continuam corretos.');
        }

        $nome = $template->nome;
        $template->delete(); // cascade: perguntas/opções/scopes; surveys pendentes → template_id NULL

        return back()->with('success', "Modelo \"{$nome}\" excluído.");
    }

    /**
     * PUT /nps/configuracao/templates/{template}/servicos — sincroniza o
     * pivot `nps_template_service_scopes` de forma ATÔMICA — Plan 70-04
     * v15.0 (REQ NPS-C-05).
     *
     * O `sync()` do BelongsToMany faz DELETE+INSERT em transação implícita
     * do Laravel, comparando o array recebido com o estado atual do pivot
     * e emitindo APENAS as diferenças (inserts para IDs novos, deletes
     * para IDs removidos). Safe para concurrent edits — ninguém termina
     * com estado parcialmente sincronizado.
     *
     * Payload `{"servicos": []}` é válido — desassocia o template de todos
     * os serviços numa chamada só (só sobra o fallback via `is_default` do
     * NpsTemplateService::resolveForCompany, se aplicável).
     *
     * Fluxo típico da UI (Plan 70-05):
     *   1. Admin abre picker de serviços
     *   2. Marca/desmarca checkboxes
     *   3. `GET /nps/configuracao/templates/{id}/empresas-afetadas` mostra
     *      preview de quais empresas mudariam de template
     *   4. Admin confirma → este PUT persiste
     */
    public function syncServicos(SyncNpsTemplateScopesRequest $request, NpsTemplate $template)
    {
        $ids = $request->validated()['servicos'] ?? [];

        $template->servicos()->sync($ids);

        $count = count($ids);

        return back()->with(
            'success',
            $count === 0
                ? 'Template desassociado de todos os serviços.'
                : "Template agora vale para {$count} " . ($count === 1 ? 'serviço.' : 'serviços.')
        );
    }

    /**
     * GET /nps/configuracao/templates/{template}/empresas-afetadas —
     * simula quais empresas em carteira receberiam ESTE template dado o
     * pivot atual (research §4 precedência) — Plan 70-04 v15.0
     * (feedback visual do REQ NPS-C-05).
     *
     * IMPORTANTE: NÃO usamos apenas "empresas com serviço em comum" pra
     * responder isso, porque a precedência do research §4 é composta:
     *   - Empresa A pode ter serviço X em comum COM ESTE template, mas
     *     outro template com maior `priority` cobre o mesmo X → empresa A
     *     receberia o OUTRO, não este.
     *   - Empresa B pode não ter NENHUM serviço no pivot → cai no
     *     `is_default` (se ESTE for o default, ainda receberia).
     *
     * Único caminho correto é SIMULAR o resolveForCompany() (Plan 69-01)
     * para cada empresa ativa e agrupar por template.id retornado.
     *
     * Perf guard:
     *   - LIMIT 100 empresas — carteira tem centenas; endpoint é chamado
     *     sob demanda pelo admin (não em batch).
     *   - Loop O(N) com resolveForCompany fazendo ~3 queries cada = ~300
     *     queries no pior caso. Aceitável para v15.0; otimização batch
     *     fica para v15.1 se latência incomodar.
     *   - Campo `truncated: true` sinaliza à UI que existem mais empresas
     *     do que as retornadas (mostra aviso "primeiras 100 de N").
     *
     * Response é JSON (não Inertia) porque este endpoint é chamado via
     * fetch/axios pelo React quando o admin mexe no picker de serviços
     * — re-renderizar a página inteira seria desperdício.
     *
     * `catch (RuntimeException)` tolera o cenário raro em que o seed
     * NPS Padrão foi revertido — em vez de crashar a página, apenas
     * ignora aquela empresa na simulação. Log warning fica para v15.1
     * se quisermos auditoria.
     */
    public function empresasAfetadas(NpsTemplate $template, NpsTemplateService $service)
    {
        // Base: empresas ativas com pelo menos 1 contrato ativo — evita
        // simular sobre empresas dormentes (que nem receberiam disparo
        // mensal). Ordena por nome pra listagem previsível.
        $companies = Company::query()
            ->where('active', true)
            ->whereHas('contratosServico', fn ($q) => $q->where('ativo', true))
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name']);

        // Total real para o campo `truncated` da resposta — permite a UI
        // avisar "primeiras 100 de N".
        $totalAtivas = Company::query()
            ->where('active', true)
            ->whereHas('contratosServico', fn ($q) => $q->where('ativo', true))
            ->count();

        $afetadas = [];
        foreach ($companies as $company) {
            try {
                $resolved = $service->resolveForCompany($company);
                if ($resolved->id === $template->id) {
                    $afetadas[] = [
                        'id'   => $company->id,
                        'name' => $company->name,
                    ];
                }
            } catch (RuntimeException $e) {
                // Cenário raro: seed 'NPS Padrão' revertido / migração
                // 100004 pendente. Ignora a empresa e continua — não
                // faz sentido crashar toda a simulação por causa de 1
                // empresa em estado anômalo.
                continue;
            }
        }

        return response()->json([
            'template_id'  => $template->id,
            'count'        => count($afetadas),
            'empresas'     => $afetadas,
            'sampled_from' => $companies->count(),
            'total_ativas' => $totalAtivas,
            'truncated'    => $totalAtivas > 100,
        ]);
    }

    /**
     * GET /nps/configuracao/templates/{template}/empresas-elegiveis
     * (Phase 81 Plan 02, DEC-81-3).
     *
     * Endpoint JSON que alimenta o modal "Gerar link" modelo-first (Plan 81-04):
     * dado um modelo, devolve as empresas ELEGÍVEIS para gerar link. É a
     * INVERSÃO da lógica "serviços cobertos ∩ contratos ativos" do
     * `NpsTemplateService::resolveForCompany` — aqui partimos do template e
     * buscamos as empresas, não o contrário.
     *
     * Regra (fiel ao research §4, mas por-empresa em vez de por-template):
     *   - Modelo COM serviços cobertos {S}: só empresas `active=true` que tenham
     *     contrato ATIVO (`ativo=true`) de algum serviço em {S}, ordenadas por name.
     *   - Modelo SEM serviços cobertos (pivot vazio): fallback → TODAS as empresas
     *     `active=true` (cobre o is_default / "NPS Padrão", que vale pra todo mundo).
     *
     * **Segurança (T-81-05):** usuário NÃO-admin só enxerga a própria carteira
     * (`whereIn('id', companies do user)`) — não vaza empresas de fora. Por isso
     * a rota fica no grupo `['auth','verified']` (espelha `nps.generate`), NÃO
     * `role:admin`: o gerar-link é usado por consultor/não-admin, e o escopo de
     * carteira acontece aqui dentro.
     *
     * Response é JSON (não Inertia) — chamado via fetch/axios pelo modal React
     * quando o admin/consultor escolhe o modelo no passo 1.
     */
    public function empresasElegiveis(Request $request, NpsTemplate $template)
    {
        // IDs dos serviços cobertos pelo modelo (pivot nps_template_service_scopes).
        $servicoIds = $template->servicos()->pluck('servicos.id');

        // Base: empresas ativas, ordenadas por nome pra listagem previsível.
        $query = Company::query()
            ->where('active', true)
            ->orderBy('name');

        // Modelo COM scopes → filtra por contrato ativo de serviço coberto.
        // Modelo SEM scopes → NÃO aplica filtro (fallback: todas as ativas).
        if ($servicoIds->isNotEmpty()) {
            $query->whereHas('contratosServico', fn ($q) => $q->active()->whereIn('servico_id', $servicoIds));
        }

        // Escopo de carteira para não-admin (T-81-05) — admin vê todas.
        if (! $request->user()->isAdmin()) {
            $query->whereIn('id', $request->user()->companies()->pluck('companies.id'));
        }

        return response()->json([
            'template_id' => $template->id,
            'empresas'    => $query->get(['id', 'name']),
        ]);
    }

    /**
     * POST /nps/configuracao/templates/preview — recebe payload
     * NÃO-PERSISTIDO (draft state completo do form) e devolve estrutura
     * normalizada pronta pra renderizar o preview live — Plan 70-04 v15.0
     * (REQ NPS-C-06).
     *
     * PURE FUNCTION — nenhum INSERT/UPDATE/DELETE/sync no banco. O admin
     * pode arrastar perguntas, mudar textos e ver o preview em tempo
     * real sem risco de sujar o banco. Preserva a experiência de "editor
     * → preview → editor" sem ping-pong.
     *
     * O shape retornado é IDÊNTICO ao `template_snapshot_json` que a
     * Phase 71 vai congelar em cada `NpsResponse` — assim o mesmo
     * componente `<PreviewFormulario>` (Plan 70-05) pode ser reusado
     * pelo `Respond.jsx` (Phase 71) sem duplicação.
     *
     * Normalização:
     *   - `ordem` recomputada via `$idx + 1` (respeita ordem do array
     *     vindo do form; frontend arrasta livremente).
     *   - `options` reordenadas por índice da UI, não por peso — permite
     *     admin escolher a ordem visual sem amarrar ao peso interno.
     *   - `obrigatoria` default `true` quando omitido (segurança padrão).
     *
     * Sem `{template}` na URL — endpoint stateless, o payload contém
     * tudo. Isso permite o admin previsualizar um template ANTES de criar
     * o registro (útil pro fluxo "montar → preview → salvar").
     */
    public function preview(PreviewNpsTemplateRequest $request)
    {
        $validated = $request->validated();

        // Normaliza perguntas + options no mesmo shape que Phase 71
        // vai renderizar. `$idx + 1` para ordens humano-legíveis (1..N)
        // em vez do índice 0-based do array.
        $perguntasNormalizadas = collect($validated['perguntas'])->map(function ($p, $idx) {
            return [
                'ordem'       => $idx + 1,
                'texto'       => $p['texto'],
                'tipo'        => $p['tipo'],
                'dimensao'    => $p['dimensao'],
                'obrigatoria' => $p['obrigatoria'] ?? true,
                'options'     => collect($p['options'] ?? [])->map(fn ($o, $j) => [
                    'ordem' => $j + 1,
                    'label' => $o['label'],
                    'peso'  => $o['peso'],
                ])->values()->all(),
            ];
        })->values()->all();

        return response()->json([
            'template' => [
                'nome'      => $validated['nome']      ?? '(sem título)',
                'descricao' => $validated['descricao'] ?? null,
                'perguntas' => $perguntasNormalizadas,
            ],
        ]);
    }
}
