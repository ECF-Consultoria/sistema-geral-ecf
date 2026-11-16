<?php

// Quais categorias do ML contam para a meta dos Polos (TKT-0036, DEV-76, 16/11/2026).
//
// Até out/2026 só contava a raiz "Casa, Móveis e Decoração". A Débora pediu Construção,
// Indústria e Comércio, Bebês e Brinquedos > Mesas e Cadeiras; o resto da lista dela
// (Banheiros, Camas, Cozinha, Organização, Móveis para Jardim) já mora dentro de Casa e
// Móveis. Decisão do Maycon: lista configurável, valendo de nov/2026, sem recalcular mês
// fechado. Estes testes travam as três coisas, passando pelo job do warm de verdade (o
// que grava o snapshot que o /polos serve) com a API de categorias do ML falsa.

namespace Tests\Feature\Polos;

use App\Jobs\SyncPolosFaturamentoJob;
use App\Models\Configuracao;
use App\Models\MlbEmpresa;
use App\Models\PoloFaturamentoSnapshot;
use App\Services\AdmanService;
use App\Services\MlCategoriaService;
use App\Services\Polos\CategoriasDaMetaPolos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** @group polos */
class CategoriasDaMetaTest extends TestCase
{
    use RefreshDatabase;

    private const CUST = '2425054445';

    /** Pedaço da árvore real do ML (ids conferidos na API pública em 16/11/2026). */
    private const ARVORE = [
        'MLB1574'   => ['MLB1574'],                          // Casa, Móveis e Decoração
        'MLB436380' => ['MLB1574', 'MLB436380'],             //   > Móveis para Casa
        'MLB1500'   => ['MLB1500'],                          // Construção
        'MLB269958' => ['MLB1500', 'MLB269958'],             //   > Mobiliário para Cozinhas
        'MLB1499'   => ['MLB1499'],                          // Indústria e Comércio
        'MLB268412' => ['MLB1499', 'MLB268412'],             //   > Gastronomia e Hotelaria
        'MLB1384'   => ['MLB1384'],                          // Bebês
        'MLB420361' => ['MLB1384', 'MLB420361'],             //   > Quarto do Bebê
        'MLB1132'   => ['MLB1132'],                          // Brinquedos e Hobbies
        'MLB270072' => ['MLB1132', 'MLB270072'],             //   > Mesas e Cadeiras
        'MLB999001' => ['MLB1132', 'MLB270072', 'MLB999001'], //     > (folha)
        'MLB1166'   => ['MLB1132', 'MLB1166'],               //   > Pelúcias
        'MLB1071'   => ['MLB1071'],                          // Animais (Pet Shop)
        'MLB1072'   => ['MLB1071', 'MLB1072'],
    ];

