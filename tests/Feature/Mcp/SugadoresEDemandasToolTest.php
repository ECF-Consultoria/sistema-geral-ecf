<?php

namespace Tests\Feature\Mcp;

use App\Models\Company;
use App\Models\DevDemanda;
use App\Models\Sugador;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\DemandasDev\LiberaModulosDev;
use Tests\TestCase;

/**
 * `sugadores` e `demandas_dev`: mesmo recorte e mesmos contadores das telas
 * /sugadores e /dev/demandas.
 */
class SugadoresEDemandasToolTest extends TestCase
{
    use ChamaMcp, LiberaModulosDev, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Queue::fake();
        $this->withoutVite();
        Carbon::setTestNow('2026-10-05 15:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sugador(Company $c, string $data, string $status = Sugador::STATUS_PENDENTE): Sugador
    {
        return Sugador::create([
            'company_id' => $c->id, 'reference_date' => $data, 'tipo' => Sugador::TIPO_ADGROUP,
            'campaign_id' => 'C1', 'campaign_name' => 'Campanha 1', 'adgroup_id' => 'AG'.uniqid(),
            'adgroup_name' => 'Anúncio', 'periodo_inicio' => '2026-09-28', 'periodo_fim' => '2026-10-04',
            'investimento_periodo' => 150, 'faturamento_periodo' => 0, 'vendas_periodo' => 0,
            'cliques' => 10, 'impressoes' => 900, 'motivos' => ['gasto_sem_venda'], 'status' => $status,
        ]);
    }

    // ═══ sugadores ═══

    public function test_sugadores_respeita_a_carteira_e_bate_com_o_contador_da_tela(): void
    {
        $consultor = $this->comPermissoes(['core.sugadores']);
        $minha = $this->empresaPerformance(['name' => 'Minha Loja']);
        $outra = $this->empresaPerformance(['name' => 'Loja Alheia']);
        $this->vincular($minha, $consultor, 'consultor');

        $this->sugador($minha, '2026-10-05');
        $this->sugador($minha, '2026-10-01');
        $this->sugador($minha, '2026-10-01', Sugador::STATUS_RESOLVIDO);
        $this->sugador($outra, '2026-10-05');

        $doConsultor = $this->ferramenta($consultor, 'sugadores');
        $this->assertSame(2, $doConsultor['total']);
        $this->assertSame(['Minha Loja'], collect($doConsultor['itens'])->pluck('empresa.nome')->unique()->values()->all());
        // Hoje primeiro, como na tela; dias pendente contados da data da análise.
        $this->assertSame('2026-10-05', $doConsultor['itens'][0]['data_referencia']);
        $this->assertSame(4, $doConsultor['itens'][1]['dias_pendente']);
        $this->assertEquals(150, $doConsultor['itens'][0]['gasto']); // JSON não guarda o ".0"

        // Mesmo contador "pendentes" que a tela mostra para esta pessoa.
        $tela = $this->actingAs($consultor)->get(route('sugadores.index'))->viewData('page')['props'];
        $this->assertSame($tela['total_pendentes'], $doConsultor['resumo']['pendentes_total']);
        $this->assertSame('só a sua carteira', $doConsultor['resumo']['visao']);

        // Admin vê todas as empresas.
        $this->assertSame(3, $this->ferramenta($this->admin(), 'sugadores')['total']);
        $this->assertSame(2, $this->ferramenta($this->admin(), 'sugadores', ['apenas_hoje' => true])['total']);
    }

    public function test_sugadores_filtro_por_analista_e_so_de_admin(): void
    {
        $consultor = $this->comPermissoes(['core.sugadores']);

        $this->assertStringContainsString('só de admin', $this->erroDaFerramenta($consultor, 'sugadores', ['analista' => 'Fulano']));
    }

    public function test_sugadores_some_para_quem_nao_tem_a_permissao(): void
    {
        $semPermissao = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $this->assertNotContains('sugadores', $this->ferramentasVisiveis($semPermissao));
    }

    public function test_empresa_inexistente_da_mensagem_legivel(): void
    {
        $this->assertStringContainsString('Empresa não encontrada', $this->erroDaFerramenta($this->admin(), 'sugadores', ['empresa' => 'Não Existe Ltda']));
    }

    // ═══ demandas_dev ═══

    private function demanda(?User $responsavel, array $extra = []): DevDemanda
    {
        static $n = 0;
        $n++;

        return DevDemanda::create($extra + [
            'codigo'         => sprintf('DEV-%02d', $n),
            'titulo'         => "Demanda {$n}",
            'prioridade'     => 2,
            'data_entrada'   => '2026-09-19',
            'responsavel_id' => $responsavel?->id,
        ]);
    }

    public function test_demandas_nao_admin_ve_so_as_proprias_e_o_painel_bate_com_a_tela(): void
    {
        $this->liberarModulosDev();
        $admin = $this->admin();
        $dev   = User::factory()->create(['role' => 'consultor', 'active' => true, 'name' => 'Dev Fulano']);
        $outro = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $this->demanda($dev, ['prazo' => '2026-10-01', 'prioridade' => 0]);   // atrasada
        $this->demanda($dev, ['prazo' => '2026-10-30']);
        $this->demanda($outro);

        $doDev = $this->ferramenta($dev, 'demandas_dev');
        $this->assertSame(2, $doDev['total']);
        $this->assertSame(['Dev Fulano'], collect($doDev['itens'])->pluck('responsavel')->unique()->values()->all());

        $atrasadas = $this->ferramenta($dev, 'demandas_dev', ['atrasadas' => true]);
        $this->assertSame(1, $atrasadas['total']);
        $this->assertSame('P0 - Crítica', $atrasadas['itens'][0]['prioridade']);
        $this->assertSame(4, $atrasadas['itens'][0]['dias_atraso']);

        // O painel é o mesmo array que a tela recebe.
        $mcp  = $this->ferramenta($admin, 'demandas_dev');
        $tela = $this->actingAs($admin)->get(route('dev.demandas.index'))->viewData('page')['props'];
        $this->assertSame($tela['painel'], $mcp['painel']);
        $this->assertSame(3, $mcp['total']);

        $this->assertSame(1, $this->ferramenta($admin, 'demandas_dev', ['responsavel' => 'fulano', 'prioridade' => 'P0'])['total']);
    }

    public function test_demandas_some_para_quem_nao_tem_demanda(): void
    {
        $this->liberarModulosDev();
        $semDemanda = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $this->assertNotContains('demandas_dev', $this->ferramentasVisiveis($semDemanda));
    }
}
