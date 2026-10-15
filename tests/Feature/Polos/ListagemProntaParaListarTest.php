<?php

// TKT-0016: quando o cliente tem 2 ou mais produtos na Planilha de Produtos E 2 ou mais com
// custo na Precificação (link público do Onboarding), a coluna Listagem do Painel Polos vira
// "Pronto para listar" sozinha. Só sobe a partir de vazio, 'Não' ou 'Falta informação'; não
// mexe em 'Já listado' nem em valor criado à mão; não volta se o cliente apagar produtos.
// O `polos:sync-planilha --apply` não rebaixa o valor enquanto a ficha cumpre a regra.

namespace Tests\Feature\Polos;

use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ListagemProntaParaListarTest extends TestCase
{
    use RefreshDatabase;

    private function ficha(array $attrs = [], ?string $cust = null): MlbImplementacao
    {
        $empresa = MlbEmpresa::create([
            'nome'    => 'Loja Listagem ' . Str::random(4),
            'tipo'    => 'POLO',
            'projeto' => 'POLOS',
            'fase'    => 'M2',
            'polo'    => 'Arapongas',
            'cust_id' => $cust,
        ]);

        return MlbImplementacao::create(array_merge([
            'empresa_id' => $empresa->id,
            'token'      => Str::random(48),
            'dados'      => MlbImplementacao::dadosPadrao(),
        ], $attrs));
    }

    private function salvar(MlbImplementacao $impl, string $item, array $produtos)
    {
        return $this->patch(route('implementacao.salvar', $impl->token), [
            'id'    => $item,
            'campo' => 'produtos',
            'valor' => $produtos,
        ])->assertOk();
    }

    private function produtos(int $n): array
    {
        return array_map(fn ($i) => ['sku' => "SKU{$i}", 'produto' => "Produto {$i}"], range(1, $n));
    }

    private function precos(int $comCusto, int $semCusto = 0): array
    {
        $linhas = array_map(fn ($i) => ['sku' => "SKU{$i}", 'produto' => "Produto {$i}", 'custo' => '100'], range(1, $comCusto));
        for ($i = 0; $i < $semCusto; $i++) {
            $linhas[] = ['sku' => 'SEM' . $i, 'produto' => 'Sem custo', 'custo' => ''];
        }

        return $linhas;
    }

    /** Dados com a regra já cumprida, para montar a ficha direto no banco. */
    private function dadosProntos(): array
    {
        $dados = MlbImplementacao::dadosPadrao();
        $dados['itens']['planilha_produtos']['produtos'] = $this->produtos(2);
        $dados['itens']['precificacao']['produtos']      = $this->precos(2);

        return $dados;
    }

    // ─── Regra no link do cliente ────────────────────────────────────────────

    public function test_dois_produtos_com_custo_viram_pronto_para_listar(): void
    {
        $impl = $this->ficha();

        $this->salvar($impl, 'planilha_produtos', $this->produtos(2));
        $this->assertNull($impl->refresh()->listagem, 'sem custo ainda não está pronto');

        $this->salvar($impl, 'precificacao', $this->precos(2));
        $this->assertSame('Pronto para listar', $impl->refresh()->listagem);

        $log = Activity::where('log_name', 'implementacao')->latest('id')->first();
        $this->assertStringContainsString('Pronto para listar', $log->description);
    }

    public function test_vale_tambem_quando_a_precificacao_vem_antes(): void
    {
        $impl = $this->ficha(['listagem' => 'Não']);

        $this->salvar($impl, 'precificacao', $this->precos(2));
        $this->assertSame('Não', $impl->refresh()->listagem);

        $this->salvar($impl, 'planilha_produtos', $this->produtos(3));
        $this->assertSame('Pronto para listar', $impl->refresh()->listagem);
    }

    public function test_falta_informacao_tambem_sobe(): void
    {
        $impl = $this->ficha(['listagem' => 'Falta informação']);

        $this->salvar($impl, 'planilha_produtos', $this->produtos(2));
        $this->salvar($impl, 'precificacao', $this->precos(2));

        $this->assertSame('Pronto para listar', $impl->refresh()->listagem);
    }

    public function test_um_produto_so_nao_muda(): void
    {
        $impl = $this->ficha(['listagem' => 'Não']);

        $this->salvar($impl, 'planilha_produtos', $this->produtos(1));
        $this->salvar($impl, 'precificacao', $this->precos(1));

        $this->assertSame('Não', $impl->refresh()->listagem);
    }

    public function test_dois_produtos_mas_um_so_com_custo_nao_muda(): void
    {
        $impl = $this->ficha(['listagem' => 'Não']);

        $this->salvar($impl, 'planilha_produtos', $this->produtos(2));
        $this->salvar($impl, 'precificacao', $this->precos(1, semCusto: 1));

        $this->assertSame('Não', $impl->refresh()->listagem);
    }

    public function test_ja_listado_nao_muda(): void
    {
        $impl = $this->ficha(['listagem' => 'Já listado']);

        $this->salvar($impl, 'planilha_produtos', $this->produtos(2));
        $this->salvar($impl, 'precificacao', $this->precos(2));

        $this->assertSame('Já listado', $impl->refresh()->listagem);
    }

    public function test_valor_criado_a_mao_no_painel_nao_muda(): void
    {
        $impl = $this->ficha(['listagem' => 'Aguardando fotos']);

        $this->salvar($impl, 'planilha_produtos', $this->produtos(2));
        $this->salvar($impl, 'precificacao', $this->precos(2));

        $this->assertSame('Aguardando fotos', $impl->refresh()->listagem);
    }

    public function test_nao_volta_se_o_cliente_apagar_produtos(): void
    {
        $impl = $this->ficha(['listagem' => 'Pronto para listar', 'dados' => $this->dadosProntos()]);

        $this->salvar($impl, 'planilha_produtos', $this->produtos(1));

        $this->assertSame('Pronto para listar', $impl->refresh()->listagem);
    }

    // ─── polos:sync-planilha não rebaixa ─────────────────────────────────────

    private function syncComListagem(string $cust, string $listagem): void
    {
        $path = tempnam(sys_get_temp_dir(), 'polos_') . '.csv';
        $fh   = fopen($path, 'w');
        fputcsv($fh, ['Cust ID', 'Loja', 'Fase', 'Polo', 'Listagem']);
        fputcsv($fh, [$cust, 'Loja Listagem', 'M2', 'Arapongas', $listagem]);
        fclose($fh);

        $this->artisan('polos:sync-planilha', ['--file' => $path, '--apply' => true])->assertExitCode(0);

        @unlink($path);
    }

    public function test_planilha_nao_rebaixa_pronto_para_listar_enquanto_a_regra_vale(): void
    {
        $impl = $this->ficha(['listagem' => 'Pronto para listar', 'dados' => $this->dadosProntos()], '3200000001');

        $this->syncComListagem('3200000001', 'Não');

        $this->assertSame('Pronto para listar', $impl->refresh()->listagem);
    }

    public function test_planilha_vale_quando_a_ficha_nao_cumpre_a_regra(): void
    {
        $impl = $this->ficha(['listagem' => 'Pronto para listar'], '3200000002');

        $this->syncComListagem('3200000002', 'Não');

        $this->assertSame('Não', $impl->refresh()->listagem);
    }

    public function test_planilha_ainda_marca_ja_listado(): void
    {
        $impl = $this->ficha(['listagem' => 'Pronto para listar', 'dados' => $this->dadosProntos()], '3200000003');

        $this->syncComListagem('3200000003', 'Já listado');

        $this->assertSame('Já listado', $impl->refresh()->listagem);
    }
}
