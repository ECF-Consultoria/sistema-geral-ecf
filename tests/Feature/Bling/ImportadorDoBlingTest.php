<?php

namespace Tests\Feature\Bling;

use App\Jobs\LerProdutosDoBlingJob;
use App\Models\BlingConexao;
use App\Models\BlingVinculo;
use App\Models\Company;
use App\Services\Bling\ImportadorDoBling;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 184-08 (BLING-23, BLING-32) — `ImportadorDoBling::previa()` (Task 2) e
 * `::aplicar()` (Task 3): o par plano/aplicar sem estado que leva a rodada já
 * lida pelo `LerProdutosDoBlingJob` ao cadastro de Produtos.
 */
class ImportadorDoBlingTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    /** Mesmo termo proibido do `PlanilhaDeProdutosV2Test` — sigilo da origem. */
    private const PROIBIDO = '/mercado|an[uú]ncio|publica|\bmlb|\bml\b/iu';

    private function assertSemOrigem(string $texto, string $onde): void
    {
        $texto = preg_replace_callback('/\\\\u([0-9a-f]{4})/i', fn ($m) => mb_chr(hexdec($m[1]), 'UTF-8'), $texto);
        $this->assertDoesNotMatchRegularExpression(self::PROIBIDO, $texto, "a origem vazou em {$onde}: {$texto}");
    }

    private function produtoSimples(int $id, string $codigo, string $nome = 'Produto Teste', array $extra = []): array
    {
        return array_merge([
            'id'       => $id,
            'nome'     => $nome,
            'codigo'   => $codigo,
            'situacao' => 'Ativo',
            'tipo'     => 'P',
            'formato'  => 'S',
        ], $extra);
    }

    /** Grava o estado da rodada no MESMO formato que o `LerProdutosDoBlingJob` grava. */
    private function rodada(Company $empresa, string $rodada, array $produtos, bool $concluida = true): void
    {
        Cache::put(LerProdutosDoBlingJob::chaveEstado((int) $empresa->id, $rodada), [
            'pagina'    => 2,
            'produtos'  => $produtos,
            'avisos'    => [],
            'erro'      => null,
            'concluida' => $concluida,
            'lidos'     => count($produtos),
        ], 3600);
    }

    private function importador(): ImportadorDoBling
    {
        return app(ImportadorDoBling::class);
    }

    // ═══ Task 2 — previa() ═══════════════════════════════════════════════════

    public function test_previa_de_produto_novo_nao_grava_nada(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->rodada($empresa, 'r1', [$this->produtoSimples(1001, 'NOVO-1')]);

        $previa = $this->importador()->previa($empresa, 'r1');

        $this->assertNull($previa['erro_geral']);
        $this->assertSame(1, $previa['totais']['novos']);
        $this->assertSame(0, $previa['totais']['atualizados']);

        // Asserção negativa: a prévia só lê.
        $this->assertDatabaseCount('estrutura_produtos', 0);
        $this->assertDatabaseCount('estrutura_produto_variacoes', 0);
        $this->assertDatabaseCount('bling_vinculos', 0);
    }

    public function test_previa_de_codigo_ja_existente_na_variacao_cai_em_atualizados(): void
    {
        $empresa = $this->empresaDoGabarito();
        app(ProdutoCadastroService::class)->gravarLinhas($empresa, [
            ['codigo' => 'EXIST-1', 'grupo' => 'EXIST-1', 'nome' => 'Já cadastrado'],
        ], $this->atorCliente($empresa));

        $this->rodada($empresa, 'r2', [$this->produtoSimples(2001, 'EXIST-1')]);

        $previa = $this->importador()->previa($empresa, 'r2');

        $this->assertSame(0, $previa['totais']['novos']);
        $this->assertSame(1, $previa['totais']['atualizados']);
    }

    public function test_previa_de_vinculo_ja_existente_pelo_id_do_bling_cai_em_atualizados(): void
    {
        $empresa = $this->empresaDoGabarito();
        $res = app(ProdutoCadastroService::class)->gravarLinhas($empresa, [
            ['codigo' => 'V-ANTIGO', 'grupo' => 'V-ANTIGO', 'nome' => 'Variação antiga'],
        ], $this->atorCliente($empresa));
        $variacaoId = $res['linhas'][0]['id'];

        BlingVinculo::create([
            'company_id'       => $empresa->id,
            'bling_produto_id' => '3001',
            'variacao_id'      => $variacaoId,
            'codigo_bling'     => 'V-ANTIGO',
            'formato'          => BlingVinculo::FORMATO_SIMPLES,
            'situacao'         => BlingVinculo::SITUACAO_VINCULADO,
        ]);

        // O Bling renomeou o código (bling_produto_id=3001 é o mesmo, codigo mudou): ainda é
        // atualizado, porque o casamento é pelo ID DO BLING, nunca pelo SKU.
        $this->rodada($empresa, 'r3', [$this->produtoSimples(3001, 'V-RENOMEADO')]);

        $previa = $this->importador()->previa($empresa, 'r3');

        $this->assertSame(0, $previa['totais']['novos']);
        $this->assertSame(1, $previa['totais']['atualizados']);
    }

    public function test_previa_lista_as_recusadas_do_tradutor_em_erros_com_motivo(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->rodada($empresa, 'r4', [$this->produtoSimples(4001, '')]);

        $previa = $this->importador()->previa($empresa, 'r4');

        $this->assertSame(1, $previa['totais']['erros']);
        $this->assertStringContainsString('código', $previa['grupos']['erros'][0]['motivo']);
    }

    public function test_previa_detecta_codigo_repetido_entre_produtos_diferentes_do_bling(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->rodada($empresa, 'r5', [
            $this->produtoSimples(5001, 'DUP-1'),
            $this->produtoSimples(5002, 'DUP-1'),
        ]);

        $previa = $this->importador()->previa($empresa, 'r5');

        $this->assertSame(1, $previa['totais']['novos']);
        $this->assertSame(1, $previa['totais']['erros']);
        $this->assertStringContainsString('já apareceu em outro produto', $previa['grupos']['erros'][0]['motivo']);
    }

    public function test_previa_junta_avisos_sem_repetir_o_mesmo_texto(): void
    {
        $empresa = $this->empresaDoGabarito();
        // Dois produtos simples, cada um com nome > 255 caracteres: o MESMO texto de aviso
        // ("Nome do Bling cortado...") se repetiria sem a deduplicação.
        $nomeLongo = str_repeat('A', 300);
        $this->rodada($empresa, 'r6', [
            $this->produtoSimples(6001, 'LONGO-1', $nomeLongo),
            $this->produtoSimples(6002, 'LONGO-2', $nomeLongo),
        ]);

        $previa = $this->importador()->previa($empresa, 'r6');

        $ocorrencias = array_filter($previa['avisos'], fn ($a) => str_contains($a, 'Nome do Bling cortado'));
        $this->assertCount(1, $ocorrencias, 'o mesmo texto de aviso não pode se repetir');
    }

    public function test_previa_corta_o_detalhe_mas_os_totais_contam_tudo(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produtos = [];
        for ($i = 1; $i <= 201; $i++) {
            $produtos[] = $this->produtoSimples(7000 + $i, "CORTE-{$i}");
        }
        $this->rodada($empresa, 'r7', $produtos);

        $previa = $this->importador()->previa($empresa, 'r7');

        $this->assertSame(201, $previa['totais']['novos']);
        $this->assertCount(ImportadorDoBling::DETALHE_MAXIMO, $previa['grupos']['novos']);
    }

    public function test_previa_de_rodada_inexistente_devolve_erro_geral_sem_excecao(): void
    {
        $empresa = $this->empresaDoGabarito();

        $previa = $this->importador()->previa($empresa, 'rodada-que-nunca-existiu');

        $this->assertNotNull($previa['erro_geral']);
        $this->assertSame(0, $previa['totais']['novos']);
        $this->assertSame(0, $previa['totais']['atualizados']);
        $this->assertSame(0, $previa['totais']['erros']);
    }

    public function test_previa_nao_cita_a_plataforma_de_venda(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->rodada($empresa, 'r9', [
            $this->produtoSimples(9001, ''), // gera recusa com motivo
            $this->produtoSimples(9002, 'DUP-9'),
            $this->produtoSimples(9003, 'DUP-9'), // gera recusa de código repetido
        ]);

        $previa = $this->importador()->previa($empresa, 'r9');

        $this->assertSemOrigem((string) json_encode($previa, JSON_UNESCAPED_UNICODE), 'prévia do importador do Bling');
    }

    // ═══ Task 3 — aplicar() ══════════════════════════════════════════════════

    public function test_aplicar_cria_as_variacoes_e_os_vinculos(): void
    {
        Queue::fake();
        $empresa = $this->empresaDoGabarito();
        $this->rodada($empresa, 'a1', [
            $this->produtoSimples(101, 'A-1'),
            $this->produtoSimples(102, 'A-2'),
        ]);

        $res = $this->importador()->aplicar($empresa, 'a1', AtorDoPortal::sistema());

        $this->assertSame(2, $res['novos']);
        $this->assertSame(2, $res['vinculos_criados']);

        $vinculos = BlingVinculo::where('company_id', $empresa->id)->get();
        $this->assertCount(2, $vinculos);
        foreach ($vinculos as $v) {
            $this->assertNotNull($v->variacao_id);
            $this->assertNotNull($v->bling_produto_id);
            $this->assertSame(BlingVinculo::FORMATO_SIMPLES, $v->formato);
            $this->assertSame(BlingVinculo::SITUACAO_VINCULADO, $v->situacao);
        }
    }

    public function test_aplicar_nao_enfileira_nenhuma_cadeia_de_ia(): void
    {
        Queue::fake();
        $empresa = $this->empresaDoGabarito();
        $this->rodada($empresa, 'a2', [$this->produtoSimples(201, 'A-3')]);

        $this->importador()->aplicar($empresa, 'a2', AtorDoPortal::sistema());

        // Asserção negativa: nenhuma cadeia de IA foi despachada.
        Queue::assertNothingPushed();
    }

    public function test_par_positivo_o_queue_fake_captura_quando_ha_algo_a_capturar(): void
    {
        // Sem este par, o assertNothingPushed do teste anterior seria um gate vazio por
        // construção — aqui provamos que o Queue::fake() do mesmo teste REALMENTE capturaria
        // um job se `agendarPreparo` fosse true (o parâmetro que aplicar() nunca passa).
        Queue::fake();
        $empresa = $this->empresaDoGabarito();

        app(ProdutoCadastroService::class)->gravarLinhas(
            $empresa,
            [['codigo' => 'PAR-POSITIVO', 'grupo' => 'PAR-POSITIVO', 'nome' => 'Par positivo']],
            AtorDoPortal::sistema(),
            ProdutoCadastroService::MODO_IMPORTACAO,
            agendarPreparo: true,
        );

        Queue::assertPushed(\App\Jobs\Publicador\PrepararProdutoNoPublicadorJob::class);
    }

    public function test_aplicar_grava_o_log_com_origem_bling_e_sem_causer(): void
    {
        Queue::fake();
        $empresa = $this->empresaDoGabarito();
        $this->rodada($empresa, 'a4', [$this->produtoSimples(401, 'A-4')]);

        $this->importador()->aplicar($empresa, 'a4', AtorDoPortal::sistema());

        $log = Activity::where('log_name', 'portal')->where('properties->evento', 'produtos_gravados')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('bling', $log->getExtraProperty('origem'));
        $this->assertNull($log->causer_id);
    }

    public function test_aplicar_duas_vezes_na_mesma_rodada_nao_duplica_vinculo(): void
    {
        Queue::fake();
        $empresa = $this->empresaDoGabarito();
        $this->rodada($empresa, 'a5', [$this->produtoSimples(501, 'A-5')]);

        $primeira = $this->importador()->aplicar($empresa, 'a5', AtorDoPortal::sistema());
        $segunda = $this->importador()->aplicar($empresa, 'a5', AtorDoPortal::sistema());

        $this->assertSame(1, $primeira['novos']);
        // A 2ª passada grava os MESMOS valores: `gravarLinhas` classifica como sem_mudanca, não
        // como "atualizados" nem "novos" — e é essa contagem real de escrita que aplicar()
        // devolve, não uma suposição da prévia.
        $this->assertSame(0, $segunda['novos']);
        $this->assertSame(0, $segunda['atualizados']);
        $this->assertSame(1, $segunda['sem_mudanca']);

        // O unique `blvin_company_prod_uq` é a idempotência: ainda assim só 1 vínculo.
        $this->assertDatabaseCount('bling_vinculos', 1);
    }

    public function test_linha_que_falha_na_gravacao_aparece_em_nao_entraram(): void
    {
        Queue::fake();
        $empresa = $this->empresaDoGabarito();
        // Nome vazio: o tradutor não exige nome (só código), mas o NormalizadorDeLinha exige —
        // a linha passa pelo PLANO e só falha na GRAVAÇÃO de fato.
        $this->rodada($empresa, 'a6', [$this->produtoSimples(601, 'A-6', '')]);

        $res = $this->importador()->aplicar($empresa, 'a6', AtorDoPortal::sistema());

        $this->assertSame(1, $res['erros']);
        $this->assertCount(1, $res['nao_entraram']);
        $this->assertStringContainsString('nome', $res['nao_entraram'][0]['motivo']);
        // A tela nunca pode dizer "concluída" com produto que não existe (BE-WR-04).
        $this->assertDatabaseCount('bling_vinculos', 0);
    }

    public function test_escrito_guarda_os_valores_que_o_bling_gravou(): void
    {
        Queue::fake();
        $empresa = $this->empresaDoGabarito();
        $this->rodada($empresa, 'a7', [$this->produtoSimples(701, 'A-7', 'Nome do Bling')]);

        $this->importador()->aplicar($empresa, 'a7', AtorDoPortal::sistema());

        $vinculo = BlingVinculo::where('company_id', $empresa->id)->where('bling_produto_id', '701')->first();
        $escrito = json_decode((string) $vinculo->escrito, true);

        $this->assertSame('Nome do Bling', $escrito['nome']);
        $this->assertSame('A-7', $escrito['codigo']);
    }

    public function test_aplicar_grava_em_lotes_de_no_maximo_200_linhas(): void
    {
        Queue::fake();
        $empresa = $this->empresaDoGabarito();
        $produtos = [];
        for ($i = 1; $i <= 250; $i++) {
            $produtos[] = $this->produtoSimples(8000 + $i, "LOTE-{$i}");
        }
        $this->rodada($empresa, 'a8', $produtos);

        // Espião: um subclasse que conta as chamadas e delega ao método REAL (construído com
        // as dependências de verdade do container) — prova que 250 linhas / 200 por lote =
        // EXATAMENTE 2 chamadas a `gravarLinhas`, sem reimplementar a lógica do serviço.
        $espiao = new class(
            app(\App\Services\Portal\Estrutura\Produtos\ListasDaEmpresaService::class),
            app(\App\Services\Incubadora\Publicador\CategoriaSugestaoService::class),
            app(\App\Services\Portal\Estrutura\Produtos\ProdutoLinhas::class),
            app(\App\Services\Portal\Estrutura\EstruturaOfertaService::class),
            app(\App\Services\Portal\Estrutura\Produtos\DescricaoDoProduto::class),
            app(\App\Services\Portal\Estrutura\Produtos\LimiteDoTitulo::class),
        ) extends ProdutoCadastroService {
            public int $chamadas = 0;

            public function gravarLinhas(Company $empresa, array $linhas, AtorDoPortal $ator, string $modo = self::MODO_GRADE, bool $agendarPreparo = true): array
            {
                $this->chamadas++;

                return parent::gravarLinhas($empresa, $linhas, $ator, $modo, $agendarPreparo);
            }
        };
        $this->app->instance(ProdutoCadastroService::class, $espiao);

        $res = $this->importador()->aplicar($empresa, 'a8', AtorDoPortal::sistema());

        $this->assertSame(250, $res['novos']);
        $this->assertSame(250, $res['vinculos_criados']);
        $this->assertSame(2, $espiao->chamadas);
    }

    public function test_orfao_vira_removido_no_bling_sem_apagar_nada(): void
    {
        Queue::fake();
        $empresa = $this->empresaDoGabarito();

        // 1ª rodada: 2 produtos vinculados.
        $this->rodada($empresa, 'a9-1', [
            $this->produtoSimples(901, 'A-9-1'),
            $this->produtoSimples(902, 'A-9-2'),
        ]);
        $this->importador()->aplicar($empresa, 'a9-1', AtorDoPortal::sistema());

        $variacaoId = BlingVinculo::where('bling_produto_id', '902')->first()->variacao_id;

        // 2ª rodada (concluída): só o produto 901 apareceu — o 902 saiu do Bling.
        $this->rodada($empresa, 'a9-2', [$this->produtoSimples(901, 'A-9-1')], concluida: true);
        $this->importador()->aplicar($empresa, 'a9-2', AtorDoPortal::sistema());

        $vinculo902 = BlingVinculo::where('bling_produto_id', '902')->first();
        $this->assertSame(BlingVinculo::SITUACAO_REMOVIDO_NO_BLING, $vinculo902->situacao);
        $this->assertNotNull($vinculo902->motivo);

        // Asserção negativa TRIPLA: nada foi apagado no Portal.
        $this->assertDatabaseHas('estrutura_produto_variacoes', ['id' => $variacaoId]);
        $variacao = \App\Models\EstruturaProdutoVariacao::find($variacaoId);
        $this->assertDatabaseHas('estrutura_produtos', ['id' => $variacao->produto_id]);
        $this->assertDatabaseHas('estrutura_ofertas', ['variacao_id' => $variacaoId]);
    }

    public function test_rodada_interrompida_nao_marca_orfao(): void
    {
        Queue::fake();
        $empresa = $this->empresaDoGabarito();

        $this->rodada($empresa, 'a10-1', [$this->produtoSimples(1001, 'A-10-1')]);
        $this->importador()->aplicar($empresa, 'a10-1', AtorDoPortal::sistema());

        // 2ª rodada NÃO concluída (interrompida no meio): não pode marcar o 1001 como órfão,
        // mesmo ele não tendo aparecido nesta fatia.
        $this->rodada($empresa, 'a10-2', [], concluida: false);
        $this->importador()->aplicar($empresa, 'a10-2', AtorDoPortal::sistema());

        $vinculo = BlingVinculo::where('bling_produto_id', '1001')->first();
        $this->assertSame(BlingVinculo::SITUACAO_VINCULADO, $vinculo->situacao);
    }

    public function test_aplicar_atualiza_a_data_da_ultima_importacao_na_conexao(): void
    {
        Queue::fake();
        $empresa = $this->empresaDoGabarito();
        BlingConexao::create([
            'company_id' => $empresa->id, 'access_token' => 'tok', 'refresh_token' => 'ref',
            'status' => BlingConexao::STATUS_ATIVA,
        ]);
        $this->rodada($empresa, 'a11', [$this->produtoSimples(1101, 'A-11')]);

        $this->importador()->aplicar($empresa, 'a11', AtorDoPortal::sistema());

        $conexao = BlingConexao::where('company_id', $empresa->id)->first();
        $this->assertNotNull($conexao->ultima_importacao_em);
    }
}
