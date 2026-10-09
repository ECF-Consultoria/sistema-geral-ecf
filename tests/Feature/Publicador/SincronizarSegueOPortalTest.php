<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoAtributo;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVolume;
use App\Models\MlCategoriaSchema;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\PortalParaRascunhoService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\RascunhoSnapshot;
use App\Support\Publicador\Schema\AtributoClassificado;
use App\Support\Publicador\Schema\ClassificadorAtributos;
use App\Support\Publicador\Schema\ContextoClassificacao;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/**
 * 09/10/2026 — o Sincronizar segue o que o PRÓPRIO Portal escreveu (D-05 refinado) e os avisos vão
 * para o log, não para a tela.
 *
 * O caso real (#459, rascunho 13, Puff 2): `SHAPE = "REDONDO"` gravado pelo Portal às 10:00; o cliente
 * trocou para "Redonda" às 10:43; o Sincronizar seguinte contou "mantido porque já estava preenchido" e
 * o rascunho ficou com o valor velho. Agora: `origem = 'portal'` segue o Portal (muda e, se o cliente
 * apagou, sai); `user`/`ia`/outra nunca muda. Estoque e SKU da variante (sem coluna `origem`) seguem
 * pela memória `step_state.portal_escrito`: só se o rascunho ainda tem o último valor do Portal.
 */
class SincronizarSegueOPortalTest extends TestCase
{
    use CarregaSchemas;
    use RefreshDatabase;

    private Company $empresa;

    private RascunhoRepository $repo;

    private EditorRascunhoService $editor;

    private PortalParaRascunhoService $servico;

    /** Atributo da cadeira guardada com opções e que aceita texto (como o SHAPE do pufe). */
    private AtributoClassificado $forma;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake();

        $schema = self::schema(self::CADEIRA);
        MlCategoriaSchema::create(['category_id' => self::CADEIRA, 'domain_id' => $schema->dominio(), 'categoria' => $schema->categoria,
            'atributos' => $schema->atributos, 'technical_specs' => $schema->technicalSpecs, 'sale_terms' => $schema->saleTerms,
            'schema_hash' => $schema->hash(), 'fetched_at' => now()]);

        $classificado = (new ClassificadorAtributos())->classificar($schema, new ContextoClassificacao('new', ['COLOR']));
        $this->forma = collect($classificado->atributos)->first(fn (AtributoClassificado $a) => $a->papel === AtributoClassificado::PRODUCT
            && ! $a->multivalor && $a->aceitaTextoLivre && count($a->valores) >= 2 && $a->secao !== AtributoClassificado::SECAO_EMBALAGEM
            && $a->id !== 'BRAND' && $a->id !== 'MODEL');
        $this->assertNotNull($this->forma, 'a cadeira guardada tem um atributo de opções que aceita texto');

