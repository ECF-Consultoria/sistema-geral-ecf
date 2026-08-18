<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Onboarding;
use App\Models\OnboardingLink;
use App\Models\OnboardingMapeamento;
use App\Models\OnboardingPasso;
use App\Services\MercadoLivreService;
use App\Services\Onboarding\OnboardingEngineService;
use App\Services\Onboarding\OnboardingLinkService;
use App\Services\Onboarding\OnboardingMapeamentoService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * OnboardingPublicoController — portal público do cliente por EMPRESA
 * (Fase 135, Plano 11, D-06). As 3 rotas deste controller ficam FORA de
 * qualquer grupo `auth`, no prefixo `onboarding-cliente/*` isento de CSRF
 * (`bootstrap/app.php`) — acesso é por posse do token, mesmo risco já
 * aceito no precedente do Polos (`MlbImplementacaoController::workspace()`,
 * usado só como molde de FORMA — D-02 proíbe reuso de código daquele
 * módulo).
 *
 * Nenhum dado de operação interna sai daqui (T-135-11-02): sem
 * responsável, sem SLA, sem dias parado, sem nome de usuário interno — o
 * portal é do cliente, o painel (`OnboardingController`) é de quem
 * trabalha.
 */
class OnboardingPublicoController extends Controller
{
    public function __construct(private OnboardingLinkService $linkService)
    {
    }

    /**
     * GET /onboarding-cliente/{token} — workspace do cliente. `firstOrFail()`
     * é o que produz o 404 de token inexistente/adivinhado (T-135-11-01).
     * Carimba `ultimo_acesso` a cada visita.
     */
    public function workspace(Request $request, string $token, OnboardingMapeamentoService $mapeamentos)
    {
        $link = OnboardingLink::where('token', $token)->with('company')->firstOrFail();

        $link->ultimo_acesso = now();
        $link->save();

        $company = $link->company;

        return Inertia::render('Onboarding/Publico', [
            'token'    => $token,
            'empresa'  => ['nome' => $company->name],
            'passos'   => $this->linkService->passosDoCliente($company),
            'reunioes' => $this->linkService->reunioesDaEmpresa($company),
            'mapeamentos' => Onboarding::where('company_id', $company->id)
                ->emAndamento()
                ->with('servico:id,nome')
                ->get()
                ->map(fn (Onboarding $o) => array_merge(
                    $mapeamentos->visao($o),
                    ['onboarding_id' => $o->id, 'servico' => $o->servico?->nome ?? ''],
                ))
                ->values()
                ->all(),
        ]);
    }

    /**
     * POST /onboarding-cliente/{token}/reuniao — o cliente PEDE a reunião.
     * Sem data: ele não escolhe agenda nossa, só sinaliza que quer. Quem marca
     * data e hora é o responsável, pelo painel interno.
     *
     * `onboarding_id` é validado contra a empresa do token — sem isso um
     * token válido conseguiria solicitar reunião no onboarding de outra
     * empresa só trocando o id no corpo do request.
     */
    public function solicitarReuniao(Request $request, string $token, OnboardingEngineService $engine)
    {
        $link = OnboardingLink::where('token', $token)->firstOrFail();

        $data = $request->validate([
            'onboarding_id' => ['required', 'integer'],
        ]);

        $onboarding = $this->onboardingDoToken($link, $data['onboarding_id']);

        $engine->solicitarReuniao($onboarding, $request->ip());

        return back()->with('success', 'Pedido de reunião enviado. Nossa equipe entra em contato com a data.');
    }

    /**
     * PATCH /onboarding-cliente/{token}/passo/desmarcar — o cliente desfaz o
     * que marcou. Espelho de {@see self::marcarFeito()}.
     */
    public function desmarcarPasso(Request $request, string $token)
    {
        $link = OnboardingLink::where('token', $token)->firstOrFail();

        $data = $request->validate(['chave' => ['required', 'string']]);

        try {
            $this->linkService->desmarcarPorChave($link->company, $data['chave'], $request->ip());
        } catch (\DomainException $e) {
            throw ValidationException::withMessages([
                'chave' => 'Este passo é confirmado pelo sistema e não pode ser desmarcado por aqui.',
            ]);
        }

        activity('onboarding')
            ->performedOn($link)
            ->withProperties(['chave' => $data['chave'], 'ip' => $request->ip()])
            ->log("Passo de chave \"{$data['chave']}\" desmarcado pelo cliente via portal público");

        return back()->with('success', 'Desmarcado.');
    }

