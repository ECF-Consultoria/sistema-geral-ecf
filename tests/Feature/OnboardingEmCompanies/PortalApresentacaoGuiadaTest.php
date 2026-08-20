<?php

namespace Tests\Feature\OnboardingEmCompanies;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingConfirmacao;
use App\Models\OnboardingLink;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Apresentação guiada do portal (v15): o cliente lê publicidade/ADMAN e
 * confirma "entendi", fechando os passos.
 *
 * ### O que este teste protege de verdade
 * A rota nova é PÚBLICA, sem senha, e fecha passo por CHAVE. Sem trava de
 * escopo ela viraria uma porta para qualquer um com o link fechar qualquer
 * passo do onboarding — inclusive os nossos. Os testes de recusa abaixo valem
 * mais do que o de sucesso.
 */
class PortalApresentacaoGuiadaTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Company,1:Onboarding,2:string} */
    private function empresaEmAndamento(): array
    {
        $servico = Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('nome', 'like', '%Gestão%')
            ->firstOrFail();

        $company = Company::create([
            'name'              => 'Empresa Apres '.uniqid(),
            'cnpj'              => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active'            => true,
            'status'            => 'ativo',
            'email_colaborador' => 'colab.'.uniqid().'@ecf.test',
            'adman_account_id'  => (string) random_int(100000, 999999),
            'empresa_nova'      => false,
        ]);

        $contrato = ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 1500,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);

        $onboarding = Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail();

        $responsavel = User::create([
            'name'     => 'Resp '.uniqid(),
            'email'    => 'resp.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'consultor',
            'active'   => true,
        ]);

        app(OnboardingEngineService::class)->definirResponsaveis($onboarding, null, $responsavel);

        $token = OnboardingLink::firstOrCreate(
            ['company_id' => $company->id],
            ['token' => Str::random(48)]
        )->token;

        return [$company, $onboarding->fresh(), $token];
    }

    private function passo(Onboarding $onboarding, string $chave): OnboardingPasso
    {
        return OnboardingPasso::where('onboarding_id', $onboarding->id)
            ->where('chave', $chave)
            ->firstOrFail();
    }

    private const PUBLICIDADE = [
        'publicidade_processo_explicado',
        'publicidade_investimento_explicado',
        'publicidade_operacao_explicada',
        'publicidade_responsabilidades_alinhadas',
    ];

    /** @test */
    public function entendi_fecha_os_itens_da_secao_e_grava_a_confirmacao(): void
    {
        [, $onboarding, $token] = $this->empresaEmAndamento();

        foreach (self::PUBLICIDADE as $chave) {
            $this->assertSame(
                OnboardingPasso::STATUS_ABERTO,
                $this->passo($onboarding, $chave)->status,
                "{$chave} deveria nascer aberto para o cliente na v15."
            );
        }

        $this->post(route('onboarding.publico.confirmacao', $token), ['chaves' => self::PUBLICIDADE])
            ->assertRedirect();

        foreach (self::PUBLICIDADE as $chave) {
            $this->assertSame(
                OnboardingPasso::STATUS_CONCLUIDO,
                $this->passo($onboarding, $chave)->status,
                "{$chave} não fechou depois do \"Entendi\"."
            );

            $this->assertDatabaseHas('onboarding_confirmacoes', [
                'onboarding_id' => $onboarding->id,
                'chave'         => $chave,
                'resposta'      => 'sim',
            ]);
        }
    }

    /**
     * Confirmar publicidade não pode fechar ADMAN. Cada seção tem o seu botão.
     *
     * @test
     */
    public function confirmar_uma_secao_nao_toca_a_outra(): void
    {
        [, $onboarding, $token] = $this->empresaEmAndamento();

        $this->post(route('onboarding.publico.confirmacao', $token), ['chaves' => self::PUBLICIDADE])
            ->assertRedirect();

        $this->assertSame(
            OnboardingPasso::STATUS_ABERTO,
            $this->passo($onboarding, 'adman_uso_explicado')->status,
            'Confirmar publicidade fechou um item de ADMAN.'
        );
    }

    /**
     * A trava que importa: passo NOSSO não fecha por esta rota, mesmo que o
     * cliente mande a chave certa. `reuniao_realizada` é `dono=interno`.
     *
     * @test
     */
    public function cliente_nao_fecha_passo_interno_mandando_a_chave(): void
    {
        [, $onboarding, $token] = $this->empresaEmAndamento();

        $antes = $this->passo($onboarding, 'reuniao_realizada')->status;

        $this->post(route('onboarding.publico.confirmacao', $token), [
            'chaves' => ['reuniao_realizada', 'adman_preenchimento_interno'],
        ])->assertRedirect();

        $this->assertSame(
            $antes,
            $this->passo($onboarding, 'reuniao_realizada')->status,
            'O cliente fechou um passo interno pelo portal público.'
        );

        $this->assertDatabaseMissing('onboarding_confirmacoes', [
            'onboarding_id' => $onboarding->id,
            'chave'         => 'reuniao_realizada',
        ]);
    }

    /**
     * Token de uma empresa não fecha passo de outra — é a mesma classe de
     * falha de trocar o id na URL, só que por chave.
     *
     * @test
     */
    public function token_de_uma_empresa_nao_alcanca_o_onboarding_de_outra(): void
    {
        [, , $tokenA] = $this->empresaEmAndamento();
        [, $onboardingB] = $this->empresaEmAndamento();

        $this->post(route('onboarding.publico.confirmacao', $tokenA), ['chaves' => self::PUBLICIDADE])
            ->assertRedirect();

        foreach (self::PUBLICIDADE as $chave) {
            $this->assertSame(
                OnboardingPasso::STATUS_ABERTO,
                $this->passo($onboardingB, $chave)->status,
                "O token da empresa A fechou \"{$chave}\" da empresa B."
            );
        }
    }

    /**
     * Chave inventada é ignorada em silêncio, sem erro: devolver 422 daria ao
     * cliente um oráculo para descobrir quais chaves existem do lado de dentro.
     *
     * @test
     */
    public function chave_inexistente_e_ignorada_sem_erro_e_sem_gravar(): void
    {
        [, $onboarding, $token] = $this->empresaEmAndamento();

        $this->post(route('onboarding.publico.confirmacao', $token), ['chaves' => ['nao_existe_isso']])
            ->assertRedirect();

        $this->assertSame(
            0,
            OnboardingConfirmacao::where('onboarding_id', $onboarding->id)
                ->where('chave', 'nao_existe_isso')
                ->count()
        );
    }

    /** @test */
    public function token_invalido_da_404(): void
    {
        $this->post(route('onboarding.publico.confirmacao', 'token-que-nao-existe'), [
            'chaves' => self::PUBLICIDADE,
        ])->assertNotFound();
    }
}
