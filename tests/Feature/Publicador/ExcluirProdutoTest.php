<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\PubFilaPublicacaoItem;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Publicador\ExcluirProdutoService;
use App\Services\Publicador\Fila\ResumoRapidoService;
use App\Support\Publicador\EditorEmUso;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Publicador\Lote\CenarioDaFila;
use Tests\TestCase;

/**
 * Excluir produtos do Publicador (pedido do usuário, 10/10/2026: limpar os produtos de teste). Só o que nunca
 * foi publicado e já está solto do Portal: o ligado voltaria no próximo Sincronizar, e o que pode existir no
 * Mercado Livre é o único registro do que foi enviado.
 */
class ExcluirProdutoTest extends TestCase
{
    use CenarioDaFila;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:00', 'America/Sao_Paulo'));
        Queue::fake();
        Storage::fake('local');
        $this->withoutVite();
        $this->montarFila(3);
    }

    private function svc(): ExcluirProdutoService
    {
        return app(ExcluirProdutoService::class);
    }

    private function alvo(): array
    {
        return ['mlb_empresa' => null, 'company' => $this->empresa, 'chave' => $this->conta()];
    }

    /** Solta o produto do Portal, como a exclusão da oferta lá deixaria (D27). */
    private function soltar(PubProduto ...$produtos): void
    {
        foreach ($produtos as $p) {
            $p->update(['oferta_id' => null, 'estrutura_produto_id' => null]);
        }
    }

    private function rota(string $nome): string
    {
        return route('mlb.anuncios.publicador.produtos.'.$nome, ['conta' => $this->conta()]);
    }

    /** Uma publicação do rascunho com um item no estado dado. */
    private function publicacaoCom(PubProduto $p, string $statusDoItem, ?string $mlb = null, int $tentativas = 1, ?int $http = null, string $status = PubPublicacao::FAILED): PubPublicacao
    {
        $pub = PubPublicacao::create([
            'rascunho_id' => $this->rascunhoDe($p)->id, 'revisao' => 1, 'modelo_publicacao' => 'user_products', 'plano_hash' => str_repeat('a', 64),
            'status' => $status, 'chave_idempotencia' => (string) Str::uuid(), 'iniciada_em' => now(), 'concluida_em' => now(),
        ]);
        PubPublicacaoItem::create([
            'publicacao_id' => $pub->id, 'indice' => 0, 'listing_type_id' => 'gold_special', 'variante_chave' => '__single__',
            'status' => $statusDoItem, 'ml_item_id' => $mlb, 'tentativas' => $tentativas, 'http_status' => $http,
        ]);

        return $pub;
    }

    private function regra(array $resposta, PubProduto $p): ?string
    {
        return collect($resposta['recusados'])->firstWhere('id', $p->id)['regra'] ?? null;
    }

    // ═══ O que sai ══════════════════════════════════════════════════════════

    public function test_produto_solto_sai_com_o_rascunho_as_fotos_e_o_arquivo_do_disco(): void
    {
        [$a, $b] = $this->cadeiras;
        $this->soltar($a);
        $r = $this->rascunhoDe($a);
        $foto = $r->imagens()->firstOrFail();
        Storage::disk('local')->put($foto->caminho, 'bytes da foto');
        $filhas = ['pub_rascunho_alvos', 'pub_rascunho_atributos', 'pub_variantes', 'pub_imagens'];
        foreach ($filhas as $tabela) {
            $this->assertGreaterThan(0, DB::table($tabela)->where('rascunho_id', $r->id)->count(), "cenário sem linhas em {$tabela}");
        }

        $resposta = $this->svc()->excluir($this->alvo(), [$a->id], $this->admin);

        $this->assertSame(['excluidos' => [$a->id], 'recusados' => [], 'nao_encontrados' => 0], $resposta);
        $this->assertNull(PubProduto::find($a->id));
        $this->assertNull(PubRascunho::find($r->id));
        foreach ($filhas as $tabela) {
            $this->assertSame(0, DB::table($tabela)->where('rascunho_id', $r->id)->count(), "{$tabela} não caiu na cascata");
        }
        Storage::disk('local')->assertMissing($foto->caminho);
        $this->assertNotNull(PubProduto::find($b->id), 'os outros ficam');

        $log = Activity::where('log_name', 'publicador')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($this->admin->id, $log->causer_id);
        $this->assertSame($a->id, $log->getExtraProperty('produto_id'));
        $this->assertSame('CAD-01', $log->getExtraProperty('sku'));
    }

    public function test_produto_sem_rascunho_e_publicacao_recusada_pelo_ml_tambem_saem(): void
    {
        [$a] = $this->cadeiras;
        $this->soltar($a);
        $recemCriado = PubProduto::create(['company_id' => $this->empresa->id, 'sku' => 'NOVO-1', 'nome' => 'Recém-criado', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        $recusada = $this->publicacaoCom($a, PubPublicacaoItem::FAILED, null, 1, 400);

        $resposta = $this->svc()->excluir($this->alvo(), [$a->id, $recemCriado->id], $this->admin);

        $this->assertEqualsCanonicalizing([$a->id, $recemCriado->id], $resposta['excluidos']);
        $this->assertNull(PubPublicacao::find($recusada->id), 'a publicação que o ML recusou (4xx) sai junto');
    }

    public function test_a_previa_nao_toca_em_nada_e_diz_o_que_sai_e_o_que_fica(): void
    {
        [$a, $b] = $this->cadeiras;
        $this->soltar($a);

        $p = $this->svc()->previa($this->alvo(), [$a->id, $b->id, 999999]);

        $this->assertSame([['id' => $a->id, 'sku' => 'CAD-01', 'nome' => $a->nome, 'kit' => false, 'fotos' => 1]], $p['excluiveis']);
        $this->assertSame('EXC-01', $p['recusados'][0]['regra']);
        $this->assertStringContainsString('Ainda existe no Portal do Cliente', $p['recusados'][0]['motivo']);
        $this->assertSame(['excluiveis' => 1, 'recusados' => 1, 'fotos' => 1], $p['totais']);
        $this->assertSame(1, $p['nao_encontrados']);
        $this->assertSame(3, PubProduto::count());
    }

    // ═══ O que fica ═════════════════════════════════════════════════════════

    public function test_ligado_ao_portal_fica_porque_voltaria(): void
    {
        [$a, $b] = $this->cadeiras;
        $b->update(['oferta_id' => null, 'estrutura_produto_id' => null]);
        // Só o vínculo de grupo (as cores de um produto do Portal) também conta como ligado.
        $portal = \App\Models\EstruturaProduto::create(['company_id' => $this->empresa->id, 'codigo' => 'GRUPO', 'nome' => 'Grupo']);
        $b->update(['estrutura_produto_id' => $portal->id]);

        $resposta = $this->svc()->excluir($this->alvo(), [$a->id, $b->id], $this->admin);

        $this->assertSame([], $resposta['excluidos']);
        $this->assertSame(['EXC-01', 'EXC-01'], array_column($resposta['recusados'], 'regra'));
        $this->assertSame(3, PubProduto::count());
    }

    public function test_o_que_pode_existir_no_mercado_livre_nunca_sai(): void
    {
        [$a, $b, $c] = $this->cadeiras;
        $this->soltar($a, $b, $c);
        $this->rascunhoDe($a)->update(['status' => PubRascunho::PUBLISHED]);
        // Item criado com o rascunho de volta a DRAFT: decide pelo fato, não pelo status.
        $criada = $this->publicacaoCom($b, PubPublicacaoItem::CREATED, 'MLB123', 1, 201, PubPublicacao::PARTIALLY_PUBLISHED);
        // Timeout/5xx depois de tentar: o ML pode ter criado.
        $this->publicacaoCom($c, PubPublicacaoItem::FAILED, null, 2, 500);

        $resposta = $this->svc()->excluir($this->alvo(), [$a->id, $b->id, $c->id], $this->admin);

        $this->assertSame([], $resposta['excluidos']);
        $this->assertSame('EXC-02', $this->regra($resposta, $a));
        $this->assertSame('EXC-02', $this->regra($resposta, $b));
        $this->assertSame('EXC-04', $this->regra($resposta, $c));
        $this->assertNotNull(PubPublicacao::find($criada->id));
        $this->assertSame(3, PubProduto::count());
    }

    public function test_item_pode_estar_no_ml_decide_pelo_fato(): void
    {
        $pode = fn (...$a) => ExcluirProdutoService::itemPodeEstarNoMl(...$a);

        $this->assertTrue($pode(PubPublicacaoItem::CREATED, 'MLB1', 1, 201));
        $this->assertTrue($pode(PubPublicacaoItem::FAILED, 'MLB1', 1, 400), 'com MLB gravado, pode');
        $this->assertTrue($pode(PubPublicacaoItem::SENT, null, 1, null));
        $this->assertTrue($pode(PubPublicacaoItem::UNKNOWN, null, 1, null));
        $this->assertTrue($pode(PubPublicacaoItem::FAILED, null, 2, 500));
        $this->assertTrue($pode(PubPublicacaoItem::FAILED, null, 1, null), 'sem resposta nenhuma');
        $this->assertFalse($pode(PubPublicacaoItem::FAILED, null, 1, 400), 'recusa certa');
        $this->assertFalse($pode(PubPublicacaoItem::FAILED, null, 1, 422));
        $this->assertFalse($pode(PubPublicacaoItem::FAILED, null, 0, null), 'nem saiu daqui');
        $this->assertFalse($pode(PubPublicacaoItem::PENDING, null, 0, null));
    }

    public function test_publicando_na_fila_conferindo_ou_com_editor_aberto_espera(): void
    {
        [$a, $b, $c] = $this->cadeiras;
        $d = $this->outraCadeira(4);
        // Na fila de publicação (agendado): o produto ainda está ligado ao Portal aqui, então a fila vem antes.
        $this->conferirTodas([$a]);
        $this->agendar([$a])->assertSuccessful();
        $this->soltar($a, $b, $c, $d);
        $this->rascunhoDe($b)->update(['status' => PubRascunho::PUBLISHING]);
        Cache::put(ResumoRapidoService::chaveConferindo($c->id), 1, now()->addMinutes(5));
        EditorEmUso::marcar($d->id);

        $resposta = $this->svc()->excluir($this->alvo(), [$a->id, $b->id, $c->id, $d->id], $this->admin);

        $this->assertSame([], $resposta['excluidos']);
        $this->assertSame(['EXC-05', 'EXC-03', 'EXC-07', 'EXC-08'], [$this->regra($resposta, $a), $this->regra($resposta, $b), $this->regra($resposta, $c), $this->regra($resposta, $d)]);

        // O editor fechou há mais tempo: agora sai.
        $this->travel(10)->minutes();
        $this->assertSame([$d->id], $this->svc()->excluir($this->alvo(), [$d->id], $this->admin)['excluidos']);
    }

    public function test_item_de_fila_ja_terminado_fica_sem_vinculo_e_o_produto_sai(): void
    {
        [$a] = $this->cadeiras;
        $this->conferirTodas([$a]);
        $this->agendar([$a])->assertSuccessful();
        $item = $this->itemDe($a);
        $item->update(['status' => PubFilaPublicacaoItem::CANCELADO, 'produto_ativo' => null]);
        $this->soltar($a);

        $resposta = $this->svc()->excluir($this->alvo(), [$a->id], $this->admin);

        $this->assertSame([$a->id], $resposta['excluidos']);
        $item = $item->fresh();
        $this->assertNotNull($item, 'o histórico da fila fica');
        $this->assertNull($item->produto_id);
    }

    // ═══ Kits da Fase N ═════════════════════════════════════════════════════

    public function test_base_de_kit_so_sai_com_o_kit_e_o_kit_sai_sozinho(): void
    {
        [$a, $b] = $this->cadeiras;
        $this->soltar($a, $b);
        $kit = PubProduto::create(['company_id' => $this->empresa->id, 'sku' => 'CAD-01-KIT2', 'nome' => 'Kit 2 Cadeiras', 'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'produto_base_id' => $a->id, 'quantidade_kit' => 2, 'fase' => 2]);

        // A base sozinha fica; a prévia com os dois diz que os dois saem (o kit conta como já fora).
        $so = $this->svc()->excluir($this->alvo(), [$a->id], $this->admin);
        $this->assertSame('EXC-06', $this->regra($so, $a));
        $this->assertSame($a->id, (int) $kit->fresh()->produto_base_id);
        $previa = $this->svc()->previa($this->alvo(), [$a->id, $kit->id]);
        $this->assertSame([$kit->id, $a->id], array_column($previa['excluiveis'], 'id'), 'o kit primeiro');
        $this->assertSame([true, false], array_column($previa['excluiveis'], 'kit'));

        // O kit sozinho sai e a base fica como estava.
        $outroKit = PubProduto::create(['company_id' => $this->empresa->id, 'sku' => 'CAD-02-KIT2', 'nome' => 'Kit 2', 'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'produto_base_id' => $b->id, 'quantidade_kit' => 2, 'fase' => 2]);
        $this->assertSame([$outroKit->id], $this->svc()->excluir($this->alvo(), [$outroKit->id], $this->admin)['excluidos']);
        $this->assertNotNull(PubRascunho::where('produto_id', $b->id)->first());

        // Base e kit juntos: saem os dois.
        $juntos = $this->svc()->excluir($this->alvo(), [$a->id, $kit->id], $this->admin);
        $this->assertSame([$kit->id, $a->id], $juntos['excluidos']);
    }

    public function test_kit_publicado_segura_a_base(): void
    {
        [$a] = $this->cadeiras;
        $this->soltar($a);
        $kit = PubProduto::create(['company_id' => $this->empresa->id, 'sku' => 'CAD-01-KIT2', 'nome' => 'Kit 2 Cadeiras', 'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'produto_base_id' => $a->id, 'quantidade_kit' => 2, 'fase' => 2]);
        // A tarefa de alavancas só nasce de produto publicado: é a defesa para o que o status não conta.
        DB::table('pub_tarefas')->insert(['produto_id' => $kit->id, 'conta_chave' => $this->conta(), 'tipo' => 'alavancas', 'status' => 'pendente',
            'itens' => '[]', 'checklist' => '[]', 'publicado_em' => now(), 'prazo' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);

        $resposta = $this->svc()->excluir($this->alvo(), [$a->id, $kit->id], $this->admin);

        $this->assertSame([], $resposta['excluidos']);
        $this->assertSame('EXC-02', $this->regra($resposta, $kit));
        $this->assertSame('EXC-06', $this->regra($resposta, $a));
    }

    // ═══ Escopo e rotas ═════════════════════════════════════════════════════

    public function test_produto_de_outra_conta_conta_como_inexistente(): void
    {
        $outra = Company::factory()->create();
        $alheio = PubProduto::create(['company_id' => $outra->id, 'sku' => 'ALHEIO', 'nome' => 'De outra conta', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);

        $this->assertSame(1, $this->svc()->previa($this->alvo(), [$alheio->id])['nao_encontrados']);
        $resposta = $this->svc()->excluir($this->alvo(), [$alheio->id], $this->admin);

        $this->assertSame(['excluidos' => [], 'recusados' => [], 'nao_encontrados' => 1], $resposta);
        $this->assertNotNull(PubProduto::find($alheio->id));
    }

    public function test_as_rotas_sao_do_admin_com_throttle_e_conta_restrita(): void
    {
        foreach (['previa' => 'produtos.exclusao.previa', '' => 'produtos.exclusao'] as $sufixo => $nome) {
            $rota = Route::getRoutes()->getByName('mlb.anuncios.publicador.'.$nome);
            $this->assertNotNull($rota, $nome);
            $this->assertSame(['POST'], $rota->methods());
            $this->assertContains('role:admin', $rota->gatherMiddleware());
            $this->assertNotNull(collect($rota->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:') && str_contains($m, 'publicador.'.$nome)));
        }

        $consultor = User::factory()->create(['role' => 'consultor']);
        [$a] = $this->cadeiras;
        $this->soltar($a);
        $this->actingAs($consultor)->postJson($this->rota('exclusao'), ['produtos' => [$a->id]])->assertForbidden();
        $this->assertNotNull(PubProduto::find($a->id));

        $this->actingAs($this->admin)->postJson(route('mlb.anuncios.publicador.produtos.exclusao', ['conta' => 'company-999999']), ['produtos' => [$a->id]])->assertNotFound();
        $this->assertNotNull(PubProduto::find($a->id));
    }

    public function test_pela_tela_previa_exclusao_e_as_mensagens(): void
    {
        [$a, $b, $c] = $this->cadeiras;
        $this->soltar($a, $b);
        $sessao = $this->actingAs($this->admin);

        $sessao->postJson($this->rota('exclusao.previa'), ['produtos' => [$a->id, $b->id, $c->id]])
            ->assertOk()->assertJsonPath('totais.excluiveis', 2)->assertJsonPath('totais.recusados', 1)->assertJsonPath('recusados.0.regra', 'EXC-01');
        $this->assertSame(3, PubProduto::count(), 'a prévia não exclui');

        $r = $sessao->postJson($this->rota('exclusao'), ['produtos' => [$a->id, $b->id, $c->id]])->assertOk()->json();
        $this->assertSame('2 produtos excluídos. 1 não pôde ser excluído.', $r['mensagem']);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $r['excluidos']);
        $this->assertSame([$c->id], PubProduto::pluck('id')->all());

        // Nenhum pôde sair: 422, com os motivos.
        $sessao->postJson($this->rota('exclusao'), ['produtos' => [$c->id]])->assertUnprocessable()
            ->assertJsonPath('recusados.0.regra', 'EXC-01')->assertJsonPath('message', 'Nenhum produto pôde ser excluído.');
        // Já excluído (segunda vez) e lista inválida.
        $sessao->postJson($this->rota('exclusao'), ['produtos' => [$a->id]])->assertUnprocessable()->assertJsonPath('message', 'Estes produtos não existem mais.');
        foreach (['exclusao.previa', 'exclusao'] as $nome) {
            $sessao->postJson($this->rota($nome), [])->assertUnprocessable()->assertJsonPath('errors.produtos.0', 'Escolha ao menos um produto.');
            $sessao->postJson($this->rota($nome), ['produtos' => range(1, ExcluirProdutoService::MAXIMO + 1)])
                ->assertUnprocessable()->assertJsonPath('errors.produtos.0', 'Dá para excluir até 200 produtos de uma vez.');
        }
    }
}
