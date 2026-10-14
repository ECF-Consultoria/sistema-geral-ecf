<?php

namespace Tests\Feature\Bling;

use App\Jobs\LerProdutosDoBlingJob;
use App\Models\BlingVinculo;
use App\Models\Company;
use App\Services\Bling\ImportadorDoBling;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
}
