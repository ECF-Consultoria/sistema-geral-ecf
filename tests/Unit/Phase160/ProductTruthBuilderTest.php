<?php

namespace Tests\Unit\Phase160;

use App\Models\Company;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioRascunho;
use App\Services\Creative\CreativeContextBuilder;
use App\Services\Creative\Dto\CreativeContext;
use App\Services\Creative\ProductTruthBuilder;
use App\Services\Creative\ReferenciaEfemeraService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `ProductTruthBuilder` + `CreativeContextBuilder` — a fronteira única do
 * Creative Engine (CTX-02) e a prova de que contagem de peça nunca vem de
 * inferência (TRUTH-02). O caso do título "2 portas 3 gavetas" é o teste
 * MAIS IMPORTANTE deste plano: §18 das notas do spike — um número errado no
 * prompt é pior que número nenhum.
 *
 * A maioria dos casos monta `CreativeContext` à mão (sem banco); só os casos
 * de `CreativeContextBuilder` precisam de rascunho/criativo persistidos.
 */
class ProductTruthBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function contexto(array $atributos = [], string $produto = 'Produto de teste'): CreativeContext
    {
        return new CreativeContext(
            rascunhoId: 1,
            produto: $produto,
            marca: $atributos['BRAND'] ?? null,
            modelo: $atributos['MODEL'] ?? null,
            categoriaId: 'MLB1574',
            descricao: null,
            atributos: $atributos,
            variacoes: ['quantidade' => 0, 'combinacoes' => []],
            loja: 'Loja Teste',
            imagensReferencia: [],
            referenciasMeta: [['indice' => 0, 'mime' => 'image/jpeg', 'bytes' => 12345, 'nome' => 'foto.jpg']],
        );
    }

    // ═══ TRUTH-01 ════════════════════════════════════════════════════════

    public function test_atributo_com_value_name_entra_em_fatos_verificados(): void
    {
        $contexto = $this->contexto(['MATERIAL' => 'Aço carbono', 'COLOR' => 'Branco']);
        $truth    = (new ProductTruthBuilder)->paraContexto($contexto);

        $this->assertSame('Aço carbono', $truth->fatosVerificados['Material']);
        $this->assertSame('Branco', $truth->fatosVerificados['Cor']);
    }

    /**
     * Prova de que a filtragem de atributo vazio acontece ANTES deste DTO
     * chegar ao `ProductTruthBuilder` — ela é feita pelo `CreativeContextBuilder`
     * (CTX-02), que é o único lugar que lê o payload do rascunho.
     */
    public function test_atributo_sem_value_name_nao_chega_ao_product_truth_via_context_builder(): void
    {
        Storage::fake('local');

        [$criativo] = $this->criativoComReferencia([
            ['id' => 'MATERIAL', 'value_name' => 'Aço carbono'],
            ['id' => 'COLOR', 'value_name' => ''],
            ['id' => 'FINISH', 'value_name' => null],
        ]);

        $contexto = app(CreativeContextBuilder::class)->paraCriativo($criativo);
        $truth    = (new ProductTruthBuilder)->paraContexto($contexto);

        $this->assertSame(['Material' => 'Aço carbono'], $truth->fatosVerificados);
    }

    // ═══ TRUTH-02 — o teste mais importante do plano ════════════════════

    public function test_titulo_com_contagem_em_texto_livre_nao_produz_contagem(): void
    {
        $contexto = $this->contexto(
            atributos: ['MATERIAL' => 'MDF'], // nenhum atributo de contagem
            produto: 'Gabinete de cozinha 2 portas 3 gavetas',
        );

        $truth = (new ProductTruthBuilder)->paraContexto($contexto);

        $this->assertSame([], $truth->contagens);
    }

    public function test_atributo_de_contagem_explicito_entra_em_contagens_com_origem_cadastro(): void
    {
        $contexto = $this->contexto(['DOOR_QUANTITY' => '2']);
        $truth    = (new ProductTruthBuilder)->paraContexto($contexto);

        $this->assertSame([
            ['peca' => 'portas', 'quantidade' => '2', 'origem' => 'cadastro'],
        ], $truth->contagens);
    }

    public function test_atributo_fora_da_lista_fechada_nao_e_tratado_como_contagem_de_peca(): void
    {
        // "2" aparece no valor de um atributo qualquer, fora da lista fechada
        // de ids de contagem — não deve virar contagem por estar perto de um número.
        $contexto = $this->contexto(['SOME_OTHER_FIELD' => '2']);
        $truth    = (new ProductTruthBuilder)->paraContexto($contexto);

        $this->assertSame([], $truth->contagens);
    }

    // ═══ TRUTH-04 ════════════════════════════════════════════════════════

    public function test_claims_proibidas_nunca_vazio_e_acrescenta_derivadas_de_ausencia(): void
    {
        $contexto = $this->contexto([]); // sem cor, sem contagem
        $truth    = (new ProductTruthBuilder)->paraContexto($contexto);

        $this->assertNotEmpty($truth->claimsProibidas);
        $this->assertGreaterThanOrEqual(8, count($truth->claimsProibidas));
        $this->assertTrue(
            collect($truth->claimsProibidas)->contains(fn ($c) => str_contains($c, 'cor'))
        );
        $this->assertTrue(
            collect($truth->claimsProibidas)->contains(fn ($c) => str_contains($c, 'número algum'))
        );
    }

    public function test_claims_proibidas_nao_duplica_derivada_quando_fato_existe(): void
    {
        $contexto = $this->contexto(['COLOR' => 'Branco', 'DOOR_QUANTITY' => '2']);
        $truth    = (new ProductTruthBuilder)->paraContexto($contexto);

        // Só a lista fixa — nem derivada de cor nem de contagem.
        $this->assertCount(8, $truth->claimsProibidas);
    }

    // ═══ paraAuditoria() nunca tem bytes/base64 ═════════════════════════

    public function test_para_auditoria_nunca_contem_bytes_nem_base64(): void
    {
        $contexto = $this->contexto(['MATERIAL' => 'MDF']);
        $truth    = (new ProductTruthBuilder)->paraContexto($contexto);

        $json = json_encode($truth->paraAuditoria());

        $this->assertDoesNotMatchRegularExpression('/[A-Za-z0-9+\/]{200,}={0,2}/', $json);
        $this->assertArrayNotHasKey('imagensReferencia', $truth->paraAuditoria());
    }

    // ═══ CreativeContextBuilder — CTX-03 e exceção pt-BR ════════════════

    public function test_context_builder_devolve_imagens_referencia_com_bytes(): void
    {
        Storage::fake('local');

        [$criativo] = $this->criativoComReferencia([]);

        $contexto = app(CreativeContextBuilder::class)->paraCriativo($criativo);

        $this->assertNotEmpty($contexto->imagensReferencia);
        $this->assertArrayHasKey('mime', $contexto->imagensReferencia[0]);
        $this->assertArrayHasKey('bytes', $contexto->imagensReferencia[0]);
        $this->assertIsString($contexto->imagensReferencia[0]['bytes']);
    }

    public function test_context_builder_lanca_excecao_pt_br_sem_referencia_viva(): void
    {
        $company = Company::factory()->create(['name' => 'Empresa Teste']);

        $rascunho = MlAnuncioRascunho::create([
            'company_id'  => $company->id,
            'category_id' => 'MLB1574',
            'payload'     => ['title' => 'Produto sem foto', 'attributes' => []],
            'status'      => MlAnuncioRascunho::STATUS_RASCUNHO,
            'user_id'     => \App\Models\User::factory()->create()->id,
        ]);

        $criativo = MlAnuncioCriativo::create([
            'token'       => Str::random(32),
            'company_id'  => $company->id,
            'rascunho_id' => $rascunho->id,
            'user_id'     => $rascunho->user_id,
            'slot'        => 'hero',
            'status'      => MlAnuncioCriativo::STATUS_PENDENTE,
            'referencias' => [],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/refer.ncia/ui');

        app(CreativeContextBuilder::class)->paraCriativo($criativo);
    }

    /**
     * @param  array<int, array{id: string, value_name: mixed}>  $attrs
     * @return array{0: MlAnuncioCriativo}
     */
    private function criativoComReferencia(array $attrs): array
    {
        $company = Company::factory()->create(['name' => 'Empresa Teste']);

        $rascunho = MlAnuncioRascunho::create([
            'company_id'  => $company->id,
            'category_id' => 'MLB1574',
            'payload'     => [
                'title'       => 'Gabinete de cozinha',
                'category_id' => 'MLB1574',
                'description' => 'Descrição qualquer.',
                'attributes'  => $attrs,
            ],
            'status'  => MlAnuncioRascunho::STATUS_RASCUNHO,
            'user_id' => \App\Models\User::factory()->create()->id,
        ]);

        $criativo = MlAnuncioCriativo::create([
            'token'       => Str::random(32),
            'company_id'  => $company->id,
            'rascunho_id' => $rascunho->id,
            'user_id'     => $rascunho->user_id,
            'slot'        => 'hero',
            'status'      => MlAnuncioCriativo::STATUS_PENDENTE,
        ]);

        $referencias = app(ReferenciaEfemeraService::class)->guardar($criativo, [UploadedFile::fake()->image('gabinete.jpg')]);
        $criativo->update(['referencias' => $referencias]);

        return [$criativo];
    }
}
