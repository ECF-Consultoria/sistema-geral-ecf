<?php

namespace Tests\Feature\Trilha;

use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use App\Jobs\Publicador\SincronizarProdutoDoPortalJob;
use App\Models\Company;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Models\MlToken;
use App\Models\TrilhaOnda;
use App\Models\User;
use App\Services\Portal\Estrutura\Produtos\ModalidadeDeEnvio;
use App\Services\Portal\Estrutura\Produtos\VariacaoImagensService;
use App\Services\Trilha\CompletudeDoProduto;
use App\Services\Trilha\TrilhaDaEmpresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * As fotos na porta da equipe da trilha.
 *
 * - **Ver** (02/11/2026, achado no teste em produção: as fotos do "Mapear" apareciam quebradas). A rota de imagem do
 *   Portal, gerada fora de uma visita ao Portal, saía `/portal/0/…` e, no domínio do admin, levava ao domínio do
 *   cliente (sem sessão da equipe). Agora a trilha tem a sua, com a guarda do cadastro: as duas chaves (404), a escrita
 *   no Portal da empresa (403) e a imagem da variação e da empresa do endereço (404). `?original=1` (F3) entrega o
 *   arquivo enviado; sem ele, a miniatura.
 * - **Enviar e excluir** (3ª entrega, F3, 03/11/2026 — desenho §5.7, §6.2 rotas 10 a 12): pelo serviço do Portal, com
 *   as regras dele (`RegrasDoCadastro::imagens`): tudo ou nada, o teto de 12 por variação, o formato pelo CONTEÚDO, o
 *   envio grande demais que vira 422 com a mensagem, a renumeração ao excluir; a foto que completa o produto o leva ao
 *   Publicador UMA vez; o produto travado é 409; nenhuma resposta leva endereço do Portal.
 * - **Conferir frete pela conta** (rota 13): sem conta, a estimativa e nenhuma requisição; com a conta, só GET, a
 *   modalidade em cache, e o ME1 que era só dos limites dos Correios some. Com a trilha de trabalho o "frete ME1" é
 *   AVISO (pergunta 3), não falta.
 */
class FotosNaTrilhaTest extends TestCase
{
    use MontaTrilha;
    use RefreshDatabase;

    private Company $empresa;

    private User $dev;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Cache::flush();
        Storage::fake(VariacaoImagensService::DISCO);
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', TrilhaOnda::FUSO));
        config(['trilha.empresas' => [], 'trilha.trabalho.empresas' => [], 'publicador.preparo_ia.ativo' => true]);
        $this->empresa = $this->empresaDaTrilha();
        $this->dev = User::factory()->create(['role' => 'admin', 'active' => true, 'name' => 'Rafael']);
        $this->dev->forceFill(['is_dev' => true])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══ Ver (rota 12) ══════════════════════════════════════════════════════

