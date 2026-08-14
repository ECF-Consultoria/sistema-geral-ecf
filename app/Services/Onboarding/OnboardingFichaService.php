<?php

namespace App\Services\Onboarding;

use App\Models\Company;
use App\Models\OnboardingFicha;
use App\Models\OnboardingPasso;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * OnboardingFichaService — registro da ficha da conta pelas DUAS portas.
 *
 * O formulário é um só; o que muda é por onde ele chega:
 *  - `ORIGEM_CLIENTE` — o próprio cliente, pelo link público (sem login).
 *  - `ORIGEM_EQUIPE`  — alguém da equipe pelo painel, tipicamente em call.
 *
 * Guardar a procedência é o ponto: "o cliente digitou" e "o analista digitou
 * ouvindo o cliente" têm confiabilidade diferente na hora de comparar o
 * declarado com o que a API apurar depois. Sem esse campo, os dois viram a
 * mesma linha no banco.
 *
 * Reenviar SOBRESCREVE a ficha da empresa (uma por empresa), inclusive a
 * procedência — se o cliente mandou pela metade e a equipe completou na call,
 * a ficha passa a ser da equipe, que foi quem falou por último.
 */
class OnboardingFichaService
{
    public function __construct(private OnboardingEngineService $engine)
    {
    }

    /**
     * Grava (ou regrava) a ficha da empresa e reavalia os onboardings vivos —
     * é a reavaliação que fecha o passo `ficha_conta_preenchida` via resolver,
     * nunca uma escrita direta de status aqui.
     *
     * @param  array<string, mixed>  $dados  Só as chaves de OnboardingFicha::CAMPOS_RESPOSTA são consideradas.
     */
    public function registrar(
        Company $company,
        array $dados,
        string $origem,
        ?User $usuario = null,
        ?string $ip = null,
    ): OnboardingFicha {
        if (! in_array($origem, OnboardingFicha::ORIGENS, true)) {
            throw new \InvalidArgumentException("Origem \"{$origem}\" fora do catálogo fechado de OnboardingFicha::ORIGENS.");
        }

        $ficha = DB::transaction(function () use ($company, $dados, $origem, $usuario, $ip) {
            $atributos = collect(OnboardingFicha::CAMPOS_RESPOSTA)
                ->mapWithKeys(fn (string $campo) => [
                    // `array_key_exists` e não `??`: o formulário pode enviar
                    // null de propósito ("não sei"), e isso é uma resposta —
                    // diferente de não mandar a chave.
                    $campo => array_key_exists($campo, $dados) ? $dados[$campo] : null,
                ])
                ->all();

            return OnboardingFicha::updateOrCreate(
                ['company_id' => $company->id],
                array_merge($atributos, [
                    'origem'         => $origem,
                    'preenchida_por' => $usuario?->id,
                    'preenchida_em'  => now(),
                    'ip'             => $ip,
                ]),
            );
        });

        $this->reavaliarOnboardingsDaEmpresa($company);

        return $ficha->fresh();
    }

    /**
     * A ficha é por EMPRESA e o passo é por ONBOARDING: uma empresa com dois
     * serviços contratados tem o passo da ficha em cada um, e a mesma ficha
     * fecha os dois.
     */
    private function reavaliarOnboardingsDaEmpresa(Company $company): void
    {
        $onboardings = $company->onboardings()
            ->naoConcluido()
            ->get();

        foreach ($onboardings as $onboarding) {
            $passo = OnboardingPasso::where('onboarding_id', $onboarding->id)
                ->where('auto_fonte', OnboardingPasso::AUTO_FONTE_FICHA_CONTA)
                ->first();

            if (! $passo) {
                continue;
            }

            // Resolver local, sem rede — pode rodar inline, não precisa de Job.
            $resolver = app(OnboardingResolverFactory::class)->for(OnboardingPasso::AUTO_FONTE_FICHA_CONTA);
            $this->engine->aplicarResultado($passo, $resolver->resolver($onboarding, $passo));
        }
    }
}
