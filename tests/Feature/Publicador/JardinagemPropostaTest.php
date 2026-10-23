<?php

namespace Tests\Feature\Publicador;

use App\Jobs\Publicador\GerarPropostaDaJardinagemJob;
use App\Models\Company;
use App\Models\PubJardinagem;
use App\Models\PubPublicacao;
use App\Services\Publicador\PalavrasChaveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Feature\Publicador\Concerns\CenarioTarefas;
use Tests\TestCase;

/**
 * A proposta de título da nova oferta (anúncio sem tração, 23/10/2026): quais termos da semana viram
 * prioridade, quais títulos entram no "não repita", e o que a tela recebe quando a IA falha. O gerador de
 * título é um dublê aqui — o prompt de verdade é provado pelo `TituloDaJardinagemTest`.
 */
class JardinagemPropostaTest extends TestCase
{
    use CenarioTarefas;
    use RefreshDatabase;

    private PubPublicacao $p;

    private PubJardinagem $j;

    protected function setUp(): void
    {
        parent::setUp();
        $conta = Company::factory()->create();
        $this->p = $this->publicacaoConcluida($conta, ['MLB1001' => 'gold_special']);
        $rascunho = \App\Models\PubRascunho::find($this->p->rascunho_id);
        $this->j = PubJardinagem::create([
            'ml_item_id' => 'MLB1001', 'ciclo' => 1, 'status' => PubJardinagem::PENDENTE, 'diagnostico' => PubJardinagem::SEM_TRACAO,
            'publicacao_id' => $this->p->id, 'produto_id' => $rascunho->produto_id, 'rascunho_id' => $rascunho->id,
            'company_id' => $conta->id, 'conta_chave' => 'company-'.$conta->id, 'listing_type_id' => 'gold_special',
            'titulo' => 'Puff Redondo Sala Moderno', 'janela_inicio' => now()->subDays(30)->format('Y-m-d'), 'janela_fim' => now()->format('Y-m-d'),
            'visitas' => 40, 'vendas' => 0, 'vendidos_total' => 0, 'conversao' => 0, 'checklist' => PubJardinagem::checklistInicial(PubJardinagem::SEM_TRACAO),
            'proposta' => ['estado' => 'gerando', 'titulo_atual' => 'Puff Redondo Sala Moderno'],
        ]);
    }

    /** O gerador de título dublê: termos da semana fixos e a resposta dada. */
    private function palavras(array|\Throwable $titulos, ?\Closure $confere = null): void
    {
        $m = Mockery::mock(PalavrasChaveService::class);
        $m->shouldReceive('termos')->andReturn(['termos' => [
            ['termo' => 'puff redondo sala', 'posicao' => 1, 'relacionado' => true, 'origem' => 'categoria'],
            ['termo' => 'puff banqueta', 'posicao' => 2, 'relacionado' => true, 'origem' => 'categoria'],
            ['termo' => 'cadeira gamer', 'posicao' => 3, 'relacionado' => false, 'origem' => 'categoria'],
            ['termo' => 'puff pera', 'posicao' => 1, 'relacionado' => true, 'origem' => 'acima'],
            ['termo' => 'puff quarto', 'posicao' => 9, 'relacionado' => true, 'origem' => 'categoria'],
        ]]);
        $chamada = $m->shouldReceive('gerarTitulosDaJardinagem')->once();
        if ($confere !== null) {
            $chamada->withArgs($confere);
        }
        $titulos instanceof \Throwable ? $chamada->andThrow($titulos) : $chamada->andReturn($titulos);
        $this->app->instance(PalavrasChaveService::class, $m);
    }

    public function test_termos_que_trazem_palavra_nova_viram_prioridade_e_todo_titulo_usado_entra_no_nao_repita(): void
    {
        $this->palavras(['gold_special' => 'Puff Banqueta Redondo Quarto', 'gold_pro' => 'Puff Redondo Banqueta Pera'],
            function ($r, array $evitar, array $priorizar) {
                // "puff redondo sala" não traz palavra nova; "cadeira gamer" não é do produto; o nível de cima vem depois.
                $this->assertSame(['puff banqueta', 'puff quarto', 'puff pera'], $priorizar);
                $this->assertContains('Puff Redondo Sala Moderno', $evitar, 'o título de agora');
                $this->assertContains('Puff Redondo', $evitar, 'o family_name publicado');

                return true;
            });

        (new GerarPropostaDaJardinagemJob($this->j->id))->handle(app(\App\Services\Publicador\Jardinagem\PropostaDaJardinagemService::class));

        $proposta = $this->j->fresh()->proposta;
        $this->assertSame('pronta', $proposta['estado']);
        $this->assertSame('Puff Banqueta Redondo Quarto', $proposta['titulo_novo'], 'o Clássico leva o título do Clássico');
        $this->assertSame('Puff Redondo Sala Moderno', $proposta['titulo_atual']);
        $this->assertSame(['puff banqueta', 'puff quarto', 'puff pera'], $proposta['termos_novos']);
        $this->assertNull($proposta['erro']);
    }

    public function test_ia_que_falha_sai_do_gerando_com_mensagem(): void
    {
        $this->palavras(new \RuntimeException('provedor fora'));

        (new GerarPropostaDaJardinagemJob($this->j->id))->handle(app(\App\Services\Publicador\Jardinagem\PropostaDaJardinagemService::class));

        $proposta = $this->j->fresh()->proposta;
        $this->assertSame('erro', $proposta['estado']);
        $this->assertSame('A IA não gerou o título agora. Tente de novo em alguns minutos.', $proposta['erro']);
        $this->assertSame(['puff banqueta', 'puff quarto', 'puff pera'], $proposta['termos_novos']);
    }

    public function test_so_titulos_repetidos_vira_erro_em_vez_de_proposta_vazia(): void
    {
        $this->palavras(['gold_special' => '', 'gold_pro' => '']);

        (new GerarPropostaDaJardinagemJob($this->j->id))->handle(app(\App\Services\Publicador\Jardinagem\PropostaDaJardinagemService::class));

        $this->assertSame('erro', $this->j->fresh()->proposta['estado']);
        $this->assertStringContainsString('só achou títulos iguais', $this->j->fresh()->proposta['erro']);
    }

    public function test_linha_fechada_ou_de_outro_ramo_nao_chama_a_ia(): void
    {
        $m = Mockery::mock(PalavrasChaveService::class);
        $m->shouldNotReceive('gerarTitulosDaJardinagem');
        $this->app->instance(PalavrasChaveService::class, $m);

        $this->j->update(['status' => PubJardinagem::SUBSTITUIDA]);
        (new GerarPropostaDaJardinagemJob($this->j->id))->handle(app(\App\Services\Publicador\Jardinagem\PropostaDaJardinagemService::class));

        $this->assertSame('gerando', $this->j->fresh()->proposta['estado']);
    }

    public function test_job_vai_para_a_fila_da_ia_do_publicador(): void
    {
        config(['publicador.preparo_ia.fila' => 'publicador-ia']);

        $this->assertSame('publicador-ia', (new GerarPropostaDaJardinagemJob(1))->queue);
    }
}
