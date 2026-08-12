<?php

namespace App\Http\Controllers;

use App\Services\Onboarding\OnboardingLinkService;
use Illuminate\Http\Request;

/**
 * OnboardingPublicoController — portal público do cliente por EMPRESA
 * (Fase 135, Plano 11, D-06). As 3 rotas deste controller ficam FORA de
 * qualquer grupo `auth`, no prefixo `onboarding-cliente/*` isento de CSRF
 * (`bootstrap/app.php`) — acesso é por posse do token, mesmo risco já
 * aceito no precedente do Polos (`MlbImplementacaoController::workspace()`,
 * usado só como molde de FORMA — D-02 proíbe reuso de código daquele
 * módulo).
 *
 * Esqueleto nesta Task (Task 1): as rotas públicas precisam de uma classe
 * para despachar antes mesmo de a lógica existir — mesmo padrão de
 * antecipação já usado no `OnboardingController` (135-09-SUMMARY.md,
 * deviation Rule 3). A Task 2 deste plano substitui o corpo dos 3 métodos
 * pela lógica real (workspace agregado por chave, conclusão manual e
 * anexo da ficha).
 */
class OnboardingPublicoController extends Controller
{
    public function __construct(private OnboardingLinkService $linkService)
    {
    }

    /** GET /onboarding-cliente/{token} — workspace do cliente. */
    public function workspace(Request $request, string $token)
    {
        //
    }

    /** PATCH /onboarding-cliente/{token}/passo — conclui manualmente uma chave. */
    public function marcarFeito(Request $request, string $token)
    {
        //
    }

    /** POST /onboarding-cliente/{token}/ficha — anexa a ficha cadastral do cliente. */
    public function anexarFicha(Request $request, string $token)
    {
        //
    }
}
