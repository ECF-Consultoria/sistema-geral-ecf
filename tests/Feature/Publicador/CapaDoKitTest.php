<?php

namespace Tests\Feature\Publicador;

use App\Jobs\PlanejarKitCriativosJob;
use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlCategoriaSchema;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Creative\Contracts\ImageGenerationProvider;
use App\Services\Creative\CreativeContextBuilder;
use App\Services\Creative\CreativePlanner;
use App\Services\Creative\CreativeSlotCatalog;
use App\Services\Creative\Dto\CreativeContext;
use App\Services\Creative\Dto\CreativeGenerationRequest;
use App\Services\Creative\Dto\CreativeGenerationResult;
use App\Services\Creative\ProductTruthBuilder;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fase 175 Plano 175-06 (§5 da ETAPA-3) — a CAPA do kit no Creative Engine.
 *
 * ═══ O que este arquivo guarda ══════════════════════════════════════════════
 *
 * 1. **Nenhum kit existente muda de comportamento.** O terceiro parâmetro do
 *    `PlanejarKitCriativosJob` é `?array` com default `null`; sem ele o Job
 *    continua lendo `config('services.creative.kit.slots')`, e os kits de 7
 *    slots gravados em produção continuam sendo lidos como sempre.
 * 2. **A capa tem DOIS slots, EXATAMENTE `lifestyle` e `hero`** (decisão do
 *    usuário em 2026-10-08, `175-DECISOES.md` item 1: "gerar as duas e você
 *    escolhe") — não os dois que o planner escolheria sozinho pelo Truth.
 * 3. **O N vai ao prompt como FATO** (TRUTH-02/03): `contagens` com
 *    `origem: 'cadastro'`, lido de `pub_produtos.quantidade_kit`. Nunca frase
 *    solta, nunca mineração de título/descrição.
 * 4. **Zero chamada paga:** `Queue::fake()` + `Http::fake()` e provedor dublê.
 *
 * @group phase175
 */
class CapaDoKitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Queue::fake();
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    /** @return array{0: PubProduto, 1: PubProduto} [base, kit de N unidades] */
    private function familia(int $quantidade = 4): array
    {
        $company = Company::factory()->create();
        $empresa = MlbEmpresa::create(['nome' => 'Polo da Capa', 'projeto' => 'POLOS', 'company_id' => $company->id]);

        MlCategoriaSchema::create([
            'category_id' => 'MLB193945',
            'categoria' => [
                'id' => 'MLB193945', 'name' => 'Cadeiras de Escritório',
                'settings' => ['max_title_length' => 60],
                'path_from_root' => [['id' => 'MLB193945', 'name' => 'Cadeiras de Escritório']],
            ],
            'atributos' => [], 'technical_specs' => [], 'sale_terms' => [],
            'schema_hash' => str_repeat('a', 64), 'fetched_at' => now(),
        ]);

        $base = PubProduto::create([
            'mlb_empresa_id' => $empresa->id,
            'company_id' => $company->id,
            'sku' => 'CAD',
            'nome' => 'Cadeira Escritório',
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);
        $this->rascunho($base, 'Cadeira Escritório Executiva');

        $kit = PubProduto::create([
            'mlb_empresa_id' => $empresa->id,
            'company_id' => $company->id,
            'sku' => "CAD-KIT{$quantidade}",
            'nome' => "Kit {$quantidade} Cadeira Escritório",
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'produto_base_id' => $base->id,
            'quantidade_kit' => $quantidade,
            'fase' => 2,
        ]);
        $this->rascunho($kit, "Kit {$quantidade} Cadeira Escritório Executiva");

        return [$base->fresh(), $kit->fresh()];
    }

    private function rascunho(PubProduto $p, string $titulo, string $status = PubRascunho::DRAFT): PubRascunho
    {
        $r = PubRascunho::create([
            'produto_id' => $p->id,
            'status' => $status,
            'revisao' => 1,
            'categoria_id' => 'MLB193945',
            'condicao' => 'new',
            'descricao' => 'Cadeira executiva com apoio lombar.',
        ]);
        $r->alvos()->create(['listing_type_id' => 'gold_special', 'titulo' => $titulo, 'ativo' => true, 'posicao' => 0]);
        $v = $r->variantes()->create([
            'combinacao_chave' => ChaveCanonica::UNICA,
            'combinacao_hash' => ChaveCanonica::hash(ChaveCanonica::UNICA),
            'ativa' => true,
            'estoque' => 8,
        ]);
        $v->atributos()->create(['attribute_id' => 'SELLER_SKU', 'value_name' => $p->sku.'-UN']);

        return $r;
    }

    /** Um `CreativeContext` montado à mão, como o `CreativePlannerTest` da Fase 161 faz. */
    private function contexto(array $atributos = [], ?int $unidadesDoKit = null): CreativeContext
    {
        return new CreativeContext(
            rascunhoId: 0,
            produto: 'Kit 4 Cadeira Escritório',
            marca: $atributos['BRAND'] ?? null,
            modelo: null,
            categoriaId: 'MLB193945',
            descricao: null,
            atributos: $atributos,
            variacoes: ['quantidade' => 0, 'combinacoes' => []],
            loja: 'Loja Teste',
            imagensReferencia: [],
            referenciasMeta: [],
            pubRascunhoId: 7,
            unidadesDoKit: $unidadesDoKit,
        );
    }

    private function planner(string $resposta = ''): CreativePlanner
    {
        $provider = new class($resposta) implements ImageGenerationProvider
        {
            public function __construct(private string $resposta) {}

            public function gerarImagem(CreativeGenerationRequest $request): CreativeGenerationResult
            {
                throw new \RuntimeException('gerarImagem() não é usado pelo planejamento.');
            }

            public function gerarTexto(string $prompt): string
            {
                if ($this->resposta === '') {
                    throw new \RuntimeException('Provedor fora do ar (plano determinístico).');
                }

                return $this->resposta;
            }
        };

        return new CreativePlanner($provider, new CreativeSlotCatalog);
    }

    // ═══ Costura 1 e 2 — `unidadesDoKit` no contexto ═════════════════════════

    public function test_contexto_de_rascunho_de_kit_carrega_as_unidades_e_o_do_base_nao(): void
    {
        [$base, $kit] = $this->familia(4);

        $this->assertSame(4, $this->unidadesDoContexto($kit));
        $this->assertNull($this->unidadesDoContexto($base), 'o produto base (1 unidade) não tem contagem de kit');
    }

    /** Monta o contexto do Publicador pelo caminho real (`paraPublicador` via `paraCriativo`). */
    private function unidadesDoContexto(PubProduto $p): ?int
    {
        $metodo = new \ReflectionMethod(CreativeContextBuilder::class, 'paraPublicador');
        $metodo->setAccessible(true);

        $portador = \App\Models\MlAnuncioCriativo::create([
            'token' => \Illuminate\Support\Str::random(32),
            'company_id' => $p->company_id,
            'mlb_empresa_id' => $p->mlb_empresa_id,
            'rascunho_id' => null,
            'pub_rascunho_id' => $p->rascunho->id,
            'pub_grupo' => \App\Support\Publicador\Imagem\ResolvedorGruposImagem::GERAL,
            'slot' => 'hero',
            'status' => \App\Models\MlAnuncioCriativo::STATUS_PENDENTE,
        ]);

        /** @var CreativeContext $ctx */
        $ctx = $metodo->invoke(app(CreativeContextBuilder::class), $portador, $portador, $p->rascunho->id);

        return $ctx->unidadesDoKit;
    }

    public function test_unidades_nulas_nao_mudam_a_forma_serializada_do_ramo_antigo(): void
    {
        $semKit = $this->contexto(['COLOR' => 'Preto']);

        $auditoria = $semKit->paraAuditoria();
        $this->assertArrayNotHasKey('unidades_do_kit', $auditoria);
        $this->assertSame(
            ['rascunho_id', 'produto', 'marca', 'modelo', 'categoria_id', 'descricao', 'atributos', 'variacoes', 'loja', 'referencias_meta', 'pub_rascunho_id'],
            array_keys($auditoria),
        );
        $this->assertSame([], (new ProductTruthBuilder)->paraContexto($semKit)->contagens);
    }

    public function test_auditoria_registra_as_unidades_quando_existem(): void
    {
        $this->assertSame(4, $this->contexto([], 4)->paraAuditoria()['unidades_do_kit']);
    }

    // ═══ Costura 3 — o N como FATO de cadastro ═══════════════════════════════

    public function test_as_unidades_do_kit_entram_em_contagens_como_fato_de_cadastro(): void
    {
        $truth = (new ProductTruthBuilder)->paraContexto($this->contexto(['COLOR' => 'Preto'], 4));

        $this->assertSame(
            [['peca' => 'unidades idênticas do mesmo produto', 'quantidade' => '4', 'origem' => 'cadastro']],
            $truth->contagens,
        );
    }

    public function test_com_a_contagem_do_kit_a_claim_de_ausencia_de_contagem_nao_entra(): void
    {
        $comKit = (new ProductTruthBuilder)->paraContexto($this->contexto(['COLOR' => 'Preto'], 4));
        $semKit = (new ProductTruthBuilder)->paraContexto($this->contexto(['COLOR' => 'Preto']));

        $ausencia = fn (array $claims) => collect($claims)->contains(fn ($c) => str_contains($c, 'não há contagem confirmada no cadastro'));

        $this->assertFalse($ausencia($comKit->claimsProibidas), 'há contagem confirmada: a claim de ausência não pode aparecer');
        $this->assertTrue($ausencia($semKit->claimsProibidas), 'sem contagem, a proibição continua valendo (TRUTH-04)');
    }

    public function test_a_contagem_do_kit_nao_e_minerada_de_titulo_nem_de_descricao(): void
    {
        // Título com "Kit 4" e descrição com números, mas `unidadesDoKit` nulo:
        // nada entra em contagens — o número só vem do CADASTRO.
        $ctx = new CreativeContext(
            rascunhoId: 0,
            produto: 'Kit 4 Cadeira Escritório 5 Pés',
            marca: null, modelo: null, categoriaId: 'MLB193945',
            descricao: 'Este kit contém 4 unidades. Cada cadeira tem 5 pés.',
            atributos: [], variacoes: ['quantidade' => 0, 'combinacoes' => []],
            loja: null, imagensReferencia: [], referenciasMeta: [],
        );

        $this->assertSame([], (new ProductTruthBuilder)->paraContexto($ctx)->contagens);
    }

    // ═══ Costura 4 — o Job e os DOIS slots da capa ═══════════════════════════

    public function test_o_job_sem_o_terceiro_parametro_continua_lendo_a_config(): void
    {
        $job = new PlanejarKitCriativosJob(11, 22);

        $this->assertNull($job->tiposFixos, 'sem o parâmetro, a fonte da quantidade continua sendo a config');
        $this->assertSame('creative', $job->queue);
        $this->assertSame('kit-plano:22', $job->uniqueId());
    }

    public function test_o_terceiro_parametro_nao_muda_a_chave_de_unicidade(): void
    {
        $semFixos = new PlanejarKitCriativosJob(11, 22);
        $comFixos = new PlanejarKitCriativosJob(11, 22, ['lifestyle', 'hero']);

        $this->assertSame($semFixos->uniqueId(), $comFixos->uniqueId());
        $this->assertSame('creative', $comFixos->queue);
        $this->assertSame(['lifestyle', 'hero'], $comFixos->tiposFixos);
    }

    public function test_a_capa_planeja_exatamente_lifestyle_e_hero_em_qualquer_categoria(): void
    {
        $truth = (new ProductTruthBuilder)->paraContexto($this->contexto(['COLOR' => 'Preto', 'WIDTH' => '60 cm', 'HEIGHT' => '110 cm'], 4));

        foreach ([false, true] as $ehMoveis) {
            // Sem fixar nada, o planner escolheria `dimensions` como 2º slot (há medida
            // do produto no Truth) — é exatamente o que a capa do kit NÃO quer.
            $livre = $this->planner()->planejar($this->contexto(['WIDTH' => '60 cm'], 4), $truth, 2, $ehMoveis);
            $this->assertNotSame(['lifestyle', 'hero'], array_column(array_map(fn ($s) => (array) $s->paraPrompt(), $livre->slots), 'tipo'));

            $capa = $this->planner()->planejar($this->contexto(['WIDTH' => '60 cm'], 4), $truth, 7, $ehMoveis, ['lifestyle', 'hero']);

            $this->assertSame(['lifestyle', 'hero'], array_map(fn ($s) => $s->tipo, $capa->slots), 'a capa é sempre ambientada + fundo limpo');
            $this->assertSame([1, 2], array_map(fn ($s) => $s->indice, $capa->slots));
        }
    }

    public function test_tipos_fixos_resistem_ao_modelo_propondo_outra_coisa(): void
    {
        $truth = (new ProductTruthBuilder)->paraContexto($this->contexto(['COLOR' => 'Preto'], 4));
        $resposta = json_encode([
            'estrategia' => ['publico' => 'p', 'proposta_de_valor' => 'v', 'direcao_visual' => 'd'],
            'slots' => [
                ['tipo' => 'white_background', 'objetivo' => 'o', 'cena' => 'c'],
                ['tipo' => 'angles', 'objetivo' => 'o', 'cena' => 'c'],
                ['tipo' => 'hero', 'objetivo' => 'o', 'cena' => 'c'],
            ],
        ]);

        $plano = $this->planner($resposta)->planejar($this->contexto([], 4), $truth, 7, false, ['lifestyle', 'hero']);

        $this->assertSame(['lifestyle', 'hero'], array_map(fn ($s) => $s->tipo, $plano->slots));
    }

    public function test_sem_tipos_fixos_o_plano_antigo_de_7_slots_continua_igual(): void
    {
        $truth = (new ProductTruthBuilder)->paraContexto($this->contexto(['COLOR' => 'Preto']));

        $plano = $this->planner()->planejar($this->contexto(), $truth, 7, false);

        $this->assertCount(7, $plano->slots, 'os kits de 7 slots continuam sendo planejados como sempre');
        $this->assertSame('hero', $plano->slots[0]->tipo);
    }
}
