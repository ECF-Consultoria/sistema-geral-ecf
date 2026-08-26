<?php

// Planilha "Dash Gerencial Polos V2" de 2026-08-26 — três mudanças que o sync passou a cobrir:
//
//   1. O cabeçalho da coluna 'Fase' foi sobrescrito à mão no Google Sheets e chegou como
//      'Loja 2', com os dados de fase intactos. Sem o alias o comando aborta em "Coluna
//      obrigatória ausente" e o sync inteiro para por causa de um título trocado.
//   2. Fase nova "Encaminhar Comercial" (lead pré-aceite, ainda sem Cust ID).
//   3. Coluna nova "Central de Promoção", que a planilha escreve ora "Não" ora "NÃO".

namespace Tests\Feature\Polos;

use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncPolosPlanilhaV2Test extends TestCase
{
    use RefreshDatabase;

    /** Escreve um CSV temporário no formato que o comando lê (cabeçalho + linhas). */
    private function csv(array $header, array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'polos_').'.csv';
        $fh   = fopen($path, 'w');
        fputcsv($fh, $header);
        foreach ($rows as $r) {
            fputcsv($fh, $r);
        }
        fclose($fh);

        return $path;
    }

    public function test_alias_loja_2_substitui_a_coluna_fase_sobrescrita(): void
    {
        // Cabeçalho SEM 'Fase' — exatamente como a planilha de 26/08 chegou.
        $file = $this->csv(
            ['Cust ID', 'Loja', 'Loja 2', 'Polo'],
            [['3567897405', 'Loja Alias', 'M2', 'Arapongas']],
        );

        $this->artisan('polos:sync-planilha', ['--file' => $file, '--apply' => true])
            ->assertExitCode(0);

        $e = MlbEmpresa::where('cust_id', '3567897405')->first();
        $this->assertNotNull($e, 'A empresa deveria ter sido criada — o alias não pegou.');
        $this->assertSame('M2', $e->fase, 'A fase veio da coluna renomeada "Loja 2".');
        $this->assertSame('POLOS', $e->projeto);

        @unlink($file);
    }

    public function test_coluna_fase_canonica_tem_precedencia_sobre_o_alias(): void
    {
        // Se a planilha for corrigida e voltar a ter 'Fase', é ela que manda —
        // o alias é rede de segurança, não precedência.
        $file = $this->csv(
            ['Cust ID', 'Loja', 'Fase', 'Loja 2', 'Polo'],
            [['3511975563', 'Loja Dupla', 'M3', 'M0', 'Arapongas']],
        );

        $this->artisan('polos:sync-planilha', ['--file' => $file, '--apply' => true])
            ->assertExitCode(0);

        $this->assertSame('M3', MlbEmpresa::where('cust_id', '3511975563')->first()->fase);

        @unlink($file);
    }

    public function test_encaminhar_comercial_vira_fase_propria(): void
    {
        // Lead pré-aceite: não tem Cust ID, casa por nome, e a fase NÃO pode ser
        // fundida num vizinho (senão some a contagem do funil de entrada).
        $file = $this->csv(
            ['Cust ID', 'Loja', 'Loja 2', 'Polo'],
            [['', 'Lead Comercial', 'Encaminhar Comercial', 'Arapongas']],
        );

        $this->artisan('polos:sync-planilha', ['--file' => $file, '--apply' => true])
            ->assertExitCode(0);

        $e = MlbEmpresa::where('nome', 'Lead Comercial')->first();
        $this->assertNotNull($e);
        $this->assertSame('Encaminhar Comercial', $e->fase);
        // Precisa ser reconhecida como POLOS mesmo sem `projeto` gravado, senão
        // uma empresa nessa fase sumiria do painel sem aviso.
        $this->assertSame('POLOS', MlbEmpresa::FASE_PARA_PROJETO['Encaminhar Comercial']);

        @unlink($file);
    }

    public function test_central_promocao_e_gravada_e_normaliza_a_caixa(): void
    {
        // A planilha mistura "Não" (182 linhas) e "NÃO" (60) na MESMA coluna; sem
        // normalizar, o filtro do painel nasce com dois valores pra mesma resposta.
        $file = $this->csv(
            ['Cust ID', 'Loja', 'Loja 2', 'Polo', 'Central de Promoção'],
            [
                ['3439119776', 'Loja Sim',      'M1', 'Arapongas', 'Sim'],
                ['3552315988', 'Loja Nao',      'M1', 'Arapongas', 'Não'],
                ['1570100036', 'Loja NaoCaixa', 'M1', 'Arapongas', 'NÃO'],
                ['3571758702', 'Loja Livre',    'M1', 'Arapongas', 'Em análise'],
            ],
        );

        $this->artisan('polos:sync-planilha', ['--file' => $file, '--apply' => true])
            ->assertExitCode(0);

        $valor = fn (string $cust) => MlbImplementacao::where(
            'empresa_id',
            MlbEmpresa::where('cust_id', $cust)->value('id'),
        )->value('central_promocao');

        $this->assertSame('Sim', $valor('3439119776'));
        $this->assertSame('Não', $valor('3552315988'));
        $this->assertSame('Não', $valor('1570100036'), '"NÃO" deveria virar "Não".');
        // Valor fora do catálogo passa VERBATIM — a planilha continua sendo a verdade.
        $this->assertSame('Em análise', $valor('3571758702'));

        @unlink($file);
    }

    public function test_celula_vazia_de_central_promocao_nao_apaga_o_valor_ja_gravado(): void
    {
        $e = MlbEmpresa::create([
            'nome' => 'Loja Preservada', 'cust_id' => '3319960685',
            'projeto' => 'POLOS', 'fase' => 'M1', 'polo' => 'Arapongas',
        ]);
        MlbImplementacao::create([
            'empresa_id' => $e->id, 'token' => str_repeat('a', 48), 'central_promocao' => 'Sim',
        ]);

        $file = $this->csv(
            ['Cust ID', 'Loja', 'Loja 2', 'Polo', 'Central de Promoção'],
            [['3319960685', 'Loja Preservada', 'M2', 'Arapongas', '']],
        );

        $this->artisan('polos:sync-planilha', ['--file' => $file, '--apply' => true])
            ->assertExitCode(0);

        $this->assertSame('M2', $e->fresh()->fase, 'A fase deveria ter avançado.');
        $this->assertSame('Sim', MlbImplementacao::where('empresa_id', $e->id)->value('central_promocao'));

        @unlink($file);
    }
}
