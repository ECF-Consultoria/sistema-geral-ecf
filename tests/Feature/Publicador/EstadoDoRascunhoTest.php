<?php

namespace Tests\Feature\Publicador;

use App\Models\PubRascunho;
use App\Services\Publicador\EditorRascunhoService;
use App\Support\Publicador\EstadoDoRascunho;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;
use Tests\TestCase;

/**
 * 23/10/2026 (teste do Publicador, P-04): o `step_state` era regravado INTEIRO a partir da cópia lida no começo da
 * requisição — na releitura da conta ao abrir o editor (depois de uma chamada ao ML) e nas condicionais da
 * conferência. O que um Job gravou no meio (o `kit_id` das imagens automáticas, por exemplo) sumia, e o `updated_at`
 * mudava só porque alguém abriu o editor (o "Atualizado há…" e a ordem da vitrine).
 */
class EstadoDoRascunhoTest extends TestCase
{
    use CenarioCadeira;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->fakeMl();
    }

    public function test_reler_a_conta_nao_apaga_o_que_um_job_gravou_no_meio_nem_mexe_no_atualizado(): void
    {
        DB::table('pub_rascunhos')->where('id', $this->r->id)->update(['conta_checada_em' => null, 'updated_at' => '2026-10-20 10:00:00']);
        $daRequisicao = PubRascunho::findOrFail($this->r->id);
        // O Job das imagens automáticas grava no meio (o editor já tinha lido o rascunho).
        EstadoDoRascunho::gravar($this->r->id, 'criativos_auto', ['kit_id' => 46]);

        (new \ReflectionMethod(EditorRascunhoService::class, 'lerContaSeVencida'))->invoke(app(EditorRascunhoService::class), $daRequisicao);

        $banco = PubRascunho::findOrFail($this->r->id);
        $this->assertSame(46, $banco->step_state['criativos_auto']['kit_id'] ?? null, 'o kit_id que o Job gravou sumiu');
        $this->assertSame('1555596317', (string) ($banco->step_state['conta']['sellerId'] ?? ''), 'a conta relida foi gravada');
        $this->assertNotNull($banco->conta_checada_em);
        $this->assertSame('2026-10-20 10:00:00', $banco->updated_at->format('Y-m-d H:i:s'), 'reler a conta mexeu no "Atualizado há…"');
        $this->assertSame(46, $daRequisicao->step_state['criativos_auto']['kit_id'] ?? null, 'o modelo em mãos fica com o estado gravado');
        $this->assertFalse($daRequisicao->isDirty(), 'nada fica pendente para um save() posterior regravar');
    }

    public function test_a_porta_grava_so_a_chave_pedida_e_apaga_com_null(): void
    {
        EstadoDoRascunho::gravar($this->r->id, 'a', 1);
        EstadoDoRascunho::gravar($this->r->id, 'b', ['x' => 2]);
        EstadoDoRascunho::gravar($this->r->id, 'a', null);

        $estado = PubRascunho::findOrFail($this->r->id)->step_state;
        $this->assertArrayNotHasKey('a', $estado);
        $this->assertSame(['x' => 2], $estado['b']);
    }
}
