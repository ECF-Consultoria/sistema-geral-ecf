<?php

namespace Tests\Feature\Trilha;

use App\Http\Middleware\RestringeDominioDoPortal;
use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use App\Jobs\Publicador\SincronizarProdutoDoPortalJob;
use App\Models\Company;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Models\EstruturaProdutoVolume;
use App\Models\PortalUsuario;
use App\Models\User;
use App\Services\EcfDriveService;
use App\Services\Portal\Estrutura\Produtos\VariacaoImagensService;
use App\Services\Portal\PortalEquipeService;
use App\Services\Trilha\FotosDoClienteNaTrilha;
use App\Services\Trilha\TrilhaDaEmpresa;
use App\Support\Portal\PortalContexto;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\EntraNoPortal;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * As fotos enviadas pelo CLIENTE na trilha (4ª entrega, fatia 4f, 04/11/2026 — desenho §19.15, D57/D58, R9): a 1ª
 * ESCRITA na porta do cliente.
 *
 * - **O quadro de fotos** (`GET portal/{pe}/trilha/produtos/{produto}/fotos`): uma caixa por cor, pela régua da gaveta
 *   da equipe (com cor e tamanho, a "dona" da cor), o que falta para o mínimo, os limites do envio e `editavel`/`motivo`.
 * - **Enviar e excluir** pelos MESMOS serviços da equipe, com o ator CLIENTE no histórico; a foto que completa o produto
 *   o leva adiante (o `prepararSeCabe`, uma vez) e o quadro fica só leitura.
 * - **A guarda**, na ordem do contrato: `portal.auth` → o cliente de verdade → as duas chaves (404) → produto, variação e
 *   imagem do endereço presos à empresa (404, antes do corpo) → produto que andou ou saiu da Lista (409 `{message}`, nada
 *   grava) → 422 (regras de imagem do Portal; o 413 do servidor vira 422 `errors.imagens`).
 * - **R9**: o domínio do cliente deixa as quatro rotas passarem (allowlist) e só elas; o CSRF vale; `cliente_escrita`
 *   chega só ao cliente de verdade (não à equipe, nem pelo modo equipe do Portal); nenhum texto que o cliente lê cita
 *   "Mercado Livre", "Publicador", "Portal" ou "agente"; a rota de foto da equipe não muda.
 */
class FotosDoClienteNaTrilhaTest extends TestCase
{
    use EntraNoPortal;
    use MontaTrilha;
    use RefreshDatabase;

    /** As palavras que o cliente nunca lê nesta porta (D59). */
    private const PROIBIDAS = ['mercado livre', 'publicador', 'portal', 'agente'];

    private Company $empresa;

