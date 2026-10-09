<?php

namespace Tests\Unit\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\MlbEmpresa;
use App\Models\PubProduto;
use App\Services\Publicador\SugestaoDeKitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase 175 Plano 175-03 (§6 da ETAPA-3): `SugestaoDeKitService`, nas duas camadas.
 *
 * O que está em jogo: o usuário confirma vínculos, nunca corrige palpites errados.
 * Por isso cada regra aqui é uma recusa — substring livre não casa, kit misto não
 * casa, ambiguidade não casa, base de outra conta nunca aparece.
 *
 * Os dois literais do critério de aceite da §9 (`CAD-CB2` sugere "Kit de CAD";
 * `Combit 4 Cadeira Escritório + 1 MESA REDONDA` não sugere nada) são provados
 * DUAS VEZES: pela camada de fato do Portal (composição da oferta) e pela camada
 * de heurística (produto sem oferta).
 */
class SugestaoDeKitServiceTest extends TestCase
{
    use RefreshDatabase;
    // ─── normalizar ───

    public function test_normalizar_tira_acento_caixa_e_espaco_sobrando(): void
    {
        $this->assertSame('cadeira escritorio', SugestaoDeKitService::normalizar('  Cadeira   Escritório  '));
        $this->assertSame('cadeira escritorio', SugestaoDeKitService::normalizar('CADEIRA ESCRITORIO'));
    }

    // ─── porSku ───

    public function test_por_sku_casa_prefixo_seguido_de_separador(): void
    {
        $this->assertTrue(SugestaoDeKitService::porSku('CAD-CB2', 'CAD'));
        $this->assertTrue(SugestaoDeKitService::porSku('CAD_KIT2', 'CAD'));
        $this->assertTrue(SugestaoDeKitService::porSku('CAD.2', 'CAD'));
        $this->assertTrue(SugestaoDeKitService::porSku('CAD 2', 'CAD'));
        $this->assertTrue(SugestaoDeKitService::porSku('CAD/2', 'CAD'));
    }

    public function test_por_sku_nao_casa_substring_livre_nem_o_proprio_sku(): void
    {
        $this->assertFalse(SugestaoDeKitService::porSku('CADEIRA', 'CAD'), 'prefixo sem separador nunca casa');
        $this->assertFalse(SugestaoDeKitService::porSku('CAD', 'CAD'), 'o proprio sku nao e kit de si mesmo');
        $this->assertFalse(SugestaoDeKitService::porSku('MESA-CB2', 'CAD'));
        $this->assertFalse(SugestaoDeKitService::porSku('XCAD-CB2', 'CAD'), 'prefixo tem de ser no comeco');
    }

    public function test_por_sku_ignora_caixa_e_sku_vazio(): void
    {
        $this->assertTrue(SugestaoDeKitService::porSku('cad-cb2', 'CAD'));
        $this->assertFalse(SugestaoDeKitService::porSku('CAD-CB2', ''), 'sku vazio nao casa com nada');
        $this->assertFalse(SugestaoDeKitService::porSku('', 'CAD'));
    }

    // ─── porNome ───

    public function test_por_nome_casa_os_tres_padroes_de_prefixo_e_devolve_o_n(): void
    {
        $this->assertSame(2, SugestaoDeKitService::porNome('Combo 2 Cadeira Escritório', 'Cadeira Escritório'));
        $this->assertSame(4, SugestaoDeKitService::porNome('Kit 4 Cadeira', 'Cadeira'));
        $this->assertSame(4, SugestaoDeKitService::porNome('4 unidades Cadeira', 'Cadeira'));
        $this->assertSame(6, SugestaoDeKitService::porNome('6 un Cadeira', 'Cadeira'));
    }

    public function test_por_nome_compara_sem_acento_e_sem_caixa(): void
    {
        $this->assertSame(2, SugestaoDeKitService::porNome('KIT 2 CADEIRA ESCRITORIO', 'Cadeira Escritório'));
        $this->assertSame(3, SugestaoDeKitService::porNome('kit 3 - Cadeira Escritório', 'cadeira escritorio'));
        $this->assertSame(3, SugestaoDeKitService::porNome('Kit 3: Cadeira', 'Cadeira'));
    }

