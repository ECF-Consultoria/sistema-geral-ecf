<?php

namespace Tests\Feature\Publicador;

use App\Models\MlAnuncioIaAnalise;
use App\Models\MlbEmpresa;
use App\Models\MlCategoriaSchema;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAtributo;
use App\Models\User;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\IaParaRascunhoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/**
 * 09/10/2026 — o "Anunciar por IA" grava NÚMERO no formato do editor (learnings publicador-ml §14).
 *
 * A IA devolve `value_number`/`value_unit`; gravado assim, sem `value_name`, o campo aparecia VAZIO
 * no editor (`CampoAtributo` só lê `value_name`) — o mesmo sintoma que o Sincronizar teve com o
 * Puff Redondo (`fbfc9264`). Agora vai como texto: "120 kg", "1", "0.5 m". Zero rede.
 */
class IaNumeroNoFormatoDoEditorTest extends TestCase
{
    use CarregaSchemas;
    use RefreshDatabase;

    private PubProduto $produto;

    private MlbEmpresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        Http::fake();

        $schema = self::schema(self::CADEIRA);
        MlCategoriaSchema::create(['category_id' => self::CADEIRA, 'domain_id' => $schema->dominio(), 'categoria' => $schema->categoria,
            'atributos' => $schema->atributos, 'technical_specs' => $schema->technicalSpecs, 'sale_terms' => $schema->saleTerms,
            'schema_hash' => $schema->hash(), 'fetched_at' => now()]);

        $this->empresa = MlbEmpresa::create(['nome' => 'Polo IA', 'projeto' => 'POLOS'])->fresh();
        $this->produto = PubProduto::create(['mlb_empresa_id' => $this->empresa->id, 'sku' => 'CAD-01', 'nome' => 'Cadeira', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
    }

    private function aplicar(array $atributos): PubRascunho
    {
        $r = app(EditorRascunhoService::class)->abrir($this->produto);
        $ficha = ['category_id' => self::CADEIRA, 'titulo' => 'Cadeira Escritório Executiva', 'atributos' => $atributos, 'variacoes' => []];
        $a = MlAnuncioIaAnalise::create([
            'mlb_empresa_id' => $this->empresa->id, 'user_id' => User::factory()->create(['role' => 'admin'])->id,
            'produto' => 'Cadeira', 'loja' => 'Polo IA', 'status' => MlAnuncioIaAnalise::STATUS_RODANDO,
            'resultado' => [
                'destino' => ['tipo' => 'publicador', 'produto_id' => $this->produto->id, 'rascunho_id' => $r->id, 'revisao_base' => (int) $r->revisao, 'substituir' => false],
                'ficha' => $ficha,
            ],
        ]);
        app(IaParaRascunhoService::class)->aplicar($a, $a->resultado);

        return $r->fresh();
    }

    private function linha(PubRascunho $r, string $id): PubRascunhoAtributo
    {
        return PubRascunhoAtributo::where('rascunho_id', $r->id)->where('attribute_id', $id)->firstOrFail();
    }

    public function test_numero_da_ia_vai_como_texto_em_value_name(): void
    {
        $r = $this->aplicar([
            ['id' => 'MAX_WEIGHT_SUPPORTED', 'value_number' => 120, 'value_unit' => 'kg'],
            ['id' => 'BACKREST_HEIGHT', 'value_number' => 0.5, 'value_unit' => 'm'],
            ['id' => 'SEAT_DEPTH', 'value_number' => 45.0],
            ['id' => 'UNITS_PER_PACK', 'value_number' => 1],
        ]);

        foreach (['MAX_WEIGHT_SUPPORTED' => '120 kg', 'BACKREST_HEIGHT' => '0.5 m', 'SEAT_DEPTH' => '45 cm', 'UNITS_PER_PACK' => '1'] as $id => $texto) {
            $l = $this->linha($r, $id);
            $this->assertSame($texto, $l->value_name, "{$id} aparece no editor");
            $this->assertNull($l->value_number, "{$id} sem número solto em value_number");
            $this->assertSame('ia', $l->origem);
        }
    }

    public function test_unidade_de_outra_grandeza_converte_e_texto_da_ia_vence_quando_nao_e_numero(): void
    {
        $r = $this->aplicar([
            // 500 mm na cadeira: mm é aceita (fica como veio).
            ['id' => 'OFFICE_CHAIR_WIDTH', 'value_number' => 500, 'value_unit' => 'mm'],
            // Peso em gramas num campo em kg: converte para a unidade aceita.
            ['id' => 'MAX_WEIGHT_SUPPORTED', 'value_number' => 90000, 'value_unit' => 'g'],
            ['id' => 'BRAND', 'value_name' => 'ECF'],
        ]);

        $this->assertSame('500 mm', $this->linha($r, 'OFFICE_CHAIR_WIDTH')->value_name);
        $this->assertSame('90 kg', $this->linha($r, 'MAX_WEIGHT_SUPPORTED')->value_name);
        $this->assertSame('ECF', $this->linha($r, 'BRAND')->value_name);
    }
}
