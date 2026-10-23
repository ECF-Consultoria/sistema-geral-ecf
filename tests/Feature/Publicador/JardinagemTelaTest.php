<?php

namespace Tests\Feature\Publicador;

use App\Jobs\Publicador\GerarPropostaDaJardinagemJob;
use App\Models\Company;
use App\Models\MlToken;
use App\Models\PubJardinagem;
use App\Models\PubPublicacaoItem;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Publicador\Concerns\CenarioTarefas;
use Tests\TestCase;

/**
 * A tela da Jardinagem (23/10/2026): quem entra (chave própria `mlb.jardinagem`), o que ela mostra (os ramos
 * com as contagens, o mês de cada anúncio e os ainda em observação) e as ações da fila — nenhuma fala com
 * o Mercado Livre.
 */
class JardinagemTelaTest extends TestCase
{
    use CenarioTarefas;
    use RefreshDatabase;

    private Company $conta;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->conta = Company::factory()->create(['name' => 'Loja Piloto']);
        MlToken::create(['company_id' => $this->conta->id, 'ml_user_id' => '1555596317', 'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token', 'token_type' => 'bearer', 'expires_at' => now()->addHours(5),
            'last_refreshed_at' => now(), 'status' => 'active', 'connected_at' => now()]);
        config(['publicador.contas_liberadas' => ['companies' => [$this->conta->id], 'mlb_empresas' => []]]);
        $this->conta = $this->conta->fresh();
    }

    private function mes(string $diagnostico, string $mlb = 'MLB1001', array $extra = []): PubJardinagem
    {
        $p = $this->publicacaoConcluida($this->conta, [$mlb => 'gold_special']);
        $item = PubPublicacaoItem::where('ml_item_id', $mlb)->sole();
        $r = \App\Models\PubRascunho::find($p->rascunho_id);

        return PubJardinagem::create([
            'ml_item_id' => $mlb, 'ciclo' => 1, 'status' => PubJardinagem::PENDENTE, 'diagnostico' => $diagnostico,
            'publicacao_item_id' => $item->id, 'publicacao_id' => $p->id, 'produto_id' => $r->produto_id, 'rascunho_id' => $r->id,
            'company_id' => $this->conta->id, 'conta_chave' => 'company-'.$this->conta->id, 'listing_type_id' => 'gold_special',
            'titulo' => 'Puff Redondo Sala', 'janela_inicio' => now()->subDays(30)->format('Y-m-d'), 'janela_fim' => now()->format('Y-m-d'),
            'visitas' => 40, 'vendas' => 0, 'vendidos_total' => 0, 'conversao' => 0.0,
            'checklist' => PubJardinagem::checklistInicial($diagnostico),
            'proposta' => $diagnostico === PubJardinagem::SEM_TRACAO
                ? ['estado' => 'pronta', 'titulo_atual' => 'Puff Redondo Sala', 'titulo_novo' => 'Puff Banqueta Redondo', 'termos_novos' => ['puff banqueta']]
                : null,
            ...$extra,
        ]);
    }

    public function test_sem_a_chave_nao_entra_nem_com_a_das_alavancas(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'consultor']))
            ->get(route('mlb.anuncios.publicador.jardinagem.index'))->assertForbidden();
        $this->actingAs($this->comPermissao(Permissions::MLB_ALAVANCAS))
            ->get(route('mlb.anuncios.publicador.jardinagem.index'))->assertForbidden();
    }

    public function test_tela_mostra_os_ramos_o_mes_de_cada_anuncio_e_os_em_observacao(): void
    {
        $this->mes(PubJardinagem::SEM_TRACAO);
        $this->mes(PubJardinagem::CONVERSAO_BOA, 'MLB2002', ['visitas' => 300, 'vendas' => 9, 'conversao' => 3.0]);
        // Publicado há 10 dias: ainda dentro do mês.
        $this->publicacaoConcluida($this->conta, ['MLB3003' => 'gold_pro']);
        PubPublicacaoItem::where('ml_item_id', 'MLB3003')->update(['criado_em' => now()->subDays(10)]);

        $this->actingAs($this->comPermissao(Permissions::MLB_JARDINAGEM, 'Jardineira'))
            ->get(route('mlb.anuncios.publicador.jardinagem.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Mlb/Jardinagem')
                ->has('linhas', 2)
                ->where('linhas.0.diagnostico', PubJardinagem::SEM_TRACAO)
                ->where('linhas.0.empresa.nome', 'Loja Piloto')
                ->where('linhas.0.proposta.titulo_novo', 'Puff Banqueta Redondo')
                ->where('linhas.0.checklist.0.chave', 'nova_oferta')
                ->where('linhas.1.diagnostico', PubJardinagem::CONVERSAO_BOA)
                ->where('linhas.1.conversao', 3)
                ->where('contagens.abertas', 2)
                ->where('contagens.por_diagnostico.sem_tracao', 1)
                ->where('contagens.por_diagnostico.visita_sem_venda', 0)
                ->where('contagens.em_observacao', 1)
                ->where('em_observacao.0.ml_item_id', 'MLB3003')
                ->where('em_observacao.0.tipo', 'Premium')
                ->where('regras.dias_do_ciclo', 30)
                ->where('regras.diagnosticos.0.regra', 'Menos de 100 visitas e nenhuma venda')
                ->where('ativa', false)
                ->where('pode_abrir_publicador', false));
    }

    public function test_filtro_por_ramo(): void
    {
        $this->mes(PubJardinagem::SEM_TRACAO);
        $this->mes(PubJardinagem::VISITA_SEM_VENDA, 'MLB2002');

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('mlb.anuncios.publicador.jardinagem.index', ['diagnostico' => PubJardinagem::VISITA_SEM_VENDA]))
            ->assertInertia(fn (Assert $page) => $page->has('linhas', 1)->where('linhas.0.ml_item_id', 'MLB2002')
                ->where('filtros.diagnostico', PubJardinagem::VISITA_SEM_VENDA)->where('pode_abrir_publicador', true));
    }

    public function test_marcar_e_concluir(): void
    {
        $j = $this->mes(PubJardinagem::VISITA_SEM_VENDA);
        $u = $this->comPermissao(Permissions::MLB_JARDINAGEM, 'Jardineira');
        $rota = fn (string $chave) => route('mlb.anuncios.publicador.jardinagem.marcar', ['jardinagem' => $j->id, 'chave' => $chave]);

        $this->actingAs($u)->put($rota('criativo'), ['estado' => 'feito'])->assertSessionHasNoErrors();
        $this->assertSame(PubJardinagem::EM_ANDAMENTO, $j->fresh()->status);
        $this->assertSame($u->id, $j->fresh()->responsavel_id);

        // Concluir com a elasticidade pendente: barrado.
        $this->actingAs($u)->post(route('mlb.anuncios.publicador.jardinagem.concluir', ['jardinagem' => $j->id]))
            ->assertSessionHasErrors('jardinagem');
        // "Não se aplica" sem motivo: barrado.
        $this->actingAs($u)->put($rota('elasticidade'), ['estado' => 'nao_se_aplica'])->assertSessionHasErrors('motivo');

        $this->actingAs($u)->put($rota('elasticidade'), ['estado' => 'nao_se_aplica', 'motivo' => 'preço já é o mínimo da precificação'])
            ->assertSessionHasNoErrors();
        $this->actingAs($u)->post(route('mlb.anuncios.publicador.jardinagem.concluir', ['jardinagem' => $j->id]))
            ->assertSessionHasNoErrors();

        $j->refresh();
        $this->assertSame(PubJardinagem::FEITA, $j->status);
        $this->assertNotNull($j->concluida_em);
        $this->assertSame('preço já é o mínimo da precificação', $j->checklist['elasticidade']['motivo']);
    }

    public function test_item_de_outro_ramo_e_mes_substituido_sao_recusados(): void
    {
        $j = $this->mes(PubJardinagem::SEM_TRACAO);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->put(route('mlb.anuncios.publicador.jardinagem.marcar', ['jardinagem' => $j->id, 'chave' => 'criativo']), ['estado' => 'feito'])
            ->assertSessionHasErrors('jardinagem');
        $this->actingAs($admin)->put(route('mlb.anuncios.publicador.jardinagem.marcar', ['jardinagem' => $j->id, 'chave' => 'inventado']), ['estado' => 'feito'])
            ->assertNotFound();

        $j->update(['status' => PubJardinagem::SUBSTITUIDA]);
        $this->actingAs($admin)->put(route('mlb.anuncios.publicador.jardinagem.marcar', ['jardinagem' => $j->id, 'chave' => 'nova_oferta']), ['estado' => 'feito'])
            ->assertSessionHasErrors(['jardinagem' => 'Este mês já foi substituído pela análise seguinte.']);
    }

    public function test_pedir_outro_titulo_so_no_sem_tracao(): void
    {
        $semTracao = $this->mes(PubJardinagem::SEM_TRACAO);
        $boa = $this->mes(PubJardinagem::CONVERSAO_BOA, 'MLB2002');
        $u = $this->comPermissao(Permissions::MLB_JARDINAGEM, 'Jardineira');

        $this->actingAs($u)->post(route('mlb.anuncios.publicador.jardinagem.proposta', ['jardinagem' => $semTracao->id]))->assertSessionHasNoErrors();
        $this->assertSame('gerando', $semTracao->fresh()->proposta['estado']);
        $this->assertSame('Puff Banqueta Redondo', $semTracao->fresh()->proposta['titulo_novo'], 'a proposta anterior fica à vista até a nova chegar');
        Queue::assertPushed(GerarPropostaDaJardinagemJob::class, fn ($job) => $job->jardinagemId === $semTracao->id);

        // Gerando: não empilha outro pedido.
        $this->actingAs($u)->post(route('mlb.anuncios.publicador.jardinagem.proposta', ['jardinagem' => $semTracao->id]))->assertSessionHasErrors('jardinagem');
        $this->actingAs($u)->post(route('mlb.anuncios.publicador.jardinagem.proposta', ['jardinagem' => $boa->id]))->assertSessionHasErrors('jardinagem');
        Queue::assertPushed(GerarPropostaDaJardinagemJob::class, 1);
    }

    public function test_observacao(): void
    {
        $j = $this->mes(PubJardinagem::CONVERSAO_BAIXA);

        $this->actingAs($this->comPermissao(Permissions::MLB_JARDINAGEM, 'Jardineira'))
            ->put(route('mlb.anuncios.publicador.jardinagem.observacao', ['jardinagem' => $j->id]), ['observacao' => 'trocamos a 2ª foto'])
            ->assertSessionHasNoErrors();

        $this->assertSame('trocamos a 2ª foto', $j->fresh()->observacao);
    }
}