    public function test_por_nome_nao_casa_sem_prefixo_nem_com_outro_produto(): void
    {
        $this->assertNull(SugestaoDeKitService::porNome('Cadeira Escritório', 'Cadeira Escritório'), 'sem prefixo nao e kit');
        $this->assertNull(SugestaoDeKitService::porNome('Kit 2 Mesa Redonda', 'Cadeira'), 'o resto tem de comecar pelo nome do base');
        $this->assertNull(SugestaoDeKitService::porNome('Cadeira Kit 2', 'Cadeira'), 'o prefixo e ancorado no comeco');
        $this->assertNull(SugestaoDeKitService::porNome('Kit Cadeira', 'Cadeira'), 'sem N nao ha quantidade para sugerir');
    }

    public function test_por_nome_recusa_kit_de_uma_unidade(): void
    {
        // Kit de 1 unidade não é kit, e `quantidade_kit = 1` é o valor das BASES
        // (unique `pubprod_base_qtd_uq` do 175-01). Sugerir isso seria palpite errado.
        $this->assertNull(SugestaoDeKitService::porNome('Kit 1 Cadeira', 'Cadeira'));
        $this->assertNull(SugestaoDeKitService::porNome('Kit 0 Cadeira', 'Cadeira'));
    }

    // ─── ehMisto ───

    public function test_eh_misto_pega_o_literal_da_spec_e_a_conjuncao(): void
    {
        $this->assertTrue(SugestaoDeKitService::ehMisto('Combit 4 Cadeira Escritório + 1 MESA REDONDA'));
        $this->assertTrue(SugestaoDeKitService::ehMisto('Kit 2 Cadeira e Mesa'));
        $this->assertTrue(SugestaoDeKitService::ehMisto('Pote e Tampa'));
    }

    public function test_eh_misto_nao_dispara_em_palavra_que_so_contem_e(): void
    {
        $this->assertFalse(SugestaoDeKitService::ehMisto('Kit 2 Mesa de Jantar'), '"de" nao e a conjuncao');
        $this->assertFalse(SugestaoDeKitService::ehMisto('Kit 2 Cadeira Escritório'));
        $this->assertFalse(SugestaoDeKitService::ehMisto('Combo 2 Estante'), '"e" no comeco de palavra nao conta');
    }

    // ─── escolher ───

    public function test_escolher_devolve_so_quando_ha_exatamente_um_candidato(): void
    {
        $a = ['base_id' => 1, 'base_sku' => 'CAD', 'base_nome' => 'Cadeira', 'quantidade' => 2, 'origem' => 'sku'];
        $b = ['base_id' => 2, 'base_sku' => 'MES', 'base_nome' => 'Mesa', 'quantidade' => 2, 'origem' => 'sku'];

        $this->assertNull(SugestaoDeKitService::escolher([]));
        $this->assertSame($a, SugestaoDeKitService::escolher([$a]));
        $this->assertNull(SugestaoDeKitService::escolher([$a, $b]), 'ambiguidade nunca vira palpite');
    }

    public function test_escolher_trata_o_mesmo_base_casado_duas_vezes_como_um(): void
    {
        // O mesmo base casado por SKU E por nome é UM candidato, não dois.
        $porNome = ['base_id' => 7, 'base_sku' => 'CAD', 'base_nome' => 'Cadeira', 'quantidade' => 2, 'origem' => 'nome'];
        $porSku = ['base_id' => 7, 'base_sku' => 'CAD', 'base_nome' => 'Cadeira', 'quantidade' => null, 'origem' => 'sku'];

        $escolhido = SugestaoDeKitService::escolher([$porNome, $porSku]);

        $this->assertNotNull($escolhido);
        $this->assertSame(7, $escolhido['base_id']);
    }

    // ─── Os dois literais da §9, na camada de heurística ───

