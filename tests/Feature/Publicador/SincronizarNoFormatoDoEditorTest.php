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
use App\Models\PubRascunhoAtributo;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDoProduto;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\PortalParaRascunhoService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\Schema\ClassificadorAtributos;
use App\Support\Publicador\Schema\ContextoClassificacao;
use App\Support\Publicador\Schema\SchemaClassificado;
use App\Support\Publicador\Schema\ValorAtributo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/**
 * 09/10/2026 — o Sincronizar grava cada tipo de atributo no MESMO formato que o editor grava.
 *
 * O caso real (#459, rascunho 9, Puff Redondo): o Portal tinha "Quantidade de pés" 3, "Quantidade de
 * puffs" 1 e "Peso máximo suportado" 60 kg; o Sincronizar gravou `value_number = 3.0000` com
 * `value_name` NULO, mas o editor (`CampoAtributo`) só lê `value_name` ("60 kg", "3") — os três campos
 * apareceram VAZIOS. O payload lia `value_number` e publicaria certo; a tela é que não mostrava.
 *
 * Cada tipo é provado duas vezes: "o editor mostra" (o que `estado()` devolve, lido como a tela lê) e
 * "o payload leva" (o atributo que vai ao ML). Categoria real guardada (MLB193945) + LEGS_NUMBER, que é
 * do pufe e não existe na cadeira. Zero HTTP.
 */
class SincronizarNoFormatoDoEditorTest extends TestCase
{
    use CarregaSchemas;
    use RefreshDatabase;

    private Company $empresa;

    private RascunhoRepository $repo;

    private PortalParaRascunhoService $servico;

    private SchemaClassificado $classificado;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake();

        $schema = self::schema(self::CADEIRA, function (array $f) {
            $f['atributos'][] = ['id' => 'LEGS_NUMBER', 'name' => 'Quantidade de pés', 'value_type' => 'number',
                'tags' => [], 'attribute_group_id' => 'OTHERS', 'attribute_group_name' => 'Outros'];

            return $f;
        });
        MlCategoriaSchema::create(['category_id' => self::CADEIRA, 'domain_id' => $schema->dominio(), 'categoria' => $schema->categoria,
            'atributos' => $schema->atributos, 'technical_specs' => $schema->technicalSpecs, 'sale_terms' => $schema->saleTerms,
            'schema_hash' => $schema->hash(), 'fetched_at' => now()]);
        $this->classificado = (new ClassificadorAtributos())->classificar($schema, new ContextoClassificacao('new', ['COLOR']));

