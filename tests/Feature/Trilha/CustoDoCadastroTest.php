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
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Models\MlCategoriaSchema;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\TrilhaOnda;
use App\Models\User;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDaCategoria;
use App\Services\Publicador\RascunhoRepository;
use App\Services\Trilha\Ondas\PortaoDaOnda;
use App\Services\Trilha\TrilhaDaEmpresa;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem;
use App\Support\Publicador\Variacao\Eixo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
 *   UMA vez — e o produto, que o agente levou adiante, trava;
 * - F3: o produto sem variação completado SÓ pela trilha (criar, caixas com a categoria, ficha, fotos) vai ao
 *   Publicador uma vez, na foto que o completa, e o rascunho nasce com a categoria e as 3 fotos;
 * - F4: o produto de duas cores completado só pela trilha vai ao Publicador uma vez e lá vira UM grupo com as duas
 *   cores e as fotos de cada uma.
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
        // O grupo das cores no Publicador, com rascunho ainda DRAFT e sem nada do agente: não andou, não trava.
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

        // O agente levou o produto adiante: a próxima escrita é recusada e nada mais vai para a fila.
        $preparos = $this->jobs(PrepararProdutoNoPublicadorJob::class, $p);
        $this->assertTrue(TrilhaDaEmpresa::itens($this->empresa->fresh())["s-{$p->id}"]['andou']);
        $this->linhas($p, [['id' => $p->variacoes()->value('id'), 'codigo' => 'CAD-AZ', 'nome' => 'Cadeira Executiva', 'custo' => '1,00']])
            ->assertStatus(409);
        $this->assertSame($preparos, $this->jobs(PrepararProdutoNoPublicadorJob::class, $p));
        $this->assertFalse($this->abrir($p)->json('edicao.livre'));
    }

    /**
     * F3 (03/11/2026), o "pronto quando" da fatia: um produto SEM variação (sem cor nem tamanho) completado SÓ pela
     * trilha — criar, as caixas com a categoria, a ficha técnica e as fotos, sem abrir o Portal. Enquanto falta algo,
     * nada vai ao Publicador; a foto que o completa o leva UMA vez, e o 1º Sincronizar leva tudo de uma vez: o rascunho
     * nasce com a categoria certa e as 3 fotos. A Simples numa onda futura: a IA fica `aguardando_onda` (a dublê nunca é
     * chamada).
     */
    public function test_um_produto_sem_variacao_completado_so_pela_trilha_vai_ao_publicador_uma_vez_e_a_ia_espera_a_onda(): void
    {
        $this->montarPreparo();
        MlCategoriaSchema::query()->update(['fetched_at' => now()]);
        config(['trilha.empresas' => [$this->empresa->id], 'trilha.trabalho.empresas' => [$this->empresa->id]]);
        // O ML do cenário não responde nada (`Http::fake()` vazio): a categoria e os campos dela vêm do que já foi lido
        // (os caches de 7 dias da busca e da ficha), com as respostas REAIS da sondagem.
        $schema = self::schema(self::CADEIRA);
        Cache::put('ml_meta_categoria_'.self::CADEIRA, $schema->categoria, 3600);
        Cache::put('ml_meta_atributos_'.self::CADEIRA, $schema->atributos, 3600);

        // 1. Criar: nome + SKU. Uma variação sem eixo; a Simples entra numa onda que ainda não chegou.
        $id = $this->criar(['nome' => 'Cadeira Executiva', 'codigo' => 'CAD-1'])->assertCreated()->json('produto.id');
        $p = EstruturaProduto::query()->findOrFail($id);
        $v = $p->variacoes()->sole();
        $this->assertNull($v->eixo);
        $this->itemNaOnda($this->onda($this->empresa, '2026-11-20', 'Onda Futura'), "s-{$p->id}");

        // 2. As caixas, com a categoria.
        $r = $this->linhas($p, [[
            'id' => $v->id, 'codigo' => 'CAD-1', 'nome' => 'Cadeira Executiva', 'titulo' => 'Cadeira Executiva Giratória Com Encosto Em Tela',
            'familia' => 'Cadeiras', 'ambientes' => ['Escritório'], 'categoria_ml_id' => self::CADEIRA, 'custo' => '350,00', 'estoque' => '5',
            'volumes' => [['c' => 60, 'l' => 60, 'a' => 40, 'kg' => 12]], 'descricao' => 'Cadeira com encosto em tela, ótima para home office.',
        ]])->assertOk()->json();
        $this->assertSame([], $r['erros']);
        $this->assertSame(self::CADEIRA, $p->fresh()->categoria_ml_id);
        $this->assertContains('fotos (0 de 3)', $r['situacao']['faltas']);
        $this->assertNotEmpty(array_filter($r['situacao']['faltas'], fn (string $f) => str_starts_with($f, 'ficha: ')));

        // 3. A ficha técnica: todo obrigatório da cadeira (sem eixo, a Cor também é da ficha).
        $r = $this->actingAs($this->dev)->putJson("/trilha/{$this->empresa->id}/produtos/{$p->id}/ficha-tecnica",
            ['atributos' => $this->obrigatoriosDaCadeira(), 'categoria' => self::CADEIRA])->assertOk()->json();
        $this->assertSame(['fotos (0 de 3)'], $r['situacao']['faltas']);
        Queue::assertNothingPushed();

        // 4. As fotos: a remessa que completa o produto.
        $r = $this->actingAs($this->dev)->post("/trilha/{$this->empresa->id}/produtos/{$p->id}/variacoes/{$v->id}/fotos",
            ['imagens' => [$this->foto('frente.jpg'), $this->foto('lado.jpg'), $this->foto('costas.jpg')]], ['Accept' => 'application/json'])
            ->assertOk()->json();
        $this->assertSame([[], 'mapa'], [$r['situacao']['faltas'], $r['situacao']['etapa']]);
        $this->assertSame(1, $this->jobs(SincronizarProdutoDoPortalJob::class, $p));
        $this->assertSame(1, $this->jobs(PrepararProdutoNoPublicadorJob::class, $p));

        // O Publicador, de verdade: o rascunho nasce inteiro, e a IA espera a data da onda.
        $this->assertSame(['pronto'], $this->rodar(PrepararProdutoNoPublicadorJob::class));
        $this->assertSame([], $this->rodar(GerarPreparoIaJob::class), 'nenhuma etapa paga');
        Queue::assertNotPushed(GerarPreparoIaJob::class);
        $rascunho = $this->rascunhoDo($p);
        $this->assertSame(self::CADEIRA, (string) $rascunho->categoria_id);
        $this->assertCount(3, app(RascunhoRepository::class)->snapshot($rascunho->fresh())->imagens, 'as 3 fotos de uma vez');
        $this->assertArrayNotHasKey('ia_preparo', (array) $rascunho->step_state, 'a IA não escreveu nada');
        $this->assertSame(PortaoDaOnda::AGUARDANDO, $this->preparo()->avaliarIa($this->pubDo($p)));
        // Não andou: o cadastro segue editável, e só esta vez foi ao Publicador.
        $this->assertTrue($this->abrir($p)->json('edicao.livre'));
        $this->assertSame(1, $this->jobs(PrepararProdutoNoPublicadorJob::class, $p));
    }

    /**
     * F4 (03/11/2026), o "pronto quando" da fatia: um produto de DUAS cores completado só pela trilha — criar, as caixas
     * com a categoria e as duas cores no MESMO pedido (a 1ª nomeia a variação que já existia; a nova herda custo e
     * caixas), a ficha, as fotos de cada cor. A foto da 1ª cor não o leva (falta a outra); a que completa a 2ª o leva
     * UMA vez, e no Publicador ele vira UM grupo — nenhuma cor separada em produto à parte — com as duas cores e as 3
     * fotos de cada uma, cada foto no grupo da sua cor.
     */
    public function test_um_produto_de_duas_cores_completado_so_pela_trilha_vira_um_grupo_no_publicador_uma_vez(): void
    {
        $this->montarPreparo();
        MlCategoriaSchema::query()->update(['fetched_at' => now()]);
        config(['trilha.empresas' => [$this->empresa->id], 'trilha.trabalho.empresas' => [$this->empresa->id]]);
        $schema = self::schema(self::CADEIRA);
        Cache::put('ml_meta_categoria_'.self::CADEIRA, $schema->categoria, 3600);
        Cache::put('ml_meta_atributos_'.self::CADEIRA, $schema->atributos, 3600);

        $id = $this->criar(['nome' => 'Cadeira Executiva', 'codigo' => 'CAD-AZ'])->assertCreated()->json('produto.id');
        $p = EstruturaProduto::query()->findOrFail($id);
        $azul = $p->variacoes()->sole();
        $this->itemNaOnda($this->onda($this->empresa, '2026-11-20', 'Onda Futura'), "s-{$p->id}");

        $r = $this->linhas($p, [
            ['id' => $azul->id, 'chave' => "v{$azul->id}", 'codigo' => 'CAD-AZ', 'nome' => 'Cadeira Executiva', 'eixo' => 'cor', 'valor' => 'Azul',
                'titulo' => 'Cadeira Executiva Giratória Com Encosto Em Tela', 'familia' => 'Cadeiras', 'ambientes' => ['Escritório'],
                'categoria_ml_id' => self::CADEIRA, 'custo' => '350,00', 'estoque' => '5', 'volumes' => [['c' => 60, 'l' => 60, 'a' => 40, 'kg' => 12]],
                'descricao' => 'Cadeira com encosto em tela, ótima para home office.'],
            ['chave' => 'n1', 'codigo' => 'CAD-PT', 'nome' => 'Cadeira Executiva', 'eixo' => 'cor', 'valor' => 'Preto', 'estoque' => '2'],
        ])->assertOk()->json();
        $this->assertSame([], $r['erros']);
        $preto = EstruturaProdutoVariacao::query()->where('codigo', 'CAD-PT')->sole();
        $this->assertSame(['cor', 'Preto', 350.0, 1], [$preto->eixo, $preto->valor, $preto->custo, $preto->volumes()->count()]);

        // A ficha: com a cor como eixo, a Cor sai dela (o id de eixo que vier é ignorado).
        $this->actingAs($this->dev)->putJson("/trilha/{$this->empresa->id}/produtos/{$p->id}/ficha-tecnica",
            ['atributos' => $this->obrigatoriosDaCadeira(), 'categoria' => self::CADEIRA])->assertOk()
            ->assertJsonPath('situacao.faltas', ['fotos (0 de 3)']);

        // As fotos da Azul: ainda falta a Preto — nada vai ao Publicador.
        $this->enviarFotos($p, $azul, ['azul-1.jpg', 'azul-2.jpg', 'azul-3.jpg'])->assertJsonPath('situacao.faltas', ['fotos (0 de 3)']);
        Queue::assertNothingPushed();
        // As da Preto completam o produto: UMA ida ao Publicador.
        $this->enviarFotos($p, $preto, ['preto-1.jpg', 'preto-2.jpg', 'preto-3.jpg'])->assertJsonPath('situacao.etapa', 'mapa');
        $this->assertSame(1, $this->jobs(SincronizarProdutoDoPortalJob::class, $p));
        $this->assertSame(1, $this->jobs(PrepararProdutoNoPublicadorJob::class, $p));

        $this->assertSame(['pronto'], $this->rodar(PrepararProdutoNoPublicadorJob::class));
        $this->assertSame([], $this->rodar(GerarPreparoIaJob::class), 'a IA espera a onda');
        // UM produto no Publicador para a empresa inteira: as duas cores no mesmo grupo, nenhuma separada.
        $this->assertSame(1, PubProduto::query()->where('company_id', $this->empresa->id)->count());
        $rascunho = $this->rascunhoDo($p);
        $snap = app(RascunhoRepository::class)->snapshot($rascunho->fresh());
        $eixos = Eixo::ordenar($snap->eixos);
        $porCor = [];
        foreach ($snap->variantes as $variante) {
            $grupo = ResolvedorGruposImagem::chaveDoGrupo($variante, $eixos, $snap->fotosPorVariante);
            $porCor[(string) collect($variante->valores)->first()?->valueName] = count(array_filter($snap->imagens, fn ($a) => $a['grupo'] === $grupo));
        }
        ksort($porCor);
        $this->assertSame(['Azul' => 3, 'Preto' => 3], $porCor, 'cada cor com as 3 fotos dela');
        $this->assertCount(6, $snap->imagens);
        $this->assertSame(self::CADEIRA, (string) $rascunho->categoria_id);
        $this->assertSame(1, $this->jobs(PrepararProdutoNoPublicadorJob::class, $p));
    }

    // ─── apoio ───

    /** As fotos (diferentes entre si: o rascunho junta as de mesmo conteúdo) na variação, pela rota da trilha. */
    private function enviarFotos(EstruturaProduto $p, EstruturaProdutoVariacao $v, array $nomes): TestResponse
    {
        return $this->actingAs($this->dev)->post("/trilha/{$this->empresa->id}/produtos/{$p->id}/variacoes/{$v->id}/fotos",
            ['imagens' => array_map(fn (string $n) => $this->foto($n), $nomes)], ['Accept' => 'application/json'])->assertOk();
    }

    /** Todo obrigatório da cadeira (a definição real), sem eixo nenhum no produto, com um valor válido para o tipo. */
    private function obrigatoriosDaCadeira(): array
    {
        $grupos = FichaTecnicaDaCategoria::doProduto(FichaTecnicaDaCategoria::daAtributos(self::schema(self::CADEIRA)->atributos), []);
        $saida = [];
        foreach (FichaTecnicaDaCategoria::camposPorId($grupos) as $id => $c) {
            if (! $c['obrigatorio'] || $id === FichaTecnicaDaCategoria::ID_MODELO) {
                continue;
            }
            $saida[] = match ($c['tipo']) {
                FichaTecnicaDaCategoria::TIPO_NUMERO_UNIDADE => ['id' => $id, 'valor' => '50', 'unidade' => $c['unidade_padrao']],
                FichaTecnicaDaCategoria::TIPO_NUMERO         => ['id' => $id, 'valor' => '3'],
                FichaTecnicaDaCategoria::TIPO_SIM_NAO        => ['id' => $id, 'valor' => 'Sim'],
                FichaTecnicaDaCategoria::TIPO_LISTA          => ['id' => $id, 'valor' => (string) $c['valores'][0]['id']],
                default                                      => ['id' => $id, 'valor' => $c['valores'] !== [] ? (string) $c['valores'][0]['id'] : 'ECF'],
            };
        }
        $this->assertGreaterThan(5, count($saida), 'a cadeira real tem vários obrigatórios');

        return $saida;
    }

    /**
     * Uma foto de verdade, grande o bastante para o Publicador (o mínimo de tamanho do ML) e DIFERENTE das outras: o
     * rascunho junta as fotos de mesmo conteúdo (sha256), e três `image()` iguais virariam uma.
     */
    private function foto(string $nome): UploadedFile
    {
        $gd = imagecreatetruecolor(1200, 1200);
        $h = crc32($nome);
        imagefill($gd, 0, 0, imagecolorallocate($gd, $h % 256, ($h >> 8) % 256, ($h >> 16) % 256));
        ob_start();
        imagejpeg($gd);

        return UploadedFile::fake()->createWithContent($nome, (string) ob_get_clean());
    }

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
