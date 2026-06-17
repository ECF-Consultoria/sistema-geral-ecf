<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\Servico;
use App\Models\Setor;
use App\Notifications\EmpresaCadastradaNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Módulo Comercial — ponto centralizado de cadastro de novas empresas.
 *
 * O setor Comercial é a única porta de entrada para criação de companies no
 * sistema. Com base nos serviços selecionados (catálogo da Frente A), o
 * controller cria os registros necessários em DB::transaction() e notifica
 * automaticamente os líderes do setor de destino após a confirmação da
 * transação.
 *
 * Phase 14 (Plan 14-04 — Frente B): cadastro passou a aceitar `servicos[]`
 * (lista de servico_id + valor_contratado opcional) em vez do enum legacy
 * preservado via helper estático `servicoDisparaImplementacao()`.
 *
 * Rotas protegidas por middleware 'permission:comercial.cadastrar_empresa'.
 * Admin tem acesso via short-circuit (hasPermission retorna true para admin).
 */
class ComercialController extends Controller
{
    /**
     * Roteia o NOME de um serviço (catálogo Frente A) para o slug de
     * implementação que dispara criação de `mlb_empresas`/`mlb_implementacao`.
     *
     * Helper PURO — testável sem precisar do container Laravel. Substitui o
     *
     * Critério: `str_contains` case-sensitive nos prefixos canônicos. Nomes
     * vindos do catálogo são normalizados em Title Case (D-02), então
     * 'Polos', 'Polos SP', 'Assessoria Premium' batem. Variantes em
     * lowercase ('polos') retornam null por design (não entram no catálogo
     * via fluxo normal).
     *
     * @return 'polos'|'assessoria'|'incubadora'|null
     */
    public static function servicoDisparaImplementacao(string $nome): ?string
    {
        return match (true) {
            str_contains($nome, 'Polos')      => 'polos',
            str_contains($nome, 'Assessoria') => 'assessoria',
            str_contains($nome, 'Incubadora') => 'incubadora',
            default                            => null,
        };
    }

    /**
     * Form GET para cadastro de nova empresa.
     *
     * Phase 14 Plan 14-04: substitui o antigo `index()` (que era apenas um
     * redirect noop) — agora retorna a página `Comercial/NovaEmpresa` com o
     * catálogo ativo de serviços para popular o seletor multi da UI.
     */
    public function create()
    {
        abort_unless(
            auth()->user()->hasPermission('comercial.cadastrar_empresa') || auth()->user()->isAdmin(),
            403
        );

        $servicos = Servico::where('ativo', true)
            ->orderBy('nome')
            ->get(['id', 'nome', 'valor_padrao', 'tipo_cobranca']);

        return Inertia::render('Comercial/NovaEmpresa', [
            'servicos_disponiveis' => $servicos,
        ]);
    }

    /**
     * @deprecated Mantido apenas para compatibilidade de redirect; use create().
     *
     * Phase 36 Plan 36-01 — antes redirecionava para `comercial.empresas`, que
     * por sua vez também é redirect (D-01). Aponta direto para `comercial.empresas.novo`
     * para evitar redirect duplo.
     */
    public function index()
    {
        return redirect()->route('comercial.empresas.novo');
    }