    public function test_aceite_da_spec_cad_cb2_casa_com_cad_e_o_combit_misto_nao_casa_com_nada(): void
    {
        // 1) `CAD-CB2` é kit de `CAD` (quantidade vazia: o SKU não diz o N).
        $this->assertTrue(SugestaoDeKitService::porSku('CAD-CB2', 'CAD'));
        $this->assertNull(SugestaoDeKitService::porNome('Kit de CAD', 'CAD'), 'sem N a quantidade fica vazia');

        // 2) O combit misto é recusado antes de qualquer casamento.
        $misto = 'Combit 4 Cadeira Escritório + 1 MESA REDONDA';
        $this->assertTrue(SugestaoDeKitService::ehMisto($misto));
        $this->assertNull(SugestaoDeKitService::porNome($misto, 'Cadeira Escritório'), 'nem o prefixo "Combit" casa');
    }

    // ═══════════════════════════════════════════════════════════════
    // As duas camadas: fato do Portal antes da heurística
    // ═══════════════════════════════════════════════════════════════

    private function servico(): SugestaoDeKitService
    {
        return app(SugestaoDeKitService::class);
    }

    /** @return array{0: MlbEmpresa, 1: Company} uma conta do Publicador (as duas âncoras) */
    private function conta(string $nome = 'Polo dos Kits'): array
    {
        return [MlbEmpresa::create(['nome' => $nome, 'projeto' => 'POLOS']), Company::factory()->create()];
    }

    private function oferta(Company $company, string $sku, string $nome, string $fase = EstruturaOferta::FASE_SIMPLES): EstruturaOferta
    {
        return EstruturaOferta::create(['company_id' => $company->id, 'sku' => $sku, 'nome' => $nome, 'fase' => $fase]);
    }

