<?php

namespace Tests\Feature\Publicador;

use App\Models\MlToken;
use App\Models\PubImagem;
use App\Models\PubRascunho;
use App\Models\PubValidacao;
use App\Services\Publicador\ConferenciaService;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\ImagemAssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;
use Tests\TestCase;

/**
 * D26: a trava por conta fecha também a conferência no ML. Em conta NÃO liberada,
 * "Conferir" roda só L1/L2 contra o schema da categoria (schema = token do APP) e
 * não faz nenhuma chamada com o token do cliente — nem validate, nem foto, nem as
 * leituras da conferência.
 *
 * Mutação: tirar a trava (ou invertê-la) quebra um dos dois lados — o caso não
 * liberado passa a ter requisição com `Bearer fake-access-token`, ou o liberado
 * deixa de chamar `/items/validate`, `available_listing_types` e `/items/search`.
 */
class ConferenciaContaNaoLiberadaTest extends TestCase
{
    use CenarioCadeira;
    use RefreshDatabase;

    /** Os caminhos que só a conta liberada pode ver (token do cliente). */
    private const PROIBIDOS = ['/items/validate', '/pictures/items/upload', '/attributes/conditional', 'available_listing_types', '/items/search', '/users/me', '/shipping_preferences'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->montarCenario();
        $this->fakeMl(['*/pictures/items/upload' => Http::response(['id' => '999-MLB1_102026', 'variations' => [['secure_url' => 'https://http2.mlstatic.com/D_1-O.jpg']]])]);
    }

    private function naoLiberar(): void
    {
        config(['publicador.contas_liberadas' => ['companies' => [], 'mlb_empresas' => []]]);
    }

    /** @return array{0: PubValidacao, 1: list<Request>} a validação e SÓ as requisições feitas durante a conferência */
    private function conferirEObservar(): array
    {
        $antes = count(Http::recorded());
        $v = app(ConferenciaService::class)->conferir($this->r->fresh());

        return [$v, array_map(fn ($par) => $par[0], array_slice(Http::recorded()->all(), $antes))];
    }

    private function assertZeroChamadaDoCliente(array $requisicoes): void
    {
        foreach ($requisicoes as $q) {
            $this->assertNotSame(['Bearer fake-access-token'], $q->header('Authorization'), 'nada com o token do cliente: '.$q->url());
            foreach (self::PROIBIDOS as $caminho) {
                $this->assertStringNotContainsString($caminho, $q->url());
            }
        }
        Http::assertNotSent(fn (Request $q) => str_contains($q->url(), '/items/validate'));
        Http::assertNotSent(fn (Request $q) => str_contains($q->url(), '/pictures/items/upload'));
    }

