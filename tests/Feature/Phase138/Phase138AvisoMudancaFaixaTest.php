<?php

namespace Tests\Feature\Phase138;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\FechamentoGrupoSnapshot;
use App\Models\FechamentoSnapshot;
use App\Models\User;
use App\Services\Fechamento\FechamentoFaixaNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 138 Plano 05 — Tarefa 1: `FechamentoFaixaNotifier` (D-02 + D-03).
 *
 * Toda asserção de resultado é por RECONSULTA ao banco (contagem de linhas
 * em `notifications` e reconsulta das tabelas de snapshot) — nunca pela
 * saída do serviço em memória (.planning/learnings/desempenho-bonificacao.md
 * §4). As fixtures montam as linhas de snapshot DIRETO (mesmo padrão de
 * `Phase138AvisoFaixaSchemaTest`) em vez de rodar o comando inteiro — este
 * teste cobre só o notificador, não o cálculo de faixa/evolução.
 */
class Phase138AvisoMudancaFaixaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function criarEmpresaSnapshot(Carbon $mes, array $overrides = []): FechamentoSnapshot
    {
        $company = Company::factory()->create();

        return FechamentoSnapshot::create(array_merge([
            'company_id'     => $company->id,
            'company_name'   => $company->name,
            'mes_referencia' => $mes->copy()->startOfMonth()->toDateString(),
            'faixa_ordem'    => 2,
            'faixa_aplicada' => 'faixa_2',
            'evolucao'       => 'subiu',
            'estado'         => FechamentoSnapshot::ESTADO_OK,
            'origem'         => FechamentoSnapshot::ORIGEM_CONSOLIDAR_MES,
            'gerado_em'      => now(),
        ], $overrides));
    }

    private function criarGrupoSnapshot(Carbon $mes, array $overrides = []): FechamentoGrupoSnapshot
    {
        $grupo = CompanyGroup::create(['name' => 'Grupo Teste '.uniqid()]);

        return FechamentoGrupoSnapshot::create(array_merge([
            'company_group_id' => $grupo->id,
            'grupo_name'       => $grupo->name,
            'mes_referencia'   => $mes->copy()->startOfMonth()->toDateString(),
            'faixa_ordem'      => 3,
            'faixa_aplicada'   => 'faixa_3',
            'evolucao'         => 'desceu',
            'estado'           => FechamentoSnapshot::ESTADO_OK,
            'origem'           => FechamentoGrupoSnapshot::ORIGEM_CONSOLIDAR_MES,
            'empresas_count'   => 2,
            'gerado_em'        => now(),
        ], $overrides));
    }

    #[Test]
    public function tres_sobem_e_duas_descem_geram_uma_notificacao_por_admin_citando_as_cinco(): void
    {
        $mes = Carbon::create(2026, 8, 1);

        for ($i = 0; $i < 3; $i++) {
            $this->criarEmpresaSnapshot($mes, ['evolucao' => 'subiu']);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->criarEmpresaSnapshot($mes, ['evolucao' => 'desceu', 'faixa_ordem' => 1]);
        }

        $admin1 = $this->criarAdmin();
        $admin2 = $this->criarAdmin();

        $resumo = app(FechamentoFaixaNotifier::class)->notificar($mes);

        $this->assertSame(5, $resumo['empresas']);
        $this->assertSame(0, $resumo['grupos']);
        $this->assertSame(2, $resumo['notificacoes']);

        // Uma notificação por admin — nunca uma por empresa.
        $this->assertSame(1, DatabaseNotification::where('notifiable_id', $admin1->id)->count());
        $this->assertSame(1, DatabaseNotification::where('notifiable_id', $admin2->id)->count());

        $notificacao = DatabaseNotification::where('notifiable_id', $admin1->id)->firstOrFail();
        $this->assertSame('faixa_alterada', $notificacao->data['categoria']);
        $this->assertStringContainsString('5', $notificacao->data['mensagem']);

        // Copy sem jargão técnico.
        foreach (['snapshot', 'competência', 'reconsolidação', 'rollup', 'ordem'] as $jargao) {
            $this->assertStringNotContainsStringIgnoringCase($jargao, $notificacao->data['mensagem']);
        }
    }

    #[Test]
    public function empresa_que_manteve_a_faixa_nao_entra_no_aviso(): void
    {
        $mes = Carbon::create(2026, 8, 1);

        $this->criarEmpresaSnapshot($mes, ['evolucao' => 'manteve']);
        $this->criarAdmin();

        $resumo = app(FechamentoFaixaNotifier::class)->notificar($mes);

        $this->assertSame(0, $resumo['empresas']);
        $this->assertSame(0, $resumo['notificacoes']);
        $this->assertSame(0, DatabaseNotification::count());
    }

    #[Test]
    public function empresa_a_definir_nao_entra_no_aviso(): void
    {
        $mes = Carbon::create(2026, 8, 1);

        // faixa_ordem nulo (A DEFINIR).
        $this->criarEmpresaSnapshot($mes, ['evolucao' => 'subiu', 'faixa_ordem' => null]);
        // estado diferente de 'ok'.
        $this->criarEmpresaSnapshot($mes, ['evolucao' => 'subiu', 'estado' => FechamentoSnapshot::ESTADO_SEM_TABELA]);
        $this->criarAdmin();

        $resumo = app(FechamentoFaixaNotifier::class)->notificar($mes);

        $this->assertSame(0, $resumo['empresas']);
        $this->assertSame(0, DatabaseNotification::count());
    }

    #[Test]
    public function chamar_duas_vezes_seguidas_sem_mudanca_nao_gera_segunda_notificacao(): void
    {
        $mes = Carbon::create(2026, 8, 1);

        $this->criarEmpresaSnapshot($mes, ['evolucao' => 'subiu']);
        $this->criarAdmin();

        $notifier = app(FechamentoFaixaNotifier::class);

        $primeiro  = $notifier->notificar($mes);
        $segundo   = $notifier->notificar($mes);

        $this->assertSame(1, $primeiro['empresas']);
        $this->assertSame(0, $segundo['empresas']);
        $this->assertSame(0, $segundo['notificacoes']);

        // Reconsulta ao banco — só uma notificação no total, nunca duas.
        $this->assertSame(1, DatabaseNotification::count());
    }

    #[Test]
    public function refazer_e_mudar_a_faixa_de_uma_empresa_gera_aviso_novo_so_sobre_ela(): void
    {
        $mes = Carbon::create(2026, 8, 1);

        $empresaEstavel = $this->criarEmpresaSnapshot($mes, ['evolucao' => 'subiu', 'faixa_ordem' => 2]);
        $empresaMudou   = $this->criarEmpresaSnapshot($mes, ['evolucao' => 'subiu', 'faixa_ordem' => 2]);
        $this->criarAdmin();

        $notifier = app(FechamentoFaixaNotifier::class);
        $notifier->notificar($mes);

        $this->assertSame(1, DatabaseNotification::count());

        // `DatabaseNotification::id` é UUID (não auto-increment) — não dá
        // pra usar `latest('id')` pra achar "a notificação nova". Guarda os
        // ids já existentes antes da 2ª rodada pra isolar só o que ela criou.
        $idsAntes = DatabaseNotification::pluck('id')->all();

        // Simula um "Refazer" que corrigiu um erro real e moveu a empresa
        // de faixa — o snapshot é regravado com faixa_ordem diferente da
        // que já foi carimbada.
        $empresaMudou->forceFill(['faixa_ordem' => 3, 'faixa_aplicada' => 'faixa_3'])->save();

        $resumo = $notifier->notificar($mes);

        $this->assertSame(1, $resumo['empresas'], 'Só a empresa que mudou de faixa de novo deveria entrar nesta rodada.');
        $this->assertSame(2, DatabaseNotification::count(), 'A segunda rodada deveria ter gerado exatamente 1 notificação nova.');

        $novas = DatabaseNotification::whereNotIn('id', $idsAntes)->get();
        $this->assertCount(1, $novas, 'A 2ª rodada deveria ter criado exatamente 1 notificação nova.');

        $ultima = $novas->first();
        $this->assertStringContainsString((string) $empresaMudou->fresh()->company_name, $ultima->data['mensagem']);
        $this->assertStringNotContainsString((string) $empresaEstavel->fresh()->company_name, $ultima->data['mensagem']);
    }

    #[Test]
    public function grupos_entram_no_mesmo_aviso_identificados_como_grupo(): void
    {
        $mes = Carbon::create(2026, 8, 1);

        $this->criarEmpresaSnapshot($mes, ['evolucao' => 'subiu']);
        $grupo = $this->criarGrupoSnapshot($mes, ['evolucao' => 'desceu']);
        $this->criarAdmin();

        $resumo = app(FechamentoFaixaNotifier::class)->notificar($mes);

        $this->assertSame(1, $resumo['empresas']);
        $this->assertSame(1, $resumo['grupos']);

        $notificacao = DatabaseNotification::latest('id')->firstOrFail();
        $this->assertStringContainsString('Grupo '.$grupo->grupo_name, $notificacao->data['mensagem']);

        $this->assertNotNull($grupo->fresh()->notificado_em);
        $this->assertSame(3, $grupo->fresh()->notificado_faixa_ordem);
    }

    #[Test]
    public function sem_nenhum_admin_cadastrado_nada_e_enviado_e_nada_e_carimbado(): void
    {
        $mes = Carbon::create(2026, 8, 1);

        $empresa = $this->criarEmpresaSnapshot($mes, ['evolucao' => 'subiu']);

        $resumo = app(FechamentoFaixaNotifier::class)->notificar($mes);

        $this->assertSame(0, $resumo['empresas']);
        $this->assertSame(0, DatabaseNotification::count());
        $this->assertNull($empresa->fresh()->notificado_em, 'Sem admin cadastrado, a mudança não pode ser carimbada como avisada.');
    }

    #[Test]
    public function sem_nenhuma_mudanca_elegivel_nenhuma_notificacao_e_criada(): void
    {
        $mes = Carbon::create(2026, 8, 1);

        $this->criarAdmin();

        $resumo = app(FechamentoFaixaNotifier::class)->notificar($mes);

        $this->assertSame(0, $resumo['empresas']);
        $this->assertSame(0, $resumo['grupos']);
        $this->assertSame(0, DatabaseNotification::count());
    }

    /**
     * Teste obrigatório da correção do plan-checker (2026-09-03): uma
     * segunda execução que comece depois da seleção da primeira, mas antes
     * do carimbo dela, não pode produzir aviso duplicado.
     *
     * Simulação: como o lock nomeado (`Cache::lock`) guarda a rodada
     * INTEIRA (seleção + envio + carimbo), a única forma de uma segunda
     * execução "começar no meio" da primeira é encontrar o lock já
     * ocupado. Este teste pré-adquire o lock manualmente — representando a
     * primeira execução que já passou da seleção e ainda não carimbou — e
     * confirma que uma segunda chamada ao notificador, encontrando o lock
     * ocupado, não processa nada: nenhuma notificação nova, nenhum carimbo
     * novo. Depois libera o lock e confirma que uma chamada seguinte
     * processa normalmente.
     */
    #[Test]
    public function segunda_execucao_que_comeca_com_o_lock_ocupado_nao_duplica_o_aviso(): void
    {
        $mes = Carbon::create(2026, 8, 1);
        $mesStr = $mes->copy()->startOfMonth()->toDateString();

        $empresa = $this->criarEmpresaSnapshot($mes, ['evolucao' => 'subiu']);
        $this->criarAdmin();

        // Simula a "primeira execução" segurando o lock nomeado da
        // competência — é exatamente o estado em que ela já passou da
        // seleção (achou a empresa elegível) mas ainda não commitou o
        // carimbo.
        $lockDaPrimeiraExecucao = Cache::lock('fechamento:notificar:'.$mesStr, 60);
        $this->assertTrue($lockDaPrimeiraExecucao->get(), 'Pré-condição do teste: o lock precisa estar livre para ser adquirido aqui.');

        // "Segunda execução" — encontra o lock ocupado e não pode processar.
        $resumoConcorrente = app(FechamentoFaixaNotifier::class)->notificar($mes);

        $this->assertSame(0, $resumoConcorrente['empresas'], 'Com o lock ocupado, a segunda execução não pode selecionar/enviar nada.');
        $this->assertSame(0, $resumoConcorrente['notificacoes']);
        $this->assertSame(0, DatabaseNotification::count(), 'Nenhuma notificação deveria existir enquanto o lock está ocupado por outra execução.');
        $this->assertNull($empresa->fresh()->notificado_em, 'Nenhum carimbo deveria acontecer enquanto o lock está ocupado por outra execução.');

        // A "primeira execução" termina e libera o lock.
        $lockDaPrimeiraExecucao->release();

        // Agora sim uma execução processa normalmente — exatamente 1 aviso.
        $resumoDepoisDoRelease = app(FechamentoFaixaNotifier::class)->notificar($mes);

        $this->assertSame(1, $resumoDepoisDoRelease['empresas']);
        $this->assertSame(1, DatabaseNotification::count(), 'Reconsulta ao banco: exatamente uma notificação gravada, nunca duas.');
        $this->assertNotNull($empresa->fresh()->notificado_em);
    }
}