    private function produto(MlbEmpresa $e, Company $c, string $sku, string $nome, array $extra = []): PubProduto
    {
        return PubProduto::create($extra + ['mlb_empresa_id' => $e->id, 'company_id' => $c->id,
            'sku' => $sku, 'nome' => $nome, 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
    }

    private function produtoDaOferta(MlbEmpresa $e, Company $c, EstruturaOferta $oferta): PubProduto
    {
        return PubProduto::create(['mlb_empresa_id' => $e->id, 'company_id' => $c->id, 'oferta_id' => $oferta->id,
            'sku' => $oferta->sku, 'nome' => $oferta->nome, 'origem' => PubProduto::ORIGEM_PORTAL]);
    }

    private function compor(EstruturaOferta $oferta, EstruturaOferta $componente, int $quantidade): void
    {
        $oferta->componentes()->create(['componente_id' => $componente->id, 'quantidade' => $quantidade]);
    }

    // ─── Camada de fato (Portal) ───

    public function test_oferta_combo_de_um_componente_x4_sugere_pelo_fato_do_portal(): void
    {
        [$e, $c] = $this->conta();
        $simples = $this->oferta($c, 'CAD', 'Cadeira Escritório');
        $base = $this->produtoDaOferta($e, $c, $simples);

        $combo = $this->oferta($c, 'CAD-CB4', 'CAD-CB4', EstruturaOferta::FASE_COMBO);
        $this->compor($combo, $simples, 4);
        $kit = $this->produtoDaOferta($e, $c, $combo);

        $sugestao = $this->servico()->sugerirPara($kit);

        $this->assertNotNull($sugestao);
        $this->assertSame($base->id, $sugestao['base_id']);
        $this->assertSame('CAD', $sugestao['base_sku']);
        $this->assertSame('Cadeira Escritório', $sugestao['base_nome']);
        $this->assertSame(4, $sugestao['quantidade'], 'o N vem da composicao, nao do nome');
        $this->assertSame('portal', $sugestao['origem']);
        $this->assertFalse($sugestao['conflito_heuristica']);
    }

    public function test_oferta_com_dois_componentes_e_kit_misto_por_construcao_e_nao_sugere(): void
    {
        [$e, $c] = $this->conta();
        $cadeira = $this->oferta($c, 'CAD', 'Cadeira');
        $this->produtoDaOferta($e, $c, $cadeira);
        $mesa = $this->oferta($c, 'MES', 'Mesa Redonda');
        $this->produtoDaOferta($e, $c, $mesa);

        // O nome CASARIA na heuristica ("Kit 4 Cadeira"), mas a composicao manda.
        $combit = $this->oferta($c, 'CBT-01', 'Kit 4 Cadeira', EstruturaOferta::FASE_COMBIT);
        $this->compor($combit, $cadeira, 4);
        $this->compor($combit, $mesa, 1);

        $this->assertNull($this->servico()->sugerirPara($this->produtoDaOferta($e, $c, $combit)));
    }

    public function test_oferta_simples_nao_e_kit(): void
    {
        [$e, $c] = $this->conta();
        $this->produtoDaOferta($e, $c, $this->oferta($c, 'CAD', 'Cadeira'));
        $outra = $this->oferta($c, 'CAD-02', 'Cadeira Gamer');

        $this->assertNull($this->servico()->sugerirPara($this->produtoDaOferta($e, $c, $outra)));
    }

    public function test_combo_cujo_componente_nao_tem_produto_nesta_conta_nao_sugere(): void
    {
        [$e, $c] = $this->conta();
        $simples = $this->oferta($c, 'CAD', 'Cadeira'); // oferta existe, produto NAO
        $combo = $this->oferta($c, 'CAD-CB2', 'CAD-CB2', EstruturaOferta::FASE_COMBO);
        $this->compor($combo, $simples, 2);

        $this->assertNull($this->servico()->sugerirPara($this->produtoDaOferta($e, $c, $combo)),
            'sem base para vincular nao ha sugestao — e nao cai na heuristica');
    }

    public function test_conflito_heuristica_e_marcado_quando_o_nome_aponta_outro_base(): void
    {
        [$e, $c] = $this->conta();
        $cadeira = $this->oferta($c, 'CAD', 'Cadeira');
        $base = $this->produtoDaOferta($e, $c, $cadeira);
        $mesa = $this->oferta($c, 'MES', 'Mesa');
        $this->produtoDaOferta($e, $c, $mesa);

        // A composicao diz Cadeira ×2; o nome diz "Kit 2 Mesa". Vale o Portal.
        $combo = $this->oferta($c, 'CB-X', 'Kit 2 Mesa', EstruturaOferta::FASE_COMBO);
        $this->compor($combo, $cadeira, 2);

        $sugestao = $this->servico()->sugerirPara($this->produtoDaOferta($e, $c, $combo));

        $this->assertNotNull($sugestao);
        $this->assertSame($base->id, $sugestao['base_id'], 'o fato do Portal tem precedencia');
        $this->assertSame('portal', $sugestao['origem']);
        $this->assertTrue($sugestao['conflito_heuristica']);
    }

    // ─── Camada de heurística ───

    public function test_produto_sem_oferta_sugere_pelo_nome_com_a_quantidade(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD', 'Cadeira Escritório');
        $kit = $this->produto($e, $c, 'K2', 'Combo 2 Cadeira Escritório');

        $sugestao = $this->servico()->sugerirPara($kit);

        $this->assertNotNull($sugestao);
        $this->assertSame($base->id, $sugestao['base_id']);
        $this->assertSame(2, $sugestao['quantidade']);
        $this->assertSame('nome', $sugestao['origem']);
        $this->assertFalse($sugestao['conflito_heuristica']);
    }

    public function test_produto_sem_oferta_sugere_pelo_sku_com_quantidade_vazia(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD', 'Cadeira Escritório');
        $kit = $this->produto($e, $c, 'CAD-CB2', 'Cadeira Escritório CB2');

        $sugestao = $this->servico()->sugerirPara($kit);

        $this->assertNotNull($sugestao);
        $this->assertSame($base->id, $sugestao['base_id']);
        $this->assertNull($sugestao['quantidade'], 'o SKU nao diz o N — o campo fica vazio para a pessoa preencher');
        $this->assertSame('sku', $sugestao['origem']);
    }

    public function test_dois_candidatos_possiveis_nunca_viram_palpite(): void
    {
        [$e, $c] = $this->conta();
        $this->produto($e, $c, 'CAD', 'Cadeira');
        $this->produto($e, $c, 'CADX', 'Cadeira Escritório');
        $kit = $this->produto($e, $c, 'K2', 'Kit 2 Cadeira Escritório');

        $this->assertNull($this->servico()->sugerirPara($kit));
    }

    public function test_kit_misto_sem_oferta_nao_recebe_sugestao(): void
    {
        [$e, $c] = $this->conta();
        $this->produto($e, $c, 'CAD', 'Cadeira Escritório');
        $misto = $this->produto($e, $c, 'CBT-01', 'Combit 4 Cadeira Escritório + 1 MESA REDONDA');

        $this->assertNull($this->servico()->sugerirPara($misto));
    }

    public function test_base_de_outra_conta_nunca_e_sugerida(): void
    {
        [$e1, $c1] = $this->conta('Polo Um');
        [$e2, $c2] = $this->conta('Polo Dois');
        $this->produto($e2, $c2, 'CAD', 'Cadeira Escritório'); // base da OUTRA conta
        $kit = $this->produto($e1, $c1, 'CAD-CB2', 'Combo 2 Cadeira Escritório');

        $this->assertNull($this->servico()->sugerirPara($kit), 'T-175-09: sugerir base de outra conta vazaria SKU/nome');
    }

    public function test_candidato_a_base_nunca_e_ele_mesmo_um_kit(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD', 'Cadeira');
        // "Kit 2 Cadeira" ja vinculado: é kit, logo nao pode ser base de ninguem (sem cadeia).
        $this->produto($e, $c, 'CAD-CB2', 'Kit 2 Cadeira',
            ['produto_base_id' => $base->id, 'quantidade_kit' => 2, 'fase' => 2]);
        // Este casaria por SKU com o kit acima (CAD-CB2-X) e por nome com nenhum.
        $novo = $this->produto($e, $c, 'CAD-CB2-X', 'Kit 2 Cadeira X');

        $sugestao = $this->servico()->sugerirPara($novo);

        $this->assertNotNull($sugestao);
        $this->assertSame($base->id, $sugestao['base_id'], 'o base é o produto base, nunca o kit intermediario');
    }

    // ─── Guardas ───

    public function test_produto_que_ja_tem_base_nao_recebe_sugestao(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD', 'Cadeira');
        $kit = $this->produto($e, $c, 'CAD-CB2', 'Kit 2 Cadeira',
            ['produto_base_id' => $base->id, 'quantidade_kit' => 2, 'fase' => 2]);

        $this->assertNull($this->servico()->sugerirPara($kit));
    }

    public function test_produto_que_ja_e_base_de_alguem_nao_recebe_sugestao(): void
    {
        [$e, $c] = $this->conta();
        $cadeira = $this->produto($e, $c, 'CAD', 'Cadeira');
        $base = $this->produto($e, $c, 'CAD-CB2', 'Combo 2 Cadeira');
        $this->produto($e, $c, 'CAD-CB2-K', 'Kit 2 Combo',
            ['produto_base_id' => $base->id, 'quantidade_kit' => 2, 'fase' => 2]);

        $this->assertNotNull($cadeira);
        $this->assertNull($this->servico()->sugerirPara($base), 'quem ja é base de um kit nao entra na lista');
    }

    public function test_recusa_registrada_devolve_null_sem_consultar_o_banco(): void
    {
        [$e, $c] = $this->conta();
        $this->produto($e, $c, 'CAD', 'Cadeira Escritório');
        $kit = $this->produto($e, $c, 'K2', 'Combo 2 Cadeira Escritório',
            ['kit_sugestao_recusada_em' => now()]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $sugestao = $this->servico()->sugerirPara($kit);
        $consultas = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertNull($sugestao);
        $this->assertSame([], $consultas, '"Nao e kit" encerra o assunto — nem consulta candidatos');
    }

    // ─── Lote e ausência de escrita ───

    public function test_candidatos_da_conta_devolve_agrupado_por_id_do_produto(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD', 'Cadeira Escritório');
        $porNome = $this->produto($e, $c, 'K2', 'Combo 2 Cadeira Escritório');
        $semCasamento = $this->produto($e, $c, 'MES', 'Mesa Redonda');

        DB::enableQueryLog();
        DB::flushQueryLog();
        $saida = $this->servico()->candidatosDaConta($e, $c);
        $consultas = DB::getQueryLog();
        DB::disableQueryLog();

        // T-175-11: uma leitura por assunto, nunca uma consulta por produto.
        $this->assertLessThanOrEqual(2, count($consultas), 'N+1 na lista de produtos da conta');
        $this->assertSame([$porNome->id], array_keys($saida));
        $this->assertSame($base->id, $saida[$porNome->id]['base_id']);
        $this->assertArrayNotHasKey($base->id, $saida, 'base nao recebe sugestao de si');
        $this->assertArrayNotHasKey($semCasamento->id, $saida);
    }

    public function test_o_servico_nao_grava_nada(): void
    {
        [$e, $c] = $this->conta();
        $simples = $this->oferta($c, 'CAD', 'Cadeira');
        $this->produtoDaOferta($e, $c, $simples);
        $combo = $this->oferta($c, 'CAD-CB2', 'CAD-CB2', EstruturaOferta::FASE_COMBO);
        $this->compor($combo, $simples, 2);
        $this->produtoDaOferta($e, $c, $combo);
        $this->produto($e, $c, 'K3', 'Kit 3 Cadeira');

        $antes = DB::table('pub_produtos')->orderBy('id')->get()->toArray();

        $this->servico()->candidatosDaConta($e, $c);

        $this->assertEquals($antes, DB::table('pub_produtos')->orderBy('id')->get()->toArray(),
            'a sugestao é só leitura — quem grava o vinculo é o 175-08/175-09');
    }

    // ─── Os dois literais da §9, agora nos DOIS caminhos ───

    public function test_aceite_da_spec_pelo_fato_do_portal(): void
    {
        [$e, $c] = $this->conta();
        $cad = $this->oferta($c, 'CAD', 'Cadeira Escritório');
        $base = $this->produtoDaOferta($e, $c, $cad);
        $mesa = $this->oferta($c, 'MES', 'MESA REDONDA');
        $this->produtoDaOferta($e, $c, $mesa);

        // 1) `CAD-CB2` é combo de 1 componente ×2 → "Kit de CAD".
        $comboCb2 = $this->oferta($c, 'CAD-CB2', 'CAD-CB2', EstruturaOferta::FASE_COMBO);
        $this->compor($comboCb2, $cad, 2);
        $sugestao = $this->servico()->sugerirPara($this->produtoDaOferta($e, $c, $comboCb2));

        $this->assertNotNull($sugestao);
        $this->assertSame($base->id, $sugestao['base_id']);
        $this->assertSame('CAD', $sugestao['base_sku'], 'a tela dira "Kit de CAD? Vincular"');
        $this->assertSame(2, $sugestao['quantidade']);

        // 2) O combit misto: 2 componentes → nenhuma sugestao.
        $combit = $this->oferta($c, 'CBT-01', 'Combit 4 Cadeira Escritório + 1 MESA REDONDA', EstruturaOferta::FASE_COMBIT);
        $this->compor($combit, $cad, 4);
        $this->compor($combit, $mesa, 1);

        $this->assertNull($this->servico()->sugerirPara($this->produtoDaOferta($e, $c, $combit)));
    }

    public function test_aceite_da_spec_pela_heuristica(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD', 'Cadeira Escritório');
        $this->produto($e, $c, 'MES', 'MESA REDONDA');

        // 1) `CAD-CB2` sem oferta: casa por SKU com `CAD`.
        $sugestao = $this->servico()->sugerirPara($this->produto($e, $c, 'CAD-CB2', 'Cadeira Escritório CB2'));

        $this->assertNotNull($sugestao);
        $this->assertSame($base->id, $sugestao['base_id']);
        $this->assertSame('CAD', $sugestao['base_sku']);
        $this->assertNull($sugestao['quantidade'], 'quantidade vazia: o SKU nao diz o N');

        // 2) O combit misto sem oferta: recusado pelo nome.
        $misto = $this->produto($e, $c, 'CBT-01', 'Combit 4 Cadeira Escritório + 1 MESA REDONDA');

        $this->assertNull($this->servico()->sugerirPara($misto));
    }
}