        $this->empresa = Company::factory()->create();
        $this->repo = new RascunhoRepository();
        $this->servico = app(PortalParaRascunhoService::class);
    }

    // ═══ Ajudantes ═══════════════════════════════════════════════════════════

    /** @return array{0: EstruturaProduto, 1: PubProduto} */
    private function puff(): array
    {
        $p = EstruturaProduto::create(['company_id' => $this->empresa->id, 'codigo' => 'PUFF', 'nome' => 'Puff Redondo',
            'categoria_ml_id' => self::CADEIRA, 'categoria_ml_nome' => 'Categoria']);
        $v = EstruturaProdutoVariacao::create(['produto_id' => $p->id, 'company_id' => $this->empresa->id, 'ordem' => 0,
            'codigo' => 'PUFF-1', 'eixo' => 'cor', 'valor' => 'Bege', 'custo' => 10, 'estoque' => 4]);
        // O pacote do Puff na #459: 15 × 22 × 23 cm, 8 kg.
        EstruturaProdutoVolume::create(['variacao_id' => $v->id, 'ordem' => 0, 'comprimento' => 15, 'largura' => 22, 'altura' => 23, 'peso' => 8]);
        EstruturaOferta::create(['company_id' => $this->empresa->id, 'variacao_id' => $v->id, 'sku' => 'PUFF-1', 'fase' => 'simples', 'nome' => 'Puff Bege']);
        $pub = PubProduto::create(['company_id' => $this->empresa->id, 'estrutura_produto_id' => $p->id, 'sku' => 'PUFF', 'nome' => 'Puff Redondo',
            'origem' => PubProduto::ORIGEM_PORTAL]);

        return [$p, $pub];
    }

    private function noPortal(EstruturaProduto $p, string $id, ?string $valor, ?string $valorId = null, ?string $unidade = null): void
    {
        EstruturaProdutoAtributo::updateOrCreate(['company_id' => $this->empresa->id, 'produto_id' => $p->id, 'atributo_id' => $id],
            ['atributo_nome' => $id, 'valor' => $valor, 'valor_id' => $valorId, 'unidade' => $unidade]);
    }

    /** Os três campos que apareceram vazios no rascunho 9. */
    private function numerosDoPuff(EstruturaProduto $p): void
    {
        $this->noPortal($p, 'LEGS_NUMBER', '3');
        $this->noPortal($p, 'UNITS_PER_PACK', '1');
        $this->noPortal($p, 'MAX_WEIGHT_SUPPORTED', '60', null, 'kg');
    }

    private function rascunho(PubProduto $pub): PubRascunho
    {
        return PubRascunho::where('produto_id', $pub->id)->firstOrFail();
    }

    private function linha(PubProduto $pub, string $id): PubRascunhoAtributo
    {
        return PubRascunhoAtributo::where('rascunho_id', $this->rascunho($pub)->id)->where('attribute_id', $id)->firstOrFail();
    }

    /**
     * O que a TELA mostra no campo, lendo como `CampoAtributo.jsx` lê:
     * N/A → "Não se aplica"; lista fechada/Sim-Não → o nome da opção do `value_id`;
     * `number_unit` → o número e a unidade tirados de `value_name`; o resto → `value_name`.
     */
    private static function oQueOEditorMostra(array $a, ?array $v): string
    {
        $v ??= [];
        if (($v['value_id'] ?? null) === '-1') {
            return 'Não se aplica';
        }
        if (in_array($a['tipo'], ['list', 'boolean'], true) && $a['valores'] !== [] && ! $a['texto_livre']) {
            foreach ($a['valores'] as $x) {
                if ((string) $x['id'] === (string) ($v['value_id'] ?? '')) {
                    return (string) $x['name'];
                }
            }

            return '';
        }
        if ($a['tipo'] === 'number_unit') {
            if (! preg_match('/^\s*(\d+(?:[.,]\d+)?)\s*(.*)$/', (string) ($v['value_name'] ?? ''), $m)) {
                return '';
            }

            return trim($m[1].' '.($m[2] !== '' ? $m[2] : ''));
        }

        return (string) ($v['value_name'] ?? '');
    }

    /** @return array<string, string> atributo → o que a tela mostra */
    private function editorMostra(PubProduto $pub, array $ids): array
    {
        $estado = app(EditorRascunhoService::class)->estado($this->rascunho($pub));
        $saida = [];
        foreach ($ids as $id) {
            $this->assertArrayHasKey($id, $estado['schema']['atributos'], "{$id} está no schema da tela");
            $saida[$id] = self::oQueOEditorMostra($estado['schema']['atributos'][$id], $estado['atributos'][$id] ?? null);
        }

        return $saida;
    }

    /** @return array<string, ?array> atributo → como vai ao ML (o mesmo `paraPayload` do montador do payload) */
    private function payloadLeva(PubProduto $pub, array $ids): array
    {
        $atributos = $this->repo->snapshot($this->rascunho($pub))->atributos;
        $saida = [];
        foreach ($ids as $id) {
            $saida[$id] = isset($atributos[$id]) ? ValorAtributo::paraPayload($this->classificado->atributo($id), $atributos[$id]) : null;
        }

        return $saida;
    }

    // ═══ Número ══════════════════════════════════════════════════════════════

    public function test_o_caso_do_puff_numero_chega_como_texto_que_o_editor_mostra_e_o_payload_leva(): void
    {
        [$p, $pub] = $this->puff();
        $this->numerosDoPuff($p);
        $this->noPortal($p, 'SEAT_WIDTH', '48.5', null, 'cm');
        $this->noPortal($p, 'OFFICE_CHAIR_WEIGHT', '2.5', null, 'kg');

        $this->servico->preencher($pub);

        // As colunas: exatamente o formato do editor (`value_name` com o texto, número e unidade vazios).
        foreach (['LEGS_NUMBER' => '3', 'UNITS_PER_PACK' => '1', 'MAX_WEIGHT_SUPPORTED' => '60 kg', 'SEAT_WIDTH' => '48.5 cm', 'OFFICE_CHAIR_WEIGHT' => '2.5 kg'] as $id => $texto) {
            $l = $this->linha($pub, $id);
            $this->assertSame($texto, $l->value_name, $id);
            $this->assertNull($l->value_number, $id);
            $this->assertNull($l->value_unit, $id);
            $this->assertNull($l->value_id, $id);
            $this->assertSame('portal', $l->origem, $id);
        }

        $ids = ['LEGS_NUMBER', 'UNITS_PER_PACK', 'MAX_WEIGHT_SUPPORTED', 'SEAT_WIDTH', 'OFFICE_CHAIR_WEIGHT'];
        $this->assertSame(['LEGS_NUMBER' => '3', 'UNITS_PER_PACK' => '1', 'MAX_WEIGHT_SUPPORTED' => '60 kg', 'SEAT_WIDTH' => '48.5 cm', 'OFFICE_CHAIR_WEIGHT' => '2.5 kg'],
            $this->editorMostra($pub, $ids));
        $this->assertSame([
            'LEGS_NUMBER' => ['id' => 'LEGS_NUMBER', 'value_name' => '3'],
            'UNITS_PER_PACK' => ['id' => 'UNITS_PER_PACK', 'value_name' => '1'],
            'MAX_WEIGHT_SUPPORTED' => ['id' => 'MAX_WEIGHT_SUPPORTED', 'value_name' => '60 kg'],
            'SEAT_WIDTH' => ['id' => 'SEAT_WIDTH', 'value_name' => '48.5 cm'],
            'OFFICE_CHAIR_WEIGHT' => ['id' => 'OFFICE_CHAIR_WEIGHT', 'value_name' => '2.5 kg'],
        ], $this->payloadLeva($pub, $ids));
        Http::assertNothingSent();
    }

    public function test_pacote_continua_no_formato_do_editor(): void
    {
        [, $pub] = $this->puff();
        $this->servico->preencher($pub);

        $ids = ['SELLER_PACKAGE_LENGTH', 'SELLER_PACKAGE_WIDTH', 'SELLER_PACKAGE_HEIGHT', 'SELLER_PACKAGE_WEIGHT'];
        $this->assertSame(['SELLER_PACKAGE_LENGTH' => '15 cm', 'SELLER_PACKAGE_WIDTH' => '22 cm', 'SELLER_PACKAGE_HEIGHT' => '23 cm', 'SELLER_PACKAGE_WEIGHT' => '8000 g'],
            $this->editorMostra($pub, $ids));
        foreach ($ids as $id) {
            $this->assertNull($this->linha($pub, $id)->value_number, $id);
        }
        $this->assertSame('8000 g', $this->payloadLeva($pub, $ids)['SELLER_PACKAGE_WEIGHT']['value_name']);
    }

    // ═══ Conserto dos rascunhos gravados no formato velho ════════════════════

    public function test_linha_do_portal_no_formato_velho_e_reescrita_mesmo_com_o_mesmo_numero_e_a_da_equipe_fica(): void
    {
        [$p, $pub] = $this->puff();
        $this->numerosDoPuff($p);
        $this->noPortal($p, 'SEAT_WIDTH', '48.5', null, 'cm');
        $this->noPortal($p, 'OFFICE_CHAIR_WEIGHT', '2.5', null, 'kg');
        $this->servico->preencher($pub);

        // O rascunho 9 como estava em produção: o que o Sincronizar velho gravou (`value_number`, nome nulo)…
        $this->repo->mesclarAtributos($this->rascunho($pub), [
            'LEGS_NUMBER' => ['value_number' => 3.0, 'origem' => 'portal'],
            'UNITS_PER_PACK' => ['value_number' => 1.0, 'origem' => 'portal'],
            'MAX_WEIGHT_SUPPORTED' => ['value_number' => 60.0, 'value_unit' => 'kg', 'origem' => 'portal'],
            // …e linhas que NÃO são do Portal, também com número em `value_number`: ninguém mexe.
            'SEAT_WIDTH' => ['value_number' => 40.0, 'value_unit' => 'cm', 'origem' => 'user'],
            'OFFICE_CHAIR_WEIGHT' => ['value_number' => 9.0, 'value_unit' => 'kg', 'origem' => 'ia'],
        ]);
        $velho = $this->linha($pub, 'MAX_WEIGHT_SUPPORTED');
        $this->assertNull($velho->value_name);
        $this->assertSame(60.0, $velho->value_number);
        $this->assertSame('', $this->editorMostra($pub, ['MAX_WEIGHT_SUPPORTED'])['MAX_WEIGHT_SUPPORTED'], 'o defeito: a tela mostrava vazio');

        $resumo = $this->servico->preencher($pub);

        $this->assertSame(3, $resumo['campos_atualizados'], 'as 3 linhas velhas do Portal contam como atualizadas');
        $this->assertSame(['LEGS_NUMBER' => '3', 'UNITS_PER_PACK' => '1', 'MAX_WEIGHT_SUPPORTED' => '60 kg'],
            $this->editorMostra($pub, ['LEGS_NUMBER', 'UNITS_PER_PACK', 'MAX_WEIGHT_SUPPORTED']));
        foreach (['LEGS_NUMBER', 'UNITS_PER_PACK', 'MAX_WEIGHT_SUPPORTED'] as $id) {
            $l = $this->linha($pub, $id);
            $this->assertNull($l->value_number, $id);
            $this->assertNull($l->value_unit, $id);
            $this->assertSame('portal', $l->origem, $id);
        }

        foreach (['SEAT_WIDTH' => [40.0, 'cm', 'user'], 'OFFICE_CHAIR_WEIGHT' => [9.0, 'kg', 'ia']] as $id => [$numero, $unidade, $origem]) {
            $l = $this->linha($pub, $id);
            $this->assertSame($numero, $l->value_number, "{$id} ({$origem}) não é tocado");
            $this->assertSame($unidade, $l->value_unit, $id);
            $this->assertNull($l->value_name, $id);
            $this->assertSame($origem, $l->origem, $id);
        }

        // Consertado uma vez, a próxima rodada não muda mais nada.
        $revisao = $this->rascunho($pub)->revisao;
        $resumo = $this->servico->preencher($pub);
        $this->assertSame(0, $resumo['campos_atualizados']);
        $this->assertSame($revisao, $this->rascunho($pub)->revisao);
    }

    // ═══ Os outros tipos ═════════════════════════════════════════════════════

    public function test_cada_tipo_gravado_pelo_sincronizar_aparece_no_editor_e_vai_no_payload(): void
    {
        [$p, $pub] = $this->puff();
        $this->noPortal($p, 'BRAND', 'ECF');                                                    // texto
        $this->noPortal($p, 'IS_KIT', 'Não');                                                   // Sim/Não
        $this->noPortal($p, 'LUMBAR_SUPPORT_TYPE', 'Regulável', '10201909');                    // lista pelo id
        $this->noPortal($p, 'WITH_LIGHTS', null, FichaTecnicaDoProduto::NAO_SE_APLICA);         // N/A
        $this->noPortal($p, 'STRUCTURE_MATERIALS', 'Ferro'.FichaTecnicaDoProduto::SEPARADOR.'Madeira'); // várias opções

        $this->servico->preencher($pub);

        $ids = ['BRAND', 'IS_KIT', 'LUMBAR_SUPPORT_TYPE', 'WITH_LIGHTS', 'STRUCTURE_MATERIALS'];
        $this->assertSame([
            'BRAND' => 'ECF',
            'IS_KIT' => 'Não',
            'LUMBAR_SUPPORT_TYPE' => 'Regulável',
            'WITH_LIGHTS' => 'Não se aplica',
            'STRUCTURE_MATERIALS' => 'Ferro', // o editor mostra a 1ª opção (D-13)
        ], $this->editorMostra($pub, $ids));

        $this->assertSame([
            'BRAND' => ['id' => 'BRAND', 'value_name' => 'ECF'],
            'IS_KIT' => ['id' => 'IS_KIT', 'value_id' => '242084'],
            'LUMBAR_SUPPORT_TYPE' => ['id' => 'LUMBAR_SUPPORT_TYPE', 'value_id' => '10201909'],
            'WITH_LIGHTS' => ['id' => 'WITH_LIGHTS', 'value_id' => '-1', 'value_name' => null],
            // D-13: o Publicador não envia multivalor — vai a 1ª opção; as outras ficam em `values_multi` e o campo pede revisão.
            'STRUCTURE_MATERIALS' => ['id' => 'STRUCTURE_MATERIALS', 'value_id' => '2431883'],
        ], $this->payloadLeva($pub, $ids));

        $multi = $this->repo->snapshot($this->rascunho($pub))->atributos['STRUCTURE_MATERIALS'];
        $this->assertSame(['2431883', '2431881'], $multi['values_multi']);
        $this->assertTrue($multi['revisar']);
        Http::assertNothingSent();
    }
}
