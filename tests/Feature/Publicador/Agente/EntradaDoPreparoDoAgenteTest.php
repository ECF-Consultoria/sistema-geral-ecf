<?php

namespace Tests\Feature\Publicador\Agente;

use App\Jobs\Publicador\GerarPreparoIaJob;
use App\Models\EstruturaProduto;
use App\Models\PubAgenteExecucao;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Publicador\Agente\ConfiguracaoDoAgenteService;
use App\Services\Publicador\Agente\EntradaDoPreparo;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\Payload\Alvo;
use App\Support\Publicador\RegraViolada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Publicador\Lote\CenarioDoPreparo;
use Tests\TestCase;

/**
 * AG-02 do Agente ECF Publicador (20/10/2026): elegibilidade antes da IA e a entrada única do preparo.
 * Critério de fechamento do plano: produto completo prepara; incompleto informa os motivos antes da IA; edição
 * manual preservada; save + "Preparar agora" juntos não duplicam etapa.
 */
class EntradaDoPreparoDoAgenteTest extends TestCase
{
    use CenarioDoPreparo;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarPreparo();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function conta(): array
    {
        return app(ProgramasPublicadorService::class)->resolver("company-{$this->empresa->id}");
    }

    private function ligar(array $dados = []): void
    {
        app(ConfiguracaoDoAgenteService::class)->salvar($this->conta(), ['habilitado' => true, 'modalidade' => 'gold_special', ...$dados], 0, $this->admin);
    }

    /** O produto do Portal já no Publicador, como o save deixa, com o recorte do piloto: só o Clássico, com foto e preço. */
    private function noPublicador(?EstruturaProduto $p = null): PubProduto
    {
        $p ??= $this->produtoDoPortal();
        $this->preparo()->sincronizarAgora((int) $this->empresa->id, (int) $p->id);
        $pub = $this->pubDo($p);
        $r = $this->rascunhoDo($p);
        $repo = app(RascunhoRepository::class);
        $repo->gravarAlvos($r, array_map(fn (Alvo $a) => new Alvo($a->listingTypeId, $a->titulo, $a->listingTypeId === 'gold_special'), $repo->snapshot($r)->alvos));
        $snap = $repo->snapshot($r->fresh());
        $variante = $snap->variantesAtivas()[0];
        $repo->gravarVariacao($r->fresh(), $snap->eixos, [$variante->comDados([...$variante->dados, 'estoque' => 5, 'precos' => ['gold_special' => 199.9]])]);
        $r->imagens()->create(['caminho' => 'publicador/cad.jpg', 'sha256' => str_repeat('a', 64), 'mime' => 'image/jpeg', 'bytes' => 800_000,
            'largura' => 1200, 'altura' => 1200, 'upload_status' => PubImagem::ENVIADA]);

        return $pub->fresh();
    }

    public function test_produto_completo_prepara_pela_cadeia_de_sempre(): void
    {
        $this->ligar();
        $pub = $this->noPublicador();

        $r = app(EntradaDoPreparo::class)->pedir($this->conta(), $pub, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin);

        $this->assertTrue($r['elegibilidade']['elegivel'], json_encode($r['elegibilidade']['pendencias'], JSON_UNESCAPED_UNICODE));
        $this->assertTrue($r['nova']);
        $this->assertSame(PubAgenteExecucao::PREPARANDO, $r['execucao']->estado);
        $this->assertSame('gerando_texto', $r['execucao']->etapa);
        $this->assertNotNull($r['execucao']->versao_dos_fatos);
        Queue::assertPushed(GerarPreparoIaJob::class, 1); // uma cadeia (título → Modelo → descrição encadeados)
    }

    public function test_incompleto_bloqueia_com_os_motivos_e_sem_ia(): void
    {
        $this->ligar(['estoque_minimo' => 10]);
        $p = $this->produtoDoPortal();
        $p->variacoes()->first()->update(['custo' => null]);
        $pub = $this->noPublicador($p);
        PubRascunho::where('produto_id', $pub->id)->first()->imagens()->delete();

        $r = app(EntradaDoPreparo::class)->pedir($this->conta(), $pub, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin);

        $this->assertFalse($r['elegibilidade']['elegivel']);
        $codigos = array_column($r['elegibilidade']['pendencias'], 'codigo');
        $this->assertSame(['custo', 'sem_fotos', 'estoque_insuficiente'], $codigos);
        $this->assertSame(PubAgenteExecucao::BLOQUEADA, $r['execucao']->estado);
        $this->assertNull($r['execucao']->chave_ativa);
        $this->assertSame($codigos, array_column($r['execucao']->bloqueios, 'codigo'));
        $this->assertNotNull($r['elegibilidade']['pendencias'][1]['url_correcao']);
        Queue::assertNotPushed(GerarPreparoIaJob::class);
    }

