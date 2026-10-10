<?php

namespace Tests\Feature\Publicador;

use App\Jobs\Publicador\GerarPreparoIaJob;
use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use App\Jobs\Publicador\SincronizarProdutoDoPortalJob;
use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoAtributo;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVolume;
use App\Models\MlCategoriaSchema;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Ia\AnaliseAnuncioService;
use App\Services\Incubadora\Publicador\TermosMaisBuscadosService;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDaCategoria;
use App\Services\Publicador\DescricaoIaService;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\PreparoIaAgenda;
use App\Services\Publicador\PreparoIaDoRascunhoService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\EditorEmUso;
use App\Support\Publicador\MemoriaDoPreparoIa;
use App\Support\Publicador\Payload\Alvo;
use App\Support\Publicador\RegrasDoTitulo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/**
 * "IA prepara o rascunho ao salvar no Portal" (09/10/2026, learnings publicador-ml §16).
 *
 * O cliente salva o produto → espera (debounce) → o Publicador sincroniza SÓ aquele produto e, com
 * a ficha completa, a IA gera título (nos dois tipos), Modelo (com o título gerado) e descrição,
 * gravando no rascunho só onde está vazio ou ainda tem o último valor que ela mesma escreveu.
 *
 * A fila é `Queue::fake()`: os Jobs são rodados à mão, na ordem, cadeia incluída. A IA e os termos
 * mais buscados são dublês (nenhuma chamada real); o ML está bloqueado (`preventStrayRequests`, um
 * único `Http::fake`). O schema é o real da cadeira (MLB193945), já guardado.
 */
class PreparoIaAoSalvarNoPortalTest extends TestCase
{
    use CarregaSchemas;
    use RefreshDatabase;

    private Company $empresa;

    /** @var array<string, list<array>> as chamadas à IA, por etapa */
    private array $chamadas = ['titulo' => [], 'modelo' => [], 'descricao' => []];

    /** O Clássico que a IA devolve (09/10/2026: dois títulos numa chamada, `titulosPorTermos`). */
    private string $tituloIa = 'Cadeira Escritório Giratória Ergonômica';

    /** O Premium que a IA devolve; nulo = a IA repete o Clássico (o servidor diferencia). */
    private ?string $tituloPremiumIa = 'Cadeira Giratória Escritório Ergonômica';

    private string $modeloIa = 'cadeira home office, cadeira ergonomica, cadeira para escritorio em casa';

    private string $descricaoIa = 'Cadeira confortável para o dia todo.';

    private bool $falhaTitulo = false;

    private \SplObjectStorage $rodados;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake();
        Queue::fake();
        Cache::flush();
        $this->rodados = new \SplObjectStorage();

        $schema = self::schema(self::CADEIRA);
        MlCategoriaSchema::create(['category_id' => self::CADEIRA, 'domain_id' => $schema->dominio(), 'categoria' => $schema->categoria,
            'atributos' => $schema->atributos, 'technical_specs' => $schema->technicalSpecs, 'sale_terms' => $schema->saleTerms,
            'schema_hash' => $schema->hash(), 'fetched_at' => now()]);

        $this->mock(AnaliseAnuncioService::class, function ($m) {
            $m->shouldReceive('comPrazo')->andReturnSelf();
            $m->shouldReceive('titulosPorTermos')->andReturnUsing(function (...$args) {
                $this->chamadas['titulo'][] = $args;
                if ($this->falhaTitulo) {
                    throw new \RuntimeException('A IA não respondeu.');
                }

                return ['dados' => ['classico' => $this->tituloIa, 'premium' => $this->tituloPremiumIa ?? $this->tituloIa], 'meta' => []];
            });
            $m->shouldReceive('modeloPorTermos')->andReturnUsing(function (...$args) {
                $this->chamadas['modelo'][] = $args;

                return ['dados' => $this->modeloIa, 'meta' => []];
            });
            $m->shouldReceive('analise')->andReturn(['dados' => ['jtbd' => 'trabalhar sentado', 'puv' => 'conforto'], 'meta' => []]);
            $m->shouldReceive('descricao')->andReturnUsing(function (...$args) {
                $this->chamadas['descricao'][] = $args;

                return ['dados' => "<p>{$this->descricaoIa}</p>", 'meta' => []];
            });
        });
        $this->mock(TermosMaisBuscadosService::class, fn ($m) => $m->shouldReceive('termos')->andReturn(['termos' => [
            ['termo' => 'cadeira escritorio', 'posicao' => 1, 'relacionado' => true],
            ['termo' => 'cadeira home office', 'posicao' => 2, 'relacionado' => false],
        ]]));

