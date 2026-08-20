<?php

namespace Tests\Feature\OnboardingEmCompanies;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Support\Onboarding\DefinicaoOnboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `natureza` — o terceiro eixo do passo: COMO o item se preenche
 * (`acao` | `reuniao` | `pergunta`).
 *
 * O teste que mais importa aqui é o do CATÁLOGO: `montarPassos()` copia
 * `etapa`/`dono` crus da definição, sem validar nada — um valor mal escrito
 * entra silencioso no banco e só aparece como bloco vazio na tela, semanas
 * depois. Como não vale pagar validação em runtime num INSERT em lote, a
 * trava é aqui.
 */
class OnboardingNaturezaTest extends TestCase
{
    use RefreshDatabase;

    private function servicoDeGestao(): Servico
    {
        return Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('nome', 'like', '%Gestão%')
            ->firstOrFail();
    }

    private function onboardingNovo(): Onboarding
    {
        $company = Company::create([
            'name'   => 'Empresa Natureza '.uniqid(),
            'cnpj'   => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true,
            'status' => 'ativo',
        ]);

        $contrato = ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $this->servicoDeGestao()->id,
            'valor_contratado' => 1500,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);

        return Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail();
    }

    /** @return array<int, array<string, mixed>> */
    private function passosDaDefinicao(): array
    {
        $metodo = new \ReflectionMethod(DefinicaoOnboarding::class, 'gestao');
        $metodo->setAccessible(true);

        return $metodo->invoke(null);
    }

    /** @test */
    public function a_coluna_natureza_existe(): void
    {
        $this->assertTrue(Schema::hasColumn('onboarding_passos', 'natureza'));
    }

    /**
     * A trava contra valor mal escrito na definição — `montarPassos()` não
     * valida catálogo, então é aqui ou em lugar nenhum.
     *
     * @test
     */
    public function todo_passo_da_definicao_declara_uma_natureza_do_catalogo(): void
    {
        foreach ($this->passosDaDefinicao() as $passo) {
            $this->assertArrayHasKey(
                'natureza',
                $passo,
                "O passo '{$passo['chave']}' não declara natureza."
            );

            $this->assertContains(
                $passo['natureza'],
                OnboardingPasso::NATUREZAS,
                "O passo '{$passo['chave']}' tem natureza fora do catálogo: '{$passo['natureza']}'."
            );
        }
    }

    /** @test */
    public function onboarding_novo_nasce_com_a_natureza_de_cada_passo_gravada(): void
    {
        $onboarding = $this->onboardingNovo();

        $porChave = $onboarding->passos->keyBy('chave');
        $this->assertNotEmpty($porChave);

        foreach ($this->passosDaDefinicao() as $definicao) {
            $passo = $porChave->get($definicao['chave']);

            $this->assertNotNull($passo, "Passo '{$definicao['chave']}' não foi montado.");
            $this->assertSame(
                $definicao['natureza'],
                $passo->natureza,
                "A natureza do passo '{$definicao['chave']}' não foi copiada no nascimento."
            );
        }
    }

    /**
     * Congelamento: o passo carrega a natureza com que NASCEU. Mudar a receita
     * em código não mexe em quem já está rodando — é a mesma propriedade que
     * `etapa` tem, e o motivo de o eixo ser coluna e não consulta ao código.
     *
     * @test
     */
    public function mudar_a_natureza_de_um_passo_ja_montado_exige_tocar_a_linha(): void
    {
        $onboarding = $this->onboardingNovo();
        $passo = $onboarding->passos->firstWhere('chave', 'grant_sistema_ecf');

        $this->assertSame(OnboardingPasso::NATUREZA_ACAO, $passo->natureza);

        // Reavaliar (que é o que roda em produção o tempo todo) não reescreve
        // a definição copiada.
        app(\App\Services\Onboarding\OnboardingEngineService::class)->reavaliar($onboarding);

        $this->assertSame(
            OnboardingPasso::NATUREZA_ACAO,
            $passo->fresh()->natureza,
            'reavaliar() não pode reescrever a definição congelada do passo.'
        );
    }

    /** Linha anterior ao eixo (natureza nula) se comporta como `acao`. */
    /** @test */
    public function passo_sem_natureza_chega_ao_payload_como_acao(): void
    {
        $onboarding = $this->onboardingNovo();
        $passo = $onboarding->passos->first();

        // Simula a linha que já existia antes da coluna.
        DB::table('onboarding_passos')->where('id', $passo->id)->update(['natureza' => null]);

        $admin = \App\Models\User::create([
            'name'     => 'Admin '.uniqid(),
            'email'    => 'admin.nat.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);

        $this->actingAs($admin)
            ->get(route('onboarding.painel.show', $onboarding))
            ->assertOk()
            ->assertInertia(function (\Inertia\Testing\AssertableInertia $page) use ($passo) {
                $passos = collect($page->toArray()['props']['passos']);
                $alvo = $passos->firstWhere('chave', $passo->chave);

                $this->assertSame(OnboardingPasso::NATUREZA_ACAO, $alvo['natureza']);
            });
    }

    /** @test */
    public function a_versao_da_definicao_subiu_junto_com_o_eixo(): void
    {
        $this->assertSame(16, DefinicaoOnboarding::VERSAO);
        // Carimbo do onboarding novo acompanha a constante — comparar com a
        // constante em vez de repetir o número evita este teste virar duas
        // manutenções a cada versão que sobe.
        $this->assertSame(DefinicaoOnboarding::VERSAO, $this->onboardingNovo()->definicao_versao);
    }
}
