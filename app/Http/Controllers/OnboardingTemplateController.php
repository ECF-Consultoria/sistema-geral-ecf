<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOnboardingTemplateRequest;
use App\Models\Onboarding;
use App\Models\OnboardingTemplate;
use App\Models\Servico;
use App\Models\Setor;
use App\Models\TemplatePasso;
use App\Services\Onboarding\OnboardingResolverFactory;
use App\Services\Onboarding\OnboardingTemplateVersionService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * OnboardingTemplateController — CRUD admin do template do Onboarding geral
 * (D-04, Fase 135). Poder de montar e editar templates pela UI sem que o
 * admin consiga apontar um passo para um resolver inexistente, criar um
 * ciclo de dependência, ou reescrever a versão em que onboardings vivos
 * estão rodando — as três guardas vivem em
 * {@see \App\Http\Requests\StoreOnboardingTemplateRequest} e em
 * {@see \App\Services\Onboarding\OnboardingTemplateVersionService}.
 *
 * Acesso admin-only herdado do grupo `role:admin` em `routes/web.php`
 * (D-04) — camada dupla junto ao `authorize()` do FormRequest.
 *
 * Nenhuma chamada de rede aqui (Pitfall 2 do RESEARCH): `index()` só lê
 * banco e catálogos em memória.
 */
class OnboardingTemplateController extends Controller
{
    /**
     * GET /onboarding/templates — lista o catálogo de serviços com a versão
     * publicada atual de cada um, os catálogos fechados que alimentam o
     * formulário da Tela 2 e os onboardings presos em versões antigas (para
     * a ação de migrar).
     */
    public function index(OnboardingTemplateVersionService $service, OnboardingResolverFactory $factory)
    {
        $servicos = Servico::active()
            ->orderBy('nome')
            ->get()
            ->map(function (Servico $servico) use ($service) {
                $templateAtivo = OnboardingTemplate::ativo()
                    ->where('servico_id', $servico->id)
                    ->with(['passos', 'publicadoPor:id,name'])
                    ->first();

                return [
                    'id'    => $servico->id,
                    'nome'  => $servico->nome,
                    'setor' => $servico->setor,
                    'template' => $templateAtivo ? [
                        'id'                       => $templateAtivo->id,
                        'versao'                   => $templateAtivo->versao,
                        'publicado_em'             => $templateAtivo->publicado_em,
                        'publicado_por'            => $templateAtivo->publicadoPor?->name,
                        'onboardings_ativos_count' => $service->contarOnboardingsNaVersao($templateAtivo),
                        'passos'                   => $templateAtivo->passos,
                    ] : null,
                ];
            })
            ->values();

        $onboardingsEmVersoesAntigas = Onboarding::query()
            ->naoConcluido()
            ->whereHas('template', fn ($q) => $q->where('ativo', false))
            ->with(['company:id,name', 'servico:id,nome', 'template:id,versao'])
            ->get()
            ->map(fn (Onboarding $onboarding) => [
                'id'           => $onboarding->id,
                'empresa'      => $onboarding->company->name,
                'servico'      => $onboarding->servico->nome,
                'versao_atual' => $onboarding->template->versao,
            ])
            ->values();

        return Inertia::render('Onboarding/Templates/Index', [
            'servicos'                       => $servicos,
            // OnboardingResolverFactory::catalogo() — chave/label/ajuda/assincrono
            // (D-09). O UI-SPEC exige a linha de ajuda abaixo do Select e a
            // marcação de "roda em job" quando assincrono=true.
            'catalogo_auto_fonte'            => $factory->catalogo(),
            'catalogo_condicoes'             => $this->catalogoCondicoes(),
            'catalogo_donos'                 => $this->catalogoDonos(),
            'setores'                        => Setor::query()->orderBy('nome')->get(['id', 'nome']),
            'onboardings_em_versoes_antigas' => $onboardingsEmVersoesAntigas,
        ]);
    }

    /**
     * POST /onboarding/templates — publica a versão N+1 do template do
     * serviço. Invariantes de sistema (`versao`, `publicado_em`,
     * `publicado_por`, `ativo`) são decididos aqui, nunca aceitos do
     * payload — molde de
     * {@see \App\Http\Controllers\NpsTemplateController::store()}
     * (linhas 107-119).
     */
    public function store(StoreOnboardingTemplateRequest $request, OnboardingTemplateVersionService $service)
    {
        $data = $request->validated();
        $servico = Servico::findOrFail($data['servico_id']);

        $template = $service->publicarNovaVersao($servico, $data['passos'], $request->user());

        return back()->with('success', "Versão {$template->versao} publicada.");
    }

    /**
     * POST /onboarding/templates/{template}/migrar — migra onboardings
     * `rascunho`/`andamento` explicitamente escolhidos para a versão do
     * template (D-07). Nunca acontece sozinho ao publicar — ação separada
     * com confirmação explícita na UI.
     */
    public function migrar(Request $request, OnboardingTemplate $template, OnboardingTemplateVersionService $service)
    {
        $data = $request->validate([
            'onboarding_ids'   => ['required', 'array', 'min:1'],
            'onboarding_ids.*' => ['integer', 'exists:onboardings,id'],
        ]);

        $migrados = $service->migrarOnboardings($template, $data['onboarding_ids']);

        return back()->with('success', "{$migrados} onboarding(s) migrado(s) para a versão {$template->versao}.");
    }

    /**
     * Catálogo fechado de `dono` (D-14) com rótulo pt-BR para o Select da
     * Tela 2 — nunca um `Select` solto, são só 3 valores fixos.
     *
     * @return array<int, array{chave: string, label: string}>
     */
    private function catalogoDonos(): array
    {
        return [
            ['chave' => TemplatePasso::DONO_CLIENTE, 'label' => 'Cliente'],
            ['chave' => TemplatePasso::DONO_INTERNO, 'label' => 'Interno'],
            ['chave' => TemplatePasso::DONO_SISTEMA, 'label' => 'Sistema'],
        ];
    }

    /**
     * Catálogo fechado de `condicao.tipo` (D-12) com rótulo pt-BR.
     *
     * @return array<int, array{chave: string, label: string}>
     */
    private function catalogoCondicoes(): array
    {
        return [
            [
                'chave' => TemplatePasso::CONDICAO_ANUNCIOS_INATIVOS,
                'label' => 'Só nasce se houver anúncios inativos',
            ],
        ];
    }
}
