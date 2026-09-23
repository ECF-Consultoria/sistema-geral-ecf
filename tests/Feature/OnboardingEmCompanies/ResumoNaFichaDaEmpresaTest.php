<?php

namespace Tests\Feature\OnboardingEmCompanies;

use App\Models\Company;
use App\Models\Onboarding;
use App\Models\OnboardingConfirmacao;
use App\Models\OnboardingContato;
use App\Models\OnboardingInvestimento;
use App\Models\OnboardingPasso;
use App\Models\OnboardingRelatorio;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Destino das informações do onboarding (23/09/2026): a ficha da empresa.
 *
 * O que prende: o que foi coletado continua visível DEPOIS de o onboarding
 * concluir — que é justamente quando o portal deixa de mostrar —, com os
 * rótulos novos do investimento; rascunho não entra; e o link para editar só
 * vai para quem abre a ficha do onboarding.
 */
class ResumoNaFichaDaEmpresaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    private function empresaComOnboardingConcluido(): array
    {
        $empresa = Company::create(['name' => 'Loja Resumo', 'cnpj' => '11222333000181', 'active' => true]);

        $servico = Servico::create([
            'nome'          => 'Gestão Resumo',
            'valor_padrao'  => 100,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_OUTROS,
        ]);

        $onboarding = Onboarding::create([
            'company_id'   => $empresa->id,
            'servico_id'   => $servico->id,
            'status'       => Onboarding::STATUS_CONCLUIDO,
            'iniciado_em'  => now()->subMonth(),
            'concluido_em' => now()->subDay(),
        ]);

        OnboardingInvestimento::create([
            'onboarding_id'                => $onboarding->id,
            'investimento_disponivel'      => 10000,
            'investimento_mensal_previsto' => 3000,
            'investimento_publicidade'     => 0,
            'observacoes'                  => 'Sazonal no Natal',
        ]);

        OnboardingRelatorio::create([
            'onboarding_id'   => $onboarding->id,
            'dados'           => [],
            'gerado_em'       => now(),
            'pontos_atencao'  => 'Reputação amarela',
            'proximos_passos' => 'Revisar fichas técnicas',
        ]);

        OnboardingPasso::create([
            'onboarding_id' => $onboarding->id,
            'ordem'         => 30,
            'etapa'         => 'publicidade',
            'natureza'      => OnboardingPasso::NATUREZA_REUNIAO,
            'chave'         => 'publicidade_processo_explicado',
            'titulo'        => 'Processo de publicidade explicado',
            'dono'          => OnboardingPasso::DONO_INTERNO,
            'auto_fonte'    => OnboardingPasso::AUTO_FONTE_CONFIRMACAO,
            'status'        => OnboardingPasso::STATUS_CONCLUIDO,
        ]);

        OnboardingConfirmacao::create([
            'onboarding_id' => $onboarding->id,
            'chave'         => 'publicidade_processo_explicado',
            'resposta'      => OnboardingConfirmacao::RESPOSTA_SIM,
            'observacoes'   => 'Entendeu a verba',
            'respondido_em' => now(),
        ]);

        OnboardingContato::create([
            'onboarding_id' => $onboarding->id,
            'papel'         => OnboardingContato::PAPEL_PONTO_CONTATO,
            'nome'          => 'Maria Dona',
            'email'         => 'maria@loja.test',
        ]);

        return [$empresa, $onboarding];
    }

    public function test_o_coletado_aparece_na_ficha_da_empresa_depois_de_concluido(): void
    {
        [$empresa, $onboarding] = $this->empresaComOnboardingConcluido();

        $props = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('companies.show', $empresa))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertCount(1, $props['onboarding_resumo']);
        $r = $props['onboarding_resumo'][0];

        $this->assertSame('concluido', $r['status']);
        $this->assertEquals(10000, $r['investimento']['disponivel']);
        $this->assertEquals(3000, $r['investimento']['objetivo']);
        // Zero é resposta — não pode virar "não registrado".
        $this->assertEquals(0, $r['investimento']['ultimos_90']);
        $this->assertSame('Reputação amarela', $r['anotacoes']['pontos_atencao']);
        $this->assertSame(
            [['titulo' => 'Processo de publicidade explicado', 'resposta' => 'sim', 'observacoes' => 'Entendeu a verba']],
            $r['alinhados'],
        );
        $this->assertSame('Maria Dona', $r['contatos'][0]['nome']);
        $this->assertSame(route('onboarding.painel.show', $onboarding->id), $r['url']);
    }

    public function test_rascunho_nao_entra_e_empresa_sem_onboarding_nao_tem_secao(): void
    {
        $empresa = Company::create(['name' => 'Loja Sem Onb', 'cnpj' => '11222333000262', 'active' => true]);
        $servico = Servico::create([
            'nome' => 'Serviço X', 'valor_padrao' => 1, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true, 'setor' => Servico::SETOR_OUTROS,
        ]);
        Onboarding::create(['company_id' => $empresa->id, 'servico_id' => $servico->id, 'status' => Onboarding::STATUS_RASCUNHO]);

        $props = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('companies.show', $empresa))
            ->viewData('page')['props'];

        $this->assertSame([], $props['onboarding_resumo']);
        $this->assertNull($props['fotografia_da_conta']);
    }

    /** Quem não abre a ficha do onboarding lê o resumo, mas não recebe link que daria 403. */
    public function test_sem_permissao_de_onboarding_nao_recebe_o_link(): void
    {
        [$empresa] = $this->empresaComOnboardingConcluido();

        $semPermissao = User::factory()->create(['role' => 'consultor']);
        $this->assertFalse($semPermissao->hasPermission(\App\Support\Permissions::CORE_ONBOARDING));

        $resumo = app(\App\Services\Onboarding\ResumoOnboardingService::class)->daEmpresa($empresa, $semPermissao);

        $this->assertNull($resumo[0]['url']);
        $this->assertNotNull($resumo[0]['investimento']);
    }
}
