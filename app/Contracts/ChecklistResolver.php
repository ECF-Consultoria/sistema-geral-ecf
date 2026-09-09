<?php

namespace App\Contracts;

use App\Models\Company;
use App\Services\ChecklistAdministrativo\ChecklistResolverResultado;

/**
 * Contrato dos resolvers automáticos do checklist administrativo (Fase 139,
 * D-10) — o catálogo fechado de "como o sistema sabe que aconteceu" para os
 * 4 itens `auto` de {@see \App\Services\ChecklistAdministrativo\ChecklistAdministrativoDefinicao}.
 *
 * Diferenças deliberadas em relação a {@see \App\Contracts\OnboardingResolver},
 * a interface análoga do motor de Onboarding:
 *
 * (a) `resolver()` recebe `Company` diretamente, não `Onboarding`/`OnboardingPasso`
 *     — não existe entidade "onboarding" nesta fase; o checklist administrativo
 *     é ancorado em `company_id` (D-10).
 *
 * (b) NÃO existe um método de assincronismo aqui. Os 4 resolvers desta fase
 *     são todos síncronos — leitura de coluna local, sem chamada de rede — e
 *     manter um método que todo implementador responderia sempre `false`
 *     seria ruído. Se uma fase futura precisar de um resolver assíncrono, a
 *     interface volta à mesa naquele momento; não se antecipa aqui.
 */
interface ChecklistResolver
{
    /**
     * Chave estável que bate 1:1 com a chave `auto_fonte` do item
     * correspondente no catálogo fechado — nunca uma string livre vinda de
     * requisição.
     */
    public function chave(): string;

    /**
     * Rótulo legível pt-BR para exibição na ficha do checklist — nunca o
     * nome da classe PHP.
     */
    public function label(): string;

    /**
     * Texto curto pt-BR explicando o que este resolver verifica.
     */
    public function ajuda(): string;

    /**
     * Verifica se o item está concluído, ainda não coletado, ou indeterminado
     * — nunca um booleano (D-10, shape copiado de
     * {@see \App\Services\Onboarding\OnboardingResolverResultado}).
     */
    public function resolver(Company $company): ChecklistResolverResultado;
}
