<?php

namespace Tests\Feature\Publicador;

use App\Jobs\Publicador\ConferirRascunhoJob;
use App\Models\PubPublicacao;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Publicador\ConferenciaService;
use App\Services\Publicador\Fila\ConferenciaEmLoteService;
use App\Services\Publicador\PadroesDoRascunhoService;
use App\Support\Publicador\EditorEmUso;
use App\Support\Publicador\PadroesDoEditor;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;
use Tests\TestCase;

/**
 * N9 — "abrir o editor grava" (medido no navegador em 21 e 22/10/2026): a 1ª abertura disparava PUT das variantes
 * e do rascunho (EAN gerado, Cor principal, fotos por variação, frete grátis obrigatório) sem ninguém tocar em
 * nada — a conferência virava "Editado", a revisão subia e o `EditorEmUso` marcava "editando" (o preparo da IA se
 * adiava 5 min).
 *
 * O conserto: os MESMOS padrões no servidor ANTES da 1ª conferência (`PadroesDoRascunhoService`). Aqui se prova o
 * lado do servidor; o lado da tela (os efeitos não gravam com o estado completo) está em
 * `tests/js/publicador-padroes-do-editor.test.js`, e a paridade da regra em `PadroesDoEditorTest`.
 */
class AbrirOEditorNaoGravaTest extends TestCase
{
    use CenarioCadeira;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        // 150 reais na conta #459: o ML exige o frete grátis do vendedor (resposta real de 01/10).
        $this->fakeMl(['*/shipping_options/free*' => Http::response(self::fixture('conta/shipping_options_free_150'))]);
        Cache::flush();

