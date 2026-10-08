<?php

namespace Tests\Feature\Publicador;

use App\Jobs\Publicador\GerarExplicacoesDeAtributosJob;
use App\Models\AtributoExplicacao;
use App\Services\Publicador\EditorRascunhoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;
use Tests\TestCase;

/**
 * O estado do editor leva a explicação de TODO atributo do schema (pedido de 08/10/2026: "isso para
 * tudo, não apenas para siglas") e o texto dos campos fixos; a IA dos que faltam vai para a fila
 * `default`, uma vez só. Cadeira MLB193945, com as respostas reais da sondagem.
 */
class EstadoComExplicacoesTest extends TestCase
{
    use CenarioCadeira;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->montarCenario();
        $this->fakeMl();
    }

    private function estado(): array
    {
        return app(EditorRascunhoService::class)->estado($this->r->fresh());
    }

    public function test_todo_atributo_do_schema_vem_com_explicacao_e_os_campos_fixos_tambem(): void
    {
        Queue::fake();

        $schema = $this->estado()['schema'];

        $this->assertNotEmpty($schema['atributos']);
        foreach ($schema['atributos'] as $id => $a) {
            $this->assertIsString($a['explicacao'] ?? null, $id);
            $this->assertNotSame('', trim($a['explicacao']), $id);
        }
        // As siglas do pedido, do glossário.
        $this->assertSame(config('publicador_glossario.atributos.AGID'), $schema['atributos']['AGID']['explicacao']);
        $this->assertSame(config('publicador_glossario.atributos.MPN'), $schema['atributos']['MPN']['explicacao']);
        $this->assertSame(config('publicador_glossario.atributos.SELLER_SKU'), $schema['atributos']['SELLER_SKU']['explicacao']);
        // Com tooltip do ML e fora do glossário: o texto do ML, guardado com origem `ml`.
        $this->assertSame($schema['atributos']['BACKREST_HEIGHT']['tooltip'], mb_substr($schema['atributos']['BACKREST_HEIGHT']['explicacao'], 0, mb_strlen($schema['atributos']['BACKREST_HEIGHT']['tooltip'])));
        $this->assertSame('ml', AtributoExplicacao::where('atributo_id', 'BACKREST_HEIGHT')->value('origem'));
        // O campo fixo Estoque.
        $this->assertSame(config('publicador_glossario.campos.estoque'), $schema['explicacoes_campos']['estoque']);
    }

    public function test_a_ia_dos_que_faltam_vai_para_a_fila_default_uma_vez_e_sem_atributo_oculto(): void
    {
        Queue::fake();

        $schema = $this->estado()['schema'];
        $this->estado();

        Queue::assertPushed(GerarExplicacoesDeAtributosJob::class, 1);
        Queue::assertPushedOn('default', GerarExplicacoesDeAtributosJob::class);
        $pedidos = Queue::pushed(GerarExplicacoesDeAtributosJob::class)->first()->atributos;
        $ids = array_column($pedidos, 'id');
        $this->assertNotEmpty($ids);
        $this->assertLessThanOrEqual(40, count($ids));
        foreach ($ids as $id) {
            $this->assertNotSame('OCULTO', $schema['atributos'][$id]['secao'], "{$id} é oculto e não devia gastar IA");
            $this->assertArrayNotHasKey($id, config('publicador_glossario.atributos'), "{$id} já tem glossário");
            $this->assertEmpty($schema['atributos'][$id]['tooltip'], "{$id} já tem texto do ML");
        }
        // O contexto é o caminho da categoria (só para a IA entender o sentido do campo).
        $this->assertStringContainsString('>', (string) Queue::pushed(GerarExplicacoesDeAtributosJob::class)->first()->contexto);
    }
}
