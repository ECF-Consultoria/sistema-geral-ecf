<?php

namespace Tests\Feature\Bling;

use App\Models\BlingConexao;
use App\Models\BlingVinculo;
use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 184-10 (BLING-25) — a regra do dono do dado (CONTEXT §4.1) aplicada onde ela tem de
 * valer: na variação vinculada ao Bling, `estoque` e `codigo` (Ref) vêm do Bling, ninguém os
 * escreve por cima. A regra mora em `ProdutoCadastroService::gravarLinha`, pelo mecanismo de
 * `$presentes` ("chave ausente = não mexer") — o mesmo que grade, ficha e importação de
 * planilha já usam, então a trava vale para as três de uma vez, sem reimplementação.
 *
 * `BlingVinculo::travas()` (Task 1 + Task 2) é a fonte ÚNICA das duas travas, chamada tanto
 * por `ProdutoCadastroService` (escrita) quanto por `ProdutoLinhas` (leitura) — duas
 * implementações divergindo já aconteceu neste projeto com o ERP declarado.
 */
class TravaDaVariacaoVinculadaTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private function cadastro(): ProdutoCadastroService
    {
        return app(ProdutoCadastroService::class);
    }

    private function criarVariacao(Company $empresa, string $codigo, array $extra = []): EstruturaProdutoVariacao
    {
        $linha = array_merge([
            'grupo'   => 'G'.$codigo,
            'codigo'  => $codigo,
            'nome'    => 'Produto '.$codigo,
            'eixo'    => 'cor',
            'valor'   => 'Azul',
            'custo'   => 10.5,
            'estoque' => 20,
            'volumes' => [['c' => 50, 'l' => 40, 'a' => 10, 'kg' => 5]],
        ], $extra);

        $res = $this->cadastro()->gravarLinhas($empresa, [$linha], $this->atorCliente($empresa));

        return EstruturaProdutoVariacao::find($res['linhas'][0]['id']);
    }

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

    /** Linha de atualização por `id` (MODO_GRADE) — forma que a ficha/grade mandam. */
    private function linhaDeAtualizacao(EstruturaProdutoVariacao $variacao, array $sobre = []): array
    {
        return array_merge([
            'id'      => $variacao->id,
            'codigo'  => $variacao->codigo.'-NOVO',
            'nome'    => $variacao->produto->nome,
            'eixo'    => $variacao->eixo,
            'valor'   => $variacao->valor,
            'custo'   => 99.9,
            'estoque' => 777,
            'volumes' => [['c' => 1, 'l' => 1, 'a' => 1, 'kg' => 1]],
        ], $sobre);
    }

    // ═══ Teste 1 — estoque não muda na variação vinculada (asserção negativa) ═══════

    public function test_estoque_nao_muda_na_variacao_vinculada(): void
    {
        $empresa = $this->empresaDoGabarito();
        $variacao = $this->criarVariacao($empresa, 'BLV-1');
        $this->conexaoAtiva($empresa);
        $this->vincular($empresa, $variacao, 'BL-1');

        $this->cadastro()->gravarLinhas($empresa, [$this->linhaDeAtualizacao($variacao, ['codigo' => $variacao->codigo])], $this->atorCliente($empresa));

        $this->assertSame(20, $variacao->refresh()->estoque, 'o estoque continua o do Bling, não o 777 da linha');
    }

    // ═══ Teste 2 — Ref (codigo) não muda na variação vinculada ══════════════════════

    public function test_ref_nao_muda_na_variacao_vinculada(): void
    {
        $empresa = $this->empresaDoGabarito();
        $variacao = $this->criarVariacao($empresa, 'BLV-2');
        $this->conexaoAtiva($empresa);
        $this->vincular($empresa, $variacao, 'BL-2');

        $this->cadastro()->gravarLinhas($empresa, [$this->linhaDeAtualizacao($variacao)], $this->atorCliente($empresa));

        $this->assertSame('BLV-2', $variacao->refresh()->codigo, 'a Ref continua a mesma: é por ela que o Bling casa com o anúncio');
    }

    // ═══ Teste 3 — a trava é SÓ dos dois campos; o resto continua editável ══════════

    public function test_nome_custo_e_volumes_continuam_editaveis_na_variacao_vinculada(): void
    {
        $empresa = $this->empresaDoGabarito();
        $variacao = $this->criarVariacao($empresa, 'BLV-3');
        $this->conexaoAtiva($empresa);
        $this->vincular($empresa, $variacao, 'BL-3');

        $this->cadastro()->gravarLinhas($empresa, [$this->linhaDeAtualizacao($variacao, [
            'codigo' => $variacao->codigo, 'nome' => 'Nome Trocado', 'custo' => 55.5,
            'volumes' => [['c' => 9, 'l' => 9, 'a' => 9, 'kg' => 9]],
        ])], $this->atorCliente($empresa));

        $variacao->refresh();
        $this->assertSame('Nome Trocado', $variacao->produto->nome);
        $this->assertEqualsWithDelta(55.5, (float) $variacao->custo, 0.001);
        $this->assertSame(1, $variacao->volumes()->count());
        $this->assertEqualsWithDelta(9.0, (float) $variacao->volumes()->first()->comprimento, 0.001);
    }

    // ═══ Teste 4 — par positivo: SEM vínculo, estoque e Ref mudam normalmente ═══════

    public function test_sem_vinculo_estoque_e_ref_mudam_normalmente(): void
    {
        // Par positivo do Teste 1/2: sem ele, uma regra que travasse TODA variação
        // (bug de "esqueceu o `if`") passaria igual nos testes acima.
        $empresa = $this->empresaDoGabarito();
        $variacao = $this->criarVariacao($empresa, 'BLV-4');
        // Conexão existe, mas NENHUM vínculo aponta para esta variação.
        $this->conexaoAtiva($empresa);

        $this->cadastro()->gravarLinhas($empresa, [$this->linhaDeAtualizacao($variacao)], $this->atorCliente($empresa));

        $variacao->refresh();
        $this->assertSame(777, $variacao->estoque);
        $this->assertSame('BLV-4-NOVO', $variacao->codigo);
    }

    // ═══ Teste 5 — vínculo removido_no_bling destrava os dois campos ════════════════

    public function test_vinculo_removido_no_bling_destrava(): void
    {
        $empresa = $this->empresaDoGabarito();
        $variacao = $this->criarVariacao($empresa, 'BLV-5');
        $this->conexaoAtiva($empresa);
        $this->vincular($empresa, $variacao, 'BL-5', ['situacao' => BlingVinculo::SITUACAO_REMOVIDO_NO_BLING]);

        $this->cadastro()->gravarLinhas($empresa, [$this->linhaDeAtualizacao($variacao)], $this->atorCliente($empresa));

        $variacao->refresh();
        $this->assertSame(777, $variacao->estoque, 'produto saiu do Bling: travar para sempre seria pior');
        $this->assertSame('BLV-5-NOVO', $variacao->codigo);
    }

    // ═══ Teste 6 — estoque_ativo=false: estoque editável, Ref SEGUE travada ═════════

    public function test_estoque_ativo_false_destrava_so_o_estoque(): void
    {
        $empresa = $this->empresaDoGabarito();
        $variacao = $this->criarVariacao($empresa, 'BLV-6');
        $this->conexaoAtiva($empresa, ['estoque_ativo' => false]);
        $this->vincular($empresa, $variacao, 'BL-6');

        $this->cadastro()->gravarLinhas($empresa, [$this->linhaDeAtualizacao($variacao)], $this->atorCliente($empresa));

        $variacao->refresh();
        // As DUAS metades da regra, na MESMA asserção de estado: o SKU casa o anúncio
        // independente de quem manda no estoque.
        $this->assertSame(['estoque' => 777, 'codigo' => 'BLV-6'], ['estoque' => $variacao->estoque, 'codigo' => $variacao->codigo]);
    }

    // ═══ Teste 7 — conexão divergente ou revogada: os dois campos editáveis ════════

    public function test_conexao_divergente_ou_revogada_destrava_os_dois(): void
    {
        foreach ([BlingConexao::STATUS_DIVERGENTE, BlingConexao::STATUS_REVOGADA] as $status) {
            $empresa = $this->empresaDoGabarito();
            $variacao = $this->criarVariacao($empresa, 'BLV-7-'.$status);
            $this->conexaoAtiva($empresa, ['status' => $status]);
            $this->vincular($empresa, $variacao, 'BL-7-'.$status);

            $this->cadastro()->gravarLinhas($empresa, [$this->linhaDeAtualizacao($variacao)], $this->atorCliente($empresa));

            $variacao->refresh();
            $this->assertSame(777, $variacao->estoque, $status);
            $this->assertSame('BLV-7-'.$status.'-NOVO', $variacao->codigo, $status);
        }
    }

    // ═══ Teste 8 — a importação de planilha obedece à mesma regra ══════════════════

    public function test_importacao_de_planilha_obedece_a_mesma_regra(): void
    {
        $empresa = $this->empresaDoGabarito();
        $variacao = $this->criarVariacao($empresa, 'BLV-8');
        $this->conexaoAtiva($empresa);
        $this->vincular($empresa, $variacao, 'BL-8');

        // A planilha casa a linha pelo CÓDIGO (sem `id`) — é o caminho de MODO_IMPORTACAO.
        $linha = $this->linhaDeAtualizacao($variacao, ['codigo' => $variacao->codigo]);
        unset($linha['id']);
        $linha['grupo'] = $variacao->produto->codigo;

        $this->cadastro()->gravarLinhas($empresa, [$linha], $this->atorCliente($empresa), ProdutoCadastroService::MODO_IMPORTACAO);

        $variacao->refresh();
        $this->assertSame(20, $variacao->estoque, 'planilha também não sobrescreve o estoque do Bling');
        $this->assertSame('BLV-8', $variacao->codigo, 'planilha também não sobrescreve a Ref travada');
    }

    // ═══ Teste 9 — o mapa de vínculos é UMA consulta por lote, nunca por linha ══════

    public function test_mapa_de_vinculos_e_uma_consulta_para_o_lote_inteiro(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->conexaoAtiva($empresa);

        $produto = EstruturaProduto::create(['company_id' => $empresa->id, 'nome' => 'Produto em massa']);
        $linhas = [];
        for ($i = 1; $i <= 50; $i++) {
            $variacao = EstruturaProdutoVariacao::create([
                'produto_id' => $produto->id, 'company_id' => $empresa->id, 'ordem' => $i, 'codigo' => "MASSA-{$i}", 'estoque' => 5,
            ]);
            $this->vincular($empresa, $variacao, "BL-MASSA-{$i}");
            $linhas[] = ['id' => $variacao->id, 'codigo' => "MASSA-{$i}", 'nome' => 'Produto em massa', 'estoque' => 999];
        }

        $consultas = 0;
        DB::listen(function ($q) use (&$consultas) {
            if (str_starts_with(strtolower($q->sql), 'select') && str_contains($q->sql, 'bling_vinculos')) {
                $consultas++;
            }
        });

        $this->cadastro()->gravarLinhas($empresa, $linhas, $this->atorCliente($empresa), ProdutoCadastroService::MODO_GRADE);

        $this->assertSame(1, $consultas, 'uma consulta a bling_vinculos para o lote de 50 linhas — não uma por linha');
    }

    // ═══ Teste 10 — aviso em pt-BR quando a linha é travada ═════════════════════════

    public function test_linha_travada_gera_aviso_em_pt_br(): void
    {
        $empresa = $this->empresaDoGabarito();
        $variacao = $this->criarVariacao($empresa, 'BLV-10');
        $this->conexaoAtiva($empresa);
        $this->vincular($empresa, $variacao, 'BL-10');

        $r = $this->cadastro()->gravarLinhas($empresa, [$this->linhaDeAtualizacao($variacao)], $this->atorCliente($empresa));

        $aviso = array_values(array_filter($r['avisos'], fn ($a) => str_contains($a, 'Bling')));
        $this->assertNotEmpty($aviso, 'avisa em pt-BR que o estoque e a Ref não mudaram porque vêm do Bling');
        $this->assertStringContainsString('estoque', $aviso[0]);
        $this->assertStringContainsString('Ref', $aviso[0]);
        $this->assertStringContainsString('Bling', $aviso[0]);
    }
}
