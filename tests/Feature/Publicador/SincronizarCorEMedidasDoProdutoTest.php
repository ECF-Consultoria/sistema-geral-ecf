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
use App\Support\Publicador\Variacao\ChaveCanonica;
use App\Support\Publicador\Variacao\Variante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/**
 * 09/10/2026 — o Puff Redondo da #459 (UMA variação, eixo Cor = "Azul") chegou ao editor com "Cor" e
 * "Cor principal" vazias, e as medidas do produto fora da caixa nunca vinham (o Portal não as pedia).
 *
 * - Cor: o produto de uma cor só leva a cor da variação para a "Cor" (COLOR) do produto, `origem =
 *   portal`; a "Cor principal" (MAIN_COLOR, lista fechada) da variante recebe a opção de mesmo nome
 *   (sem acento/caixa). Com várias cores, cada variante recebe a sua. A equipe manda no que mudou.
 * - Medidas: HEIGHT/WIDTH/LENGTH/DIAMETER/WEIGHT do Portal chegam no formato do editor ("60 cm").
 *
 * Categoria real guardada (MLB193945, que tem COLOR e MAIN_COLOR de verdade) + as medidas genéricas
 * do produto, que a cadeira não tem. Zero HTTP.
 */
class SincronizarCorEMedidasDoProdutoTest extends TestCase
{
    use CarregaSchemas;
    use RefreshDatabase;

    /** Os ids da opção na lista real da "Cor principal" (MAIN_COLOR) da cadeira. */
    private const TOM_AZUL = '2450293';

    private const TOM_VERDE = '2450314';

    private const TOM_PRETO = '2450295';

    private Company $empresa;

    private RascunhoRepository $repo;

    private PortalParaRascunhoService $servico;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake();

        $schema = self::schema(self::CADEIRA, function (array $f) {
            $medida = fn (string $id, string $nome, array $unidades, string $padrao) => ['id' => $id, 'name' => $nome, 'value_type' => 'number_unit',
                'tags' => ['hidden' => true], 'allowed_units' => array_map(fn ($u) => ['id' => $u, 'name' => $u], $unidades), 'default_unit' => $padrao,
                'attribute_group_id' => 'OTHERS', 'attribute_group_name' => 'Outros'];
            foreach ([['LENGTH', 'Comprimento'], ['WIDTH', 'Largura'], ['HEIGHT', 'Altura'], ['DIAMETER', 'Diâmetro']] as [$id, $nome]) {
                $f['atributos'][] = $medida($id, $nome, ['cm', 'mm', 'm'], 'cm');
            }
            $f['atributos'][] = $medida('WEIGHT', 'Peso', ['g', 'kg'], 'kg');

            return $f;
        });
        MlCategoriaSchema::create(['category_id' => self::CADEIRA, 'domain_id' => $schema->dominio(), 'categoria' => $schema->categoria,
            'atributos' => $schema->atributos, 'technical_specs' => $schema->technicalSpecs, 'sale_terms' => $schema->saleTerms,
            'schema_hash' => $schema->hash(), 'fetched_at' => now()]);

