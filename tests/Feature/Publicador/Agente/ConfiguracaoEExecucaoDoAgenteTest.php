<?php

namespace Tests\Feature\Publicador\Agente;

use App\Models\Company;
use App\Models\PubAgenteConfiguracao;
use App\Models\PubAgenteExecucao;
use App\Models\PubProduto;
use App\Models\User;
use App\Services\Publicador\Agente\ConfiguracaoDoAgenteService;
use App\Services\Publicador\Agente\ExecucoesDoAgente;
use App\Services\Publicador\Agente\PoliticaDoAgente;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Support\Publicador\RegraViolada;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * AG-01 do Agente ECF Publicador (20/10/2026): configuração e controle POR LOJA, e execução de preparo sem
 * duplicar trabalho. Critério de fechamento do plano: duas contas isoladas; dupla solicitação devolve uma
 * execução; conta desligada não admite preparo; alteração concorrente de configuração recusa.
 */
class ConfiguracaoEExecucaoDoAgenteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ConfiguracaoDoAgenteService $config;

    private ExecucoesDoAgente $execucoes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->config = app(ConfiguracaoDoAgenteService::class);
        $this->execucoes = app(ExecucoesDoAgente::class);
    }

    private function conta(Company $c): array
    {
        return app(ProgramasPublicadorService::class)->resolver("company-{$c->id}");
    }

    private function produto(Company $c, string $sku = 'CAD-01'): PubProduto
    {
        return PubProduto::create(['company_id' => $c->id, 'sku' => $sku, 'nome' => $sku, 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
    }

    /** Conta com o agente ligado, na versão 1. */
    private function ligada(Company $c, array $dados = []): array
    {
        $conta = $this->conta($c);
        $this->config->salvar($conta, ['habilitado' => true, 'modalidade' => 'gold_special', ...$dados], 0, $this->admin);

        return $conta;
    }

    // ─── Configuração ───

    public function test_conta_sem_configuracao_nasce_desligada_e_nao_admite(): void
    {
        $c = Company::factory()->create();
        $conta = $this->conta($c);

        $cfg = $this->config->daConta($conta);
        $this->assertFalse($cfg->exists);
        $this->assertFalse($cfg->habilitado);
        $this->assertSame(0, $cfg->versao);

        try {
            $this->execucoes->admitir($conta, $this->produto($c), PubAgenteExecucao::ORIGEM_MANUAL, $this->admin);
            $this->fail('admitiu sem configuração');
        } catch (RegraViolada $e) {
            $this->assertSame(PoliticaDoAgente::REGRA_DESLIGADO, $e->regra);
        }
        $this->assertSame(0, PubAgenteExecucao::count());
    }

    public function test_salvar_sobe_a_versao_e_guarda_snapshot_de_cada_uma(): void
    {
        $c = Company::factory()->create();
        $conta = $this->conta($c);

        $v1 = $this->config->salvar($conta, ['habilitado' => false, 'limite_diario' => 3], 0, $this->admin);
        $v2 = $this->config->salvar($conta, ['habilitado' => true, 'janela_inicio' => '08:00', 'janela_fim' => '18:00'], 1, $this->admin);

        $this->assertSame(1, PubAgenteConfiguracao::count());
        $this->assertSame(2, $v2->versao);
        $this->assertSame($c->id, $v2->company_id);
        $this->assertSame('08:00:00', $v2->fresh()->janela_inicio);
        $versoes = $v2->versoes()->get();
        $this->assertSame([1, 2], $versoes->pluck('versao')->all());
        $this->assertFalse($versoes[0]->dados['habilitado']);
        $this->assertSame(3, $versoes[0]->dados['limite_diario']);
        $this->assertTrue($versoes[1]->dados['habilitado']);
        $this->assertSame($v1->id, $v2->id);
    }

    public function test_versao_obsoleta_recusa_e_nao_sobrescreve(): void
    {
        $c = Company::factory()->create();
        $conta = $this->conta($c);
        $this->config->salvar($conta, ['limite_diario' => 3], 0, $this->admin);
        $this->config->salvar($conta, ['limite_diario' => 7], 1, $this->admin); // outra pessoa salvou

        try {
            $this->config->salvar($conta, ['limite_diario' => 99], 1, $this->admin); // quem viu a v1
            $this->fail('sobrescreveu com versão obsoleta');
        } catch (RegraViolada $e) {
            $this->assertSame(ConfiguracaoDoAgenteService::REGRA_VERSAO, $e->regra);
        }

        // Criar de novo uma configuração que já existe também é versão obsoleta.
        $this->expectException(RegraViolada::class);
        try {
            $this->config->salvar($conta, ['limite_diario' => 1], 0, $this->admin);
        } finally {
            $this->assertSame(7, PubAgenteConfiguracao::first()->limite_diario);
            $this->assertSame(2, PubAgenteConfiguracao::first()->versao);
        }
    }

    public function test_valores_invalidos_sao_recusados(): void
    {
        $conta = $this->conta(Company::factory()->create());

        $this->expectException(ValidationException::class);
        $this->config->salvar($conta, ['modalidade' => 'free'], 0, $this->admin);
    }

    public function test_pausar_e_suspender_nao_exigem_versao_e_deixam_snapshot(): void
    {
        $c = Company::factory()->create();
        $conta = $this->ligada($c);

        $this->config->pausar($conta, $this->admin, 'conferindo fotos');
        $cfg = $this->config->suspender($conta, $this->admin);

        $this->assertSame(PubAgenteConfiguracao::SUSPENSO, $cfg->controle);
        $this->assertSame(3, $cfg->versao);
        $this->assertSame([PubAgenteConfiguracao::ATIVO, PubAgenteConfiguracao::PAUSADO, PubAgenteConfiguracao::SUSPENSO],
            $cfg->versoes()->get()->map(fn ($v) => $v->dados['controle'])->all());

        // Repetir o mesmo controle não cria versão.
        $this->assertSame(3, $this->config->suspender($conta, $this->admin)->versao);

        $this->expectException(RegraViolada::class);
        $this->config->pausar($this->conta(Company::factory()->create()), $this->admin);
    }

    // ─── Admissão ───

    public function test_dupla_solicitacao_devolve_a_mesma_execucao(): void
    {
        $c = Company::factory()->create();
        $conta = $this->ligada($c);
        $p = $this->produto($c);

        $a = $this->execucoes->admitir($conta, $p, PubAgenteExecucao::ORIGEM_AO_SALVAR, null, 'fatos-1');
        $b = $this->execucoes->admitir($conta, $p, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin, 'fatos-1');

        $this->assertTrue($a['nova']);
        $this->assertFalse($b['nova']);
        $this->assertSame($a['execucao']->id, $b['execucao']->id);
        $this->assertSame(1, PubAgenteExecucao::count());
        $this->assertSame(1, $a['execucao']->configuracao_versao);
        $this->assertSame("{$conta['chave']}:{$p->id}", $a['execucao']->chave_ativa);
    }

    public function test_o_banco_recusa_duas_vivas_do_mesmo_produto(): void
    {
        $c = Company::factory()->create();
        $conta = $this->ligada($c);
        $p = $this->produto($c);
        $viva = $this->execucoes->admitir($conta, $p, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin)['execucao'];

        $this->expectException(QueryException::class);
        PubAgenteExecucao::create([...$viva->only(['conta_chave', 'produto_id', 'origem', 'configuracao_versao', 'chave_logica', 'chave_ativa']),
            'uuid' => 'outra-'.str_repeat('x', 30)]);
    }

    public function test_mesmos_fatos_ja_preparados_nao_repetem_e_fatos_novos_admitem(): void
    {
        $c = Company::factory()->create();
        $conta = $this->ligada($c);
        $p = $this->produto($c);

        $primeira = $this->execucoes->admitir($conta, $p, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin, 'fatos-1')['execucao'];
        $this->execucoes->encerrar($primeira, PubAgenteExecucao::AGUARDANDO_APROVACAO);
        $this->assertNull($primeira->fresh()->chave_ativa);

        $repetida = $this->execucoes->admitir($conta, $p, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin, 'fatos-1');
        $this->assertFalse($repetida['nova']);
        $this->assertSame($primeira->id, $repetida['execucao']->id);

        $nova = $this->execucoes->admitir($conta, $p, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin, 'fatos-2');
        $this->assertTrue($nova['nova']);
        $this->assertSame(2, PubAgenteExecucao::count());
    }

    public function test_execucao_que_falhou_nao_segura_um_novo_pedido(): void
    {
        $c = Company::factory()->create();
        $conta = $this->ligada($c);
        $p = $this->produto($c);

        $falhou = $this->execucoes->admitir($conta, $p, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin, 'fatos-1')['execucao'];
        $this->execucoes->encerrar($falhou, PubAgenteExecucao::FALHOU, 'timeout da IA');

        $outra = $this->execucoes->admitir($conta, $p, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin, 'fatos-1');
        $this->assertTrue($outra['nova']);
        $this->assertNotSame($falhou->id, $outra['execucao']->id);
    }

    public function test_duas_contas_sao_isoladas(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        $contaA = $this->ligada($a);
        $contaB = $this->conta($b); // B não configurou o agente
        $produtoDeB = $this->produto($b, 'B-01');

        // A ligada não admite produto de B, e B desligada não admite o próprio.
        foreach ([[$contaA, PoliticaDoAgente::REGRA_CONTA], [$contaB, PoliticaDoAgente::REGRA_DESLIGADO]] as [$conta, $regra]) {
            try {
                $this->execucoes->admitir($conta, $produtoDeB, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin);
                $this->fail("admitiu em {$conta['chave']}");
            } catch (RegraViolada $e) {
                $this->assertSame($regra, $e->regra);
            }
        }

        // Pausar A não afeta a configuração de B.
        $this->ligada($b);
        $this->config->pausar($contaA, $this->admin);
        $this->assertTrue($this->execucoes->admitir($contaB, $produtoDeB, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin)['nova']);
        $this->assertSame(PubAgenteConfiguracao::ATIVO, $this->config->daConta($contaB)->controle);
    }

    public function test_pausado_ou_suspenso_nao_admite_e_retomar_volta_a_admitir(): void
    {
        $c = Company::factory()->create();
        $conta = $this->ligada($c);
        $p = $this->produto($c);

        foreach (['pausar', 'suspender'] as $acao) {
            $this->config->{$acao}($conta, $this->admin);
            try {
                $this->execucoes->admitir($conta, $p, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin);
                $this->fail("admitiu depois de {$acao}");
            } catch (RegraViolada $e) {
                $this->assertSame(PoliticaDoAgente::REGRA_CONTROLE, $e->regra);
            }
        }

        $this->config->retomar($conta, $this->admin);
        $this->assertTrue($this->execucoes->admitir($conta, $p, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin)['nova']);
    }

    public function test_execucao_ja_admitida_nao_segue_depois_de_suspender(): void
    {
        $c = Company::factory()->create();
        $conta = $this->ligada($c);
        $execucao = $this->execucoes->admitir($conta, $this->produto($c), PubAgenteExecucao::ORIGEM_MANUAL, $this->admin)['execucao'];
        $politica = app(PoliticaDoAgente::class);

        $this->assertNull($politica->recusaParaSeguir($execucao));
        $this->config->suspender($conta, $this->admin);
        $this->assertSame(PoliticaDoAgente::REGRA_CONTROLE, $politica->recusaParaSeguir($execucao)['regra']);
    }

    public function test_escopo_inclui_e_exclui_produtos(): void
    {
        $c = Company::factory()->create();
        $dentro = $this->produto($c, 'IN');
        $fora = $this->produto($c, 'OUT');
        $conta = $this->ligada($c, ['escopo' => ['incluir' => [$dentro->id], 'excluir' => []]]);

        $this->assertTrue($this->execucoes->admitir($conta, $dentro, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin)['nova']);
        $this->expectException(RegraViolada::class);
        $this->execucoes->admitir($conta, $fora, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin);
    }

    public function test_janela_vale_so_para_o_automatico(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 23:30', 'America/Sao_Paulo'));
        $c = Company::factory()->create();
        $conta = $this->ligada($c, ['janela_inicio' => '08:00', 'janela_fim' => '18:00']);
        $p = $this->produto($c);

        try {
            $this->execucoes->admitir($conta, $p, PubAgenteExecucao::ORIGEM_AUTOMATICA);
            $this->fail('admitiu fora da janela');
        } catch (RegraViolada $e) {
            $this->assertSame(PoliticaDoAgente::REGRA_JANELA, $e->regra);
        }
        $this->assertTrue($this->execucoes->admitir($conta, $p, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin)['nova']);

        // Janela que passa da meia-noite.
        $cfg = new PubAgenteConfiguracao(['janela_inicio' => '22:00:00', 'janela_fim' => '06:00:00']);
        $this->assertTrue(PoliticaDoAgente::dentroDaJanela($cfg, Carbon::parse('2026-10-20 23:30', 'America/Sao_Paulo')));
        $this->assertFalse(PoliticaDoAgente::dentroDaJanela($cfg, Carbon::parse('2026-10-20 12:00', 'America/Sao_Paulo')));
    }
}