        // O rascunho como o Portal o deixa: sem código universal e sem o frete grátis marcado; a conta já lida.
        $this->variante(['SELLER_SKU' => ['value_name' => 'CAD-01']]);
        $this->r->update([
            'envio' => ['modo' => 'me2', 'frete_gratis' => false, 'retirada' => false],
            'step_state' => ['conta' => ['sellerId' => '1555596317', 'modelo' => 'UP', 'tags' => [], 'modosEnvio' => ['me2', 'custom', 'not_specified']]],
            'conta_checada_em' => now(),
        ]);
        $this->r = $this->r->fresh();
    }

    private function padroes(): PadroesDoRascunhoService
    {
        return app(PadroesDoRascunhoService::class);
    }

    private function gtin(): ?string
    {
        return $this->repo->snapshot($this->r->fresh())->variantes[0]->dados['atributos']['GTIN']['value_name'] ?? null;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_o_servidor_preenche_o_que_falta_uma_vez_so(): void
    {
        $antes = $this->r->revisao;

        $aplicados = $this->padroes()->aplicar($this->r, comFrete: true);

        $this->assertSame(['GTIN:'.ChaveCanonica::UNICA, 'envio.frete_gratis'], $aplicados);
        $this->assertTrue(PadroesDoEditor::eanValido($this->gtin()));
        $this->assertStringStartsWith('789', $this->gtin());
        $this->assertTrue($this->r->fresh()->envio['frete_gratis']);
        $this->assertSame('CAD-01', $this->repo->snapshot($this->r->fresh())->variantes[0]->dados['atributos']['SELLER_SKU']['value_name'], 'o resto da variação fica');
        $this->assertSame($antes + 2, $this->r->fresh()->revisao, 'uma revisão para os padrões sem HTTP, outra para o frete');

        // Rodar de novo (outro Sincronizar, outra conferência): nada falta, nada muda — nem a revisão.
        $gtin = $this->gtin();
        $this->assertSame([], $this->padroes()->aplicar($this->r, comFrete: true));
        $this->assertSame($antes + 2, $this->r->fresh()->revisao);
        $this->assertSame($gtin, $this->gtin(), 'o código gerado não é trocado');
    }

    public function test_sem_ir_ao_ml_o_frete_fica_para_quem_pode_perguntar(): void
    {
        $this->assertSame(['GTIN:'.ChaveCanonica::UNICA], $this->padroes()->aplicar($this->r));
        $this->assertFalse($this->r->fresh()->envio['frete_gratis']);
        $this->assertSame(0, $this->chamadas('/shipping_options/free'), 'o Sincronizar do Portal não vai ao ML (D-10)');
    }

    public function test_sem_a_conta_lida_o_preparo_le_a_conta_como_o_abrir_e_entao_liga_o_frete(): void
    {
        // O rascunho que ninguém abriu ainda não sabe o vendedor: sem ele não há como perguntar o frete.
        $this->r->update(['step_state' => [], 'conta_checada_em' => null]);
        $this->assertFalse($this->padroes()->garantirFreteObrigatorio($this->r->fresh()));
        $this->assertSame(0, $this->chamadas('/shipping_options/free'));

        app(\App\Services\Publicador\EditorRascunhoService::class)->lerContaParaOsPadroes($this->r->fresh());

        $this->assertSame('1555596317', $this->r->fresh()->step_state['conta']['sellerId'] ?? null);
        $this->assertTrue($this->padroes()->garantirFreteObrigatorio($this->r->fresh()));
        $this->assertTrue($this->r->fresh()->envio['frete_gratis']);
    }

    /**
     * 23/10/2026 (teste do Publicador, P-17, D26): conta fora da lista de liberadas não tem chamada ao ML — o preparo e
     * a conferência cotavam o frete com o token do cliente sem ninguém abrir nada.
     */
    public function test_conta_nao_liberada_nao_cota_o_frete_gratis(): void
    {
        config(['publicador.contas_liberadas' => ['companies' => [], 'mlb_empresas' => []]]);

        $this->assertFalse($this->padroes()->garantirFreteObrigatorio($this->r->fresh()));
        $this->assertSame(0, $this->chamadas('/shipping_options/free'), 'nada vai ao ML');
        $this->assertFalse((bool) ($this->r->fresh()->envio['frete_gratis'] ?? false));
    }

    public function test_conferencia_do_editor_aplica_os_padroes_antes_e_vale_para_o_payload_que_o_ml_viu(): void
    {
        // A categoria pede o GTIN nesta revisão: sem o padrão, a conferência bloquearia (V-ATT-08).
        $this->condicionais = ['GTIN'];

        (new ConferirRascunhoJob($this->r->id))->handle(app(ConferenciaService::class));

        $r = $this->r->fresh();
        $v = $r->validacoes()->latest('id')->first();
        $this->assertSame(ConferenciaService::OK, $v->resultado, json_encode($v->issues));
        $this->assertSame($r->revisao, $v->revisao, 'a conferência é da revisão COM os padrões: vale');
        $payload = $v->respostas_ml['itens'][0]['payload'];
        $this->assertContains($this->gtin(), collect($payload['attributes'] ?? [])->where('id', 'GTIN')->pluck('value_name')->all()
            ?: collect($payload['variations'][0]['attributes'] ?? [])->where('id', 'GTIN')->pluck('value_name')->all(), 'o ML conferiu com o código');
        $this->assertTrue($r->envio['frete_gratis']);
    }

    public function test_abrir_o_editor_de_rascunho_com_os_padroes_nao_grava_nem_marca_editando(): void
    {
        $this->padroes()->aplicar($this->r, comFrete: true);
        app(ConferenciaService::class)->conferir($this->r->fresh());
        $revisao = $this->r->fresh()->revisao;
        Cache::forget(EditorEmUso::chaveDaEdicao((int) $this->produto->id));

        $estado = $this->actingAs($this->admin())
            ->getJson(route('mlb.anuncios.publicador.abrir', $this->produto->id))->assertOk()->json();
        $this->actingAs($this->admin())->getJson(route('mlb.anuncios.publicador.frete', $this->produto->id))->assertOk()
            ->assertJsonPath('frete_gratis.obrigatorio', true);

        $this->assertSame($revisao, $this->r->fresh()->revisao, 'abrir e perguntar o frete não sobem a revisão');
        $this->assertTrue($estado['conferencia']['vale'], 'a conferência não vira "Editado" por abrir');
        $this->assertFalse(EditorEmUso::editando((int) $this->produto->id), 'quem só abre não adia o preparo da IA');
        // O que a tela leria: nada faltando para os efeitos gravarem.
        $this->assertNotEmpty($estado['variantes'][0]['atributos']['GTIN']['value_name']);
        $this->assertTrue($estado['rascunho']['envio']['frete_gratis']);
    }

    public function test_quem_edita_de_verdade_sobe_a_revisao_como_antes(): void
    {
        $this->padroes()->aplicar($this->r, comFrete: true);
        app(ConferenciaService::class)->conferir($this->r->fresh());
        $revisao = $this->r->fresh()->revisao;

        $estado = $this->actingAs($this->admin())->putJson(route('mlb.anuncios.publicador.variantes', $this->produto->id), [
            'variantes' => [ChaveCanonica::UNICA => ['estoque' => 9]],
        ])->assertOk()->json();

        $this->assertSame($revisao + 1, $this->r->fresh()->revisao);
        $this->assertFalse($estado['conferencia']['vale'], 'editou: confira de novo');
        $this->assertTrue(EditorEmUso::editando((int) $this->produto->id));
    }

    public function test_conferencia_em_lote_aplica_os_mesmos_padroes(): void
    {
        $this->condicionais = ['GTIN'];

        $saida = app(ConferenciaEmLoteService::class)->executar((int) $this->produto->id);

        $this->assertSame('conferido:'.ConferenciaService::OK, $saida);
        $this->assertTrue(PadroesDoEditor::eanValido($this->gtin()));
        $this->assertTrue($this->r->fresh()->envio['frete_gratis']);
    }

    public function test_rascunho_publicado_ou_publicando_nao_recebe_padroes(): void
    {
        $this->r->update(['status' => PubRascunho::PUBLISHED]);
        $this->assertSame([], $this->padroes()->aplicar($this->r, comFrete: true));
        $this->assertNull($this->gtin());

        $this->r->update(['status' => PubRascunho::DRAFT]);
        $this->r->publicacoes()->create(['status' => PubPublicacao::RUNNING, 'revisao' => $this->r->revisao, 'modelo_publicacao' => 'UP', 'chave_idempotencia' => (string) Str::uuid()]);
        $this->assertSame([], $this->padroes()->aplicar($this->r, comFrete: true));
        $this->assertNull($this->gtin());
        $this->assertCount(0, Http::recorded(fn (Request $q) => str_contains($q->url(), '/shipping_options/free')));
    }

    public function test_quem_ja_tem_o_motivo_de_nao_ter_codigo_nao_ganha_ean(): void
    {
        $this->variante(['SELLER_SKU' => ['value_name' => 'CAD-01'], 'EMPTY_GTIN_REASON' => ['value_id' => '17055160']]);

        $this->assertSame([], $this->padroes()->aplicar($this->r));
        $this->assertNull($this->gtin());
    }
}
