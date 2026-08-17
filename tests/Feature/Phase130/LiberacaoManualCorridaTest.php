<?php

namespace Tests\Feature\Phase130;

use App\Models\Company;
use App\Models\ContratoLiberacao;
use App\Models\MlbEmpresa;
use App\Models\Servico;
use App\Models\User;
use App\Services\Operacional\EmpresaOperacionalRouter;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 130 Plano 04 (SC4) — PROVA, não reimplementação.
 *
 * O SC4 desta fase ("liberação manual concorrente com o webhook não cria
 * `MlbEmpresa` duplicada") já estava resolvido pela Fase 129 (CR-01, commit
 * `f50e123c`): `EmpresaOperacionalRouter::lockDaEmpresa()`
 * (`Cache::lock('operacional-guarda-empresa-{id}')`, TTL 10s) é herdada
 * automaticamente por QUALQUER chamador de `liberarEmpresa()` — inclusive o
 * `ContratoLiberacaoManualController` deste plano e o
 * `ReconciliarContratoClicksignJob` do plano 130-03. Este arquivo não define
 * nenhum `Cache::lock(` próprio: ele só decora `lockDaEmpresa()` (mesma
 * técnica de `tests/Feature/Phase129/LiberarEmpresaCorridaConcorrenteTest.php`,
 * adaptada aqui) para provar que a trava cobre também os pares
 * manual×webhook e manual×reconciliação, não só webhook×webhook.
 *
 * `CACHE_STORE=array` (`phpunit.xml`) implementa `Illuminate\Contracts\Cache\
 * LockProvider` e é suficiente para `Cache::lock()` funcionar nos testes —
 * não trocar o driver de cache da suíte.
 */
class LiberacaoManualCorridaTest extends TestCase
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
     * lock por um decorator que dispara um gatilho ANTES de disputar a
     * trava real — ver docblock de `LiberarEmpresaCorridaConcorrenteTest`
     * (Fase 129) para a explicação completa da técnica.
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
    public function sc4_liberacao_manual_concorrente_com_webhook_em_servicos_diferentes_cria_uma_unica_mlbempresa(): void
    {
        $company    = Company::factory()->create(['name' => 'Empresa Corrida Manual x Webhook']);
        $polos      = $this->servico('Polos (SC4-1)');
        $assessoria = $this->servico('Assessoria (SC4-1)');
        $admin      = User::factory()->create(['role' => 'admin']);

        $router = $this->routerComGatilhoDeCorrida();

        // "De dentro" (Assessoria) é o webhook automático da Fase 129.
        $router->antesDeDisputarLock = function () use ($router, $company, $assessoria) {
            $router->liberarEmpresa($company, $assessoria, ContratoLiberacao::VIA_WEBHOOK);
        };

        // "De fora" (Polos) é a liberação manual desta fase — com autor e motivo.
        $router->liberarEmpresa(
            $company,
            $polos,
            ContratoLiberacao::VIA_MANUAL,
            liberadoPorUserId: $admin->id,
            motivo: 'Teste de corrida SC4',
            motivoSlug: ContratoLiberacao::MOTIVO_OUTRO,
        );

        $this->assertSame(
            1,
            MlbEmpresa::where('company_id', $company->id)->count(),
            'SC4: liberacao manual concorrente com webhook em servicos diferentes da mesma empresa tem que resultar em UMA MlbEmpresa so'
        );
    }

    #[Test]
    public function webhook_e_manual_concorrentes_no_mesmo_servico_nao_duplicam_contrato_liberacao(): void
    {
        $company = Company::factory()->create(['name' => 'Empresa Corrida Mesmo Servico']);
        $polos   = $this->servico('Polos (SC4-2)');
        $admin   = User::factory()->create(['role' => 'admin']);

        $router = $this->routerComGatilhoDeCorrida();

        // A corrida real da D-11: o webhook e a liberação manual competindo
        // pelo MESMO par (empresa, serviço) — a garantia final é o índice
        // único `cl_empresa_servico_uniq`, não este decorator.
        $router->antesDeDisputarLock = function () use ($router, $company, $polos) {
            $router->liberarEmpresa($company, $polos, ContratoLiberacao::VIA_WEBHOOK);
        };

        $router->liberarEmpresa(
            $company,
            $polos,
            ContratoLiberacao::VIA_MANUAL,
            liberadoPorUserId: $admin->id,
            motivo: 'Teste de corrida mesmo servico',
            motivoSlug: ContratoLiberacao::MOTIVO_OUTRO,
        );

        $this->assertSame(
            1,
            ContratoLiberacao::where('company_id', $company->id)->where('servico_id', $polos->id)->count(),
            'a constraint cl_empresa_servico_uniq garante uma unica ContratoLiberacao mesmo com via=webhook e via=manual disputando o mesmo par'
        );
    }

    #[Test]
    public function reconciliacao_e_manual_concorrentes_em_servicos_diferentes_criam_uma_unica_mlbempresa(): void
    {
        $company = Company::factory()->create(['name' => 'Empresa Corrida Reconciliacao x Manual']);
        $polos      = $this->servico('Polos (SC4-3)');
        $incubadora = $this->servico('Incubadora (SC4-3)');
        $admin      = User::factory()->create(['role' => 'admin']);

        $router = $this->routerComGatilhoDeCorrida();

        // "De dentro" (Incubadora) é a varredura de reconciliação do plano 130-03.
        $router->antesDeDisputarLock = function () use ($router, $company, $incubadora) {
            $router->liberarEmpresa($company, $incubadora, ContratoLiberacao::VIA_RECONCILIACAO);
        };

        // "De fora" (Polos) é a liberação manual deste plano.
        $router->liberarEmpresa(
            $company,
            $polos,
            ContratoLiberacao::VIA_MANUAL,
            liberadoPorUserId: $admin->id,
            motivo: 'Teste de corrida reconciliacao x manual',
            motivoSlug: ContratoLiberacao::MOTIVO_OUTRO,
        );

        $this->assertSame(
            1,
            MlbEmpresa::where('company_id', $company->id)->count(),
            'reconciliacao concorrente com liberacao manual em servicos diferentes da mesma empresa tem que resultar em UMA MlbEmpresa so'
        );
    }
}
