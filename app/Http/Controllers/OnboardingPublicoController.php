<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOnboardingFichaRequest;
use App\Models\Company;
use App\Models\OnboardingFicha;
use App\Models\OnboardingLink;
use App\Models\OnboardingPasso;
use App\Services\Onboarding\OnboardingFichaService;
use App\Services\Onboarding\OnboardingLinkService;
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
    /** Chave (denormalizada, D-10) do passo `dono=interno` cujo anexo o cliente envia (D-16). */
    private const CHAVE_FICHA_CLIENTE = 'ficha_cliente_recebida';

    /** Catálogo de extensões aceitas para a ficha — nunca executável (T-135-11-04). */
    private const EXTENSOES_FICHA_PERMITIDAS = 'pdf,doc,docx,xls,xlsx,png,jpg,jpeg';

    public function __construct(private OnboardingLinkService $linkService)
    {
    }

    /**
     * GET /onboarding-cliente/{token} — workspace do cliente. `firstOrFail()`
     * é o que produz o 404 de token inexistente/adivinhado (T-135-11-01).
     * Carimba `ultimo_acesso` a cada visita.
     */
    public function workspace(Request $request, string $token)
    {
        $link = OnboardingLink::where('token', $token)->with('company')->firstOrFail();

        $link->ultimo_acesso = now();
        $link->save();

        $company = $link->company;

        return Inertia::render('Onboarding/Publico', [
            'token'   => $token,
            'empresa' => ['nome' => $company->name],
            'passos'  => $this->linkService->passosDoCliente($company),
            'ficha'   => $this->fichaPayload($company),
            // As 7 informações da conta, para o cliente responder ou revisar.
            // Vai o que já foi respondido, para o formulário abrir preenchido
            // em vez de zerado a cada visita.
            'ficha_conta' => $this->fichaContaPayload($company),
        ]);
    }

    /**
     * POST /onboarding-cliente/{token}/ficha-conta — o cliente declara as 7
     * informações de "Métricas e situação da conta".
     *
     * É a MESMA ação do painel interno, só que pela porta pública: a
     * procedência (`ORIGEM_CLIENTE`) é o que separa as duas no banco.
     */
    public function salvarFichaConta(
        StoreOnboardingFichaRequest $request,
        string $token,
        OnboardingFichaService $fichaService,
    ) {
        $link = OnboardingLink::where('token', $token)->with('company')->firstOrFail();

        $fichaService->registrar(
            company: $link->company,
            dados: $request->validated(),
            origem: OnboardingFicha::ORIGEM_CLIENTE,
            usuario: null,
            ip: $request->ip(),
        );

        activity('onboarding')
            ->performedOn($link)
            ->withProperties(['ip' => $request->ip()])
            ->log('Ficha da conta preenchida pelo cliente via portal público');

        return back()->with('success', 'Ficha recebida — obrigado!');
    }

    /**
     * Payload da ficha da conta para o formulário público. Devolve as 7
     * respostas cruas (o cliente pode revisar o que declarou) e NUNCA a
     * procedência ou o IP — isso é operação interna.
     *
     * @return array<string, mixed>
     */
    private function fichaContaPayload(Company $company): array
    {
        $ficha = OnboardingFicha::where('company_id', $company->id)->first();

        $respostas = collect(OnboardingFicha::CAMPOS_RESPOSTA)
            ->mapWithKeys(fn (string $campo) => [$campo => $ficha?->{$campo}])
            ->all();

        return [
            'respostas'       => $respostas,
            'preenchida_em'   => $ficha?->preenchida_em?->toISOString(),
            'total_perguntas' => count(OnboardingFicha::CAMPOS_RESPOSTA),
            'respondidas'     => $ficha?->respondidas() ?? 0,
        ];
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

    /**
     * POST /onboarding-cliente/{token}/ficha — anexa a ficha cadastral do
     * cliente. Disco SEMPRE privado (`local`, nunca `public`) e nome gerado
     * por `Str::uuid()` — nunca o nome original do arquivo no filesystem
     * (T-135-11-04). NÃO conclui o passo `ficha_cliente_recebida`: quem
     * confirma o recebimento é usuário interno na Tela 1 (D-16, o passo tem
     * `dono=interno`) — capacidade de anexar ≠ autoridade de confirmar.
     */
    public function anexarFicha(Request $request, string $token)
    {
        $link = OnboardingLink::where('token', $token)->firstOrFail();
        $company = $link->company;

        $request->validate([
            'ficha' => ['required', 'file', 'max:10240', 'mimes:' . self::EXTENSOES_FICHA_PERMITIDAS],
        ]);

        $passosFicha = OnboardingPasso::query()
            ->where('chave', self::CHAVE_FICHA_CLIENTE)
            ->whereHas('onboarding', fn ($q) => $q->where('company_id', $company->id)->emAndamento())
            ->get();

        if ($passosFicha->isEmpty()) {
            throw ValidationException::withMessages([
                'ficha' => 'Nenhum onboarding em andamento para anexar a ficha no momento.',
            ]);
        }

        $arquivo = $request->file('ficha');
        $nomeGerado = Str::uuid() . '.' . $arquivo->getClientOriginalExtension();
        $caminho = $arquivo->storeAs("onboarding-fichas/{$company->id}", $nomeGerado, 'local');

        // D-10: o mesmo anexo vale para toda instância da chave nos onboardings
        // ativos da empresa — a v1 só tem uma (Gestão), mas a escrita já cobre
        // o caso de dois serviços colidindo na mesma chave.
        foreach ($passosFicha as $passo) {
            $passo->valor = [
                'arquivo'       => $caminho,
                'nome_original' => $arquivo->getClientOriginalName(),
                'enviado_em'    => now()->toISOString(),
            ];
            $passo->save();
        }

        activity('onboarding')
            ->performedOn($link)
            ->withProperties(['arquivo' => $caminho, 'ip' => $request->ip()])
            ->log('Ficha cadastral anexada pelo cliente via portal público');

        return back()->with('success', 'Ficha recebida — nossa equipe vai revisar em breve.');
    }

    /**
     * Payload de `ficha` para o workspace: nome do arquivo original + data
     * de envio, se já houver anexo em algum onboarding ativo da empresa —
     * NUNCA o caminho físico no disco (evita expor o path interno de
     * armazenamento ao cliente).
     */
    private function fichaPayload(Company $company): ?array
    {
        $passo = OnboardingPasso::query()
            ->where('chave', self::CHAVE_FICHA_CLIENTE)
            ->whereHas('onboarding', fn ($q) => $q->where('company_id', $company->id)->emAndamento())
            ->whereNotNull('valor')
            ->first();

        if (! $passo || empty($passo->valor['nome_original'])) {
            return null;
        }

        return [
            'nome_original' => $passo->valor['nome_original'],
            'enviado_em'    => $passo->valor['enviado_em'] ?? null,
        ];
    }
}
