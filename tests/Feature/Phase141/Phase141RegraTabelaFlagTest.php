<?php

namespace Tests\Feature\Phase141;

use App\Models\Configuracao;
use App\Services\Fechamento\FechamentoRegraTabela;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 141, Plano 02, Tarefa 1 — interruptor `fechamento_tabela_por_empresa_ativa`.
 *
 * Prova o contrato do RESUMO: nasce desligada, só '1' liga, e a leitura é
 * memoizada (uma consulta por instância, não uma por chamada).
 */
class Phase141RegraTabelaFlagTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sem_nenhuma_linha_em_configuracoes_a_flag_esta_desligada(): void
    {
        $regra = new FechamentoRegraTabela();

        $this->assertFalse($regra->ativa());
    }

    #[Test]
    public function com_valor_1_a_flag_esta_ligada(): void
    {
        Configuracao::set(FechamentoRegraTabela::CHAVE, '1');

        $regra = new FechamentoRegraTabela();

        $this->assertTrue($regra->ativa());
    }

    #[Test]
    public function qualquer_valor_diferente_de_1_e_desligado(): void
    {
        foreach (['true', '', '0'] as $valor) {
            Configuracao::set(FechamentoRegraTabela::CHAVE, $valor);

            $regra = new FechamentoRegraTabela();

            $this->assertFalse($regra->ativa(), "Valor '{$valor}' deveria ser lido como desligado");
        }
    }

    #[Test]
    public function ativa_consulta_o_banco_uma_unica_vez_por_instancia(): void
    {
        Configuracao::set(FechamentoRegraTabela::CHAVE, '1');

        $regra = new FechamentoRegraTabela();

        DB::enableQueryLog();
        for ($i = 0; $i < 100; $i++) {
            $regra->ativa();
        }
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $consultasConfiguracoes = collect($log)->filter(
            fn ($q) => str_contains($q['query'], 'configuracoes')
        );

        $this->assertCount(1, $consultasConfiguracoes, 'ativa() deve memoizar — 100 chamadas não podem gerar 100 consultas.');
    }

    #[Test]
    public function esquecer_limpa_a_memoria_para_a_proxima_leitura_ver_o_valor_novo(): void
    {
        $regra = new FechamentoRegraTabela();

        $this->assertFalse($regra->ativa());

        Configuracao::set(FechamentoRegraTabela::CHAVE, '1');
        $this->assertFalse($regra->ativa(), 'Sem esquecer(), a leitura antiga ainda está memoizada.');

        $regra->esquecer();
        $this->assertTrue($regra->ativa(), 'Depois de esquecer(), a leitura deve refletir o novo valor.');
    }
}
