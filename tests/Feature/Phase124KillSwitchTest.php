<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\Servico;
use App\Services\Operacional\EmpresaOperacionalRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 124 Plano 04 — prova o interruptor de emergência da milestone v22.0
 * (REDE-01) nos DOIS lados, exercitando `EmpresaOperacionalRouter`
 * DIRETAMENTE, sem HTTP.
 *
 * Ligado, o roteamento automático PARA (nenhuma `MlbEmpresa` nem
 * `MlbImplementacao` nasce). Desligado, o roteamento é o de sempre. A
 * chave está desligada em produção e continua assim até a Fase 133 — o
 * objetivo deste arquivo é que o mecanismo esteja PROVADO antes de a
 * operação passar a depender dele.
 *
 * Este arquivo NÃO entra no gate de regressão de 6 arquivos (o baseline
 * congelado dos planos 124-03/124-05): ele testa comportamento NOVO, que
 * por definição não existia antes da refatoração — incluí-lo faria o diff
 * nominal acusar diferença legítima como se fosse regressão.
 */
class Phase124KillSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Polos', 'Assessoria', 'Incubadora'] as $nome) {
            Servico::firstOrCreate(
                ['nome' => $nome],
                ['valor_padrao' => 0, 'tipo_cobranca' => 'mensal', 'ativo' => true],
            );
        }
    }

    private function criarEmpresa(string $nome = 'Empresa Teste Interruptor'): Company
    {
        return Company::create(['name' => $nome]);
    }

    private function router(): EmpresaOperacionalRouter
    {
        return app(EmpresaOperacionalRouter::class);
    }

    /**
     * O nome da chave é contrato entre as Fases 124, 128, 131 e 133.
     * Mudá-lo em silêncio quebraria a tela do admin (Fase 131), que aciona
     * o botão por este literal.
     */
    public function test_chave_do_interruptor_tem_o_nome_acordado(): void
    {
        $this->assertSame('administrativo_bloqueio_ativo', EmpresaOperacionalRouter::CHAVE_BLOQUEIO);
    }

    /**
     * Sem nenhuma linha gravada na tabela `configuracoes`, o interruptor
     * está desligado. Este é o "default false" do D-04 do CONTEXT: não há
     * migration de seed — o desligado vem do default de `Configuracao::get`
     * dentro do próprio router.
     */
    public function test_interruptor_nasce_desligado(): void
    {
        $router = $this->router();

        $this->assertFalse($router->bloqueioAtivo());
    }

    /**
     * Com a chave LIGADA, `rotearCadastro()` (mecânica do Comercial) não
     * cria NADA — nem `MlbEmpresa`, nem `MlbImplementacao`. Este é o teste
     * que prova que o interruptor funciona de verdade, não decorativamente.
     */
    public function test_interruptor_ligado_impede_o_roteamento_do_cadastro(): void
    {
        Configuracao::set(EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '1');

        $company = $this->criarEmpresa();

        $this->router()->rotearCadastro($company, ['Polos']);

        $this->assertSame(0, MlbEmpresa::count());
        $this->assertSame(0, MlbImplementacao::count());
    }

    /**
     * Mesma chave ligada, mas pelo segundo ponto de entrada —
     * `rotearServico()` (mecânica do webhook HubSpot). A leitura do
     * interruptor é única (D-05), mas os dois caminhos públicos precisam
     * parar igualmente.
     */
    public function test_interruptor_ligado_impede_o_roteamento_por_servico(): void
    {
        Configuracao::set(EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '1');

        $company = $this->criarEmpresa();

        $this->router()->rotearServico($company, 'Polos');

        $this->assertSame(0, MlbEmpresa::count());
        $this->assertSame(0, MlbImplementacao::count());
    }

    /**
     * Com a chave DESLIGADA (estado de produção), o roteamento continua
     * criando a ficha normalmente — prova que o `return` do bloqueio não
     * vazou nem afetou o caminho desligado.
     */
    public function test_interruptor_desligado_roteia_como_sempre(): void
    {
        Configuracao::set(EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '0');

        $company = $this->criarEmpresa();

        $this->router()->rotearCadastro($company, ['Polos']);

        $mlbEmp = MlbEmpresa::where('company_id', $company->id)->where('tipo', 'POLO')->first();
        $this->assertNotNull($mlbEmp, 'Com o interruptor desligado, a ficha POLO deve ser criada');
        $this->assertSame('POLOS', $mlbEmp->projeto);

        $this->assertNotNull(
            MlbImplementacao::where('empresa_id', $mlbEmp->id)->first(),
            'Com o interruptor desligado, a MlbImplementacao deve ser criada',
        );
    }
}