    public function test_segunda_modalidade_ativa_bloqueia_sem_desativar_nada(): void
    {
        $this->ligar();
        $pub = $this->noPublicador();
        $repo = app(RascunhoRepository::class);
        $r = PubRascunho::where('produto_id', $pub->id)->first();
        $repo->gravarAlvos($r, array_map(fn (Alvo $a) => new Alvo($a->listingTypeId, $a->titulo, true), $repo->snapshot($r)->alvos));

        $res = app(EntradaDoPreparo::class)->pedir($this->conta(), $pub, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin);

        $this->assertContains('alvos_fora_do_piloto', array_column($res['elegibilidade']['pendencias'], 'codigo'));
        $this->assertCount(2, $repo->snapshot($r->fresh())->alvosAtivos());
        Queue::assertNotPushed(GerarPreparoIaJob::class);
    }

    public function test_save_e_preparar_agora_juntos_nao_duplicam_a_cadeia(): void
    {
        $this->ligar();
        $pub = $this->noPublicador();
        $entrada = app(EntradaDoPreparo::class);

        $manual = $entrada->pedir($this->conta(), $pub, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin);
        $doSave = $entrada->aoPreparar($this->conta(), $pub);

        $this->assertSame('agente: preparando (gerando_texto)', $doSave);
        $this->assertSame(1, PubAgenteExecucao::count());
        $this->assertSame($manual['execucao']->id, PubAgenteExecucao::first()->id);
        Queue::assertPushed(GerarPreparoIaJob::class, 1);
    }

    public function test_edicao_da_equipe_fica_quando_a_cadeia_roda(): void
    {
        $this->ligar();
        $pub = $this->noPublicador();
        $r = PubRascunho::where('produto_id', $pub->id)->first();
        $r->update(['descricao' => 'Texto que a equipe escreveu.']);

        app(EntradaDoPreparo::class)->pedir($this->conta(), $pub, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin);
        $this->rodar(GerarPreparoIaJob::class);

        $this->assertSame('Texto que a equipe escreveu.', $r->fresh()->descricao);
    }

    /** O controle do teste acima: sem a edição da equipe, a mesma cadeia ESCREVE a descrição — senão aquele passaria à toa. */
    public function test_sem_edicao_da_equipe_a_cadeia_escreve_a_descricao(): void
    {
        $this->ligar();
        $pub = $this->noPublicador();
        $r = PubRascunho::where('produto_id', $pub->id)->first();
        $r->update(['descricao' => null]);

        app(EntradaDoPreparo::class)->pedir($this->conta(), $pub, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin);
        $this->rodar(GerarPreparoIaJob::class);

        $this->assertStringContainsString('Cadeira confortável', (string) $r->fresh()->descricao);
    }

    public function test_conta_sem_agente_segue_o_preparo_antigo_e_pausada_nao_chama_ia(): void
    {
        $pub = $this->noPublicador();
        $entrada = app(EntradaDoPreparo::class);
        $this->assertNull($entrada->aoPreparar($this->conta(), $pub));

        $this->ligar();
        app(ConfiguracaoDoAgenteService::class)->pausar($this->conta(), $this->admin);
        $this->assertSame('agente: AG-01-CONTROLE', $entrada->aoPreparar($this->conta(), $pub));
        Queue::assertNotPushed(GerarPreparoIaJob::class);

        $this->expectException(RegraViolada::class);
        $entrada->pedir($this->conta(), $pub, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin);
    }

    public function test_o_save_do_portal_passa_pela_entrada_do_agente(): void
    {
        $this->ligar();
        $p = $this->produtoDoPortal();
        $pub = $this->noPublicador($p);

        [$preparos] = $this->salvarERodar($p);

        $this->assertSame(['pronto'], $preparos);
        $execucao = PubAgenteExecucao::firstOrFail();
        $this->assertSame(PubAgenteExecucao::ORIGEM_AO_SALVAR, $execucao->origem);
        $this->assertSame($pub->id, $execucao->produto_id);
    }
}
