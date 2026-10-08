<?php

namespace Tests\Feature\Publicador;

use App\Jobs\Publicador\GerarExplicacoesDeAtributosJob;
use App\Models\AtributoExplicacao;
use App\Services\Publicador\ExplicacaoDeAtributos;
use App\Support\Publicador\Schema\AtributoClassificado;
use App\Support\Publicador\Schema\ClassificadorAtributos;
use App\Support\Publicador\Schema\ContextoClassificacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/**
 * A explicação de cada campo (08/10/2026): glossário > guardado > ML > texto montado + IA
 * enfileirada uma vez por atributo; e o filtro de sigilo da versão do Portal.
 * Nenhuma chamada real: a fila é falsa e o HTTP é barrado.
 */
class ExplicacaoDeAtributosTest extends TestCase
{
    use CarregaSchemas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
    }

    private function servico(): ExplicacaoDeAtributos
    {
        return app(ExplicacaoDeAtributos::class);
    }

    public function test_prioridade_glossario_depois_guardado_depois_ml_depois_texto_montado(): void
    {
        Queue::fake();
        AtributoExplicacao::create(['atributo_id' => 'SEAT_DEPTH', 'nome' => 'Profundidade do assento', 'texto' => 'Do encosto até a ponta do assento.', 'origem' => 'ia', 'modelo' => 'm1']);
        // Guardado também para um id do glossário: o glossário vence.
        AtributoExplicacao::create(['atributo_id' => 'MPN', 'nome' => 'MPN', 'texto' => 'texto velho', 'origem' => 'ia']);

        $e = $this->servico()->paraAtributos([
            ['id' => 'MPN', 'nome' => 'MPN', 'tooltip' => 'tooltip do ML'],
            ['id' => 'SEAT_DEPTH', 'nome' => 'Profundidade do assento', 'tooltip' => 'tooltip novo do ML', 'tipo' => 'number_unit', 'unidades' => ['cm']],
            ['id' => 'BACKREST_HEIGHT', 'nome' => 'Altura do encosto', 'tooltip' => 'Do assento até o topo do encosto.', 'hint' => 'Meça com fita.', 'tipo' => 'number_unit', 'unidades' => ['cm']],
            ['id' => 'SEAT_WIDTH', 'nome' => 'Largura do assento', 'tipo' => 'number_unit', 'unidades' => ['cm']],
        ]);

        $this->assertSame(config('publicador_glossario.atributos.MPN'), $e['MPN']);
        $this->assertSame('Do encosto até a ponta do assento.', $e['SEAT_DEPTH']);
        $this->assertSame('Do assento até o topo do encosto. Meça com fita.', $e['BACKREST_HEIGHT'], 'tooltip + hint do ML');
        $this->assertSame('Largura do assento, em centímetros.', $e['SEAT_WIDTH']);

        // O ML foi guardado na 1ª vez, com origem `ml`; o glossário nunca vai para a tabela.
        $ml = AtributoExplicacao::where('atributo_id', 'BACKREST_HEIGHT')->first();
        $this->assertSame('ml', $ml->origem);
        $this->assertNull($ml->modelo);
        $this->assertSame('texto velho', AtributoExplicacao::where('atributo_id', 'MPN')->value('texto'));

        // Só o sem explicação foi para a IA, na fila `default`.
        Queue::assertPushedOn('default', GerarExplicacoesDeAtributosJob::class, fn ($j) => array_column($j->atributos, 'id') === ['SEAT_WIDTH']);
        Queue::assertPushed(GerarExplicacoesDeAtributosJob::class, 1);
    }

    public function test_texto_do_ml_guardado_vale_mesmo_se_o_ml_mudar_depois(): void
    {
        Queue::fake();
        $this->servico()->paraAtributos([['id' => 'COLOR_X', 'nome' => 'Cor X', 'tooltip' => 'Primeiro texto.']]);
        $e = $this->servico()->paraAtributos([['id' => 'COLOR_X', 'nome' => 'Cor X', 'tooltip' => 'Texto trocado pelo ML.']]);

        $this->assertSame('Primeiro texto.', $e['COLOR_X']);
        $this->assertSame(1, AtributoExplicacao::count());
    }

    public function test_enfileira_uma_vez_por_atributo_em_lotes_de_40_e_nunca_para_oculto(): void
    {
        Queue::fake();
        $atributos = array_map(fn ($i) => ['id' => "ATTR_{$i}", 'nome' => "Atributo {$i}", 'tipo' => 'string'], range(1, 45));
        $atributos[] = ['id' => 'OCULTO_1', 'nome' => 'Oculto', 'tipo' => 'string', 'oculto' => true];

        $e = $this->servico()->paraAtributos($atributos, 'Cadeiras de Escritório');

        $this->assertCount(46, $e);
        $this->assertSame('Oculto do produto.', $e['OCULTO_1'], 'oculto recebe texto, mas não gasta IA');
        $lotes = Queue::pushed(GerarExplicacoesDeAtributosJob::class)->map(fn ($j) => count($j->atributos))->all();
        $this->assertSame([40, 5], $lotes);
        $this->assertNotContains('OCULTO_1', Queue::pushed(GerarExplicacoesDeAtributosJob::class)->flatMap(fn ($j) => array_column($j->atributos, 'id'))->all());
        $this->assertSame('Cadeiras de Escritório', Queue::pushed(GerarExplicacoesDeAtributosJob::class)->first()->contexto);

        // A tela abre de novo: a trava segura a mesma geração.
        $this->servico()->paraAtributos($atributos);
        Queue::assertPushed(GerarExplicacoesDeAtributosJob::class, 2);

        // Atributo novo ainda entra.
        $this->servico()->paraAtributos([['id' => 'ATTR_NOVO', 'nome' => 'Novo', 'tipo' => 'string']]);
        Queue::assertPushed(GerarExplicacoesDeAtributosJob::class, 3);
    }

    public function test_com_fila_sync_nao_enfileira_para_a_tela_nao_esperar_a_ia(): void
    {
        // Sem Queue::fake: a fila dos testes é `sync`, onde o Job rodaria dentro da abertura da tela.
        $rodou = false;
        Queue::before(function () use (&$rodou) { $rodou = true; });

        $e = $this->servico()->paraAtributos([['id' => 'SEAT_WIDTH', 'nome' => 'Largura do assento', 'tipo' => 'number_unit', 'unidades' => ['cm']]]);

        $this->assertSame('Largura do assento, em centímetros.', $e['SEAT_WIDTH']);
        $this->assertFalse($rodou, 'nenhum Job rodou');
        $this->assertFalse(Cache::has('publicador:explicacao:SEAT_WIDTH'), 'nem chegou a travar o atributo');
        $this->assertSame(0, AtributoExplicacao::count());
        Http::assertNothingSent();
    }

    public function test_sem_a_tabela_a_tela_continua_com_glossario_e_texto_montado(): void
    {
        Queue::fake();
        Schema::drop('atributo_explicacoes');

        $e = $this->servico()->paraAtributos([['id' => 'GTIN', 'nome' => 'Código universal'], ['id' => 'X', 'nome' => 'Altura', 'tooltip' => 'Do ML.']]);

        $this->assertSame(config('publicador_glossario.atributos.GTIN'), $e['GTIN']);
        $this->assertSame('Do ML.', $e['X']);
        Queue::assertNothingPushed();
    }

    public function test_portal_troca_o_texto_que_entrega_o_destino_pelo_texto_montado(): void
    {
        Queue::fake();
        $e = $this->servico()->paraPortal([
            ['id' => 'BRAND', 'nome' => 'Marca', 'tooltip' => 'Marca do anúncio.'],
            ['id' => 'X_ML', 'nome' => 'Altura', 'tipo' => 'number_unit', 'unidades' => ['cm'], 'tooltip' => 'Informe como no seu anúncio do Mercado Livre.'],
            ['id' => 'X_NEUTRO', 'nome' => 'Profundidade', 'tooltip' => 'Do encosto até a ponta.'],
            ['id' => 'LIMITED_MARKETPLACE_VISIBILITY_REASONS', 'nome' => 'Razões de visibilidade limitada no Marketplace', 'tooltip' => 'Visibilidade no marketplace.'],
        ]);

        $this->assertSame(config('publicador_glossario.atributos.BRAND'), $e['BRAND'], 'o glossário é neutro');
        $this->assertSame('Altura, em centímetros.', $e['X_ML']);
        $this->assertSame('Do encosto até a ponta.', $e['X_NEUTRO']);
        $this->assertSame('Característica do produto.', $e['LIMITED_MARKETPLACE_VISIBILITY_REASONS']);
        foreach ($e as $id => $texto) {
            $this->assertFalse(ExplicacaoDeAtributos::revelaDestino($texto), $id);
        }

        // O editor interno continua vendo o texto do ML (é a mesma linha guardada).
        $this->assertSame('Informe como no seu anúncio do Mercado Livre.', $this->servico()->paraAtributos([['id' => 'X_ML', 'nome' => 'Altura']])['X_ML']);
    }

    public function test_todo_atributo_visivel_das_4_categorias_da_sondagem_ganha_explicacao(): void
    {
        Queue::fake();
        foreach ([self::CADEIRA, self::FURADEIRA, self::CAMISETA, self::PASTILHA] as $cat) {
            $s = (new ClassificadorAtributos())->classificar(self::schema($cat), new ContextoClassificacao('new', [], []));
            $lista = array_values(array_map(fn (AtributoClassificado $a) => [
                'id' => $a->id, 'nome' => $a->nome, 'tooltip' => $a->tooltip, 'hint' => $a->dica, 'tipo' => $a->valueType,
                'unidades' => $a->unidades, 'unidade_padrao' => $a->unidadePadrao, 'valores' => $a->valores, 'oculto' => $a->secao === AtributoClassificado::SECAO_OCULTO,
            ], $s->atributos));

            $e = $this->servico()->paraAtributos($lista);

            $this->assertSame(array_column($lista, 'id'), array_keys($e), $cat);
            foreach ($e as $id => $texto) {
                $this->assertNotSame('', trim($texto), "{$cat} {$id}");
                $this->assertLessThanOrEqual(300, mb_strlen($texto), "{$cat} {$id}");
            }
        }
        // AGID e MPN, as siglas do pedido, vêm do glossário.
        $this->assertSame(config('publicador_glossario.atributos.AGID'), $this->servico()->paraAtributos([['id' => 'AGID', 'nome' => 'AGID']])['AGID']);
    }
}