    public function test_conta_nao_liberada_confere_so_local_sem_nenhuma_chamada_com_o_token_do_cliente(): void
    {
        $this->naoLiberar();

        [$v, $requisicoes] = $this->conferirEObservar();

        $this->assertZeroChamadaDoCliente($requisicoes);
        $this->assertSame([], $requisicoes, 'o schema já estava guardado: nem a leitura pública foi preciso');
        $this->assertSame('L2', $v->camada);
        $this->assertSame(ConferenciaService::LOCAL, $v->resultado, json_encode($v->issues, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($v->respostas_ml['local']);
        $this->assertSame('CONTA-LIB', $v->respostas_ml['motivo']);
        $this->assertNull($v->plano_hash);
    }

    public function test_a_conferencia_local_nunca_deixa_o_rascunho_validado_nem_mexe_na_conta(): void
    {
        $this->naoLiberar();
        $antes = $this->r->fresh();

        $this->conferirEObservar();

        $depois = $this->r->fresh();
        $this->assertNotSame(PubRascunho::VALIDATED, $depois->status);
        $this->assertSame($antes->status, $depois->status);
        $this->assertSame($antes->modelo_publicacao, $depois->modelo_publicacao);
        $this->assertEquals($antes->conta_checada_em, $depois->conta_checada_em);
    }

    public function test_com_bloqueio_local_grava_bloqueado_na_camada_l2(): void
    {
        $this->naoLiberar();
        $this->r->update(['descricao' => null, 'categoria_id' => null]);

        [$v, $requisicoes] = $this->conferirEObservar();

        $this->assertSame(ConferenciaService::BLOQUEADO, $v->resultado);
        $this->assertSame('L2', $v->camada);
        $this->assertSame(['V-CAT-01'], array_column((array) $v->issues, 'regra'), 'sem categoria: BLOQUEADO V-CAT-01, também sem chamada');
        $this->assertSame([], $requisicoes);
    }

    public function test_foto_que_ainda_nao_subiu_nao_bloqueia_a_conferencia_local(): void
    {
        $this->naoLiberar();
        PubImagem::query()->where('rascunho_id', $this->r->id)->update(['upload_status' => PubImagem::PENDENTE, 'ml_picture_id' => null]);

        [$v, $requisicoes] = $this->conferirEObservar();

        $this->assertNotContains('V-IMG-08', array_column((array) $v->issues, 'regra'), 'elas não sobem de propósito');
        $this->assertSame(ConferenciaService::LOCAL, $v->resultado, json_encode($v->issues, JSON_UNESCAPED_UNICODE));
        $this->assertZeroChamadaDoCliente($requisicoes);
    }

    public function test_sem_token_continua_erro_v_acc_01_e_sem_chamada(): void
    {
        $this->naoLiberar();
        MlToken::query()->delete();

        [$v, $requisicoes] = $this->conferirEObservar();

        $this->assertSame(ConferenciaService::ERRO, $v->resultado);
        $this->assertSame('V-ACC-01', $v->issues[0]['regra']);
        $this->assertSame([], $requisicoes);
    }

    public function test_estado_traz_conferencia_local_e_tem_arquivo_da_foto(): void
    {
        $editor = app(EditorRascunhoService::class);
        $this->naoLiberar();
        $this->conferirEObservar();

        $estado = $editor->estado($this->r->fresh());
        $this->assertTrue($estado['conferencia']['local']);
        $this->assertSame(ConferenciaService::LOCAL, $estado['conferencia']['resultado']);
        $this->assertTrue($estado['imagens'][0]['tem_arquivo']);

        config(['publicador.contas_liberadas' => ['companies' => [$this->empresa->id], 'mlb_empresas' => []]]);
        $this->conferirEObservar();
        $this->assertFalse($editor->estado($this->r->fresh())['conferencia']['local']);
    }

    public function test_foto_em_conta_nao_liberada_fica_guardada_e_so_sobe_depois_da_liberacao(): void
    {
        $this->naoLiberar();
        $imagens = app(ImagemAssetService::class);
        $foto = PubImagem::query()->where('rascunho_id', $this->r->id)->firstOrFail();
        $foto->update(['upload_status' => PubImagem::PENDENTE, 'ml_picture_id' => null]);
        Storage::disk('local')->put($foto->caminho, 'conteudo');

        $imagens->enviarAoMl($foto->fresh());

        $this->assertSame(PubImagem::PENDENTE, $foto->fresh()->upload_status);
        $this->assertNull($foto->fresh()->upload_erro);
        $this->assertSame(0, $this->chamadas('/pictures/items/upload'));

        config(['publicador.contas_liberadas' => ['companies' => [$this->empresa->id], 'mlb_empresas' => []]]);
        $imagens->enviarAoMl($foto->fresh());

        $this->assertSame(PubImagem::ENVIADA, $foto->fresh()->upload_status);
        $this->assertSame(1, $this->chamadas('/pictures/items/upload'));
    }

    public function test_consultar_condicionais_em_conta_nao_liberada_devolve_nulo_sem_chamar(): void
    {
        $this->naoLiberar();

        $this->assertNull(app(ConferenciaService::class)->consultarCondicionais($this->r->fresh()));
        $this->assertSame(0, $this->chamadas('/attributes/conditional'));

        config(['publicador.contas_liberadas' => ['companies' => [$this->empresa->id], 'mlb_empresas' => []]]);
        $this->assertNotNull(app(ConferenciaService::class)->consultarCondicionais($this->r->fresh()));
        $this->assertSame(1, $this->chamadas('/attributes/conditional'));
    }

    public function test_conta_liberada_segue_chamando_validate_tipos_e_sku(): void
    {
        [$v, $requisicoes] = $this->conferirEObservar();

        $this->assertSame('L3', $v->camada);
        $this->assertContains($v->resultado, [ConferenciaService::OK, ConferenciaService::AVISOS]);
        $urls = array_map(fn (Request $q) => $q->url(), $requisicoes);
        $validates = array_filter($urls, fn ($u) => str_contains($u, '/items/validate'));
        $this->assertCount(count((array) $v->respostas_ml['itens']), $validates, 'um validate por item do plano');
        $this->assertNotEmpty($validates);
        $this->assertNotEmpty(array_filter($urls, fn ($u) => str_contains($u, 'available_listing_types')));
        $this->assertNotEmpty(array_filter($urls, fn ($u) => str_contains($u, '/items/search')));
        $this->assertNotEmpty(array_filter($requisicoes, fn (Request $q) => $q->header('Authorization') === ['Bearer fake-access-token']));
        $this->assertSame(PubRascunho::VALIDATED, $this->r->fresh()->status);
    }
}