        $this->empresa = Company::factory()->create();
    }

    // ═══ Ajudantes ═══════════════════════════════════════════════════════════

    /** Um produto do Portal (cadeira, uma cor) com a ficha e a descrição do cliente. */
    private function produtoDoPortal(string $codigo = 'CAD', bool $completo = true, ?Company $empresa = null): EstruturaProduto
    {
        $empresa ??= $this->empresa;
        $p = EstruturaProduto::create(['company_id' => $empresa->id, 'codigo' => $codigo, 'nome' => 'Cadeira Executiva',
            'categoria_ml_id' => self::CADEIRA, 'categoria_ml_nome' => 'Cadeiras de Escritório',
            'descricao' => 'Cadeira com encosto em tela, ótima para home office.']);
        $v = EstruturaProdutoVariacao::create(['produto_id' => $p->id, 'company_id' => $empresa->id, 'ordem' => 0,
            'codigo' => "{$codigo}-AZ", 'eixo' => 'cor', 'valor' => 'Azul', 'custo' => 100, 'estoque' => 5]);
        EstruturaProdutoVolume::create(['variacao_id' => $v->id, 'ordem' => 0, 'comprimento' => 60, 'largura' => 60, 'altura' => 40, 'peso' => 12]);
        EstruturaOferta::create(['company_id' => $empresa->id, 'variacao_id' => $v->id, 'sku' => "{$codigo}-AZ", 'fase' => 'simples', 'nome' => 'Cadeira Executiva — Azul']);
        $this->preencherFicha($p, $completo);

        return $p;
    }

    /** Todos os obrigatórios da ficha da categoria (menos o Modelo, que é da IA); `completo = false` deixa a Marca de fora. */
    private function preencherFicha(EstruturaProduto $p, bool $completo = true): void
    {
        $grupos = FichaTecnicaDaCategoria::doProduto(FichaTecnicaDaCategoria::daAtributos(self::schema(self::CADEIRA)->atributos), ['cor']);
        foreach (FichaTecnicaDaCategoria::camposPorId($grupos) as $id => $c) {
            if (! $c['obrigatorio'] || $id === 'MODEL' || (! $completo && $id === 'BRAND')) {
                continue;
            }
            [$valor, $valorId, $unidade] = match ($c['tipo']) {
                FichaTecnicaDaCategoria::TIPO_NUMERO_UNIDADE => ['50', null, 'cm'],
                FichaTecnicaDaCategoria::TIPO_NUMERO => ['3', null, null],
                FichaTecnicaDaCategoria::TIPO_SIM_NAO => ['Sim', '242085', null],
                default => $c['valores'] !== [] ? [$c['valores'][0]['nome'], (string) $c['valores'][0]['id'], null] : ['ECF', null, null],
            };
            $this->noPortal($p, $id, $valor, $valorId, $unidade);
        }
    }

    private function noPortal(EstruturaProduto $p, string $id, ?string $valor, ?string $valorId = null, ?string $unidade = null): void
    {
        EstruturaProdutoAtributo::updateOrCreate(['company_id' => $p->company_id, 'produto_id' => $p->id, 'atributo_id' => $id],
            ['atributo_nome' => $id, 'valor' => $valor, 'valor_id' => $valorId, 'unidade' => $unidade]);
    }

    private function salvarNoPortal(EstruturaProduto $p): void
    {
        app(PreparoIaAgenda::class)->aoSalvar((int) $p->company_id, [$p->id]);
    }

    private function servico(): PreparoIaDoRascunhoService
    {
        return app(PreparoIaDoRascunhoService::class);
    }

    /**
     * Roda, na ordem, os Jobs desta classe que a fila recebeu e ainda não rodaram — a cadeia de cada
     * um incluída, como o worker faria. Devolve o resultado de cada um.
     *
     * @return list<string>
     */
    private function rodar(string $classe): array
    {
        $saida = [];
        foreach (Queue::pushed($classe) as $job) {
            if ($this->rodados->contains($job)) {
                continue;
            }
            $this->rodados->attach($job);
            $saida[] = $this->executar($job);
            foreach ($job->chained ?? [] as $serializado) {
                $saida[] = $this->executar(unserialize($serializado));
            }
        }

        return $saida;
    }

    private function executar(object $job): string
    {
        return $job instanceof PrepararProdutoNoPublicadorJob
            ? $this->servico()->preparar($job->companyId, $job->estruturaProdutoId, $job->marca, $job->adiamentos)
            : $this->servico()->executarEtapa($job->rascunhoId, $job->etapa, $job->hash, $job->valorPronto, $job->adiamentos);
    }

    /** Salvar → espera → preparo → IA: o caminho inteiro de um save. @return array{0: list<string>, 1: list<string>} */
    private function salvarERodar(EstruturaProduto $p): array
    {
        $this->salvarNoPortal($p);

        return [$this->rodar(PrepararProdutoNoPublicadorJob::class), $this->rodar(GerarPreparoIaJob::class)];
    }

    private function pubDo(EstruturaProduto $p): PubProduto
    {
        return PubProduto::where('estrutura_produto_id', $p->id)->firstOrFail();
    }

    private function rascunhoDo(EstruturaProduto $p): PubRascunho
    {
        return PubRascunho::where('produto_id', $this->pubDo($p)->id)->firstOrFail();
    }

    /** @return array<string, ?string> listing_type_id → título gravado */
    private function titulos(PubRascunho $r): array
    {
        return $r->alvos()->orderBy('posicao')->pluck('titulo', 'listing_type_id')->all();
    }

    private function modelo(PubRascunho $r): ?array
    {
        return (new RascunhoRepository())->snapshot($r->fresh())->atributos['MODEL'] ?? null;
    }

    private function etapas(PubRascunho $r): array
    {
        return collect((array) ($r->fresh()->step_state['ia_preparo']['etapas'] ?? []))->map(fn ($e) => $e['status'] ?? null)->all();
    }

    private function editarComoEquipe(PubRascunho $r, array $dados): void
    {
        app(EditorRascunhoService::class)->salvar($r->fresh(), $dados);
    }

    // ═══ Debounce ════════════════════════════════════════════════════════════

    public function test_dois_saves_seguidos_so_o_ultimo_job_age(): void
    {
        $p = $this->produtoDoPortal();

        $this->salvarNoPortal($p);
        $this->salvarNoPortal($p);

        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, 2);
        Queue::assertPushedOn('high', PrepararProdutoNoPublicadorJob::class);
        // Decisão do usuário (10/10/2026): a IA espera 2 minutos sem save (era 10).
        $this->assertSame(2, config('publicador.preparo_ia.atraso_min'));
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->delay !== null
            && now()->diffInSeconds($j->delay, true) > 90 && now()->diffInSeconds($j->delay, true) <= 120);

        $jobs = Queue::pushed(PrepararProdutoNoPublicadorJob::class)->values();
        $this->rodados->attach($jobs[0]);
        $this->assertSame('superado', $this->executar($jobs[0]), 'o 1º acorda, vê marca mais nova e sai');
        $this->assertSame(0, PubProduto::count(), 'o Job superado não sincroniza nada');

        $this->assertSame(['pronto'], $this->rodar(PrepararProdutoNoPublicadorJob::class));
        $this->assertSame(1, PubProduto::where('estrutura_produto_id', $p->id)->count());
    }

    // ═══ O produto chega ao Publicador logo (10/10/2026) ═════════════════════

    public function test_salvar_leva_o_produto_ao_publicador_em_segundos_e_a_ia_continua_esperando(): void
    {
        $p = $this->produtoDoPortal();

        $this->salvarNoPortal($p);

        Queue::assertPushedOn('high', SincronizarProdutoDoPortalJob::class);
        Queue::assertPushed(SincronizarProdutoDoPortalJob::class, fn ($j) => $j->estruturaProdutoId === $p->id && $j->companyId === $this->empresa->id
            && $j->delay !== null && now()->diffInSeconds($j->delay, true) <= 60);
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->delay !== null && now()->diffInSeconds($j->delay, true) > 90);

        Queue::pushed(SincronizarProdutoDoPortalJob::class)->sole()->handle($this->servico());

        $r = $this->rascunhoDo($p);
        $this->assertSame(self::CADEIRA, $r->categoria_id, 'o produto já está no Publicador, com a ficha do Portal');
        Queue::assertNotPushed(GerarPreparoIaJob::class);
        $this->assertSame([], array_merge(...array_values($this->chamadas)), 'a IA não foi chamada');
        $this->assertSame([], array_filter($this->titulos($r)));

        // Passada a espera, o preparo sincroniza de novo (no MESMO produto) e só então chama a IA.
        $this->assertSame(['pronto'], $this->rodar(PrepararProdutoNoPublicadorJob::class));
        Queue::assertPushed(GerarPreparoIaJob::class);
        $this->assertSame(1, PubProduto::where('estrutura_produto_id', $p->id)->count());
    }

    public function test_saves_seguidos_viram_uma_sincronizacao_so_e_nenhum_save_fica_de_fora(): void
    {
        $p = $this->produtoDoPortal();

        $this->salvarNoPortal($p);
        $this->salvarNoPortal($p);
        $this->salvarNoPortal($p);
        Queue::assertPushed(SincronizarProdutoDoPortalJob::class, 1);
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, 3);

        Queue::pushed(SincronizarProdutoDoPortalJob::class)->sole()->handle($this->servico());
        // O Job apagou a marca ao começar: o save seguinte agenda outro.
        $this->salvarNoPortal($p);
        Queue::assertPushed(SincronizarProdutoDoPortalJob::class, 2);
    }

    public function test_editor_aberto_nao_e_sincronizado_logo_e_o_preparo_cobre_depois(): void
    {
        $p = $this->produtoDoPortal();
        $this->assertSame('sincronizado', $this->servico()->sincronizarAgora((int) $this->empresa->id, (int) $p->id));
        $marca = fn () => (new RascunhoRepository())->snapshot($this->rascunhoDo($p)->fresh())->atributos['BRAND'] ?? null;
        $antes = $marca();
        $this->assertNotNull($antes);

        EditorEmUso::marcar((int) $this->pubDo($p)->id);
        $this->noPortal($p, 'BRAND', 'Outra Marca');

        $this->assertSame('ocupado', $this->servico()->sincronizarAgora((int) $this->empresa->id, (int) $p->id));
        $this->assertSame($antes, $marca(), 'nada foi escrito por baixo de quem está editando');
    }

    // ═══ Ficha incompleta × completa ═════════════════════════════════════════

    public function test_ficha_incompleta_so_sincroniza_e_nao_chama_a_ia(): void
    {
        $p = $this->produtoDoPortal(completo: false);

        [$preparos, $ia] = $this->salvarERodar($p);

        $this->assertSame(['pronto'], $preparos);
        $this->assertSame([], $ia);
        Queue::assertNotPushed(GerarPreparoIaJob::class);
        $r = $this->rascunhoDo($p);
        $this->assertSame(self::CADEIRA, $r->categoria_id, 'sincronizou o produto mesmo assim');
        $this->assertSame([], array_filter($this->titulos($r)));
        $this->assertSame([], array_merge(...array_values($this->chamadas)));
    }

    public function test_ficha_completa_gera_titulo_nos_dois_tipos_modelo_com_o_titulo_e_descricao(): void
    {
        $p = $this->produtoDoPortal();

        [, $ia] = $this->salvarERodar($p);

        $this->assertSame(['escrito', 'escrito', 'escrito'], $ia);
        Queue::assertPushedWithChain(GerarPreparoIaJob::class, [GerarPreparoIaJob::class, GerarPreparoIaJob::class]);
        Queue::assertPushedOn('high', GerarPreparoIaJob::class);

        $r = $this->rascunhoDo($p);
        $this->assertSame(['gold_special' => $this->tituloIa, 'gold_pro' => $this->tituloPremiumIa], $this->titulos($r),
            'dois títulos diferentes, um por tipo');
        // O Modelo recebeu os títulos gerados; o filtro do servidor tirou o termo que só repete o título.
        $this->assertSame("{$this->tituloIa} / {$this->tituloPremiumIa}", $this->chamadas['modelo'][0][4]);
        $this->assertSame(['value_name' => 'cadeira home office, cadeira para escritorio em casa', 'origem' => 'ia'],
            array_intersect_key($this->modelo($r), array_flip(['value_id', 'value_name', 'origem'])));
        $this->assertSame($this->descricaoIa, $r->descricao);

        $this->assertSame([
            'titulo_gold_special' => $this->tituloIa,
            'titulo_gold_pro' => $this->tituloPremiumIa,
            'modelo' => 'cadeira home office, cadeira para escritorio em casa',
            'descricao' => $this->descricaoIa,
        ], $r->step_state['ia_escrito']);
        $this->assertSame(['titulo' => 'ok', 'modelo' => 'ok', 'descricao' => 'ok'], $this->etapas($r));
        // O texto do cliente entrou na descrição (MAG T8, só a entrada).
        $this->assertStringContainsString('encosto em tela', $this->chamadas['descricao'][0][2]);
    }

    // ═══ Dois títulos diferentes, sem marca nem peso (relato de 09/10/2026) ══

    public function test_ia_repete_o_titulo_com_ecf_e_peso_e_o_servidor_grava_dois_diferentes_e_limpos(): void
    {
        // O caso do usuário: a IA devolveu o MESMO título, com a marca e o peso suportado, para os dois tipos.
        $this->tituloIa = 'Cadeira Escritório Giratória Ergonômica ECF 130 kg';
        $this->tituloPremiumIa = null;
        $p = $this->produtoDoPortal();

        [, $ia] = $this->salvarERodar($p);

        $this->assertSame(['escrito', 'escrito', 'escrito'], $ia);
        $titulos = $this->titulos($this->rascunhoDo($p));
        $this->assertSame([
            'gold_special' => 'Cadeira Escritório Giratória Ergonômica',
            'gold_pro' => 'Cadeira Escritório Ergonômica Giratória',
        ], $titulos, 'sem ECF e sem "130 kg"; o Premium troca a ordem das duas últimas palavras');
        $this->assertFalse(RegrasDoTitulo::mesmo($titulos['gold_special'], $titulos['gold_pro']));
    }

    public function test_premium_da_equipe_igual_ao_que_a_ia_gera_para_o_classico_nao_se_repete(): void
    {
        $p = $this->produtoDoPortal();
        $this->salvarNoPortal($p);
        $this->rodar(PrepararProdutoNoPublicadorJob::class);
        // Antes de a IA rodar, a equipe escreve no Premium exatamente o que a IA vai sugerir ao Clássico.
        $r = $this->rascunhoDo($p);
        $this->editarComoEquipe($r, ['alvos' => [
            ['listing_type_id' => 'gold_special', 'titulo' => '', 'ativo' => true],
            ['listing_type_id' => 'gold_pro', 'titulo' => 'cadeira escritorio giratoria ergonomicas', 'ativo' => true],
        ]]);

        $this->rodar(GerarPreparoIaJob::class);

        $titulos = $this->titulos($r->fresh());
        $this->assertSame('cadeira escritorio giratoria ergonomicas', $titulos['gold_pro'], 'o Premium é da equipe');
        $this->assertSame('Cadeira Escritório Ergonômica Giratória', $titulos['gold_special'], 'o Clássico não repete o Premium (nem por caixa, acento ou plural)');
        $this->assertStringContainsString('cadeira escritorio giratoria ergonomicas', $this->chamadas['titulo'][0][7], 'o prompt recebe o título a evitar');
    }

    // ═══ Hash dos fatos ══════════════════════════════════════════════════════

    public function test_fatos_iguais_nao_chamam_a_ia_de_novo(): void
    {
        $p = $this->produtoDoPortal();
        $this->salvarERodar($p);

        [$preparos, $ia] = $this->salvarERodar($p);

        $this->assertSame(['pronto'], $preparos);
        $this->assertSame([], $ia, 'nenhuma cadeia nova');
        $this->assertCount(1, $this->chamadas['titulo']);
        $this->assertCount(1, $this->chamadas['modelo']);
        $this->assertCount(1, $this->chamadas['descricao']);
    }

    public function test_ficha_mudou_regera_e_reescreve_o_que_ainda_e_da_ia(): void
    {
        $p = $this->produtoDoPortal();
        $this->salvarERodar($p);
        $revisao = $this->rascunhoDo($p)->revisao;

        $p->update(['descricao' => 'Cadeira com encosto em tela e apoio de braço regulável.']);
        $this->tituloIa = 'Cadeira Escritório Giratória Apoio Braço Regulável';
        $this->tituloPremiumIa = 'Cadeira Giratória Escritório Braço Regulável';
        $this->modeloIa = 'cadeira home office, cadeira presidente';
        $this->descricaoIa = 'Nova descrição com o apoio de braço.';
        [, $ia] = $this->salvarERodar($p);

        $this->assertSame(['escrito', 'escrito', 'escrito'], $ia);
        $r = $this->rascunhoDo($p);
        $this->assertSame(['gold_special' => $this->tituloIa, 'gold_pro' => $this->tituloPremiumIa], $this->titulos($r));
        $this->assertSame('cadeira home office, cadeira presidente', $this->modelo($r)['value_name']);
        $this->assertSame('Nova descrição com o apoio de braço.', $r->descricao);
        $this->assertSame('Nova descrição com o apoio de braço.', $r->step_state['ia_escrito']['descricao']);
        $this->assertGreaterThan($revisao, $r->revisao, 'conteúdo mudou: a conferência anterior deixa de valer');
    }

    public function test_equipe_editou_o_titulo_ele_fica_e_modelo_e_descricao_ainda_mudam(): void
    {
        $p = $this->produtoDoPortal();
        $this->salvarERodar($p);
        $r = $this->rascunhoDo($p);
        $this->editarComoEquipe($r, ['alvos' => [
            ['listing_type_id' => 'gold_special', 'titulo' => $this->tituloIa, 'ativo' => true],
            ['listing_type_id' => 'gold_pro', 'titulo' => 'Cadeira Premium Escolhida Pela Equipe', 'ativo' => true],
        ]]);

        $p->update(['descricao' => 'Agora com rodízios de silicone.']);
        $this->tituloIa = 'Cadeira Escritório Giratória Rodízio Silicone';
        $this->modeloIa = 'cadeira home office, cadeira silenciosa';
        $this->descricaoIa = 'Rodízios de silicone que não riscam.';
        $this->salvarERodar($p);

        $r = $r->fresh();
        $this->assertSame(['gold_special' => $this->tituloIa, 'gold_pro' => 'Cadeira Premium Escolhida Pela Equipe'], $this->titulos($r),
            'o Clássico ainda era da IA e mudou; o Premium é da equipe e ficou');
        $this->assertSame('cadeira home office, cadeira silenciosa', $this->modelo($r)['value_name']);
        $this->assertSame('Rodízios de silicone que não riscam.', $r->descricao);
        $this->assertNotSame('Cadeira Premium Escolhida Pela Equipe', $r->step_state['ia_escrito']['titulo_gold_pro'],
            'a memória do Premium não passa a ser o título da equipe');
    }

    public function test_campos_todos_da_equipe_nem_chamam_a_ia(): void
    {
        $p = $this->produtoDoPortal();
        $this->salvarERodar($p);
        $r = $this->rascunhoDo($p);
        $this->editarComoEquipe($r, [
            'alvos' => [
                ['listing_type_id' => 'gold_special', 'titulo' => 'Título A da Equipe', 'ativo' => true],
                ['listing_type_id' => 'gold_pro', 'titulo' => 'Título B da Equipe', 'ativo' => true],
            ],
            'descricao' => 'Descrição escrita pela equipe.',
        ]);

        $p->update(['descricao' => 'Texto novo do cliente.']);
        [, $ia] = $this->salvarERodar($p);

        $this->assertSame(['pulado', 'escrito', 'pulado'], $ia, 'título e descrição da equipe: nada a gerar; o Modelo usa o título dela');
        $this->assertCount(1, $this->chamadas['titulo']);
        $this->assertCount(1, $this->chamadas['descricao']);
        $this->assertSame('Título B da Equipe', $this->titulos($r->fresh())['gold_pro']);
        $this->assertSame('Título A da Equipe / Título B da Equipe', $this->chamadas['modelo'][1][4]);
        $this->assertSame('Descrição escrita pela equipe.', $r->fresh()->descricao);
    }

    // ═══ Travas ══════════════════════════════════════════════════════════════

    public function test_rascunho_publicado_nao_e_tocado(): void
    {
        $p = $this->produtoDoPortal(completo: false);
        $this->salvarERodar($p);
        $r = $this->rascunhoDo($p);
        $r->update(['status' => PubRascunho::PUBLISHED]);

        $this->preencherFicha($p);
        [, $ia] = $this->salvarERodar($p);

        $this->assertSame([], $ia);
        $this->assertSame([], array_filter($this->titulos($r->fresh())));
        $this->assertNull($r->fresh()->descricao);
        $this->assertSame([], array_merge(...array_values($this->chamadas)));
    }

    public function test_publicacao_comecou_no_meio_a_etapa_nao_escreve(): void
    {
        $p = $this->produtoDoPortal();
        $this->salvarNoPortal($p);
        $this->rodar(PrepararProdutoNoPublicadorJob::class);
        $this->rascunhoDo($p)->update(['status' => PubRascunho::PUBLISHING]);

        $this->assertSame(['intocavel', 'intocavel', 'intocavel'], $this->rodar(GerarPreparoIaJob::class));
        $this->assertSame([], array_filter($this->titulos($this->rascunhoDo($p))));
    }

    public function test_kit_da_fase_n_do_publicador_nao_e_preparado(): void
    {
        $p = $this->produtoDoPortal();
        $this->salvarERodar($p);
        $base = $this->pubDo($p);
        $kit = PubProduto::create(['company_id' => $this->empresa->id, 'sku' => 'CAD-KIT2', 'nome' => 'Kit 2 Cadeira',
            'origem' => PubProduto::ORIGEM_PUBLICADOR, 'produto_base_id' => $base->id, 'fase' => 2, 'quantidade_kit' => 2, 'estoque_calculado' => true]);
        (new RascunhoRepository())->criar($kit, [new Alvo('gold_special', null), new Alvo('gold_pro', null)]);

        $this->assertSame('kit_da_fase', $this->servico()->avaliarIa($kit));
        Queue::assertPushed(GerarPreparoIaJob::class, 1);
    }

    public function test_anunciar_por_ia_rodando_adia_o_preparo(): void
    {
        $p = $this->produtoDoPortal();
        $this->salvarERodar($p);
        $r = $this->rascunhoDo($p);
        \App\Models\MlAnuncioIaAnalise::create(['company_id' => $this->empresa->id, 'user_id' => User::factory()->create(['role' => 'admin'])->id,
            'produto' => 'Cadeira', 'loja' => 'X', 'status' => \App\Models\MlAnuncioIaAnalise::STATUS_RODANDO,
            'resultado' => ['destino' => ['tipo' => 'publicador', 'produto_id' => $r->produto_id, 'rascunho_id' => $r->id, 'revisao_base' => 0, 'substituir' => false]]]);

        $p->update(['descricao' => 'Mudou.']);
        [$preparos] = $this->salvarERodar($p);

        $this->assertSame(['adiado'], $preparos);
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->adiamentos === 1);
    }

    // ═══ Editor aberto ═══════════════════════════════════════════════════════

    public function test_editor_em_uso_adia_o_preparo_inteiro_e_depois_segue(): void
    {
        $p = $this->produtoDoPortal();
        $this->salvarERodar($p);
        $pub = $this->pubDo($p);

        EditorEmUso::marcar((int) $pub->id);
        $p->update(['descricao' => 'Mudou com o editor aberto.']);
        $this->descricaoIa = 'Descrição depois do editor fechar.';
        [$preparos, $ia] = $this->salvarERodar($p);

        $this->assertSame(['adiado'], $preparos);
        $this->assertSame([], $ia);
        $this->assertSame('Cadeira confortável para o dia todo.', $this->rascunhoDo($p)->descricao, 'nada foi escrito com a tela aberta');
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->adiamentos === 1
            && $j->delay !== null && now()->diffInMinutes($j->delay, true) >= 4);

        Cache::forget(EditorEmUso::chave((int) $pub->id));
        $this->assertSame(['pronto'], $this->rodar(PrepararProdutoNoPublicadorJob::class));
        $this->rodar(GerarPreparoIaJob::class);
        $this->assertSame('Descrição depois do editor fechar.', $this->rascunhoDo($p)->descricao);
    }

    public function test_editor_aberto_no_meio_da_ia_adia_a_escrita_sem_gerar_de_novo(): void
    {
        $p = $this->produtoDoPortal();
        $this->salvarNoPortal($p);
        $this->rodar(PrepararProdutoNoPublicadorJob::class);
        $pub = $this->pubDo($p);

        EditorEmUso::marcar((int) $pub->id);
        $this->assertSame(['adiado', 'adiado', 'adiado'], $this->rodar(GerarPreparoIaJob::class));
        $r = $this->rascunhoDo($p);
        $this->assertSame([], array_filter($this->titulos($r)));
        $this->assertSame(['titulo' => 'adiado', 'modelo' => 'adiado', 'descricao' => 'adiado'], $this->etapas($r));
        Queue::assertPushed(GerarPreparoIaJob::class, fn ($j) => $j->etapa === 'titulo' && $j->adiamentos === 1
            && json_decode((string) $j->valorPronto, true) === ['gold_special' => $this->tituloIa, 'gold_pro' => $this->tituloPremiumIa]);

        Cache::forget(EditorEmUso::chave((int) $pub->id));
        $this->assertSame(['escrito', 'escrito', 'escrito'], $this->rodar(GerarPreparoIaJob::class));
        $this->assertSame($this->tituloIa, $this->titulos($r->fresh())['gold_special']);
        $this->assertCount(1, $this->chamadas['titulo'], 'a escrita adiada usa o valor já gerado');
        $this->assertCount(1, $this->chamadas['descricao']);
    }

    public function test_editor_em_uso_desiste_depois_do_limite_de_esperas(): void
    {
        config(['publicador.preparo_ia.max_adiamentos' => 1]);
        $p = $this->produtoDoPortal();
        $this->salvarNoPortal($p);
        $this->rodar(PrepararProdutoNoPublicadorJob::class);
        EditorEmUso::marcar((int) $this->pubDo($p)->id);

        $this->assertSame(['adiado', 'adiado', 'adiado'], $this->rodar(GerarPreparoIaJob::class));
        $this->assertSame(['desistiu', 'desistiu', 'desistiu'], $this->rodar(GerarPreparoIaJob::class));
        $this->assertSame([], array_filter($this->titulos($this->rascunhoDo($p))));
    }

    public function test_rotas_do_editor_marcam_o_uso(): void
    {
        $p = $this->produtoDoPortal();
        $this->salvarERodar($p);
        $pub = $this->pubDo($p);
        Cache::forget(EditorEmUso::chave((int) $pub->id));

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('mlb.anuncios.publicador.presenca', $pub->id))->assertOk();

        $this->assertTrue(EditorEmUso::emUso((int) $pub->id));
    }

    // ═══ Chave e custo ═══════════════════════════════════════════════════════

    public function test_chave_desligada_nao_agenda_nem_prepara(): void
    {
        config(['publicador.preparo_ia.ativo' => false]);
        $p = $this->produtoDoPortal();

        $this->salvarNoPortal($p);
        Queue::assertNothingPushed();

        $this->assertSame('desligado', $this->servico()->preparar((int) $this->empresa->id, (int) $p->id, 'qualquer'));
        $this->assertSame('desligado', $this->servico()->sincronizarAgora((int) $this->empresa->id, (int) $p->id));
        $this->assertSame(0, PubProduto::count());
    }

    public function test_limite_diario_da_empresa_so_sincroniza_o_que_passa(): void
    {
        config(['publicador.preparo_ia.limite_diario_por_empresa' => 1]);
        $a = $this->produtoDoPortal('CAD-A');
        $b = $this->produtoDoPortal('CAD-B');

        $this->salvarNoPortal($a);
        $this->salvarNoPortal($b);
        $this->rodar(PrepararProdutoNoPublicadorJob::class);

        Queue::assertPushed(GerarPreparoIaJob::class, 1);
        $this->rodar(GerarPreparoIaJob::class);
        $this->assertNotSame([], array_filter($this->titulos($this->rascunhoDo($a))));
        $this->assertSame([], array_filter($this->titulos($this->rascunhoDo($b))), 'o 2º só foi sincronizado');
        $this->assertSame(self::CADEIRA, $this->rascunhoDo($b)->categoria_id);
    }

    public function test_falha_da_ia_no_titulo_nao_impede_a_descricao_e_so_ela_e_refeita(): void
    {
        $this->falhaTitulo = true;
        $p = $this->produtoDoPortal();

        [, $ia] = $this->salvarERodar($p);

        $this->assertSame(['erro', 'pulado', 'escrito'], $ia, 'sem título o Modelo é pulado; a descrição sai');
        $r = $this->rascunhoDo($p);
        $this->assertSame($this->descricaoIa, $r->descricao);
        $this->assertSame(['titulo' => 'erro', 'modelo' => 'pulado', 'descricao' => 'ok'], $this->etapas($r));

        // Mesmo fato, IA de volta: refaz título e Modelo, não a descrição.
        $this->falhaTitulo = false;
        [, $ia] = $this->salvarERodar($p);
        $this->assertSame(['escrito', 'escrito'], $ia);
        $this->assertCount(1, $this->chamadas['descricao']);
        $this->assertSame($this->tituloPremiumIa, $this->titulos($r->fresh())['gold_pro']);
    }

    public function test_falha_que_derruba_o_job_do_titulo_ainda_poe_a_descricao_na_fila(): void
    {
        $job = new GerarPreparoIaJob(123, 'titulo', 'h', restantes: ['modelo', 'descricao']);
        $job->failed(new \RuntimeException('worker morreu'));

        Queue::assertPushed(GerarPreparoIaJob::class, fn ($j) => $j->etapa === 'descricao' && $j->rascunhoId === 123);
    }

    // ═══ Isolamento, D-11 e a tela ═══════════════════════════════════════════

    public function test_produto_de_outra_empresa_nao_e_preparado(): void
    {
        $outra = Company::factory()->create();
        $alheio = $this->produtoDoPortal('ALHEIO', empresa: $outra);

        app(PreparoIaAgenda::class)->aoSalvar((int) $this->empresa->id, [$alheio->id]);
        $this->assertSame(['sem_produto'], $this->rodar(PrepararProdutoNoPublicadorJob::class));
        $this->assertSame('sem_produto', $this->servico()->sincronizarAgora((int) $this->empresa->id, (int) $alheio->id));
        $this->assertSame(0, PubProduto::count());
    }

    public function test_descricao_escrita_pela_automacao_nao_dispara_o_automatico_do_editor(): void
    {
        $p = $this->produtoDoPortal();
        $this->salvarERodar($p);
        $r = $this->rascunhoDo($p);
        $r->update(['descricao' => '']);

        $this->assertNull(app(DescricaoIaService::class)->pedir($r->fresh(), true), 'a chance automática já foi gasta pela automação');
    }

    public function test_estado_do_editor_marca_o_que_ainda_e_da_ia(): void
    {
        $p = $this->produtoDoPortal();
        $this->salvarERodar($p);
        $r = $this->rascunhoDo($p);

        $marcas = app(EditorRascunhoService::class)->estado($r)['preparo_ia'];
        $this->assertSame(['titulos' => ['gold_special' => true, 'gold_pro' => true], 'modelo' => true, 'descricao' => true], $marcas);

        $this->editarComoEquipe($r, ['alvos' => [
            ['listing_type_id' => 'gold_special', 'titulo' => $this->tituloIa, 'ativo' => true],
            ['listing_type_id' => 'gold_pro', 'titulo' => 'Outro', 'ativo' => true],
        ], 'descricao' => 'Editada.']);
        $marcas = app(EditorRascunhoService::class)->estado($r->fresh())['preparo_ia'];
        $this->assertSame(['titulos' => ['gold_special' => true, 'gold_pro' => false], 'modelo' => true, 'descricao' => false], $marcas);
    }

    public function test_regra_de_ouro_da_memoria(): void
    {
        $this->assertTrue(MemoriaDoPreparoIa::podeEscrever('', null), 'vazio');
        $this->assertTrue(MemoriaDoPreparoIa::podeEscrever('  ', 'x'), 'só espaço é vazio');
        $this->assertTrue(MemoriaDoPreparoIa::podeEscrever('Título da IA', 'Título da IA'), 'ainda é o último escrito');
        $this->assertFalse(MemoriaDoPreparoIa::podeEscrever('Título da IA editado', 'Título da IA'), 'a equipe mexeu');
        $this->assertFalse(MemoriaDoPreparoIa::podeEscrever('Qualquer', null), 'preenchido sem memória = da equipe');
    }

    // ═══ O Modelo é da IA (decisão do usuário, 09/10/2026) ═══════════════════

    public function test_modelo_do_cliente_vira_fato_e_o_modelo_antigo_do_portal_da_lugar_ao_da_ia(): void
    {
        $p = $this->produtoDoPortal(completo: false);
        // O cliente gravou o Modelo antes de ele sair da ficha: a linha fica (é dado dele).
        $this->noPortal($p, 'MODEL', 'Puff Redondo Lia');
        $this->salvarERodar($p);
        $r = $this->rascunhoDo($p);
        $this->assertNull($this->modelo($r), 'o Sincronizar não leva mais o Modelo do Portal');

        // Rascunho de antes da decisão: o Sincronizar antigo tinha gravado o MODEL com origem portal.
        (new RascunhoRepository())->mesclarAtributos($r->fresh(), ['MODEL' => ['value_name' => 'Puff Redondo Lia', 'origem' => 'portal']]);

        $this->preencherFicha($p);
        [, $ia] = $this->salvarERodar($p);

        $this->assertSame(['escrito', 'escrito', 'escrito'], $ia);
        $modelo = $this->modelo($r);
        $this->assertSame('ia', $modelo['origem'], 'o MODEL do Portal saiu e a IA gerou o dela');
        $this->assertSame('cadeira home office, cadeira para escritorio em casa', $modelo['value_name']);
        $this->assertSame('Puff Redondo Lia', EstruturaProdutoAtributo::where('produto_id', $p->id)->where('atributo_id', 'MODEL')->value('valor'),
            'a linha do cliente no Portal não é apagada');
        // O que o cliente escreveu entra como FATO nos dois prompts.
        $this->assertStringContainsString('Nome/modelo informado pelo cliente: Puff Redondo Lia', $this->chamadas['titulo'][0][5]);
        $this->assertStringContainsString('Nome/modelo informado pelo cliente: Puff Redondo Lia', $this->chamadas['modelo'][0][5]);
    }

    public function test_modelo_da_equipe_nao_sai_no_sincronizar(): void
    {
        $p = $this->produtoDoPortal(completo: false);
        $this->salvarERodar($p);
        $r = $this->rascunhoDo($p);
        (new RascunhoRepository())->mesclarAtributos($r->fresh(), ['MODEL' => ['value_name' => 'Linha Executiva', 'origem' => 'user']]);

        $this->preencherFicha($p);
        [, $ia] = $this->salvarERodar($p);

        $this->assertSame(['escrito', 'pulado', 'escrito'], $ia, 'Modelo da equipe: nada a gerar');
        $this->assertSame(['value_name' => 'Linha Executiva', 'origem' => 'user'],
            array_intersect_key($this->modelo($r), array_flip(['value_name', 'origem'])));
        $this->assertCount(0, $this->chamadas['modelo']);
    }
}

