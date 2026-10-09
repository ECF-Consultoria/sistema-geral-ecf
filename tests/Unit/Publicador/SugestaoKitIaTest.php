<?php

namespace Tests\Unit\Publicador;

use App\Jobs\Publicador\GerarSugestaoKitIaJob;
use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlCategoriaSchema;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Ia\AnaliseAnuncioService;
use App\Services\Publicador\CategorySchemaRepository;
use App\Services\Publicador\PalavrasChaveService;
use App\Services\Publicador\SugestaoKitIaService;
use App\Support\Publicador\RegraViolada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fase 175 Plano 175-06 (§4 da ETAPA-3) — "Sugerir com IA" do painel
 * "Criar Fase 2": `SugestaoKitIaService`, o serviço IRMÃO do
 * `PalavrasChaveService`.
 *
 * As disciplinas que este arquivo guarda:
 *
 * 1. **Chave de cache PRÓPRIA.** O pedido do kit mora em
 *    `publicador:kit-ia:{rascunho}:{alvo}:{N}` e nunca encosta na chave do
 *    `PalavrasChaveService` (`publicador:palavras:{rascunho}:{alvo}`), que está
 *    em produção com os alvos `modelo`/`titulo_gold_*`.
 * 2. **O N entra na chave.** Trocar a quantidade no painel muda o resultado
 *    esperado: o pedido de "Kit 2" não pode responder ao painel de "Kit 4".
 * 3. **O pedido é escopado ao rascunho do BASE** — no momento do painel o
 *    rascunho do kit ainda não existe.
 * 4. **Nada é gravado no rascunho por trás da pessoa**: o resultado fica no
 *    cache e a tela é que aplica.
 * 5. **Nenhuma chamada real a provedor de IA** (custa dinheiro): o
 *    `AnaliseAnuncioService` é injetado falso e `Http::fake()` prova que
 *    nenhuma requisição saiu.
 *
 * @group phase175
 */
class SugestaoKitIaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Queue::fake();
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    /** O rascunho do BASE: publicado, dois tipos ativos, categoria com limite 60. */
    private function rascunhoDoBase(?string $categoria = 'MLB193945', string $titulo = 'Cadeira Escritório Executiva'): PubRascunho
    {
        $company = Company::factory()->create();
        $empresa = MlbEmpresa::create(['nome' => 'Polo das Fases', 'projeto' => 'POLOS', 'company_id' => $company->id]);

        if ($categoria !== null) {
            MlCategoriaSchema::create([
                'category_id' => $categoria,
                'categoria' => [
                    'id' => $categoria, 'name' => 'Cadeiras de Escritório',
                    'settings' => ['max_title_length' => 60],
                    'path_from_root' => [['id' => $categoria, 'name' => 'Cadeiras de Escritório']],
                ],
                'atributos' => [], 'technical_specs' => [], 'sale_terms' => [],
                'schema_hash' => str_repeat('a', 64), 'fetched_at' => now(),
            ]);
        }

        $produto = PubProduto::create([
            'mlb_empresa_id' => $empresa->id,
            'company_id' => $company->id,
            'sku' => 'CAD',
            'nome' => 'Cadeira Escritório',
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);

        $r = PubRascunho::create([
            'produto_id' => $produto->id,
            'status' => PubRascunho::PUBLISHED,
            'revisao' => 1,
            'categoria_id' => $categoria,
            'condicao' => 'new',
            'descricao' => 'Cadeira executiva com apoio lombar.',
        ]);
        $r->alvos()->create(['listing_type_id' => 'gold_special', 'titulo' => $titulo, 'ativo' => true, 'posicao' => 0]);

        return $r->fresh('produto');
    }

    /**
     * Um `AnaliseAnuncioService` que NUNCA fala com provedor nenhum: devolve o
     * texto combinado e registra o que recebeu, para as asserções de prompt.
     */
    private function iaFalsa(string $resposta, array &$recebido = []): AnaliseAnuncioService
    {
        return new class($resposta, $recebido) extends AnaliseAnuncioService
        {
            public function __construct(private string $resposta, private array &$recebido) {}

            public function comPrazo(float $instante): static
            {
                $this->recebido['prazo'] = $instante;

                return $this;
            }

            public function textoDeKit(string $alvo, string $produto, string $tituloBase, ?string $descricaoBase, int $unidades, int $maximo): array
            {
                $this->recebido['alvo'] = $alvo;
                $this->recebido['produto'] = $produto;
                $this->recebido['titulo_base'] = $tituloBase;
                $this->recebido['descricao_base'] = $descricaoBase;
                $this->recebido['unidades'] = $unidades;
                $this->recebido['maximo'] = $maximo;

                return ['dados' => $this->resposta, 'meta' => []];
            }
        };
    }

    private function servico(string $resposta, array &$recebido = []): SugestaoKitIaService
    {
        return new SugestaoKitIaService($this->iaFalsa($resposta, $recebido), app(CategorySchemaRepository::class));
    }

    // ═══ O contrato pedir → cache → estado ═══════════════════════════════════

    public function test_pedir_devolve_o_id_e_grava_rodando_numa_chave_propria(): void
    {
        $r = $this->rascunhoDoBase();
        $servico = $this->servico('qualquer');

        $pedido = $servico->pedir($r, 'titulo', 2);

        $this->assertNotSame('', $pedido);
        $this->assertSame("publicador:kit-ia:{$r->id}:titulo:2", SugestaoKitIaService::chave($r->id, 'titulo', 2));
        $this->assertSame(
            ['pedido' => $pedido, 'status' => 'rodando', 'valor' => null, 'erro' => null],
            Cache::get(SugestaoKitIaService::chave($r->id, 'titulo', 2)),
        );

        // A chave do serviço em produção continua intocada.
        $this->assertNull(Cache::get(PalavrasChaveService::chave($r->id, 'titulo')));
        $this->assertNull(Cache::get(PalavrasChaveService::chave($r->id, 'modelo')));

        Queue::assertPushed(GerarSugestaoKitIaJob::class, fn ($job) => $job->rascunhoBaseId === $r->id
            && $job->alvo === 'titulo'
            && $job->quantidade === 2
            && $job->pedido === $pedido);
    }

    public function test_a_quantidade_entra_na_chave_e_os_pedidos_nao_se_misturam(): void
    {
        $r = $this->rascunhoDoBase();
        $servico = $this->servico('qualquer');

        $dois = $servico->pedir($r, 'titulo', 2);
        $quatro = $servico->pedir($r, 'titulo', 4);

        $this->assertNotSame($dois, $quatro);
        $this->assertSame($dois, $servico->estado($r, 'titulo', 2)['pedido']);
        $this->assertSame($quatro, $servico->estado($r, 'titulo', 4)['pedido']);
    }

    public function test_estado_de_alvo_nunca_pedido_e_nulo(): void
    {
        $r = $this->rascunhoDoBase();

        $this->assertNull($this->servico('x')->estado($r, 'descricao', 2));
    }

    public function test_alvo_fora_da_lista_fechada_e_recusado(): void
    {
        $r = $this->rascunhoDoBase();

        $this->assertSame(['titulo', 'descricao'], SugestaoKitIaService::ALVOS);

        $this->expectException(RegraViolada::class);
        $this->servico('x')->pedir($r, 'modelo', 2);
    }

    public function test_resultado_de_pedido_antigo_nao_sobrescreve_o_mais_novo(): void
    {
        $r = $this->rascunhoDoBase();
        $servico = $this->servico('Kit 2 Cadeira Escritório Executiva');

        $antigo = $servico->pedir($r, 'titulo', 2);
        $novo = $servico->pedir($r, 'titulo', 2);

        $servico->executar($r, 'titulo', 2, $antigo);

        $estado = $servico->estado($r, 'titulo', 2);
        $this->assertSame($novo, $estado['pedido']);
        $this->assertSame('rodando', $estado['status']);
        $this->assertNull($estado['valor']);
    }

    // ═══ O resultado ═════════════════════════════════════════════════════════

    public function test_titulo_vazio_da_ia_vira_erro_com_mensagem_nunca_pronto_vazio(): void
    {
        $r = $this->rascunhoDoBase();
        $servico = $this->servico('   ');
        $pedido = $servico->pedir($r, 'titulo', 2);

        try {
            $servico->executar($r, 'titulo', 2, $pedido);
            $this->fail('executar() tinha de lançar para o Job registrar a falha');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('IA', $e->getMessage());
        }

        $estado = $servico->estado($r, 'titulo', 2);
        $this->assertSame('erro', $estado['status']);
        $this->assertNull($estado['valor']);
        $this->assertNotSame('', (string) $estado['erro']);
    }

    public function test_titulo_acima_do_limite_da_categoria_sai_cortado_por_palavra_inteira(): void
    {
        $r = $this->rascunhoDoBase();
        $longo = 'Kit 2 Cadeira Escritório Executiva Giratória Ergonômica Premium Reclinável Apoio Lombar';
        $servico = $this->servico($longo, $recebido);
        $pedido = $servico->pedir($r, 'titulo', 2);

        $servico->executar($r, 'titulo', 2, $pedido);

        $valor = $servico->estado($r, 'titulo', 2)['valor'];
        $this->assertSame(60, $recebido['maximo'], 'o max_title_length da categoria tem de chegar à IA');
        $this->assertLessThanOrEqual(60, mb_strlen($valor));
        $this->assertSame($valor, trim($valor));
        // Cortado na última palavra INTEIRA: nenhum pedaço de palavra no fim.
        $this->assertStringStartsWith('Kit 2 Cadeira Escritório Executiva', $valor);
        $this->assertStringContainsString(mb_substr($valor, (int) mb_strrpos($valor, ' ') + 1), $longo);
    }

    public function test_titulo_sem_a_marca_do_kit_recebe_o_prefixo_e_o_n_nunca_se_perde(): void
    {
        $r = $this->rascunhoDoBase();
        $servico = $this->servico('Cadeira Escritório Executiva Giratória');
        $pedido = $servico->pedir($r, 'titulo', 4);

        $servico->executar($r, 'titulo', 4, $pedido);

        $this->assertStringStartsWith('Kit 4 ', $servico->estado($r, 'titulo', 4)['valor']);
    }

    public function test_titulo_que_ja_tem_a_marca_do_kit_nao_ganha_outra(): void
    {
        $this->assertSame('Kit 2 Cadeira Escritório', SugestaoKitIaService::ajustarTituloDoKit('Kit 2 Cadeira Escritório', 2, 60));
        $this->assertSame('Cadeira Kit 2 Escritório', SugestaoKitIaService::ajustarTituloDoKit('Cadeira Kit 2 Escritório', 2, 60));
        // "Kit 20" não é a marca de "Kit 2": o prefixo entra.
        $this->assertSame('Kit 2 Kit 20 Cadeiras', SugestaoKitIaService::ajustarTituloDoKit('Kit 20 Cadeiras', 2, 60));
        // O corte nunca come o prefixo: ele está no começo.
        $this->assertStringStartsWith('Kit 3 ', SugestaoKitIaService::ajustarTituloDoKit(str_repeat('Cadeira ', 20), 3, 30));
    }

    public function test_descricao_preserva_a_frase_obrigatoria_no_comeco(): void
    {
        $r = $this->rascunhoDoBase();
        $servico = $this->servico('Leve 2 cadeiras e monte seu escritório.', $recebido);
        $pedido = $servico->pedir($r, 'descricao', 2);

        $servico->executar($r, 'descricao', 2, $pedido);

        $valor = $servico->estado($r, 'descricao', 2)['valor'];
        $this->assertStringStartsWith('Este kit contém 2 unidades de Cadeira Escritório.', $valor);
        $this->assertStringContainsString('Leve 2 cadeiras', $valor);
        $this->assertSame('descricao', $recebido['alvo']);
        $this->assertSame('Cadeira executiva com apoio lombar.', $recebido['descricao_base']);
        $this->assertSame('Cadeira Escritório Executiva', $recebido['titulo_base'], 'o título da Fase 1 é o ponto de partida');
    }

    public function test_descricao_nao_repete_a_frase_quando_a_ia_ja_a_escreveu(): void
    {
        $frase = 'Este kit contém 2 unidades de Cadeira Escritório.';

        $uma = SugestaoKitIaService::ajustarDescricaoDoKit("{$frase}\n\nTexto da IA.", 'Cadeira Escritório', 2);
        $this->assertSame(1, substr_count($uma, $frase));
        $this->assertStringStartsWith($frase, $uma);

        // A IA escreveu a frase NO MEIO: ela sai de lá e volta para o começo, uma só vez.
        $meio = SugestaoKitIaService::ajustarDescricaoDoKit("Aproveite. {$frase} Fim.", 'Cadeira Escritório', 2);
        $this->assertSame(1, substr_count($meio, $frase));
        $this->assertStringStartsWith($frase, $meio);
    }

    public function test_sem_categoria_no_base_o_limite_cai_no_padrao_de_60(): void
    {
        $r = $this->rascunhoDoBase(categoria: null);
        $servico = $this->servico('Kit 2 Cadeira', $recebido);
        $pedido = $servico->pedir($r, 'titulo', 2);

        $servico->executar($r, 'titulo', 2, $pedido);

        $this->assertSame(60, $recebido['maximo']);
        $this->assertSame('Kit 2 Cadeira', $servico->estado($r, 'titulo', 2)['valor']);
    }

    public function test_o_prazo_do_job_e_repassado_a_ia(): void
    {
        $r = $this->rascunhoDoBase();
        $servico = $this->servico('Kit 2 Cadeira', $recebido);
        $pedido = $servico->pedir($r, 'titulo', 2);

        $servico->executar($r, 'titulo', 2, $pedido, 1234.5);

        $this->assertSame(1234.5, $recebido['prazo']);
    }

    public function test_falhou_marca_o_erro_do_pedido_mais_recente(): void
    {
        $r = $this->rascunhoDoBase();
        $servico = $this->servico('x');
        $pedido = $servico->pedir($r, 'titulo', 2);

        $servico->falhou($r->id, 'titulo', 2, $pedido, 'A IA não terminou a tempo. Tente de novo.');

        $estado = $servico->estado($r, 'titulo', 2);
        $this->assertSame('erro', $estado['status']);
        $this->assertSame('A IA não terminou a tempo. Tente de novo.', $estado['erro']);
    }

    public function test_nada_e_gravado_no_rascunho_e_nenhuma_requisicao_sai(): void
    {
        $r = $this->rascunhoDoBase();
        $servico = $this->servico('Kit 2 Cadeira Escritório Nova');
        $pedido = $servico->pedir($r, 'titulo', 2);
        $antes = $r->only(['descricao', 'revisao', 'status']);

        $servico->executar($r, 'titulo', 2, $pedido);

        $this->assertSame($antes, $r->fresh()->only(['descricao', 'revisao', 'status']));
        $this->assertSame('Cadeira Escritório Executiva', (string) $r->alvos()->first()->titulo);
        Http::assertNothingSent();
    }

    // ═══ O Job ═══════════════════════════════════════════════════════════════

    public function test_o_job_vai_para_a_fila_high_sem_nova_tentativa(): void
    {
        $job = new GerarSugestaoKitIaJob(7, 'titulo', 2, 'pedido-1');

        $this->assertSame('high', $job->queue);
        $this->assertSame(1, $job->tries);
        $this->assertSame(300, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
    }

    public function test_o_job_de_rascunho_que_sumiu_marca_erro_sem_explodir(): void
    {
        $servico = $this->servico('x');
        Cache::put(SugestaoKitIaService::chave(99999, 'titulo', 2), ['pedido' => 'p1', 'status' => 'rodando', 'valor' => null, 'erro' => null], 60);

        (new GerarSugestaoKitIaJob(99999, 'titulo', 2, 'p1'))->handle($servico);

        $estado = Cache::get(SugestaoKitIaService::chave(99999, 'titulo', 2));
        $this->assertSame('erro', $estado['status']);
        $this->assertStringContainsString('não existe', (string) $estado['erro']);
    }

    public function test_o_job_grava_o_resultado_do_pedido_no_cache(): void
    {
        $r = $this->rascunhoDoBase();
        $servico = $this->servico('Kit 2 Cadeira Escritório Executiva');
        $pedido = $servico->pedir($r, 'titulo', 2);

        (new GerarSugestaoKitIaJob($r->id, 'titulo', 2, $pedido))->handle($servico);

        $estado = $servico->estado($r, 'titulo', 2);
        $this->assertSame('pronto', $estado['status']);
        $this->assertSame('Kit 2 Cadeira Escritório Executiva', $estado['valor']);
        Http::assertNothingSent();
    }
}