        $this->empresa = Company::factory()->create();
        $this->repo = new RascunhoRepository();
        $this->servico = app(PortalParaRascunhoService::class);
    }

    // ═══ Ajudantes ═══════════════════════════════════════════════════════════

    /**
     * O Puff com as cores pedidas (uma variação por cor, eixo cor).
     *
     * @param  list<string>  $cores
     * @return array{0: EstruturaProduto, 1: PubProduto, 2: list<EstruturaProdutoVariacao>}
     */
    private function puff(array $cores = ['Azul']): array
    {
        $p = EstruturaProduto::create(['company_id' => $this->empresa->id, 'codigo' => 'PUFF', 'nome' => 'Puff Redondo',
            'categoria_ml_id' => self::CADEIRA, 'categoria_ml_nome' => 'Categoria']);
        $variacoes = [];
        foreach ($cores as $i => $cor) {
            $v = EstruturaProdutoVariacao::create(['produto_id' => $p->id, 'company_id' => $this->empresa->id, 'ordem' => $i,
                'codigo' => 'PUFF-'.($i + 1), 'eixo' => 'cor', 'valor' => $cor, 'custo' => 10, 'estoque' => 4]);
            // O pacote do Puff na #459: 23 × 22 × 15 cm, 8 kg.
            EstruturaProdutoVolume::create(['variacao_id' => $v->id, 'ordem' => 0, 'comprimento' => 15, 'largura' => 22, 'altura' => 23, 'peso' => 8]);
            EstruturaOferta::create(['company_id' => $this->empresa->id, 'variacao_id' => $v->id, 'sku' => 'PUFF-'.($i + 1), 'fase' => 'simples', 'nome' => "Puff {$cor}"]);
            $variacoes[] = $v;
        }
        $pub = PubProduto::create(['company_id' => $this->empresa->id, 'estrutura_produto_id' => $p->id, 'sku' => 'PUFF', 'nome' => 'Puff Redondo',
            'origem' => PubProduto::ORIGEM_PORTAL]);

        return [$p, $pub, $variacoes];
    }

    private function noPortal(EstruturaProduto $p, string $id, ?string $valor, ?string $unidade = null): void
    {
        EstruturaProdutoAtributo::updateOrCreate(['company_id' => $this->empresa->id, 'produto_id' => $p->id, 'atributo_id' => $id],
            ['atributo_nome' => $id, 'valor' => $valor, 'valor_id' => null, 'unidade' => $unidade]);
    }

    private function rascunho(PubProduto $pub): PubRascunho
    {
        return PubRascunho::where('produto_id', $pub->id)->firstOrFail();
    }

    private function atributo(PubProduto $pub, string $id): ?array
    {
        return $this->repo->snapshot($this->rascunho($pub))->atributos[$id] ?? null;
    }

    /** @return array<string, ?string> nome da cor da variante (ou "única") → `value_id` da Cor principal */
    private function tons(PubProduto $pub): array
    {
        $snap = $this->repo->snapshot($this->rascunho($pub));
        $saida = [];
        foreach ($snap->variantes as $v) {
            /** @var Variante $v */
            if ($v->orfa) {
                continue;
            }
            $nome = $v->chave === ChaveCanonica::UNICA ? 'única' : (string) (array_values($v->valores)[0]->valueName ?? $v->chave);
            $saida[$nome] = $v->dados['atributos']['MAIN_COLOR']['value_id'] ?? null;
        }

        return $saida;
    }

    /** Troca a Cor principal da variante única como a TELA troca (a pessoa escolheu outra opção). */
    private function equipeEscolheOTom(PubProduto $pub, string $valueId, string $nome): void
    {
        $r = $this->rascunho($pub);
        $unica = collect($this->repo->snapshot($r)->variantes)->first(fn (Variante $v) => $v->chave === ChaveCanonica::UNICA);
        $atributos = (array) ($unica->dados['atributos'] ?? []);
        $atributos['MAIN_COLOR'] = ['value_id' => $valueId, 'value_name' => $nome, 'origem' => 'user'];
        app(EditorRascunhoService::class)->salvarVariantes($r, [ChaveCanonica::UNICA => ['atributos' => $atributos]]);
    }

    // ═══ Cor: o caso exato do Puff ═══════════════════════════════════════════

    public function test_o_puff_de_uma_cor_azul_chega_com_cor_e_cor_principal(): void
    {
        [, $pub] = $this->puff(['Azul']);

        $this->servico->preencher($pub);

        $cor = $this->atributo($pub, 'COLOR');
        $this->assertNotNull($cor, 'a Cor do produto vem da variação');
        $this->assertSame('Azul', $cor['value_name']);
        $this->assertSame('portal', $cor['origem']);
        $this->assertSame(['única' => self::TOM_AZUL], $this->tons($pub), 'a Cor principal casa com a opção "Azul" da lista');
        $this->assertSame([], $this->repo->snapshot($this->rascunho($pub))->eixos, 'uma cor só não vira eixo');

        // Rodar de novo sem mudança no Portal não muda nada.
        $revisao = $this->rascunho($pub)->revisao;
        $resumo = $this->servico->preencher($pub);
        $this->assertSame(0, $resumo['campos_atualizados']);
        $this->assertSame(0, $resumo['campos_preenchidos']);
        $this->assertSame($revisao, $this->rascunho($pub)->revisao);
        Http::assertNothingSent();
    }

    public function test_o_cliente_troca_a_cor_e_a_cor_e_a_cor_principal_do_portal_acompanham(): void
    {
        [, $pub, [$variacao]] = $this->puff(['Azul']);
        $this->servico->preencher($pub);

        $variacao->update(['valor' => 'Verde']);
        $resumo = $this->servico->preencher($pub);

        $this->assertSame('Verde', $this->atributo($pub, 'COLOR')['value_name']);
        $this->assertSame(['única' => self::TOM_VERDE], $this->tons($pub));
        $this->assertSame(2, $resumo['campos_atualizados'], 'a Cor e a Cor principal');
    }

    public function test_cor_e_cor_principal_que_a_equipe_escolheu_nunca_sao_trocadas(): void
    {
        [, $pub, [$variacao]] = $this->puff(['Azul']);
        $this->servico->preencher($pub);
        $this->repo->mesclarAtributos($this->rascunho($pub), ['COLOR' => ['value_id' => null, 'value_name' => 'Azul Royal', 'origem' => 'user']]);
        $this->equipeEscolheOTom($pub, self::TOM_PRETO, 'Preto');

        $variacao->update(['valor' => 'Verde']);
        $this->servico->preencher($pub);

        $this->assertSame('Azul Royal', $this->atributo($pub, 'COLOR')['value_name']);
        $this->assertSame('user', $this->atributo($pub, 'COLOR')['origem']);
        $this->assertSame(['única' => self::TOM_PRETO], $this->tons($pub), 'a escolha da equipe fica');
    }

    public function test_cor_sem_opcao_de_mesmo_nome_deixa_a_cor_principal_vazia_e_avisa_so_no_log(): void
    {
        [, $pub] = $this->puff(['Furta-cor']);

        $resumo = $this->servico->preencher($pub);

        $this->assertSame('Furta-cor', $this->atributo($pub, 'COLOR')['value_name'], 'a Cor aceita nome próprio');
        $this->assertSame(['única' => null], $this->tons($pub), 'sinônimo/aproximação é da tela, não do Sincronizar');
        $this->assertNotEmpty(array_filter($resumo['avisos'], fn ($a) => str_contains($a, 'Cor principal')));
    }

    public function test_acento_e_caixa_nao_impedem_o_casamento(): void
    {
        [, $pub] = $this->puff(['  aZUL ']);

        $this->servico->preencher($pub);

        $this->assertSame(['única' => self::TOM_AZUL], $this->tons($pub));
    }

    public function test_varias_variacoes_da_mesma_cor_tambem_levam_a_cor_do_produto(): void
    {
        [$p, $pub] = $this->puff(['Azul']);
        // Uma segunda variação de mesma cor (ex.: dois tamanhos que o cliente pôs como cor repetida).
        $v2 = EstruturaProdutoVariacao::create(['produto_id' => $p->id, 'company_id' => $this->empresa->id, 'ordem' => 1,
            'codigo' => 'PUFF-2', 'eixo' => 'cor', 'valor' => 'azul', 'custo' => 10, 'estoque' => 2]);
        EstruturaOferta::create(['company_id' => $this->empresa->id, 'variacao_id' => $v2->id, 'sku' => 'PUFF-2', 'fase' => 'simples', 'nome' => 'Puff azul']);

        $this->servico->preencher($pub);

        $this->assertSame('Azul', $this->atributo($pub, 'COLOR')['value_name']);
        $this->assertSame(['única' => self::TOM_AZUL], $this->tons($pub));
    }

    // ═══ Cor: várias cores ═══════════════════════════════════════════════════

    public function test_tres_cores_cada_variante_recebe_a_sua_cor_principal(): void
    {
        [, $pub] = $this->puff(['Azul', 'Verde', 'Furta-cor']);

        $this->servico->preencher($pub);

        $this->assertSame(['Azul' => self::TOM_AZUL, 'Verde' => self::TOM_VERDE, 'Furta-cor' => null], $this->tons($pub));
        $this->assertNull($this->atributo($pub, 'COLOR'), 'com várias cores a Cor mora no eixo, não no produto');
        $this->assertSame('COLOR', $this->repo->snapshot($this->rascunho($pub))->eixos[0]->chave);

        $revisao = $this->rascunho($pub)->revisao;
        $resumo = $this->servico->preencher($pub);
        $this->assertSame(0, $resumo['campos_atualizados']);
        $this->assertSame($revisao, $this->rascunho($pub)->revisao, 'rodar de novo não muda nada');
    }

    public function test_produto_de_uma_cor_que_ganha_outras_tira_a_cor_do_produto_que_o_portal_escreveu(): void
    {
        [$p, $pub] = $this->puff(['Azul']);
        $this->servico->preencher($pub);
        $this->assertSame('Azul', $this->atributo($pub, 'COLOR')['value_name']);

        $v2 = EstruturaProdutoVariacao::create(['produto_id' => $p->id, 'company_id' => $this->empresa->id, 'ordem' => 1,
            'codigo' => 'PUFF-2', 'eixo' => 'cor', 'valor' => 'Verde', 'custo' => 10, 'estoque' => 2]);
        EstruturaOferta::create(['company_id' => $this->empresa->id, 'variacao_id' => $v2->id, 'sku' => 'PUFF-2', 'fase' => 'simples', 'nome' => 'Puff Verde']);
        $this->servico->preencher($pub);

        $this->assertNull($this->atributo($pub, 'COLOR'), 'a Cor passou a ser o eixo');
        $this->assertSame(['Azul' => self::TOM_AZUL, 'Verde' => self::TOM_VERDE], $this->tons($pub));
    }

    // ═══ Medidas do produto fora da caixa ════════════════════════════════════

    public function test_medidas_do_produto_chegam_no_formato_do_editor_e_o_diametro_vem(): void
    {
        [$p, $pub] = $this->puff(['Azul']);
        $this->noPortal($p, 'LENGTH', '60', 'cm');
        $this->noPortal($p, 'WIDTH', '60', 'cm');
        $this->noPortal($p, 'HEIGHT', '40', 'cm');
        $this->noPortal($p, 'DIAMETER', '58.5', 'cm');
        $this->noPortal($p, 'WEIGHT', '7.5', 'kg');

        $this->servico->preencher($pub);

        $esperado = ['LENGTH' => '60 cm', 'WIDTH' => '60 cm', 'HEIGHT' => '40 cm', 'DIAMETER' => '58.5 cm', 'WEIGHT' => '7.5 kg'];
        $estado = app(EditorRascunhoService::class)->estado($this->rascunho($pub));
        foreach ($esperado as $id => $texto) {
            $a = $this->atributo($pub, $id);
            $this->assertSame($texto, $a['value_name'] ?? null, $id);
            $this->assertNull($a['value_number'] ?? null, $id);
            $this->assertSame('portal', $a['origem'], $id);
            $this->assertSame($texto, $estado['atributos'][$id]['value_name'] ?? null, "{$id} aparece no editor");
        }
        // O pacote (o embalado) continua o do Volume, separado das medidas do produto.
        $this->assertSame('23 cm', $this->atributo($pub, 'SELLER_PACKAGE_HEIGHT')['value_name']);
        $this->assertSame('8000 g', $this->atributo($pub, 'SELLER_PACKAGE_WEIGHT')['value_name']);
    }
}
