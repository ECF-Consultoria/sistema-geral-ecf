<?php

namespace Tests\Feature\Quick260915;

use App\Models\Company;
use App\Models\ContratoTabelaProposta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 260915-mtj — a confirmação da leitura do contrato confere o CNPJ antes de gravar.
 *
 * Caso real: o contrato da BORIM (CNPJ 08040574000103) foi confirmado na "ByMobille - Teste" e
 * gravou nela o CNPJ e a razão social da BORIM sem ninguém ver. CNPJ de outra empresa NÃO é
 * bloqueio absoluto (há contratos legítimos em CNPJ de outra empresa do mesmo dono) — só exige que
 * a pessoa marque que conferiu.
 *
 * Toda asserção de persistência é por RECONSULTA ao banco (`DB::table`).
 */
class ConfirmacaoConfereCnpjTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function situacao(ContratoTabelaProposta $proposta): string
    {
        return DB::table('contrato_tabela_propostas')->where('id', $proposta->id)->value('situacao');
    }

    #[Test]
    public function mesmo_cnpj_confirma_sem_o_campo_novo(): void
    {
        $company  = Company::factory()->create(['cnpj' => '08040574000103']);
        $proposta = ContratoTabelaProposta::factory()->valorFixo()->create(['cnpj_lido' => '08040574000103']);

        $this->actingAs($this->admin())
            ->post(route('admin.contratos.tabelas.confirmar', $proposta), ['company_id' => $company->id])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame(ContratoTabelaProposta::SITUACAO_CONFIRMADA, $this->situacao($proposta));
    }

    #[Test]
    public function mesma_empresa_outra_unidade_confirma_sem_o_campo_novo(): void
    {
        // CAMILLO: filial cadastrada × contrato em nome da matriz.
        $company  = Company::factory()->create(['cnpj' => '93.734.150/0005-33']);
        $proposta = ContratoTabelaProposta::factory()->valorFixo()->create(['cnpj_lido' => '93734150000100']);

        $this->actingAs($this->admin())
            ->post(route('admin.contratos.tabelas.confirmar', $proposta), ['company_id' => $company->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(ContratoTabelaProposta::SITUACAO_CONFIRMADA, $this->situacao($proposta));
        $this->assertSame('93.734.150/0005-33', DB::table('companies')->where('id', $company->id)->value('cnpj'));
    }

    #[Test]
    public function cnpj_diferente_sem_o_campo_recusa_e_nao_grava_nada(): void
    {
        $company = Company::factory()->create([
            'name'         => 'ByMobille - Teste',
            'cnpj'         => '11.222.333/0001-81',
            'razao_social' => null,
        ]);
        $proposta = ContratoTabelaProposta::factory()->valorFixo()->create([
            'cnpj_lido'         => '08040574000103',
            'razao_social_lida' => 'BORIM COMERCIO LTDA',
        ]);

        $response = $this->actingAs($this->admin())
            ->postJson(route('admin.contratos.tabelas.confirmar', $proposta), ['company_id' => $company->id]);

        $response->assertStatus(422)->assertJsonValidationErrors('confirmo_cnpj_diferente');
        $mensagem = $response->json('errors.confirmo_cnpj_diferente.0');
        $this->assertStringContainsString('ByMobille - Teste', $mensagem);
        $this->assertStringContainsString('Nada foi gravado', $mensagem);

        $linha = DB::table('companies')->where('id', $company->id)->first();
        $this->assertSame('11.222.333/0001-81', $linha->cnpj);
        $this->assertNull($linha->razao_social);
        $this->assertSame(ContratoTabelaProposta::SITUACAO_PENDENTE, $this->situacao($proposta));
        $this->assertNull(DB::table('contrato_tabela_propostas')->where('id', $proposta->id)->value('confirmado_por'));
    }

    #[Test]
    public function cnpj_diferente_pela_tela_devolve_o_erro_para_a_pessoa_ler(): void
    {
        $company  = Company::factory()->create(['cnpj' => '11.222.333/0001-81']);
        $proposta = ContratoTabelaProposta::factory()->valorFixo()->create(['cnpj_lido' => '08040574000103']);

        $this->actingAs($this->admin())
            ->from(route('admin.contratos.tabelas.index'))
            ->post(route('admin.contratos.tabelas.confirmar', $proposta), ['company_id' => $company->id])
            ->assertSessionHasErrors('confirmo_cnpj_diferente');

        $this->assertSame(ContratoTabelaProposta::SITUACAO_PENDENTE, $this->situacao($proposta));
    }

    #[Test]
    public function cnpj_diferente_com_o_campo_marcado_confirma(): void
    {
        // "Renovação K2" confirmado na RODRICALHAS 2R — operação legítima em CNPJ de outra empresa.
        $company = Company::factory()->create(['cnpj' => '11.222.333/0001-81', 'razao_social' => 'RODRICALHAS 2R LTDA']);
        $proposta = ContratoTabelaProposta::factory()->valorFixo()->create([
            'cnpj_lido'         => '08040574000103',
            'razao_social_lida' => 'K2 COMERCIO LTDA',
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.contratos.tabelas.confirmar', $proposta), [
                'company_id'              => $company->id,
                'confirmo_cnpj_diferente' => true,
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame(ContratoTabelaProposta::SITUACAO_CONFIRMADA, $this->situacao($proposta));

        // Empresa já tinha os dois campos — nada muda nela.
        $linha = DB::table('companies')->where('id', $company->id)->first();
        $this->assertSame('11.222.333/0001-81', $linha->cnpj);
        $this->assertSame('RODRICALHAS 2R LTDA', $linha->razao_social);
    }

    #[Test]
    public function campo_marcado_como_falso_continua_recusando(): void
    {
        $company  = Company::factory()->create(['cnpj' => '11.222.333/0001-81']);
        $proposta = ContratoTabelaProposta::factory()->valorFixo()->create(['cnpj_lido' => '08040574000103']);

        $this->actingAs($this->admin())
            ->postJson(route('admin.contratos.tabelas.confirmar', $proposta), [
                'company_id'              => $company->id,
                'confirmo_cnpj_diferente' => false,
            ])
            ->assertStatus(422);

        $this->assertSame(ContratoTabelaProposta::SITUACAO_PENDENTE, $this->situacao($proposta));
    }

    #[Test]
    public function tabela_com_cnpj_diferente_sem_o_campo_recusa_antes_de_gravar_faixa(): void
    {
        $company  = Company::factory()->create(['cnpj' => '11.222.333/0001-81']);
        $proposta = ContratoTabelaProposta::factory()->create([
            'tipo_cobranca' => ContratoTabelaProposta::TIPO_TABELA,
            'cnpj_lido'     => '08040574000103',
            'faixas'        => [
                ['ordem' => 1, 'limite_superior' => 500_000.00, 'valor' => 3_000.00, 'valor_e_piso' => false],
                ['ordem' => 2, 'limite_superior' => null, 'valor' => 5_000.00, 'valor_e_piso' => true],
            ],
        ]);

        $this->actingAs($this->admin())
            ->postJson(route('admin.contratos.tabelas.confirmar', $proposta), ['company_id' => $company->id])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('empresa_faixas_faturamento')->count());
        $this->assertSame(0, DB::table('activity_log')->where('log_name', 'faixa_faturamento_tabela')->count());
        $this->assertSame(ContratoTabelaProposta::SITUACAO_PENDENTE, $this->situacao($proposta));
    }

    #[Test]
    public function empresa_sem_cnpj_recebe_cnpj_e_razao_social_e_o_aviso_diz_o_que_gravou(): void
    {
        $company = Company::factory()->create(['name' => 'Loja Sem Cadastro', 'cnpj' => null, 'razao_social' => null]);
        $proposta = ContratoTabelaProposta::factory()->valorFixo()->create([
            'cnpj_lido'         => '08040574000103',
            'razao_social_lida' => 'BORIM COMERCIO LTDA',
        ]);

        $response = $this->actingAs($this->admin())
            ->post(route('admin.contratos.tabelas.confirmar', $proposta), ['company_id' => $company->id]);

        $response->assertSessionHasNoErrors();
        $this->assertStringContainsString(
            'CNPJ e razão social do contrato gravados em Loja Sem Cadastro.',
            session('success')
        );

        $linha = DB::table('companies')->where('id', $company->id)->first();
        // Formato armazenado não muda: grava o texto lido do contrato.
        $this->assertSame('08040574000103', $linha->cnpj);
        $this->assertSame('BORIM COMERCIO LTDA', $linha->razao_social);
    }

    #[Test]
    public function quando_so_o_cnpj_e_gravado_o_aviso_fala_so_do_cnpj(): void
    {
        $company  = Company::factory()->create(['name' => 'Loja Com Razao', 'cnpj' => null, 'razao_social' => 'JA TEM LTDA']);
        $proposta = ContratoTabelaProposta::factory()->valorFixo()->create([
            'cnpj_lido'         => '08040574000103',
            'razao_social_lida' => 'BORIM COMERCIO LTDA',
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.contratos.tabelas.confirmar', $proposta), ['company_id' => $company->id]);

        $this->assertStringContainsString('CNPJ do contrato gravado em Loja Com Razao.', session('success'));
        $this->assertStringNotContainsString('razão social', session('success'));
        $this->assertSame('JA TEM LTDA', DB::table('companies')->where('id', $company->id)->value('razao_social'));
    }

    #[Test]
    public function empresa_com_cnpj_mascarado_e_contrato_so_digitos_e_o_mesmo_cnpj(): void
    {
        $company  = Company::factory()->create(['cnpj' => '08.040.574/0001-03']);
        $proposta = ContratoTabelaProposta::factory()->valorFixo()->create(['cnpj_lido' => '08040574000103']);

        $this->actingAs($this->admin())
            ->post(route('admin.contratos.tabelas.confirmar', $proposta), ['company_id' => $company->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(ContratoTabelaProposta::SITUACAO_CONFIRMADA, $this->situacao($proposta));
        $this->assertSame('08.040.574/0001-03', DB::table('companies')->where('id', $company->id)->value('cnpj'));
    }

    #[Test]
    public function cnpj_lido_ja_gravado_com_mascara_em_outra_empresa_nao_grava_e_avisa(): void
    {
        Company::factory()->create(['cnpj' => '08.040.574/0001-03']);
        $alvo     = Company::factory()->create(['cnpj' => null]);
        $proposta = ContratoTabelaProposta::factory()->valorFixo()->create(['cnpj_lido' => '08040574000103']);

        $this->actingAs($this->admin())
            ->post(route('admin.contratos.tabelas.confirmar', $proposta), ['company_id' => $alvo->id])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('aviso');

        $this->assertNull(DB::table('companies')->where('id', $alvo->id)->value('cnpj'));
        $this->assertStringNotContainsString('CNPJ', session('success'));
        $this->assertSame(ContratoTabelaProposta::SITUACAO_CONFIRMADA, $this->situacao($proposta));
    }

    #[Test]
    public function contrato_sem_cnpj_confirma_e_nao_grava_cnpj(): void
    {
        $company  = Company::factory()->create(['cnpj' => null]);
        $proposta = ContratoTabelaProposta::factory()->valorFixo()->create(['cnpj_lido' => null]);

        $this->actingAs($this->admin())
            ->post(route('admin.contratos.tabelas.confirmar', $proposta), ['company_id' => $company->id])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame(ContratoTabelaProposta::SITUACAO_CONFIRMADA, $this->situacao($proposta));
        $this->assertNull(DB::table('companies')->where('id', $company->id)->value('cnpj'));
    }

    #[Test]
    public function campo_novo_com_valor_que_nao_e_booleano_e_recusado(): void
    {
        $company  = Company::factory()->create(['cnpj' => '11.222.333/0001-81']);
        $proposta = ContratoTabelaProposta::factory()->valorFixo()->create(['cnpj_lido' => '08040574000103']);

        $this->actingAs($this->admin())
            ->postJson(route('admin.contratos.tabelas.confirmar', $proposta), [
                'company_id'              => $company->id,
                'confirmo_cnpj_diferente' => 'talvez',
            ])
            ->assertStatus(422);

        $this->assertSame(ContratoTabelaProposta::SITUACAO_PENDENTE, $this->situacao($proposta));
    }
}
