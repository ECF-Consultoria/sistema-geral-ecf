<?php

namespace Tests\Feature\Trilha;

use App\Jobs\Publicador\GerarPreparoIaJob;
use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use App\Jobs\Publicador\SincronizarProdutoDoPortalJob;
use App\Models\Company;
use App\Models\EstruturaAmbiente;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaFamilia;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Models\MlCategoriaSchema;
use App\Models\PubRascunho;
use App\Models\TrilhaOnda;
use App\Models\User;
use App\Services\Trilha\Ondas\PortaoDaOnda;
use App\Services\Trilha\TrilhaDaEmpresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Publicador\Lote\CenarioDoPreparo;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * O custo do cadastro na trilha (3ª entrega, 02/11/2026; desenho §6.3 "prepararSeCabe" e §9), fatia F1: o produto
 * cadastrado pela trilha SÓ vai ao Publicador quando sai da Lista (pergunta 2) — ou quando já está lá. Motivo, conferido
 * no código: o Sincronizar roda a cada save, mas só aplica a categoria em rascunho SEM categoria e só copia foto para
 * grupo VAZIO; com o cadastro aos poucos o rascunho ficaria preso na 1ª categoria e na 1ª foto.
 *
 * - incompleto: nenhum `SincronizarProdutoDoPortalJob` nem `PrepararProdutoNoPublicadorJob` (o save do Portal agenda os
 *   dois a cada gravação; a trilha grava com `agendarPreparo: false`);
 * - completo: os dois, uma vez por save que mudou algo; nada mudou, nada agendado;
 * - já no Publicador (o grupo das cores ou o legado por cor): cada gravação vai, mesmo incompleto;
 * - travado (pergunta 1): 409 e nenhum Job;
 * - de ponta a ponta, com o preparo de verdade (`CenarioDoPreparo`): completar pela trilha com a Simples numa onda
 *   futura prepara o rascunho e a IA fica `aguardando_onda` (a dublê nunca é chamada); com a onda já começada a IA roda
 *   UMA vez — e o produto, que o robô levou adiante, trava.
 */
class CustoDoCadastroTest extends TestCase
{
    use CenarioDoPreparo;
    use MontaTrilha;
    use RefreshDatabase;

    private User $dev;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Cache::flush();
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', TrilhaOnda::FUSO));
        // O preparo ligado aqui, e não pelo `.env` (o do worktree o desliga: tudo "passaria" sem Job nenhum).
        config([
            'trilha.empresas' => [], 'trilha.trabalho.empresas' => [],
            'publicador.preparo_ia.ativo' => true, 'publicador.criativos_auto.ativo' => false,
        ]);
        $this->dev = User::factory()->create(['role' => 'admin', 'active' => true, 'name' => 'Rafael']);
        $this->dev->forceFill(['is_dev' => true])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══ prepararSeCabe ═════════════════════════════════════════════════════

    public function test_o_produto_incompleto_nao_vai_ao_publicador_nem_ao_criar_nem_ao_salvar(): void
    {
        $this->empresa = $this->empresaDaTrilha();
        $id = $this->criar(['nome' => 'Poltrona', 'codigo' => 'POLT-1'])->assertCreated()->json('produto.id');
        $produto = EstruturaProduto::query()->findOrFail($id);
        $v = $produto->variacoes()->sole();

        $r = $this->linhas($produto, [['id' => $v->id, 'codigo' => 'POLT-1', 'nome' => 'Poltrona', 'custo' => '9,90', 'familia' => 'Poltronas']])
            ->assertOk()->json();

        $this->assertSame('lista', $r['situacao']['etapa']);
        $this->assertSame(9.9, $v->fresh()->custo, 'gravou');
        Queue::assertNotPushed(SincronizarProdutoDoPortalJob::class);
        Queue::assertNotPushed(PrepararProdutoNoPublicadorJob::class);
        // Nem a oferta simples que nasceu levou o produto ao Publicador por fora (o 2º caminho até o `aoSalvar`).
        Queue::assertNothingPushed();
    }

    public function test_o_save_que_completa_o_produto_o_leva_ao_publicador_uma_vez(): void
    {
        $this->empresa = $this->empresaDaTrilha();
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA', [null], ['descricao' => null]);
        $linha = ['id' => $p['variacoes'][0]->id, 'codigo' => 'VA', 'nome' => 'Vaso Alfa', 'descricao' => 'Vaso de cerâmica feito à mão.'];

        $r = $this->linhas($p['produto'], [$linha])->assertOk()->json();

        $this->assertSame([[], 'mapa', 'Lista completa: o produto seguiu para o mapeamento.'], [$r['situacao']['faltas'], $r['situacao']['etapa'], $r['mensagem']]);
        $this->assertSame(1, $this->jobs(SincronizarProdutoDoPortalJob::class, $p['produto']));
        $this->assertSame(1, $this->jobs(PrepararProdutoNoPublicadorJob::class, $p['produto']));
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->soItens === null && $j->companyId === (int) $this->empresa->id);

