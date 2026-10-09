<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoAtributo;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVolume;
use App\Models\MlAcervoItem;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Publicador\FamiliaDeFasesService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem as R;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 175, Plano 175-04 (§3 da ETAPA-3) — a tela do Produto: o payload dos 6
 * blocos (`FamiliaDeFasesService`) e o escopo da rota nova
 * (`MlbPublicadorFaseController::mostrar`).
 *
 * Duas disciplinas que este arquivo guarda:
 *
 * 1. **Zero chamada ao Mercado Livre nesta tela** (T-175-16). `Http::fake()` sem
 *    handler nenhum intercepta tudo e `Http::assertNothingSent()` cobra o
 *    contrário — a tela só lê o que já está gravado.
 * 2. **Produto de outra conta é 404, nunca 403** (D-13). O produto é buscado
 *    DENTRO de `produtosQuery()`; fora do escopo ele simplesmente não existe.
 *
 * @group phase175
 */
class TelaDoProdutoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // T-175-16: nada desta tela fala com o ML.
        Http::fake();
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    private function servico(): FamiliaDeFasesService
    {
        return app(FamiliaDeFasesService::class);
    }

    private function programas(): ProgramasPublicadorService
    {
        return app(ProgramasPublicadorService::class);
    }

    /**
     * Uma conta de Polos com token ativo. `$comCompany` false = loja da
     * Incubadora sem Company (D23): sem acervo e sem o modal de 90 dias.
     *
     * @return array{0: MlbEmpresa, 1: ?Company}
     */
    private function conta(bool $comCompany = true): array
    {
        $company = $comCompany ? Company::factory()->create() : null;
        $empresa = MlbEmpresa::create([
            'nome' => 'Polo das Fases',
            'projeto' => 'POLOS',
            'company_id' => $company?->id,
        ]);
        MlToken::create([
            'company_id' => $company?->id,
            'mlb_empresa_id' => $company === null ? $empresa->id : null,
            'ml_user_id' => '1555596317',
            'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'token_type' => 'bearer',
            'expires_at' => now()->addHours(5),
            'status' => 'active',
            'connected_at' => now(),
        ]);
        config(['publicador.contas_liberadas' => [
            'companies' => $company ? [$company->id] : [],
            'mlb_empresas' => $company ? [] : [$empresa->id],
        ]]);

        return [$empresa->fresh(), $company];
    }

    /** O alvo do resolver para esta conta (o mesmo que o controller usa). */
    private function alvo(MlbEmpresa $empresa): array
    {
        return $this->programas()->resolver('empresa-'.$empresa->id);
    }

    /** O payload da tela para este produto. */
    private function tela(PubProduto $p, MlbEmpresa $empresa): array
    {
        $alvo = $this->alvo($empresa);

        return $this->servico()->paraTela($p, $alvo, $this->programas()->empresaParaTela($alvo, $p));
    }

    private function base(MlbEmpresa $empresa, ?Company $company = null, string $sku = 'CAD-01'): PubProduto
    {
        return PubProduto::create([
            'mlb_empresa_id' => $empresa->id,
            'company_id' => $company?->id,
            'sku' => $sku,
            'nome' => 'Cadeira',
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);
    }

    private function kit(PubProduto $base, int $quantidade, int $fase, bool $estoqueCalculado = true): PubProduto
    {
        return PubProduto::create([
            'mlb_empresa_id' => $base->mlb_empresa_id,
            'company_id' => $base->company_id,
            'sku' => $base->sku.'-KIT'.$quantidade,
            'nome' => $base->nome,
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'produto_base_id' => $base->id,
            'quantidade_kit' => $quantidade,
            'fase' => $fase,
            'estoque_calculado' => $estoqueCalculado,
        ]);
    }

    private function rascunho(PubProduto $p, string $status = PubRascunho::DRAFT): PubRascunho
    {
        return PubRascunho::create([
            'produto_id' => $p->id,
            'status' => $status,
            'revisao' => 1,
            'descricao' => 'Cadeira executiva.',
        ]);
    }

    private function variante(PubRascunho $r, int $estoque, string $chave = '__single__'): void
    {
        $r->variantes()->create([
            'combinacao_chave' => $chave,
            'combinacao_hash' => hash('sha256', $chave),
            'estoque' => $estoque,
        ]);
    }

    /**
     * Uma publicação concluída com os itens pedidos.
     *
     * @param  list<array{0: string, 1: ?string, 2?: string}>  $itens  [listing_type_id, ml_item_id, status?]
     */
    private function publicacao(PubRascunho $r, array $itens, ?array $ator = null, ?string $titulo = 'Cadeira Executiva ECF'): void
    {
        $pub = $r->publicacoes()->create([
            'revisao' => $r->revisao,
            'modelo_publicacao' => 'USER_PRODUCTS',
            'status' => 'PUBLISHED',
            'chave_idempotencia' => (string) Str::uuid(),
            'iniciada_em' => now()->subMinutes(5),
            'concluida_em' => now()->subMinutes(4),
            'ator' => $ator,
        ]);

        foreach ($itens as $i => $item) {
            $pub->itens()->create([
                'indice' => $i,
                'listing_type_id' => $item[0],
                'variante_chave' => '__single__',
                'status' => $item[2] ?? 'CREATED',
                'ml_item_id' => $item[1],
                'payload' => ['family_name' => $titulo],
            ]);
        }
    }

    // ═══ Task 1 — fases e o botão "Criar Fase N" ════════════════════════════

    public function test_base_sem_kit_e_sem_rascunho_tem_um_cartao_nao_iniciada_e_criar_fase_2_travado(): void
    {
        [$empresa] = $this->conta();
        $base = $this->base($empresa);

        $tela = $this->tela($base, $empresa);

        $this->assertCount(1, $tela['fases']);
        $this->assertSame(1, $tela['fases'][0]['fase']);
        $this->assertSame('1 unidade', $tela['fases'][0]['rotulo']);
        $this->assertSame('nao_iniciada', $tela['fases'][0]['estado_fase']);
        $this->assertSame(0, $tela['fases'][0]['ofertas_no_ar']);
        $this->assertSame(2, $tela['proxima_fase']['numero']);
        $this->assertSame(2, $tela['proxima_fase']['quantidade_sugerida']);
        $this->assertFalse($tela['proxima_fase']['habilitado']);
        $this->assertSame('Publique a Fase 1 primeiro', $tela['proxima_fase']['motivo']);
        $this->assertNull($tela['fase_destacada']);
        Http::assertNothingSent();
    }

    public function test_base_publicado_ou_parcial_habilita_criar_fase_2(): void
    {
        [$empresa] = $this->conta();
        $base = $this->base($empresa);
        $r = $this->rascunho($base, PubRascunho::PUBLISHED);

        $tela = $this->tela($base->fresh(), $empresa);
        $this->assertTrue($tela['proxima_fase']['habilitado']);
        $this->assertNull($tela['proxima_fase']['motivo']);
        $this->assertSame('publicada', $tela['fases'][0]['estado_fase']);

        $r->update(['status' => PubRascunho::PARTIALLY_PUBLISHED]);
        $tela = $this->tela($base->fresh(), $empresa);
        $this->assertTrue($tela['proxima_fase']['habilitado'], 'a §9 diz que parcial também libera');
        $this->assertSame('publicada', $tela['fases'][0]['estado_fase']);
    }

    public function test_base_em_rascunho_conferido_nao_habilita_criar_fase_2(): void
    {
        [$empresa] = $this->conta();
        $base = $this->base($empresa);
        $r = $this->rascunho($base);
        $r->validacoes()->create([
            'revisao' => $r->revisao,
            'camada' => 'L3',
            'resultado' => 'OK',
            'issues' => [],
        ]);

        $tela = $this->tela($base->fresh(), $empresa);

        $this->assertSame('pronto', $tela['fases'][0]['estado']['chave']);
        $this->assertSame('em_preparacao', $tela['fases'][0]['estado_fase']);
        $this->assertFalse($tela['proxima_fase']['habilitado']);
        $this->assertSame('Publique a Fase 1 primeiro', $tela['proxima_fase']['motivo']);
    }

    public function test_base_com_kit_2_publicado_tem_dois_cartoes_e_sugere_fase_3_quantidade_3(): void
    {
        [$empresa] = $this->conta();
        $base = $this->base($empresa);
        $this->rascunho($base, PubRascunho::PUBLISHED);
        $kit = $this->kit($base, 2, 2);
        $this->rascunho($kit, PubRascunho::PUBLISHED);

        $tela = $this->tela($base->fresh(), $empresa);

        $this->assertCount(2, $tela['fases']);
        $this->assertSame([1, 2], array_column($tela['fases'], 'fase'));
        $this->assertSame(['1 unidade', 'Kit 2'], array_column($tela['fases'], 'rotulo'));
        $this->assertSame('CAD-01-KIT2', $tela['fases'][1]['sku']);
        $this->assertSame(3, $tela['proxima_fase']['numero']);
        $this->assertSame(3, $tela['proxima_fase']['quantidade_sugerida']);
        $this->assertTrue($tela['proxima_fase']['habilitado']);
    }

    public function test_chamado_com_o_kit_devolve_a_familia_do_base_com_a_fase_destacada(): void
    {
        [$empresa] = $this->conta();
        $base = $this->base($empresa);
        $this->rascunho($base, PubRascunho::PUBLISHED);
        $kit = $this->kit($base, 2, 2);

        $tela = $this->tela($kit->fresh(), $empresa);

        $this->assertSame($base->id, $tela['base']['id'], 'abrir um kit leva à tela do BASE (§3)');
        $this->assertSame('CAD-01', $tela['base']['sku']);
        $this->assertSame(2, $tela['fase_destacada']);
        $this->assertCount(2, $tela['fases']);
        $this->assertFalse($tela['base']['base_excluido']);
    }

    public function test_kit_cujo_base_foi_apagado_vira_base_solto_com_aviso(): void
    {
        [$empresa] = $this->conta();
        $base = $this->base($empresa);
        $kit = $this->kit($base, 2, 2);
        $base->delete();   // pubprod_base_fk é SET NULL: o kit fica solto COM o histórico

        $solto = PubProduto::findOrFail($kit->id);
        $this->assertNull($solto->produto_base_id);
        $tela = $this->tela($solto, $empresa);

        $this->assertSame($kit->id, $tela['base']['id']);
        $this->assertTrue($tela['base']['base_excluido']);
        $this->assertCount(1, $tela['fases']);
    }

    public function test_estoque_do_combo_vinculado_fica_proprio_com_o_calculado_ao_lado(): void
    {
        [$empresa] = $this->conta();
        $base = $this->base($empresa);
        $rb = $this->rascunho($base, PubRascunho::PUBLISHED);
        $this->variante($rb, 7);

        $calculado = $this->kit($base, 2, 2, estoqueCalculado: true);
        $this->rascunho($calculado);
        $vinculado = $this->kit($base, 3, 3, estoqueCalculado: false);
        $this->rascunho($vinculado);

        $tela = $this->tela($base->fresh(), $empresa);
        $porFase = array_column($tela['fases'], null, 'fase');

        $this->assertSame(7, $tela['base']['estoque_total']);
        $this->assertFalse($porFase[2]['estoque_proprio']);
        $this->assertNull($porFase[2]['estoque_calculado_valor']);
        $this->assertTrue($porFase[3]['estoque_proprio'], 'combo vinculado mantém o próprio (§6)');
        $this->assertSame(2, $porFase[3]['estoque_calculado_valor'], 'floor(7 ÷ 3) só para exibição');
    }

    // ═══ Task 1 — ofertas no ar ═════════════════════════════════════════════

    public function test_duas_ofertas_created_viram_duas_linhas_com_mlb_tipo_preco_e_fase(): void
    {
        [$empresa, $company] = $this->conta();
        $base = $this->base($empresa, $company);
        $r = $this->rascunho($base, PubRascunho::PUBLISHED);
        $this->publicacao($r, [['gold_special', 'MLB1111'], ['gold_pro', 'MLB2222']]);

        MlAcervoItem::create([
            'company_id' => $company->id, 'ml_item_id' => 'MLB1111', 'title' => 'Cadeira no ML',
            'status' => 'active', 'price' => 199.90, 'sold_quantity' => 4, 'visitas_30d' => 120,
            'detalhe_coletado_em' => now(),
        ]);

        $tela = $this->tela($base->fresh(), $empresa);

        $this->assertCount(2, $tela['ofertas']);
        $this->assertSame(['MLB1111', 'MLB2222'], array_column($tela['ofertas'], 'ml_item_id'));
        $this->assertSame(['Clássico', 'Premium'], array_column($tela['ofertas'], 'tipo_rotulo'));
        $this->assertSame([1, 1], array_column($tela['ofertas'], 'fase'));
        $this->assertSame($base->id, $tela['ofertas'][0]['produto_id']);
        $this->assertSame('Cadeira Executiva ECF', $tela['ofertas'][0]['titulo']);
        $this->assertSame(199.90, (float) $tela['ofertas'][0]['preco']);
        $this->assertSame(4, $tela['ofertas'][0]['vendas']);
        $this->assertSame(120, $tela['ofertas'][0]['visitas']);
        $this->assertSame('active', $tela['ofertas'][0]['situacao']);
        $this->assertFalse($tela['ofertas'][0]['visitas_nao_avaliadas']);
        $this->assertTrue($tela['ofertas'][0]['detalhe_disponivel']);
        $this->assertNull($tela['ofertas'][0]['detalhe_motivo']);
        $this->assertSame(2, $tela['fases'][0]['ofertas_no_ar']);
        Http::assertNothingSent();
    }

    public function test_oferta_sem_acervo_tem_preco_vendas_e_visitas_nulos_nunca_zero(): void
    {
        [$empresa, $company] = $this->conta();
        $base = $this->base($empresa, $company);
        $r = $this->rascunho($base, PubRascunho::PUBLISHED);
        $this->publicacao($r, [['gold_special', 'MLB9999']]);

        $oferta = $this->tela($base->fresh(), $empresa)['ofertas'][0];

        $this->assertNull($oferta['preco'], 'zero mente: o acervo ainda não coletou');
        $this->assertNull($oferta['vendas']);
        $this->assertNull($oferta['visitas']);
        $this->assertNull($oferta['situacao']);
        $this->assertNull($oferta['visitas_nao_avaliadas']);
    }

    public function test_item_nao_created_ou_sem_mlb_nao_entra_nas_ofertas(): void
    {
        [$empresa, $company] = $this->conta();
        $base = $this->base($empresa, $company);
        $r = $this->rascunho($base, PubRascunho::PARTIALLY_PUBLISHED);
        $this->publicacao($r, [
            ['gold_special', 'MLB1111'],
            ['gold_pro', null, 'FAILED'],
            ['gold_pro', 'MLB3333', 'SENT'],
        ]);

        $tela = $this->tela($base->fresh(), $empresa);

        $this->assertSame(['MLB1111'], array_column($tela['ofertas'], 'ml_item_id'));
        $this->assertSame(1, $tela['fases'][0]['ofertas_no_ar']);
    }

    public function test_conta_sem_company_tem_detalhe_indisponivel_com_motivo(): void
    {
        [$empresa] = $this->conta(comCompany: false);
        $base = $this->base($empresa);
        $r = $this->rascunho($base, PubRascunho::PUBLISHED);
        $this->publicacao($r, [['gold_special', 'MLB1111']]);

        $oferta = $this->tela($base->fresh(), $empresa)['ofertas'][0];

        $this->assertFalse($oferta['detalhe_disponivel']);
        $this->assertNotNull($oferta['detalhe_motivo']);
        $this->assertSame('Disponível só para empresas cadastradas no sistema', $oferta['detalhe_motivo']);
    }

    // ═══ Task 1 — lateral Mapeamento ════════════════════════════════════════

    public function test_oferta_do_portal_sem_variacao_da_mapeamento_vazio(): void
    {
        [$empresa, $company] = $this->conta();
        $oferta = EstruturaOferta::create([
            'company_id' => $company->id, 'sku' => 'CAD-01', 'fase' => 'simples', 'nome' => 'Cadeira',
        ]);
        $base = PubProduto::create([
            'mlb_empresa_id' => $empresa->id, 'company_id' => $company->id, 'oferta_id' => $oferta->id,
            'sku' => 'CAD-01', 'nome' => 'Cadeira', 'origem' => PubProduto::ORIGEM_PORTAL,
        ]);

        $m = $this->tela($base, $empresa)['mapeamento'];

        $this->assertTrue($m['vazio']);
        $this->assertNull($m['peso']);
        $this->assertNull($m['material']);
        $this->assertNull($m['ean']);
        $this->assertNull($m['medidas']['comprimento']);
        $this->assertNull($m['medidas']['largura']);
        $this->assertNull($m['medidas']['altura']);
    }

    public function test_produto_sem_portal_nenhum_da_mapeamento_vazio(): void
    {
        [$empresa] = $this->conta();

        $this->assertTrue($this->tela($this->base($empresa), $empresa)['mapeamento']['vazio']);
    }

    public function test_mapeamento_vem_dos_volumes_e_atributos_do_portal(): void
    {
        [$empresa, $company] = $this->conta();
        $produtoPortal = EstruturaProduto::create(['company_id' => $company->id, 'codigo' => 'CAD', 'nome' => 'Cadeira']);
        $variacao = EstruturaProdutoVariacao::create([
            'produto_id' => $produtoPortal->id, 'company_id' => $company->id, 'ordem' => 1, 'codigo' => 'CAD-01',
        ]);
        EstruturaProdutoVolume::create([
            'variacao_id' => $variacao->id, 'ordem' => 1,
            'comprimento' => 60.0, 'largura' => 50.0, 'altura' => 110.0, 'peso' => 12.5,
        ]);
        EstruturaProdutoAtributo::create([
            'company_id' => $company->id, 'produto_id' => $produtoPortal->id,
            'atributo_id' => 'MATERIAL', 'atributo_nome' => 'Material', 'valor' => 'Couro sintético',
        ]);
        EstruturaProdutoAtributo::create([
            'company_id' => $company->id, 'produto_id' => $produtoPortal->id,
            'atributo_id' => 'GTIN', 'atributo_nome' => 'Código universal', 'valor' => '7896553367645',
        ]);
        $oferta = EstruturaOferta::create([
            'company_id' => $company->id, 'variacao_id' => $variacao->id,
            'sku' => 'CAD-01', 'fase' => 'simples', 'nome' => 'Cadeira',
        ]);
        $base = PubProduto::create([
            'mlb_empresa_id' => $empresa->id, 'company_id' => $company->id, 'oferta_id' => $oferta->id,
            'sku' => 'CAD-01', 'nome' => 'Cadeira', 'origem' => PubProduto::ORIGEM_PORTAL,
        ]);

        $m = $this->tela($base, $empresa)['mapeamento'];

        $this->assertFalse($m['vazio']);
        $this->assertSame(60.0, $m['medidas']['comprimento']);
        $this->assertSame(50.0, $m['medidas']['largura']);
        $this->assertSame(110.0, $m['medidas']['altura']);
        $this->assertSame('cm', $m['medidas']['unidade']);
        $this->assertSame(12.5, $m['peso']);
        $this->assertSame('Couro sintético', $m['material']);
        $this->assertSame('7896553367645', $m['ean']);
    }

    /**
     * ⚠️ A colisão de nome do docblock de `PubProduto`: `pub_produtos.fase` é o
     * NÚMERO da fase, `estrutura_ofertas.fase` é o TIPO da oferta no Portal
     * (`simples|combo|kit|combit`). As duas tabelas entram no mesmo SELECT por
     * `pub_produtos.oferta_id`; sem qualificar, o valor vem calado e errado.
     */
    public function test_fase_do_publicador_nao_se_confunde_com_a_fase_da_oferta_do_portal(): void
    {
        [$empresa, $company] = $this->conta();
        $oferta = EstruturaOferta::create([
            'company_id' => $company->id, 'sku' => 'CAD-01', 'fase' => 'combit', 'nome' => 'Cadeira',
        ]);
        $base = PubProduto::create([
            'mlb_empresa_id' => $empresa->id, 'company_id' => $company->id, 'oferta_id' => $oferta->id,
            'sku' => 'CAD-01', 'nome' => 'Cadeira', 'origem' => PubProduto::ORIGEM_PORTAL,
        ]);

        $tela = $this->tela($base, $empresa);

        $this->assertSame(1, $tela['fases'][0]['fase']);
        $this->assertSame('1 unidade', $tela['fases'][0]['rotulo']);
    }

    // ═══ Task 1 — histórico e criativos ═════════════════════════════════════

    public function test_historico_traz_publicacao_fase_criada_e_combo_vinculado_em_ordem_desc(): void
    {
        [$empresa, $company] = $this->conta();
        $user = User::factory()->create(['name' => 'Fulano da Silva', 'role' => 'admin']);
        $base = $this->base($empresa, $company);
        $r = $this->rascunho($base, PubRascunho::PUBLISHED);
        $this->publicacao($r, [['gold_special', 'MLB1111']], ['equipe' => true, 'id' => $user->id]);
        $kit = $this->kit($base, 2, 2, estoqueCalculado: false);

        $tela = $this->tela($base->fresh(), $empresa);
        $tipos = array_column($tela['historico'], 'tipo');

        $this->assertContains('publicacao', $tipos);
        $this->assertContains('fase_criada', $tipos);
        $this->assertContains('combo_vinculado', $tipos);
        $publicacao = collect($tela['historico'])->firstWhere('tipo', 'publicacao');
        $this->assertSame('Fulano da Silva', $publicacao['quem']);
        $this->assertSame(1, $publicacao['fase']);
        $faseCriada = collect($tela['historico'])->firstWhere('tipo', 'fase_criada');
        $this->assertSame(2, $faseCriada['fase']);
        // Ordem decrescente por data.
        $datas = array_column($tela['historico'], 'quando');
        $ordenadas = $datas;
        rsort($ordenadas);
        $this->assertSame($ordenadas, $datas);
        unset($kit);
    }

    public function test_publicacao_de_linha_migrada_sem_id_no_ator_tem_quem_nulo(): void
    {
        [$empresa, $company] = $this->conta();
        $base = $this->base($empresa, $company);
        $r = $this->rascunho($base, PubRascunho::PUBLISHED);
        $this->publicacao($r, [['gold_special', 'MLB1111']], ['equipe' => true]);

        $publicacao = collect($this->tela($base->fresh(), $empresa)['historico'])->firstWhere('tipo', 'publicacao');

        $this->assertNull($publicacao['quem'], 'achado da Fase 173: linha migrada não tem ator.id');
    }

    public function test_criativos_trazem_as_miniaturas_do_kit_aprovado_por_fase_sem_token_no_navegador(): void
    {
        [$empresa, $company] = $this->conta();
        $base = $this->base($empresa, $company);
        $r = $this->rascunho($base, PubRascunho::PUBLISHED);

        $kitCriativo = MlAnuncioCriativoKit::create([
            'token' => Str::random(32),
            'company_id' => $company->id,
            'mlb_empresa_id' => $empresa->id,
            'rascunho_id' => null,
            'pub_rascunho_id' => $r->id,
            'pub_grupo' => R::GERAL,
            'status' => MlAnuncioCriativoKit::STATUS_APROVADO,
            'total_slots' => 2,
            'minimo_aprovadas' => 1,
            'aprovado_em' => now()->subMinute(),
        ]);
        MlAnuncioCriativo::create([
            'token' => Str::random(32), 'company_id' => $company->id, 'kit_id' => $kitCriativo->id,
            'slot_indice' => 1, 'slot' => 'hero', 'status' => MlAnuncioCriativo::STATUS_APROVADO,
            'imagem_path' => 'creative-geradas/x/1.jpg',
        ]);
        MlAnuncioCriativo::create([
            'token' => Str::random(32), 'company_id' => $company->id, 'kit_id' => $kitCriativo->id,
            'slot_indice' => 2, 'slot' => 'hero', 'status' => MlAnuncioCriativo::STATUS_PRONTO,
            'imagem_path' => null,
        ]);

        $tela = $this->tela($base->fresh(), $empresa);

        $this->assertCount(1, $tela['criativos']);
        $this->assertSame(1, $tela['criativos'][0]['fase']);
        $this->assertCount(1, $tela['criativos'][0]['miniaturas'], 'slot sem imagem gerada não vira miniatura');
        $url = $tela['criativos'][0]['miniaturas'][0]['url'];
        $this->assertStringContainsString('/criativos/kit/'.$kitCriativo->id.'/slots/1/imagem', $url);
        $this->assertStringNotContainsString($kitCriativo->token, $url, 'D-13: nenhum token no navegador');
        $this->assertContains('criativos', array_column($tela['historico'], 'tipo'));
    }

    public function test_criativos_vazio_quando_nao_ha_kit_aprovado(): void
    {
        [$empresa, $company] = $this->conta();
        $base = $this->base($empresa, $company);
        $this->rascunho($base, PubRascunho::PUBLISHED);

        $this->assertSame([], $this->tela($base->fresh(), $empresa)['criativos']);
    }

    // ═══ Task 1 — cabeçalho ═════════════════════════════════════════════════

    public function test_cabecalho_traz_categoria_do_cache_foto_do_grupo_geral_e_editor_url(): void
    {
        [$empresa, $company] = $this->conta();
        $base = $this->base($empresa, $company);
        $r = $this->rascunho($base, PubRascunho::PUBLISHED);
        $r->update(['categoria_id' => 'MLB193945']);
        \App\Models\MlCategoriaSchema::create([
            'category_id' => 'MLB193945',
            'categoria' => ['id' => 'MLB193945', 'name' => 'Cadeiras de Escritório', 'path_from_root' => [
                ['id' => 'MLB1574', 'name' => 'Casa, Móveis e Decoração'],
                ['id' => 'MLB193945', 'name' => 'Cadeiras de Escritório'],
            ]],
            'atributos' => [], 'technical_specs' => [], 'sale_terms' => [],
            'schema_hash' => str_repeat('a', 64), 'fetched_at' => now(),
        ]);
        $foto = $r->imagens()->create([
            'caminho' => 'publicador/1/a.jpg', 'sha256' => str_repeat('a', 64), 'mime' => 'image/jpeg',
            'bytes' => 1000, 'largura' => 1200, 'altura' => 1200, 'upload_status' => 'uploaded',
        ]);
        $foto->atribuicoes()->create(['grupo_chave' => R::GERAL, 'grupo_hash' => hash('sha256', R::GERAL), 'posicao' => 0]);

        $tela = $this->tela($base->fresh(), $empresa);

        $this->assertSame('Casa, Móveis e Decoração › Cadeiras de Escritório', $tela['base']['categoria']);
        $this->assertStringContainsString('/fotos/'.$foto->id.'/arquivo', (string) $tela['base']['foto_url']);
        $this->assertStringContainsString('/produtos/'.$base->id.'/editor', $tela['base']['editor_url']);
        $this->assertSame(PubProduto::ORIGEM_PUBLICADOR, $tela['base']['origem']);
        Http::assertNothingSent();
    }

    public function test_foto_ja_no_ml_usa_a_url_do_ml(): void
    {
        [$empresa, $company] = $this->conta();
        $base = $this->base($empresa, $company);
        $r = $this->rascunho($base);
        $foto = $r->imagens()->create([
            'caminho' => null, 'sha256' => str_repeat('b', 64), 'mime' => 'image/jpeg',
            'upload_status' => 'uploaded', 'ml_url' => 'https://http2.mlstatic.com/D_1-O.jpg',
        ]);
        $foto->atribuicoes()->create(['grupo_chave' => R::GERAL, 'grupo_hash' => hash('sha256', R::GERAL), 'posicao' => 0]);

        $this->assertSame('https://http2.mlstatic.com/D_1-O.jpg', $this->tela($base->fresh(), $empresa)['base']['foto_url']);
    }

    public function test_categoria_fora_do_cache_nao_chama_o_ml(): void
    {
        [$empresa, $company] = $this->conta();
        $base = $this->base($empresa, $company);
        $this->rascunho($base)->update(['categoria_id' => 'MLB999999']);

        $this->assertSame('MLB999999', $this->tela($base->fresh(), $empresa)['base']['categoria']);
        Http::assertNothingSent();
    }
}
