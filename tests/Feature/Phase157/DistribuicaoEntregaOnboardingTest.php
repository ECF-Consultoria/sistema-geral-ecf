<?php

namespace Tests\Feature\Phase157;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\Servico;
use App\Models\User;
use App\Services\FluxoEntrada\DistribuicaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * D-157-B — distribuir ENTREGA os responsáveis ao onboarding.
 *
 * O defeito que originou estes testes foi medido em produção: a empresa 428 foi
 * distribuída para Gustavo enquanto o onboarding dela seguia dizendo Danilo, e
 * ela ficou parada em "Aguardando Onboarding" com um onboarding correndo havia
 * três semanas. A causa é que a transição 6→7 mora na virada
 * rascunho→andamento, e nesse onboarding a virada já tinha acontecido em agosto
 * — não havia como acontecer de novo.
 */
class DistribuicaoEntregaOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();
    }

    /**
     * Empresa em `aguardando_distribuicao` com um serviço ativo, mais o par de
     * responsáveis que o líder vai escolher.
     *
     * @return array{empresa: Company, servico: Servico, analista: User, estrategista: User, lider: User}
     */
    private function cenario(): array
    {
        $n = str_pad((string) (++self::$seq), 4, '0', STR_PAD_LEFT);

        $empresa = Company::factory()->create([
            'active' => true,
            'name'   => 'Empresa 157B '.$n,
            'cnpj'   => "17.157.157/{$n}-70",
            'etapa'  => Company::ETAPA_AGUARDANDO_DISTRIBUICAO,
        ]);

        $servico = Servico::create([
            'nome'                  => 'Serviço 157B '.$n,
            'valor_padrao'          => 100,
            'tipo_cobranca'         => Servico::TIPO_MENSAL,
            'ativo'                 => true,
            'setor'                 => Servico::SETOR_OUTROS,
            'exige_contrato'        => true,
        ]);

        // `withoutEvents`: o observer criaria um onboarding sozinho e cada teste
        // aqui quer controlar o status do onboarding que está sob prova.
        ContratoServico::withoutEvents(fn () => ContratoServico::create([
            'company_id'            => $empresa->id,
            'servico_id'            => $servico->id,
            'valor_contratado'      => 100,
            'data_contratacao'      => now()->toDateString(),
            'data_primeira_parcela' => now()->addMonth()->toDateString(),
            'dia_vencimento'        => 10,
            'ativo'                 => true,
        ]));

        return [
            'empresa'      => $empresa->fresh(),
            'servico'      => $servico,
            'analista'     => User::factory()->create(['role' => 'consultor', 'active' => true, 'name' => 'Analista 157B '.$n]),
            'estrategista' => User::factory()->create(['role' => 'consultor', 'active' => true, 'name' => 'Estrategista 157B '.$n]),
            'lider'        => User::factory()->create(['role' => 'admin', 'active' => true, 'name' => 'Líder 157B '.$n]),
        ];
    }

    private function distribuir(array $c): array
    {
        return app(DistribuicaoService::class)->distribuir(
            $c['empresa'],
            $c['analista']->id,
            $c['estrategista']->id,
            $c['lider']
        );
    }

    // ─── Caminho 1: onboarding em RASCUNHO ──────────────────────────────────

    /**
     * O caso corrente daqui para a frente: distribuir liga o onboarding e a
     * empresa chega em "Onboarding em andamento" sem ninguém reescrever os
     * mesmos dois nomes numa segunda tela.
     */
    public function test_onboarding_em_rascunho_e_ligado_e_a_empresa_vai_para_etapa_7(): void
    {
        $c = $this->cenario();

        $onboarding = Onboarding::create([
            'company_id' => $c['empresa']->id,
            'servico_id' => $c['servico']->id,
            'status'     => Onboarding::STATUS_RASCUNHO,
        ]);

        $this->assertSame('distribuido', $this->distribuir($c)['status']);

        // Reconsulta ao banco — nunca ao objeto em memória.
        $onboarding = Onboarding::findOrFail($onboarding->id);

        $this->assertSame(Onboarding::STATUS_ANDAMENTO, $onboarding->status);
        $this->assertNotNull($onboarding->iniciado_em);
        $this->assertSame($c['analista']->id, $onboarding->responsavel_analista_id);
        $this->assertSame($c['estrategista']->id, $onboarding->responsavel_estrategista_id);

        $this->assertSame(
            Company::ETAPA_ONBOARDING_ANDAMENTO,
            Company::findOrFail($c['empresa']->id)->etapa
        );
    }

    // ─── Caminho 2: onboarding JÁ em andamento (o bug de produção) ──────────

    /**
     * A empresa 428 em forma de teste. Sem a reconciliação ela para em 6 para
     * sempre, porque a virada que o engine observa já passou.
     */
    public function test_onboarding_ja_em_andamento_reconcilia_a_etapa_para_7(): void
    {
        $c = $this->cenario();

        $antigo = User::factory()->create(['role' => 'consultor', 'active' => true, 'name' => 'Analista Antigo']);

        $onboarding = Onboarding::create([
            'company_id'              => $c['empresa']->id,
            'servico_id'              => $c['servico']->id,
            'status'                  => Onboarding::STATUS_ANDAMENTO,
            'iniciado_em'             => now()->subWeeks(3),
            'responsavel_analista_id' => $antigo->id,
            'responsavel_id'          => $antigo->id,
        ]);

        $this->assertSame('distribuido', $this->distribuir($c)['status']);

        $this->assertSame(
            Company::ETAPA_ONBOARDING_ANDAMENTO,
            Company::findOrFail($c['empresa']->id)->etapa,
            'onboarding correndo + empresa em 6 é contradição; a distribuição tem de reconciliar.'
        );

        // A escolha do líder é a fresca e é a única — duas telas não podem
        // mostrar donos diferentes.
        $onboarding = Onboarding::findOrFail($onboarding->id);
        $this->assertSame($c['analista']->id, $onboarding->responsavel_analista_id);
        $this->assertSame($c['estrategista']->id, $onboarding->responsavel_estrategista_id);
        $this->assertNotSame($antigo->id, $onboarding->responsavel_analista_id);

        // `iniciado_em` é a data em que o trabalho começou de verdade; a
        // distribuição não reescreve história.
        $this->assertTrue($onboarding->iniciado_em->lessThan(now()->subWeek()));
    }

    // ─── Caminho 3: sem onboarding — a etapa 6 é o repouso correto ─────────

    public function test_sem_onboarding_a_empresa_para_em_aguardando_onboarding(): void
    {
        $c = $this->cenario();

        $this->assertSame(0, Onboarding::where('company_id', $c['empresa']->id)->count());
        $this->assertSame('distribuido', $this->distribuir($c)['status']);

        $this->assertSame(
            Company::ETAPA_AGUARDANDO_ONBOARDING,
            Company::findOrFail($c['empresa']->id)->etapa,
            'sem onboarding não há o que estar "em andamento" — 6 é o repouso, não um bug.'
        );
    }

    /**
     * Onboarding concluído não é reaberto nem reatribuído, e não puxa a empresa
     * para 7 — ela fica em 6 esperando o próximo, que é o certo.
     */
    public function test_onboarding_concluido_e_intocado(): void
    {
        $c = $this->cenario();

        $onboarding = Onboarding::create([
            'company_id'  => $c['empresa']->id,
            'servico_id'  => $c['servico']->id,
            'status'      => Onboarding::STATUS_CONCLUIDO,
            'iniciado_em' => now()->subMonth(),
        ]);

        $this->assertSame('distribuido', $this->distribuir($c)['status']);

        $onboarding = Onboarding::findOrFail($onboarding->id);
        $this->assertSame(Onboarding::STATUS_CONCLUIDO, $onboarding->status);
        $this->assertNull($onboarding->responsavel_analista_id);

        $this->assertSame(
            Company::ETAPA_AGUARDANDO_ONBOARDING,
            Company::findOrFail($c['empresa']->id)->etapa
        );
    }

    /**
     * A distribuição não pode cair por causa do onboarding: a pivot e a etapa
     * 5→6 já estão gravadas quando a entrega acontece.
     */
    public function test_pivot_e_etapa_sobrevivem_mesmo_se_a_entrega_falhar(): void
    {
        $c = $this->cenario();

        // Onboarding órfão de serviço: o engine trabalha, mas qualquer tropeço
        // aqui tem de ficar no log, nunca derrubar o ato do líder.
        Onboarding::create([
            'company_id' => $c['empresa']->id,
            'servico_id' => $c['servico']->id,
            'status'     => Onboarding::STATUS_RASCUNHO,
        ]);

        $this->assertSame('distribuido', $this->distribuir($c)['status']);

        $this->assertDatabaseHas('company_users', [
            'company_id' => $c['empresa']->id,
            'role'       => 'analista',
            'user_id'    => $c['analista']->id,
        ]);
        $this->assertDatabaseHas('company_users', [
            'company_id' => $c['empresa']->id,
            'role'       => 'estrategista',
            'user_id'    => $c['estrategista']->id,
        ]);
    }
}
