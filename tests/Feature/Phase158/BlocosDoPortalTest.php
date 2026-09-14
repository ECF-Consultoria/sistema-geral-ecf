<?php

namespace Tests\Feature\Phase158;

use App\Http\Controllers\OnboardingPublicoController;
use App\Models\Company;
use App\Models\Onboarding;
use App\Models\OnboardingInvestimento;
use App\Models\OnboardingRelatorio;
use App\Models\Servico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 14/09 — anotações da reunião e investimento, operados no portal.
 *
 * O que este teste protege:
 *
 * 1. As rotas existem SÓ autenticadas. Não pode haver porta anônima para um
 *    bloco que é registro da ECF.
 * 2. O payload do portal entrega um bloco por onboarding em andamento, e o
 *    rótulo com o nome do serviço só quando há mais de um — senão a tela
 *    repetiria o nome do serviço sem necessidade.
 * 3. O relatório do portal leva SÓ os três campos de anotação. O relatório
 *    interno tem mais coisa e um botão de gerar documento; a decisão de 14/09
 *    foi explícita em não levar isso para o cliente.
 */
class BlocosDoPortalTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();
    }

    private function empresaComOnboardings(int $quantos): Company
    {
        $n = str_pad((string) (++self::$seq), 4, '0', STR_PAD_LEFT);

        $empresa = Company::factory()->create([
            'active' => true,
            'name'   => 'Empresa Blocos '.$n,
            'cnpj'   => "15.815.815/{$n}-82",
        ]);

        for ($i = 1; $i <= $quantos; $i++) {
            $servico = Servico::create([
                'nome'          => "Serviço Blocos {$n}-{$i}",
                'valor_padrao'  => 100,
                'tipo_cobranca' => Servico::TIPO_MENSAL,
                'ativo'         => true,
                'setor'         => Servico::SETOR_OUTROS,
            ]);

            Onboarding::create([
                'company_id'  => $empresa->id,
                'servico_id'  => $servico->id,
                'status'      => Onboarding::STATUS_ANDAMENTO,
                'iniciado_em' => now(),
            ]);
        }

        return $empresa;
    }

    /** @return array<int, array<string, mixed>> */
    private function blocos(Company $company): array
    {
        $metodo = new \ReflectionMethod(OnboardingPublicoController::class, 'blocosDeOperacao');
        $metodo->setAccessible(true);

        return $metodo->invoke(app(OnboardingPublicoController::class), $company);
    }

    // ─── As rotas ───────────────────────────────────────────────────────────

    public function test_as_rotas_dos_blocos_existem_so_autenticadas(): void
    {
        foreach (['portal.auth.onboarding.relatorio', 'portal.auth.onboarding.investimento'] as $nome) {
            $this->assertNotNull(Route::getRoutes()->getByName($nome), "rota {$nome} não existe");
        }

        // A ausência é o ponto: bloco de registro da ECF não pode ter porta
        // por token, que é anônima.
        foreach (['onboarding.publico.relatorio', 'onboarding.publico.investimento'] as $nome) {
            $this->assertNull(
                Route::getRoutes()->getByName($nome),
                "rota {$nome} não deveria existir — o modo por token é anônimo"
            );
        }
    }

    // ─── O payload ──────────────────────────────────────────────────────────

    public function test_um_bloco_por_onboarding_em_andamento(): void
    {
        $blocos = $this->blocos($this->empresaComOnboardings(2));

        $this->assertCount(2, $blocos);
    }

    public function test_rotulo_so_aparece_quando_ha_mais_de_um_servico(): void
    {
        $um = $this->blocos($this->empresaComOnboardings(1));
        $this->assertNull($um[0]['rotulo'], 'com um serviço só, repetir o nome dele é ruído');
        $this->assertNotNull($um[0]['servico']);

        $dois = $this->blocos($this->empresaComOnboardings(2));
        $this->assertNotNull($dois[0]['rotulo']);
        $this->assertNotNull($dois[1]['rotulo']);
    }

    public function test_relatorio_do_portal_leva_so_os_tres_campos_de_anotacao(): void
    {
        $empresa = $this->empresaComOnboardings(1);
        $onboardingId = Onboarding::where('company_id', $empresa->id)->value('id');

        OnboardingRelatorio::create([
            'onboarding_id'   => $onboardingId,
            'dados'           => [],
            'gerado_em'       => now(),
            'pontos_atencao'  => 'conta sem histórico',
            'oportunidades'   => 'catálogo grande',
            'proximos_passos' => 'subir 20 anúncios',
        ]);

        $bloco = $this->blocos($empresa)[0];

        $this->assertSame(
            ['pontos_atencao', 'oportunidades', 'proximos_passos'],
            array_keys($bloco['relatorio'])
        );
        $this->assertSame('conta sem histórico', $bloco['relatorio']['pontos_atencao']);
    }

    public function test_investimento_chega_com_os_quatro_campos_mesmo_vazio(): void
    {
        $bloco = $this->blocos($this->empresaComOnboardings(1))[0];

        $this->assertSame(
            ['investimento_disponivel', 'investimento_mensal_previsto', 'investimento_publicidade', 'observacoes'],
            array_keys($bloco['investimento'])
        );
        // Vazio é `null`, nunca chave ausente: a tela decide entre "—" e campo
        // em branco, e chave faltando quebraria o `??` do JSX.
        $this->assertNull($bloco['investimento']['investimento_disponivel']);
    }

    public function test_valor_registrado_chega_ao_bloco(): void
    {
        $empresa = $this->empresaComOnboardings(1);
        $onboardingId = Onboarding::where('company_id', $empresa->id)->value('id');

        OnboardingInvestimento::create([
            'onboarding_id'           => $onboardingId,
            'investimento_disponivel' => 1500,
            'observacoes'             => 'vai começar devagar',
            'informado_em'            => now(),
            'informado_canal'         => 'interno_call',
        ]);

        $bloco = $this->blocos($empresa)[0];

        $this->assertEquals(1500, $bloco['investimento']['investimento_disponivel']);
        $this->assertSame('vai começar devagar', $bloco['investimento']['observacoes']);
    }

    /** Onboarding concluído não é assunto do portal — mesma régua dos passos. */
    public function test_onboarding_concluido_nao_vira_bloco(): void
    {
        $empresa = $this->empresaComOnboardings(1);
        Onboarding::where('company_id', $empresa->id)->update(['status' => Onboarding::STATUS_CONCLUIDO]);

        $this->assertSame([], $this->blocos($empresa));
    }
}