    /**
     * Processa o cadastro centralizado de uma nova empresa.
     *
     * Fluxo (Phase 14 Plan 14-04):
     *   (1) Validação dos campos obrigatórios + `servicos[]` no catálogo
     *   (2) Guard de duplicata por nome (case-insensitive em companies + mlb_empresas)
     *   (3) DB::transaction:
     *       (a) Cria company com status='pendente'
     *       (b) Cria 1 contrato_servico por servico selecionado
     *       (c) Roteamento por nome → cria (opcional) mlb_empresas + mlb_implementacao
     *   (4) Activity log fora da transação
     *   (5) Notificação para líderes do setor de destino fora da transação
     */
    public function store(Request $request)
    {
        abort_unless(
            auth()->user()->hasPermission('comercial.cadastrar_empresa') || auth()->user()->isAdmin(),
            403
        );

        // (1) Validação — aceita servicos[] do catálogo Frente A
        $validated = $request->validate([
            'nome'                        => 'required|string|max:255',
            'cnpj'                        => 'nullable|string|max:20|unique:companies,cnpj',
            'notes'                       => 'nullable|string|max:2000',
            // Quick task 260611-eml — contato do cliente para NPS mensal + futuro contato comercial.
            'email_cliente'               => 'nullable|email|max:255',
            'telefone'                    => 'nullable|string|max:20',
            // Wizard de cadastro (Polos) — handoff opcional. Só o gmail tem consumo
            // hoje (passo "Acesso Colaborador" do Onboarding). Os demais são aceitos
            // (não quebram o submit do wizard) mas ainda não exibidos nesta instância.
            'nome_contato'                => 'nullable|string|max:255',
            'gmail_colaborador'           => 'nullable|email|max:150',
            'polo'                        => 'nullable|string|max:255',
            'grupo_whatsapp'              => 'nullable|boolean',
            // Phase 34 Plan 02 — campos do "close" comercial (todos opcionais).
            // Schema das colunas garantido pela migration do Plan 34-01.
            // Backend NÃO valida formato CNPJ/Telefone — máscara só no front (D-08).
            'nicho'                       => 'nullable|string|max:255',
            'dor'                         => 'nullable|string|max:5000',
            'vende_ml'                    => 'nullable|boolean',
            'faturamento_mensal'          => 'nullable|numeric|min:0|max:99999999.99',
            'marketplaces_extras'         => 'nullable|array',
            'marketplaces_extras.*'       => [Rule::in(['shopee', 'amazon', 'magalu', 'temu', 'tiktok'])],
            'email_colaborador'           => 'nullable|email|max:255',
            'servicos'                    => 'required|array|min:1',
            'servicos.*.servico_id'       => [
                'required',
                'integer',
                Rule::exists('servicos', 'id')->where('ativo', true),
            ],
            'servicos.*.valor_contratado' => 'nullable|numeric|min:0',
        ]);

        // (2) Guard de duplicata — verifica companies.name e mlb_empresas.nome
        $existeEmCompanies  = Company::whereRaw('LOWER(name) = LOWER(?)', [$validated['nome']])->exists();
        $existeEmMlbEmpresa = MlbEmpresa::whereRaw('LOWER(nome) = LOWER(?)', [$validated['nome']])->exists();

        if ($existeEmCompanies || $existeEmMlbEmpresa) {
            throw ValidationException::withMessages([
                'nome' => 'Já existe uma empresa com o nome "' . $validated['nome'] . '" no sistema.',
            ]);
        }

        // (3) Criação atômica via DB::transaction
        $company         = null;
        $servicosCriados = collect();

        DB::transaction(function () use ($validated, &$company, &$servicosCriados) {
            // (a) Cria company com status pendente
            $company = Company::create([
                'name'                => $validated['nome'],
                'cnpj'                => $validated['cnpj'] ?? null,
                'notes'               => $validated['notes'] ?? null,
                // Quick 260611-eml — contato comercial + destinatário NPS mensal.
                'email_cliente'       => $validated['email_cliente'] ?? null,
                'telefone'            => $validated['telefone'] ?? null,
                // Phase 34 Plan 02 — campos do "close" comercial (todos opcionais).
                // Capturados pelo Comercial no fechamento; ajudam o estrategista/analista
                // a entender o cliente sem precisar reentrevistar. Cast 'array' do model
                // serializa marketplaces_extras como JSON; vende_ml é tinyint nullable
                // (null = "não sei"). Schema garantido pelo Plan 34-01.
                'nicho'               => $validated['nicho'] ?? null,
                'dor'                 => $validated['dor'] ?? null,
                'vende_ml'            => $validated['vende_ml'] ?? null,
                'faturamento_mensal'  => $validated['faturamento_mensal'] ?? null,
                'marketplaces_extras' => $validated['marketplaces_extras'] ?? null,
                'email_colaborador'   => $validated['email_colaborador'] ?? null,
                'status'              => 'pendente',
                'active'              => true,
            ]);

            // (b) Cria 1 contrato_servico por servico selecionado
            // O `valor_contratado` cai no `valor_padrao` do catálogo se o cliente
            // não enviar override explícito.
            foreach ($validated['servicos'] as $item) {
                $servico = Servico::find($item['servico_id']);
                if (!$servico) {
                    continue;
                }

                ContratoServico::create([
                    'company_id'       => $company->id,
                    'servico_id'       => $servico->id,
                    'valor_contratado' => isset($item['valor_contratado'])
                        ? (float) $item['valor_contratado']
                        : (float) $servico->valor_padrao,
                    'data_contratacao' => now()->toDateString(),
                    'data_vencimento'  => null,
                    'ativo'            => true,
                ]);

                $servicosCriados->push($servico);
            }

            // (c) Roteamento Phase 13 PRESERVADO — inspeciona NOMES dos serviços
            // (e não slugs legacy) via helper estático.
            $tiposImplementacao = $servicosCriados
                ->map(fn($s) => self::servicoDisparaImplementacao($s->nome))
                ->filter()
                ->unique()
                ->values();

            foreach ($tiposImplementacao as $tipo) {
                if ($tipo === 'polos') {
                    $mlbEmp = MlbEmpresa::create([
                        'nome'       => $company->name,
                        'tipo'       => 'POLO',
                        'projeto'    => 'POLOS',
                        'company_id' => $company->id,
                    ]);
                    $this->criarImplementacaoPolo($mlbEmp, $validated);
                } elseif ($tipo === 'assessoria') {
                    MlbEmpresa::create([
                        'nome'       => $company->name,
                        'tipo'       => 'ASSESSORIA',
                        'company_id' => $company->id,
                    ]);
                } elseif ($tipo === 'incubadora') {
                    MlbEmpresa::create([
                        'nome'       => $company->name,
                        'tipo'       => 'INCUBADORA',
                        'company_id' => $company->id,
                    ]);
                }
                // Publicidade/Gestão/Publicação (helper retorna null) — sem
                // mlb_empresas, apenas company (COM-06 preservado).
            }
        });

        // (4) Activity log — fora da transaction para não afetar rollback
        $nomesServicos = $servicosCriados->pluck('nome')->toArray();

        activity('comercial')
            ->causedBy($request->user())
            ->withProperties(['empresa' => $company->name, 'servicos' => $nomesServicos])
            ->log('Empresa cadastrada pelo Comercial: "' . $company->name . '"');

        // (5) Notificação para líderes dos setores de destino — fora da transaction
        $slugsSetores = $servicosCriados
            ->map(fn($s) => $this->slugSetorParaServico($s->nome))
            ->filter()
            ->unique()
            ->values();

        foreach ($slugsSetores as $slug) {
            $setor = Setor::where('slug', $slug)->first();
            if ($setor && $setor->lideres->isNotEmpty()) {
                Notification::send(
                    $setor->lideres,
                    new EmpresaCadastradaNotification(
                        $company->name,
                        $nomesServicos,  // array (não mais string com implode '+')
                        $request->user()->id,
                    ),
                );
            }
        }

        return back()->with('success', 'Empresa "' . $company->name . '" cadastrada com sucesso.');
    }

