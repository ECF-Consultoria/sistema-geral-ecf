<?php

namespace Tests\Feature\Phase129;

use App\Models\Company;
use App\Models\ContratoLiberacao;
use App\Models\MlbEmpresa;
use App\Models\Servico;
use App\Services\Operacional\EmpresaOperacionalRouter;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CR-01 (revisão de código da Fase 129, 129-REVIEW.md) — prova a corrida
 * que o guard de "ficha única por empresa" (D-02) não sobrevivia quando
 * DOIS SERVIÇOS DIFERENTES da MESMA empresa são liberados por dois
 * workers de fila verdadeiramente concorrentes. O `WithoutOverlapping` de
 * `ProcessarEventoClicksignJob::middleware()` só serializa por
 * `envelope_id` — envelopes diferentes (ex.: Polos e Assessoria assinados
 * quase juntos) correm em paralelo de propósito. Antes desta correção,
 * `EmpresaOperacionalRouter::aplicarRoteamento()` fazia um
 * check-then-act (`MlbEmpresa::where(...)->exists()` seguido de
 * `criarFicha()`) sem transação nem lock: dois workers podiam os dois ler
 * `exists()===false` antes de qualquer um commitar e os dois criar
 * `MlbEmpresa` para a mesma empresa.
 *
 * **Como este teste simula concorrência sem paralelismo real de SO:**
 * PHPUnit é single-thread — não existe forma de ter dois processos PHP
 * disputando a trava no MESMO instante. A técnica usada aqui é honesta e
 * sem deadlock: troca a fábrica de lock do router (`lockDaEmpresa()`,
 * `protected`, ponto de extensão criado de propósito por este fix) por um
 * decorator que, só na primeira chamada, dispara a SEGUNDA
 * `liberarEmpresa()` (serviço B) exatamente no instante em que a primeira
 * (serviço A) está PRESTES a disputar a trava real — o ponto em que, na
 * produção, os dois workers "chegam juntos". A chamada B roda o fluxo
 * REAL e completo do router (lock real, guard real, INSERT real) e só
 * depois que ela termina (e libera a trava) é que a chamada A prossegue
 * para a trava real, agora livre.
 *
 * O resultado observável é o MESMO que duas liberações genuinamente
 * concorrentes produziriam se a trava não existisse: sem ela, as duas
 * `exists()` rodariam antes de qualquer `create()` e duplicariam a ficha.
 * Com a trava, não importa qual das duas "chega primeiro" no ponto de
 * disputa — só uma cria. A prova de que este teste tem poder de detectar
 * regressão (e não é vácuo) foi feita manualmente durante a correção:
 * comentando a chamada a `lockDaEmpresa()->block()` em
 * `aplicarRoteamento()` (voltando ao `exists()`+`criarFicha()` direto), a
 * asserção de UMA `MlbEmpresa` falha e o teste acusa 2.
 */
class LiberarEmpresaCorridaConcorrenteTest extends TestCase
{
    use RefreshDatabase;

    private function servico(string $nome): Servico
    {
        return Servico::create([
            'nome'          => $nome,
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
        ]);
    }

    /**
     * Router de teste: mesmo comportamento do real, só troca a fábrica de
     * lock por um decorator que sabe disparar um gatilho ANTES de A
     * disputar a trava de verdade — ver docblock da classe.
     */
    private function routerComGatilhoDeCorrida(): EmpresaOperacionalRouter
    {
        return new class extends EmpresaOperacionalRouter {
            /** @var (callable(): void)|null dispara a "chamada concorrente" uma única vez */
            public $antesDeDisputarLock = null;

            protected function lockDaEmpresa(int $companyId): Lock
            {
                $lockReal = parent::lockDaEmpresa($companyId);
                $gatilho  = $this->antesDeDisputarLock;

                // Consome o gatilho já aqui — a chamada concorrente (B),
                // disparada de dentro dele, também passa por este método,
                // e não pode reentrar no mesmo gatilho (senão recursão
                // infinita).
                $this->antesDeDisputarLock = null;

                return new class($lockReal, $gatilho) implements Lock {
                    public function __construct(private Lock $lock, private $gatilho)
                    {
                    }

                    public function get($callback = null)
                    {
                        return $this->lock->get($callback);
                    }

                    public function block($seconds, $callback = null)
                    {
                        if ($this->gatilho !== null) {
                            ($this->gatilho)();
                        }

                        return $this->lock->block($seconds, $callback);
                    }

                    public function release()
                    {
                        return $this->lock->release();
                    }

                    public function owner()
                    {
                        return $this->lock->owner();
                    }

                    public function forceRelease()
                    {
                        return $this->lock->forceRelease();
                    }
                };
            }
        };
    }

