<?php

namespace Tests\Feature\Polos;

use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\PoloFaturamentoSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Mcp\ChamaMcp;
use Tests\TestCase;

/**
 * TKT-0022 — lista enxuta dos sellers do Painel Polos (`mlb.polos-painel.sellers`), para o
 * `ler_tela` do MCP: o prop `empresas` do painel passa de 7 MB e uma empresa sozinha
 * estoura o tamanho máximo de resposta.
 *
 * O que os testes protegem (e não é óbvio no código):
 *   - Nada da ficha pesada sai (`dados` = planilha de produtos + checklist).
 *   - Faturamento, meta e status vêm do MESMO montarPolos() do /polos/empresas e seguem o
 *     `?mes=`; só quem abre aquela tela os recebe, e quem não abre nem toca o ECF Drive.
 *   - A região é sempre um dos 4 nomes oficiais: as grafias do CSV ("Rio Preto",
 *     "Bento Goncalves") não podem virar uma quinta e uma sexta região.
 *
 * @group polos
 */
class PainelSellersEnxutoTest extends TestCase
{
    use ChamaMcp, RefreshDatabase;

    /** Chaves de cada seller — o contrato que o artefato de gestão lê. */
    private const CHAVES = [
        'id', 'nome', 'cust_id', 'fase', 'estagio', 'regiao', 'responsavel',
        'faturamento', 'meta', 'status_meta', 'ads', 'ads_desligado', 'ads_campanha',
        'problema', 'problema_nota', 'problema_fora_da_meta',
        'places', 'decola', 'decola_ml_ativo', 'central_promocao', 'me1', 'listagem', 'publicacao',
        'acesso_colaborador', 'adman_vinculo', 'tem_ppa',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.ecf.base' => 'https://files.ecfconsultoria.com.br/api/v1',
            'services.ecf.key'  => 'test-key',
        ]);
        Cache::flush();
    }

    /** ECF Drive com o arquivo POLOS MENSAL e as linhas informadas (o mês corrente entra sempre). */
    private function ecfDrive(array $linhas = []): void
    {
        Http::fake([
            '*/files/*/json*' => Http::response([
                'filename' => 'SFTP_ECF_COMERCIO_POLOS_MENSAL.csv',
                'returned' => count($linhas),
                'limited'  => false,
                'rows'     => $linhas,
            ], 200),
            '*/files*' => Http::response([
                'data'  => [[
                    'id'           => 'arquivo-polos-01',
                    'filename'     => 'SFTP_ECF_COMERCIO_POLOS_MENSAL.csv',
                    'etlStatus'    => 'done',
                    'downloadedAt' => '2026-10-01T15:00:10Z',
                ]],
                'total' => 1,
            ], 200),
        ]);
    }

    private function empresa(string $nome, string $cust, array $opts = []): MlbEmpresa
    {
        return MlbEmpresa::create(array_merge([
            'nome'    => $nome,
            'tipo'    => 'POLO',
            'projeto' => 'POLOS',
            'fase'    => 'M2',
            'polo'    => 'Arapongas',
            'estagio' => 'Não Listado',
            'cust_id' => $cust,
        ], $opts));
    }

    private function faturou(string $cust, float $valor, ?string $mes = null, float $ads = 0): void
    {
        PoloFaturamentoSnapshot::create([
            'mes'                => $mes ?? now()->format('Ym'),
            'cust_id'            => $cust,
            'faturamento'        => $valor,
            'faturamento_moveis' => $valor,
            'ads'                => $ads,
            'synced_at'          => now(),
        ]);
    }

    private function sellers(User $user, array $query = []): array
    {
        return $this->actingAs($user)
            ->getJson(route('mlb.polos-painel.sellers', $query))
            ->assertOk()
            ->json();
    }

    private function seller(array $resposta, string $nome): array
    {
        $linha = collect($resposta['sellers'])->firstWhere('nome', $nome);
        $this->assertNotNull($linha, "seller {$nome} não veio na lista");

        return $linha;
    }

    // ─── Forma ───────────────────────────────────────────────────────────────

    public function test_traz_os_campos_pedidos_e_nada_da_ficha(): void
    {
        $admin    = User::factory()->create(['role' => 'admin']);
        $caroline = User::factory()->create(['name' => 'Caroline']);
        $this->ecfDrive();

        $alfa = $this->empresa('Loja Alfa', '2425054445', [
            'estagio' => 'Estágio 2', 'problema' => true, 'problema_nota' => 'Sem estoque',
        ]);
        MlbImplementacao::create([
            'empresa_id'         => $alfa->id,
            'token'              => 'tok-alfa',
            'responsavel_id'     => $caroline->id,
            'places'             => 'Ativo',
            'decola'             => 'Sim',
            'central_promocao'   => 'Não',
            'me1'                => 'Ativo',
            'listagem'           => 'Já listado',
            'publicacao'         => 'Concluído',
            'acesso_colaborador' => 'Com acesso',
            // A ficha pesada: tem de ficar fora da resposta.
            'dados'              => ['planilha_produtos' => ['valor' => str_repeat('produto ', 2000)]],
        ]);
        $this->faturou('2425054445', 1500, ads: 320.5);

        $resposta = $this->sellers($admin);

        $this->assertTrue($resposta['financeiro']);
        $this->assertSame(now()->format('Ym'), $resposta['mes']);
        $this->assertSame(MlbImplementacao::ONB_POLO_OPCOES, $resposta['regioes']);
        $this->assertSame(1, $resposta['total']);

        $linha = $this->seller($resposta, 'Loja Alfa');
        $this->assertSame(self::CHAVES, array_keys($linha));
        $this->assertStringNotContainsString('produto produto', json_encode($resposta));

        $this->assertSame('2425054445', $linha['cust_id']);
        $this->assertSame('M2', $linha['fase']);
        $this->assertSame('Estágio 2', $linha['estagio']);
        $this->assertSame('Arapongas', $linha['regiao']);
        $this->assertSame('Caroline', $linha['responsavel'], 'com ficha, o responsável é o da ficha (como no painel)');
        $this->assertEquals(1500, $linha['faturamento']);
        $this->assertEquals(1000, $linha['meta']);
        $this->assertSame('Sim', $linha['status_meta']);
        $this->assertEquals(320.5, $linha['ads']);
        $this->assertTrue($linha['problema']);
        $this->assertSame('Sem estoque', $linha['problema_nota']);
        $this->assertFalse($linha['problema_fora_da_meta'], 'problema comum não tira da meta');
        $this->assertSame('Ativo', $linha['places']);
        $this->assertSame('Sim', $linha['decola']);
        $this->assertSame('Não', $linha['central_promocao']);
        $this->assertSame('Ativo', $linha['me1']);
        $this->assertSame('Já listado', $linha['listagem']);
        $this->assertSame('Concluído', $linha['publicacao']);
        $this->assertSame('Com acesso', $linha['acesso_colaborador']);
        $this->assertFalse($linha['tem_ppa']);
    }

    public function test_universo_e_o_do_painel_e_fora_da_meta_fica_sem_financeiro(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $dono  = User::factory()->create(['name' => 'Dono da Empresa']);
        $this->ecfDrive();

        $this->empresa('Entrante M0', '111', ['fase' => 'M0', 'responsavel_id' => $dono->id]);
        $this->empresa('Arquivada', '222')->forceFill(['arquivado_em' => now()])->save();
        $this->empresa('Outro Projeto', '333', ['projeto' => 'MENTORIA']);

        $resposta = $this->sellers($admin);

        $this->assertSame(['Entrante M0'], array_column($resposta['sellers'], 'nome'));
        $m0 = $this->seller($resposta, 'Entrante M0');
        $this->assertSame('Dono da Empresa', $m0['responsavel'], 'sem ficha, o responsável é o da empresa');
        $this->assertNull($m0['faturamento'], 'M0 não está na meta do mês');
        $this->assertNull($m0['status_meta']);
        $this->assertNull($m0['places'], 'sem ficha não há campo da ficha');
    }

    public function test_cust_repetido_nao_passa_o_financeiro_para_quem_esta_fora_da_meta(): void
    {
        // Produção, 19/10/2026: "Moveis absoluto" (Encaminhar Comercial) tem o cust do
        // Natture (M2) e saía com o faturamento e o "Sim" dele.
        $admin = User::factory()->create(['role' => 'admin']);
        $this->ecfDrive();
        $this->empresa('Natture', '3561917388', ['fase' => 'M2']);
        $this->empresa('Moveis absoluto', '3561917388', ['fase' => 'Encaminhar Comercial']);
        $this->empresa('Sem Cust na Meta', '', ['fase' => 'M3']);
        $this->faturou('3561917388', 32576.98);

        $resposta = $this->sellers($admin);

        $this->assertEquals(32576.98, $this->seller($resposta, 'Natture')['faturamento']);
        $fora = $this->seller($resposta, 'Moveis absoluto');
        $this->assertNull($fora['faturamento'], 'fora da meta não herda o financeiro de outra empresa');
        $this->assertNull($fora['status_meta']);
        $semCust = $this->seller($resposta, 'Sem Cust na Meta');
        $this->assertSame('Não', $semCust['status_meta'], 'na meta sem cust: liga pelo id e sai como "Não", como no /polos/empresas');
        $this->assertEquals(4000, $semCust['meta']);
    }

    // ─── Acesso ──────────────────────────────────────────────────────────────

    public function test_sem_acesso_ao_faturamento_recebe_so_o_operacional_e_nao_toca_o_ecf_drive(): void
    {
        Http::fake();
        $operacao = $this->comPermissoes(['mlb.projetos']);
        $this->empresa('Loja Alfa', '2425054445');
        $this->faturou('2425054445', 1500);

        $resposta = $this->sellers($operacao);

        $this->assertFalse($resposta['financeiro']);
        $this->assertNull($resposta['mes']);
        $linha = $this->seller($resposta, 'Loja Alfa');
        $this->assertNull($linha['faturamento']);
        $this->assertNull($linha['meta']);
        $this->assertNull($linha['status_meta']);
        $this->assertNull($linha['ads']);
        $this->assertSame('Arapongas', $linha['regiao']);
        Http::assertNothingSent();
    }

    public function test_com_permissao_de_faturamento_recebe_o_financeiro(): void
    {
        $this->ecfDrive();
        $setor = $this->comPermissoes(['mlb.projetos', 'mlb.faturamento_polos']);
        $this->empresa('Loja Alfa', '2425054445');
        $this->faturou('2425054445', 1500);

        $linha = $this->seller($this->sellers($setor), 'Loja Alfa');

        $this->assertEquals(1500, $linha['faturamento']);
        $this->assertSame('Sim', $linha['status_meta']);
    }

    public function test_quem_nao_abre_o_painel_leva_403(): void
    {
        $consultor = User::factory()->create(['role' => 'consultor']);

        $this->actingAs($consultor)->getJson(route('mlb.polos-painel.sellers'))->assertForbidden();
    }

    // ─── Mês e região ────────────────────────────────────────────────────────

    public function test_filtro_de_mes_troca_o_financeiro_como_no_polos_empresas(): void
    {
        $admin    = User::factory()->create(['role' => 'admin']);
        $anterior = now()->subMonthNoOverflow()->format('Ym');
        $this->ecfDrive([[
            'TIM_MONTH_ID' => $anterior, 'COMPARATIVO' => 'FECHADO', 'CUS_CUST_ID_SEL' => '444,0',
            'MESES_NO_PROGRAMA' => '1', 'CUS_NICKNAME' => 'LOJA ANTERIOR', 'LOCALIDADE' => 'Arapongas',
        ]]);
        $this->empresa('Loja Anterior', '444', ['fase' => 'M3']);
        $this->faturou('444', 9000);
        $this->faturou('444', 700, $anterior);

        $hoje = $this->seller($this->sellers($admin), 'Loja Anterior');
        $this->assertEquals(9000, $hoje['faturamento']);
        $this->assertEquals(4000, $hoje['meta']);

        $resposta = $this->sellers($admin, ['mes' => $anterior]);
        $this->assertSame($anterior, $resposta['mes']);
        $this->assertFalse($resposta['parcial']);
        $antes = $this->seller($resposta, 'Loja Anterior');
        $this->assertEquals(700, $antes['faturamento'], 'faturamento do mês pedido');
        $this->assertEquals(1000, $antes['meta'], 'meta da fase daquele mês (M2), não a de hoje');
        $this->assertSame('Em progresso', $antes['status_meta']);
        $this->assertSame('M3', $antes['fase'], 'campos operacionais são o estado de hoje');
    }

    public function test_regiao_sai_sempre_como_um_dos_quatro_nomes_oficiais(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->ecfDrive([[
            'TIM_MONTH_ID' => now()->format('Ym'), 'COMPARATIVO' => 'PARCIAL', 'CUS_CUST_ID_SEL' => '555',
            'MESES_NO_PROGRAMA' => '1', 'LOCALIDADE' => 'Bento Goncalves',
        ]]);
        $this->empresa('Cadastro Rio Preto', '501', ['polo' => 'Rio Preto']);
        $this->empresa('Sem Polo no Cadastro', '555', ['polo' => null]);
        $this->empresa('Polo Desconhecido', '502', ['polo' => 'Curitiba']);

        $resposta = $this->sellers($admin);

        $this->assertSame('S. J. Rio Preto', $this->seller($resposta, 'Cadastro Rio Preto')['regiao']);
        $this->assertSame('Serra Gaúcha', $this->seller($resposta, 'Sem Polo no Cadastro')['regiao'], 'sem polo no cadastro, vale a LOCALIDADE do CSV');
        $this->assertNull($this->seller($resposta, 'Polo Desconhecido')['regiao']);
        $this->assertSame(['Curitiba'], $resposta['regioes_nao_reconhecidas']);
    }

    public function test_de_para_de_regiao(): void
    {
        foreach ([
            'Arapongas'             => 'Arapongas',
            'S. J. Rio Preto'       => 'S. J. Rio Preto',
            'Rio Preto'             => 'S. J. Rio Preto',
            'São José do Rio Preto' => 'S. J. Rio Preto',
            ' rio preto '           => 'S. J. Rio Preto',
            'Serra Gaúcha'          => 'Serra Gaúcha',
            'Bento Goncalves'       => 'Serra Gaúcha',
            'Bento Gonçalves'       => 'Serra Gaúcha',
            'São Bento do Sul'      => 'São Bento do Sul',
            'SAO BENTO DO SUL'      => 'São Bento do Sul',
        ] as $grafia => $oficial) {
            $this->assertSame($oficial, MlbImplementacao::poloOficial($grafia), "grafia \"{$grafia}\"");
        }

        $this->assertNull(MlbImplementacao::poloOficial(null));
        $this->assertNull(MlbImplementacao::poloOficial(''));
        $this->assertNull(MlbImplementacao::poloOficial('Sem polo'));
    }

    // ─── Pelo MCP ────────────────────────────────────────────────────────────

    public function test_ler_tela_do_mcp_abre_a_lista_e_pagina_os_sellers(): void
    {
        $this->ecfDrive();
        $admin = $this->admin();
        foreach (range(1, 3) as $i) {
            $this->empresa("Loja {$i}", (string) (9000 + $i));
        }

        $ler = function (array $args) use ($admin): array {
            $r = $this->rpc($admin, 'tools/call', ['name' => 'ler_tela', 'arguments' => (object) $args])->assertOk()->json('result');
            $this->assertFalse($r['isError'] ?? false, 'ler_tela deu erro: '.($r['content'][0]['text'] ?? ''));

            return json_decode($r['content'][0]['text'], true);
        };

        $resumo = $ler(['tela' => 'mlb.polos-painel.sellers']);
        $this->assertSame('/mlb/polos-painel/sellers', $resumo['endereco']);
        $this->assertSame(3, $resumo['dados']['total']);

        $pagina = $ler(['tela' => 'mlb.polos-painel.sellers', 'campo' => 'sellers', 'limite' => 2]);
        $this->assertSame(['Loja 1', 'Loja 2'], array_column($pagina['itens'], 'nome'));
        $this->assertNotNull($pagina['proximo_cursor']);
    }
}
