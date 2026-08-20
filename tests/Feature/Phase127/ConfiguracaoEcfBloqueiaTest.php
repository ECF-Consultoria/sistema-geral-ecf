<?php

namespace Tests\Feature\Phase127;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Services\Clicksign\ContratoClicksignService;
use App\Services\Contratos\ContratoDadosMinimosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 127 — regressão do achado do GATE (plano 127-07), medido contra o
 * sandbox REAL em 12/08/2026.
 *
 * O fluxo passava pela checagem de dados mínimos (que só olha a EMPRESA),
 * criava o envelope, criava o documento, e **só então** a API recusava o 1º
 * signatário com `email - não pode ficar em branco`, porque
 * `config('services.clicksign.signatarios_ecf')` nasce com as 3 entradas
 * presentes e VAZIAS (as chaves do `.env.example` vêm sem valor).
 *
 * Custo do buraco: 3 chamadas queimadas da janela MEDIDA de 20/min, mais o
 * rollback, por um dado sabível sem nenhuma requisição — exatamente o que o
 * Goal da fase proíbe ("nunca gasta uma chamada HTTP com dado que já sabia
 * estar incompleto").
 *
 * ⚠️ Estado padrão, não caso de borda: qualquer ambiente recém-configurado
 * (incluindo produção antes do cutover da Fase 132) está assim.
 */
class ConfiguracaoEcfBloqueiaTest extends TestCase
{
    use RefreshDatabase;

    /** Os 3 signatários da D-08, preenchidos — o estado "configurado". */
    private function signatariosOk(): array
    {
        return [
            ['nome' => 'Sócio Um', 'email' => 'socio1@example.com', 'papel' => 'contratada'],
            ['nome' => 'Sócio Dois', 'email' => 'socio2@example.com', 'papel' => 'contratada'],
            ['nome' => 'Comercial', 'email' => 'comercial@example.com', 'papel' => 'testemunha'],
        ];
    }

    private function empresaCompleta(): Company
    {
        $servico = Servico::query()->first() ?? Servico::factory()->create();
        $servico->forceFill(['clicksign_template_id' => '00000000-0000-4000-8000-000000000008'])->save();

        $company = Company::factory()->create([
            'cnpj'          => '11.222.333/0001-81',
            'email_cliente' => 'cliente@example.com',
            'nome_contato'  => 'Contato de Teste',
            // Quick 260819-guy — obrigatórios desde 2026-08-19.
            'razao_social'  => 'Contato de Teste LTDA',
            'endereco'      => 'Rua de Teste, 123',
        ]);

        ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'data_contratacao' => '2026-08-01',
            'valor_contratado' => 3000.00,
            'ativo'            => true,
            // Quick 260819-guy — obrigatórios desde 2026-08-19.
            'data_primeira_parcela' => '2026-09-05',
            'dia_vencimento'        => 5,
        ]);

        return $company;
    }

    #[Test]
    public function signatarios_da_ecf_vazios_sao_reportados_como_problema_de_configuracao(): void
    {
        // O estado que o `.env.example` produz: entradas presentes, valores vazios.
        config(['services.clicksign.signatarios_ecf' => [
            ['nome' => '', 'email' => '', 'papel' => 'contratada'],
            ['nome' => '', 'email' => '', 'papel' => 'contratada'],
            ['nome' => '', 'email' => '', 'papel' => 'testemunha'],
        ]]);

        $problemas = app(ContratoDadosMinimosService::class)->faltantesDaConfiguracaoEcf();

        // 3 signatários × (nome + e-mail) = 6 problemas.
        $this->assertCount(6, $problemas);
        $this->assertStringContainsString('ECF', $problemas[0]);
    }

    /**
     * UM signatário só é configuração VÁLIDA.
     *
     * Decisão da diretoria em 2026-08-20: pela ECF assina só o Thiago Messina;
     * o sócio e a testemunha saíram, e o rodapé do `.docx` na Clicksign foi
     * editado junto. Antes disso esta checagem exigia as TRÊS entradas, então
     * apagar `CLICKSIGN_SIG2_*`/`SIG3_*` do `.env` não removia signatário —
     * travava a emissão de contrato para TODAS as empresas com "Signatários da
     * ECF não configurados". Era a armadilha desta mudança.
     */
    #[Test]
    public function um_unico_signatario_e_configuracao_valida(): void
    {
        config(['services.clicksign.signatarios_ecf' => [
            ['nome' => 'Thiago Messina', 'email' => 'thiago@example.com', 'papel' => 'contratada'],
        ]]);

        $this->assertSame([], app(ContratoDadosMinimosService::class)->faltantesDaConfiguracaoEcf());
    }

    /** Zero signatários reprova — ninguém assinaria pela ECF. */
    #[Test]
    public function nenhum_signatario_reprova(): void
    {
        config(['services.clicksign.signatarios_ecf' => []]);

        $problemas = app(ContratoDadosMinimosService::class)->faltantesDaConfiguracaoEcf();

        $this->assertCount(1, $problemas);
        $this->assertStringContainsString('Nenhum signatário da ECF', $problemas[0]);
    }

    /**
     * ⚠️ O que NÃO afrouxou junto com a mudança acima: entrada preenchida pela
     * METADE continua reprovando. Meio preenchido é erro de digitação, não
     * remoção intencional — e é exatamente o caso que o gate do plano 127-07
     * pegou contra o sandbox real (o envelope e o documento nasciam, e só então
     * a API recusava o signatário com `email - não pode ficar em branco`).
     * Só o slot com os DOIS vazios significa "não usado", e esse o
     * `config/services.php` descarta antes de chegar aqui.
     */
    #[Test]
    public function signatario_preenchido_pela_metade_continua_reprovando(): void
    {
        config(['services.clicksign.signatarios_ecf' => [
            ['nome' => 'Thiago Messina', 'email' => 'thiago@example.com', 'papel' => 'contratada'],
            ['nome' => 'Sobrou Do Env', 'email' => '', 'papel' => 'contratada'],
        ]]);

        $problemas = app(ContratoDadosMinimosService::class)->faltantesDaConfiguracaoEcf();

        $this->assertCount(1, $problemas);
        $this->assertStringContainsString('E-mail do signatário da ECF', $problemas[0]);
    }

    #[Test]
    public function configuracao_completa_nao_reporta_problema(): void
    {
        config(['services.clicksign.signatarios_ecf' => $this->signatariosOk()]);

        $this->assertSame([], app(ContratoDadosMinimosService::class)->faltantesDaConfiguracaoEcf());
    }

    #[Test]
    public function e_mail_de_signatario_da_ecf_com_formato_invalido_e_pego(): void
    {
        config(['services.clicksign.signatarios_ecf' => [
            ['nome' => 'Sócio Um', 'email' => 'nao-e-email', 'papel' => 'contratada'],
        ]]);

        $problemas = app(ContratoDadosMinimosService::class)->faltantesDaConfiguracaoEcf();

        $this->assertCount(1, $problemas);
        $this->assertStringContainsString('formato inválido', $problemas[0]);
    }

    /**
     * O teste que prova o Goal da fase: empresa PERFEITA, configuração da ECF
     * vazia → **zero requisições HTTP**. Antes do fix, aqui saíam 3.
     */
    #[Test]
    public function config_da_ecf_vazia_bloqueia_SEM_gastar_nenhuma_chamada_http(): void
    {
        Http::fake();

        config(['services.clicksign.signatarios_ecf' => [
            ['nome' => '', 'email' => '', 'papel' => 'contratada'],
        ]]);

        $company = $this->empresaCompleta();

        $r = app(ContratoClicksignService::class)->iniciarParaEmpresa($company);

        $this->assertFalse($r['ok']);
        $this->assertNotEmpty($r['configuracao'] ?? []);
        $this->assertSame([], $r['criados']);
        $this->assertSame([], $r['faltando'], 'A empresa está completa — o problema é da ECF, não dela.');

        Http::assertNothingSent();

        $this->assertDatabaseCount('contrato_assinaturas', 0);
    }
}