    /**
     * Atualiza campos básicos de uma empresa existente.
     *
     * Phase 14 Plan 14-04: validação limpa de campos legacy. A gestão de
     * serviços é feita via rotas `/empresas/{company}/contratos-servico`
     * (Frente A). Plan 14-06 dropa as 6 colunas legacy do schema; este
     * método já não persiste nada legacy, então não precisará de mudanças
     * adicionais no Plan 14-06.
     *
     * ainda ser enviados pelo cliente Inertia (Comercial/Empresas.jsx
     * refator final no Plan 14-07), mas o controller os IGNORA silenciosa
     * e seguramente: campos não validados não chegam em `$validated`.
     */
    public function update(Request $request, Company $company)
    {
        abort_unless(
            auth()->user()->hasPermission('comercial.cadastrar_empresa') || auth()->user()->isAdmin(),
            403
        );

        // Validação: name/cnpj/notes/email_cliente + as empresas vinculadas (filha_ids) ao grupo.
        // O vínculo é gerenciado PELA empresa principal (marca-se as vinculadas).
        // Phase 34 Plan 34-03 — adiciona os 6 campos do close comercial (mesmos rules
        // do Plan 34-02 ComercialController::store) para que a edição em /comercial/empresas
        // mantenha esses dados sincronizados (paridade com CompanyController::update).
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'cnpj'          => 'nullable|string|max:20|unique:companies,cnpj,' . $company->id,
            'notes'         => 'nullable|string|max:2000',
            // Phase 31 D-04 — destinatario do email NPS mensal
            'email_cliente' => 'nullable|email|max:255',
            // Quick 260611-eml — contato comercial.
            'telefone'      => 'nullable|string|max:20',
            // Phase 34 Plan 34-03 — info do close comercial
            'nicho'                  => 'nullable|string|max:255',
            'dor'                    => 'nullable|string|max:5000',
            'vende_ml'               => 'nullable|boolean',
            'faturamento_mensal'     => 'nullable|numeric|min:0|max:99999999.99',
            'marketplaces_extras'    => 'nullable|array',
            'marketplaces_extras.*'  => [Rule::in(['shopee', 'amazon', 'magalu', 'temu', 'tiktok'])],
            // Phase 34 D-07 — email criado pela ECF para acesso colaborador no ML
            // (separado de email_cliente, que é o email do proprietário usado pelo NPS).
            'email_colaborador'      => 'nullable|email|max:255',
            'filha_ids'     => 'nullable|array',
            'filha_ids.*'   => ['integer', 'exists:companies,id', Rule::notIn([$company->id])],
        ]);

        // filha_ids não é fillable → ignorado no update dos campos básicos.
        $company->update($validated);

        // Campo AUSENTE = não alterar vínculos; presente = reatribuir as vinculadas.
        if ($request->has('filha_ids')) {
            $this->sincronizarFilhas($company, $validated['filha_ids'] ?? []);
        }

        activity('comercial')
            ->causedBy($request->user())
            ->withProperties(['empresa' => $company->name])
            ->log('Empresa editada pelo Comercial: "' . $company->name . '"');

        return back()->with('success', 'Empresa "' . $company->name . '" atualizada com sucesso.');
    }

    /**
     * Desativa uma empresa (active = false).
     * Não exclui fisicamente para preservar os registros relacionados (mlb_empresas,
     * sugadores, etc.) e permitir recuperação via admin se necessário.
     */
    public function destroy(Request $request, Company $company)
    {
        abort_unless(
            auth()->user()->hasPermission('comercial.cadastrar_empresa') || auth()->user()->isAdmin(),
            403
        );

        $nome = $company->name;
        $company->update(['active' => false]);

        activity('comercial')
            ->causedBy($request->user())
            ->withProperties(['empresa' => $nome])
            ->log('Empresa removida pelo Comercial: "' . $nome . '"');

        return back()->with('success', 'Empresa "' . $nome . '" removida.');
    }

    // ─── Métodos privados ────────────────────────────────────────────────────

    /**
     * Reatribui as empresas vinculadas (filhas) de uma empresa principal.
     *
     * O vínculo de grupo é gerenciado PELA principal: marca-se quais empresas
     * pertencem ao grupo. Garante o limite de 1 nível (mensagens em pt-BR):
     *  - a própria principal não pode estar vinculada a outra empresa;
     *  - nenhuma vinculada pode ser, ela mesma, principal de outras.
     *
     * Empresas que saíram da lista são desvinculadas (parent_company_id = null).
     *
     * @param int[] $filhaIds IDs das empresas que devem pertencer ao grupo.
     * @throws ValidationException quando o vínculo é proibido.
     */
    private function sincronizarFilhas(Company $principal, array $filhaIds): void
    {
        $filhaIds = array_values(array_unique(array_map('intval', $filhaIds)));

        // 1 nível: a própria principal não pode ser uma vinculada de outra.
        if ($principal->parent_company_id !== null && ! empty($filhaIds)) {
            throw ValidationException::withMessages([
                'filha_ids' => 'Esta empresa está vinculada a outra; desvincule-a antes de adicionar vinculadas.',
            ]);
        }

        // 1 nível: nenhuma vinculada pode ser principal de outras empresas.
        $jaPrincipais = Company::whereIn('id', $filhaIds ?: [0])
            ->whereHas('filhas')
            ->pluck('name');
        if ($jaPrincipais->isNotEmpty()) {
            throw ValidationException::withMessages([
                'filha_ids' => 'Já são principais de outro grupo e não podem ser vinculadas: ' . $jaPrincipais->implode(', ') . '.',
            ]);
        }

        // Desvincula as filhas atuais que saíram da lista.
        Company::where('parent_company_id', $principal->id)
            ->whereNotIn('id', $filhaIds ?: [0])
            ->update(['parent_company_id' => null]);

        // Vincula as selecionadas (move de outro grupo, se for o caso).
        if (! empty($filhaIds)) {
            Company::whereIn('id', $filhaIds)
                ->where('id', '!=', $principal->id)
                ->update(['parent_company_id' => $principal->id]);
        }
    }

    /**
     * Mapeia o NOME de um serviço (catálogo Frente A) para o slug do setor
     *
     * Polos/Assessoria/Publicação → setor 'publicacao'
     * Publicidade                 → setor 'publicidade'
     * Gestão                      → setor 'gestao'
     * Incubadora                  → setor 'incubadora'
     *
     * Outros nomes arbitrários retornam null (não notifica setor algum).
     */
    private function slugSetorParaServico(string $nome): ?string
    {
        return match (true) {
            str_contains($nome, 'Polos')      => 'publicacao',
            str_contains($nome, 'Assessoria') => 'publicacao',
            str_contains($nome, 'Publicação') => 'publicacao',
            str_contains($nome, 'Publicidade') => 'publicidade',
            str_contains($nome, 'Gestão')      => 'gestao',
            str_contains($nome, 'Incubadora')  => 'incubadora',
            default                             => null,
        };
    }


    /**
     * Cria uma MlbImplementacao para uma empresa POLO com os dados padrão
     * configurados em MlbConfiguracao::implementacaoPadroes().
     *
     * Lógica extraída de MlbImplementacaoController::criar() (linhas 192-207)
     * para reutilização no fluxo do Comercial sem duplicação de código (D-20).
     *
     * @param  MlbEmpresa  $empresa  Empresa POLO recém-criada.
     * @param  array        $handoff  Campos do wizard (usa só gmail_colaborador aqui).
     * @return MlbImplementacao
     */
    /**
     * Phase 35 Plan 35-02 — proxy para `MlbImplementacaoFactory::criarParaPolo`.
     *
     * Lógica original extraída para a factory estática reutilizável (D-05),
     * permitindo o `HubspotWebhookController` chamar o mesmo fluxo quando um
     * deal "Fechado Ganho" do HubSpot dispara cadastro automático. Mantemos
     * o método private aqui para preservar a API interna do controller.
     */
    private function criarImplementacaoPolo(MlbEmpresa $empresa, array $handoff = []): MlbImplementacao
    {
        return \App\Services\MlbImplementacaoFactory::criarParaPolo($empresa, $handoff);
    }
}
