<?php

namespace Tests\Feature\Phase143;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\ContratoServico;
use App\Models\FechamentoGrupoSnapshot;
use App\Models\FechamentoSnapshot;
use App\Models\GrupoFaixaFaturamento;
use App\Models\Servico;
use App\Models\User;
use App\Services\Fechamento\FechamentoFaixaNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 143 Plano 05 — Tarefa 1 (item 7 do `deferred-items.md`): o aviso de
 * mudança de faixa para de reportar como crescimento a linha que mudou
 * porque mudou QUEM faz parte do cliente.
 *
 * ⚠️ Por que isto importa no momento exato em que morde: o usuário vai
 * montar o grupo MPozenato (MPozenato + DRossi + Gran Belo + Lyam, 10
 * empresas) e abrir o fechamento para conferir se a cobrança caiu de
 * R$ 33.500 para R$ 21.000. É nessa hora que o Passo 8 dispararia para todos
 * os admins um aviso dizendo que o cliente "subiu de faixa" — quando o que
 * aconteceu foi a junção que ele mesmo acabou de fazer.
 *
 * A garantia que permite deployar sem medo é a primeira deste arquivo:
 * **composição igual → comportamento idêntico ao de hoje.** Enquanto ninguém
 * juntar grupo nenhum (os 15 grupos de produção seguem com `parent_id`
 * nulo), nenhum aviso muda.
 *
 * Toda asserção de resultado é por RECONSULTA (tabela `notifications` e
 * reconsulta das colunas de carimbo) — nunca pelo texto do console
 * (.planning/learnings/desempenho-bonificacao.md §4). A exceção deliberada é
 * o teste do RESUMO do comando, cujo objeto de verificação É o texto.
 */
