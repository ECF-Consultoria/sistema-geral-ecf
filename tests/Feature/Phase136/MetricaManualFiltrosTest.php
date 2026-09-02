<?php

namespace Tests\Feature\Phase136;

use App\Models\Company;
use App\Models\Servico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Filtros de marketplace e de colaborador na grade de métricas manuais
 * (2026-09-02).
 *
 * O que estes testes vigiam, e por quê:
 *  - o filtro recorta o PAR `(empresa, canal)`, não a empresa inteira. Conta
 *    atendida no Mercado Livre por uma pessoa e na Shopee por outra tem de
 *    aparecer, na carteira de cada uma, só com a linha do canal dela — senão
 *    o filtro "carteira" mostraria trabalho de outro time;
 *  - `multi_canal` continua verdadeiro mesmo com o outro canal escondido: o
 *    selo "2 canais" é o único aviso de que existe outra linha, e ele não
 *    pode sumir junto com o filtro;
 *  - quem aparece no seletor de colaborador SEMPRE tem linha para mostrar —
 *    o seletor é derivado do mesmo universo da grade.
 *
 * `Http::preventStrayRequests()`: nenhum cenário aqui tem lançamento, e sem
 * lançamento a grade não resolve valor de API (T-136-17). Um GET que
 * dispare HTTP à Adman falha o teste, como deve.
 *
 * @see app/Http/Controllers/DesempenhoMetricasManuaisController.php
 */
class MetricaManualFiltrosTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private User $ana;
    private User $bruno;
    private Company $soMl;
    private Company $soShopee;
    private Company $doisCanais;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-11 10:00:00'));
        Http::preventStrayRequests();
        $this->withoutVite();

        $performance = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $shopee      = $this->criarServico(Servico::SETOR_SHOPEE, true);

        $this->ana   = User::factory()->create(['name' => 'Ana Performance', 'role' => 'consultor', 'active' => true]);
        $this->bruno = User::factory()->create(['name' => 'Bruno Shopee', 'role' => 'consultor', 'active' => true]);

        $this->doisCanais = Company::factory()->create(['active' => true, 'name' => 'Loja Dois Canais']);
        $this->soMl       = Company::factory()->create(['active' => true, 'name' => 'Loja So ML']);
        $this->soShopee   = Company::factory()->create(['active' => true, 'name' => 'Loja So Shopee']);

        // Ana atende o Mercado Livre das duas contas; Bruno, a Shopee das duas
        // outras. Só "Loja Dois Canais" é atendida pelos dois — é ela que
        // separa "recorte por empresa" de "recorte por par (empresa, canal)".
        $this->inserirPivot($this->soMl->id, $this->ana->id, 'consultor', $performance);
        $this->inserirPivot($this->doisCanais->id, $this->ana->id, 'consultor', $performance);
        $this->inserirPivot($this->soShopee->id, $this->bruno->id, 'consultor', $shopee);
        $this->inserirPivot($this->doisCanais->id, $this->bruno->id, 'consultor', $shopee);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    /** @param array<string, mixed> $filtros */
    private function grade(array $filtros = [])
    {
        return $this->actingAs($this->admin())
            ->get(route('desempenho.metricas-manuais.index', $filtros))
            ->assertOk();
    }

    // === Linha de base: sem filtro, a grade inteira ========================

    #[Test]
    public function sem_filtro_a_grade_traz_uma_linha_por_empresa_e_canal(): void
    {
        $this->grade()->assertInertia(fn (AssertableInertia $page) => $page
            // 3 empresas, 4 linhas — "Loja Dois Canais" rende duas.
            ->has('empresas', 4)
            ->where('empresas.0.company_name', 'Loja Dois Canais')
            ->where('empresas.0.fonte', 'adman')
            ->where('empresas.1.company_name', 'Loja Dois Canais')
            ->where('empresas.1.fonte', 'shopee')
            ->where('empresas.2.company_name', 'Loja So ML')
            ->where('empresas.3.company_name', 'Loja So Shopee')
            ->where('fonte', null)
            ->where('colaborador', null)
            ->etc()
        );
    }

    // === Filtro por marketplace ===========================================

    #[Test]
    public function filtro_por_marketplace_deixa_so_as_linhas_daquele_canal(): void
    {
        $this->grade(['fonte' => 'shopee'])->assertInertia(fn (AssertableInertia $page) => $page
            ->has('empresas', 2)
            ->where('empresas.0.company_name', 'Loja Dois Canais')
            ->where('empresas.0.fonte', 'shopee')
            ->where('empresas.1.company_name', 'Loja So Shopee')
            ->where('empresas.1.fonte', 'shopee')
            ->where('fonte', 'shopee')
            ->etc()
        );

        $this->grade(['fonte' => 'adman'])->assertInertia(fn (AssertableInertia $page) => $page
            ->has('empresas', 2)
            ->where('empresas.0.fonte', 'adman')
            ->where('empresas.1.company_name', 'Loja So ML')
            ->etc()
        );
    }

    /**
     * A empresa some da grade, mas o canal escondido continua existindo para
     * quem sobrou: `fontes` e `multi_canal` são a verdade da EMPRESA, não do
     * recorte. Sem isso o selo "2 canais" sumiria e o admin lançaria achando
     * que aquela conta tem um time só.
     */
    #[Test]
    public function canal_escondido_pelo_filtro_continua_declarado_na_linha_que_ficou(): void
    {
        $this->grade(['fonte' => 'shopee'])->assertInertia(fn (AssertableInertia $page) => $page
            ->where('empresas.0.company_name', 'Loja Dois Canais')
            ->where('empresas.0.multi_canal', true)
            ->where('empresas.0.fontes', ['adman', 'shopee'])
            ->etc()
        );
    }

    #[Test]
    public function marketplace_fora_da_whitelist_e_recusado_na_validacao(): void
    {
        $this->actingAs($this->admin())
            ->get(route('desempenho.metricas-manuais.index', ['fonte' => 'amazon']))
            ->assertSessionHasErrors(['fonte']);
    }

    // === Filtro por colaborador ===========================================

    #[Test]
    public function filtro_por_colaborador_traz_apenas_as_empresas_da_carteira_dele(): void
    {
        $this->grade(['colaborador' => $this->ana->id])->assertInertia(fn (AssertableInertia $page) => $page
            ->has('empresas', 2)
            ->where('empresas.0.company_name', 'Loja Dois Canais')
            ->where('empresas.1.company_name', 'Loja So ML')
            ->where('colaborador', $this->ana->id)
            ->etc()
        );

        $this->grade(['colaborador' => $this->bruno->id])->assertInertia(fn (AssertableInertia $page) => $page
            ->has('empresas', 2)
            ->where('empresas.0.company_name', 'Loja Dois Canais')
            ->where('empresas.1.company_name', 'Loja So Shopee')
            ->etc()
        );
    }

    /**
     * O caso que separa recorte por empresa de recorte por par: na conta de
     * dois canais, Ana só atende o Mercado Livre. A linha da Shopee é do time
     * do Bruno e NÃO pode aparecer na carteira dela.
     */
    #[Test]
    public function em_conta_de_dois_canais_a_carteira_traz_so_o_canal_do_colaborador(): void
    {
        $this->grade(['colaborador' => $this->ana->id])->assertInertia(fn (AssertableInertia $page) => $page
            ->where('empresas.0.company_name', 'Loja Dois Canais')
            ->where('empresas.0.fonte', 'adman')
            ->where('empresas.0.multi_canal', true)
            ->etc()
        );

        $this->grade(['colaborador' => $this->bruno->id])->assertInertia(fn (AssertableInertia $page) => $page
            ->where('empresas.0.company_name', 'Loja Dois Canais')
            ->where('empresas.0.fonte', 'shopee')
            ->etc()
        );
    }

    #[Test]
    public function marketplace_e_colaborador_se_acumulam_em_vez_de_um_anular_o_outro(): void
    {
        // Ana não atende Shopee em lugar nenhum — a interseção é vazia.
        $this->grade(['colaborador' => $this->ana->id, 'fonte' => 'shopee'])
            ->assertInertia(fn (AssertableInertia $page) => $page->has('empresas', 0)->etc());

        // E a interseção que existe devolve exatamente uma linha.
        $this->grade(['colaborador' => $this->ana->id, 'fonte' => 'adman', 'busca' => 'So ML'])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('empresas', 1)
                ->where('empresas.0.company_name', 'Loja So ML')
                ->etc()
            );
    }

    #[Test]
    public function colaborador_sem_carteira_financeira_devolve_grade_vazia_e_nao_entra_no_seletor(): void
    {
        // Vínculo em setor sem fonte financeira (Polos) — não produz linha de
        // Desempenho, então também não é filtro possível.
        $polos   = User::factory()->create(['name' => 'Zeca Polos', 'role' => 'consultor', 'active' => true]);
        $empresa = Company::factory()->create(['active' => true, 'name' => 'Loja Polos']);
        $this->inserirPivot($empresa->id, $polos->id, 'consultor', $this->criarServico(Servico::SETOR_POLOS, true));

        $this->grade(['colaborador' => $polos->id])->assertInertia(fn (AssertableInertia $page) => $page
            ->has('empresas', 0)
            ->has('colaboradores', 2)
            ->etc()
        );
    }

    // === Opções dos seletores =============================================

    #[Test]
    public function o_seletor_de_colaborador_conta_empresas_distintas_e_vem_ordenado_por_nome(): void
    {
        // Segundo vínculo do MESMO par (empresa, colaborador) em outro serviço
        // do mesmo setor: a pivot tem uma linha por serviço desde a Fase 76, e
        // um COUNT(*) diria "3 empresas" onde há 2.
        $this->inserirPivot(
            $this->soMl->id,
            $this->ana->id,
            'estrategista',
            $this->criarServico(Servico::SETOR_PERFORMANCE, true)
        );

        $this->grade()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('colaboradores', 2)
            ->where('colaboradores.0.nome', 'Ana Performance')
            ->where('colaboradores.0.total_empresas', 2)
            ->where('colaboradores.0.ativo', true)
            ->where('colaboradores.1.nome', 'Bruno Shopee')
            ->where('colaboradores.1.total_empresas', 2)
            ->etc()
        );
    }

    /**
     * Profissional desligado continua dono dos vínculos na pivot. Escondê-lo
     * do seletor tornaria a carteira dele inalcançável pelo filtro — ele entra
     * marcado, não some.
     */
    #[Test]
    public function colaborador_inativo_continua_no_seletor_marcado_como_inativo(): void
    {
        $this->bruno->update(['active' => false]);

        $this->grade()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('colaboradores', 2)
            ->where('colaboradores.1.nome', 'Bruno Shopee')
            ->where('colaboradores.1.ativo', false)
            ->etc()
        );
    }

    #[Test]
    public function o_seletor_de_marketplace_expoe_os_dois_canais_com_rotulo_humano(): void
    {
        $this->grade()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('fontes', [
                ['valor' => 'adman',  'label' => 'Mercado Livre'],
                ['valor' => 'shopee', 'label' => 'Shopee'],
            ])
            ->etc()
        );
    }
}