    /** Vendas do mês por categoria: uma em cada canto da árvore. */
    private const VENDAS = [
        'MLB436380' => 100.0, // móveis — sempre conta
        'MLB269958' => 200.0, // Construção
        'MLB268412' => 300.0, // Indústria e Comércio > Gastronomia e Hotelaria
        'MLB420361' => 400.0, // Bebês
        'MLB999001' => 500.0, // Brinquedos > Mesas e Cadeiras > folha
        'MLB1166'   => 600.0, // Brinquedos, FORA de Mesas e Cadeiras
        'MLB1072'   => 700.0, // Pet Shop
        'MLB000404' => 800.0, // categoria que o ML não resolve
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.adman.polos_pausa_ms' => 0]);
        // Nem out/2026 nem nov/2026 são o mês corrente: o job não lê status de campanha.
        Carbon::setTestNow('2027-01-10 10:00:00');

        Http::fake(function ($request) {
            $id = basename((string) parse_url($request->url(), PHP_URL_PATH));
            $caminho = self::ARVORE[$id] ?? null;

            return $caminho === null
                ? Http::response(['message' => 'Category not found'], 404)
                : Http::response([
                    'id' => $id,
                    'path_from_root' => array_map(fn ($c) => ['id' => $c, 'name' => $c], $caminho),
                ]);
        });

        MlbEmpresa::create([
            'nome' => 'Loja Teste', 'fase' => 'M2', 'projeto' => 'POLOS', 'cust_id' => self::CUST,
            'polo' => 'Arapongas', 'estagio' => 'Não Listado', 'problema' => false,
        ]);

        $this->mock(AdmanService::class, function ($m) {
            $m->shouldReceive('fetchPerformanceBreakdown')
                ->andReturn(['gross_billing' => 3600.0, 'net_por_categoria' => self::VENDAS]);
            $m->shouldReceive('fetchAdsInvestmentTotal')->andReturn(0.0);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Roda o warm do mês e devolve o que ficou gravado como faturamento da meta. */
    private function warm(string $de, string $ate, string $mes): float
    {
        (new SyncPolosFaturamentoJob($de, $ate, null))
            ->handle(app(AdmanService::class), new MlCategoriaService());

        $snap = PoloFaturamentoSnapshot::where('mes', $mes)->where('cust_id', self::CUST)->first();
        $this->assertNotNull($snap, "o warm de {$mes} tinha de gravar o snapshot");
        $this->assertEqualsWithDelta(3600.0, $snap->faturamento, 0.001, 'o gross da conta não muda');

        return (float) $snap->faturamento_moveis;
    }

    public function test_novembro_soma_construcao_industria_bebes_e_mesas_e_cadeiras(): void
    {
        $daMeta = $this->warm('2026-11-01', '2026-11-30', '202611');

        // 100 móveis + 200 Construção + 300 Gastronomia + 400 Bebês + 500 Mesas e Cadeiras.
        // Fora: Pelúcias (outro galho de Brinquedos), Pet Shop e a categoria sem resposta.
        $this->assertEqualsWithDelta(1500.0, $daMeta, 0.001);
    }

    public function test_outubro_fechado_continua_so_com_moveis(): void
    {
        // Mesmo com a lista configurada: o botão Sincronizar de mês fechado não reescreve
        // o passado com a regra nova.
        Configuracao::set(CategoriasDaMetaPolos::CHAVE, implode(',', CategoriasDaMetaPolos::PADRAO));

        $this->assertEqualsWithDelta(100.0, $this->warm('2026-10-01', '2026-10-31', '202610'), 0.001);
    }

    public function test_lista_configurada_restringe_sem_deploy(): void
    {
        // Ex.: se "Construção" tiver de ser só o mobiliário de cozinha.
        Configuracao::set(CategoriasDaMetaPolos::CHAVE, 'mlb1574, MLB269958');

        $this->assertEqualsWithDelta(300.0, $this->warm('2026-11-01', '2026-11-30', '202611'), 0.001);
    }

    public function test_configuracao_sem_id_valido_cai_no_padrao(): void
    {
        Configuracao::set(CategoriasDaMetaPolos::CHAVE, 'construção, ;;');

        $this->assertSame(CategoriasDaMetaPolos::PADRAO, CategoriasDaMetaPolos::doMes('202611'));
        $this->assertSame(['MLB1574'], CategoriasDaMetaPolos::doMes('202610'));
    }

    public function test_item_fora_das_raizes_da_meta_nao_busca_o_caminho_inteiro(): void
    {
        // O warm passa por milhares de itens: quem a raiz já decide não pode custar
        // chamada nova ao ML. Só o brinquedo (raiz de Mesas e Cadeiras) busca o caminho.
        $servico = new MlCategoriaService();
        $alvos = CategoriasDaMetaPolos::PADRAO;

        $this->assertFalse($servico->pertenceAAlguma('MLB1072', $alvos));
        $this->assertTrue($servico->pertenceAAlguma('MLB269958', $alvos));
        $this->assertFalse($servico->pertenceAAlguma('MLB1166', $alvos));
        $this->assertTrue($servico->pertenceAAlguma('MLB999001', $alvos));

        $pedidos = collect(Http::recorded())
            ->map(fn ($par) => basename((string) parse_url($par[0]->url(), PHP_URL_PATH)))
            ->countBy();

        $this->assertSame(1, $pedidos['MLB1072'] ?? 0, 'Pet Shop: só a raiz');
        $this->assertSame(1, $pedidos['MLB269958'] ?? 0, 'Construção: só a raiz');
        $this->assertSame(2, $pedidos['MLB1166'] ?? 0, 'Pelúcias: raiz + caminho');
        $this->assertSame(2, $pedidos['MLB999001'] ?? 0, 'folha de Mesas e Cadeiras: raiz + caminho');
    }
}
