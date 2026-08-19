<?php

namespace Tests\Feature\OnboardingEmCompanies;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `onboarding:aplicar-passos-novos` — o caminho de volta que faltava.
 *
 * Existe porque a definição é copiada no nascimento: acrescentar um passo à
 * régua não o dá a ninguém que já existe. Sem ele, régua nova vale só para
 * empresas futuras.
 *
 * Os testes cobrem os três modos de errar que o comando precisa impedir:
 * duplicar linha, reescrever o que já estava congelado, e inserir um passo
 * que nasce bloqueado para sempre por depender de chave inexistente.
 */
class AplicarPassosNovosTest extends TestCase
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
            'name'   => 'Empresa Aplicar '.uniqid(),
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

    /** Simula o onboarding que nasceu ANTES de um passo entrar na régua. */
    private function removerPasso(Onboarding $onboarding, string $chave): void
    {
        OnboardingPasso::where('onboarding_id', $onboarding->id)
            ->where('chave', $chave)
            ->delete();
    }

    /** @test */
    public function dry_run_e_o_padrao_e_nao_grava_nada(): void
    {
        $onboarding = $this->onboardingNovo();
        $this->removerPasso($onboarding, 'custos_app_ecf');

        $antes = $onboarding->passos()->count();

        $this->artisan('onboarding:aplicar-passos-novos')
            ->expectsOutputToContain('Dry-run')
            ->assertSuccessful();

        $this->assertSame($antes, $onboarding->passos()->count(), 'Dry-run gravou.');
    }

    /** @test */
    public function apply_insere_apenas_a_chave_que_falta(): void
    {
        $onboarding = $this->onboardingNovo();
        $this->removerPasso($onboarding, 'custos_app_ecf');

        $antes = $onboarding->passos()->count();

        $this->artisan('onboarding:aplicar-passos-novos --apply')->assertSuccessful();

        $this->assertSame($antes + 1, $onboarding->passos()->count());
        $this->assertNotNull(
            OnboardingPasso::where('onboarding_id', $onboarding->id)->where('chave', 'custos_app_ecf')->first()
        );
    }

    /**
     * O modo de errar mais provável: rodar duas vezes. A unique
     * (onboarding_id, chave) existe, mas ela não pode ser QUEM descobre o
     * problema — exceção no meio do laço deixaria o trabalho pela metade.
     *
     * @test
     */
    public function rodar_duas_vezes_nao_duplica_nem_estoura(): void
    {
        $onboarding = $this->onboardingNovo();
        $this->removerPasso($onboarding, 'custos_app_ecf');

        $this->artisan('onboarding:aplicar-passos-novos --apply')->assertSuccessful();
        $depoisDaPrimeira = $onboarding->passos()->count();

        $this->artisan('onboarding:aplicar-passos-novos --apply')->assertSuccessful();

        $this->assertSame($depoisDaPrimeira, $onboarding->passos()->count());
        $this->assertSame(
            1,
            OnboardingPasso::where('onboarding_id', $onboarding->id)->where('chave', 'custos_app_ecf')->count()
        );
    }

    /**
     * O congelamento continua valendo para quem já estava lá: o comando
     * ACRESCENTA, nunca atualiza linha existente.
     *
     * @test
     */
    public function nao_reescreve_passo_que_ja_existe(): void
    {
        $onboarding = $this->onboardingNovo();
        $this->removerPasso($onboarding, 'custos_app_ecf');

        $intocado = OnboardingPasso::where('onboarding_id', $onboarding->id)
            ->where('chave', 'grant_sistema_ecf')
            ->firstOrFail();

        // Título "antigo", como se a régua tivesse mudado o texto depois.
        DB::table('onboarding_passos')->where('id', $intocado->id)->update([
            'titulo'   => 'Título congelado no nascimento',
            'sla_dias' => 99,
        ]);

        $this->artisan('onboarding:aplicar-passos-novos --apply')->assertSuccessful();

        $depois = $intocado->fresh();
        $this->assertSame('Título congelado no nascimento', $depois->titulo);
        $this->assertSame(99, $depois->sla_dias);
    }

    /**
     * Passo cuja dependência não existe naquele onboarding é PULADO, não
     * inserido bloqueado para sempre — a imagem espelhada do bug que o comando
     * de remoção resolve.
     *
     * @test
     */
    public function passo_com_dependencia_ausente_e_pulado_com_aviso(): void
    {
        $onboarding = $this->onboardingNovo();

        // `anuncios_ativos_inativos` depende de `grant_sistema_ecf`. Tirando os
        // dois, o dependente volta a faltar mas a dependência não.
        $this->removerPasso($onboarding, 'anuncios_ativos_inativos');
        $this->removerPasso($onboarding, 'grant_sistema_ecf');

        // Pede só o dependente: a dependência continua ausente depois desta passada.
        $this->artisan('onboarding:aplicar-passos-novos --apply --chave=anuncios_ativos_inativos')
            ->expectsOutputToContain('PULADO')
            ->assertSuccessful();

        $this->assertNull(
            OnboardingPasso::where('onboarding_id', $onboarding->id)
                ->where('chave', 'anuncios_ativos_inativos')
                ->first(),
            'Passo com dependência ausente não pode ser inserido — nasceria bloqueado para sempre.'
        );
    }

    /** Passo novo que depende de OUTRO passo novo da mesma leva é legítimo. */
    /** @test */
    public function dependencia_satisfeita_pela_propria_leva_e_aceita(): void
    {
        $onboarding = $this->onboardingNovo();

        $this->removerPasso($onboarding, 'anuncios_ativos_inativos');
        $this->removerPasso($onboarding, 'grant_sistema_ecf');

        // Sem --chave: os dois entram juntos, e a dependência é satisfeita
        // pela própria passada.
        $this->artisan('onboarding:aplicar-passos-novos --apply')->assertSuccessful();

        $this->assertNotNull(
            OnboardingPasso::where('onboarding_id', $onboarding->id)
                ->where('chave', 'anuncios_ativos_inativos')
                ->first()
        );
    }

    /** @test */
    public function onboarding_concluido_nao_e_reaberto_por_padrao(): void
    {
        $onboarding = $this->onboardingNovo();
        $this->removerPasso($onboarding, 'custos_app_ecf');
        $onboarding->update(['status' => Onboarding::STATUS_CONCLUIDO]);

        $antes = $onboarding->passos()->count();

        $this->artisan('onboarding:aplicar-passos-novos --apply')->assertSuccessful();
        $this->assertSame($antes, $onboarding->passos()->count());

        $this->artisan('onboarding:aplicar-passos-novos --apply --incluir-concluidos')->assertSuccessful();
        $this->assertSame($antes + 1, $onboarding->passos()->count());
    }

    /**
     * `definicao_versao` registra sob qual receita a empresa ENTROU —
     * reescrevê-la faria o registro mentir sobre a história do onboarding.
     *
     * @test
     */
    public function definicao_versao_nao_sobe_por_padrao(): void
    {
        $onboarding = $this->onboardingNovo();
        $this->removerPasso($onboarding, 'custos_app_ecf');

        DB::table('onboardings')->where('id', $onboarding->id)->update(['definicao_versao' => 7]);

        $this->artisan('onboarding:aplicar-passos-novos --apply')->assertSuccessful();
        $this->assertSame(7, $onboarding->fresh()->definicao_versao);

        $this->artisan('onboarding:aplicar-passos-novos --apply --carimbar-versao --chave=custos_app_ecf')
            ->assertSuccessful();
    }

    /** @test */
    public function o_filtro_por_empresa_nao_toca_as_outras(): void
    {
        $alvo = $this->onboardingNovo();
        $outro = $this->onboardingNovo();

        $this->removerPasso($alvo, 'custos_app_ecf');
        $this->removerPasso($outro, 'custos_app_ecf');

        $this->artisan('onboarding:aplicar-passos-novos --apply --company='.$alvo->company_id)
            ->assertSuccessful();

        $this->assertNotNull(
            OnboardingPasso::where('onboarding_id', $alvo->id)->where('chave', 'custos_app_ecf')->first()
        );
        $this->assertNull(
            OnboardingPasso::where('onboarding_id', $outro->id)->where('chave', 'custos_app_ecf')->first(),
            'O filtro por empresa vazou para outra empresa.'
        );
    }
}