    /**
     * POST /onboarding-cliente/{token}/mapeamento/sincronizar — o cliente pede
     * ao sistema que busque os dados da conta dele.
     *
     * Despacha e volta: os resolvers de rede levam de 2 a 30 minutos e a tela
     * passa a mostrar "buscando". Um botão que esperasse a resposta derrubaria
     * a página com 504.
     */
    public function sincronizarMapeamento(Request $request, string $token, OnboardingMapeamentoService $service)
    {
        $link = OnboardingLink::where('token', $token)->firstOrFail();

        $data = $request->validate(['onboarding_id' => ['required', 'integer']]);
        $onboarding = $this->onboardingDoToken($link, $data['onboarding_id']);

        $despachados = $service->sincronizar($onboarding);

        return back()->with(
            'success',
            $despachados > 0
                ? 'Estamos buscando os dados da sua conta. Isso pode levar alguns minutos.'
                : 'Seus dados já estão atualizados.'
        );
    }

    /**
     * POST /onboarding-cliente/{token}/mapeamento/confirmar — o cliente confere
     * o apurado e completa o que o sistema não conseguiu buscar.
     *
     * `confirmado_por` fica `null` de propósito: não há usuário autenticado no
     * portal, e inventar um mentiria sobre a origem do dado. Quem responde
     * "quem confirmou?" é o `confirmado_canal`.
     */
    public function confirmarMapeamento(Request $request, string $token, OnboardingMapeamentoService $service)
    {
        $link = OnboardingLink::where('token', $token)->firstOrFail();

        $data = $request->validate([
            'onboarding_id'  => ['required', 'integer'],
            'full_pontuacao' => ['nullable', 'integer', 'min:0', 'max:100'],
            'observacoes'    => ['nullable', 'string', 'max:2000'],
        ]);

        $onboarding = $this->onboardingDoToken($link, $data['onboarding_id']);

        $service->confirmar(
            onboarding: $onboarding,
            canal: OnboardingMapeamento::CANAL_CLIENTE_PORTAL,
            por: null,
            fullPontuacao: $data['full_pontuacao'] ?? null,
            observacoes: $data['observacoes'] ?? null,
        );

        return back()->with('success', 'Obrigado! Confirmação registrada.');
    }

    /**
     * Resolve o onboarding pedido no corpo do request DENTRO da empresa do
     * token. Sem este recorte, um token válido viraria chave para o onboarding
     * de qualquer outra empresa — bastaria trocar o id.
     */
    private function onboardingDoToken(OnboardingLink $link, int $onboardingId): Onboarding
    {
        $onboarding = Onboarding::where('id', $onboardingId)
            ->where('company_id', $link->company_id)
            ->first();

        if (! $onboarding) {
            throw ValidationException::withMessages([
                'onboarding_id' => 'Não encontramos este onboarding.',
            ]);
        }

        return $onboarding;
    }

    /**
     * GET /onboarding-cliente/{token}/conectar/ml — leva o cliente ao OAuth do
     * Mercado Livre a partir do portal, sem login.
     *
     * O padrão já existia para a Shopee (`/shopee/conectar/{company}`, rota
     * assinada) e o callback do ML já é público — o que faltava era a porta de
     * entrada. Aqui ela é ainda mais simples: o token do onboarding JÁ
     * identifica a empresa, então não precisa de link assinado nem de
     * parâmetro de empresa na URL.
     *
     * A URL de retorno é montada com `route()` a partir do token já validado —
     * nunca lida do request, senão o callback viraria open redirect.
     */
    public function conectarMercadoLivre(string $token, MercadoLivreService $ml)
    {
        $link = OnboardingLink::where('token', $token)->with('company')->firstOrFail();

        $url = $ml->buildAuthUrl(
            company: $link->company,
            retornoUrl: route('onboarding.publico.workspace', $token),
        );

        activity('onboarding')
            ->performedOn($link)
            ->log('Cliente iniciou a autorização do Mercado Livre pelo portal');

        return redirect()->away($url);
    }

    /**
     * PATCH /onboarding-cliente/{token}/passo — conclui manualmente todos os
     * passos daquela `chave` (D-10) nos onboardings ativos da empresa. A
     * `\DomainException` de passo automático (D-19) vira 422 em pt-BR — nunca
     * repassa a mensagem de domínio crua ao cliente. `throttle:20,1` na rota
     * (T-135-11-06 — endpoint público sem auth).
     */
    public function marcarFeito(Request $request, string $token)
    {
        $link = OnboardingLink::where('token', $token)->firstOrFail();

        $data = $request->validate([
            'chave' => ['required', 'string'],
        ]);

        try {
            $this->linkService->marcarFeitoPorChave($link->company, $data['chave'], $request->ip());
        } catch (\DomainException $e) {
            throw ValidationException::withMessages([
                'chave' => 'Este passo é verificado automaticamente pelo sistema e não pode ser marcado como feito por aqui.',
            ]);
        }

        activity('onboarding')
            ->performedOn($link)
            ->withProperties(['chave' => $data['chave'], 'ip' => $request->ip()])
            ->log("Passo de chave \"{$data['chave']}\" marcado como feito pelo cliente via portal público");

        return back()->with('success', 'Marcado como feito.');
    }

}
