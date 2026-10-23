<?php

namespace Tests\Feature\Publicador;

use App\Services\Publicador\EditorRascunhoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;
use Tests\TestCase;

/**
 * Demo 23/10/2026 (A9) — a Prévia dizia "Vendido por Dev 02 Testes API" (o nome cadastrado da empresa); no
 * Mercado Livre o comprador vê o apelido da conta, "MGSTOREL". O apelido vem do mesmo `/users/me` que já se lê
 * ao abrir o editor (`ContaMlService`) e vai para `step_state.conta` e para o `estado()['conta']`.
 */
class ApelidoDaContaNaPreviaTest extends TestCase
{
    use CenarioCadeira;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->fakeMl();
    }

    public function test_abrir_o_editor_guarda_o_apelido_da_conta_e_o_estado_o_entrega(): void
    {
        $this->r->update(['conta_checada_em' => null]);
        $editor = app(EditorRascunhoService::class);

        $r = $editor->abrir($this->produto);

        $this->assertSame('MGSTOREL', $r->step_state['conta']['nickname'] ?? null);
        $this->assertSame('MGSTOREL', $editor->estado($r)['conta']['nickname']);
    }

    public function test_conta_lida_antes_do_apelido_nao_quebra_e_entrega_nulo(): void
    {
        $this->usuario = [...self::fixture('conta/usuario'), 'nickname' => null];
        $this->r->update(['conta_checada_em' => null]);
        $editor = app(EditorRascunhoService::class);

        $r = $editor->abrir($this->produto);

        $this->assertNull($editor->estado($r)['conta']['nickname']);
    }
}
