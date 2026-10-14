<?php

namespace Tests\Feature\Bling;

use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use App\Models\EstruturaOferta;
use App\Models\User;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use App\Services\Portal\Estrutura\RegistroEstrutura;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 184-03 (BLING-21, BLING-22): o terceiro nascimento do ator
 * (`AtorDoPortal::sistema()`) e os DOIS caminhos até `PreparoIaAgenda::aoSalvar`
 * fechados por `agendarPreparo: false` — provados aqui porque uma varredura do
 * Bling não é nem cliente nem equipe, e não pode enfileirar centenas de cadeias
 * de IA na fila que publica anúncio e manda o código de acesso do Portal
 * (`184-RESEARCH.md` §3.4/3.5; `publicador-ml.md` §22).
 */
class EscritaSemPreparoIaTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private function linha(string $codigo, ?string $grupo = null): array
    {
        return [
            'grupo'   => $grupo ?? 'G'.$codigo,
            'codigo'  => $codigo,
            'nome'    => 'Produto '.$codigo,
            'eixo'    => 'cor',
            'valor'   => 'Azul',
            'volumes' => [['c' => 50, 'l' => 40, 'a' => 10, 'kg' => 5]],
        ];
    }

    // ═══ Task 1 — o terceiro nascimento do ator ═════════════════════════════

    public function test_ator_sistema_nasce_sem_causer_e_com_origem_bling(): void
    {
        $ator = AtorDoPortal::sistema();

        $this->assertSame('bling', $ator->origem);
        $this->assertFalse($ator->equipe);
        $this->assertNull($ator->modelo);
        $this->assertNotSame('', trim($ator->nome));
    }

    public function test_ator_cliente_continua_igual(): void
    {
        $empresa = $this->empresaDoGabarito();
        $usuario = $this->clienteDoPortal($empresa);

        $ator = AtorDoPortal::cliente($usuario);

        $this->assertSame('cliente', $ator->origem);
        $this->assertFalse($ator->equipe);
        $this->assertNotNull($ator->modelo);
    }

    public function test_ator_da_equipe_continua_igual(): void
    {
        $membro = User::factory()->create();

        $ator = AtorDoPortal::daEquipe($membro);

        $this->assertSame('interno', $ator->origem);
        $this->assertTrue($ator->equipe);
        $this->assertNotNull($ator->modelo);
    }

    public function test_registro_com_ator_sistema_grava_sem_causer(): void
    {
        $empresa = $this->empresaDoGabarito();

        RegistroEstrutura::registrar(AtorDoPortal::sistema(), $empresa, null, 'produtos_gravados', 'teste do ator de sistema');

        $log = Activity::where('log_name', 'portal')->where('properties->evento', 'produtos_gravados')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('bling', $log->getExtraProperty('origem'));
        // Asserção negativa: carimbar um funcionário aqui seria uma ação que nenhum humano fez.
        $this->assertNull($log->causer_id);
    }

    public function test_registro_com_ator_de_equipe_continua_com_causer(): void
    {
        $empresa = $this->empresaDoGabarito();
        $membro = User::factory()->create();

        RegistroEstrutura::registrar(AtorDoPortal::daEquipe($membro), $empresa, null, 'produtos_gravados', 'teste do ator de equipe');

        $log = Activity::where('log_name', 'portal')->where('properties->evento', 'produtos_gravados')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('interno', $log->getExtraProperty('origem'));
        $this->assertSame($membro->id, $log->causer_id);
    }

    // ═══ Task 2 — os DOIS caminhos até PreparoIaAgenda::aoSalvar ════════════

    public function test_gravar_linhas_com_agendarpreparo_false_nao_enfileira_nada(): void
    {
        Queue::fake();
        $empresa = $this->empresaDoGabarito();

        app(ProdutoCadastroService::class)->gravarLinhas(
            $empresa,
            [$this->linha('BL-1'), $this->linha('BL-2')],
            AtorDoPortal::sistema(),
            ProdutoCadastroService::MODO_IMPORTACAO,
            agendarPreparo: false,
        );

        // Asserção negativa: nem o caminho direto nem o da oferta (criarOferta →
        // EstruturaOfertaService::criar → prepararNoPublicador → DB::afterCommit)
        // podem ter despachado nada.
        Queue::assertNothingPushed();
        $this->assertSame(2, EstruturaOferta::where('company_id', $empresa->id)->whereNotNull('variacao_id')->count());
    }

    public function test_gravar_linhas_com_agendarpreparo_true_enfileira(): void
    {
        // Par positivo do teste anterior: sem ele, o `assertNothingPushed` de cima
        // passaria mesmo que a fila estivesse desligada por outro motivo (gate vazio
        // por construção).
        Queue::fake();
        $empresa = $this->empresaDoGabarito();

        app(ProdutoCadastroService::class)->gravarLinhas(
            $empresa,
            [$this->linha('BL-3'), $this->linha('BL-4')],
            AtorDoPortal::sistema(),
            ProdutoCadastroService::MODO_IMPORTACAO,
            agendarPreparo: true,
        );

        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class);
    }

    public function test_gravar_linhas_sem_o_parametro_novo_continua_agendando(): void
    {
        // A grade e a planilha não passam `agendarPreparo`: o padrão continua `true`.
        Queue::fake();
        $empresa = $this->empresaDoGabarito();

        app(ProdutoCadastroService::class)->gravarLinhas(
            $empresa,
            [$this->linha('BL-5')],
            $this->atorCliente($empresa),
        );

        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class);
    }

    public function test_com_agendarpreparo_false_o_registro_continua_com_origem_bling_e_sem_causer(): void
    {
        Queue::fake();
        $empresa = $this->empresaDoGabarito();

        app(ProdutoCadastroService::class)->gravarLinhas(
            $empresa,
            [$this->linha('BL-6')],
            AtorDoPortal::sistema(),
            ProdutoCadastroService::MODO_IMPORTACAO,
            agendarPreparo: false,
        );

        $log = Activity::where('log_name', 'portal')->where('properties->evento', 'produtos_gravados')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('bling', $log->getExtraProperty('origem'));
        $this->assertNull($log->causer_id);
    }

    public function test_com_agendarpreparo_false_a_oferta_ligada_continua_nascendo(): void
    {
        // Desligar o preparo não pode desligar a oferta ligada (protege sku/nome/fase).
        Queue::fake();
        $empresa = $this->empresaDoGabarito();

        app(ProdutoCadastroService::class)->gravarLinhas(
            $empresa,
            [$this->linha('BL-7'), $this->linha('BL-8')],
            AtorDoPortal::sistema(),
            ProdutoCadastroService::MODO_IMPORTACAO,
            agendarPreparo: false,
        );

        $this->assertSame(2, EstruturaOferta::where('company_id', $empresa->id)->whereIn('sku', ['BL-7', 'BL-8'])->count());
    }
}