        $this->empresa = Company::factory()->create();
        $this->repo = new RascunhoRepository();
        $this->editor = app(EditorRascunhoService::class);
        $this->servico = app(PortalParaRascunhoService::class);
    }

    // ═══ Ajudantes ═══════════════════════════════════════════════════════════

    /** @return array{0: EstruturaProduto, 1: PubProduto, 2: list<EstruturaProdutoVariacao>} */
    private function produto(array $cores = [['Azul', 10], ['Preto', 5]], array $extra = []): array
    {
        $p = EstruturaProduto::create(['company_id' => $this->empresa->id, 'codigo' => 'PUFF', 'nome' => 'Puff 2',
            'categoria_ml_id' => self::CADEIRA, 'categoria_ml_nome' => 'Categoria']);
        $vars = [];
        foreach ($cores as $i => [$nome, $estoque]) {
            $v = EstruturaProdutoVariacao::create(['produto_id' => $p->id, 'company_id' => $this->empresa->id, 'ordem' => $i,
                'codigo' => 'PUFF-'.($i + 1), 'eixo' => 'cor', 'valor' => $nome, 'custo' => 10, 'estoque' => $estoque]);
            EstruturaProdutoVolume::create(['variacao_id' => $v->id, 'ordem' => 0, 'comprimento' => 50, 'largura' => 40, 'altura' => 30, 'peso' => 2.5]);
            EstruturaOferta::create(['company_id' => $this->empresa->id, 'variacao_id' => $v->id, 'sku' => 'PUFF-'.($i + 1), 'fase' => 'simples', 'nome' => "Puff {$nome}"]);
            $vars[] = $v;
        }
        $pub = PubProduto::create(['company_id' => $this->empresa->id, 'estrutura_produto_id' => $p->id, 'sku' => 'PUFF', 'nome' => 'Puff 2',
            'origem' => PubProduto::ORIGEM_PORTAL, ...$extra]);

        return [$p, $pub, $vars];
    }

    /** Grava (ou troca) um campo da ficha do Portal, como a tela do cliente faz. */
    private function noPortal(EstruturaProduto $p, string $id, string $valor, ?string $valorId = null): void
    {
        EstruturaProdutoAtributo::updateOrCreate(['company_id' => $this->empresa->id, 'produto_id' => $p->id, 'atributo_id' => $id],
            ['atributo_nome' => $id, 'valor' => $valor, 'valor_id' => $valorId]);
    }

    private function rascunho(PubProduto $pub): PubRascunho
    {
        return PubRascunho::where('produto_id', $pub->id)->firstOrFail();
    }

    private function snap(PubProduto $pub): RascunhoSnapshot
    {
        return $this->repo->snapshot($this->rascunho($pub));
    }

    /** @return array<string, array> cor → dados da variante */
    private function porCor(PubProduto $pub): array
    {
        $saida = [];
        foreach ($this->snap($pub)->variantes as $v) {
            $saida[collect($v->valores)->first()?->valueName ?? ChaveCanonica::UNICA] = $v->dados;
        }

        return $saida;
    }

    /** A equipe grava um atributo pelo editor (a tela manda `origem: 'user'`). */
    private function equipeGrava(PubProduto $pub, string $id, array $valor): void
    {
        $daTela = $this->snap($pub)->atributos;
        $daTela[$id] = $valor + ['origem' => 'user', 'revisar' => false];
        $this->editor->salvar($this->rascunho($pub), ['atributos' => $daTela]);
    }

    // ═══ Ficha técnica ═══════════════════════════════════════════════════════

    public function test_o_caso_do_shape_o_cliente_corrige_no_portal_e_o_rascunho_acompanha(): void
    {
        [$p, $pub] = $this->produto();
        $this->noPortal($p, $this->forma->id, 'REDONDO');
        $this->servico->preencher($pub);
        $this->assertSame('REDONDO', $this->snap($pub)->atributos[$this->forma->id]['value_name']);
        $this->assertSame('portal', $this->snap($pub)->atributos[$this->forma->id]['origem']);

        // 10:43 — o cliente escolhe a opção certa no Portal.
        $opcao = $this->forma->valores[1];
        $this->noPortal($p, $this->forma->id, $opcao['name'], $opcao['id']);
        $resumo = $this->servico->preencher($pub);

        $atual = $this->snap($pub)->atributos[$this->forma->id];
        $this->assertSame($opcao['id'], $atual['value_id']);
        $this->assertSame($opcao['name'], $atual['value_name']);
        $this->assertSame('portal', $atual['origem']);
        $this->assertSame(1, $resumo['campos_atualizados']);
    }

    public function test_o_que_a_equipe_gravou_nunca_muda_mesmo_que_o_portal_mude(): void
    {
        [$p, $pub] = $this->produto();
        $this->noPortal($p, $this->forma->id, 'REDONDO');
        $this->noPortal($p, 'BRAND', 'ECF');
        $this->servico->preencher($pub);

        $this->equipeGrava($pub, $this->forma->id, ['value_name' => 'Da equipe']);
        $this->noPortal($p, $this->forma->id, $this->forma->valores[1]['name'], $this->forma->valores[1]['id']);
        $resumo = $this->servico->preencher($pub);

        $atual = $this->snap($pub)->atributos[$this->forma->id];
        $this->assertSame('Da equipe', $atual['value_name']);
        $this->assertSame('user', $atual['origem']);
        $this->assertSame(0, $resumo['campos_atualizados']);

        // E apagar no Portal também não tira o que é da equipe.
        EstruturaProdutoAtributo::where('atributo_id', $this->forma->id)->delete();
        $this->servico->preencher($pub);
        $this->assertSame('Da equipe', $this->snap($pub)->atributos[$this->forma->id]['value_name']);
    }

    public function test_o_que_a_ia_escreveu_nunca_muda(): void
    {
        [$p, $pub] = $this->produto();
        $this->noPortal($p, 'MODEL', 'Puff do Portal');
        $this->servico->preencher($pub);
        $this->repo->mesclarAtributos($this->rascunho($pub), ['MODEL' => ['value_name' => 'Puff da IA', 'origem' => 'ia']]);

        $this->noPortal($p, 'MODEL', 'Puff do Portal 2');
        $this->servico->preencher($pub);

        $this->assertSame('Puff da IA', $this->snap($pub)->atributos['MODEL']['value_name']);
        $this->assertSame('ia', $this->snap($pub)->atributos['MODEL']['origem']);
    }

    public function test_o_cliente_apagou_no_portal_e_o_valor_que_o_portal_escreveu_sai(): void
    {
        [$p, $pub] = $this->produto();
        $this->noPortal($p, 'MODEL', 'Puff');
        $this->noPortal($p, 'BRAND', 'ECF');
        $this->servico->preencher($pub);
        $this->assertArrayHasKey('MODEL', $this->snap($pub)->atributos);

        EstruturaProdutoAtributo::where('atributo_id', 'MODEL')->delete();
        $resumo = $this->servico->preencher($pub);

        $this->assertArrayNotHasKey('MODEL', $this->snap($pub)->atributos);
        $this->assertSame('ECF', $this->snap($pub)->atributos['BRAND']['value_name'], 'só o campo apagado sai');
        $this->assertSame(1, $resumo['campos_atualizados']);
    }

    public function test_pacote_escrito_pelo_portal_acompanha_o_volume_novo_e_o_da_equipe_fica(): void
    {
        [$p, $pub, $vars] = $this->produto();
        $this->servico->preencher($pub);
        $this->assertSame('50 cm', $this->snap($pub)->atributos['SELLER_PACKAGE_LENGTH']['value_name']);

        $this->equipeGrava($pub, 'SELLER_PACKAGE_WIDTH', ['value_name' => '99 cm']);
        foreach ($vars as $v) {
            EstruturaProdutoVolume::where('variacao_id', $v->id)->update(['comprimento' => 60, 'largura' => 45]);
        }
        $this->servico->preencher($pub);

        $this->assertSame('60 cm', $this->snap($pub)->atributos['SELLER_PACKAGE_LENGTH']['value_name']);
        $this->assertSame('99 cm', $this->snap($pub)->atributos['SELLER_PACKAGE_WIDTH']['value_name']);
    }

    // ═══ Estoque e SKU da variante (memória `portal_escrito`) ════════════════

    public function test_estoque_escrito_pelo_portal_e_depois_mudado_no_portal_e_atualizado(): void
    {
        [, $pub, $vars] = $this->produto();
        $this->servico->preencher($pub);
        $this->assertSame(10, $this->porCor($pub)['Azul']['estoque']);

        $vars[0]->update(['estoque' => 4]);
        $resumo = $this->servico->preencher($pub);

        $this->assertSame(4, $this->porCor($pub)['Azul']['estoque']);
        $this->assertSame(5, $this->porCor($pub)['Preto']['estoque']);
        $this->assertSame(1, $resumo['campos_atualizados']);
        $memoria = $this->rascunho($pub)->step_state['portal_escrito'];
        $this->assertContains(4, array_column($memoria, 'estoque'));
    }

    public function test_estoque_mudado_pela_equipe_no_publicador_nao_e_atualizado(): void
    {
        [, $pub, $vars] = $this->produto();
        $this->servico->preencher($pub);
        $azul = collect($this->snap($pub)->variantes)->first(fn ($v) => collect($v->valores)->first()?->valueName === 'Azul');
        $this->editor->salvarVariantes($this->rascunho($pub), [$azul->chave => ['estoque' => 99]]);

        $vars[0]->update(['estoque' => 4]);
        $this->servico->preencher($pub);

        $this->assertSame(99, $this->porCor($pub)['Azul']['estoque']);
    }

    public function test_sku_trocado_no_portal_acompanha_e_o_da_equipe_fica(): void
    {
        [, $pub, $vars] = $this->produto();
        $this->servico->preencher($pub);
        $preto = collect($this->snap($pub)->variantes)->first(fn ($v) => collect($v->valores)->first()?->valueName === 'Preto');
        $dados = $preto->dados;
        $dados['atributos']['SELLER_SKU'] = ['value_name' => 'SKU-DA-EQUIPE'];
        $this->editor->salvarVariantes($this->rascunho($pub), [$preto->chave => ['atributos' => $dados['atributos']]]);

        $vars[0]->update(['codigo' => 'PUFF-AZUL']);
        $vars[1]->update(['codigo' => 'PUFF-PRETO']);
        $this->servico->preencher($pub);

        $this->assertSame('PUFF-AZUL', $this->porCor($pub)['Azul']['atributos']['SELLER_SKU']['value_name']);
        $this->assertSame('SKU-DA-EQUIPE', $this->porCor($pub)['Preto']['atributos']['SELLER_SKU']['value_name']);
    }

    public function test_rascunho_de_antes_da_memoria_passa_a_seguir_o_portal_quando_o_valor_e_igual(): void
    {
        [, $pub, $vars] = $this->produto();
        $this->servico->preencher($pub);
        // Rascunho preenchido antes de 09/10: sem a memória.
        $estado = $this->rascunho($pub)->step_state;
        unset($estado['portal_escrito']);
        $this->rascunho($pub)->update(['step_state' => $estado]);

        $this->servico->preencher($pub); // igual ao Portal: anota como dele, sem gravar nada
        $vars[0]->update(['estoque' => 3]);
        $this->servico->preencher($pub);

        $this->assertSame(3, $this->porCor($pub)['Azul']['estoque']);
    }

    public function test_kit_com_estoque_calculado_nao_recebe_estoque_do_portal(): void
    {
        [, $pub] = $this->produto([['Azul', 10]], ['estoque_calculado' => true]);

        $this->servico->preencher($pub);

        $this->assertArrayNotHasKey('estoque', $this->porCor($pub)[ChaveCanonica::UNICA]);
        $this->assertSame('PUFF-1', $this->porCor($pub)[ChaveCanonica::UNICA]['atributos']['SELLER_SKU']['value_name'], 'o SKU continua vindo');
    }

    // ═══ Idempotência e trava ════════════════════════════════════════════════

    public function test_rodar_duas_vezes_sem_mudanca_no_portal_nao_sobe_a_revisao(): void
    {
        [$p, $pub, $vars] = $this->produto();
        $this->noPortal($p, $this->forma->id, 'REDONDO');
        $this->servico->preencher($pub);
        $this->noPortal($p, $this->forma->id, $this->forma->valores[1]['name'], $this->forma->valores[1]['id']);
        $vars[0]->update(['estoque' => 4]);
        $this->servico->preencher($pub);

        $revisao = $this->rascunho($pub)->revisao;
        $estado = $this->rascunho($pub)->step_state;
        $resumo = $this->servico->preencher($pub);
        $this->servico->preencher($pub);

        $this->assertSame($revisao, $this->rascunho($pub)->revisao);
        $this->assertSame($estado, $this->rascunho($pub)->step_state);
        $this->assertSame(0, $resumo['campos_atualizados']);
        $this->assertSame(0, $resumo['campos_preenchidos']);
    }

    public function test_rascunho_publicado_nao_segue_o_portal(): void
    {
        [$p, $pub, $vars] = $this->produto();
        $this->noPortal($p, 'MODEL', 'Puff');
        $this->servico->preencher($pub);
        $this->rascunho($pub)->update(['status' => PubRascunho::PUBLISHED]);

        $this->noPortal($p, 'MODEL', 'Puff novo');
        $vars[0]->update(['estoque' => 1]);
        $resumo = $this->servico->preencher($pub);

        $this->assertTrue($resumo['intocavel']);
        $this->assertSame('Puff', $this->snap($pub)->atributos['MODEL']['value_name']);
        $this->assertSame(10, $this->porCor($pub)['Azul']['estoque']);
    }

    // ═══ Avisos: log, não tela ═══════════════════════════════════════════════

    public function test_os_avisos_vao_para_o_log_com_a_empresa_e_o_rascunho(): void
    {
        Log::spy();
        [$p, $pub] = $this->produto();
        $this->noPortal($p, 'BRAND', 'ECF');
        $this->noPortal($p, 'MAX_WEIGHT_SUPPORTED', 'muito'); // número inválido: vira aviso

        $resumo = $this->servico->preencher($pub);

        $this->assertNotEmpty($resumo['avisos']);
        $rascunhoId = $this->rascunho($pub)->id;
        Log::shouldHaveReceived('info')->withArgs(fn ($mensagem, $contexto = []) => $mensagem === '[Publicador] Sincronizar avisos'
            && $contexto['company_id'] === (int) $this->empresa->id
            && $contexto['rascunho_id'] === $rascunhoId
            && $contexto['avisos'] === array_values(array_unique($resumo['avisos'])))->once();
    }
}
