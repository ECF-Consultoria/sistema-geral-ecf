<?php

namespace App\Contracts;

use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Services\Onboarding\OnboardingResolverResultado;

/**
 * Contract do catálogo fechado de resolvers automáticos do Onboarding geral
 * (D-09, Fase 135).
 *
 * Cada `template_passos.auto_fonte` aponta para EXATAMENTE 1 implementação
 * deste contrato, registrada por chave fixa no `OnboardingResolverFactory` —
 * nunca um texto livre vindo do CRUD de template (D-04 + D-03 só convivem
 * assim). Molde direto de {@see \App\Contracts\SugadoresAdsProvider} e
 * {@see \App\Contracts\MetricsProvider}, que já resolveram este mesmo
 * problema no projeto.
 */
interface OnboardingResolver
{
    /**
     * Chave estável que bate 1:1 com `template_passos.auto_fonte` e com uma
     * constante de `OnboardingPasso::AUTO_FONTES`. Não traduzir.
     */
    public function chave(): string;

    /**
     * Rótulo legível pt-BR para o Select da Tela 2 (Plano 07/09) — nunca o
     * nome da classe PHP.
     */
    public function label(): string;

    /**
     * Texto curto pt-BR explicando O QUE este resolver verifica e QUANDO
     * roda (síncrono/assíncrono) — o UI-SPEC exige esta linha de ajuda
     * abaixo do campo na Tela 2.
     */
    public function ajuda(): string;

    /**
     * Capacidade, não evento: declara que este resolver depende de job/rede
     * e por isso PODE devolver `nao_coletado` enquanto aguarda uma coleta.
     * NUNCA significa "coleta foi disparada agora" — esse sinal viaja pela
     * chave reservada
     * {@see OnboardingResolverResultado::CHAVE_COLETA_EM_ANDAMENTO}.
     */
    public function assincrono(): bool;

    /**
     * Verifica se o passo está concluído, ainda não coletado, ou se a sonda
     * devolveu um resultado indeterminado (429/timeout/rede) — nunca um
     * booleano.
     */
    public function resolver(Onboarding $onboarding, OnboardingPasso $passo): OnboardingResolverResultado;
}