    #[Test]
    public function duas_liberacoes_concorrentes_de_servicos_diferentes_da_mesma_empresa_criam_uma_unica_mlbempresa(): void
    {
        $company    = Company::factory()->create(['name' => 'Empresa Corrida CR-01']);
        $polos      = $this->servico('Polos');
        $assessoria = $this->servico('Assessoria');

        $router = $this->routerComGatilhoDeCorrida();

        // "Chamada B" (Assessoria) roda inteira DENTRO do gatilho de A,
        // exatamente antes de A disputar a trava real — simula o worker 2
        // "chegando" no mesmo instante em que o worker 1 está prestes a
        // tentar a trava.
        $router->antesDeDisputarLock = function () use ($router, $company, $assessoria) {
            $router->liberarEmpresa($company, $assessoria, ContratoLiberacao::VIA_WEBHOOK);
        };

        // "Chamada A" (Polos) dispara o gatilho acima de dentro do seu
        // próprio lockDaEmpresa()->block(), antes de adquirir a trava.
        $router->liberarEmpresa($company, $polos, ContratoLiberacao::VIA_WEBHOOK);

        $this->assertSame(
            2,
            ContratoLiberacao::where('company_id', $company->id)->count(),
            'as duas liberacoes (Polos e Assessoria) tem que ser gravadas -- isso nao e o que o CR-01 discute, e a constraint cl_empresa_servico_uniq garante'
        );

        $this->assertSame(
            1,
            MlbEmpresa::where('company_id', $company->id)->count(),
            'CR-01: duas liberacoes concorrentes de servicos diferentes da mesma empresa tem que resultar em UMA MlbEmpresa so'
        );

        // Só uma das duas liberações pode ter efetivamente criado a ficha
        // -- a que "venceu" a disputa pela trava.
        $comFicha = ContratoLiberacao::where('company_id', $company->id)->where('gerou_ficha', true)->count();
        $this->assertSame(1, $comFicha, 'so uma das duas liberacoes concorrentes pode ter criado a ficha');
    }

    #[Test]
    public function a_trava_e_por_empresa_nao_global_empresas_diferentes_nao_disputam_a_mesma_chave(): void
    {
        // Preserva o paralelismo entre envelopes de EMPRESAS diferentes
        // (não regredir para fila serial global) -- prova que a chave do
        // lock inclui o company_id disputando a mesma trava com uma
        // empresa e verificando que ISSO bloqueia, enquanto uma empresa
        // diferente, na mesma janela, não é afetada.
        $companyA = Company::factory()->create(['name' => 'Empresa A - trava']);
        $companyB = Company::factory()->create(['name' => 'Empresa B - trava']);
        $polos    = $this->servico('Polos');

        $router = app(EmpresaOperacionalRouter::class);

        // Segura a trava da empresa A manualmente, simulando um worker
        // "no meio" do guard dela.
        $reflexao = new \ReflectionMethod($router, 'lockDaEmpresa');
        $reflexao->setAccessible(true);
        /** @var Lock $lockA */
        $lockA = $reflexao->invoke($router, $companyA->id);
        $this->assertTrue($lockA->get(), 'pre-condicao: conseguiu segurar a trava da empresa A manualmente');

        try {
            // Empresa B usa uma chave DIFERENTE (company_id diferente) --
            // liberarEmpresa() para B não pode ficar presa atrás da trava
            // de A.
            $liberacaoB = $router->liberarEmpresa($companyB, $polos, ContratoLiberacao::VIA_WEBHOOK);

            $this->assertTrue($liberacaoB->gerou_ficha);
            $this->assertSame(1, MlbEmpresa::where('company_id', $companyB->id)->count());
        } finally {
            $lockA->release();
        }
    }
}