    public function test_o_mapear_entrega_a_foto_pela_rota_da_trilha_e_ela_abre_para_a_equipe(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA', ['Azul', 'Verde']);
        $capa = $this->comArquivo($this->capaDa($a['variacoes'][0]->id));

        $opcoes = $this->actingAs($this->dev)
            ->getJson("/trilha/{$this->empresa->id}/produtos/{$a['produto']->id}/mapeamento")
            ->assertOk()->json();

        $esperada = "/trilha/{$this->empresa->id}/fotos/{$a['variacoes'][0]->id}/{$capa->id}";
        $this->assertSame($esperada, $opcoes['produto']['foto']);
        $this->assertSame($esperada, $opcoes['produto']['cores'][0]['foto']);
        foreach ($opcoes['produto']['cores'] as $cor) {
            $this->assertStringNotContainsString('/portal', (string) $cor['foto'], 'nunca a rota do Portal na porta da equipe');
        }

        $resposta = $this->actingAs($this->dev)->get($esperada)->assertOk();
        $this->assertSame('nosniff', $resposta->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('image/', (string) $resposta->headers->get('Content-Type'));
        $this->assertStringContainsString('private', (string) $resposta->headers->get('Cache-Control'));
    }

    public function test_a_foto_de_outra_variacao_ou_de_outra_loja_pelo_endereco_desta_e_404(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA', ['Azul', 'Verde']);
        $capaAzul = $this->comArquivo($this->capaDa($a['variacoes'][0]->id));
        $outra = $this->empresaDaTrilha();
        $b = $this->produtoCompleto($outra, 'Vaso Beta', 'VB');
        $deFora = $this->comArquivo($this->capaDa($b['variacoes'][0]->id));

        // A imagem da cor Azul pelo número da cor Verde.
        $this->actingAs($this->dev)->get("/trilha/{$this->empresa->id}/fotos/{$a['variacoes'][1]->id}/{$capaAzul->id}")->assertNotFound();
        // A imagem de outra loja pelo endereço desta (com a variação certa dela) — também a original.
        $this->actingAs($this->dev)->get("/trilha/{$this->empresa->id}/fotos/{$b['variacoes'][0]->id}/{$deFora->id}")->assertNotFound();
        $this->actingAs($this->dev)->get("/trilha/{$this->empresa->id}/fotos/{$b['variacoes'][0]->id}/{$deFora->id}?original=1")->assertNotFound();
        // Linha sem arquivo: 404, nunca 500.
        $semArquivo = $this->capaDa($a['variacoes'][1]->id);
        $this->actingAs($this->dev)->get("/trilha/{$this->empresa->id}/fotos/{$a['variacoes'][1]->id}/{$semArquivo->id}")->assertNotFound();
        // Id de 19 dígitos: 404, nunca 500.
        $this->actingAs($this->dev)->get("/trilha/{$this->empresa->id}/fotos/1234567890123456789/1")->assertNotFound();
    }

    public function test_sem_a_chave_sem_acesso_ao_portal_ou_sem_login_a_foto_nao_sai(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA');
        $capa = $this->comArquivo($this->capaDa($a['variacoes'][0]->id));
        $url = "/trilha/{$this->empresa->id}/fotos/{$a['variacoes'][0]->id}/{$capa->id}";

        $semCarteira = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $semCarteira->forceFill(['is_dev' => true])->save();
        $this->actingAs($semCarteira->refresh())->get($url)->assertForbidden();

        // Na trilha, mas sem a chave de trabalho: o Mapear não existe, a foto também não.
        $soLeitura = $this->empresaDaTrilha(trabalho: false);
        $p = $this->produtoCompleto($soLeitura, 'Vaso Leitura', 'VL');
        $capaLeitura = $this->comArquivo($this->capaDa($p['variacoes'][0]->id));
        $this->actingAs($this->dev)->get("/trilha/{$soLeitura->id}/fotos/{$p['variacoes'][0]->id}/{$capaLeitura->id}")->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->get($url)->assertRedirect();
    }

    public function test_original_1_entrega_o_arquivo_enviado_e_sem_ele_a_miniatura(): void
    {
        [$produto, $v] = $this->produtoNovo();
        $r = $this->enviar($produto, $v, [UploadedFile::fake()->image('sala.png', 900, 700)])->assertOk()->json();
        $foto = $r['variacoes'][0]['fotos'][0];
        $img = EstruturaProdutoVariacaoImagem::query()->findOrFail($foto['id']);

        $grande = $this->actingAs($this->dev)->get($foto['url_original'])->assertOk();
        $this->assertSame('image/png', $grande->headers->get('Content-Type'));
        $this->assertSame(['max-age=3600', 'private'], $this->cache($grande));
        $this->assertSame('nosniff', $grande->headers->get('X-Content-Type-Options'));
        $this->assertSame(Storage::disk(VariacaoImagensService::DISCO)->get($img->caminho), $grande->streamedContent());

        $miniatura = $this->actingAs($this->dev)->get($foto['url'])->assertOk();
        $this->assertSame('image/jpeg', $miniatura->headers->get('Content-Type'));
        $this->assertSame(['max-age=604800', 'private'], $this->cache($miniatura));
        $this->assertSame('nosniff', $miniatura->headers->get('X-Content-Type-Options'));
        $this->assertNotSame($grande->streamedContent(), $miniatura->streamedContent());
        [$largura] = getimagesizefromstring($miniatura->streamedContent());
        $this->assertSame(VariacaoImagensService::MINIATURA, $largura);
    }

    // ═══ Enviar (rota 10) ═══════════════════════════════════════════════════

    public function test_envia_tres_fotos_e_o_retrato_volta_com_as_urls_da_trilha(): void
    {
        [$produto, $v] = $this->produtoNovo();

        $resposta = $this->enviar($produto, $v, [$this->jpg('a.jpg'), $this->jpg('b.jpg'), $this->jpg('c.jpg')])->assertOk();
        $r = $resposta->json();

        $fotos = $r['variacoes'][0]['fotos'];
        $ids = EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $v->id)->orderBy('ordem')->pluck('id')->all();
        $this->assertCount(3, $ids);
        $this->assertSame(array_map(fn (int $id) => [
            'id'            => $id,
            'url'           => "/trilha/{$this->empresa->id}/fotos/{$v->id}/{$id}",
            'url_original'  => "/trilha/{$this->empresa->id}/fotos/{$v->id}/{$id}?original=1",
        ], $ids), array_map(fn (array $f) => array_intersect_key($f, array_flip(['id', 'url', 'url_original'])), $fotos));
        $this->assertSame(['a.jpg', 'b.jpg', 'c.jpg'], array_column($fotos, 'nome_original'));
        $this->assertCount(3, Storage::disk(VariacaoImagensService::DISCO)->allFiles());
        $this->semPortal($resposta);

        // A falta das fotos saiu; o resto do cadastro continua faltando — nada vai ao Publicador.
        $this->assertSame('lista', $r['situacao']['etapa']);
        $this->assertEmpty(array_filter($r['situacao']['faltas'], fn (string $f) => str_starts_with($f, 'fotos')));
        $this->assertSame('Fotos enviadas. Ainda faltam: '.implode(', ', $r['situacao']['faltas']).'.', $r['mensagem']);
        Queue::assertNothingPushed();

        // O rastro da trilha (passo `fotos`) e o do serviço.
        $evento = $this->eventosDaTrilha()->last();
        $this->assertSame(['fotos', 'trilha', 'interno', (int) $v->id, 3], [$evento->getExtraProperty('passo'), $evento->getExtraProperty('via'),
            $evento->getExtraProperty('origem'), $evento->getExtraProperty('variacao_id'), $evento->getExtraProperty('quantidade')]);
        $this->assertSame([User::class, (int) $this->dev->id], [$evento->causer_type, (int) $evento->causer_id]);
        $this->assertTrue(Activity::query()->get()->contains(fn (Activity $a) => $a->getExtraProperty('evento') === 'imagens_variacao_enviadas'));
    }

