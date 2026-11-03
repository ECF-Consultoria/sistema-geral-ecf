<?php

namespace Tests\Feature\Trilha;

use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use App\Jobs\Publicador\SincronizarProdutoDoPortalJob;
use App\Models\Company;
use App\Models\PubProduto;
use App\Models\TrilhaOnda;
use App\Models\User;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use App\Services\Trilha\CadastroNaTrilha;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * Revisão da F1 da 3ª entrega (03/11/2026): na empresa da trilha de trabalho, os caminhos de save do PORTAL também só
 * levam ao Publicador o produto completo ou o que ele já conhece (`CadastroNaTrilha::soOsQueCabem`). Antes, a gaveta da
 * F1 mandava a equipe ao Portal para categoria e fotos, e cada save ali agendava o Publicador com o produto incompleto —
 * o rascunho ficava preso na 1ª categoria e nas 1ªs fotos. Fora da chave, nada muda.
 */
class PortalNaLojaDaTrilhaTest extends TestCase
{
    use MontaTrilha;
    use RefreshDatabase;

    private User $dev;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', TrilhaOnda::FUSO));
        config(['trilha.empresas' => [], 'trilha.trabalho.empresas' => [], 'publicador.preparo_ia.ativo' => true]);
        $this->dev = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->dev->forceFill(['is_dev' => true])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_na_loja_da_trilha_o_save_do_portal_nao_leva_o_produto_incompleto_ao_publicador(): void
    {
        $empresa = $this->empresaDaTrilha();
        $p = $this->produtoCompleto($empresa, 'Vaso Alfa', 'VA-1', [null], ['descricao' => '']);

        $this->salvarCustoPeloPortal($empresa, $p, '150,00');

        Queue::assertNotPushed(SincronizarProdutoDoPortalJob::class);
        Queue::assertNotPushed(PrepararProdutoNoPublicadorJob::class);
    }

    public function test_completo_ou_ja_no_publicador_vai_como_sempre(): void
    {
        $empresa = $this->empresaDaTrilha();
        $completo = $this->produtoCompleto($empresa, 'Vaso Alfa', 'VA-1');
        $incompletoConhecido = $this->produtoCompleto($empresa, 'Vaso Beta', 'VB-1', [null], ['descricao' => '']);
        $this->grupoNoPublicador($empresa, $incompletoConhecido);

        $this->salvarCustoPeloPortal($empresa, $completo, '150,00');
        $this->salvarCustoPeloPortal($empresa, $incompletoConhecido, '160,00');

        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->estruturaProdutoId === (int) $completo['produto']->id);
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->estruturaProdutoId === (int) $incompletoConhecido['produto']->id);
        $this->assertSame([(int) $completo['produto']->id],
            CadastroNaTrilha::soOsQueCabem((int) $empresa->id, [(int) $completo['produto']->id]));
    }

    public function test_fora_da_chave_o_portal_segue_levando_o_incompleto(): void
    {
        $soLeitura = $this->empresaDaTrilha(trabalho: false);
        $p = $this->produtoCompleto($soLeitura, 'Vaso Leitura', 'VL-1', [null], ['descricao' => '']);
        $semTrilha = Company::create(['name' => 'Loja comum', 'cnpj' => '12345678000199', 'active' => true, 'status' => 'ativo', 'empresa_nova' => false]);
        $q = $this->produtoCompleto($semTrilha, 'Vaso Comum', 'VC-1', [null], ['descricao' => '']);

        $this->salvarCustoPeloPortal($soLeitura, $p, '150,00');
        $this->salvarCustoPeloPortal($semTrilha, $q, '150,00');

        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, 2);
        $this->assertSame(0, PubProduto::query()->count());
    }

    // ─── apoio ───

    /** Um save da grade de Produtos do Portal (o mesmo serviço; agenda o Publicador como o Portal agenda). */
    private function salvarCustoPeloPortal(Company $empresa, array $p, string $custo): void
    {
        $v = $p['variacoes'][0];
        $r = app(ProdutoCadastroService::class)->gravarLinhas($empresa, [[
            'chave' => 'v'.$v->id, 'id' => $v->id, 'codigo' => $v->codigo, 'nome' => $p['produto']->nome, 'custo' => $custo,
        ]], AtorDoPortal::daEquipe($this->dev));
        $this->assertSame([], $r['erros'], json_encode($r['erros']));
    }
}
