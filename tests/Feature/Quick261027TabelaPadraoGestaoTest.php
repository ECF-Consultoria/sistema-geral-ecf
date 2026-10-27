<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\Servico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 261027-m1y — migration que troca a tabela padrão de `Gestão` e congela
 * a tabela antiga nos contratos em voo. Tudo conferido por reconsulta ao banco.
 */
class Quick261027TabelaPadraoGestaoTest extends TestCase
{
    use RefreshDatabase;

    private const ANTIGA = [
        [1, 499_999.99, 3_000.00, false],
        [2, 999_999.99, 4_500.00, false],
        [3, 1_999_999.99, 6_000.00, false],
        [4, 2_999_999.99, 7_500.00, false],
        [5, 3_999_999.99, 9_000.00, false],
        [6, 4_999_999.99, 10_500.00, false],
        [7, null, 12_000.00, true],
    ];

    private const SHOPEE = [
        [1, 50_000.00, 1_500.00, false],
        [2, 150_000.00, 2_000.00, false],
    ];

    private function servico(string $nome, array $faixas): Servico
    {
        $s = Servico::where('nome', $nome)->first() ?? Servico::create([
            'nome'          => $nome,
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
        ]);

        DB::table('servico_faixas_faturamento')->where('servico_id', $s->id)->delete();

        foreach ($faixas as [$ordem, $limite, $valor, $piso]) {
            DB::table('servico_faixas_faturamento')->insert([
                'servico_id' => $s->id, 'ordem' => $ordem, 'limite_superior' => $limite,
                'valor' => $valor, 'valor_e_piso' => $piso, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $s;
    }

    private function faixasNoBanco(int $servicoId): array
    {
        return DB::table('servico_faixas_faturamento')->where('servico_id', $servicoId)->orderBy('ordem')->get()
            ->map(fn ($f) => [(int) $f->ordem, $f->limite_superior === null ? null : (float) $f->limite_superior, (float) $f->valor, (bool) $f->valor_e_piso])
            ->all();
    }

    private function migrar(): void
    {
        (require database_path('migrations/2026_10_27_100001_atualizar_tabela_padrao_gestao_ml.php'))->up();
    }

    private function contrato(Servico $servico, string $status, ?string $envelope): ContratoAssinatura
    {
        return ContratoAssinatura::factory()->create([
            'company_id'            => Company::factory()->create()->id,
            'servico_id'            => $servico->id,
            'status'                => $status,
            'clicksign_envelope_id' => $envelope,
        ]);
    }

    #[Test]
    public function troca_a_tabela_do_servico_gestao_e_e_idempotente(): void
    {
        $gestao = $this->servico('Gestão', self::ANTIGA);

        $this->migrar();
        $this->migrar();

        $esperado = [
            [1, 500_000.00, 3_700.00, false],
            [2, 1_000_000.00, 5_500.00, false],
            [3, 2_000_000.00, 7_400.00, false],
            [4, 3_000_000.00, 9_250.00, false],
            [5, 5_000_000.00, 11_100.00, false],
            [6, null, 13_500.00, true],
        ];

        $this->assertSame($esperado, $this->faixasNoBanco($gestao->id));
    }

    #[Test]
    public function nao_toca_brigada_shopee_nem_tabelas_de_empresa(): void
    {
        $this->servico('Gestão', self::ANTIGA);
        $brigada = $this->servico('Brigada', self::ANTIGA);
        $shopee  = $this->servico('Gestão de ADS Shopee', self::SHOPEE);

        $company = Company::factory()->create();
        foreach ([EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO, EmpresaFaixaFaturamento::ORIGEM_MANUAL] as $i => $origem) {
            EmpresaFaixaFaturamento::create([
                'company_id' => $company->id, 'ordem' => $i + 1, 'limite_superior' => $i === 0 ? 100.00 : null,
                'valor' => 777.00 + $i, 'valor_e_piso' => $i === 1, 'origem' => $origem,
            ]);
        }
        $empresaAntes = DB::table('empresa_faixas_faturamento')->orderBy('id')->get()->toArray();

        $this->migrar();

        $this->assertSame(self::ANTIGA, $this->faixasNoBanco($brigada->id));
        $this->assertSame(self::SHOPEE, $this->faixasNoBanco($shopee->id));
        $this->assertEquals($empresaAntes, DB::table('empresa_faixas_faturamento')->orderBy('id')->get()->toArray());
    }

    #[Test]
    public function congela_a_tabela_antiga_so_nos_contratos_em_voo_de_gestao(): void
    {
        $gestao  = $this->servico('Gestão', self::ANTIGA);
        $brigada = $this->servico('Brigada', self::ANTIGA);

        $rascunho   = $this->contrato($gestao, ContratoAssinatura::STATUS_RASCUNHO, 'env-1');
        $aguardando = $this->contrato($gestao, ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS, 'env-2');
        $assinado   = $this->contrato($gestao, ContratoAssinatura::STATUS_ASSINADO, 'env-3');
        $cancelado  = $this->contrato($gestao, ContratoAssinatura::STATUS_CANCELADO, 'env-4');
        $semEnvelope = $this->contrato($gestao, ContratoAssinatura::STATUS_RASCUNHO, null);
        $outro      = $this->contrato($brigada, ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS, 'env-5');

        $this->migrar();
        $this->migrar();

        foreach ([$rascunho, $aguardando] as $c) {
            $snap = json_decode(DB::table('contrato_assinaturas')->where('id', $c->id)->value('tabela_progressiva_snapshot'), true);
            $this->assertSame('servico', $snap['origem']);
            $this->assertSame($gestao->id, $snap['servico_id']);
            $this->assertCount(7, $snap['faixas']);
            $this->assertSame(3000.0, (float) $snap['faixas'][0]['valor']);
            $this->assertTrue($snap['faixas'][6]['valor_e_piso']);
            $this->assertNull($snap['faixas'][6]['limite_superior']);
        }

        foreach ([$assinado, $cancelado, $semEnvelope, $outro] as $c) {
            $this->assertNull(DB::table('contrato_assinaturas')->where('id', $c->id)->value('tabela_progressiva_snapshot'));
        }
    }

    #[Test]
    public function sem_servico_gestao_nao_faz_nada_e_nao_lanca(): void
    {
        Servico::where('nome', 'Gestão')->get()->each(function ($s) {
            DB::table('servico_faixas_faturamento')->where('servico_id', $s->id)->delete();
            DB::table('servicos')->where('id', $s->id)->update(['nome' => 'Outro serviço']);
        });

        $this->migrar();

        $this->assertSame(0, DB::table('servico_faixas_faturamento')->where('valor', 13_500)->count());
    }
}
