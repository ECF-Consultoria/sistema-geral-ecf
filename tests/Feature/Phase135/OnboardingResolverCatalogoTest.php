<?php

namespace Tests\Feature\Phase135;

use App\Models\OnboardingPasso;
use App\Services\Onboarding\OnboardingResolverFactory;
use App\Services\Onboarding\OnboardingResolverResultado;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Fase 135 Plano 03 — catálogo fechado de resolvers automáticos (D-09) e
 * resultado de 3 estados (D-11).
 *
 * Prova que `auto_fonte` desconhecido falha alto (nunca `null`, nunca um
 * resolver padrão) e que `OnboardingResolverResultado` não expõe nenhuma
 * porta de fuga booleana — nem no construtor, nem nos named constructors
 * estáticos.
 */
class OnboardingResolverCatalogoTest extends TestCase
{
    // ─── Catálogo fechado (D-09) ────────────────────────────────────────────

    /** @test */
    public function for_com_chave_desconhecida_lanca_runtime_exception(): void
    {
        $factory = app(OnboardingResolverFactory::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/nao_existe/');

        $factory->for('nao_existe');
    }

    /** @test */
    public function existe_devolve_false_para_chave_fora_do_catalogo(): void
    {
        $factory = app(OnboardingResolverFactory::class);

        $this->assertFalse($factory->existe('nao_existe'));
    }

    /** @test */
    public function toda_chave_do_catalogo_esta_em_onboarding_passo_auto_fontes(): void
    {
        $factory = app(OnboardingResolverFactory::class);

        foreach ($factory->catalogo() as $entrada) {
            $this->assertContains($entrada['chave'], OnboardingPasso::AUTO_FONTES);
        }
    }

    /** @test */
    public function catalogo_traz_label_e_ajuda_nao_vazios_para_cada_entrada(): void
    {
        $factory = app(OnboardingResolverFactory::class);

        foreach ($factory->catalogo() as $entrada) {
            $this->assertGreaterThan(0, strlen($entrada['label']), "label vazio para {$entrada['chave']}");
            $this->assertGreaterThan(0, strlen($entrada['ajuda']), "ajuda vazia para {$entrada['chave']}");
        }
    }

    /** @test */
    public function factory_e_singleton_no_container(): void
    {
        $a = app(OnboardingResolverFactory::class);
        $b = app(OnboardingResolverFactory::class);

        $this->assertSame($a, $b);
    }

    // ─── OnboardingResolverResultado: sem porta de fuga booleana (D-11) ─────

    /** @test */
    public function resultado_nao_expoe_construtor_publico_nem_named_constructor_booleano(): void
    {
        $reflection = new ReflectionClass(OnboardingResolverResultado::class);

        $construtor = $reflection->getConstructor();
        $this->assertNotNull($construtor);
        $this->assertFalse(
            $construtor->isPublic(),
            'O construtor deve ser privado — só os named constructors estáticos criam instâncias.'
        );

        foreach ($reflection->getMethods(ReflectionMethod::IS_STATIC | ReflectionMethod::IS_PUBLIC) as $metodo) {
            foreach ($metodo->getParameters() as $parametro) {
                $tipo = $parametro->getType();
                $nomeTipo = $tipo instanceof ReflectionNamedType ? $tipo->getName() : null;

                $this->assertNotSame(
                    'bool',
                    $nomeTipo,
                    "Named constructor {$metodo->getName()}() não pode aceitar parâmetro booleano ({$parametro->getName()})."
                );
            }
        }
    }

    /** @test */
    public function nao_coletado_sem_chave_reservada_nao_sinaliza_coleta_em_andamento(): void
    {
        $resultado = OnboardingResolverResultado::naoColetado('sem grant ainda');

        $this->assertFalse($resultado->sinalizouColetaEmAndamento());
    }

    /** @test */
    public function nao_coletado_com_chave_reservada_true_sinaliza_coleta_em_andamento(): void
    {
        $resultado = OnboardingResolverResultado::naoColetado('coleta disparada', [
            OnboardingResolverResultado::CHAVE_COLETA_EM_ANDAMENTO => true,
        ]);

        $this->assertTrue($resultado->sinalizouColetaEmAndamento());
    }

    /** @test */
    public function concluido_com_chave_reservada_nunca_sinaliza_coleta_em_andamento(): void
    {
        // A chave só vale no estado NAO_COLETADO — em CONCLUIDO ela é
        // ignorada, mesmo presente no array de valor.
        $resultado = OnboardingResolverResultado::concluido([
            OnboardingResolverResultado::CHAVE_COLETA_EM_ANDAMENTO => true,
        ]);

        $this->assertFalse($resultado->sinalizouColetaEmAndamento());
    }
}