class Phase143AvisoComposicaoTest extends TestCase
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

    /**
     * Linha de EMPRESA congelada — é dela que a composição do grupo é lida
     * (o conjunto de `company_id` gravado sob aquele grupo de cobrança).
     */
    private function linhaEmpresa(Carbon $mes, Company $company, ?int $grupoId): FechamentoSnapshot
    {
        return FechamentoSnapshot::create([
            'company_id'       => $company->id,
            'company_name'     => $company->name,
            'company_group_id' => $grupoId,
            'mes_referencia'   => $mes->copy()->startOfMonth()->toDateString(),
            'faixa_ordem'      => 1,
            'faixa_aplicada'   => 'faixa_1',
            'evolucao'         => 'manteve',
            'estado'           => FechamentoSnapshot::ESTADO_OK,
            'origem'           => FechamentoSnapshot::ORIGEM_CONSOLIDAR_MES,
            'gerado_em'        => now(),
        ]);
    }

    private function linhaGrupo(Carbon $mes, CompanyGroup $grupo, array $overrides = []): FechamentoGrupoSnapshot
    {
        return FechamentoGrupoSnapshot::create(array_merge([
            'company_group_id' => $grupo->id,
            'grupo_name'       => $grupo->name,
            'mes_referencia'   => $mes->copy()->startOfMonth()->toDateString(),
            'faixa_ordem'      => 3,
            'faixa_aplicada'   => 'faixa_3',
            'evolucao'         => 'subiu',
            'estado'           => FechamentoSnapshot::ESTADO_OK,
            'origem'           => FechamentoGrupoSnapshot::ORIGEM_CONSOLIDAR_MES,
            'empresas_count'   => 2,
            'gerado_em'        => now(),
        ], $overrides));
    }

    /**
     * Monta os dois meses de um grupo de cobrança: `$idsAnterior` e
     * `$idsAtual` são índices dentro de `$empresas`, para o teste declarar a
     * composição de cada competência sem repetir fixture.
     *
     * @param  Company[]  $empresas
     */
    private function montarDoisMeses(CompanyGroup $grupo, array $empresas, array $indicesAnterior, array $indicesAtual, array $overridesGrupoAtual = []): array
    {
        $mesAtual    = Carbon::create(2026, 8, 1);
        $mesAnterior = Carbon::create(2026, 7, 1);

        foreach ($indicesAnterior as $i) {
            $this->linhaEmpresa($mesAnterior, $empresas[$i], $grupo->id);
        }
        $this->linhaGrupo($mesAnterior, $grupo, [
            'faixa_ordem'            => 1,
            'faixa_aplicada'         => 'faixa_1',
            'evolucao'               => 'manteve',
            'notificado_em'          => now(),
            'notificado_faixa_ordem' => 1,
        ]);

        foreach ($indicesAtual as $i) {
            $this->linhaEmpresa($mesAtual, $empresas[$i], $grupo->id);
        }
        $linhaAtual = $this->linhaGrupo($mesAtual, $grupo, $overridesGrupoAtual);

        return [$mesAtual, $linhaAtual];
    }

    // ─── ⚠️ Regressão zero: composição igual, aviso igual ao de hoje ──────

    #[Test]
    public function composicao_igual_nos_dois_meses_avisa_exatamente_como_hoje(): void
    {
        $grupo    = CompanyGroup::create(['name' => 'Cliente Estável']);
        $empresas = Company::factory()->count(2)->create()->all();

        [$mes, $linhaAtual] = $this->montarDoisMeses($grupo, $empresas, [0, 1], [0, 1]);

        $admin = $this->criarAdmin();

        $resumo = app(FechamentoFaixaNotifier::class)->notificar($mes);

        $this->assertSame(1, $resumo['grupos'], 'A faixa mudou com a MESMA composição — é desempenho, e o aviso tem de sair como sempre saiu.');
        $this->assertSame(1, $resumo['notificacoes']);
        $this->assertSame([], $resumo['composicao_mudou'], 'Nada mudou de composição — a lista sai vazia.');

        $this->assertSame(1, DatabaseNotification::where('notifiable_id', $admin->id)->count());

        $linhaAtual->refresh();
        $this->assertNotNull($linhaAtual->notificado_em, 'Linha avisada é linha carimbada.');
        $this->assertSame(3, (int) $linhaAtual->notificado_faixa_ordem);
    }

    // ─── A composição mudou: nada de "cresceu" ────────────────────────────

    #[Test]
    public function grupo_juntado_entre_as_competencias_nao_vira_aviso_de_crescimento(): void
    {
        $grupo    = CompanyGroup::create(['name' => 'MPozenato']);
        $empresas = Company::factory()->count(10)->create()->all();

        // Julho: só as 2 empresas do MPozenato. Agosto: as 10, porque DRossi,
        // Gran Belo e Lyam foram juntados. O caso literal do 143-CONTEXT.
        [$mes, $linhaAtual] = $this->montarDoisMeses($grupo, $empresas, [0, 1], range(0, 9));

        $admin = $this->criarAdmin();

        $resumo = app(FechamentoFaixaNotifier::class)->notificar($mes);

        $this->assertSame(0, $resumo['grupos'], 'A faixa mudou por composição — não pode virar aviso de crescimento.');
        $this->assertSame(0, $resumo['notificacoes']);

        $this->assertSame(
            0,
            DatabaseNotification::where('notifiable_id', $admin->id)->count(),
            '10 empresas entrando numa linha só gerariam UM aviso dizendo que o cliente cresceu — a mentira mais cara possível, porque chega na hora da conferência.'
        );

        // ⚠️ Não silenciar em silêncio: o resumo precisa dizer o que houve.
        $this->assertCount(1, $resumo['composicao_mudou']);
        $this->assertSame($grupo->id, $resumo['composicao_mudou'][0]['company_group_id']);
        $this->assertSame('MPozenato', $resumo['composicao_mudou'][0]['nome']);
        $this->assertSame(8, $resumo['composicao_mudou'][0]['entraram']);
        $this->assertSame(0, $resumo['composicao_mudou'][0]['sairam']);

        $linhaAtual->refresh();
        $this->assertNull($linhaAtual->notificado_em, 'Não houve aviso, então não há o que carimbar.');
    }

    #[Test]
    public function empresa_que_saiu_do_grupo_recebe_o_mesmo_tratamento(): void
    {
        $grupo    = CompanyGroup::create(['name' => 'Cliente Encolhido']);
        $empresas = Company::factory()->count(4)->create()->all();

        // Julho: 4 empresas. Agosto: 2 — duas saíram.
        [$mes] = $this->montarDoisMeses($grupo, $empresas, [0, 1, 2, 3], [0, 1], [
            'faixa_ordem'    => 1,
            'faixa_aplicada' => 'faixa_1',
            'evolucao'       => 'desceu',
        ]);

        $admin = $this->criarAdmin();

        $resumo = app(FechamentoFaixaNotifier::class)->notificar($mes);

        $this->assertSame(0, $resumo['grupos']);
        $this->assertSame(0, DatabaseNotification::where('notifiable_id', $admin->id)->count());

        $this->assertCount(1, $resumo['composicao_mudou']);
        $this->assertSame(0, $resumo['composicao_mudou'][0]['entraram']);
        $this->assertSame(2, $resumo['composicao_mudou'][0]['sairam']);
    }

    #[Test]
    public function troca_de_empresa_conta_entrada_e_saida_ao_mesmo_tempo(): void
    {
        $grupo    = CompanyGroup::create(['name' => 'Cliente Trocado']);
        $empresas = Company::factory()->count(3)->create()->all();

        [$mes] = $this->montarDoisMeses($grupo, $empresas, [0, 1], [1, 2]);

        $this->criarAdmin();

        $resumo = app(FechamentoFaixaNotifier::class)->notificar($mes);

        $this->assertCount(1, $resumo['composicao_mudou']);
        $this->assertSame(1, $resumo['composicao_mudou'][0]['entraram']);
        $this->assertSame(1, $resumo['composicao_mudou'][0]['sairam']);
    }

    // ─── A supressão de um grupo não contamina os vizinhos ────────────────

    #[Test]
    public function o_grupo_que_nao_mudou_de_composicao_continua_avisando_no_mesmo_lote(): void
    {
        $estavel = CompanyGroup::create(['name' => 'Cliente Estável']);
        $juntado = CompanyGroup::create(['name' => 'MPozenato']);

        $empresasEstavel = Company::factory()->count(2)->create()->all();
        $empresasJuntado = Company::factory()->count(4)->create()->all();

        $this->montarDoisMeses($estavel, $empresasEstavel, [0, 1], [0, 1]);
        [$mes] = $this->montarDoisMeses($juntado, $empresasJuntado, [0, 1], [0, 1, 2, 3]);

        $admin = $this->criarAdmin();

        $resumo = app(FechamentoFaixaNotifier::class)->notificar($mes);

        $this->assertSame(1, $resumo['grupos'], 'Só o grupo estável entra no aviso.');
        $this->assertCount(1, $resumo['composicao_mudou']);
        $this->assertSame('MPozenato', $resumo['composicao_mudou'][0]['nome']);

        $notificacao = DatabaseNotification::where('notifiable_id', $admin->id)->firstOrFail();
        $this->assertStringContainsString('Cliente Estável', $notificacao->data['mensagem']);
        $this->assertStringNotContainsString('MPozenato', $notificacao->data['mensagem']);
    }

    #[Test]
    public function empresa_que_mudou_de_faixa_continua_avisando_mesmo_com_grupo_suprimido(): void
    {
        $grupo    = CompanyGroup::create(['name' => 'MPozenato']);
        $empresas = Company::factory()->count(4)->create()->all();

        [$mes] = $this->montarDoisMeses($grupo, $empresas, [0, 1], [0, 1, 2, 3]);

        // Uma empresa fora de qualquer grupo, com mudança de faixa real.
        $solitaria = Company::factory()->create();
        FechamentoSnapshot::create([
            'company_id'     => $solitaria->id,
            'company_name'   => $solitaria->name,
            'mes_referencia' => $mes->copy()->startOfMonth()->toDateString(),
            'faixa_ordem'    => 4,
            'faixa_aplicada' => 'faixa_4',
            'evolucao'       => 'subiu',
            'estado'         => FechamentoSnapshot::ESTADO_OK,
            'origem'         => FechamentoSnapshot::ORIGEM_CONSOLIDAR_MES,
            'gerado_em'      => now(),
        ]);

        $admin = $this->criarAdmin();

        $resumo = app(FechamentoFaixaNotifier::class)->notificar($mes);

        $this->assertSame(1, $resumo['empresas'], 'A supressão é só da linha de grupo — empresa nenhuma é afetada.');
        $this->assertSame(0, $resumo['grupos']);
        $this->assertSame(1, DatabaseNotification::where('notifiable_id', $admin->id)->count());
    }

    // ─── Idempotência: "Refazer fechamento" não ressuscita aviso ──────────

    #[Test]
    public function refazer_o_fechamento_nao_ressuscita_aviso_nem_com_composicao_igual(): void
    {
        $grupo    = CompanyGroup::create(['name' => 'Cliente Estável']);
        $empresas = Company::factory()->count(2)->create()->all();

        [$mes] = $this->montarDoisMeses($grupo, $empresas, [0, 1], [0, 1]);

        $admin = $this->criarAdmin();

        app(FechamentoFaixaNotifier::class)->notificar($mes);
        $segundo = app(FechamentoFaixaNotifier::class)->notificar($mes);

        $this->assertSame(0, $segundo['grupos'], 'O carimbo `notificado_faixa_ordem` segue mandando — nada foi contornado.');
        $this->assertSame(
            1,
            DatabaseNotification::where('notifiable_id', $admin->id)->count(),
            'Duas rodadas, um aviso só: a idempotência da Fase 138 continua inteira.'
        );
    }

    #[Test]
    public function refazer_o_fechamento_nao_ressuscita_aviso_da_linha_suprimida(): void
    {
        $grupo    = CompanyGroup::create(['name' => 'MPozenato']);
        $empresas = Company::factory()->count(4)->create()->all();

        [$mes, $linhaAtual] = $this->montarDoisMeses($grupo, $empresas, [0, 1], [0, 1, 2, 3]);

        $admin = $this->criarAdmin();

        app(FechamentoFaixaNotifier::class)->notificar($mes);
        $segundo = app(FechamentoFaixaNotifier::class)->notificar($mes);

        $this->assertSame(0, $segundo['grupos']);
        $this->assertSame(0, DatabaseNotification::where('notifiable_id', $admin->id)->count());

        // A comparação é determinística: a linha segue suprimida, e segue
        // sem carimbo (não houve aviso a registrar).
        $this->assertCount(1, $segundo['composicao_mudou']);
        $linhaAtual->refresh();
        $this->assertNull($linhaAtual->notificado_em);
    }

    // ─── Quando não dá para comparar ──────────────────────────────────────

    #[Test]
    public function sem_nenhuma_empresa_registrada_no_mes_anterior_o_aviso_sai_como_sempre(): void
    {
        $mesAtual    = Carbon::create(2026, 8, 1);
        $mesAnterior = Carbon::create(2026, 7, 1);

        $grupo    = CompanyGroup::create(['name' => 'Cliente Novo']);
        $empresas = Company::factory()->count(2)->create()->all();

        // Mês anterior: existe a linha de GRUPO (senão não haveria evolução),
        // mas nenhuma linha de empresa sob ele — não há composição a comparar.
        $this->linhaGrupo($mesAnterior, $grupo, ['faixa_ordem' => 1, 'evolucao' => 'manteve']);

        foreach ($empresas as $empresa) {
            $this->linhaEmpresa($mesAtual, $empresa, $grupo->id);
        }
        $this->linhaGrupo($mesAtual, $grupo);

        $admin = $this->criarAdmin();

        $resumo = app(FechamentoFaixaNotifier::class)->notificar($mesAtual);

        $this->assertSame(
            1,
            $resumo['grupos'],
            'Sem dado para comparar, o comportamento é o de sempre — calar um aviso legítimo por falta de dado seria pior que o problema.'
        );
        $this->assertSame([], $resumo['composicao_mudou']);
        $this->assertSame(1, DatabaseNotification::where('notifiable_id', $admin->id)->count());
    }

    // ─── O resumo do comando, ponta a ponta ───────────────────────────────

    /**
     * Fixture de verdade (empresas, faturamento, tabela do grupo) para rodar
     * `fechamento:consolidar-mes` nos DOIS meses — os testes dos blocos acima
     * montam snapshot à mão, que o comando reescreveria.
     *
     * @return array{0: CompanyGroup, 1: CompanyGroup}
     */
    private function montarCasoRealDeDoisMeses(): array
    {
        $servico = Servico::firstOrCreate(
            ['nome' => 'Gestão'],
            ['valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true]
        );

        $raiz     = CompanyGroup::create(['name' => 'MPozenato', 'color' => '#000']);
        $subgrupo = CompanyGroup::create(['name' => 'Gran Belo', 'color' => '#000']);

        $criar = function (CompanyGroup $grupo, float $julho, float $agosto) use ($servico) {
            $company = Company::factory()->create([
                'adman_account_id' => 'cust-'.uniqid(),
                'company_group_id' => $grupo->id,
            ]);

            ContratoServico::factory()->paraServico($servico)->create([
                'company_id' => $company->id,
                'ativo'      => true,
            ]);

            AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-07-10', 'revenue' => $julho]);
            AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => $agosto]);

            return $company;
        };

        $criar($raiz, 3_000_000.00, 3_000_000.00);
        $criar($raiz, 812_487.89, 812_487.89);
        $criar($subgrupo, 5_000_000.00, 5_000_000.00);
        $criar($subgrupo, 977_697.79, 977_697.79);

        // Tabela do grupo de cobrança: até R$ 5 mi cobra R$ 12.000; acima,
        // R$ 21.000. Sozinho o MPozenato fica na faixa 1; junto com o
        // Gran Belo passa dos R$ 9,7 mi e cai na faixa 2.
        GrupoFaixaFaturamento::create([
            'company_group_id' => $raiz->id, 'ordem' => 1,
            'limite_superior'  => 5_000_000.00, 'valor' => 12_000.00, 'valor_e_piso' => false,
        ]);
        GrupoFaixaFaturamento::create([
            'company_group_id' => $raiz->id, 'ordem' => 2,
            'limite_superior'  => null, 'valor' => 21_000.00, 'valor_e_piso' => true,
        ]);

        return [$raiz, $subgrupo];
    }

    #[Test]
    public function juntar_um_grupo_entre_os_dois_meses_aparece_no_resumo_e_nao_vira_notificacao(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        [$raiz, $subgrupo] = $this->montarCasoRealDeDoisMeses();
        $admin = $this->criarAdmin();

        // Julho fecha com os dois grupos separados.
        $this->assertSame(0, Artisan::call('fechamento:consolidar-mes', ['--mes' => '2026-07']));

        $this->assertSame(
            1,
            (int) FechamentoGrupoSnapshot::whereDate('mes_referencia', '2026-07-01')
                ->where('company_group_id', $raiz->id)->value('faixa_ordem'),
            'Sozinho, o MPozenato fica na faixa 1 (R$ 3,81 mi).'
        );

        // ⚠️ Entre as duas competências, o usuário junta os grupos pela tela.
        $subgrupo->update(['parent_id' => $raiz->id]);

        // O resumo e lido por captura direta (Artisan::output), nao por
        // expectsOutputToContain: aquele casa UMA escrita por expectativa, e
        // as tres coisas que importam aqui saem todas na MESMA linha.
        $this->assertSame(0, Artisan::call('fechamento:consolidar-mes', ['--mes' => '2026-08']));

        $saida = Artisan::output();

        $this->assertStringContainsString('mudou QUEM faz parte deles', $saida, 'Silenciar em silencio seria so trocar uma mentira por uma omissao.');
        $this->assertStringContainsString('MPozenato', $saida, 'O resumo tem de dizer DE QUEM e a linha.');
        $this->assertStringContainsString('2 empresas entraram', $saida, 'E de que tamanho foi a mudanca.');

        $linhaAgosto = FechamentoGrupoSnapshot::whereDate('mes_referencia', '2026-08-01')
            ->where('company_group_id', $raiz->id)
            ->firstOrFail();

        $this->assertSame(2, (int) $linhaAgosto->faixa_ordem, 'Juntos passam de R$ 9,7 mi e caem na faixa 2.');
        $this->assertSame('subiu', $linhaAgosto->evolucao, 'A coluna `evolucao` continua registrando o que aconteceu — o que muda é o AVISO.');
        $this->assertNull($linhaAgosto->notificado_em, 'Sem aviso, sem carimbo.');

        // ⚠️ O que não pode sair é o aviso DA LINHA DO CLIENTE. Outras
        // mudanças da mesma rodada (empresas que trocaram de régua ao entrar
        // no grupo) continuam sendo avisadas normalmente — a supressão é
        // cirúrgica, nunca um silêncio geral.
        foreach (DatabaseNotification::where('notifiable_id', $admin->id)->get() as $notificacao) {
            $this->assertStringNotContainsString(
                'Grupo MPozenato',
                $notificacao->data['mensagem'],
                'O cliente não cresceu: quem mudou foi quem faz parte dele. A linha não pode aparecer no aviso.'
            );
        }
    }

    #[Test]
    public function sem_juntar_ninguem_o_comando_avisa_e_o_resumo_nao_ganha_linha_nova(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        [$raiz] = $this->montarCasoRealDeDoisMeses();

        // Agosto da raiz sobe acima de R$ 5 mi por faturamento de verdade —
        // mesma composição nos dois meses.
        AdmanMetric::create([
            'company_id'     => Company::where('company_group_id', $raiz->id)->orderBy('id')->value('id'),
            'reference_date' => '2026-08-11',
            'revenue'        => 2_000_000.00,
        ]);

        $admin = $this->criarAdmin();

        $this->assertSame(0, Artisan::call('fechamento:consolidar-mes', ['--mes' => '2026-07']));

        $this->assertSame(0, Artisan::call('fechamento:consolidar-mes', ['--mes' => '2026-08']));

        $this->assertStringNotContainsString(
            'mudou QUEM faz parte deles',
            Artisan::output(),
            'Composicao igual: o resumo nao ganha linha nenhuma a mais.'
        );

        $linhaAgosto = FechamentoGrupoSnapshot::whereDate('mes_referencia', '2026-08-01')
            ->where('company_group_id', $raiz->id)
            ->firstOrFail();

        $this->assertSame(2, (int) $linhaAgosto->faixa_ordem);
        $this->assertSame('subiu', $linhaAgosto->evolucao);
        $this->assertNotNull($linhaAgosto->notificado_em, 'Composição igual: o aviso sai como sempre saiu, e carimba.');

        $this->assertSame(
            1,
            DatabaseNotification::where('notifiable_id', $admin->id)->count(),
            '⚠️ Regressão zero: enquanto ninguém juntar grupo nenhum, o aviso é exatamente o de hoje.'
        );
    }
}
