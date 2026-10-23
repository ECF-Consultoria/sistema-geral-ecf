<?php

namespace Tests\Feature\Publicador\Agente;

use App\Jobs\Publicador\GerarPreparoIaJob;
use App\Models\PubAgenteExecucao;
use App\Models\PubAprovacaoOferta;
use App\Models\PubRascunho;
use App\Services\Publicador\Agente\ControleDoAgente;
use App\Services\Publicador\Agente\EntradaDoPreparo;
use App\Services\Publicador\Agente\ExecucoesDoAgente;
use App\Services\Publicador\Agente\PreparadorDaAprovacao;
use App\Services\Publicador\Agente\VarreduraDoAgente;
use App\Services\Publicador\PreparoIaDoRascunhoService;
use App\Support\Publicador\RegraViolada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * AG-06 do Agente ECF Publicador (20/10/2026): limites, pausa/suspensão no worker, recuperação e varredura.
 * Critério de fechamento do plano: limites respeitados; suspensão alcança Job já enfileirado; retomada não
 * repete gasto. (A presença da revisão segurando a publicação — G10 — fica para depois da 189 e da 193.)
 */
class LimitesPausaERecuperacaoDoAgenteTest extends TestCase
{
    use CenarioDoAgente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarAgente();
    }

    private function pedir($pub): array
    {
        return app(EntradaDoPreparo::class)->pedir($this->conta(), $pub, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin);
    }

    private function regraDe(callable $f): ?string
    {
        try {
            $f();
        } catch (RegraViolada $e) {
            return $e->regra;
        }

        return null;
    }

    // ─── Limites ───

    public function test_limite_diario_conta_produto_admitido_e_nao_conta_o_bloqueado(): void
    {
        $this->ligar(['limite_diario' => 1]);
        $incompleto = $this->produtoDoPortal('INC');
        $incompleto->variacoes()->first()->update(['custo' => null]);
        $pubIncompleto = $this->noPublicador($incompleto);
        $a = $this->noPublicador($this->produtoDoPortal('A'));
        $b = $this->noPublicador($this->produtoDoPortal('B'));

        $this->assertSame(PubAgenteExecucao::BLOQUEADA, $this->pedir($pubIncompleto)['execucao']->estado);
        $this->assertTrue($this->pedir($a)['nova'], 'o bloqueado gastou a vaga do dia');
        $this->assertSame(ExecucoesDoAgente::REGRA_LIMITE_DIARIO, $this->regraDe(fn () => $this->pedir($b)));

        // Repetir o que já foi admitido não é trabalho novo: devolve a mesma, sem esbarrar no limite.
        $this->assertFalse($this->pedir($a)['nova']);
    }

    public function test_teto_da_fila_conta_oferta_esperando_gente_e_execucao_em_curso(): void
    {
        $this->ligar(['teto_fila' => 1]);
        $a = $this->noPublicador($this->produtoDoPortal('A'));
        $b = $this->noPublicador($this->produtoDoPortal('B'));

        $this->pedir($a);
        $this->assertSame(ExecucoesDoAgente::REGRA_TETO_FILA, $this->regraDe(fn () => $this->pedir($b)), 'a vaga reservada pela viva não contou');

        $this->rodar(GerarPreparoIaJob::class);
        app(PreparadorDaAprovacao::class)->encaminharProntas();
        $this->assertSame(1, PubAprovacaoOferta::where('andamento', PubAprovacaoOferta::AGUARDANDO_APROVACAO)->count());
        $this->assertSame(ExecucoesDoAgente::REGRA_TETO_FILA, $this->regraDe(fn () => $this->pedir($b)), 'a oferta esperando a pessoa não contou');
    }

    // ─── Pausa e suspensão no worker ───

    public function test_suspensao_alcanca_job_ja_na_fila_e_retomar_nao_refaz_o_que_deu_certo(): void
    {
        $this->ligar();
        $pub = $this->noPublicador();
        $r = PubRascunho::where('produto_id', $pub->id)->first();
        $r->update(['descricao' => null]);
        $this->pedir($pub);

        // As DUAS cadeias estão na fila ([título → Modelo] e [descrição], despacharCadeias 21/10/2026): o título roda
        // antes da suspensão; o Modelo (elo seguinte) e a descrição (outra cadeia) já encontram a loja suspensa.
        $cabecas = Queue::pushed(GerarPreparoIaJob::class)->keyBy(fn ($j) => $j->etapa);
        $titulo = $cabecas[PreparoIaDoRascunhoService::TITULO];
        $this->executar($titulo);
        app(ControleDoAgente::class)->suspender($this->conta(), $this->admin, 'conferir fotos');
        foreach ([...array_map('unserialize', $titulo->chained), $cabecas[PreparoIaDoRascunhoService::DESCRICAO]] as $job) {
            $this->assertSame(PreparoIaDoRascunhoService::DESISTIU, $this->executar($job), "a etapa {$job->etapa} rodou com a loja suspensa");
        }
        $this->assertNull($r->fresh()->descricao, 'escreveu com o agente suspenso');
        $this->assertSame('pausada', app(PreparadorDaAprovacao::class)->encaminhar(PubAgenteExecucao::firstOrFail()));
        $this->assertSame(0, PubAprovacaoOferta::count());

        // Retomar continua a MESMA execução e pede só o que faltou (o título já deu certo).
        $antes = Queue::pushed(GerarPreparoIaJob::class)->count();
        $res = app(ControleDoAgente::class)->retomar($this->conta(), $this->admin);
        $this->assertSame(1, $res['continuadas']);
        $this->assertSame(1, PubAgenteExecucao::count());
        // As cadeias NOVAS são só as do que faltou — Modelo e descrição; o título (já `ok`) não volta a gastar.
        $novas = Queue::pushed(GerarPreparoIaJob::class)->slice($antes)->map(fn ($j) => $j->etapa)->sort()->values()->all();
        $this->assertSame([PreparoIaDoRascunhoService::DESCRICAO, PreparoIaDoRascunhoService::MODELO], $novas);
    }

    public function test_valor_que_volta_da_ia_com_o_agente_pausado_espera_e_nao_escreve(): void
    {
        $this->ligar();
        $pub = $this->noPublicador();
        $r = PubRascunho::where('produto_id', $pub->id)->first();
        $r->update(['descricao' => null]);
        $this->pedir($pub);
        $hash = $this->preparo()->hashDosFatos($r->fresh());
        app(ControleDoAgente::class)->pausar($this->conta(), $this->admin);

        $saida = $this->preparo()->executarEtapa($r->id, PreparoIaDoRascunhoService::DESCRICAO, $hash, '<p>Texto que voltou da IA.</p>');

        $this->assertSame(PreparoIaDoRascunhoService::ADIADO, $saida);
        $this->assertNull($r->fresh()->descricao);
        Queue::assertPushed(GerarPreparoIaJob::class, fn ($j) => $j->etapa === PreparoIaDoRascunhoService::DESCRICAO && $j->valorPronto === '<p>Texto que voltou da IA.</p>');
    }

    // ─── Recuperação ───

    public function test_cadeia_que_morreu_e_pedida_de_novo_ate_o_limite_e_depois_bloqueia(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 09:00', 'America/Sao_Paulo'));
        $this->ligar();
        $pub = $this->noPublicador();
        $execucao = $this->pedir($pub)['execucao']; // a cadeia nunca roda: os Jobs "morreram"
        $preparador = app(PreparadorDaAprovacao::class);
        $this->assertSame('aguardando_texto', $preparador->encaminhar($execucao));

        foreach ([1, 2] as $tentativa) {
            Carbon::setTestNow(now()->addHours(4));
            $this->assertSame('recuperando', $preparador->encaminhar($execucao));
            $this->assertSame($tentativa, $execucao->fresh()->tentativas);
        }
        Carbon::setTestNow(now()->addHours(4));
        $this->assertSame('bloqueada', $preparador->encaminhar($execucao));
        $this->assertContains('texto_nao_concluido', array_column($execucao->fresh()->bloqueios, 'codigo'));
        $this->assertSame(0, PubAprovacaoOferta::count());
    }

    public function test_execucao_admitida_que_parou_e_retomada_pela_varredura(): void
    {
        $this->ligar();
        $pub = $this->noPublicador();
        $parada = app(ExecucoesDoAgente::class)->admitir($this->conta(), $pub, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin)['execucao'];
        $parada->update(['heartbeat_em' => now()->subMinutes(30)]);

        $r = app(VarreduraDoAgente::class)->rodar();

        $this->assertSame(1, $r['recuperadas']);
        $this->assertSame(PubAgenteExecucao::PREPARANDO, $parada->fresh()->estado);
        $this->assertSame([PreparoIaDoRascunhoService::DESCRICAO, PreparoIaDoRascunhoService::TITULO], $this->cabecasDasCadeias());
    }

    // ─── Varredura ───

    public function test_varredura_so_descobre_produto_novo_e_nao_repete(): void
    {
        $this->ligar();
        $jaNaRevisao = $this->noPublicador($this->produtoDoPortal('A'));
        $this->pedir($jaNaRevisao);
        $this->rodar(GerarPreparoIaJob::class);
        app(PreparadorDaAprovacao::class)->encaminharProntas();
        $novo = $this->noPublicador($this->produtoDoPortal('B'));
        $incompleto = $this->produtoDoPortal('C');
        $incompleto->variacoes()->first()->update(['custo' => null]);
        $pubIncompleto = $this->noPublicador($incompleto);

        $primeira = app(VarreduraDoAgente::class)->rodar();
        $segunda = app(VarreduraDoAgente::class)->rodar();

        $this->assertSame(2, $primeira['pedidos']);
        $this->assertSame(PubAgenteExecucao::ORIGEM_AUTOMATICA, PubAgenteExecucao::where('produto_id', $novo->id)->value('origem'));
        $this->assertSame(PubAgenteExecucao::BLOQUEADA, PubAgenteExecucao::where('produto_id', $pubIncompleto->id)->value('estado'));
        $this->assertSame(1, PubAgenteExecucao::where('produto_id', $jaNaRevisao->id)->count());
        $this->assertSame(0, $segunda['pedidos'], 'a segunda passada pediu de novo');
        $this->assertSame(3, PubAgenteExecucao::count());
    }

    public function test_varredura_ignora_loja_pausada_e_o_comando_roda(): void
    {
        $this->ligar();
        $this->noPublicador();
        app(ControleDoAgente::class)->pausar($this->conta(), $this->admin);

        $this->artisan('publicador:agente-varrer')->assertSuccessful();

        $this->assertSame(0, PubAgenteExecucao::count());
    }
}
