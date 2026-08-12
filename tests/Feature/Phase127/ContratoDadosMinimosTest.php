<?php

namespace Tests\Feature\Phase127;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Services\Contratos\ContratoDadosMinimosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Prova do Success Criteria 1 (REDE-05, Fase 127 Plano 03): a checagem de
 * dados mínimos reprova ANTES de qualquer chamada HTTP. `Http::fake()` +
 * `Http::assertNothingSent()` em todo teste é a prova de que o
 * `ContratoDadosMinimosService` é puro — nenhuma rede é tocada mesmo quando
 * a empresa reprova.
 *
 * Teste 6 é o que documenta por que este service NÃO reusa
 * `PendenciasComerciaisService::calcular()`: aquele é gated por
 * `is_origem_hubspot` e não checa e-mail nem CNPJ (ver docblock do service).
 */
class ContratoDadosMinimosTest extends TestCase
{
    use RefreshDatabase;

    private ContratoDadosMinimosService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ContratoDadosMinimosService();

        Http::fake();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();

        parent::tearDown();
    }

    /**
     * Devolve o ID de um serviço do catálogo já semeado por migration
     * (`Servico` não tem `HasFactory`) — mesma regra do plano 127-01. Se o
     * catálogo estiver vazio, completa sem inventar `setor` novo (enum
     * legado, CHECK do SQLite derruba a suíte).
     */
    private function servicoId(): int
    {
        $id = Servico::query()->value('id');

        if ($id !== null) {
            return $id;
        }

        $setorExistente = Servico::query()->value('setor') ?? Servico::SETOR_OUTROS;

        return Servico::create([
            'nome'          => 'Serviço de teste '.uniqid(),
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => $setorExistente,
        ])->id;
    }

    private function companyCompleta(array $overrides = []): Company
    {
        return Company::factory()->create(array_merge([
            'email_cliente' => 'cliente@empresa.com.br',
            'cnpj'          => '12.345.678/0001-95',
            'nome_contato'  => 'Fulano de Tal',
        ], $overrides));
    }

    private function contratoAtivo(Company $company, array $overrides = []): ContratoServico
    {
        return ContratoServico::create(array_merge([
            'company_id'       => $company->id,
            'servico_id'       => $this->servicoId(),
            'valor_contratado' => 100,
            'data_contratacao' => '2026-01-10',
            'ativo'            => true,
        ], $overrides));
    }

    #[Test]
    public function empresa_completa_esta_pronta_e_nao_tem_faltantes(): void
    {
        $company = $this->companyCompleta();
        $this->contratoAtivo($company);

        $this->assertTrue($this->service->estaPronta($company));
        $this->assertSame([], $this->service->faltantes($company));
    }

    #[Test]
    public function sem_email_cliente_reprova_como_ausente(): void
    {
        $company = $this->companyCompleta(['email_cliente' => null]);
        $this->contratoAtivo($company);

        $faltantes = $this->service->faltantes($company);

        $item = collect($faltantes)->firstWhere('campo', 'email_cliente');
        $this->assertNotNull($item);
        $this->assertSame('ausente', $item['motivo']);
        $this->assertFalse($this->service->estaPronta($company));
    }

    #[Test]
    public function email_cliente_malformado_reprova_como_formato(): void
    {
        $company = $this->companyCompleta(['email_cliente' => 'joao@']);
        $this->contratoAtivo($company);

        $faltantes = $this->service->faltantes($company);

        $item = collect($faltantes)->firstWhere('campo', 'email_cliente');
        $this->assertNotNull($item);
        $this->assertSame('formato', $item['motivo']);
    }

    #[Test]
    public function cnpj_ausente_reprova_como_ausente(): void
    {
        $company = $this->companyCompleta(['cnpj' => null]);
        $this->contratoAtivo($company);

        $item = collect($this->service->faltantes($company))->firstWhere('campo', 'cnpj');
        $this->assertNotNull($item);
        $this->assertSame('ausente', $item['motivo']);
    }

    #[Test]
    public function cnpj_com_13_digitos_reprova_como_formato(): void
    {
        $company = $this->companyCompleta(['cnpj' => '1234567890123']);
        $this->contratoAtivo($company);

        $item = collect($this->service->faltantes($company))->firstWhere('campo', 'cnpj');
        $this->assertNotNull($item);
        $this->assertSame('formato', $item['motivo']);
    }

    #[Test]
    public function cnpj_com_pontuacao_e_14_digitos_passa(): void
    {
        $company = $this->companyCompleta(['cnpj' => '12.345.678/0001-95']);
        $this->contratoAtivo($company);

        $item = collect($this->service->faltantes($company))->firstWhere('campo', 'cnpj');
        $this->assertNull($item);
    }

    #[Test]
    public function sem_nome_contato_reprova(): void
    {
        $company = $this->companyCompleta(['nome_contato' => null]);
        $this->contratoAtivo($company);

        $item = collect($this->service->faltantes($company))->firstWhere('campo', 'nome_contato');
        $this->assertNotNull($item);
        $this->assertSame('ausente', $item['motivo']);
    }

    #[Test]
    public function empresa_nao_hubspot_com_email_faltando_reprova_igual(): void
    {
        // Documenta a diferença para PendenciasComerciaisService::calcular(),
        // que retorna [] para qualquer empresa não-HubSpot — se alguém
        // "otimizar" reusando aquele método, este teste quebra.
        $company = $this->companyCompleta(['email_cliente' => null]);
        $company->is_origem_hubspot = false;
        $this->contratoAtivo($company);

        $item = collect($this->service->faltantes($company))->firstWhere('campo', 'email_cliente');
        $this->assertNotNull($item);
        $this->assertFalse($this->service->estaPronta($company));
    }

    #[Test]
    public function campos_a_definir_nao_aparecem_em_faltantes(): void
    {
        $company = $this->companyCompleta();
        $this->contratoAtivo($company);

        $faltantes = $this->service->faltantes($company);
        $campos = collect($faltantes)->pluck('campo')->all();

        $this->assertNotContains('endereco', $campos);
        $this->assertNotContains('dia_vencimento', $campos);
        $this->assertNotContains('data_primeira_parcela', $campos);
    }

    #[Test]
    public function sem_contrato_servico_ativo_reprova(): void
    {
        $company = $this->companyCompleta();
        // Nenhum ContratoServico criado.

        $item = collect($this->service->faltantes($company))->firstWhere('campo', 'contratos_servico');
        $this->assertNotNull($item);
        $this->assertSame('ausente', $item['motivo']);
    }

    #[Test]
    public function contrato_ativo_sem_data_contratacao_reprova_com_servico_id(): void
    {
        // A coluna `data_contratacao` é NOT NULL no schema (migration
        // 2026_05_26_120002) — string vazia é o jeito de representar "sem
        // data preenchida" sem violar a constraint do banco; representa dado
        // legado que nunca deveria ter existido, mas a checagem precisa
        // pegar mesmo assim.
        $company = $this->companyCompleta();
        $servicoId = $this->servicoId();
        $this->contratoAtivo($company, [
            'servico_id'       => $servicoId,
            'data_contratacao' => '',
        ]);

        $item = collect($this->service->faltantes($company))->firstWhere('campo', 'data_contratacao');
        $this->assertNotNull($item);
        $this->assertSame('ausente', $item['motivo']);
        $this->assertSame($servicoId, $item['servico_id']);
    }

    #[Test]
    public function data_vencimento_vazia_nao_reprova(): void
    {
        $company = $this->companyCompleta();
        $this->contratoAtivo($company, ['data_vencimento' => null]);

        $item = collect($this->service->faltantes($company))->firstWhere('campo', 'data_vencimento');
        $this->assertNull($item);
        $this->assertTrue($this->service->estaPronta($company));
    }

    #[Test]
    public function cada_item_tem_campo_rotulo_e_motivo_com_rotulo_legivel(): void
    {
        $company = $this->companyCompleta(['email_cliente' => null, 'cnpj' => null, 'nome_contato' => null]);
        // Sem ContratoServico ativo também — cobre o item 'contratos_servico'.

        $faltantes = $this->service->faltantes($company);

        $this->assertNotEmpty($faltantes);

        foreach ($faltantes as $item) {
            $this->assertArrayHasKey('campo', $item);
            $this->assertArrayHasKey('rotulo', $item);
            $this->assertArrayHasKey('motivo', $item);
            $this->assertIsString($item['rotulo']);
            $this->assertNotSame('', trim($item['rotulo']));
            // Rótulo não é o nome cru da coluna nem contém underscore/jargão.
            $this->assertStringNotContainsString('_', $item['rotulo']);
        }
    }
}
