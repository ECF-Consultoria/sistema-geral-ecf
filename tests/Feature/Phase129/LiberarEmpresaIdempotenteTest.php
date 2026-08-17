<?php

namespace Tests\Feature\Phase129;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\ContratoAssinatura;
use App\Models\ContratoLiberacao;
use App\Models\MlbEmpresa;
use App\Models\Servico;
use App\Services\Operacional\EmpresaOperacionalRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 129 Plano 04 (Task 3) — prova `EmpresaOperacionalRouter::liberarEmpresa()`:
 * idempotência do EFEITO (CLICK-04), D-02 (uma ficha só por empresa mesmo
 * liberando serviços em semanas diferentes), D-03 (liberação gravada mesmo
 * sem ficha) e a independência do interruptor de emergência (T-129-25).
 */
class LiberarEmpresaIdempotenteTest extends TestCase
{
    use RefreshDatabase;

    private function router(): EmpresaOperacionalRouter
    {
        return app(EmpresaOperacionalRouter::class);
    }

    private function servico(string $nome): Servico
    {
        return Servico::create([
            'nome'          => $nome,
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
        ]);
    }

    private function company(string $nome = 'Empresa Teste Liberacao'): Company
    {
        return Company::factory()->create(['name' => $nome]);
    }

    #[Test]
    public function liberar_duas_vezes_o_mesmo_par_empresa_servico_cria_uma_liberacao_e_uma_ficha_so(): void
    {
        $company = $this->company();
        $polos   = $this->servico('Polos');

        $primeira  = $this->router()->liberarEmpresa($company, $polos, 'webhook');
        $segunda   = $this->router()->liberarEmpresa($company, $polos, 'webhook');

        $this->assertSame($primeira->id, $segunda->id);
        $this->assertSame(1, ContratoLiberacao::where('company_id', $company->id)->where('servico_id', $polos->id)->count());
        $this->assertSame(1, MlbEmpresa::where('company_id', $company->id)->count());
    }

    #[Test]
    public function servico_que_nao_gera_ficha_grava_liberacao_com_gerou_ficha_falso(): void
    {
        $company = $this->company();
        $gestao  = $this->servico('Gestão');

        $liberacao = $this->router()->liberarEmpresa($company, $gestao, 'webhook');

        $this->assertFalse($liberacao->gerou_ficha);
        $this->assertSame(0, MlbEmpresa::where('company_id', $company->id)->count());
        $this->assertNotNull(ContratoLiberacao::find($liberacao->id), 'A liberacao precisa constar mesmo sem ficha (D-03)');
    }

    #[Test]
    public function liberar_dois_servicos_em_chamadas_separadas_no_tempo_cria_uma_mlbempresa_so(): void
    {
        $company    = $this->company();
        $assessoria = $this->servico('Assessoria');
        $incubadora = $this->servico('Incubadora');

        $liberacaoAssessoria = $this->router()->liberarEmpresa($company, $assessoria, 'webhook');
        $liberacaoIncubadora = $this->router()->liberarEmpresa($company, $incubadora, 'webhook');

        $this->assertNotSame($liberacaoAssessoria->id, $liberacaoIncubadora->id);
        $this->assertSame(2, ContratoLiberacao::where('company_id', $company->id)->count());
        $this->assertSame(
            1,
            MlbEmpresa::where('company_id', $company->id)->count(),
            'D-02: uma ficha so por empresa, mesmo com dois servicos liberados em chamadas separadas'
        );
    }

    #[Test]
    public function interruptor_de_emergencia_nao_trava_liberarempresa_mas_continua_travando_rotearservico(): void
    {
        Configuracao::set(EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '1');

        $company = $this->company();
        $polos   = $this->servico('Polos');

        $liberacao = $this->router()->liberarEmpresa($company, $polos, 'webhook');

        $this->assertTrue($liberacao->gerou_ficha, 'liberarEmpresa() contorna o interruptor por desenho (T-129-25)');
        $this->assertSame(1, MlbEmpresa::where('company_id', $company->id)->count());

        // Mesma empresa, mesmo interruptor ligado — rotearServico() (caminho
        // PRÉ-contrato) continua bloqueado. Não cria uma segunda ficha nem
        // para um serviço diferente.
        $assessoria = $this->servico('Assessoria');
        $this->router()->rotearServico($company, $assessoria->nome);

        $this->assertSame(
            1,
            MlbEmpresa::where('company_id', $company->id)->count(),
            'rotearServico() continua bloqueado pelo interruptor mesmo apos liberarEmpresa() ter contornado'
        );
    }

    #[Test]
    public function contrato_assinatura_tem_liberado_em_preenchido_quando_passado(): void
    {
        $company  = $this->company();
        $polos    = $this->servico('Polos');
        $contrato = ContratoAssinatura::factory()->comSnapshot()->create([
            'company_id' => $company->id,
            'servico_id' => $polos->id,
            'status'     => ContratoAssinatura::STATUS_ASSINADO,
        ]);

        $this->assertNull($contrato->liberado_em);

        $this->router()->liberarEmpresa($company, $polos, 'webhook', contrato: $contrato);

        $contrato->refresh();
        $this->assertNotNull($contrato->liberado_em);
    }

    #[Test]
    public function liberacao_ja_existente_e_devolvida_sem_excecao_vazar(): void
    {
        // Simula a corrida (webhook + liberação manual simultâneos): a linha
        // já existe quando `liberarEmpresa()` é chamado — impraticável
        // forçar paralelismo real em PHPUnit, então inserimos manualmente
        // ANTES da chamada (mesma prática do precedente Fase 127,
        // `IdempotenciaContratoTest::a_garantia_real_e_a_constraint_nao_o_guard_de_leitura`).
        $company = $this->company();
        $polos   = $this->servico('Polos');

        $preExistente = ContratoLiberacao::create([
            'company_id'  => $company->id,
            'servico_id'  => $polos->id,
            'via'         => ContratoLiberacao::VIA_MANUAL,
            'liberado_em' => now()->subMinute(),
        ]);

        $resultado = $this->router()->liberarEmpresa($company, $polos, 'webhook');

        $this->assertSame($preExistente->id, $resultado->id);
        $this->assertSame(1, ContratoLiberacao::where('company_id', $company->id)->where('servico_id', $polos->id)->count());
        // Não deve ter roteado de novo — a liberação pré-existente já era
        // `manual` e nenhuma MlbEmpresa deveria nascer aqui.
        $this->assertSame(0, MlbEmpresa::where('company_id', $company->id)->count());
    }

    #[Test]
    public function o_catch_reconhece_a_violacao_pelo_sqlstate_nao_pelo_errorinfo_do_mysql(): void
    {
        $caminho = app_path('Services/Operacional/EmpresaOperacionalRouter.php');
        $this->assertFileExists($caminho);

        $linhas = file($caminho);
        $codigoSemComentarios = collect($linhas)
            ->reject(fn (string $linha) => preg_match('/^\s*[\/*]/', $linha) === 1)
            ->implode('');

        $this->assertStringContainsString('23000', $codigoSemComentarios);
        $this->assertStringNotContainsString('errorInfo', $codigoSemComentarios);
        $this->assertStringContainsString('QueryException', $codigoSemComentarios);
    }
}
