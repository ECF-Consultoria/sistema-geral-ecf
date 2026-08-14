<?php

namespace App\Services\Onboarding;

use App\Contracts\OnboardingResolver;
use App\Models\OnboardingPasso;

/**
 * Registry por chave do catálogo fechado de resolvers automáticos do
 * Onboarding geral (D-09, Fase 135).
 *
 * Difere do molde {@see \App\Services\Sugadores\SugadoresAdsProviderFactory}
 * / {@see \App\Services\Metrics\MetricsProviderFactory} — aqueles escolhem 1
 * provider entre N candidatos para a MESMA empresa. Aqui cada
 * `template_passos.auto_fonte` aponta para EXATAMENTE 1 resolver por chave
 * fixa, então o shape correto é um registry indexado por chave, não um
 * fallback.
 *
 * Construído em `AppServiceProvider::register()` com uma lista EXPLÍCITA de
 * instâncias — nunca um `iterable` mágico de descoberta por diretório.
 * Descoberta implícita é o oposto de catálogo fechado. Os Planos 05/06
 * acrescentam mais resolvers a esta mesma lista.
 */
class OnboardingResolverFactory
{
    /** @var array<string, OnboardingResolver> */
    private array $porChave = [];

    /**
     * @param iterable<OnboardingResolver> $resolvers
     */
    public function __construct(iterable $resolvers)
    {
        foreach ($resolvers as $resolver) {
            $this->porChave[$resolver->chave()] = $resolver;
        }

        // Falha alta na construção: nenhum resolver registrado pode apontar
        // para uma chave fora de OnboardingPasso::AUTO_FONTES — trava que
        // impede um resolver órfão de existir sem que o template consiga
        // apontar para ele (D-09).
        foreach (array_keys($this->porChave) as $chave) {
            if (! in_array($chave, OnboardingPasso::AUTO_FONTES, true)) {
                throw new \RuntimeException(
                    "Resolver registrado com chave '{$chave}' fora do catálogo fechado "
                    . 'OnboardingPasso::AUTO_FONTES — catálogo fechado (D-09).'
                );
            }
        }
    }

    /**
     * Resolve o resolver para o `auto_fonte` do passo. Catálogo fechado:
     * lança `\RuntimeException` para qualquer chave desconhecida — nunca
     * devolve `null` nem um resolver padrão (D-09).
     */
    public function for(string $autoFonte): OnboardingResolver
    {
        return $this->porChave[$autoFonte]
            ?? throw new \RuntimeException(
                "auto_fonte '{$autoFonte}' sem resolver registrado — catálogo fechado (D-09)."
            );
    }

    public function existe(string $autoFonte): bool
    {
        return isset($this->porChave[$autoFonte]);
    }

    /**
     * Lista o catálogo para alimentar o Select da Tela 2 (Plano 07/09).
     *
     * @return array<int, array{chave: string, label: string, ajuda: string, assincrono: bool}>
     */
    public function catalogo(): array
    {
        return array_values(array_map(
            static fn (OnboardingResolver $resolver) => [
                'chave'      => $resolver->chave(),
                'label'      => $resolver->label(),
                'ajuda'      => $resolver->ajuda(),
                'assincrono' => $resolver->assincrono(),
            ],
            $this->porChave
        ));
    }
}
