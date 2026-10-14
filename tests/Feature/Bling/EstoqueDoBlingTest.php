<?php

namespace Tests\Feature\Bling;

use App\Jobs\LerProdutosDoBlingJob;
use App\Models\BlingConexao;
use App\Models\BlingVinculo;
use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Services\Bling\EstoqueDoBlingService;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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

    // ═══ Task 2 — EstoqueDoBlingService::varrer ═════════════════════════════

    private function conexaoAtiva(Company $empresa, array $extra = []): BlingConexao
    {
        return BlingConexao::create(array_merge([
            'company_id'    => $empresa->id,
            'access_token'  => 'access-de-teste',
            'refresh_token' => 'refresh-de-teste',
            'status'        => BlingConexao::STATUS_ATIVA,
            'expires_at'    => now()->addHours(6),
            'estoque_ativo' => true,
        ], $extra));
    }

    private function vincular(Company $empresa, EstruturaProdutoVariacao $variacao, string $blingProdutoId, array $extra = []): BlingVinculo
    {
        return BlingVinculo::create(array_merge([
            'company_id'       => $empresa->id,
            'bling_produto_id' => $blingProdutoId,
            'variacao_id'      => $variacao->id,
            'formato'          => BlingVinculo::FORMATO_SIMPLES,
            'situacao'         => BlingVinculo::SITUACAO_VINCULADO,
        ], $extra));
    }

    /** N variações de um único produto (bulk), sem passar por `gravarLinhas` — só para os testes de volume do Task 2. */
    private function criarVariacoesEmMassa(Company $empresa, int $quantidade, string $prefixo): array
    {
        $produto = EstruturaProduto::create(['company_id' => $empresa->id, 'nome' => "Produto em massa {$prefixo}"]);
        $variacoes = [];
        for ($i = 1; $i <= $quantidade; $i++) {
            $variacoes[] = EstruturaProdutoVariacao::create([
                'produto_id' => $produto->id, 'company_id' => $empresa->id, 'ordem' => $i, 'codigo' => "{$prefixo}-{$i}",
            ]);
        }

        return $variacoes;
    }

    /**
     * Fake dos dois endpoints usados pela varredura (`/depositos` e `/estoques/saldos`).
     * `$saldoPorIdDoBling[$id] = inteiro` grava a presença; ausente da lista = o Bling não
     * devolveu aquele produto (asserção de ausência ≠ zero). `$erroNoBloco` força um 429 na
     * N-ésima chamada a `/estoques/saldos` (1-based).
     */
    /**
     * `Http::fake($closure)` ACUMULA closures (merge), nunca substitui — por isso este helper
     * só pode ser chamado UMA VEZ por teste. Um 2º `Http::fake()` no mesmo teste deixaria os
     * dois fakes registrados, e o primeiro continuaria "ganhando" (resolução por ordem de
     * registro) para qualquer id que ele não reconheça — e `Http::fake()` SEM argumento não
     * é um reset: ele empilha mais um closure que responde 200 vazio a QUALQUER requisição,
     * o que faria esse closure genérico "ganhar" de todos os outros. Testes que precisam de
     * duas varreduras com respostas diferentes usam duas conexões em métodos de teste
     * SEPARADOS, nunca duas chamadas a este helper no mesmo método.
     */
    private function fakeBling(array $saldoPorIdDoBling, array $depositos = [], ?int $erroNoBloco = null): void
    {
        $chamadaSaldos = 0;

        Http::fake(function (Request $request) use ($saldoPorIdDoBling, $depositos, $erroNoBloco, &$chamadaSaldos) {
            $url = $request->url();

            if (str_contains($url, '/depositos')) {
                return Http::response(['data' => $depositos], 200);
            }

            if (str_contains($url, '/estoques/saldos')) {
                $chamadaSaldos++;

                if ($erroNoBloco !== null && $chamadaSaldos === $erroNoBloco) {
                    return Http::response(['error' => ['type' => 'TOO_MANY_REQUESTS', 'period' => '1 minuto']], 429);
                }

                $query = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
                parse_str($query, $parsed);
                $idsPedidos = array_map('strval', (array) ($parsed['idsProdutos'] ?? []));

                $data = [];
                foreach ($idsPedidos as $id) {
                    if (array_key_exists($id, $saldoPorIdDoBling)) {
                        $data[] = [
                            'produto'          => ['id' => $id],
                            'saldoFisicoTotal' => $saldoPorIdDoBling[$id],
                            'saldoVirtualTotal' => $saldoPorIdDoBling[$id],
                            'depositos'        => [],
                        ];
                    }
                }

                return Http::response(['data' => $data], 200);
            }

            return Http::response(['data' => []], 404);
        });
    }

    private function servico(): EstoqueDoBlingService
    {
        return app(EstoqueDoBlingService::class);
    }

    public function test_varrer_grava_os_tres_estoques_e_atualiza_saldo_e_data_lida(): void
    {
        $empresa = $this->empresaDoGabarito();
        $conexao = $this->conexaoAtiva($empresa);
        $v1 = $this->criarVariacao($empresa, 'VAR-1');
        $v2 = $this->criarVariacao($empresa, 'VAR-2');
        $v3 = $this->criarVariacao($empresa, 'VAR-3');
        $vinc1 = $this->vincular($empresa, $v1, '1001');
        $this->vincular($empresa, $v2, '1002');
        $this->vincular($empresa, $v3, '1003');

        $this->fakeBling(['1001' => 10, '1002' => 0, '1003' => 5]);

        $res = $this->servico()->varrer($conexao);

        $this->assertSame(3, $res['gravadas']);
        $this->assertNull($res['erro']);
        $this->assertSame(10, $v1->refresh()->estoque);
        $this->assertSame(0, $v2->refresh()->estoque);
        $this->assertSame(5, $v3->refresh()->estoque);
        $this->assertSame(10, $vinc1->refresh()->saldo);
        $this->assertNotNull($vinc1->refresh()->saldo_lido_em);
    }

    public function test_cento_e_vinte_vinculos_fazem_exatamente_tres_chamadas_de_saldos(): void
    {
        $empresa = $this->empresaDoGabarito();
        $conexao = $this->conexaoAtiva($empresa);
        $variacoes = $this->criarVariacoesEmMassa($empresa, 120, 'BULK');

        $saldoPorId = [];
        foreach ($variacoes as $i => $variacao) {
            $blingId = (string) (10000 + $i);
            $this->vincular($empresa, $variacao, $blingId);
            $saldoPorId[$blingId] = 1;
        }

        $this->fakeBling($saldoPorId);

        $this->servico()->varrer($conexao);

        Http::assertSentCount(4); // 1 /depositos + 3 blocos de 50/50/20
    }

    public function test_depositos_e_chamado_uma_unica_vez_por_varredura(): void
    {
        $empresa = $this->empresaDoGabarito();
        $conexao = $this->conexaoAtiva($empresa);
        $variacoes = $this->criarVariacoesEmMassa($empresa, 60, 'DEP');

        $saldoPorId = [];
        foreach ($variacoes as $i => $variacao) {
            $blingId = (string) (20000 + $i);
            $this->vincular($empresa, $variacao, $blingId);
            $saldoPorId[$blingId] = 2;
        }

        $this->fakeBling($saldoPorId);

        $this->servico()->varrer($conexao);

        Http::assertSentCount(3); // 1 /depositos + 2 blocos de 50/10 — nunca 1 /depositos por bloco
    }

    public function test_vinculo_removido_ou_ignorado_nao_entra_na_varredura(): void
    {
        $empresa = $this->empresaDoGabarito();
        $conexao = $this->conexaoAtiva($empresa);
        $vIgnorado = $this->criarVariacao($empresa, 'IGN-1');
        $vRemovido = $this->criarVariacao($empresa, 'REM-1');
        $this->vincular($empresa, $vIgnorado, '3001', ['situacao' => BlingVinculo::SITUACAO_IGNORADO]);
        $this->vincular($empresa, $vRemovido, '3002', ['situacao' => BlingVinculo::SITUACAO_REMOVIDO_NO_BLING]);

        $this->fakeBling(['3001' => 99, '3002' => 99]);

        $res = $this->servico()->varrer($conexao);

        // Asserção negativa: pedir saldo de produto que saiu do Bling gastaria cota à toa.
        $this->assertSame(0, $res['gravadas']);
        Http::assertSentCount(1); // só /depositos — nenhum bloco de saldos, porque não há vínculo elegível
    }

    public function test_conexao_com_estoque_desativado_nao_faz_nenhuma_requisicao(): void
    {
        $empresa = $this->empresaDoGabarito();
        $conexao = $this->conexaoAtiva($empresa, ['estoque_ativo' => false]);
        $variacao = $this->criarVariacao($empresa, 'OFF-1');
        $this->vincular($empresa, $variacao, '4001');

        $this->fakeBling(['4001' => 10]);

        $res = $this->servico()->varrer($conexao);

        $this->assertSame($this->resultadoVazio(), $res);
        Http::assertNothingSent();
    }

    public function test_conexao_nao_ativa_nao_faz_nenhuma_requisicao(): void
    {
        $empresa = $this->empresaDoGabarito();
        $conexao = $this->conexaoAtiva($empresa, ['status' => BlingConexao::STATUS_DIVERGENTE]);
        $variacao = $this->criarVariacao($empresa, 'DIV-1');
        $this->vincular($empresa, $variacao, '5001');

        $this->fakeBling(['5001' => 10]);

        $res = $this->servico()->varrer($conexao);

        $this->assertSame($this->resultadoVazio(), $res);
        Http::assertNothingSent();
    }

    private function resultadoVazio(): array
    {
        return ['gravadas' => 0, 'sem_mudanca' => 0, 'ignoradas' => 0, 'chamadas' => 0, 'erro' => null, 'avisos' => []];
    }

    public function test_produto_ausente_da_resposta_nao_zera_o_estoque_anterior(): void
    {
        $empresa = $this->empresaDoGabarito();
        $conexao = $this->conexaoAtiva($empresa);
        $variacao = $this->criarVariacao($empresa, 'AUS-1');
        $this->vincular($empresa, $variacao, '6001');

        // UM ÚNICO Http::fake(): a 1ª varredura devolve o saldo 8; a 2ª varredura (mesmo
        // closure, pelo contador de chamadas) já não devolve o produto 6001 — simula o Bling
        // parando de incluí-lo na resposta, sem precisar de um 2º Http::fake() no mesmo teste
        // (que acumularia closures — ver o docblock de `fakeBling`).
        $chamadaSaldos = 0;
        Http::fake(function (Request $request) use (&$chamadaSaldos) {
            $url = $request->url();
            if (str_contains($url, '/depositos')) {
                return Http::response(['data' => []], 200);
            }
            if (str_contains($url, '/estoques/saldos')) {
                $chamadaSaldos++;
                $data = $chamadaSaldos === 1
                    ? [['produto' => ['id' => '6001'], 'saldoFisicoTotal' => 8, 'saldoVirtualTotal' => 8, 'depositos' => []]]
                    : [];

                return Http::response(['data' => $data], 200);
            }

            return Http::response(['data' => []], 404);
        });

        // 1ª varredura: grava 8.
        $this->servico()->varrer($conexao);
        $this->assertSame(8, $variacao->refresh()->estoque);

        // 2ª varredura: o Bling não devolveu o produto 6001 na resposta.
        $this->servico()->varrer($conexao);

        // Asserção negativa: o estoque anterior PERMANECE — ausência não é zero.
        $this->assertSame(8, $variacao->refresh()->estoque);
    }

    public function test_429_no_segundo_bloco_mantem_o_primeiro_bloco_gravado_e_para_sem_novo_pedido(): void
    {
        $empresa = $this->empresaDoGabarito();
        $conexao = $this->conexaoAtiva($empresa);
        $variacoes = $this->criarVariacoesEmMassa($empresa, 60, 'ERR');

        $saldoPorId = [];
        foreach ($variacoes as $i => $variacao) {
            $blingId = (string) (70000 + $i);
            $this->vincular($empresa, $variacao, $blingId);
            $saldoPorId[$blingId] = 3;
        }

        // 429 na 2ª chamada a /estoques/saldos (o 2º bloco, dos 2 totais).
        $this->fakeBling($saldoPorId, erroNoBloco: 2);

        $res = $this->servico()->varrer($conexao);

        $this->assertNotNull($res['erro']);
        Http::assertSentCount(3); // /depositos + bloco 1 (sucesso) + bloco 2 (429) — nenhuma 4ª
        // Os primeiros 50 (bloco 1) continuam gravados.
        $this->assertSame(3, $variacoes[0]->refresh()->estoque);
        $this->assertSame(3, $variacoes[49]->refresh()->estoque);
        // O bloco 2 (a partir do índice 50) nunca foi gravado.
        $this->assertNull($variacoes[50]->refresh()->estoque);
        $this->assertSame($res['erro'], $conexao->refresh()->last_error);
    }

    public function test_ultimo_estoque_em_atualiza_quando_nao_ha_erro_em_nenhum_bloco(): void
    {
        $empresa = $this->empresaDoGabarito();
        $conexao = $this->conexaoAtiva($empresa);
        $variacao = $this->criarVariacao($empresa, 'OK-1');
        $this->vincular($empresa, $variacao, '8001');

        $this->fakeBling(['8001' => 1]);
        $this->servico()->varrer($conexao);

        $this->assertNotNull($conexao->refresh()->ultimo_estoque_em);
    }

    public function test_ultimo_estoque_em_nao_atualiza_quando_um_bloco_deu_erro(): void
    {
        $empresa = $this->empresaDoGabarito();
        $conexao = $this->conexaoAtiva($empresa);
        $variacao = $this->criarVariacao($empresa, 'ERRO-1');
        $this->vincular($empresa, $variacao, '8002');

        // 429 na 1ª (e única) chamada a /estoques/saldos — a varredura inteira para com erro.
        $this->fakeBling(['8002' => 1], erroNoBloco: 1);
        $this->servico()->varrer($conexao);

        $this->assertNull($conexao->refresh()->ultimo_estoque_em);
    }

    // ═══ Task 3 — comando bling:estoque ══════════════════════════════════════

    public function test_comando_varre_todas_as_conexoes_ativas_com_estoque_ligado(): void
    {
        $empresaA = $this->empresaDoGabarito();
        $empresaB = $this->empresaDoGabarito();
        $conexaoA = $this->conexaoAtiva($empresaA);
        $conexaoB = $this->conexaoAtiva($empresaB);
        $vA = $this->criarVariacao($empresaA, 'CMD-A');
        $vB = $this->criarVariacao($empresaB, 'CMD-B');
        $this->vincular($empresaA, $vA, '9001');
        $this->vincular($empresaB, $vB, '9002');

        $this->fakeBling(['9001' => 4, '9002' => 6]);

        $this->artisan('bling:estoque')->assertExitCode(0);

        $this->assertSame(4, $vA->refresh()->estoque);
        $this->assertSame(6, $vB->refresh()->estoque);
        $this->assertNotNull($conexaoA->refresh()->ultimo_estoque_em);
        $this->assertNotNull($conexaoB->refresh()->ultimo_estoque_em);
    }

    public function test_comando_com_empresa_varre_so_aquela_empresa(): void
    {
        $empresaA = $this->empresaDoGabarito();
        $empresaB = $this->empresaDoGabarito();
        $this->conexaoAtiva($empresaA);
        $this->conexaoAtiva($empresaB);
        $vA = $this->criarVariacao($empresaA, 'SO-A');
        $vB = $this->criarVariacao($empresaB, 'SO-B');
        $this->vincular($empresaA, $vA, '9101');
        $this->vincular($empresaB, $vB, '9102');

        $this->fakeBling(['9101' => 11, '9102' => 22]);

        $this->artisan('bling:estoque', ['--empresa' => $empresaA->id])->assertExitCode(0);

        $this->assertSame(11, $vA->refresh()->estoque);
        // Asserção negativa: a outra empresa não foi tocada por este comando.
        $this->assertNull($vB->refresh()->estoque);
    }

    public function test_conexao_divergente_revogada_ou_erro_e_pulada_e_entra_no_resumo(): void
    {
        $empresa = $this->empresaDoGabarito();
        $conexao = $this->conexaoAtiva($empresa, ['status' => BlingConexao::STATUS_DIVERGENTE]);
        $variacao = $this->criarVariacao($empresa, 'PULADA-1');
        $this->vincular($empresa, $variacao, '9201');

        $this->fakeBling(['9201' => 99]);

        // Via --empresa: a conexão é carregada mesmo não estando ativa, e é pulada dentro do laço.
        $this->artisan('bling:estoque', ['--empresa' => $empresa->id])
            ->expectsOutputToContain('pulada')
            ->assertExitCode(0);

        // Asserção negativa: nada foi gravado, porque a conexão nunca chegou a ser varrida.
        $this->assertNull($variacao->refresh()->estoque);
        Http::assertNothingSent();
    }

    public function test_uma_empresa_que_falha_nao_interrompe_as_demais(): void
    {
        $empresaFalha = $this->empresaDoGabarito();
        $empresaOk = $this->empresaDoGabarito();
        $this->conexaoAtiva($empresaFalha);
        $this->conexaoAtiva($empresaOk);
        $vFalha = $this->criarVariacao($empresaFalha, 'FALHA-1');
        $vOk = $this->criarVariacao($empresaOk, 'OK-CMD-1');
        $this->vincular($empresaFalha, $vFalha, '9301');
        $this->vincular($empresaOk, $vOk, '9302');

        // 429 na 1ª chamada a /estoques/saldos — qual das duas conexões bate nela primeiro
        // depende da ordem por ultimo_estoque_em (ambas null); de qualquer forma, uma delas
        // erra e a outra PRECISA continuar.
        $this->fakeBling(['9301' => 1, '9302' => 1], erroNoBloco: 1);

        $this->artisan('bling:estoque')->assertExitCode(0);

        // Pelo menos uma das duas foi gravada (a que não bateu no 429) — nenhuma trava a outra.
        $algumaGravada = $vFalha->refresh()->estoque !== null || $vOk->refresh()->estoque !== null;
        $this->assertTrue($algumaGravada);
    }

    public function test_dry_run_nao_faz_nenhuma_requisicao(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->conexaoAtiva($empresa);
        $variacao = $this->criarVariacao($empresa, 'DRY-1');
        $this->vincular($empresa, $variacao, '9401');

        Http::fake(); // nenhuma resposta configurada — qualquer requisição real falharia o teste

        $this->artisan('bling:estoque', ['--dry-run' => true])->assertExitCode(0);

        $this->assertNull($variacao->refresh()->estoque);
        Http::assertNothingSent();
    }

    public function test_resumo_e_conferido_por_reconsulta_ao_banco_nao_por_stdout(): void
    {
        $empresa = $this->empresaDoGabarito();
        $conexao = $this->conexaoAtiva($empresa);
        $variacao = $this->criarVariacao($empresa, 'RESUMO-1');
        $this->vincular($empresa, $variacao, '9501');

        $this->fakeBling(['9501' => 42]);

        $this->artisan('bling:estoque')->assertExitCode(0);

        // A prova é a RECONSULTA ao banco, nunca o texto impresso (armadilha conhecida do projeto).
        $this->assertSame(42, $variacao->refresh()->estoque);
        $this->assertNotNull($conexao->refresh()->ultimo_estoque_em);
    }
}
