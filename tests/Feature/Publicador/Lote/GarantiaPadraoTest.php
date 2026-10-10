<?php

namespace Tests\Feature\Publicador\Lote;

use App\Models\Configuracao;
use App\Models\User;
use App\Support\Publicador\EditorEmUso;
use App\Support\Publicador\GarantiaPadrao;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Garantia padrão da conta (decisão do usuário, 10/10/2026): o Portal não pergunta garantia e o ML não publica sem
 * ela (V-SAL-05) — no teste de ponta a ponta da #459 todo produto que chegou do Portal travou na conferência. Salva
 * na tela de Publicação em lote, entra sozinha em todo rascunho SEM garantia e nunca troca a que alguém escolheu.
 */
class GarantiaPadraoTest extends TestCase
{
    use CenarioDaFila;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:00', 'America/Sao_Paulo'));
        Queue::fake();
        $this->withoutVite();
        $this->montarFila(3);
    }

    private function salvar(array $dados)
    {
        return $this->actingAs($this->admin)->putJson($this->rotaLote('garantia'), $dados);
    }

    public function test_salvar_aplica_nos_rascunhos_sem_garantia_e_nao_troca_a_escolhida(): void
    {
        [$a, $b, $c] = $this->cadeiras;
        $this->rascunhoDe($b)->update(['garantia' => null]);
        $this->rascunhoDe($c)->update(['garantia' => []]);
        $revisoes = [$this->rascunhoDe($a)->revisao, $this->rascunhoDe($b)->revisao, $this->rascunhoDe($c)->revisao];

        $r = $this->salvar(['tipo' => '2230280', 'tempo' => 90, 'unidade' => 'dias'])->assertOk()->json();

        $this->assertSame(2, $r['aplicados']);
        $this->assertSame('Garantia padrão salva: Garantia do vendedor, 90 dias. Aplicada em 2 produtos sem garantia.', $r['mensagem']);
        $this->assertSame('Garantia do vendedor, 90 dias', $r['garantia_padrao']['texto']);
        $this->assertSame(['tipo' => '2230280', 'tempo' => 30, 'unidade' => 'dias'], $this->rascunhoDe($a)->garantia, 'a escolhida pela equipe fica');
        $this->assertSame(['tipo' => '2230280', 'tempo' => 90, 'unidade' => 'dias'], $this->rascunhoDe($b)->garantia);
        $this->assertSame(['tipo' => '2230280', 'tempo' => 90, 'unidade' => 'dias'], $this->rascunhoDe($c)->garantia);
        $this->assertSame([$revisoes[0], $revisoes[1] + 1, $revisoes[2] + 1], [$this->rascunhoDe($a)->revisao, $this->rascunhoDe($b)->revisao, $this->rascunhoDe($c)->revisao],
            'quem recebeu sobe a revisão (a conferência de antes não vale mais)');
        $this->assertNotNull(Configuracao::get(GarantiaPadrao::PREFIXO.'company-'.$this->empresa->id));

        // A tela abre com o padrão e as opções.
        $this->actingAs($this->admin)->get($this->rotaLote('index'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('garantia_padrao.texto', 'Garantia do vendedor, 90 dias')
                ->where('garantia_padrao.atual.tempo', 90)
                ->where('garantia_padrao.sem_garantia', GarantiaPadrao::SEM_GARANTIA)
                ->has('garantia_padrao.tipos', 3));
    }

    public function test_editor_aberto_fica_para_o_proximo_sincronizar(): void
    {
        [, $b] = $this->cadeiras;
        $this->rascunhoDe($b)->update(['garantia' => null]);
        EditorEmUso::marcar($b->id);

        $r = $this->salvar(['tipo' => '2230279', 'tempo' => 1, 'unidade' => 'anos'])->assertOk()->json();

        $this->assertSame(0, $r['aplicados']);
        $this->assertSame('Garantia padrão salva: Garantia de fábrica, 1 anos. Nenhum produto estava sem garantia.', $r['mensagem']);
        $this->assertNull($this->rascunhoDe($b)->garantia, 'não grava por baixo de quem está editando');
    }

    public function test_sem_garantia_nao_pede_tempo_e_validacao_do_formulario(): void
    {
        [, $b] = $this->cadeiras;
        $this->rascunhoDe($b)->update(['garantia' => null]);

        $this->salvar(['tipo' => '999'])->assertUnprocessable()->assertJsonValidationErrors('tipo');
        $this->salvar(['tipo' => '2230280', 'unidade' => 'dias'])->assertUnprocessable()->assertJsonPath('errors.tempo.0', 'Informe o tempo da garantia.');
        $this->salvar(['tipo' => '2230280', 'tempo' => 90, 'unidade' => 'semanas'])->assertUnprocessable()->assertJsonValidationErrors('unidade');
        $this->salvar(['tipo' => '2230280', 'tempo' => 0, 'unidade' => 'dias'])->assertUnprocessable()->assertJsonValidationErrors('tempo');
        $this->assertNull(GarantiaPadrao::daConta(['mlb_empresa' => null, 'company' => $this->empresa]), 'nada foi salvo');

        $this->salvar(['tipo' => GarantiaPadrao::SEM_GARANTIA])->assertOk()->assertJsonPath('garantia_padrao.texto', 'Sem garantia');
        $this->assertSame(['tipo' => GarantiaPadrao::SEM_GARANTIA, 'tempo' => null, 'unidade' => null], $this->rascunhoDe($b)->garantia);

        // Tipo vazio remove o padrão (o que já foi aplicado fica).
        $this->salvar(['tipo' => null])->assertOk()->assertJsonPath('garantia_padrao.atual', null)
            ->assertJsonPath('mensagem', 'Garantia padrão removida. O que já foi aplicado continua nos produtos.');
        $this->assertNull(Configuracao::get(GarantiaPadrao::PREFIXO.'company-'.$this->empresa->id));
        $this->assertSame(GarantiaPadrao::SEM_GARANTIA, $this->rascunhoDe($b)->garantia['tipo']);
    }

    public function test_sem_admin_nao_entra(): void
    {
        $consultor = User::factory()->create(['role' => 'consultor']);
        $this->actingAs($consultor)->putJson($this->rotaLote('garantia'), ['tipo' => '2230280', 'tempo' => 90, 'unidade' => 'dias'])->assertForbidden();
        $this->assertNull(Configuracao::get(GarantiaPadrao::PREFIXO.'company-'.$this->empresa->id));
    }
}