        // O mesmo pedido de novo: nada mudou, nada agendado.
        $this->linhas($p['produto'], [$linha])->assertOk()->assertJsonPath('mensagem', 'Nada mudou.');
        $this->assertSame(1, $this->jobs(PrepararProdutoNoPublicadorJob::class, $p['produto']));
        // Mudou de novo (completo): agenda de novo — o debounce do Publicador junta (o Sincronizar tem a marca de 5 min).
        $this->linhas($p['produto'], [[...$linha, 'custo' => '130,00']])->assertOk();
        $this->assertSame(2, $this->jobs(PrepararProdutoNoPublicadorJob::class, $p['produto']));
        $this->assertSame(1, $this->jobs(SincronizarProdutoDoPortalJob::class, $p['produto']));
    }

    public function test_o_produto_que_ja_esta_no_publicador_vai_a_cada_gravacao_mesmo_incompleto(): void
    {
        $this->empresa = $this->empresaDaTrilha();
        // O grupo das cores no Publicador, com rascunho ainda DRAFT e sem nada do robô: não andou, não trava.
        $grupo = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA', ['Azul', 'Verde'], ['descricao' => null]);
        $this->rascunhoDoPub($this->grupoNoPublicador($this->empresa, $grupo));
        // O legado por cor: o pub ancorado só na oferta simples de uma cor.
        $legado = $this->produtoCompleto($this->empresa, 'Vaso Beta', 'VB', [null], ['descricao' => null]);
        $this->ofertaNoPublicador($this->empresa, $legado['simples'][0]);

        foreach ([$grupo, $legado] as $p) {
            $v = $p['variacoes'][0];
            $r = $this->linhas($p['produto'], [['id' => $v->id, 'codigo' => $v->codigo, 'nome' => $p['produto']->nome, 'custo' => '77,00']])->assertOk()->json();

            $this->assertSame(['descrição'], $r['situacao']['faltas'], 'continua incompleto');
            $this->assertTrue($r['situacao']['no_publicador']);
            $this->assertSame(1, $this->jobs(PrepararProdutoNoPublicadorJob::class, $p['produto']), $p['produto']->nome);
            $this->assertSame(1, $this->jobs(SincronizarProdutoDoPortalJob::class, $p['produto']), $p['produto']->nome);
        }
    }

    public function test_o_produto_travado_responde_409_e_nada_vai_para_a_fila(): void
    {
        $this->empresa = $this->empresaDaTrilha();
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA');
        EstruturaAnuncio::create(['oferta_id' => $p['simples'][0]->id, 'tipo' => EstruturaAnuncio::TIPO_CLASSICO,
            'status' => EstruturaAnuncio::STATUS_ATIVO, 'codigo_mlb' => 'MLB1234567890', 'titulo' => 'Vaso']);

        $this->linhas($p['produto'], [['id' => $p['variacoes'][0]->id, 'codigo' => 'VA', 'nome' => 'Vaso Alfa', 'custo' => '1,00']])
            ->assertStatus(409)->assertJsonPath('travado', true);

        $this->assertSame(100.0, $p['variacoes'][0]->fresh()->custo);
        Queue::assertNothingPushed();
    }

    // ═══ De ponta a ponta, com o preparo de verdade ════════════════════════

    public function test_completar_com_a_simples_numa_onda_futura_prepara_o_rascunho_e_a_ia_espera_a_data(): void
    {
        $p = $this->cadeiraQuaseCompleta();
        $this->itemNaOnda($this->onda($this->empresa, '2026-11-20', 'Onda Futura'), "s-{$p->id}");

        $this->completarPeloTitulo($p)->assertOk()->assertJsonPath('situacao.etapa', 'mapa');

        $this->assertSame(1, $this->jobs(PrepararProdutoNoPublicadorJob::class, $p));
        $this->assertSame(['pronto'], $this->rodar(PrepararProdutoNoPublicadorJob::class), 'o produto chegou ao Publicador');
        $this->assertSame([], $this->rodar(GerarPreparoIaJob::class), 'nenhuma etapa paga');
        Queue::assertNotPushed(GerarPreparoIaJob::class);
        $r = $this->rascunhoDo($p);
        $this->assertArrayNotHasKey('ia_preparo', (array) $r->step_state, 'a IA não escreveu nada');
        $this->assertSame(PortaoDaOnda::AGUARDANDO, $this->preparo()->avaliarIa($this->pubDo($p)));
        // O rascunho nasceu com a categoria do cadastro (o 1º Sincronizar leva tudo de uma vez).
        $this->assertSame(self::CADEIRA, (string) $r->categoria_id);
        // Não andou: o cadastro segue editável.
        $this->assertTrue($this->abrir($p)->json('edicao.livre'));
    }

    public function test_com_a_onda_ja_comecada_a_ia_roda_uma_vez_e_o_produto_trava(): void
    {
        $p = $this->cadeiraQuaseCompleta();
        $onda = $this->onda($this->empresa, '2026-11-05', 'Onda de Hoje');
        $this->itemNaOnda($onda, "s-{$p->id}");
        $this->assertSame(['id' => (int) $onda->id, 'nome' => 'Onda de Hoje', 'data' => '2026-11-05'], $this->abrir($p)->json('edicao.onda_comecou'));

        $this->completarPeloTitulo($p)->assertOk();

        $this->assertSame(['pronto'], $this->rodar(PrepararProdutoNoPublicadorJob::class));
        $this->assertNotSame([], $this->rodar(GerarPreparoIaJob::class), 'a cadeia da IA rodou');
        $this->assertArrayHasKey('ia_preparo', (array) $this->rascunhoDo($p)->fresh()->step_state);

        // O robô levou o produto adiante: a próxima escrita é recusada e nada mais vai para a fila.
        $preparos = $this->jobs(PrepararProdutoNoPublicadorJob::class, $p);
        $this->assertTrue(TrilhaDaEmpresa::itens($this->empresa->fresh())["s-{$p->id}"]['andou']);
        $this->linhas($p, [['id' => $p->variacoes()->value('id'), 'codigo' => 'CAD-AZ', 'nome' => 'Cadeira Executiva', 'custo' => '1,00']])
            ->assertStatus(409);
        $this->assertSame($preparos, $this->jobs(PrepararProdutoNoPublicadorJob::class, $p));
        $this->assertFalse($this->abrir($p)->json('edicao.livre'));
    }

    // ─── apoio ───

    /**
     * A cadeira do cenário do preparo (categoria com o schema guardado e a ficha completa) com família, ambiente e as 3
     * fotos (com arquivo) — falta só o título de venda. Na empresa da trilha de trabalho.
     */
    private function cadeiraQuaseCompleta(): EstruturaProduto
    {
        $this->montarPreparo();
        MlCategoriaSchema::query()->update(['fetched_at' => now()]);
        config(['trilha.empresas' => [$this->empresa->id], 'trilha.trabalho.empresas' => [$this->empresa->id]]);

        $p = $this->produtoDoPortal('CAD');
        $p->update(['familia_id' => EstruturaFamilia::create(['company_id' => $this->empresa->id, 'nome' => 'Cadeiras'])->id]);
        $p->ambientes()->attach(EstruturaAmbiente::create(['company_id' => $this->empresa->id, 'nome' => 'Escritório'])->id);
        $v = $p->variacoes()->sole();
        for ($f = 0; $f < 3; $f++) {
            $caminho = "estrutura/{$this->empresa->id}/produtos/{$p->id}/variacoes/{$v->id}/".Str::random(8).'.jpg';
            Storage::disk('local')->put($caminho, $this->jpeg());
            EstruturaProdutoVariacaoImagem::create(['company_id' => $this->empresa->id, 'produto_id' => $p->id, 'variacao_id' => $v->id,
                'caminho' => $caminho, 'nome_original' => "foto{$f}.jpg", 'mime' => 'image/jpeg', 'tamanho' => 1000, 'ordem' => $f]);
        }
        $this->assertSame(['título'], TrilhaDaEmpresa::faltasDoProduto($this->empresa, (int) $p->id), 'o cenário: falta só o título');
        Queue::assertNothingPushed();

        return $p->fresh();
    }

    private function completarPeloTitulo(EstruturaProduto $p): TestResponse
    {
        return $this->linhas($p, [['id' => $p->variacoes()->value('id'), 'codigo' => 'CAD-AZ', 'nome' => 'Cadeira Executiva',
            'titulo' => 'Cadeira Executiva Giratória Com Encosto Em Tela']]);
    }

    private function jpeg(): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return 'jpeg-falso';
        }
        $gd = imagecreatetruecolor(1200, 1200);
        ob_start();
        imagejpeg($gd);

        return (string) ob_get_clean();
    }

    /** Quantos Jobs desta classe a fila recebeu para o produto. */
    private function jobs(string $classe, EstruturaProduto $produto): int
    {
        return Queue::pushed($classe, fn ($j) => (int) $j->estruturaProdutoId === (int) $produto->id)->count();
    }

    private function criar(array $corpo): TestResponse
    {
        return $this->actingAs($this->dev)->postJson("/trilha/{$this->empresa->id}/produtos", $corpo);
    }

    private function abrir(EstruturaProduto $produto): TestResponse
    {
        return $this->actingAs($this->dev)->getJson("/trilha/{$this->empresa->id}/produtos/{$produto->id}/cadastro")->assertOk();
    }

    private function linhas(EstruturaProduto $produto, array $linhas): TestResponse
    {
        return $this->actingAs($this->dev)->postJson("/trilha/{$this->empresa->id}/produtos/{$produto->id}/linhas", ['linhas' => $linhas]);
    }
}
