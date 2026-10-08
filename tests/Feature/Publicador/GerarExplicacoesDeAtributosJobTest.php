<?php

namespace Tests\Feature\Publicador;

use App\Jobs\Publicador\GerarExplicacoesDeAtributosJob;
use App\Models\AtributoExplicacao;
use App\Services\Ia\AnaliseAnuncioService;
use App\Services\Publicador\ExplicacaoDeAtributos;
use Illuminate\Http\Client\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * A IA escreve a explicação dos campos sem texto: UMA chamada, JSON por id, o que não passa na
 * regra é descartado, nada sobrescreve o que já existe. A IA é simulada: o fake HTTP é registrado
 * UMA vez e responde por `$this->conteudo` (learnings §5); nenhum outro host é aceito.
 */
class GerarExplicacoesDeAtributosJobTest extends TestCase
{
    use RefreshDatabase;

    /** O `content` que a IA devolve agora. */
    private string $conteudo = '{}';

    private int $chamadas = 0;

    private const PEDIDOS = [
        ['id' => 'SEAT_WIDTH', 'nome' => 'Largura do assento', 'tipo' => 'number_unit', 'unidades' => ['cm'], 'valores' => []],
        ['id' => 'WITH_WHEELS', 'nome' => 'Com rodas', 'tipo' => 'boolean', 'unidades' => [], 'valores' => ['Sim', 'Não']],
        ['id' => 'FOOT_RING_MATERIALS', 'nome' => 'Materiais do aro para pés', 'tipo' => 'string', 'unidades' => [], 'valores' => []],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.llm' => ['base_url' => 'https://ia.teste/v1', 'key' => 'chave-de-teste', 'model' => 'modelo-a', 'fallbacks' => '', 'timeout' => 30, 'max_tokens' => 1000]]);
        Http::preventStrayRequests();
        Http::fake(['ia.teste/*' => function () {
            $this->chamadas++;

            return Http::response(['model' => 'modelo-a', 'choices' => [['message' => ['content' => $this->conteudo]]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20]]);
        }]);
    }

    private function rodar(array $pedidos = self::PEDIDOS, ?string $contexto = 'Cadeiras de Escritório'): void
    {
        (new GerarExplicacoesDeAtributosJob($pedidos, $contexto))->handle(app(AnaliseAnuncioService::class), app(ExplicacaoDeAtributos::class));
    }

    public function test_json_valido_vira_linha_com_origem_ia_e_modelo_numa_chamada_so(): void
    {
        $this->conteudo = "```json\n".json_encode([
            'SEAT_WIDTH' => 'Medida de um lado ao outro do assento, onde a pessoa senta.',
            'WITH_WHEELS' => 'Se a cadeira tem rodinhas na base para se mover.',
            'FOOT_RING_MATERIALS' => 'Do que é feito o aro onde se apoiam os pés.',
            'NAO_PEDIDO' => 'Não deve entrar.',
        ])."\n```";

        $this->rodar();

        $this->assertSame(1, $this->chamadas);
        $this->assertSame(3, AtributoExplicacao::count());
        $l = AtributoExplicacao::where('atributo_id', 'SEAT_WIDTH')->first();
        $this->assertSame(['ia', 'modelo-a', 'Largura do assento'], [$l->origem, $l->modelo, $l->nome]);
        $this->assertNull(AtributoExplicacao::where('atributo_id', 'NAO_PEDIDO')->first());

        // O prompt leva os campos e pede texto neutro; a chave vai no cabeçalho, nunca no corpo.
        Http::assertSent(function (Request $r) {
            $prompt = $r['messages'][0]['content'];

            return str_contains($prompt, 'SEAT_WIDTH | Largura do assento | tipo: number_unit | unidades: cm')
                && str_contains($prompt, 'Cadeiras de Escritório')
                && str_contains($prompt, 'não cite plataforma')
                && $r->hasHeader('Authorization', 'Bearer chave-de-teste');
        });

        // Agora a tela lê o texto guardado.
        $this->assertSame('Do que é feito o aro onde se apoiam os pés.', app(ExplicacaoDeAtributos::class)->paraAtributos([['id' => 'FOOT_RING_MATERIALS', 'nome' => 'x']])['FOOT_RING_MATERIALS']);
    }

    public function test_json_embrulhado_em_explicacoes_tambem_vale(): void
    {
        $this->conteudo = json_encode(['explicacoes' => ['WITH_WHEELS' => 'Se a cadeira tem rodinhas.']]);

        $this->rodar();

        $this->assertSame('Se a cadeira tem rodinhas.', AtributoExplicacao::where('atributo_id', 'WITH_WHEELS')->value('texto'));
    }

    public function test_resposta_que_nao_e_json_nao_grava_nada_e_nao_quebra_o_job(): void
    {
        Log::spy();
        $this->conteudo = 'Desculpe, não consigo ajudar com isso.';

        $this->rodar();

        $this->assertSame(0, AtributoExplicacao::count());
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains((string) $m, '[Publicador] IA de explicações falhou'))->once();
    }

    public function test_texto_longo_com_html_ou_que_cita_plataforma_e_descartado(): void
    {
        $this->conteudo = json_encode([
            'SEAT_WIDTH' => str_repeat('Largura do assento. ', 15),
            'WITH_WHEELS' => 'Informe se tem rodas <b>no anúncio</b>.',
            'FOOT_RING_MATERIALS' => 'Material do aro, como pede o Mercado Livre.',
        ]);

        $this->rodar();

        $this->assertSame(0, AtributoExplicacao::count());
    }

    public function test_idempotente_nao_duplica_nem_sobrescreve_o_que_ja_existe(): void
    {
        AtributoExplicacao::create(['atributo_id' => 'WITH_WHEELS', 'nome' => 'Com rodas', 'texto' => 'Texto do ML.', 'origem' => 'ml']);
        $this->conteudo = json_encode(['SEAT_WIDTH' => 'Primeira versão.', 'WITH_WHEELS' => 'Versão da IA.']);
        $this->rodar();

        $this->conteudo = json_encode(['SEAT_WIDTH' => 'Segunda versão.']);
        $this->rodar();

        $this->assertSame(2, AtributoExplicacao::count());
        $this->assertSame('Primeira versão.', AtributoExplicacao::where('atributo_id', 'SEAT_WIDTH')->value('texto'));
        $ml = AtributoExplicacao::where('atributo_id', 'WITH_WHEELS')->first();
        $this->assertSame(['Texto do ML.', 'ml'], [$ml->texto, $ml->origem], 'o texto do ML não é trocado pelo da IA');
    }

    public function test_fila_default_tentativa_unica_e_failed_registra_o_erro(): void
    {
        $job = new GerarExplicacoesDeAtributosJob(self::PEDIDOS);
        $this->assertSame('default', $job->queue);
        $this->assertSame(1, $job->tries);
        $this->assertSame(300, $job->timeout);

        Log::spy();
        $job->failed(new \RuntimeException('estourou o tempo'));
        Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains((string) $m, '[Publicador] IA de explicações quebrou') && str_contains((string) $m, 'estourou o tempo'))->once();
    }

    public function test_lista_vazia_nao_chama_a_ia(): void
    {
        $this->rodar([]);

        $this->assertSame(0, $this->chamadas);
    }
}
