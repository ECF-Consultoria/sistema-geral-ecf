<?php

namespace Tests\Feature\Phase133;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\Servico;
use App\Services\Operacional\EmpresaOperacionalRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 133 Plano 01 (D-02) — prova a exceção por serviço dentro de
 * `EmpresaOperacionalRouter::rotear()`: com o interruptor de emergência
 * ligado, um serviço isento de contrato (Polos) continua sendo roteado
 * normalmente, enquanto um serviço que exige contrato (Assessoria) fica
 * retido. A decisão é tomada POR SERVIÇO, dentro do laço — nunca por
 * empresa inteira (a alternativa foi rejeitada pelo usuário, ver
 * 133-CONTEXT.md D-02).
 *
 * ⚠️ A fase inteira depende de o "Polos" deste cenário ser REALMENTE
 * isento. O default de schema de `servicos.exige_contrato`
 * (migration `2026_08_13_100001_add_exige_contrato_to_servicos_table.php`)
 * é `true` — e `firstOrCreate` IGNORA o array de atributos quando a linha
 * já existe (o seed do catálogo roda em todo `RefreshDatabase`). Por isso
 * o `setUp()` abaixo força os dois valores explicitamente com `update()`
 * logo após o `firstOrCreate`, e o primeiro teste desta classe é uma
 * sentinela: se a fixture estiver errada, ele falha alto — a suíte nunca
 * pode "passar" provando o contrário do que promete.
 */
class RoteamentoExcecaoServicoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Polos', 'Assessoria'] as $nome) {
            Servico::firstOrCreate(
                ['nome' => $nome],
                ['valor_padrao' => 0, 'tipo_cobranca' => 'mensal', 'ativo' => true],
            );
        }

        // O `update()` logo após o `firstOrCreate` acima é DELIBERADO:
        // `firstOrCreate` ignora o array de atributos quando a linha já
        // existe, e o seed real do catálogo roda em todo `RefreshDatabase`.
        // Declarar o valor no array de criação NÃO garante nada — só o
        // `update()` explícito garante o cenário que este arquivo promete.
        Servico::where('nome', 'Polos')->update(['exige_contrato' => false]);
        Servico::where('nome', 'Assessoria')->update(['exige_contrato' => true]);
    }

    private function criarEmpresa(string $nome = 'Empresa Teste Exceção'): Company
    {
        return Company::create(['name' => $nome]);
    }

    private function router(): EmpresaOperacionalRouter
    {
        return app(EmpresaOperacionalRouter::class);
    }

    /**
     * Sentinela de fixture. Se "Polos" não estiver isento e "Assessoria" não
     * estiver exigindo contrato neste cenário de teste, todo o resto do
     * arquivo é inconclusivo — este teste precisa falhar primeiro, com
     * mensagem explícita, em vez de deixar os outros "passarem" provando o
     * contrário do que a fase promete.
     */
    public function test_fixture_declara_polos_isento_e_assessoria_exigindo_contrato(): void
    {
        $polos      = Servico::where('nome', 'Polos')->firstOrFail();
        $assessoria = Servico::where('nome', 'Assessoria')->firstOrFail();

        $this->assertFalse(
            $polos->exigeContrato(),
            'Fixture errada: "Polos" precisa nascer ISENTO de contrato neste cenário — senão o SC 2b não pode ser provado.',
        );
        $this->assertTrue(
            $assessoria->exigeContrato(),
            'Fixture errada: "Assessoria" precisa nascer EXIGINDO contrato neste cenário — senão a retenção não pode ser provada.',
        );
    }

    /**
     * Chave ligada + `rotearCadastro()` com Polos → a ficha nasce mesmo
     * assim (SC 2b, primeiro ponto de entrada).
     */
    public function test_interruptor_ligado_nao_impede_o_roteamento_do_cadastro_de_polos(): void
    {
        Configuracao::set(EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '1');

        $company = $this->criarEmpresa();

        $this->router()->rotearCadastro($company, ['Polos']);

        $mlbEmp = MlbEmpresa::where('company_id', $company->id)->where('tipo', 'POLO')->first();
        $this->assertNotNull($mlbEmp, 'Com a chave ligada, Polos deveria ser roteado normalmente (SC 2b).');
        $this->assertSame('POLOS', $mlbEmp->projeto);

        $this->assertNotNull(
            MlbImplementacao::where('empresa_id', $mlbEmp->id)->first(),
            'Com a chave ligada, a MlbImplementacao de Polos deveria ser criada normalmente.',
        );
    }

    /**
     * Chave ligada + `rotearServico()` com Polos → mesmo resultado pelo
     * segundo ponto de entrada (webhook HubSpot).
     */
    public function test_interruptor_ligado_nao_impede_o_roteamento_por_servico_de_polos(): void
    {
        Configuracao::set(EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '1');

        $company = $this->criarEmpresa();

        $this->router()->rotearServico($company, 'Polos');

        $mlbEmp = MlbEmpresa::where('company_id', $company->id)->where('tipo', 'POLO')->first();
        $this->assertNotNull($mlbEmp, 'Com a chave ligada, Polos deveria ser roteado normalmente pelo caminho rotearServico() (SC 2b).');

        $this->assertNotNull(
            MlbImplementacao::where('empresa_id', $mlbEmp->id)->first(),
            'Com a chave ligada, a MlbImplementacao de Polos deveria ser criada normalmente pelo caminho rotearServico().',
        );
    }

    /**
     * Chave ligada + Assessoria → retido, nenhuma ficha nasce (comportamento
     * atual, continua valendo).
     */
    public function test_interruptor_ligado_retem_servico_que_exige_contrato(): void
    {
        Configuracao::set(EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '1');

        $company = $this->criarEmpresa();

        $this->router()->rotearCadastro($company, ['Assessoria']);

        $this->assertSame(0, MlbEmpresa::where('company_id', $company->id)->count(), 'Assessoria exige contrato — nenhuma ficha deveria nascer com a chave ligada.');
        $this->assertSame(0, MlbImplementacao::count());
    }

    /**
     * Empresa com Polos + Assessoria na mesma submissão: só o Polos entra na
     * operação, a Assessoria fica esperando (D-02) — a alternativa de
     * prender a empresa inteira foi rejeitada.
     */
    public function test_com_polos_e_assessoria_apenas_polos_e_roteado(): void
    {
        Configuracao::set(EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '1');

        $company = $this->criarEmpresa();

        $this->router()->rotearCadastro($company, ['Polos', 'Assessoria']);

        $fichas = MlbEmpresa::where('company_id', $company->id)->get();
        $this->assertSame(1, $fichas->count(), 'Deveria nascer exatamente 1 ficha (só a de Polos) para a empresa com Polos + Assessoria.');
        $this->assertSame('POLO', $fichas->first()->tipo);
        $this->assertSame(
            0,
            MlbEmpresa::where('company_id', $company->id)->where('tipo', 'ASSESSORIA')->count(),
            'Nenhuma ficha ASSESSORIA deveria nascer — o serviço fica retido.',
        );
    }

    /**
     * Nome de serviço fora do catálogo (mas que casa por `str_contains` no
     * mapa `servicoDisparaImplementacao()`) é tratado como se exigisse
     * contrato — fail-safe, nunca isenção por semelhança de nome.
     */
    public function test_nome_de_servico_fora_do_catalogo_e_retido_mesmo_parecendo_polos(): void
    {
        Configuracao::set(EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '1');

        $company = $this->criarEmpresa();

        $this->router()->rotearCadastro($company, ['Polos Fantasma Não Catalogado']);

        $this->assertSame(
            0,
            MlbEmpresa::where('company_id', $company->id)->count(),
            'Nome de serviço fora do catálogo precisa ser tratado como "exige contrato" — fail-safe, nunca isenção por nome.',
        );
    }

    /**
     * Chave desligada + Assessoria → o comportamento de hoje continua
     * intacto: a ficha nasce normalmente, o filtro do gate não vazou para o
     * caminho desligado.
     */
    public function test_interruptor_desligado_roteia_assessoria_como_sempre(): void
    {
        Configuracao::set(EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '0');

        $company = $this->criarEmpresa();

        $this->router()->rotearCadastro($company, ['Assessoria']);

        $mlbEmp = MlbEmpresa::where('company_id', $company->id)->where('tipo', 'ASSESSORIA')->first();
        $this->assertNotNull($mlbEmp, 'Com o interruptor desligado, a ficha ASSESSORIA deveria nascer normalmente.');
    }
}
