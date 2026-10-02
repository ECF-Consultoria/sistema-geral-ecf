<?php

namespace Tests\Feature\Publicador;

use App\Jobs\Publicador\ConferirRascunhoJob;
use App\Models\MlToken;
use App\Models\PubImagem;
use App\Models\PubRascunho;
use App\Models\PubValidacao;
use App\Services\Publicador\ConferenciaService;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem as R;
use App\Support\Publicador\Payload\MontadorDePlano;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\RegeneradorVariantes;
use App\Support\Publicador\Variacao\ValorEixo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;
use Tests\TestCase;

/**
 * A conferência com o ML — L3 (`08` §4), TC-32, 33, 92, 103. O ML é simulado
 * com as respostas REAIS da sondagem de 01/10 (conta #459 e cadeira MLB193945).
 */
class ConferenciaTest extends TestCase
{
    use CenarioCadeira;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->fakeMl();
    }

    private function conferir(): PubValidacao
    {
        return app(ConferenciaService::class)->conferir($this->r->fresh());
    }

    private static function regras(PubValidacao $v): array
    {
        return array_column((array) $v->issues, 'regra');
    }

    public function test_tc92_aprovado_grava_o_plano_e_marca_validado(): void
    {
        $v = $this->conferir();

        $this->assertSame(ConferenciaService::AVISOS, $v->resultado, 'o 400 só com avisos é aprovação (N-16)');
        $this->assertSame('L3', $v->camada);
        $this->assertSame($this->r->revisao, $v->revisao);
        $this->assertSame(PubRascunho::VALIDATED, $this->r->fresh()->status);
        $this->assertSame(1, $this->chamadas('/items/validate'));
        $this->assertSame(1, $this->chamadas('/attributes/conditional'));
        $this->assertCount(0, Http::recorded(fn (Request $q) => $q->method() === 'POST' && str_ends_with($q->url(), '/items')), 'conferir nunca publica');

        // D6: o hash é o do plano que será publicado; o payload enviado e a resposta crua ficam.
        $this->assertSame(64, strlen($v->plano_hash));
        $item = $v->respostas_ml['itens'][0];
        $this->assertSame('Cadeira Escritório Executiva ECF Giratória', $item['payload']['family_name']);
        $this->assertSame([['id' => '123-MLB1_102026']], $item['payload']['pictures']);
        $this->assertSame(400, $item['status']);
        $this->assertSame(['shipping.lost_me1_by_user', 'item.shipping.mandatory_free_shipping'], array_column($item['corpo']['cause'], 'code'));
        $this->assertStringNotContainsString('fake-access-token', json_encode($v->getAttributes()), 'token nunca é gravado');
    }

    public function test_preco_e_titulo_efetivos_entram_no_plano_e_mudar_o_preco_muda_o_hash(): void
    {
        $this->variante(['SELLER_SKU' => ['value_name' => 'CAD-01'], 'GTIN' => ['value_name' => '7896553367645']], preco: 0);
        $this->r->alvos()->update(['titulo' => null]);
        $this->variante(['SELLER_SKU' => ['value_name' => 'CAD-01'], 'GTIN' => ['value_name' => '7896553367645']]);
        $this->r->variantes()->first()->precos()->update(['preco' => null]);
        $this->efetivos = ['titulos' => ['gold_special' => 'Cadeira Planejada na Aba Anúncios ECF'], 'precos' => ['gold_special' => 149.9], 'mlbs' => []];

        $antes = $this->conferir();
        $this->assertSame(149.9, $antes->respostas_ml['itens'][0]['payload']['price']);
        $this->assertSame('Cadeira Planejada na Aba Anúncios ECF', $antes->respostas_ml['itens'][0]['payload']['family_name']);

        // A Precificação mudou: o plano muda — a publicação vai exigir conferir de novo (D6).
        $this->efetivos['precos']['gold_special'] = 159.9;
        $this->assertNotSame($antes->plano_hash, $this->conferir()->plano_hash);
    }

    public function test_tc32_condicional_exige_gtin_e_o_motivo_libera(): void
    {
        $this->variante(['SELLER_SKU' => ['value_name' => 'CAD-01']]);
        $this->condicionais = ['GTIN'];

        $v = $this->conferir();

        $this->assertSame(ConferenciaService::BLOQUEADO, $v->resultado);
        $gtin = collect($v->issues)->firstWhere('regra', 'V-ATT-08');
        $this->assertNotNull($gtin, json_encode($v->issues));
        $this->assertSame('GTIN', $gtin['alvo']['atributo']);
        $this->assertStringContainsString('passou a exigir', $gtin['mensagem']);
        $this->assertSame(0, $this->chamadas('/items/validate'), 'com bloqueio local, o validate não é chamado');
        $this->assertSame(['GTIN'], $this->r->fresh()->step_state['condicionais']['ids']);

        // "Outro motivo" de não ter GTIN resolve.
        $this->variante(['SELLER_SKU' => ['value_name' => 'CAD-01'], 'EMPTY_GTIN_REASON' => ['value_id' => '17055161']]);
        $v = $this->conferir();

        $this->assertNotContains('V-ATT-08', self::regras($v));
        $this->assertSame(ConferenciaService::AVISOS, $v->resultado);
        $this->assertSame(1, $this->chamadas('/items/validate'));
    }

    public function test_tc33_condicional_e_perguntado_de_novo_a_cada_revisao(): void
    {
        $servico = app(ConferenciaService::class);
        $this->assertSame([], $servico->consultarCondicionais($this->r->fresh()));
        $primeira = $this->r->fresh()->step_state['condicionais']['revisao'];

        $this->repo->gravarAtributos($this->r->fresh(), [...self::ATRIBUTOS, 'BRAND' => ['value_name' => 'Outra Marca']]);
        $this->repo->tocar($this->r->fresh());
        $this->condicionais = ['GTIN'];

        $this->assertSame(['GTIN'], $servico->consultarCondicionais($this->r->fresh()));
        $this->assertSame(2, $this->chamadas('/attributes/conditional'));
        $this->assertSame($primeira + 1, $this->r->fresh()->step_state['condicionais']['revisao'], 'a resposta fica com a revisão nova');

        // E o payload enviado é o do produto como está agora.
        $ultima = collect(Http::recorded(fn (Request $q) => str_contains($q->url(), '/attributes/conditional')))->last()[0];
        $this->assertContains(['id' => 'BRAND', 'value_name' => 'Outra Marca'], $ultima->data()['attributes']);
    }

    public function test_tc103_tipo_de_anuncio_indisponivel_para_a_conta(): void
    {
        $this->tipos = ['gold_pro'];

        $v = $this->conferir();

        $this->assertSame(ConferenciaService::BLOQUEADO, $v->resultado);
        $problema = collect($v->issues)->firstWhere('regra', 'V-SAL-01');
        $this->assertSame(['etapa' => 'E10', 'campo' => 'tipo', 'listing_type' => 'gold_special'], $problema['alvo']);
        $this->assertSame(0, $this->chamadas('/items/validate'));
    }

    public function test_bloqueio_local_para_antes_de_chamar_o_ml(): void
    {
        $this->repo->gravarAtributos($this->r->fresh(), array_diff_key(self::ATRIBUTOS, ['BRAND' => 1]));

        $v = $this->conferir();

        $this->assertSame(ConferenciaService::BLOQUEADO, $v->resultado);
        $this->assertContains('V-ATT-12', self::regras($v));
        $this->assertNull($v->plano_hash);
        $this->assertSame(0, $this->chamadas('/attributes/conditional') + $this->chamadas('/items/validate'));
        $this->assertSame(PubRascunho::DRAFT, $this->r->fresh()->status);
    }

    public function test_um_validate_por_item_e_o_mesmo_erro_vira_um_problema(): void
    {
        $this->r->alvos()->create(['listing_type_id' => 'gold_pro', 'titulo' => 'Cadeira de Escritório ECF Executiva com Giro', 'posicao' => 1]);
        $cor = new Eixo('COLOR', 'Cor', 0, true, [new ValorEixo('52049', 'Preto'), new ValorEixo('52028', 'Azul')]);
        $variantes = RegeneradorVariantes::regenerar($this->repo->snapshot($this->r->fresh())->variantes, [$cor])->variantes;
        $variantes = array_map(fn ($v) => $v->comDados(['estoque' => 2, 'precos' => ['gold_special' => 150.0, 'gold_pro' => 165.0],
            'atributos' => ['SELLER_SKU' => ['value_name' => 'CAD-'.$v->valores['COLOR']->valueName], 'GTIN' => ['value_name' => '7896553367645']]]), $variantes);
        $this->repo->gravarVariacao($this->r->fresh(), [$cor], $variantes);
        // Cor define a foto (V-IMG-05): cada cor com a sua, além da geral.
        $atribuicoes = [['imagem' => $this->r->imagens()->value('id'), 'grupo' => R::GERAL, 'posicao' => 0]];
        foreach (['52049', '52028'] as $i => $cor) {
            $foto = $this->r->imagens()->create(['caminho' => "publicador/{$cor}.jpg", 'sha256' => str_repeat((string) $i, 64), 'mime' => 'image/jpeg', 'bytes' => 800_000,
                'largura' => 1200, 'altura' => 1200, 'upload_status' => PubImagem::ENVIADA, 'ml_picture_id' => "PIC-{$cor}"]);
            $atribuicoes[] = ['imagem' => $foto->id, 'grupo' => "COLOR=id:{$cor}", 'posicao' => 0];
        }
        $this->repo->gravarAtribuicoes($this->r, $atribuicoes);

        $this->validate = fn (array $corpo) => Http::response(['message' => 'Validation error', 'error' => 'validation_error', 'status' => 400, 'cause' => [
            ['department' => 'items', 'cause_id' => 3705, 'type' => 'error', 'code' => 'item.title.minimum_length', 'references' => ['item.title'], 'message' => 'Inclua características'],
        ]], 400);

        $v = $this->conferir();

        $this->assertSame(4, $this->chamadas('/items/validate'), '2 tipos × 2 cores');
        $this->assertSame(ConferenciaService::BLOQUEADO, $v->resultado);
        $titulos = array_values(array_filter($v->issues, fn ($i) => $i['regra'] === 'V-TIT-03'));
        $this->assertCount(2, $titulos, 'um por tipo de anúncio — cada tipo tem o seu título');
        $this->assertSame([0, 1], $titulos[0]['alvo']['itens']);
        $this->assertSame('gold_special', $titulos[0]['alvo']['listing_type']);
        $this->assertSame(3705, $titulos[0]['ml_causa']['cause_id']);
        $this->assertSame(PubRascunho::DRAFT, $this->r->fresh()->status);
    }

    public function test_ml_fora_do_ar_grava_erro_sem_aprovar(): void
    {
        $this->validate = fn () => Http::response(['message' => 'internal'], 503);

        $v = $this->conferir();

        $this->assertSame(ConferenciaService::ERRO, $v->resultado);
        $this->assertStringContainsString('não respondeu', $v->issues[0]['mensagem']);
        $this->assertSame(4, $this->chamadas('/items/validate'), 'validate é leitura: repete o 5xx (3 vezes)');
        $this->assertSame(PubRascunho::DRAFT, $this->r->fresh()->status);
    }

    public function test_sku_em_outro_anuncio_avisa_mas_o_par_da_oferta_nao(): void
    {
        $this->skuEm = ['CAD-01' => ['MLB111', 'MLB222']];

        $v = $this->conferir();
        $aviso = collect($v->issues)->firstWhere('regra', 'V-REM-02');
        $this->assertSame('WARNING', $aviso['severidade']);
        $this->assertStringContainsString('MLB111, MLB222', $aviso['mensagem']);

        // O Premium já publicado desta oferta divide o SKU de propósito.
        $this->efetivos['mlbs'] = ['MLB111', 'MLB222'];
        $this->assertNotContains('V-REM-02', self::regras($this->conferir()));
    }

    public function test_conta_desconectada_e_modelo_que_mudou(): void
    {
        $this->r->update(['modelo_publicacao' => MontadorDePlano::LEGADO]);
        $v = $this->conferir();
        $this->assertSame(['V-ACC-02'], self::regras($v));

        MlToken::query()->update(['status' => 'revoked']);
        $v = $this->conferir();
        $this->assertSame(ConferenciaService::ERRO, $v->resultado);
        $this->assertSame('V-ACC-01', $v->issues[0]['regra']);
        $this->assertStringContainsString('reconectada', $v->issues[0]['mensagem']);
    }

    public function test_edicao_durante_a_conferencia_nao_marca_validado(): void
    {
        $this->validate = function () {
            PubRascunho::whereKey($this->r->id)->increment('revisao'); // alguém salvou enquanto o ML respondia

            return Http::response(self::fixture('conta/categorias/MLB193945/validate_base_up'), 400);
        };

        $v = $this->conferir();

        $this->assertSame(ConferenciaService::AVISOS, $v->resultado);
        $this->assertSame(PubRascunho::DRAFT, $this->r->fresh()->status, 'a conferência vale para a revisão antiga');
        $this->assertSame($this->r->revisao, $v->revisao);
    }

    public function test_job_vai_para_a_fila_high_e_um_por_rascunho(): void
    {
        Queue::fake();

        ConferirRascunhoJob::dispatch($this->r->id);

        Queue::assertPushedOn('high', ConferirRascunhoJob::class);
        $this->assertSame((string) $this->r->id, (new ConferirRascunhoJob($this->r->id))->uniqueId());
    }

    public function test_job_roda_a_conferencia_e_falha_vira_erro_na_tela(): void
    {
        (new ConferirRascunhoJob($this->r->id))->handle(app(ConferenciaService::class));
        $this->assertSame(ConferenciaService::AVISOS, $this->r->validacoes()->latest('id')->first()->resultado);

        (new ConferirRascunhoJob($this->r->id))->failed(new \RuntimeException('boom'));
        $ultima = $this->r->validacoes()->latest('id')->first();
        $this->assertSame(ConferenciaService::ERRO, $ultima->resultado);
        $this->assertStringContainsString('não terminou', $ultima->issues[0]['mensagem']);
    }
}
