<?php

namespace Tests\Feature\Trilha;

use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use App\Jobs\Publicador\SincronizarProdutoDoPortalJob;
use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoAtributo;
use App\Models\PubRascunho;
use App\Models\TrilhaOnda;
use App\Models\User;
use App\Services\Ia\AnaliseAnuncioService;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDaCategoria;
use App\Services\Portal\Estrutura\Produtos\VariacaoImagensService;
use App\Services\Trilha\CadastroNaTrilha;
use App\Services\Trilha\TrilhaDaEmpresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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
 * A categoria e a ficha técnica GRAVADAS pelo cadastro da trilha (3ª entrega, fatias F2 e F2b — desenho §5.4, §5.5,
 * §6.2 rotas 3, 4 e 9, §6.3 "Categoria"). As leituras (busca, árvore, campos, termos) estão em
 * {@see CategoriasNaTrilhaTest}; a guarda das rotas, em {@see CadastroNaTrilhaAcessoTest}.
 *
 * O que se prova:
 * - a ficha grava INCOMPLETA (`exigirObrigatorios: false`): o obrigatório vazio dá 200 e vira a falta "ficha: x" na
 *   Lista; valor inválido continua 422 por campo e nada grava; o id de eixo e o desconhecido são ignorados; o Modelo
 *   antigo fica; sem categoria é 422 SEM chamar o ML; o catálogo fora é 422 sem apagar nada;
 * - a categoria na linha (rota 3): a nova precisa ser anunciável (422 na folha fechada para anúncio novo); a que não é
 *   folha continua o erro DA LINHA do serviço (200 com `erros`); com o ML fora grava "não validada" com aviso, como o
 *   Portal; texto que não é categoria é 422; e com o produto no Publicador a troca é 422 (D12);
 * - ler os campos da categoria guarda a definição, e a Lista deixa de dizer "a conferir";
 * - o "Preencher com IA" (F2b): 422 sem categoria salva ou com a tela em outra categoria, sem chamar a IA; com ela,
 *   sugere só para os campos vazios da tela e NÃO grava nada;
 * - o produto travado responde 409 na ficha e na IA.
 *
 * Nenhuma chamada real: `Http::fake` por URL com as respostas REAIS da sondagem de 01/10/2026 (MLB193945, Cadeiras
 * para Escritório) e a IA é um dublê.
 */
class FichaNaTrilhaTest extends TestCase
{
    use MontaTrilha;
    use RefreshDatabase;

    private const CADEIRA = 'MLB193945';

    /** O pai real da cadeira: não é folha. */
    private const PAI = 'MLB436389';

    /** Uma folha que NÃO aceita anúncio novo (`settings.listing_allowed: false`). */
    private const FECHADA = 'MLB999001';

    private Company $empresa;

    private User $dev;

    /** `GET /categories/{id}` responde 500 (o ML fora para a categoria). */
    private bool $categoriaFora = false;

    /** `GET /categories/{id}/attributes` responde 500 (o catálogo da ficha fora). */
    private bool $atributosFora = false;

    /** O que a IA dublê responde, quantas vezes foi chamada e se está fora. */
    public array $respostaDaIa = [];

    public int $chamadasDaIa = 0;

    public bool $iaFora = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Cache::flush();
        Storage::fake(VariacaoImagensService::DISCO);
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', TrilhaOnda::FUSO));
        // Depois do relógio: o TTL conta a partir do "agora" do teste.
        Cache::put('ml_app_token_coleta', 'app-token-teste', 3600);
        config(['trilha.empresas' => [], 'trilha.trabalho.empresas' => [], 'publicador.preparo_ia.ativo' => true]);
        $this->empresa = $this->empresaDaTrilha();
        $this->dev = User::factory()->create(['role' => 'admin', 'active' => true, 'name' => 'Rafael']);
        $this->dev->forceFill(['is_dev' => true])->save();

        Http::fake(fn (Request $r) => $this->responder($r));

