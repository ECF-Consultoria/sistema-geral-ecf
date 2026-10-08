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
use App\Models\User;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDoProduto;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\PortalParaRascunhoService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Portal\AtorDoPortal;
use App\Support\Publicador\RascunhoSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/**
 * Ponta a ponta (08/10/2026): o cliente preenche na ficha do Portal os campos que antes só o
 * editor interno mostrava — os escondidos editáveis ("Mais detalhes"), o "Material do
 * estofamento" (`allow_variations` que não é o eixo) e o "Não se aplica" —, a ficha GRAVA pelo
 * serviço do Portal (com a validação de verdade), e o Sincronizar os leva ao rascunho.
 *
 * Categoria real guardada (MLB193945, cadeira de escritório); zero HTTP.
 */
class PortalCamposDoEditorNoRascunhoTest extends TestCase
{
    use CarregaSchemas;
    use RefreshDatabase;

    private Company $empresa;

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
        // A ficha do Portal lê os campos da mesma resposta, já em cache (sem rede).
        Cache::put('ml_meta_atributos_'.self::CADEIRA, $schema->atributos, 3600);

        $this->empresa = Company::factory()->create();
    }

    /** @return array{0: EstruturaProduto, 1: PubProduto} */
    private function cadeira(): array
    {
        $p = EstruturaProduto::create(['company_id' => $this->empresa->id, 'codigo' => 'CAD', 'nome' => 'Cadeira',
            'categoria_ml_id' => self::CADEIRA, 'categoria_ml_nome' => 'Cadeiras de Escritório']);
        foreach ([['Azul', 3], ['Preto', 5]] as $i => [$cor, $estoque]) {
            $v = EstruturaProdutoVariacao::create(['produto_id' => $p->id, 'company_id' => $this->empresa->id, 'ordem' => $i,
                'codigo' => 'CAD-'.($i + 1), 'eixo' => 'cor', 'valor' => $cor, 'custo' => 100, 'estoque' => $estoque]);
            EstruturaProdutoVolume::create(['variacao_id' => $v->id, 'ordem' => 0, 'comprimento' => 70, 'largura' => 60, 'altura' => 50, 'peso' => 12]);
            EstruturaOferta::create(['company_id' => $this->empresa->id, 'variacao_id' => $v->id, 'sku' => 'CAD-'.($i + 1), 'fase' => 'simples', 'nome' => "Cadeira {$cor}"]);
        }
        $pub = PubProduto::create(['company_id' => $this->empresa->id, 'estrutura_produto_id' => $p->id, 'sku' => 'CAD', 'nome' => 'Cadeira', 'origem' => PubProduto::ORIGEM_PORTAL]);

        return [$p, $pub];
    }

    /** Os 11 obrigatórios da categoria (sem eles a ficha não grava) + o que se quer provar. */
    private function gravarFicha(EstruturaProduto $p, array $mais): void
    {
        $obrigatorios = [
            ['id' => 'BRAND', 'valor' => 'ECF'], ['id' => 'MODEL', 'valor' => 'Executiva'],
            ['id' => 'BACKREST_HEIGHT', 'valor' => '60', 'unidade' => 'cm'], ['id' => 'SEAT_DEPTH', 'valor' => '45', 'unidade' => 'cm'],
            ['id' => 'OFFICE_CHAIR_WIDTH', 'valor' => '62', 'unidade' => 'cm'], ['id' => 'MAX_CHAIR_HEIGHT', 'valor' => '120', 'unidade' => 'cm'],
            ['id' => 'REQUIRES_ASSEMBLY', 'valor' => 'Sim'], ['id' => 'IS_GAMER', 'valor' => 'Não'], ['id' => 'IS_ERGONOMIC', 'valor' => 'Sim'],
            ['id' => 'IS_SWIVEL', 'valor' => 'Sim'], ['id' => 'INCLUDES_ASSEMBLY_MANUAL', 'valor' => 'Sim'],
        ];
        $ator = AtorDoPortal::daEquipe(User::factory()->create(['role' => 'consultor', 'active' => true]));

        app(FichaTecnicaDoProduto::class)->gravar($this->empresa, $p, array_merge($obrigatorios, $mais), $ator);
    }

    private function snap(PubProduto $pub): RascunhoSnapshot
    {
        return (new RascunhoRepository())->snapshot(PubRascunho::where('produto_id', $pub->id)->firstOrFail());
    }

    public function test_campos_novos_e_nao_se_aplica_do_portal_chegam_ao_rascunho(): void
    {
        [$p, $pub] = $this->cadeira();
        $this->gravarFicha($p, [
            ['id' => 'SEAT_WIDTH', 'valor' => '48,5', 'unidade' => 'cm'],              // escondido editável, número com unidade
            ['id' => 'LUMBAR_SUPPORT_TYPE', 'valor' => '10201909'],                    // escondido editável, lista (Regulável)
            ['id' => 'IS_KIT', 'valor' => 'Não'],                                      // escondido editável, Sim/Não
            ['id' => 'UPHOLSTERY_MATERIAL', 'valor' => '482783'],                      // allow_variations que não é o eixo (Couro)
            ['id' => 'WITH_LIGHTS', 'nao_se_aplica' => true],                          // "Não se aplica"
            ['id' => 'BASE_DIAMETER', 'nao_se_aplica' => true],                        // "Não se aplica" num escondido
        ]);

        // O que o Portal gravou.
        $gravado = EstruturaProdutoAtributo::where('produto_id', $p->id)->get()->keyBy('atributo_id');
        $this->assertSame('48.5', $gravado['SEAT_WIDTH']->valor);
        $this->assertSame('cm', $gravado['SEAT_WIDTH']->unidade);
        $this->assertSame('482783', $gravado['UPHOLSTERY_MATERIAL']->valor_id);
        $this->assertSame(FichaTecnicaDoProduto::NAO_SE_APLICA, $gravado['WITH_LIGHTS']->valor_id);
        $this->assertNull($gravado['WITH_LIGHTS']->valor);

        $resumo = app(PortalParaRascunhoService::class)->preencher($pub);
        $a = $this->snap($pub)->atributos;

        $this->assertSame(48.5, (float) $a['SEAT_WIDTH']['value_number']);
        $this->assertSame('cm', $a['SEAT_WIDTH']['value_unit']);
        $this->assertSame('portal', $a['SEAT_WIDTH']['origem']);
        $this->assertSame('10201909', $a['LUMBAR_SUPPORT_TYPE']['value_id']);
        $this->assertSame('242084', $a['IS_KIT']['value_id']);
        $this->assertSame('482783', $a['UPHOLSTERY_MATERIAL']['value_id']);
        $this->assertSame('Couro', $a['UPHOLSTERY_MATERIAL']['value_name']);
        $this->assertSame('-1', $a['WITH_LIGHTS']['value_id']);
        $this->assertNull($a['WITH_LIGHTS']['value_name'] ?? null);
        $this->assertSame('-1', $a['BASE_DIAMETER']['value_id']);
        $this->assertArrayNotHasKey('COLOR', $a, 'a cor é a variação, não atributo do produto');
        $this->assertSame([], array_filter($resumo['avisos'], fn ($x) => str_contains($x, 'Não se aplica')));
        Http::assertNothingSent();
    }

    public function test_nao_se_aplica_do_portal_so_preenche_o_vazio(): void
    {
        [$p, $pub] = $this->cadeira();
        $this->gravarFicha($p, [
            ['id' => 'WITH_LIGHTS', 'nao_se_aplica' => true],
            ['id' => 'WITH_HEADREST', 'nao_se_aplica' => true],
        ]);

        // A equipe já respondeu um deles no editor.
        $editor = app(EditorRascunhoService::class);
        $r = $editor->rascunhoDoProduto($pub, false);
        $editor->trocarCategoria($r, self::CADEIRA);
        (new RascunhoRepository())->mesclarAtributos($r, ['WITH_HEADREST' => ['value_id' => '242085', 'value_name' => 'Sim', 'origem' => 'user']]);

        app(PortalParaRascunhoService::class)->preencher($pub);
        $a = $this->snap($pub)->atributos;

        $this->assertSame('242085', $a['WITH_HEADREST']['value_id'], 'o que a equipe preencheu fica');
        $this->assertSame('user', $a['WITH_HEADREST']['origem']);
        $this->assertSame('-1', $a['WITH_LIGHTS']['value_id'], 'o vazio recebe o N/A do Portal');

        // E o N/A, uma vez no rascunho, conta como preenchido: rodar de novo não muda nada.
        $antes = PubRascunho::where('produto_id', $pub->id)->value('revisao');
        app(PortalParaRascunhoService::class)->preencher($pub);
        $this->assertSame($antes, PubRascunho::where('produto_id', $pub->id)->value('revisao'));
    }
}
