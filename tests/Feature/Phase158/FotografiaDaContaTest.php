<?php

namespace Tests\Feature\Phase158;

use App\Models\Company;
use App\Models\Onboarding;
use App\Models\OnboardingFotografiaConta;
use App\Models\Servico;
use App\Models\User;
use App\Services\MercadoLivreService;
use App\Services\Onboarding\FotografiaContaService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Fotografia da Conta — o retrato de faturamento do Mercado Livre.
 *
 * Substituiu "Métricas da conta" e a ficha de mapeamento: os dois diziam "como
 * está a conta" de formas diferentes.
 *
 * O que estes testes protegem, em ordem de importância:
 *
 * 1. Falha da API de terceiro NÃO derruba nada — vira linha com `erro`, e a
 *    tela pode dizer o que houve. Retrato vazio faria "o ML recusou o token"
 *    parecer "o cliente não vendeu nada".
 * 2. O corte antes/depois é decidido no SERVIDOR, e congelado no retrato.
 * 3. Os totais dos cards saem da MESMA série do gráfico — card e barra não
 *    podem divergir.
 */
class FotografiaDaContaTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    private function empresa(): Company
    {
        $n = str_pad((string) (++self::$seq), 4, '0', STR_PAD_LEFT);

        return Company::factory()->create([
            'active' => true,
            'name'   => 'Empresa Foto '.$n,
            'cnpj'   => "15.815.815/{$n}-83",
        ]);
    }

    private function comOnboardingIniciadoHa(Company $company, int $dias): Onboarding
    {
        $n = str_pad((string) (++self::$seq), 4, '0', STR_PAD_LEFT);

        $servico = Servico::create([
            'nome'          => 'Serviço Foto '.$n,
            'valor_padrao'  => 100,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_OUTROS,
        ]);

        return Onboarding::create([
            'company_id'  => $company->id,
            'servico_id'  => $servico->id,
            'status'      => Onboarding::STATUS_ANDAMENTO,
            'iniciado_em' => now()->subDays($dias),
        ]);
    }

    /** Um ML falso que devolve o mesmo resumo para qualquer semana. */
    private function mlQueDevolve(float $revenue, int $pedidos = 3, int $itens = 5): void
    {
        $this->instance(MercadoLivreService::class, new class($revenue, $pedidos, $itens) extends MercadoLivreService {
            public function __construct(private float $r, private int $p, private int $i)
            {
            }

            public function fetchOrdersSummary(Company $company, string $dateFrom, string $dateTo): array
            {
                return [
                    'revenue'       => $this->r,
                    'sold_quantity' => $this->i,
                    'orders_count'  => $this->p,
                    'sales_fee'     => 0.0,
                    'raw_orders'    => [],
                ];
            }
        });
    }

    private function mlQueFalha(string $mensagem): void
    {
        $this->instance(MercadoLivreService::class, new class($mensagem) extends MercadoLivreService {
            public function __construct(private string $msg)
            {
            }

            public function fetchOrdersSummary(Company $company, string $dateFrom, string $dateTo): array
            {
                throw new \RuntimeException($this->msg);
            }
        });
    }

    private function service(): FotografiaContaService
    {
        return app(FotografiaContaService::class);
    }

    // ─── Falha de terceiro ──────────────────────────────────────────────────

    public function test_falha_do_mercado_livre_vira_linha_com_erro_e_nao_excecao(): void
    {
        $empresa = $this->empresa();
        $this->mlQueFalha('token expirado');

        $foto = $this->service()->coletar($empresa);

        $this->assertNotNull($foto->id, 'a linha tem de existir mesmo com falha');
        $this->assertSame('token expirado', $foto->erro);
        $this->assertNull($foto->serie);

        // A tela precisa distinguir isto de "não vendeu nada".
        $payload = $this->service()->paraPortal($empresa);
        $this->assertSame('token expirado', $payload['erro']);
    }

    // ─── A série e os totais ────────────────────────────────────────────────

    public function test_a_serie_tem_treze_semanas_e_os_cards_somam_a_mesma_serie(): void
    {
        $empresa = $this->empresa();
        $this->mlQueDevolve(1000.0);

        $foto = $this->service()->coletar($empresa);

        $this->assertCount(FotografiaContaService::SEMANAS, $foto->serie);
        $this->assertNull($foto->erro);

        // 4 · 9 · 13 semanas × 1000 — card e gráfico contando a mesma coisa.
        $this->assertEqualsWithDelta(4000.0,  $foto->janelas['30']['faturamento'], 0.01);
        $this->assertEqualsWithDelta(9000.0,  $foto->janelas['60']['faturamento'], 0.01);
        $this->assertEqualsWithDelta(13000.0, $foto->janelas['90']['faturamento'], 0.01);
    }

    public function test_cada_semana_cobre_sete_dias_sem_buraco_nem_sobreposicao(): void
    {
        $empresa = $this->empresa();
        $this->mlQueDevolve(500.0);

        $serie = $this->service()->coletar($empresa)->serie;

        foreach ($serie as $i => $semana) {
            $inicio = CarbonImmutable::parse($semana['inicio']);
            $fim    = CarbonImmutable::parse($semana['fim']);

            // `diffInDays` devolve float no Carbon 3 — comparar por valor.
            $this->assertEquals(6, $inicio->diffInDays($fim), "semana {$i} não cobre 7 dias");

            if ($i > 0) {
                $fimAnterior = CarbonImmutable::parse($serie[$i - 1]['fim']);
                $this->assertEquals(
                    1,
                    $fimAnterior->diffInDays($inicio),
                    "há buraco ou sobreposição entre a semana {$i} e a anterior"
                );
            }
        }
    }

    // ─── O corte antes/depois ───────────────────────────────────────────────

    public function test_o_corte_vem_do_inicio_do_onboarding_e_separa_as_semanas(): void
    {
        $empresa = $this->empresa();
        $this->comOnboardingIniciadoHa($empresa, 30);
        $this->mlQueDevolve(700.0);

        $this->service()->coletar($empresa);
        $payload = $this->service()->paraPortal($empresa);

        $this->assertSame(now()->subDays(30)->toDateString(), $payload['corte_em']);

        // ~4 semanas depois, ~9 antes. A régua é `fim >= corte`, então o
        // limite exato depende de onde a semana cai — o que importa é os dois
        // lados existirem e somarem 13.
        $this->assertGreaterThan(0, $payload['semanas_antes']);
        $this->assertGreaterThan(0, $payload['semanas_depois']);
        $this->assertSame(
            FotografiaContaService::SEMANAS,
            $payload['semanas_antes'] + $payload['semanas_depois']
        );
    }

    public function test_sem_onboarding_iniciado_nao_ha_corte(): void
    {
        $empresa = $this->empresa();
        $this->mlQueDevolve(200.0);

        $this->service()->coletar($empresa);
        $payload = $this->service()->paraPortal($empresa);

        $this->assertNull($payload['corte_em'], 'a ECF ainda não operou — não há o que cortar');
        $this->assertSame(0, $payload['semanas_depois']);
        $this->assertNull($payload['media_depois']);
    }

    /**
     * O corte é COPIADO no retrato, não derivado na leitura: retrato antigo
     * tem de continuar contando a história que contava quando foi tirado.
     */
    public function test_mudar_o_inicio_do_onboarding_nao_reescreve_retrato_antigo(): void
    {
        $empresa = $this->empresa();
        $onboarding = $this->comOnboardingIniciadoHa($empresa, 30);
        $this->mlQueDevolve(100.0);

        $foto = $this->service()->coletar($empresa);
        $corteOriginal = $foto->corte_em->toDateString();

        $onboarding->update(['iniciado_em' => now()->subDays(80)]);

        $this->assertSame(
            $corteOriginal,
            OnboardingFotografiaConta::find($foto->id)->corte_em->toDateString()
        );
    }

    // ─── Histórico ──────────────────────────────────────────────────────────

    /**
     * Sem histórico, o "antes da ECF" se perde: a janela de 90 dias anda, e
     * daqui a seis meses nenhuma alcança o período anterior.
     */
    public function test_coletar_de_novo_guarda_as_duas_e_a_tela_le_a_mais_recente(): void
    {
        $empresa = $this->empresa();

        $this->mlQueDevolve(100.0);
        $primeira = $this->service()->coletar($empresa);

        $this->travel(1)->days();

        $this->mlQueDevolve(900.0);
        $segunda = $this->service()->coletar($empresa);

        $this->assertSame(2, OnboardingFotografiaConta::where('company_id', $empresa->id)->count());
        $this->assertSame($segunda->id, OnboardingFotografiaConta::maisRecenteDe($empresa->id)->id);
        $this->assertSame($primeira->id, OnboardingFotografiaConta::linhaDeBaseDe($empresa->id)->id);

        $this->travelBack();
    }

    // ─── A porta ────────────────────────────────────────────────────────────

    public function test_a_rota_da_coleta_existe_so_autenticada(): void
    {
        $this->assertNotNull(Route::getRoutes()->getByName('portal.auth.onboarding.fotografia'));

        // A coleta gasta 13 chamadas à API do cliente — não pode ter porta
        // anônima, senão qualquer um com o link queima a cota dele.
        $this->assertNull(Route::getRoutes()->getByName('onboarding.publico.fotografia'));
    }

    public function test_sem_coleta_o_payload_e_nulo(): void
    {
        $this->assertNull($this->service()->paraPortal($this->empresa()));
    }

    public function test_a_autoria_da_coleta_fica_registrada(): void
    {
        $empresa = $this->empresa();
        $membro = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $this->mlQueDevolve(300.0);

        $this->service()->coletar($empresa, $membro);
        $payload = $this->service()->paraPortal($empresa);

        $this->assertSame($membro->name, $payload['coletado_por']);
    }
}
