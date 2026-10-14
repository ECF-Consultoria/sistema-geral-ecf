<?php

namespace Tests\Feature\Bling;

use App\Jobs\LerProdutosDoBlingJob;
use App\Models\Company;
use App\Models\EstruturaProdutoVariacao;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 184-09 (BLING-25, BLING-26) — a varredura de estoque do Bling: o método dedicado de
 * gravação (`ProdutoCadastroService::gravarEstoqueDoBling`, Task 1), o serviço que lê os
 * saldos em blocos de ids (`EstoqueDoBlingService`, Task 2) e o comando agendado
 * (`bling:estoque`, Task 3).
 */
class EstoqueDoBlingTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private function criarVariacao(Company $empresa, string $codigo, array $extra = []): EstruturaProdutoVariacao
    {
        $linha = array_merge([
            'grupo'   => 'G'.$codigo,
            'codigo'  => $codigo,
            'nome'    => 'Produto '.$codigo,
            'eixo'    => 'cor',
            'valor'   => 'Azul',
            'custo'   => 10.5,
            'volumes' => [['c' => 50, 'l' => 40, 'a' => 10, 'kg' => 5]],
        ], $extra);

        $res = app(ProdutoCadastroService::class)->gravarLinhas($empresa, [$linha], $this->atorCliente($empresa));

        return EstruturaProdutoVariacao::find($res['linhas'][0]['id']);
    }

    private function cadastro(): ProdutoCadastroService
    {
        return app(ProdutoCadastroService::class);
    }

    // ═══ Task 1 — gravarEstoqueDoBling ═══════════════════════════════════════

    public function test_grava_estoque_na_variacao_informada(): void
    {
        $empresa = $this->empresaDoGabarito();
        $variacao = $this->criarVariacao($empresa, 'EST-1');

        $totais = $this->cadastro()->gravarEstoqueDoBling($empresa, [$variacao->id => 7], AtorDoPortal::sistema());

        $this->assertSame(['gravadas' => 1, 'sem_mudanca' => 0, 'ignoradas' => 0], $totais);
        $this->assertSame(7, $variacao->refresh()->estoque);
    }

    public function test_gravar_estoque_nao_enfileira_nada(): void
    {
        $empresa = $this->empresaDoGabarito();
        $variacao = $this->criarVariacao($empresa, 'EST-2');

        // O Queue::fake() entra SÓ depois da preparação (criarVariacao chama gravarLinhas com
        // agendarPreparo padrão true) — senão os Jobs da preparação, não de gravarEstoqueDoBling,
        // fariam este teste falhar por um motivo que não é o dele.
        Queue::fake();
        $this->cadastro()->gravarEstoqueDoBling($empresa, [$variacao->id => 3], AtorDoPortal::sistema());

        // Asserção negativa (risco 5 do seed): nenhuma cadeia de preparo, nenhum Job.
        Queue::assertNothingPushed();
    }

    public function test_par_positivo_o_queue_fake_captura_quando_ha_algo_a_capturar(): void
    {
        // Sem este par, o assertNothingPushed do teste anterior seria um gate vazio por
        // construção: passaria mesmo com o corpo de gravarEstoqueDoBling apagado, ou com o
        // Queue::fake() desligado por outro motivo. Aqui provamos que o MESMO mecanismo
        // (Queue::fake(), nesta MESMA suíte) realmente captura um Job qualquer quando ele é
        // despachado de propósito — este Job não tem nenhuma relação com gravarEstoqueDoBling,
        // só prova que a medição funciona.
        Queue::fake();

        LerProdutosDoBlingJob::dispatch(999999, 'par-positivo-184-09');

        Queue::assertPushed(LerProdutosDoBlingJob::class);
    }

    public function test_nenhuma_outra_coluna_da_variacao_muda(): void
    {
        $empresa = $this->empresaDoGabarito();
        $variacao = $this->criarVariacao($empresa, 'EST-3');
        $antes = $variacao->refresh()->only(['nome', 'codigo', 'custo', 'valor', 'ordem', 'eixo']);
        $volumesAntes = $variacao->volumes()->get()
            ->map(fn ($v) => [(float) $v->comprimento, (float) $v->largura, (float) $v->altura, (float) $v->peso])->all();

        $this->cadastro()->gravarEstoqueDoBling($empresa, [$variacao->id => 9], AtorDoPortal::sistema());

        $depois = $variacao->refresh()->only(['nome', 'codigo', 'custo', 'valor', 'ordem', 'eixo']);
        $volumesDepois = $variacao->volumes()->get()
            ->map(fn ($v) => [(float) $v->comprimento, (float) $v->largura, (float) $v->altura, (float) $v->peso])->all();

        $this->assertSame($antes, $depois);
        $this->assertSame($volumesAntes, $volumesDepois);
        $this->assertSame(9, $variacao->estoque);
    }

    public function test_null_nao_apaga_e_zero_grava_zero(): void
    {
        $empresa = $this->empresaDoGabarito();
        $variacao = $this->criarVariacao($empresa, 'EST-4');
        $this->cadastro()->gravarEstoqueDoBling($empresa, [$variacao->id => 15], AtorDoPortal::sistema());
        $this->assertSame(15, $variacao->refresh()->estoque);

        // null = "o Bling não informou": não apaga o que já havia.
        $totaisNull = $this->cadastro()->gravarEstoqueDoBling($empresa, [$variacao->id => null], AtorDoPortal::sistema());
        $this->assertSame(15, $variacao->refresh()->estoque);
        $this->assertSame(['gravadas' => 0, 'sem_mudanca' => 0, 'ignoradas' => 1], $totaisNull);

        // 0 é "sem estoque" — grava; não é a mesma coisa que null, mesmo vindo de um estoque
        // anterior que nunca foi gravado (null não vira "0 por padrão").
        $totaisZero = $this->cadastro()->gravarEstoqueDoBling($empresa, [$variacao->id => 0], AtorDoPortal::sistema());
        $this->assertSame(0, $variacao->refresh()->estoque);
        $this->assertSame(1, $totaisZero['gravadas']);
    }

    public function test_variacao_de_outra_empresa_e_ignorada(): void
    {
        $empresaA = $this->empresaDoGabarito();
        $empresaB = $this->empresaDoGabarito();
        $variacaoDeB = $this->criarVariacao($empresaB, 'EST-5');

        $totais = $this->cadastro()->gravarEstoqueDoBling($empresaA, [$variacaoDeB->id => 50], AtorDoPortal::sistema());

        $this->assertSame(['gravadas' => 0, 'sem_mudanca' => 0, 'ignoradas' => 0], $totais);
        $this->assertNull($variacaoDeB->refresh()->estoque);
    }

    public function test_activity_log_com_origem_bling_e_sem_causer(): void
    {
        $empresa = $this->empresaDoGabarito();
        $variacao = $this->criarVariacao($empresa, 'EST-6');

        $this->cadastro()->gravarEstoqueDoBling($empresa, [$variacao->id => 4], AtorDoPortal::sistema());

        $log = Activity::where('log_name', 'portal')->where('properties->evento', 'estoque_do_bling')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('bling', $log->getExtraProperty('origem'));
        $this->assertNull($log->causer_id);
        $this->assertNotSame('produtos_gravados', $log->getExtraProperty('evento'));
    }

    public function test_retorno_bate_com_reconsulta_ao_banco(): void
    {
        $empresa = $this->empresaDoGabarito();
        $v1 = $this->criarVariacao($empresa, 'EST-7A');
        $v2 = $this->criarVariacao($empresa, 'EST-7B');
        $v3 = $this->criarVariacao($empresa, 'EST-7C');

        // v2 já começa com o mesmo valor que será pedido de novo (para cair em sem_mudanca).
        $this->cadastro()->gravarEstoqueDoBling($empresa, [$v2->id => 5], AtorDoPortal::sistema());

        $totais = $this->cadastro()->gravarEstoqueDoBling($empresa, [
            $v1->id => 10,
            $v2->id => 5,
            $v3->id => null,
        ], AtorDoPortal::sistema());

        $this->assertSame(['gravadas' => 1, 'sem_mudanca' => 1, 'ignoradas' => 1], $totais);
        // Conferência por RECONSULTA ao banco, nunca pelo retorno em si.
        $this->assertSame(10, $v1->refresh()->estoque);
        $this->assertSame(5, $v2->refresh()->estoque);
        $this->assertNull($v3->refresh()->estoque);
    }
}