    private PortalUsuario $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        Queue::fake();
        Cache::flush();
        Storage::fake(VariacaoImagensService::DISCO);
        // O badge de alertas do menu interno (só na porta da equipe) pergunta ao ECF Drive.
        $this->mock(EcfDriveService::class, fn (MockInterface $m) => $m->shouldReceive('listSignals')->andReturn(['total' => 7]));
        config(['trilha.empresas' => [], 'trilha.trabalho.empresas' => [], 'publicador.preparo_ia.ativo' => true]);
        $this->empresa = $this->empresaDaTrilha();
        $this->cliente = $this->clienteDoPortal($this->empresa, ['nome' => 'Joana da Loja']);
    }

    // ═══ O quadro de fotos ══════════════════════════════════════════════════

    public function test_o_cliente_le_o_quadro_de_fotos_uma_caixa_por_cor_com_o_que_falta(): void
    {
        $p = $this->produtoNaLista(['Azul', 'Verde']);
        [$azul, $verde] = $p['variacoes'];
        $fotosAzul = $this->idsDasFotos($azul);
        $fotosVerde = $this->idsDasFotos($verde);

        $q = $this->comoCliente()->getJson($this->urlQuadro($p['produto']))->assertOk()->json();

        $base = "/portal/{$this->empresa->id}/trilha/fotos";
        $this->assertSame([
            'produto' => ['id' => (int) $p['produto']->id, 'nome' => 'Vaso Jatobá', 'editavel' => true, 'motivo' => null],
            'cores'   => [
                ['variacao_id' => (int) $azul->id, 'rotulo' => 'Azul', 'minimo' => 3, 'maximo' => 12,
                    'fotos' => array_map(fn (int $id) => ['id' => $id, 'url' => "{$base}/{$azul->id}/{$id}"], $fotosAzul), 'faltam' => 0],
                ['variacao_id' => (int) $verde->id, 'rotulo' => 'Verde', 'minimo' => 3, 'maximo' => 12,
                    'fotos' => array_map(fn (int $id) => ['id' => $id, 'url' => "{$base}/{$verde->id}/{$id}"], $fotosVerde), 'faltam' => 1],
            ],
            'limites' => ['tamanho_mb' => 10, 'tipos' => ['jpg', 'png', 'webp'], 'por_envio' => 12],
        ], $q);
        $this->assertTextoNeutro($q);
    }

    /**
     * Com cor E tamanho, a foto é da COR e mora numa variação dela — a "dona": a 1ª com foto, senão a 1ª. É a MESMA
     * régua da gaveta da equipe (`dona_da_cor` do retrato): as duas portas apontam para a mesma variação.
     */
    public function test_com_cor_e_tamanho_a_caixa_e_da_cor_e_aponta_para_a_dona_da_gaveta_da_equipe(): void
    {
        $produto = $this->produtoComCorETamanho();
        [$azulP, $azulM, $verdeP] = $produto->variacoes()->get()->all();
        $this->fotos($azulM, 2); // a Azul tem foto só no M: a dona é o M, não o 1º tamanho

        $q = $this->comoCliente()->getJson($this->urlQuadro($produto))->assertOk()->json();

        $this->assertSame([[(int) $azulM->id, 'Azul', 2, 1], [(int) $verdeP->id, 'Verde', 0, 3]],
            array_map(fn (array $c) => [$c['variacao_id'], $c['rotulo'], count($c['fotos']), $c['faltam']], $q['cores']));

        // A gaveta da equipe marca as mesmas donas.
        $dev = $this->dev();
        $retrato = $this->actingAs($dev)->getJson("/trilha/{$this->empresa->id}/produtos/{$produto->id}/cadastro")->assertOk()->json();
        $donas = array_values(array_map(fn (array $v) => $v['id'], array_filter($retrato['variacoes'], fn (array $v) => $v['dona_da_cor'])));
        $this->assertSame($donas, array_column($q['cores'], 'variacao_id'));
        $this->assertNotContains((int) $azulP->id, $donas);

        // O envio vai à dona (a da Verde, sem foto nenhuma, é o 1º tamanho dela) e o quadro o mostra na caixa da cor.
        $this->app['auth']->forgetGuards();
        $q = $this->enviar($produto, $verdeP, [$this->jpg('verde.jpg')])->assertOk()->json();
        $this->assertSame([2, 1], array_map(fn (array $c) => count($c['fotos']), $q['cores']));
        $this->assertSame([], $q['avisos']);
    }

    // ═══ Enviar ═════════════════════════════════════════════════════════════

    public function test_o_cliente_envia_fotos_o_arquivo_fica_e_o_historico_e_do_cliente(): void
    {
        $p = $this->produtoNaLista(['Azul', 'Verde']);
        [$azul, $verde] = $p['variacoes'];
        // A Verde fica com 1: a foto a mais na Azul não tira o produto da Lista.
        EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $verde->id)->orderByDesc('ordem')->first()->delete();
        $antes = EstruturaProdutoVariacaoImagem::query()->count();

        $q = $this->enviar($p['produto'], $azul, [$this->jpg('sala.jpg')])->assertOk()->json();

        $nova = EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $azul->id)->orderByDesc('ordem')->firstOrFail();
        $this->assertSame('sala.jpg', $nova->nome_original);
        $this->assertTrue(Storage::disk(VariacaoImagensService::DISCO)->exists($nova->caminho), 'o arquivo ficou no disco privado');
        $this->assertSame($antes + 1, EstruturaProdutoVariacaoImagem::query()->count());
        $this->assertSame(['id' => (int) $nova->id, 'url' => "/portal/{$this->empresa->id}/trilha/fotos/{$azul->id}/{$nova->id}"],
            end($q['cores'][0]['fotos']));
        $this->assertSame([4, 0, 1, 2], [count($q['cores'][0]['fotos']), $q['cores'][0]['faltam'], count($q['cores'][1]['fotos']), $q['cores'][1]['faltam']]);
        $this->assertSame([true, null, []], [$q['produto']['editavel'], $q['produto']['motivo'], $q['avisos']]);
        $this->assertSame(['produto', 'cores', 'limites', 'avisos'], array_keys($q));
        $this->assertTextoNeutro($q);

        // O rastro da trilha (passo `fotos`) e o do serviço — os dois com o ator CLIENTE, nunca alguém da equipe.
        $evento = $this->eventosDaTrilha()->sole();
        $this->assertSame(['fotos', 'trilha', 'cliente', (int) $azul->id, 1], [$evento->getExtraProperty('passo'), $evento->getExtraProperty('via'),
            $evento->getExtraProperty('origem'), $evento->getExtraProperty('variacao_id'), $evento->getExtraProperty('quantidade')]);
        $this->assertSame([PortalUsuario::class, (int) $this->cliente->id], [$evento->causer_type, (int) $evento->causer_id]);
        $doServico = Activity::query()->get()->first(fn (Activity $a) => $a->getExtraProperty('evento') === 'imagens_variacao_enviadas');
        $this->assertSame(['cliente', PortalUsuario::class, (int) $this->cliente->id],
            [$doServico->getExtraProperty('origem'), $doServico->causer_type, (int) $doServico->causer_id]);

        // Ainda na Lista: nada vai ao robô.
        Queue::assertNothingPushed();
    }

    /** A foto que completa o produto o leva adiante (o mesmo gatilho da equipe, UMA vez) e o quadro fica só leitura. */
    public function test_a_foto_que_completa_o_produto_o_leva_adiante_e_o_quadro_fica_so_leitura(): void
    {
        $p = $this->produtoNaLista();
        $v = $p['variacoes'][0];
        $this->assertSame(['fotos (2 de 3)'], TrilhaDaEmpresa::faltasDoProduto($this->empresa, (int) $p['produto']->id));

        $q = $this->enviar($p['produto'], $v, [$this->jpg()])->assertOk()->json();

        $this->assertSame([false, FotosDoClienteNaTrilha::MSG_SO_LEITURA, [FotosDoClienteNaTrilha::AVISO_SEGUIU]],
            [$q['produto']['editavel'], $q['produto']['motivo'], $q['avisos']]);
        $this->assertSame(0, $q['cores'][0]['faltam']);
        $this->assertSame(1, $this->jobs(SincronizarProdutoDoPortalJob::class, $p['produto']));
        $this->assertSame(1, $this->jobs(PrepararProdutoNoPublicadorJob::class, $p['produto']));
        $this->assertTextoNeutro($q);

        // Daí em diante, só leitura: enviar e excluir são 409 e nada muda.
        $total = EstruturaProdutoVariacaoImagem::query()->count();
        $this->enviar($p['produto'], $v, [$this->jpg()])->assertStatus(409)->assertExactJson(['message' => FotosDoClienteNaTrilha::MSG_SO_LEITURA]);
        $this->comoCliente()->deleteJson($this->urlFotos($p['produto'], $v).'/'.$this->idsDasFotos($v)[0])
            ->assertStatus(409)->assertExactJson(['message' => FotosDoClienteNaTrilha::MSG_SO_LEITURA]);
        $this->assertSame($total, EstruturaProdutoVariacaoImagem::query()->count());
        $this->assertSame(1, $this->eventosDaTrilha()->count());
    }

    /**
     * O produto que já estava sendo montado e voltou à Lista (a equipe tirou uma foto): a foto nova fica guardada, mas não
     * entra sozinha no anúncio que já começou — o aviso diz para falar com a equipe.
     */
    public function test_produto_que_ja_estava_sendo_montado_avisa_que_a_foto_nova_precisa_da_equipe(): void
    {
        $p = $this->produtoNaLista(['Azul', 'Verde']);
        $this->grupoNoPublicador($this->empresa, $p);
        EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $p['variacoes'][1]->id)->orderByDesc('ordem')->first()->delete();

        $q = $this->enviar($p['produto'], $p['variacoes'][1], [$this->jpg()])->assertOk()->json();

        $this->assertSame([true, [FotosDoClienteNaTrilha::AVISO_JA_EM_PREPARO]], [$q['produto']['editavel'], $q['avisos']]);
        $this->assertTextoNeutro($q);
    }

    // ═══ Excluir ════════════════════════════════════════════════════════════

    public function test_o_cliente_exclui_a_foto_renumera_e_o_arquivo_sai_do_disco(): void
    {
        $p = $this->produtoNaLista(['Azul', 'Verde']);
        $verde = $p['variacoes'][1];
        // Duas fotos de verdade enviadas pelo cliente (com arquivo), numa variação que ainda não tem nenhuma.
        EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $verde->id)->delete();
        $enviadas = array_column($this->enviar($p['produto'], $verde, [$this->jpg('a.jpg'), $this->jpg('b.jpg')])->assertOk()->json('cores.1.fotos'), 'id');
        $capa = EstruturaProdutoVariacaoImagem::query()->findOrFail($enviadas[0]);
        $this->assertTrue(Storage::disk(VariacaoImagensService::DISCO)->exists($capa->caminho));

        $q = $this->comoCliente()->deleteJson($this->urlFotos($p['produto'], $verde)."/{$capa->id}")->assertOk()->json();

        $this->assertSame(['produto', 'cores', 'limites'], array_keys($q), 'o DELETE devolve o quadro, sem `avisos`');
        $this->assertSame([[$enviadas[1]], 2], [array_column($q['cores'][1]['fotos'], 'id'), $q['cores'][1]['faltam']]);
        $this->assertSame([$enviadas[1] => 0], EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $verde->id)->pluck('ordem', 'id')->all());
        $this->assertFalse(Storage::disk(VariacaoImagensService::DISCO)->exists($capa->caminho), 'o arquivo sai depois do commit');
        $this->assertTextoNeutro($q);

        $evento = $this->eventosDaTrilha()->last();
        $this->assertSame(['foto_excluida', 'cliente', (int) $verde->id, (int) $capa->id, PortalUsuario::class],
            [$evento->getExtraProperty('passo'), $evento->getExtraProperty('origem'), $evento->getExtraProperty('variacao_id'),
                $evento->getExtraProperty('imagem_id'), $evento->causer_type]);
    }

    // ═══ A guarda ═══════════════════════════════════════════════════════════

    /** Produto, variação e imagem de outra empresa (ou de outro produto/variação do endereço): 404, antes do corpo, e nada muda. */
    public function test_produto_variacao_ou_imagem_de_outra_empresa_e_404_e_nada_muda(): void
    {
        $a = $this->produtoNaLista(['Azul', 'Verde']);
        [$azul, $verde] = $a['variacoes'];
        $capaAzul = $this->comArquivo($this->capaDa($azul->id));
        $b = $this->produtoNaLista([null], 'Mesa Beta', 'MB');
        $outra = $this->empresaDaTrilha();
        $deFora = $this->produtoNaLista([null], 'Vaso de Fora', 'VX', $outra);
        $vFora = $deFora['variacoes'][0];
        $capaDeFora = $this->comArquivo($this->capaDa($vFora->id));
        $total = EstruturaProdutoVariacaoImagem::query()->count();
        $arquivos = Storage::disk(VariacaoImagensService::DISCO)->allFiles();

        // O quadro e a foto de outra loja pelo endereço desta.
        $this->comoCliente()->getJson($this->urlQuadro($deFora['produto']))->assertNotFound();
        $this->comoCliente()->get("/portal/{$this->empresa->id}/trilha/fotos/{$vFora->id}/{$capaDeFora->id}")->assertNotFound();
        $this->comoCliente()->get("/portal/{$this->empresa->id}/trilha/fotos/{$vFora->id}/{$capaDeFora->id}?original=1")->assertNotFound();
        // A imagem da Azul pela Verde; a linha sem arquivo.
        $this->comoCliente()->get("/portal/{$this->empresa->id}/trilha/fotos/{$verde->id}/{$capaAzul->id}")->assertNotFound();
        $this->comoCliente()->get("/portal/{$this->empresa->id}/trilha/fotos/{$verde->id}/{$this->capaDa($verde->id)->id}")->assertNotFound();
        // Enviar (corpo vazio de propósito: o 404 vem antes do 422): o produto de fora, a variação de fora, a de B por A.
        $this->comoCliente()->postJson($this->urlFotos($deFora['produto'], $vFora), [])->assertNotFound();
        $this->comoCliente()->postJson($this->urlFotos($a['produto'], $vFora), [])->assertNotFound();
        $this->comoCliente()->postJson($this->urlFotos($a['produto'], $b['variacoes'][0]), [])->assertNotFound();
        $this->enviar($a['produto'], $vFora, [$this->jpg()])->assertNotFound();
        // Excluir: a imagem da Azul pela Verde, a de fora pela variação de fora, a de B pelo produto A.
        $this->comoCliente()->deleteJson($this->urlFotos($a['produto'], $verde)."/{$capaAzul->id}")->assertNotFound();
        $this->comoCliente()->deleteJson($this->urlFotos($a['produto'], $vFora)."/{$capaDeFora->id}")->assertNotFound();
        $this->comoCliente()->deleteJson($this->urlFotos($deFora['produto'], $vFora)."/{$capaDeFora->id}")->assertNotFound();
        $this->comoCliente()->deleteJson($this->urlFotos($a['produto'], $b['variacoes'][0])."/{$this->capaDa($b['variacoes'][0]->id)->id}")->assertNotFound();
        // Ids de 19 dígitos: 404, nunca 500.
        $this->comoCliente()->getJson("/portal/{$this->empresa->id}/trilha/produtos/1234567890123456789/fotos")->assertNotFound();
        $this->comoCliente()->get("/portal/{$this->empresa->id}/trilha/fotos/1234567890123456789/1")->assertNotFound();
        $this->comoCliente()->deleteJson($this->urlFotos($a['produto'], $azul).'/1234567890123456789')->assertNotFound();

        // O cliente da OUTRA loja pelo endereço desta: o `portal.auth` recusa a escrita (e a leitura volta ao Início dele).
        $doOutro = $this->clienteDoPortal($outra);
        $this->outroCliente($outra, $doOutro)->post($this->urlFotos($a['produto'], $azul), ['imagens' => [$this->jpg()]], ['Accept' => 'application/json'])
            ->assertForbidden();
        $this->outroCliente($outra, $doOutro)->get($this->urlQuadro($a['produto']))->assertRedirect();

        $this->assertSame($total, EstruturaProdutoVariacaoImagem::query()->count());
        $this->assertSame($arquivos, Storage::disk(VariacaoImagensService::DISCO)->allFiles());
        $this->assertSame(0, $this->eventosDaTrilha()->count());
    }

    /** Sem as duas chaves, as quatro rotas não existem (404) — também com o corpo vazio. */
    public function test_sem_as_duas_chaves_as_quatro_rotas_sao_404(): void
    {
        foreach (['sem a chave de trabalho' => true, 'fora da trilha' => false] as $caso => $naTrilha) {
            $empresa = $this->empresaDaTrilha(trabalho: false);
            if (! $naTrilha) {
                config(['trilha.empresas' => array_values(array_diff(config('trilha.empresas'), [$empresa->id]))]);
            }
            $p = $this->produtoNaLista([null], 'Vaso '.$caso, 'V'.Str::random(4), $empresa);
            $v = $p['variacoes'][0];
            $capa = $this->comArquivo($this->capaDa($v->id));
            $cliente = $this->clienteDoPortal($empresa);
            $base = "/portal/{$empresa->id}/trilha";

            $this->outroCliente($empresa, $cliente)->getJson("{$base}/produtos/{$p['produto']->id}/fotos")->assertNotFound();
            $this->outroCliente($empresa, $cliente)->get("{$base}/fotos/{$v->id}/{$capa->id}")->assertNotFound();
            $this->outroCliente($empresa, $cliente)->post("{$base}/produtos/{$p['produto']->id}/variacoes/{$v->id}/fotos",
                ['imagens' => [$this->jpg()]], ['Accept' => 'application/json'])->assertNotFound();
            $this->outroCliente($empresa, $cliente)->deleteJson("{$base}/produtos/{$p['produto']->id}/variacoes/{$v->id}/fotos/{$capa->id}")->assertNotFound();
            $this->assertSame(2, EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $v->id)->count(), $caso);
        }
        $this->assertSame(0, $this->eventosDaTrilha()->count());
    }

    /**
     * Fora da Lista (cadastro completo) e com a trava do cadastro (um item que o leva já andou): o quadro diz só leitura,
     * e enviar e excluir são 409 `{message}` SEM `travado` — antes do corpo (o envio vazio também é 409, não 422).
     */
    public function test_produto_que_andou_ou_fora_da_lista_e_409_e_nada_grava(): void
    {
        $completo = $this->produtoCompleto($this->empresa, 'Vaso Completo', 'VC');
        $naLista = $this->produtoNaLista([null], 'Vaso Andou', 'VD');
        EstruturaAnuncio::create(['oferta_id' => $naLista['simples'][0]->id, 'tipo' => EstruturaAnuncio::TIPO_CLASSICO,
            'status' => EstruturaAnuncio::STATUS_ATIVO, 'codigo_mlb' => 'MLB1234567890', 'titulo' => 'Vaso']);
        $this->assertNotSame([], TrilhaDaEmpresa::faltasDoProduto($this->empresa, (int) $naLista['produto']->id), 'continua na Lista: só a trava segura');
        $total = EstruturaProdutoVariacaoImagem::query()->count();

        foreach (['fora da Lista' => $completo, 'andou' => $naLista] as $caso => $p) {
            $v = $p['variacoes'][0];
            $q = $this->comoCliente()->getJson($this->urlQuadro($p['produto']))->assertOk()->json('produto');
            $this->assertSame([false, FotosDoClienteNaTrilha::MSG_SO_LEITURA], [$q['editavel'], $q['motivo']], $caso);

            $this->enviar($p['produto'], $v, [$this->jpg()])->assertStatus(409)->assertExactJson(['message' => FotosDoClienteNaTrilha::MSG_SO_LEITURA]);
            $this->comoCliente()->postJson($this->urlFotos($p['produto'], $v), [])->assertStatus(409);
            $this->comoCliente()->deleteJson($this->urlFotos($p['produto'], $v).'/'.$this->capaDa($v->id)->id)
                ->assertStatus(409)->assertExactJson(['message' => FotosDoClienteNaTrilha::MSG_SO_LEITURA]);
        }

        $this->assertSame($total, EstruturaProdutoVariacaoImagem::query()->count());
        $this->assertSame([], Storage::disk(VariacaoImagensService::DISCO)->allFiles());
        $this->assertSame(0, $this->eventosDaTrilha()->count());
        Queue::assertNothingPushed();
        $this->assertTextoNeutro(['motivo' => FotosDoClienteNaTrilha::MSG_SO_LEITURA]);
    }

    // ═══ 422 ════════════════════════════════════════════════════════════════

    /** O envio grande demais — o 413 do `post_max_size`, o corpo descartado e o `UPLOAD_ERR_INI_SIZE` — vira 422 `errors.imagens`. */
    public function test_o_envio_grande_demais_vira_422_errors_imagens_no_caminho_do_cliente(): void
    {
        $p = $this->produtoNaLista();
        $v = $p['variacoes'][0];
        $grande = VariacaoImagensService::MENSAGEM_GRANDE_DEMAIS;

        // Acima do `post_max_size`: o `ValidatePostSize` lança o 413 mudo e o `bootstrap/app.php` o traduz.
        $this->comoCliente()->call('POST', $this->urlFotos($p['produto'], $v), [], [], [], [
            'CONTENT_LENGTH' => 50 * 1024 * 1024, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'multipart/form-data; boundary=x',
        ])->assertStatus(422)->assertJsonPath('errors.imagens.0', $grande)->assertJsonPath('message', $grande);

        // Abaixo do `post_max_size`, mas grande e sem arquivo (o PHP do servidor descartou): a mesma mensagem.
        $this->comoCliente()->call('POST', $this->urlFotos($p['produto'], $v), [], [], [], [
            'CONTENT_LENGTH' => 3 * 1024 * 1024, 'HTTP_ACCEPT' => 'application/json',
        ])->assertStatus(422)->assertJsonPath('errors.imagens.0', $grande);

        // O arquivo acima do `upload_max_filesize` chega marcado inválido: a mesma mensagem.
        $caminho = tempnam(sys_get_temp_dir(), 'img');
        $this->comoCliente()->post($this->urlFotos($p['produto'], $v),
            ['imagens' => [new UploadedFile($caminho, 'grande.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true)]], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.imagens.0', $grande)->assertJsonPath('message', $grande);
        @unlink($caminho);

        $this->assertSame(2, EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $v->id)->count());
        $this->assertSame(0, $this->eventosDaTrilha()->count());
    }

    /** Tipo pelo CONTEÚDO, tamanho, quantidade e o teto da variação: 422 nas regras do Portal, sempre em JSON, e nada fica. */
    public function test_tipo_tamanho_e_quantidade_sao_422_e_nada_fica(): void
    {
        $p = $this->produtoNaLista();
        $v = $p['variacoes'][0];

        $erros = $this->enviar($p['produto'], $v, [$this->arquivoReal('foto.jpg', '<?php echo 1; ?>')])->assertStatus(422)->json();
        $this->assertSame(['A imagem 1 não está num formato aceito. Envie JPG, PNG ou WebP.'], $erros['errors']['imagens.0'] ?? null);
        $this->assertSame('A imagem 1 não está num formato aceito. Envie JPG, PNG ou WebP.', $erros['message'], 'só o 1º erro, sem o "(and N more)"');

        $erros = $this->enviar($p['produto'], $v, [$this->jpg(), UploadedFile::fake()->image('enorme.jpg')->size(11000)])->assertStatus(422)->json();
        $this->assertSame(['A imagem 2 passa de 10 MB; tente uma menor.'], $erros['errors']['imagens.1'] ?? null);

        $this->enviar($p['produto'], $v, array_map(fn () => $this->jpg(), range(1, 13)))
            ->assertStatus(422)->assertJsonPath('errors.imagens.0', 'Cada variação aceita até 12 imagens. Envie menos de uma vez.');
        // 2 + 11 passa do teto da variação: a remessa inteira é recusada (o serviço, tudo ou nada).
        $this->enviar($p['produto'], $v, array_map(fn () => $this->jpg(), range(1, 11)))
            ->assertStatus(422)->assertJsonPath('errors.imagens.0', 'Cada variação aceita até 12 imagens. Esta já tem 2; envie no máximo 10.');
        // Sem arquivo nenhum — e sem o `Accept` de JSON: o 422 continua em JSON (nunca o redirect da sessão).
        $this->comoCliente()->post($this->urlFotos($p['produto'], $v), [])
            ->assertStatus(422)->assertJsonPath('errors.imagens.0', 'Escolha ao menos uma imagem.');

        $this->assertSame(2, EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $v->id)->count());
        $this->assertSame([], Storage::disk(VariacaoImagensService::DISCO)->allFiles(), 'nenhum arquivo órfão');
        $this->assertSame(0, $this->eventosDaTrilha()->count());
        $this->assertTextoNeutro($erros);
    }

    // ═══ R9: domínio do cliente, CSRF, a página e a equipe ══════════════════

    /** No domínio do cliente as quatro rotas existem (allowlist) — e só elas: a foto da equipe continua 404 lá. */
    public function test_o_dominio_do_cliente_deixa_as_quatro_rotas_passarem(): void
    {
        $p = $this->produtoNaLista();
        $v = $p['variacoes'][0];
        $capa = $this->comArquivo($this->capaDa($v->id));
        config(['portal.dominio_cliente' => 'cliente.teste']);
        $base = "http://cliente.teste/portal/{$this->empresa->id}/trilha";

        $this->comoCliente()->getJson("{$base}/produtos/{$p['produto']->id}/fotos")->assertOk()->assertJsonPath('produto.editavel', true);
        $this->comoCliente()->get("{$base}/fotos/{$v->id}/{$capa->id}")->assertOk();
        $nova = $this->comoCliente()->post("{$base}/produtos/{$p['produto']->id}/variacoes/{$v->id}/fotos", ['imagens' => [$this->jpg()]],
            ['Accept' => 'application/json'])->assertOk()->json('cores.0.fotos.2.id');
        // O envio completou o produto (3 de 3): a exclusão chega ao controller e responde o 409 dele — não o 404 do domínio.
        $this->comoCliente()->deleteJson("{$base}/produtos/{$p['produto']->id}/variacoes/{$v->id}/fotos/{$nova}")->assertStatus(409);

        // A rota de foto da EQUIPE não existe no domínio do cliente (nem para o Dev).
        $this->actingAs($this->dev())->get("http://cliente.teste/trilha/{$this->empresa->id}/fotos/{$v->id}/{$capa->id}")->assertNotFound();

        // A lista: cada linha casa só o caminho inteiro, com dígitos.
        foreach (['portal/7/trilha/produtos/91/fotos', 'portal/7/trilha/fotos/301/5501', 'portal/7/trilha/produtos/91/variacoes/301/fotos',
            'portal/7/trilha/produtos/91/variacoes/301/fotos/5501'] as $caminho) {
            $this->assertTrue(RestringeDominioDoPortal::liberado($caminho), $caminho);
        }
        foreach (['portal/7/trilha/produtos/abc/fotos', 'portal/7/trilha/produtos/91/fotos/1', 'portal/7/trilha/fotos/301',
            'portal/7/trilha/fotos/301/5501/x', 'portal/7/trilha/produtos/91/variacoes/301', 'portal/7/trilha/produtos/91',
            'trilha/7/fotos/301/5501', 'portal/7/trilha/1'] as $caminho) {
            $this->assertFalse(RestringeDominioDoPortal::liberado($caminho), $caminho);
        }
        // E toda rota da porta do cliente da trilha está na lista (rota nova sem linha nasce 404 lá — e no localhost passa).
        // O `{item}` da aprovação (4e) é a chave do item da trilha, não um número.
        $rotas = 0;
        foreach (Route::getRoutes() as $rota) {
            if (str_starts_with($rota->uri(), 'portal/{'.PortalContexto::PARAMETRO.'}/trilha')) {
                $rotas++;
                $caminho = preg_replace('/\{\w+\}/', '7', str_replace('{item}', 's-7', $rota->uri()));
                $this->assertTrue(RestringeDominioDoPortal::liberado($caminho), "{$rota->getName()} ({$rota->uri()})");
            }
        }
        $this->assertSame(9, $rotas, 'a página, as quatro rotas das fotos (4f) e as quatro da aprovação (4e)');
    }

    /** O CSRF vale como em produção: sem o token, 419 e nada grava; com ele, passa. */
    public function test_o_csrf_vale_nas_escritas_do_cliente(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $p = $this->produtoNaLista(['Azul', 'Verde']);
        $v = $p['variacoes'][1];
        // A Verde com 1: o envio de uma foto não completa o produto (a exclusão depois ainda vale).
        EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $v->id)->orderByDesc('ordem')->first()->delete();
        $capa = $this->capaDa($v->id);

        $this->comoCliente()->post($this->urlFotos($p['produto'], $v), ['imagens' => [$this->jpg()]], ['Accept' => 'application/json'])->assertStatus(419);
        $this->comoCliente()->deleteJson($this->urlFotos($p['produto'], $v)."/{$capa->id}")->assertStatus(419);
        $this->assertSame(1, EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $v->id)->count());
        $this->assertSame(0, $this->eventosDaTrilha()->count());

        // Com o token da sessão (o que a página manda), passa.
        $this->comoCliente()->withSession(['_token' => 'token-da-aba'])
            ->post($this->urlFotos($p['produto'], $v), ['imagens' => [$this->jpg()], '_token' => 'token-da-aba'], ['Accept' => 'application/json'])
            ->assertOk();
        $this->comoCliente()->withSession(['_token' => 'token-da-aba'])
            ->deleteJson($this->urlFotos($p['produto'], $v)."/{$capa->id}", [], ['X-CSRF-TOKEN' => 'token-da-aba'])
            ->assertOk();
        $this->assertSame(2, $this->eventosDaTrilha()->count());
    }

    /**
     * `cliente_escrita.fotos` chega SÓ ao cliente de verdade, com as duas chaves: não à porta da equipe (nem ao "Ver como:
     * Cliente" dela, que é o mesmo dado), não ao modo equipe do Portal e não à loja sem a chave de trabalho. E a equipe
     * dentro do Portal não usa as rotas do cliente (404): não há cliente para ser o ator.
     */
    public function test_cliente_escrita_so_para_o_cliente_de_verdade_e_a_equipe_no_portal_nao_escreve(): void
    {
        $p = $this->produtoNaLista(['Azul', 'Verde']);
        $v = $p['variacoes'][1];
        $capa = $this->comArquivo($this->capaDa($v->id));

        $cliente = $this->props($this->comoCliente()->get("/portal/{$this->empresa->id}/trilha")->assertOk());
        $base = "/portal/{$this->empresa->id}/trilha/produtos/__PRODUTO__/fotos";
        $this->assertSame(['fotos' => $base], $cliente['cliente_escrita']);
        $this->assertNull($cliente['escrita'], 'a escrita da equipe continua só da equipe');
        // O endereço é o do quadro: com o id no lugar do marcador, abre.
        $this->comoCliente()->getJson(str_replace('__PRODUTO__', (string) $p['produto']->id, $base))->assertOk()->assertJsonPath('produto.id', (int) $p['produto']->id);

        // A porta da equipe: a chave existe, nula (o "Ver como: Cliente" é do navegador, sobre este mesmo dado).
        $equipe = $this->props($this->actingAs($this->dev())->get("/trilha/{$this->empresa->id}")->assertOk());
        $this->assertArrayHasKey('cliente_escrita', $equipe);
        $this->assertNull($equipe['cliente_escrita']);
        $this->app['auth']->forgetGuards();

        // O modo equipe do Portal: a página sem `cliente_escrita`, e as quatro rotas não existem para ela.
        $this->entrarComoEquipe($this->empresa);
        $naPassagem = $this->props($this->get("/portal/{$this->empresa->id}/trilha")->assertOk());
        $this->assertTrue($naPassagem['sessao_equipe']);
        $this->assertNull($naPassagem['cliente_escrita']);
        $this->getJson($this->urlQuadro($p['produto']))->assertNotFound();
        $this->get("/portal/{$this->empresa->id}/trilha/fotos/{$v->id}/{$capa->id}")->assertNotFound();
        $this->post($this->urlFotos($p['produto'], $v), ['imagens' => [$this->jpg()]], ['Accept' => 'application/json'])->assertNotFound();
        $this->deleteJson($this->urlFotos($p['produto'], $v)."/{$capa->id}")->assertNotFound();
        $this->assertSame(2, EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $v->id)->count());

        // A rede: uma sessão de equipe com a do cliente "por baixo" (o Portal já derruba uma ao abrir a outra — isto é o
        // caso de ele um dia falhar). Quem está ali é a equipe: 404, e nada sai em nome do cliente.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $membro = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->withSession([
            PortalContexto::SESSAO_EQUIPE                     => $membro->id,
            PortalContexto::SESSAO_EMPRESAS_EQUIPE            => [$this->empresa->id => 'passagem'],
            $this->app['auth']->guard('portal')->getName() => $this->cliente->id,
            'portal_empresa_id'                               => $this->empresa->id,
        ]);
        $this->post($this->urlFotos($p['produto'], $v), ['imagens' => [$this->jpg()]], ['Accept' => 'application/json'])->assertNotFound();
        $this->deleteJson($this->urlFotos($p['produto'], $v)."/{$capa->id}")->assertNotFound();
        $this->assertSame(2, EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $v->id)->count());
        $this->assertSame(0, $this->eventosDaTrilha()->count());

        // A loja na trilha, mas sem a chave de trabalho: o cliente lê a trilha, sem escrita.
        $this->flushSession();
        $soLeitura = $this->empresaDaTrilha(trabalho: false);
        $props = $this->props($this->outroCliente($soLeitura)->get("/portal/{$soLeitura->id}/trilha")->assertOk());
        $this->assertSame([false, null], [$props['sessao_equipe'], $props['cliente_escrita']]);
    }

    /** A rota de foto da EQUIPE não muda: o retrato da gaveta segue nela, ela abre para o Dev e não abre com a sessão do cliente. */
    public function test_a_rota_de_foto_da_equipe_nao_muda(): void
    {
        $p = $this->produtoNaLista(['Azul', 'Verde']);
        $v = $p['variacoes'][0];
        $capa = $this->comArquivo($this->capaDa($v->id));
        $daEquipe = "/trilha/{$this->empresa->id}/fotos/{$v->id}/{$capa->id}";

        $dev = $this->dev();
        $retrato = $this->actingAs($dev)->getJson("/trilha/{$this->empresa->id}/produtos/{$p['produto']->id}/cadastro")->assertOk()->json();
        $this->assertSame([$daEquipe, "{$daEquipe}?original=1"], [$retrato['variacoes'][0]['fotos'][0]['url'], $retrato['variacoes'][0]['fotos'][0]['url_original']]);
        $this->actingAs($dev)->get($daEquipe)->assertOk();
        $this->app['auth']->forgetGuards();

        // O cliente (só o guard `portal`) não entra na porta da equipe; e a dele serve a mesma foto.
        $this->comoCliente()->get($daEquipe)->assertRedirect();
        $resposta = $this->comoCliente()->get("/portal/{$this->empresa->id}/trilha/fotos/{$v->id}/{$capa->id}")->assertOk();
        $this->assertSame(['image/jpeg', 'nosniff'], [$resposta->headers->get('Content-Type'), $resposta->headers->get('X-Content-Type-Options')]);
        $this->assertStringContainsString('private', (string) $resposta->headers->get('Cache-Control'));
        $original = $this->comoCliente()->get("/portal/{$this->empresa->id}/trilha/fotos/{$v->id}/{$capa->id}?original=1")->assertOk();
        $this->assertSame(Storage::disk(VariacaoImagensService::DISCO)->get($capa->caminho), $original->streamedContent());
        $this->assertStringContainsString('private', (string) $original->headers->get('Cache-Control'));
    }

    // ─── apoio ───

    /** O cliente da loja, na sessão do Portal (o guard `portal`), com a aba nesta empresa. */
    private function comoCliente(): static
    {
        return $this->outroCliente($this->empresa, $this->cliente);
    }

    /**
     * Entra no Portal com OUTRA pessoa. Os guards ficam vivos entre os pedidos do mesmo teste (em produção cada pedido é
     * um processo novo): sem esquecê-los, o guard `portal` devolveria o cliente do pedido anterior, e o teste de "cliente de
     * outra loja" provaria a coisa errada.
     */
    private function outroCliente(Company $empresa, ?PortalUsuario $usuario = null): static
    {
        $this->app['auth']->forgetGuards();

        return $this->entrarNoPortal($empresa, $usuario);
    }

    /**
     * Um produto NA LISTA: completo pela régua, menos uma foto na 1ª cor ("fotos (2 de 3)").
     *
     * @param  list<?string>  $cores
     * @return array{produto: EstruturaProduto, variacoes: list<EstruturaProdutoVariacao>, simples: list<\App\Models\EstruturaOferta>}
     */
    private function produtoNaLista(array $cores = [null], string $nome = 'Vaso Jatobá', string $codigo = 'VJ', ?Company $empresa = null): array
    {
        $p = $this->produtoCompleto($empresa ?? $this->empresa, $nome, $codigo, $cores);
        EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $p['variacoes'][0]->id)->orderByDesc('ordem')->first()->delete();
        if (count($cores) > 1) {
            // As outras cores com 2 também: a Verde fica com 1 falta (o quadro mostra), a Azul volta a 3.
            EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $p['variacoes'][1]->id)->orderByDesc('ordem')->first()->delete();
            $this->fotos($p['variacoes'][0], 1, 2);
        }

        return $p;
    }

    /** Cadeira com cor E tamanho: Azul P, Azul M, Verde P — sem foto nenhuma (na Lista). */
    private function produtoComCorETamanho(): EstruturaProduto
    {
        $produto = EstruturaProduto::create(['company_id' => $this->empresa->id, 'codigo' => 'CJ', 'nome' => 'Cadeira Jatobá']);
        foreach ([['Azul', 'P'], ['Azul', 'M'], ['Verde', 'P']] as $i => [$cor, $tamanho]) {
            $v = EstruturaProdutoVariacao::create(['produto_id' => $produto->id, 'company_id' => $this->empresa->id, 'ordem' => $i + 1,
                'codigo' => "CJ-{$cor}-{$tamanho}", 'eixo' => 'cor', 'valor' => $cor, 'eixo_2' => 'tamanho', 'valor_2' => $tamanho, 'custo' => 100]);
            EstruturaProdutoVolume::create(['variacao_id' => $v->id, 'ordem' => 1, 'comprimento' => 50, 'largura' => 40, 'altura' => 30, 'peso' => 5]);
        }

        return $produto->fresh();
    }

    /** Linhas de foto (sem arquivo) no fim da galeria da variação. */
    private function fotos(EstruturaProdutoVariacao $v, int $quantas, int $aPartirDe = 0): void
    {
        for ($f = 0; $f < $quantas; $f++) {
            EstruturaProdutoVariacaoImagem::create([
                'company_id' => $v->company_id, 'produto_id' => $v->produto_id, 'variacao_id' => $v->id,
                'caminho' => 'estrutura/'.Str::random(8).'.jpg', 'nome_original' => "extra{$f}.jpg", 'mime' => 'image/jpeg',
                'tamanho' => 1000, 'ordem' => $aPartirDe + $f,
            ]);
        }
    }

    /** @return list<int> */
    private function idsDasFotos(EstruturaProdutoVariacao $v): array
    {
        return EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $v->id)->orderBy('ordem')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function urlQuadro(EstruturaProduto $produto): string
    {
        return "/portal/{$this->empresa->id}/trilha/produtos/{$produto->id}/fotos";
    }

    private function urlFotos(EstruturaProduto $produto, EstruturaProdutoVariacao $v): string
    {
        return "/portal/{$this->empresa->id}/trilha/produtos/{$produto->id}/variacoes/{$v->id}/fotos";
    }

    private function enviar(EstruturaProduto $produto, EstruturaProdutoVariacao $v, array $arquivos): TestResponse
    {
        return $this->comoCliente()->post($this->urlFotos($produto, $v), ['imagens' => $arquivos], ['Accept' => 'application/json']);
    }

    /** Imagens DIFERENTES (tamanhos distintos): o conteúdo igual não importa aqui, mas evita confusão com a régua do sha256. */
    private function jpg(string $nome = 'foto.jpg'): UploadedFile
    {
        static $n = 0;
        $n++;

        return UploadedFile::fake()->image($nome, 600 + $n, 400 + $n);
    }

    /** Arquivo de verdade em disco temporário: o `UploadedFile::fake()` deduz o MIME pelo NOME; aqui importa o CONTEÚDO. */
    private function arquivoReal(string $nome, string $conteudo): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, $nome, null, null, true);
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

    /** O Dev (admin, `is_dev`): entra na porta da equipe e escreve no Portal de qualquer empresa. */
    private function dev(): User
    {
        $usuario = User::factory()->create(['role' => 'admin', 'active' => true, 'name' => 'Rafael']);
        $usuario->forceFill(['is_dev' => true])->save();

        return $usuario->refresh();
    }

    /** A sessão de equipe no Portal: a passagem de 60 s emitida pelo admin, consumida na porta da equipe. */
    private function entrarComoEquipe(Company $empresa): void
    {
        $membro = User::factory()->create(['role' => 'admin', 'active' => true]);
        $ticket = app(PortalEquipeService::class)->emitir($membro, $empresa, '127.0.0.1');
        $this->get(route('portal.equipe.entrar', ['t' => $ticket]))->assertRedirect();
    }

    /** As props da página Inertia como o navegador as recebe. */
    private function props(TestResponse $resposta): array
    {
        return json_decode(json_encode($resposta->viewData('page')), true)['props'];
    }

    private function eventosDaTrilha()
    {
        return Activity::query()->where('log_name', 'portal')->orderBy('id')->get()
            ->filter(fn (Activity $a) => $a->getExtraProperty('evento') === 'trilha_cadastro_salvo')->values();
    }

    /** Quantos Jobs desta classe a fila recebeu para o produto. */
    private function jobs(string $classe, EstruturaProduto $produto): int
    {
        return Queue::pushed($classe, fn ($j) => (int) $j->estruturaProdutoId === (int) $produto->id)->count();
    }

    /** Todo TEXTO que o cliente lê (as URLs não) sem "Mercado Livre", "Publicador", "Portal" nem "agente". */
    private function assertTextoNeutro(array $dados, string $onde = ''): void
    {
        foreach ($dados as $chave => $valor) {
            if (is_array($valor)) {
                $this->assertTextoNeutro($valor, "{$onde}.{$chave}");
            } elseif (is_string($valor) && $chave !== 'url') {
                foreach (self::PROIBIDAS as $palavra) {
                    $this->assertStringNotContainsStringIgnoringCase($palavra, $valor, "{$onde}.{$chave}");
                }
            }
        }
    }
}