        $teste = $this;
        $this->app->instance(AnaliseAnuncioService::class, new class($teste) extends AnaliseAnuncioService
        {
            public function __construct(private $t) {}

            public function ficha(string $produto, string $specs, string $titulo, string $caminhoCategoria, string $catalogo, bool $comNaoSeAplica = false): array
            {
                $this->t->chamadasDaIa++;
                if ($this->t->iaFora) {
                    throw new \RuntimeException('provedor fora');
                }

                return ['dados' => ['atributos' => $this->t->respostaDaIa], 'meta' => ['modelo' => 'teste']];
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══ A ficha técnica (rota 4) ══════════════════════════════════════════

    public function test_o_obrigatorio_vazio_grava_200_e_a_lista_diz_ficha_x(): void
    {
        $p = $this->cadeira();

        $r = $this->ficha($p['produto'], [['id' => 'BRAND', 'valor' => 'ECF'], ['id' => 'IS_GAMER', 'valor' => 'Não']])->assertOk()->json();

        $this->assertSame(['BRAND' => 'ECF', 'IS_GAMER' => 'Não'], $this->fichaNoBanco($p['produto']));

        // A resposta é o retrato novo (como a rota 3), com a falta de cada obrigatório em branco.
        $this->assertSame(['produto', 'variacoes', 'ficha', 'listas', 'eixos', 'limites', 'situacao', 'edicao', 'versao', 'mensagem'], array_keys($r));
        $this->assertEqualsCanonicalizing(['BRAND', 'IS_GAMER'], array_column($r['ficha']['salvos'], 'id'));
        $this->assertSame('lista', $r['situacao']['etapa']);
        $this->assertContains('ficha: altura do encosto', $r['situacao']['faltas']);
        $this->assertContains('ficha: é ergonômica', $r['situacao']['faltas']);
        $this->assertNotContains('ficha: marca', $r['situacao']['faltas']);
        $this->assertNotContains('ficha: é gamer', $r['situacao']['faltas']);
        $this->assertSame('Ficha salva. Ainda faltam: '.implode(', ', $r['situacao']['faltas']).'.', $r['mensagem']);

        // A Lista da trilha diz o mesmo, com a ficha conferida (e incompleta).
        $naLista = $this->produtoNaTrilha($this->trilha(), (int) $p['produto']->id);
        $this->assertSame(['lista', 'incompleta', $r['situacao']['faltas']], [$naLista['etapa'], $naLista['ficha'], $naLista['faltas']]);

        // O rastro da trilha (passo `ficha`) e o do serviço; incompleto, nada vai ao Publicador.
        $evento = $this->eventosDaTrilha()->sole();
        $this->assertSame(['ficha', 'trilha', 'interno', 2], [$evento->getExtraProperty('passo'), $evento->getExtraProperty('via'),
            $evento->getExtraProperty('origem'), $evento->getExtraProperty('campos')]);
        $this->assertSame([User::class, (int) $this->dev->id], [$evento->causer_type, (int) $evento->causer_id]);
        $this->assertTrue(Activity::query()->get()->contains(fn (Activity $a) => $a->getExtraProperty('evento') === 'ficha_tecnica_gravada'));
        Queue::assertNothingPushed();
    }

    public function test_a_ficha_completa_tira_o_produto_da_lista_e_vai_ao_publicador_uma_vez(): void
    {
        $p = $this->cadeira();

        $r = $this->ficha($p['produto'], $this->obrigatoriosPreenchidos())->assertOk()->json();

        $this->assertSame(['mapa', []], [$r['situacao']['etapa'], $r['situacao']['faltas']]);
        $this->assertSame('Lista completa: o produto seguiu para o mapeamento.', $r['mensagem']);
        $this->assertSame('completa', $this->produtoNaTrilha($this->trilha(), (int) $p['produto']->id)['ficha']);
        $this->assertSame(1, Queue::pushed(SincronizarProdutoDoPortalJob::class, fn ($j) => (int) $j->estruturaProdutoId === (int) $p['produto']->id)->count());
        $this->assertSame(1, Queue::pushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => (int) $j->estruturaProdutoId === (int) $p['produto']->id)->count());
    }

    public function test_valor_invalido_e_422_por_campo_e_nada_grava(): void
    {
        $p = $this->cadeira();
        $this->atributo($p['produto'], 'BRAND', 'Antiga');

        $this->ficha($p['produto'], [
            ['id' => 'BRAND', 'valor' => 'Nova'],
            ['id' => 'BACKREST_HEIGHT', 'valor' => 'alto', 'unidade' => 'cm'],
            ['id' => 'IS_SWIVEL', 'valor' => 'talvez'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['atributos.BACKREST_HEIGHT', 'atributos.IS_SWIVEL'])
            ->assertJsonPath('errors', fn (array $e) => $this->primeiro($e, 'atributos.IS_SWIVEL', 'Escolha Sim ou Não em “É giratória”.'));

        // A forma errada é barrada antes do serviço, por índice e em português.
        $this->ficha($p['produto'], [['id' => 'BRAND', 'valor' => ['a' => ['b' => 1]]]])
            ->assertUnprocessable()->assertJsonPath('errors', fn (array $e) => $this->primeiro($e, 'atributos.0.valor', 'Valor inválido.'));
        $this->actingAs($this->dev)->putJson($this->url($p['produto'], 'ficha-tecnica'), [])
            ->assertUnprocessable()->assertJsonPath('errors.atributos.0', 'Envie a ficha técnica.');
        $this->ficha($p['produto'], [['valor' => 'sem id']])
            ->assertUnprocessable()->assertJsonPath('errors', fn (array $e) => $this->primeiro($e, 'atributos.0.id', 'Campo da ficha sem identificação.'));

        $this->assertSame(['BRAND' => 'Antiga'], $this->fichaNoBanco($p['produto']));
        $this->assertCount(0, $this->eventosDaTrilha());
        Queue::assertNothingPushed();
    }

    public function test_o_id_de_eixo_e_o_desconhecido_sao_ignorados_e_o_modelo_antigo_fica(): void
    {
        $p = $this->cadeira(['Preto']);
        $this->atributo($p['produto'], FichaTecnicaDaCategoria::ID_MODELO, 'Modelo do cliente');
        // Cor salva de antes (o produto passou a variar por cor): gravar substitui, e ela sai.
        $this->atributo($p['produto'], 'COLOR', 'Coral');

        $r = $this->ficha($p['produto'], [
            ['id' => 'COLOR', 'valor' => '283148'],
            ['id' => 'CAMPO_QUE_NAO_EXISTE', 'valor' => 'x'],
            ['id' => 'BRAND', 'valor' => 'ECF'],
        ])->assertOk()->json();

        $this->assertSame(['BRAND' => 'ECF', 'MODEL' => 'Modelo do cliente'], $this->fichaNoBanco($p['produto']));
        $this->assertSame(['BRAND'], array_column($r['ficha']['salvos'], 'id'), 'o Modelo fica no banco, fora da tela');
        $this->assertNotContains('ficha: cor', $r['situacao']['faltas'], 'a cor é do eixo, não da ficha');
    }

    public function test_sem_categoria_e_422_sem_chamar_o_ml(): void
    {
        $p = $this->cadeira([null], ['categoria_ml_id' => null, 'categoria_ml_nome' => null, 'categoria_ml_caminho' => null]);

        $this->ficha($p['produto'], [['id' => 'BRAND', 'valor' => 'ECF']])
            ->assertUnprocessable()
            ->assertJsonPath('errors.atributos.0', 'Escolha a categoria do produto antes de preencher a ficha técnica.');

        Http::assertNothingSent();
        $this->assertSame(0, EstruturaProdutoAtributo::query()->count());
    }

    public function test_com_o_catalogo_fora_e_422_e_nada_e_apagado(): void
    {
        $p = $this->cadeira();
        $this->atributo($p['produto'], 'BRAND', 'Antiga');
        $this->atributo($p['produto'], 'IS_GAMER', 'Sim');
        $this->atributosFora = true;

        $this->ficha($p['produto'], [['id' => 'BRAND', 'valor' => 'Nova']])
            ->assertUnprocessable()
            ->assertJsonPath('errors.atributos.0', 'A ficha técnica desta categoria não está disponível agora. Tente novamente em instantes.');

        $this->assertSame(['BRAND' => 'Antiga', 'IS_GAMER' => 'Sim'], $this->fichaNoBanco($p['produto']));
        $this->assertCount(0, $this->eventosDaTrilha());
    }

    /** A gaveta com a ficha de uma categoria que o "Salvar" das linhas não gravou: gravar por cima apagaria a outra. */
    public function test_a_ficha_de_outra_categoria_que_nao_a_gravada_e_422_e_nada_e_apagado(): void
    {
        $p = $this->cadeira();
        $this->atributo($p['produto'], 'BRAND', 'Antiga');

        $this->ficha($p['produto'], [['id' => 'BRAND', 'valor' => 'Nova']], self::FECHADA)
            ->assertUnprocessable()
            ->assertJsonPath('errors.categoria.0', 'A categoria mudou: salve o produto antes de gravar a ficha.');
        $this->assertSame('Antiga', EstruturaProdutoAtributo::query()->where('atributo_id', 'BRAND')->value('valor'));

        // A mesma categoria (sem caixa) passa.
        $this->ficha($p['produto'], [['id' => 'BRAND', 'valor' => 'Nova']], strtolower(self::CADEIRA))->assertOk();
        $this->assertSame('Nova', EstruturaProdutoAtributo::query()->where('atributo_id', 'BRAND')->value('valor'));
    }

    public function test_depois_do_get_dos_campos_a_lista_nao_diz_a_conferir(): void
    {
        $p = $this->cadeira();

        $antes = $this->produtoNaTrilha($this->trilha(), (int) $p['produto']->id);
        $this->assertSame('nao_conferida', $antes['ficha'], 'sem a definição guardada, a Lista diz "a conferir"');
        $this->assertSame([], array_values(array_filter($antes['faltas'], fn ($f) => str_starts_with($f, 'ficha'))));

        $this->actingAs($this->dev)->getJson("/trilha/{$this->empresa->id}/categorias/".self::CADEIRA.'/campos')->assertOk();

        $depois = $this->produtoNaTrilha($this->trilha(), (int) $p['produto']->id);
        $this->assertSame('incompleta', $depois['ficha']);
        $this->assertContains('ficha: marca', $depois['faltas']);
    }

    // ═══ A categoria na linha (rota 3) ═════════════════════════════════════

    public function test_a_categoria_nova_anunciavel_grava_confirmada(): void
    {
        $p = $this->cadeira([null], ['categoria_ml_id' => null, 'categoria_ml_nome' => null, 'categoria_ml_caminho' => null]);

        // Sem caixa e com espaço: a régua do Portal (`MLB` + dígitos), em maiúsculas.
        $r = $this->linhas($p, ['categoria_ml_id' => ' mlb193945 '])->assertOk()->json();

        $this->assertSame([], $r['erros']);
        $produto = $p['produto']->fresh();
        $this->assertSame([self::CADEIRA, 'Cadeiras para Escritório'], [$produto->categoria_ml_id, $produto->categoria_ml_nome]);
        $this->assertStringEndsWith('Cadeiras para Escritório', (string) $produto->categoria_ml_caminho);
        $this->assertSame('confirmada', $r['produto']['categoria']['estado']);
        $this->assertNotContains('categoria', $r['situacao']['faltas']);
        $this->assertContains('linhas', $this->eventosDaTrilha()->map(fn (Activity $a) => $a->getExtraProperty('passo'))->all());
    }

    public function test_a_folha_que_nao_aceita_anuncio_novo_e_422_e_nada_da_linha_grava(): void
    {
        $p = $this->cadeira();

        $this->linhas($p, ['categoria_ml_id' => self::FECHADA, 'nome' => 'Cadeira Renomeada'])
            ->assertUnprocessable()
            ->assertJsonPath('errors', fn (array $e) => $this->primeiro($e, 'linhas.0.categoria_ml_id', CadastroNaTrilha::MSG_CATEGORIA_NAO_ANUNCIAVEL));

        $produto = $p['produto']->fresh();
        $this->assertSame([self::CADEIRA, 'Cadeira Alfa'], [$produto->categoria_ml_id, $produto->nome]);
        $this->assertCount(0, $this->eventosDaTrilha());
    }

    public function test_a_categoria_que_nao_e_folha_continua_o_erro_da_linha_do_servico(): void
    {
        $p = $this->cadeira();

        $r = $this->linhas($p, ['categoria_ml_id' => self::PAI, 'chave' => 'k1'])->assertOk()->json();

        $this->assertCount(1, $r['erros']);
        $this->assertSame('k1', $r['erros'][0]['chave']);
        $this->assertSame('Escolha uma categoria mais específica (a última do caminho).', $r['erros'][0]['campos']['categoria_ml_id']);
        $this->assertSame(self::CADEIRA, $p['produto']->fresh()->categoria_ml_id);
    }

    public function test_com_o_ml_fora_a_categoria_grava_nao_validada_com_aviso_como_no_portal(): void
    {
        $p = $this->cadeira([null], ['categoria_ml_id' => null, 'categoria_ml_nome' => null, 'categoria_ml_caminho' => null]);
        $this->categoriaFora = true;

        $r = $this->linhas($p, ['categoria_ml_id' => self::CADEIRA])->assertOk()->json();

        $this->assertSame([], $r['erros']);
        $this->assertContains('Categoria '.self::CADEIRA.' não validada agora.', $r['avisos']);
        $this->assertSame([self::CADEIRA, null], [$p['produto']->fresh()->categoria_ml_id, $p['produto']->fresh()->categoria_ml_nome]);
        $this->assertSame('nao_validada', $r['produto']['categoria']['estado']);
    }

    public function test_vazio_nao_mexe_null_limpa_e_texto_que_nao_e_categoria_e_422(): void
    {
        $p = $this->cadeira();

        // `''` = não mexi: nada vai ao ML, a categoria fica.
        $this->linhas($p, ['categoria_ml_id' => '  '])->assertOk()->assertJsonPath('mensagem', 'Nada mudou.');
        $this->assertSame(self::CADEIRA, $p['produto']->fresh()->categoria_ml_id);
        Http::assertNothingSent();

        // Texto que não é categoria (no Portal viraria "a confirmar"): 422.
        foreach (['Cadeiras', 'MLB', 'MLB12x', 123] as $invalida) {
            $this->linhas($p, ['categoria_ml_id' => $invalida])
                ->assertUnprocessable()
                ->assertJsonPath('errors', fn (array $e) => $this->primeiro($e, 'linhas.0.categoria_ml_id', CadastroNaTrilha::MSG_CATEGORIA_INVALIDA));
        }
        $this->assertSame([self::CADEIRA, 'Cadeiras para Escritório'], [$p['produto']->fresh()->categoria_ml_id, $p['produto']->fresh()->categoria_ml_nome]);

        // `null` = limpar (o produto não está no Publicador).
        $r = $this->linhas($p, ['categoria_ml_id' => null])->assertOk()->json();
        $this->assertSame([null, null], [$p['produto']->fresh()->categoria_ml_id, $p['produto']->fresh()->categoria_ml_nome]);
        $this->assertContains('categoria', $r['situacao']['faltas']);
    }

    public function test_com_o_produto_no_publicador_trocar_ou_limpar_a_categoria_e_422_e_a_mesma_passa(): void
    {
        $p = $this->cadeira();
        $this->rascunhoDoPub($this->grupoNoPublicador($this->empresa, $p));

        foreach ([self::FECHADA, 'MLB31447', null] as $outra) {
            $this->linhas($p, ['categoria_ml_id' => $outra, 'nome' => 'Cadeira Renomeada'])
                ->assertUnprocessable()
                ->assertJsonPath('errors', fn (array $e) => $this->primeiro($e, 'linhas.0.categoria_ml_id', CadastroNaTrilha::MSG_CATEGORIA_NO_PUBLICADOR));
        }
        Http::assertNothingSent();
        $this->assertSame([self::CADEIRA, 'Cadeira Alfa'], [$p['produto']->fresh()->categoria_ml_id, $p['produto']->fresh()->nome]);

        // A tela devolve a categoria de sempre em toda linha: a mesma (sem caixa) não é troca.
        $this->linhas($p, ['categoria_ml_id' => strtolower(self::CADEIRA), 'nome' => 'Cadeira Renomeada'])->assertOk()->assertJsonPath('erros', []);
        $this->assertSame([self::CADEIRA, 'Cadeira Renomeada'], [$p['produto']->fresh()->categoria_ml_id, $p['produto']->fresh()->nome]);
    }

    // ═══ "Preencher com IA" (rota 9, F2b) ══════════════════════════════════

    public function test_a_ia_sugere_so_os_vazios_da_tela_e_nao_grava_nada(): void
    {
        $p = $this->cadeira();
        $this->respostaDaIa = ['BRAND' => 'Inventada', 'IS_GAMER' => 'Não', 'REQUIRES_ASSEMBLY' => 'Talvez'];

        $r = $this->ia($p['produto'], ['categoria' => self::CADEIRA, 'titulo' => 'Cadeira de escritório giratória',
            // Na tela, sem salvar: a Marca já está preenchida.
            'atributos' => [['id' => 'brand', 'valor' => 'ECF']]])->assertOk()->json();

        $this->assertSame(['sugestoes', 'faltavam'], array_keys($r));
        $sugestoes = collect($r['sugestoes'])->keyBy('id');
        $this->assertSame(['IS_GAMER'], $sugestoes->keys()->all(), 'a Marca estava na tela; "Talvez" não é Sim nem Não');
        $this->assertSame(['id' => 'IS_GAMER', 'nome' => 'É gamer', 'valor' => 'Não', 'valor_id' => null, 'unidade' => null], $sugestoes['IS_GAMER']);
        $this->assertSame(1, $this->chamadasDaIa);

        $this->assertSame(0, EstruturaProdutoAtributo::query()->count(), 'o botão não grava');
        $this->assertCount(0, $this->eventosDaTrilha());
        Queue::assertNothingPushed();
    }

    public function test_a_ia_sem_categoria_salva_ou_com_a_tela_em_outra_categoria_e_422_sem_chamar_nada(): void
    {
        $sem = $this->cadeira([null], ['categoria_ml_id' => null, 'categoria_ml_nome' => null]);
        $this->ia($sem['produto'], ['categoria' => self::CADEIRA, 'atributos' => []])
            ->assertUnprocessable()->assertExactJson(['message' => 'Escolha a categoria e salve o produto antes de usar a IA.']);

        $com = $this->cadeira([null], [], 'Cadeira Beta', 'CB');
        $this->ia($com['produto'], ['categoria' => self::FECHADA, 'atributos' => []])
            ->assertUnprocessable()->assertExactJson(['message' => 'A categoria mudou: salve o produto antes de usar a IA.']);

        $this->assertSame(0, $this->chamadasDaIa);
        Http::assertNothingSent();

        // Corpo malformado: 422 por campo, em português.
        $this->ia($com['produto'], ['categoria' => self::CADEIRA])->assertUnprocessable()->assertJsonPath('errors.atributos.0', 'Envie a ficha técnica.');
    }

    public function test_a_ia_fora_do_ar_e_422_com_mensagem(): void
    {
        $p = $this->cadeira();
        $this->iaFora = true;

        $this->ia($p['produto'], ['categoria' => self::CADEIRA, 'atributos' => []])
            ->assertUnprocessable()->assertExactJson(['message' => 'A IA não respondeu agora. Tente de novo em instantes.']);
        $this->assertSame(0, EstruturaProdutoAtributo::query()->count());
    }

    // ═══ A trava (pergunta 1) ══════════════════════════════════════════════

    public function test_o_produto_travado_responde_409_na_ficha_e_na_ia_e_nada_acontece(): void
    {
        $p = $this->cadeira();
        $this->atributo($p['produto'], 'BRAND', 'Antiga');
        $this->rascunhoDoPub($this->grupoNoPublicador($this->empresa, $p), PubRascunho::VALIDATED);

        $this->ficha($p['produto'], [['id' => 'BRAND', 'valor' => 'Nova']])
            ->assertStatus(409)->assertJsonPath('travado', true)
            ->assertJsonPath('motivo', fn (string $m) => str_contains($m, 'já saiu do Planejamento'));
        $this->ia($p['produto'], ['categoria' => self::CADEIRA, 'atributos' => []])->assertStatus(409)->assertJsonPath('travado', true);
        // A categoria, também: a trava vem antes de consultar o ML.
        $this->linhas($p, ['categoria_ml_id' => self::FECHADA])->assertStatus(409);

        $this->assertSame('Antiga', EstruturaProdutoAtributo::query()->where('atributo_id', 'BRAND')->value('valor'));
        $this->assertSame(0, $this->chamadasDaIa);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    // ═══ apoio ═══

    /**
     * A cadeira completa pela régua da Lista (3 fotos, caixa, custo, título, descrição, família, ambiente) na
     * categoria real da sondagem — sem a definição guardada, a ficha dela ainda está "a conferir".
     *
     * @param  list<?string>  $cores
     */
    private function cadeira(array $cores = [null], array $dados = [], string $nome = 'Cadeira Alfa', string $codigo = 'CA'): array
    {
        return $this->produtoCompleto($this->empresa, $nome, $codigo, $cores, array_merge([
            'categoria_ml_id' => self::CADEIRA, 'categoria_ml_nome' => 'Cadeiras para Escritório',
            'categoria_ml_caminho' => 'Casa, Móveis e Decoração > Móveis para Casa > Cadeiras, Sofás e Banquetas > Cadeiras para Escritório',
        ], $dados));
    }

    /**
     * A 1ª mensagem do campo em `errors`, pela chave EXATA (`linhas.0.categoria_ml_id`, `atributos.BRAND`) — o
     * `assertJsonPath` lê o ponto como nível e não acharia a chave.
     */
    private function primeiro(array $erros, string $campo, string $mensagem): bool
    {
        $this->assertArrayHasKey($campo, $erros, json_encode($erros, JSON_UNESCAPED_UNICODE));
        $this->assertSame($mensagem, $erros[$campo][0]);

        return true;
    }

    /** A ficha gravada do produto, `atributo_id => valor`, em ordem de id. */
    private function fichaNoBanco(EstruturaProduto $produto): array
    {
        return EstruturaProdutoAtributo::query()->where('produto_id', $produto->id)->orderBy('atributo_id')->pluck('valor', 'atributo_id')->all();
    }

    private function atributo(EstruturaProduto $produto, string $id, string $valor): void
    {
        EstruturaProdutoAtributo::create(['company_id' => $this->empresa->id, 'produto_id' => $produto->id,
            'atributo_id' => $id, 'atributo_nome' => $id, 'valor' => $valor]);
    }

    /** Todo obrigatório da cadeira (pela definição real) com um valor válido para o tipo dele. */
    private function obrigatoriosPreenchidos(): array
    {
        $saida = [];
        foreach (app(FichaTecnicaDaCategoria::class)->definicao(self::CADEIRA) as $grupo) {
            foreach ($grupo['campos'] as $c) {
                if (! $c['obrigatorio']) {
                    continue;
                }
                $saida[] = match ($c['tipo']) {
                    FichaTecnicaDaCategoria::TIPO_NUMERO_UNIDADE => ['id' => $c['id'], 'valor' => '45', 'unidade' => $c['unidade_padrao']],
                    FichaTecnicaDaCategoria::TIPO_NUMERO         => ['id' => $c['id'], 'valor' => '2'],
                    FichaTecnicaDaCategoria::TIPO_SIM_NAO        => ['id' => $c['id'], 'valor' => 'Sim'],
                    FichaTecnicaDaCategoria::TIPO_LISTA          => ['id' => $c['id'], 'valor' => (string) $c['valores'][0]['id']],
                    default                                      => ['id' => $c['id'], 'valor' => 'ECF'],
                };
            }
        }
        $this->assertGreaterThan(5, count($saida), 'a cadeira real tem vários obrigatórios');

        return $saida;
    }

    private function url(EstruturaProduto $produto, string $fim): string
    {
        return "/trilha/{$this->empresa->id}/produtos/{$produto->id}/{$fim}";
    }

    private function ficha(EstruturaProduto $produto, array $atributos, ?string $categoria = null): TestResponse
    {
        $corpo = ['atributos' => $atributos] + ($categoria !== null ? ['categoria' => $categoria] : []);

        return $this->semPortal($this->actingAs($this->dev)->putJson($this->url($produto, 'ficha-tecnica'), $corpo));
    }

    private function ia(EstruturaProduto $produto, array $corpo): TestResponse
    {
        return $this->semPortal($this->actingAs($this->dev)->postJson($this->url($produto, 'ficha-tecnica/ia'), $corpo));
    }

    /** A linha da 1ª variação do produto, com `codigo` e `nome` (o normalizador exige) e os campos pedidos. */
    private function linhas(array $p, array $campos): TestResponse
    {
        $v = $p['variacoes'][0];
        $linha = array_merge(['id' => $v->id, 'codigo' => $v->codigo, 'nome' => $p['produto']->nome], $campos);

        return $this->semPortal($this->actingAs($this->dev)->postJson($this->url($p['produto'], 'linhas'), ['linhas' => [$linha]]));
    }

    private function semPortal(TestResponse $resposta): TestResponse
    {
        $this->assertStringNotContainsString('/portal/', (string) $resposta->getContent());
        $this->assertStringNotContainsString('\/portal\/', (string) $resposta->getContent());

        return $resposta;
    }

    private function trilha(): array
    {
        return app(TrilhaDaEmpresa::class)->montar($this->empresa->fresh());
    }

    private function eventosDaTrilha()
    {
        return Activity::query()->where('log_name', 'portal')->get()
            ->filter(fn (Activity $a) => $a->getExtraProperty('evento') === 'trilha_cadastro_salvo')->values();
    }

    /** O ML de mentira, por URL. O que não está aqui é 404 (a categoria que "não existe"). */
    private function responder(Request $r)
    {
        $caminho = (string) parse_url($r->url(), PHP_URL_PATH);
        if (! preg_match('#/categories/(MLB\d+)(/[a-z_/]+)?$#', $caminho, $m)) {
            return Http::response([], 404);
        }
        [$id, $sub] = [$m[1], $m[2] ?? ''];

        if ($sub === '' && $this->categoriaFora) {
            return Http::response(['message' => 'erro'], 500);
        }
        if ($sub === '/attributes' && $this->atributosFora) {
            return Http::response(['message' => 'erro'], 500);
        }
        if ($id === self::FECHADA && $sub === '') {
            return Http::response(['id' => self::FECHADA, 'name' => 'Cadeiras antigas', 'children_categories' => [],
                'settings' => ['listing_allowed' => false, 'max_title_length' => 60],
                'path_from_root' => [['id' => 'MLB1574', 'name' => 'Casa, Móveis e Decoração'], ['id' => self::FECHADA, 'name' => 'Cadeiras antigas']]]);
        }
        if ($id === self::PAI && $sub === '') {
            return Http::response($this->fixture('pai_'.self::PAI.'.json'));
        }
        if ($id !== self::CADEIRA) {
            return Http::response(['message' => 'not_found'], 404);
        }

        return Http::response(match ($sub) {
            ''                       => $this->fixture('categoria.json'),
            '/attributes'            => $this->fixture('atributos.json'),
            '/technical_specs/input' => $this->fixture('technical_specs_input.json'),
            '/sale_terms'            => $this->fixture('sale_terms.json'),
            default                  => ['message' => 'not_found'],
        });
    }

    private function fixture(string $arquivo): array
    {
        return json_decode((string) file_get_contents(base_path('tests/fixtures-ml/sondagem/publico/categorias/'.self::CADEIRA."/{$arquivo}")), true)['resposta'];
    }
}
