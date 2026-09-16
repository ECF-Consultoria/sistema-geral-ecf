<?php

namespace App\Support\Onboarding;

use App\Models\Onboarding;
use App\Models\User;

/**
 * Quem pode ver e mexer num onboarding: admin, ou quem tem a empresa na
 * carteira.
 *
 * Extraído de `OnboardingController::autorizarEscopo()` em 16/09/2026, quando a
 * Agenda passou a abrir os eventos do onboarding fora da ficha. Duas cópias da
 * régua divergiriam na primeira mudança — e a agenda de um colega viraria
 * endereço aberto a quem não tem a empresa.
 */
final class EscopoOnboarding
{
    public static function permite(User $user, Onboarding $onboarding): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->companies()->where('companies.id', $onboarding->company_id)->exists();
    }
}
