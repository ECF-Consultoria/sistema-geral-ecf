<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\Servico;
use App\Models\User;
use App\Services\Contratos\ContratoDadosMinimosService;
use App\Services\Contratos\TabelaDoContratoService;
use App\Services\Fechamento\TabelaDeContratoAssinadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 261027-m1y — assinatura grava a tabela IMPRESSA; pré-check na tela; .docx v6.
 */
class Quick261027TabelaAssinadaTest extends TestCase
{
    use RefreshDatabase;

    private const NOVA = [
        [1, 500_000.00, 3_700.00, false],
        [2, 1_000_000.00, 5_500.00, false],
        [3, null, 13_500.00, true],
    ];

    private function servico(string $nome, array $faixas = self::NOVA): Servico
    {
        $s = Servico::where('nome', $nome)->first() ?? Servico::create([
            'nome' => $nome, 'valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo' => true, 'setor' => Servico::SETOR_PERFORMANCE,
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

    private function assinado(Company $company, Servico $servico, ?array $snapshotTabela): ContratoAssinatura
    {
        return ContratoAssinatura::factory()->assinado()->create([
            'company_id'                  => $company->id,
            'servico_id'                  => $servico->id,
            'servicos_snapshot'           => [[
                'servico' => $servico->nome, 'valor_contratado' => 1500.0,
                'data_contratacao' => '2026-01-01', 'data_vencimento' => '2027-01-01',
            ]],
            'tabela_progressiva_snapshot' => $snapshotTabela,
        ]);
    }

    private function snapshotImpresso(int $servicoId): array
    {
        return [
            'origem'       => 'servico',
            'servico_id'   => $servicoId,
            'congelada_em' => '2026-10-20 10:00:00',
            'faixas'       => [
                ['ordem' => 1, 'limite_superior' => 100.0, 'valor' => 111.0, 'valor_e_piso' => false],
                ['ordem' => 2, 'limite_superior' => null, 'valor' => 222.0, 'valor_e_piso' => true],
            ],
        ];
    }

    private function tabelaEmpresa(Company $c): array
    {
        return DB::table('empresa_faixas_faturamento')->where('company_id', $c->id)->orderBy('ordem')->get()
            ->map(fn ($f) => [(int) $f->ordem, (float) $f->valor, $f->origem, $f->servico_origem_id !== null ? (int) $f->servico_origem_id : null])
            ->all();
    }

    #[Test]
    public function assinado_com_snapshot_grava_a_tabela_impressa_e_nao_a_do_servico_ao_vivo(): void
    {
        $gestao  = $this->servico('Gestão');
        $company = Company::factory()->create();
        $contrato = $this->assinado($company, $gestao, $this->snapshotImpresso($gestao->id));

        // A tabela do serviço muda depois da geração: não pode vazar para a empresa.
        DB::table('servico_faixas_faturamento')->where('servico_id', $gestao->id)->update(['valor' => 9999.00]);

        $r = app(TabelaDeContratoAssinadoService::class)->aplicar($contrato);

        $this->assertSame(TabelaDeContratoAssinadoService::MOTIVO_GRAVOU, $r['motivo']);
        $this->assertSame([
            [1, 111.0, EmpresaFaixaFaturamento::ORIGEM_CONTRATO, $gestao->id],
            [2, 222.0, EmpresaFaixaFaturamento::ORIGEM_CONTRATO, $gestao->id],
        ], $this->tabelaEmpresa($company));
    }

    #[Test]
    public function snapshot_de_tabela_manual_sem_servico_usa_o_servico_do_contrato(): void
    {
        $gestao  = $this->servico('Gestão');
        $company = Company::factory()->create();
        $snap    = $this->snapshotImpresso($gestao->id);
        $snap['origem']     = 'manual';
        $snap['servico_id'] = null;

        app(TabelaDeContratoAssinadoService::class)->aplicar($this->assinado($company, $gestao, $snap));

        $this->assertSame($gestao->id, $this->tabelaEmpresa($company)[0][3]);
    }

    #[Test]
    public function sem_snapshot_o_comportamento_antigo_segue_com_a_tabela_do_servico(): void
    {
        $gestao  = $this->servico('Gestão');
        $company = Company::factory()->create();
        $contrato = $this->assinado($company, $gestao, null);

        app(TabelaDeContratoAssinadoService::class)->aplicar($contrato);

        $this->assertSame([
            [1, 3700.0, EmpresaFaixaFaturamento::ORIGEM_CONTRATO, $gestao->id],
            [2, 5500.0, EmpresaFaixaFaturamento::ORIGEM_CONTRATO, $gestao->id],
            [3, 13500.0, EmpresaFaixaFaturamento::ORIGEM_CONTRATO, $gestao->id],
        ], $this->tabelaEmpresa($company));
    }

    #[Test]
    public function tabela_manual_ou_de_contrato_ja_existente_vence_com_ou_sem_snapshot(): void
    {
        $gestao = $this->servico('Gestão');

        foreach ([EmpresaFaixaFaturamento::ORIGEM_MANUAL, EmpresaFaixaFaturamento::ORIGEM_CONTRATO] as $origem) {
            foreach ([null, $this->snapshotImpresso($gestao->id)] as $snap) {
                $company = Company::factory()->create();
                EmpresaFaixaFaturamento::create([
                    'company_id' => $company->id, 'ordem' => 1, 'limite_superior' => null,
                    'valor' => 777.00, 'valor_e_piso' => true, 'origem' => $origem,
                ]);

                $r = app(TabelaDeContratoAssinadoService::class)->aplicar($this->assinado($company, $gestao, $snap));

                $this->assertSame(TabelaDeContratoAssinadoService::MOTIVO_JA_CONFIRMADA, $r['motivo']);
                $this->assertSame([[1, 777.0, $origem, null]], $this->tabelaEmpresa($company));
            }
        }
    }

    #[Test]
    public function salvar_pela_ficha_grava_origem_manual(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $company = Company::factory()->create();

        $this->actingAs($admin)->post(route('admin.contratos.tabela.salvar', $company), [
            'faixas' => [
                ['ordem' => 1, 'limite_superior' => 300_000.00, 'valor' => 1_500.00, 'valor_e_piso' => false],
                ['ordem' => 2, 'limite_superior' => null, 'valor' => 3_000.00, 'valor_e_piso' => true],
            ],
        ])->assertRedirect();

        $origens = DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->pluck('origem')->unique()->all();
        $this->assertSame([EmpresaFaixaFaturamento::ORIGEM_MANUAL], $origens);
    }

    private function companyCompleta(): Company
    {
        return Company::factory()->create([
            'email_cliente' => 'cliente@empresa.com.br', 'cnpj' => '12.345.678/0001-95',
            'nome_contato' => 'Fulano de Tal', 'razao_social' => 'Empresa de Teste LTDA',
            'endereco' => 'Rua de Teste, 123', 'bairro' => 'Bairro', 'cidade' => 'Cidade',
            'estado' => 'TS', 'cep' => '00000-000',
        ]);
    }

    private function contratoAtivo(Company $company, Servico $servico): void
    {
        ContratoServico::create([
            'company_id' => $company->id, 'servico_id' => $servico->id, 'valor_contratado' => 100,
            'data_contratacao' => '2026-01-10', 'ativo' => true,
            'data_primeira_parcela' => '2026-02-05', 'dia_vencimento' => 5,
        ]);
    }

    #[Test]
    public function pre_check_aponta_tabela_com_mais_de_7_faixas_ausente_e_ignora_outros_servicos(): void
    {
        $gestao = $this->servico('Gestão');
        $brigada = $this->servico('Brigada');
        $service = new ContratoDadosMinimosService();

        // Tabela da empresa com 8 faixas → formato.
        $c8 = $this->companyCompleta();
        $this->contratoAtivo($c8, $gestao);
        for ($i = 1; $i <= 8; $i++) {
            EmpresaFaixaFaturamento::create([
                'company_id' => $c8->id, 'ordem' => $i, 'limite_superior' => $i < 8 ? $i * 1000.0 : null,
                'valor' => 100.0 * $i, 'valor_e_piso' => $i === 8, 'origem' => EmpresaFaixaFaturamento::ORIGEM_MANUAL,
            ]);
        }
        $item = collect($service->faltantes($c8->fresh()))->firstWhere('campo', 'tabela_progressiva');
        $this->assertNotNull($item);
        $this->assertSame('formato', $item['motivo']);
        $this->assertSame($gestao->id, $item['servico_id']);

        // Gestão sem nenhuma faixa no serviço e sem tabela da empresa → ausente.
        DB::table('servico_faixas_faturamento')->where('servico_id', $gestao->id)->delete();
        $cAus = $this->companyCompleta();
        $this->contratoAtivo($cAus, $gestao);
        $item = collect($service->faltantes($cAus->fresh()))->firstWhere('campo', 'tabela_progressiva');
        $this->assertSame('ausente', $item['motivo']);

        // Só Brigada não ganha item.
        $cBri = $this->companyCompleta();
        $this->contratoAtivo($cBri, $brigada);
        $this->assertNull(collect($service->faltantes($cBri->fresh()))->firstWhere('campo', 'tabela_progressiva'));
    }

    #[Test]
    public function docx_v6_tem_as_14_variaveis_inteiras_e_preserva_as_antigas(): void
    {
        $caminho = base_path('modelo-contrato-gestao-v6-TABELA-VARIAVEL.docx');
        if (! file_exists($caminho)) {
            $this->markTestSkipped('.docx v6 ausente neste checkout.');
        }

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($caminho) === true);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', $xml, $m);
        $textos = $m[1];

        for ($n = 1; $n <= 7; $n++) {
            $this->assertContains('{{faixa_'.$n.'_faturamento}}', $textos);
            $this->assertContains('{{faixa_'.$n.'_valor}}', $textos);
        }

        $texto = implode('', $textos);
        foreach (['razao_social', 'cnpj', 'endereco', 'bairro', 'cidade', 'estado', 'cep', 'plataformas', 'plano_parcelas', 'dia_vencimento', 'data_assinatura'] as $v) {
            $this->assertStringContainsString('{{'.$v.'}}', $texto, "Variável {$v} sumiu do v6.");
        }
    }
}