    public function test_a_13a_foto_e_422_e_a_remessa_e_tudo_ou_nada(): void
    {
        [$produto, $v] = $this->produtoNovo();
        $this->enviar($produto, $v, [$this->jpg(), $this->jpg(), $this->jpg()])->assertOk();

        // 3 + 10 passa do teto de 12: a remessa inteira é recusada, e a mensagem diz quantas ainda cabem.
        $this->enviar($produto, $v, array_map(fn () => $this->jpg(), range(1, 10)))
            ->assertStatus(422)
            ->assertJsonPath('errors.imagens.0', 'Cada variação aceita até 12 imagens. Esta já tem 3; envie no máximo 9.');
        // 13 numa remessa só: a forma já recusa.
        $this->enviar($produto, $v, array_map(fn () => $this->jpg(), range(1, 13)))
            ->assertStatus(422)
            ->assertJsonPath('errors.imagens.0', 'Cada variação aceita até 12 imagens. Envie menos de uma vez.');

        $this->assertSame(3, EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $v->id)->count());
        $this->assertCount(3, Storage::disk(VariacaoImagensService::DISCO)->allFiles(), 'nenhum arquivo órfão');
        $this->assertSame(1, $this->eventosDaTrilha()->filter(fn (Activity $a) => $a->getExtraProperty('passo') === 'fotos')->count());
    }

    public function test_texto_com_extensao_jpg_e_422_e_a_boa_da_mesma_remessa_tambem_nao_fica(): void
    {
        [$produto, $v] = $this->produtoNovo();

        // `assertJsonPath` não lê chave com ponto (`imagens.0`): o erro é lido direto (learnings §31).
        $erros = $this->enviar($produto, $v, [$this->arquivoReal('foto.jpg', '<?php echo 1; ?>')])->assertStatus(422)->json('errors');
        $this->assertSame(['A imagem 1 não está num formato aceito. Envie JPG, PNG ou WebP.'], $erros['imagens.0'] ?? null);
        $erros = $this->enviar($produto, $v, [$this->jpg(), $this->arquivoReal('nota.jpg', 'só texto')])->assertStatus(422)->json('errors');
        $this->assertSame(['A imagem 2 não está num formato aceito. Envie JPG, PNG ou WebP.'], $erros['imagens.1'] ?? null);
        $this->assertArrayNotHasKey('imagens.0', $erros, 'a boa passou na forma — e mesmo assim não fica');
        // Sem arquivo nenhum.
        $this->actingAs($this->dev)->postJson($this->urlFotos($produto, $v), [])
            ->assertStatus(422)->assertJsonPath('errors.imagens.0', 'Escolha ao menos uma imagem.');

        $this->assertSame(0, EstruturaProdutoVariacaoImagem::query()->count());
        $this->assertSame([], Storage::disk(VariacaoImagensService::DISCO)->allFiles());
        $this->assertSame(0, $this->eventosDaTrilha()->filter(fn (Activity $a) => $a->getExtraProperty('passo') === 'fotos')->count());
    }

    public function test_o_envio_grande_demais_vira_422_com_a_mensagem_no_caminho_da_trilha(): void
    {
        [$produto, $v] = $this->produtoNovo();
        $grande = VariacaoImagensService::MENSAGEM_GRANDE_DEMAIS;

        // O PHP descartou o corpo acima do `post_max_size`: o `ValidatePostSize` lança o 413 mudo e o bootstrap o traduz.
        $this->actingAs($this->dev)->call('POST', $this->urlFotos($produto, $v), [], [], [], [
            'CONTENT_LENGTH' => 50 * 1024 * 1024, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'multipart/form-data; boundary=x',
        ])->assertStatus(422)->assertJsonPath('errors.imagens.0', $grande)->assertJsonPath('message', $grande);

        // Abaixo do `post_max_size`, mas grande e sem arquivo (o PHP do servidor descartou): a mesma mensagem.
        $this->actingAs($this->dev)->call('POST', $this->urlFotos($produto, $v), [], [], [], [
            'CONTENT_LENGTH' => 3 * 1024 * 1024, 'HTTP_ACCEPT' => 'application/json',
        ])->assertStatus(422)->assertJsonPath('errors.imagens.0', $grande);

        // O arquivo que passou do `upload_max_filesize` chega marcado inválido: a mesma mensagem.
        $caminho = tempnam(sys_get_temp_dir(), 'img');
        $this->actingAs($this->dev)->post($this->urlFotos($produto, $v),
            ['imagens' => [new UploadedFile($caminho, 'grande.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true)]], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.imagens.0', $grande);
        @unlink($caminho);

        $this->assertSame(0, EstruturaProdutoVariacaoImagem::query()->count());
    }

    public function test_a_foto_que_completa_o_produto_o_leva_ao_publicador_uma_vez(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA');
        $v = $p['variacoes'][0];
        EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $v->id)->orderByDesc('ordem')->first()->delete();
        $this->assertSame(['fotos (2 de 3)'], TrilhaDaEmpresa::faltasDoProduto($this->empresa, (int) $p['produto']->id));

        $r = $this->enviar($p['produto'], $v, [$this->jpg()])->assertOk()->json();

        $this->assertSame(['mapa', [], 'Lista completa: o produto seguiu para o mapeamento.'], [$r['situacao']['etapa'], $r['situacao']['faltas'], $r['mensagem']]);
        $this->assertSame(1, $this->jobs(SincronizarProdutoDoPortalJob::class, $p['produto']));
        $this->assertSame(1, $this->jobs(PrepararProdutoNoPublicadorJob::class, $p['produto']));
    }

    // ═══ Excluir (rota 11) ══════════════════════════════════════════════════

    public function test_excluir_renumera_a_proxima_vira_capa_e_o_arquivo_sai_do_disco(): void
    {
        [$produto, $v] = $this->produtoNovo();
        $antes = $this->enviar($produto, $v, [$this->jpg('a.jpg'), $this->jpg('b.jpg'), $this->jpg('c.jpg')])->assertOk()->json('variacoes.0.fotos');
        $capa = EstruturaProdutoVariacaoImagem::query()->findOrFail($antes[0]['id']);
        $this->assertTrue(Storage::disk(VariacaoImagensService::DISCO)->exists($capa->caminho));

        $resposta = $this->actingAs($this->dev)->deleteJson($this->urlFotos($produto, $v)."/{$capa->id}")->assertOk();
        $r = $resposta->json();

        $this->assertSame([$antes[1]['id'], $antes[2]['id']], array_column($r['variacoes'][0]['fotos'], 'id'));
        $this->assertSame([$antes[1]['id'] => 0, $antes[2]['id'] => 1],
            EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $v->id)->orderBy('ordem')->pluck('ordem', 'id')->all());
        $this->assertFalse(Storage::disk(VariacaoImagensService::DISCO)->exists($capa->caminho), 'o arquivo sai depois do commit');
        $this->assertCount(2, Storage::disk(VariacaoImagensService::DISCO)->allFiles());
        $this->assertContains('fotos (2 de 3)', $r['situacao']['faltas']);
        $this->assertStringStartsWith('Foto excluída. Ainda faltam: ', $r['mensagem']);
        $this->semPortal($resposta);

        $evento = $this->eventosDaTrilha()->last();
        $this->assertSame(['foto_excluida', (int) $v->id, (int) $capa->id], [$evento->getExtraProperty('passo'),
            $evento->getExtraProperty('variacao_id'), $evento->getExtraProperty('imagem_id')]);
        Queue::assertNothingPushed();
    }

    public function test_a_imagem_ou_a_variacao_que_nao_e_do_endereco_e_404_e_nada_muda(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA', ['Azul', 'Verde']);
        [$azul, $verde] = $a['variacoes'];
        $capaAzul = $this->capaDa($azul->id);
        $b = $this->produtoCompleto($this->empresa, 'Vaso Beta', 'VB');
        $outra = $this->empresaDaTrilha();
        $deFora = $this->produtoCompleto($outra, 'Vaso de Fora', 'VX');
        $capaDeFora = $this->capaDa($deFora['variacoes'][0]->id);
        $total = EstruturaProdutoVariacaoImagem::query()->count();

        // Excluir: a imagem da Azul pela Verde; a da outra loja por esta; a variação de B pelo produto A.
        $this->actingAs($this->dev)->deleteJson($this->urlFotos($a['produto'], $verde)."/{$capaAzul->id}")->assertNotFound();
        $this->actingAs($this->dev)->deleteJson("/trilha/{$this->empresa->id}/produtos/{$a['produto']->id}/variacoes/{$deFora['variacoes'][0]->id}/fotos/{$capaDeFora->id}")->assertNotFound();
        $this->actingAs($this->dev)->deleteJson($this->urlFotos($a['produto'], $b['variacoes'][0])."/{$this->capaDa($b['variacoes'][0]->id)->id}")->assertNotFound();
        // Enviar: a variação de B pelo produto A (corpo inválido de propósito: o 404 vem antes do 422) e a da outra loja.
        $this->actingAs($this->dev)->postJson($this->urlFotos($a['produto'], $b['variacoes'][0]), [])->assertNotFound();
        $this->enviar($a['produto'], $deFora['variacoes'][0], [$this->jpg()])->assertNotFound();
        // Ids de 19 dígitos: 404, nunca 500.
        $this->actingAs($this->dev)->deleteJson($this->urlFotos($a['produto'], $azul).'/1234567890123456789')->assertNotFound();
        $this->actingAs($this->dev)->postJson("/trilha/{$this->empresa->id}/produtos/{$a['produto']->id}/variacoes/1234567890123456789/fotos", [])->assertNotFound();

        $this->assertSame($total, EstruturaProdutoVariacaoImagem::query()->count());
        $this->assertSame([], Storage::disk(VariacaoImagensService::DISCO)->allFiles());
        $this->assertSame(0, $this->eventosDaTrilha()->count());
    }

    // ═══ A trava (pergunta 1) ═══════════════════════════════════════════════

    public function test_o_produto_travado_responde_409_ao_enviar_e_ao_excluir_e_nada_muda(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA');
        $v = $p['variacoes'][0];
        EstruturaAnuncio::create(['oferta_id' => $p['simples'][0]->id, 'tipo' => EstruturaAnuncio::TIPO_CLASSICO,
            'status' => EstruturaAnuncio::STATUS_ATIVO, 'codigo_mlb' => 'MLB1234567890', 'titulo' => 'Vaso']);
        $capa = $this->capaDa($v->id);

        $this->enviar($p['produto'], $v, [$this->jpg()])->assertStatus(409)->assertJsonPath('travado', true);
        $this->actingAs($this->dev)->deleteJson($this->urlFotos($p['produto'], $v)."/{$capa->id}")
            ->assertStatus(409)->assertJsonPath('travado', true)->assertJsonStructure(['motivo', 'message']);

        $this->assertSame(3, EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $v->id)->count());
        $this->assertSame([], Storage::disk(VariacaoImagensService::DISCO)->allFiles());
        $this->assertSame(0, $this->eventosDaTrilha()->count());
        Queue::assertNothingPushed();
    }

    // ═══ Frete (rota 13) e o ME1 como aviso (pergunta 3) ════════════════════

    public function test_frete_sem_conta_conectada_e_a_estimativa_sem_requisicao_e_o_me1_e_aviso(): void
    {
        $p = $this->produtoSoPorTransportadora();
        Http::fake();

        $resposta = $this->frete($p['produto'])->assertOk();
        $r = $resposta->json();

        Http::assertNothingSent();
        $this->assertSame(['conectado' => false, 'falhou' => false, 'fora_do_me2' => ModalidadeDeEnvio::FORA_SEM_SABER], $r['frete']);
        $this->assertSame('me1', $r['variacoes'][0]['logistica'], 'pelos limites padrão (Correios)');
        // Na trilha de trabalho o ME1 não segura o produto: aviso, e ele segue para o mapeamento.
        $this->assertSame([[], [CompletudeDoProduto::AVISO_ME1], 'mapa'], [$r['situacao']['faltas'], $r['situacao']['avisos'], $r['situacao']['etapa']]);
        $this->assertStringContainsString('estimativa', $r['mensagem']);
        $this->assertNull(Cache::get(ModalidadeDeEnvio::chave((int) $this->empresa->id)));
        $this->semPortal($resposta);
        Queue::assertNothingPushed();
    }

    public function test_frete_pela_conta_le_so_com_get_guarda_a_modalidade_e_o_me1_falso_some(): void
    {
        $p = $this->produtoSoPorTransportadora();
        $this->conectar();
        // A conta despacha pela Coleta: 187 × 44 × 12 cm de 28,5 kg cabe no ME2 dela (nos Correios, não).
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), '/shipping_preferences')) {
                return Http::response(['modes' => ['me2'], 'logistics' => [['mode' => 'me2', 'types' => [
                    ['type' => 'drop_off', 'default' => false, 'status' => 'active'],
                    ['type' => 'cross_docking', 'default' => true, 'status' => 'active'],
                ]]]]);
            }
            if (str_contains($r->url(), '/shipping_options/free')) {
                return Http::response(['coverage' => ['all_country' => ['list_cost' => 61.9, 'discount' => ['type' => 'mandatory']]]]);
            }

            return Http::response([], 404);
        });
        $this->assertSame([CompletudeDoProduto::AVISO_ME1], $this->abrir($p['produto'])->json('situacao.avisos'), 'antes: ME1 pelos Correios');

        $resposta = $this->frete($p['produto'])->assertOk();
        $r = $resposta->json();

        $this->assertSame(['conectado' => true, 'falhou' => false, 'fora_do_me2' => ModalidadeDeEnvio::FORA_COMBINAR], $r['frete']);
        $this->assertSame('cross_docking', ModalidadeDeEnvio::emCache($this->empresa));
        $this->assertSame('me2', $r['variacoes'][0]['logistica']);
        $this->assertSame([[], []], [$r['situacao']['faltas'], $r['situacao']['avisos']], 'o ME1 falso some');
        $this->assertSame('Frete conferido pela conta da loja.', $r['mensagem']);
        // A Lista da trilha também (a mesma modalidade em cache).
        $this->assertSame([], $this->produtoNaTrilha(app(TrilhaDaEmpresa::class)->montar($this->empresa->fresh()), (int) $p['produto']->id)['avisos']);

        // Só leitura na conta da loja, e o token não sai na resposta.
        $metodos = array_map(fn (array $par) => $par[0]->method(), Http::recorded()->all());
        $this->assertNotEmpty($metodos);
        $this->assertSame(['GET'], array_values(array_unique($metodos)));
        $this->assertStringNotContainsString('token-da-loja', (string) $resposta->getContent());
        $this->semPortal($resposta);
    }

    public function test_frete_e_404_fora_das_chaves_e_de_produto_de_outra_loja(): void
    {
        Http::fake();
        $outra = $this->empresaDaTrilha();
        $deFora = $this->produtoCompleto($outra, 'Vaso de Fora', 'VX');
        $this->actingAs($this->dev)->postJson("/trilha/{$this->empresa->id}/produtos/{$deFora['produto']->id}/frete")->assertNotFound();

        $soLeitura = $this->empresaDaTrilha(trabalho: false);
        $p = $this->produtoCompleto($soLeitura, 'Vaso Leitura', 'VL');
        $this->actingAs($this->dev)->postJson("/trilha/{$soLeitura->id}/produtos/{$p['produto']->id}/frete")->assertNotFound();
        Http::assertNothingSent();
    }

    // ─── apoio ───

    /** Um produto criado pela trilha (nome + SKU): uma variação sem eixo e sem foto. @return array{0: EstruturaProduto, 1: EstruturaProdutoVariacao} */
    private function produtoNovo(): array
    {
        $id = $this->actingAs($this->dev)->postJson("/trilha/{$this->empresa->id}/produtos", ['nome' => 'Poltrona', 'codigo' => 'POLT-'.uniqid()])
            ->assertCreated()->json('produto.id');
        $produto = EstruturaProduto::query()->findOrFail($id);

        return [$produto, $produto->variacoes()->sole()];
    }

    /** O produto completo cuja caixa só vai por transportadora pelos limites dos Correios (o maior lado passa de 100 cm). */
    private function produtoSoPorTransportadora(): array
    {
        $p = $this->produtoCompleto($this->empresa, 'Aparador Longo', 'AP');
        $p['variacoes'][0]->volumes()->update(['comprimento' => 187, 'largura' => 44, 'altura' => 12, 'peso' => 28.5]);

        return $p;
    }

    private function conectar(): void
    {
        MlToken::create([
            'company_id' => $this->empresa->id, 'ml_user_id' => '436501796',
            'access_token' => 'token-da-loja', 'refresh_token' => 'refresh-da-loja',
            'token_type' => 'bearer', 'scope' => 'read write offline_access',
            'expires_at' => now()->addDays(6), 'last_refreshed_at' => now(),
            'status' => 'active', 'connected_at' => now(),
        ]);
    }

    private function urlFotos(EstruturaProduto $produto, EstruturaProdutoVariacao $v): string
    {
        return "/trilha/{$this->empresa->id}/produtos/{$produto->id}/variacoes/{$v->id}/fotos";
    }

    private function enviar(EstruturaProduto $produto, EstruturaProdutoVariacao $v, array $arquivos): TestResponse
    {
        return $this->actingAs($this->dev)->post($this->urlFotos($produto, $v), ['imagens' => $arquivos], ['Accept' => 'application/json']);
    }

    private function frete(EstruturaProduto $produto): TestResponse
    {
        return $this->actingAs($this->dev)->postJson("/trilha/{$this->empresa->id}/produtos/{$produto->id}/frete");
    }

    private function abrir(EstruturaProduto $produto): TestResponse
    {
        return $this->actingAs($this->dev)->getJson("/trilha/{$this->empresa->id}/produtos/{$produto->id}/cadastro")->assertOk();
    }

    private function jpg(string $nome = 'foto.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($nome, 800, 600);
    }

    /**
     * Arquivo de verdade em disco temporário: o `UploadedFile::fake()` deduz o MIME pelo NOME, e o que importa aqui é o
     * servidor olhar o CONTEÚDO.
     */
    private function arquivoReal(string $nome, string $conteudo): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, $nome, null, null, true);
    }

    /** As diretivas do Cache-Control, em ordem (o Symfony pode reordená-las). @return list<string> */
    private function cache(TestResponse $resposta): array
    {
        $diretivas = array_map('trim', explode(',', (string) $resposta->headers->get('Cache-Control')));
        sort($diretivas);

        return $diretivas;
    }

    private function semPortal(TestResponse $resposta): void
    {
        $this->assertStringNotContainsString('/portal/', (string) $resposta->getContent());
        $this->assertStringNotContainsString('\/portal\/', (string) $resposta->getContent());
    }

    /** Quantos Jobs desta classe a fila recebeu para o produto. */
    private function jobs(string $classe, EstruturaProduto $produto): int
    {
        return Queue::pushed($classe, fn ($j) => (int) $j->estruturaProdutoId === (int) $produto->id)->count();
    }

    private function eventosDaTrilha()
    {
        return Activity::query()->where('log_name', 'portal')->orderBy('id')->get()
            ->filter(fn (Activity $a) => $a->getExtraProperty('evento') === 'trilha_cadastro_salvo')->values();
    }

    private function capaDa(int $variacaoId): EstruturaProdutoVariacaoImagem
    {
        return EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $variacaoId)->orderBy('ordem')->orderBy('id')->firstOrFail();
    }

    /** Grava no disco uma imagem de verdade no caminho da linha (o GD gera a miniatura dela). */
    private function comArquivo(EstruturaProdutoVariacaoImagem $img): EstruturaProdutoVariacaoImagem
    {
        $conteudo = 'jpeg-falso';
        if (function_exists('imagecreatetruecolor')) {
            $gd = imagecreatetruecolor(40, 30);
            ob_start();
            imagejpeg($gd);
            $conteudo = (string) ob_get_clean();
        }
        Storage::disk(VariacaoImagensService::DISCO)->put($img->caminho, $conteudo);

        return $img;
    }
}
