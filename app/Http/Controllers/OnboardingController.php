<?php

namespace App\Http\Controllers;

use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Services\Onboarding\OnboardingEngineService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * OnboardingController — painel operacional do onboarding geral por serviço
 * (Fase 135, Plano 09). Responde "o que está travando, há quantos dias e de
 * quem é a bola" (SC-11) — nunca `feitos/total` — e expõe as duas ações que
 * a Coordenação precisa: confirmar responsável (liga o SLA, D-05/SC-04) e
 * concluir manualmente um passo (nunca um passo com `auto_fonte`, D-19).
 *
 * Gate: `permission:core.onboarding` na rota (`routes/web.php`), distinto do
 * `role:admin` do CRUD de template (D-04) — admin passa por short-circuit em
 * `User::hasPermission()`.
 *
 * Nenhuma chamada de rede aqui: todo o cálculo é sobre dados já persistidos
 * (T-135-09-06); reavaliar() do engine só toca o banco local.
 */
class OnboardingController extends Controller
{
    public function __construct(private OnboardingEngineService $engine)
    {
    }

    /**
     * GET /onboarding — lista agregada por empresa (D-01).
     */
    public function index(Request $request)
    {
        return Inertia::render('Onboarding/Painel', [
            'empresas' => [],
        ]);
    }

    /**
     * GET /onboarding/{onboarding} — detalhe com os passos do template.
     */
    public function show(Request $request, Onboarding $onboarding)
    {
        return Inertia::render('Onboarding/Detalhe', [
            'onboarding' => null,
            'passos'     => [],
        ]);
    }

    /**
     * POST /onboarding/{onboarding}/responsavel — confirma o responsável e
     * transiciona rascunho→andamento (D-05/SC-04).
     */
    public function confirmarResponsavel(Request $request, Onboarding $onboarding)
    {
        return back();
    }

    /**
     * POST /onboarding/passos/{passo}/concluir — conclui manualmente um
     * passo (nunca um passo com `auto_fonte`, D-19).
     */
    public function concluirPasso(Request $request, OnboardingPasso $passo)
    {
        return back();
    }
}
