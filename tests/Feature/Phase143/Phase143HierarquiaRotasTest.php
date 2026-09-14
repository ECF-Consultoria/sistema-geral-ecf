<?php

namespace Tests\Feature\Phase143;

use App\Http\Controllers\GrupoCobrancaHierarquiaController;
use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\Configuracao;
use App\Models\ContratoServico;
use App\Models\GrupoFaixaFaturamento;
use App\Models\Servico;
use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\User;
use App\Services\Fechamento\FechamentoRegraTabela;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 143 Plano 02 — Tarefa 3: as rotas que penduram, despenduram e servem
 * a prévia, dentro do módulo administrativo de contratos.
 *
 * O que estes testes protegem:
 *
 * 1. **A regra da árvore não foi duplicada.** A recusa vem do `saving()` do
 *    model (143-01), com a MESMA mensagem pt-BR — se a rota tivesse a sua
 *    própria cópia da regra, as duas divergiriam com o tempo e a tela
 *    aceitaria o que a gravação recusa.
 * 2. **Trilha de auditoria.** Pendurar um grupo muda quanto um cliente paga;
 *    tem de ficar registrado quem fez, o que mudou e QUAL NÚMERO a pessoa
 *    viu ao decidir.
 * 3. **Permissão é a do módulo** (`admin.contratos`), liberável por setor sem
 *    deploy — nenhuma permissão nova.
 *
 * Molde de montagem de permissão por setor: `Phase142FichaTabelaPermissaoTest`.
 */
class Phase143HierarquiaRotasTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function userComPermissaoViaSetor(string $permissionKey): User
    {
        $setor = Setor::create([
            'nome'   => 'Setor Hierarquia Teste '.uniqid(),
            'slug'   => 'hierarquia-teste-'.uniqid(),
            'active' => true,
        ]);
        SetorPermissao::create([
            'setor_id'       => $setor->id,
            'permission_key' => $permissionKey,
        ]);
        $user = User::factory()->create(['role' => 'consultor']);
        $setor->membros()->attach($user->id, [
            'is_principal' => true,
            'assigned_at'  => now(),
        ]);

        return $user;
    }

    private function criarServicoGestao(): Servico
    {
        $servico = Servico::firstOrCreate(
            ['nome' => 'Gestão'],
            ['valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true]
        );
        $servico->update(['plataforma' => 'Mercado Livre', 'setor' => Servico::SETOR_PERFORMANCE]);

        return $servico->refresh();
    }

    private function criarMembro(Servico $servico, CompanyGroup $grupo, float $faturamento): Company
    {
        $company = Company::factory()->create([
            'adman_account_id' => 'cust-'.uniqid(),
            'company_group_id' => $grupo->id,
        ]);

        ContratoServico::factory()->paraServico($servico)->create([
            'company_id' => $company->id,
            'ativo'      => true,
        ]);

        AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => '2026-08-10',
            'revenue'        => $faturamento,
        ]);

        return $company;
    }

    /**
     * Raiz com tabela de dois degraus + um subgrupo com tabela própria —
     * juntar derruba a cobrança, que é o cenário que a trilha precisa
     * registrar.
     *
     * @return array{0: CompanyGroup, 1: CompanyGroup}
     */
    private function cenario(): array
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        Configuracao::set(FechamentoRegraTabela::CHAVE, '1');

        $gestao = $this->criarServicoGestao();

        $raiz = CompanyGroup::create(['name' => 'MPozenato', 'color' => '#000']);
        $sub  = CompanyGroup::create(['name' => 'DRossi', 'color' => '#000']);

        $this->criarMembro($gestao, $raiz, 3_812_487.89);
        $this->criarMembro($gestao, $sub, 1_238_304.17);

        // ⚠️ O teto da faixa 1 (R$ 6 mi) cobre a SOMA dos dois grupos
        // (R$ 5.050.792,06) de propósito: é o caso da fase — juntar o
        // cliente o mantém numa faixa só e DERRUBA a cobrança, de
        // R$ 15.500 (9.500 + 6.000) para R$ 9.500.
        GrupoFaixaFaturamento::create([
            'company_group_id' => $raiz->id, 'ordem' => 1,
            'limite_superior'  => 6_000_000.00, 'valor' => 9_500.00, 'valor_e_piso' => false,
        ]);
        GrupoFaixaFaturamento::create([
            'company_group_id' => $raiz->id, 'ordem' => 2,
            'limite_superior'  => null, 'valor' => 21_000.00, 'valor_e_piso' => true,
        ]);
        GrupoFaixaFaturamento::create([
            'company_group_id' => $sub->id, 'ordem' => 1,
            'limite_superior'  => null, 'valor' => 6_000.00, 'valor_e_piso' => true,
        ]);

        return [$raiz, $sub];
    }

    // ─── Prévia ───────────────────────────────────────────────────────────

    #[Test]
    public function a_previa_responde_o_antes_e_o_depois_e_nao_escreve_nada(): void
    {
        [$raiz, $sub] = $this->cenario();

        $response = $this->actingAs($this->admin())->getJson(
            route('admin.contratos.grupos.hierarquia.previa', [
                'grupo_ids' => [$sub->id],
                'pai_id'    => $raiz->id,
                'mes'       => '2026-08',
            ])
        );

        $response->assertOk();

        $json = $response->json();

        $this->assertCount(2, $json['antes']['linhas'], 'Hoje são duas linhas de cobrança.');
        $this->assertCount(1, $json['depois']['linhas'], 'Juntos, viram uma.');
        $this->assertEqualsWithDelta(15_500.00, $json['antes']['total_cobranca'], 0.01);
        $this->assertEqualsWithDelta(9_500.00, $json['depois']['total_cobranca'], 0.01);
        $this->assertEqualsWithDelta(-6_000.00, $json['delta'], 0.01);
        $this->assertSame('MPozenato', $json['pai_nome']);

        // De onde vem a tabela — a informação sem a qual o número acima é
        // uma decisão no escuro.
        $this->assertArrayHasKey('procedencia', $json['depois']['linhas'][0]);
        $this->assertSame('grupo', $json['depois']['linhas'][0]['tabela_origem']);
        $this->assertSame('MPozenato', $json['depois']['linhas'][0]['tabela_grupo_nome']);

        // ⚠️ Consulta PURA.
        $this->assertSame(0, CompanyGroup::whereNotNull('parent_id')->count());
        $this->assertSame(0, DB::table('activity_log')->where('log_name', GrupoCobrancaHierarquiaController::LOG_NAME)->count());
        $this->assertSame(0, DB::table('fechamento_grupo_snapshots')->count());
    }

    #[Test]
    public function a_previa_de_um_arranjo_impossivel_devolve_422_com_a_mensagem_do_model(): void
    {
        $avo   = CompanyGroup::create(['name' => 'Avô', 'color' => '#000']);
        $pai   = CompanyGroup::create(['name' => 'Pai', 'color' => '#000', 'parent_id' => $avo->id]);
        $outro = CompanyGroup::create(['name' => 'Outro', 'color' => '#000']);

        $response = $this->actingAs($this->admin())->getJson(
            route('admin.contratos.grupos.hierarquia.previa', [
                'grupo_ids' => [$outro->id],
                'pai_id'    => $pai->id,
            ])
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('a hierarquia tem um nível só.', $response->json('message'));
    }

    // ─── Pendurar / despendurar ───────────────────────────────────────────

    #[Test]
    public function pendurar_grava_parent_id_e_registra_a_trilha_com_a_previa_da_decisao(): void
    {
        [$raiz, $sub] = $this->cenario();
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post(route('admin.contratos.grupos.hierarquia.pendurar'), [
            'grupo_ids' => [$sub->id],
            'pai_id'    => $raiz->id,
            'mes'       => '2026-08',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertSame($raiz->id, (int) $sub->fresh()->parent_id);

        $log = DB::table('activity_log')
            ->where('log_name', GrupoCobrancaHierarquiaController::LOG_NAME)
            ->first();

        $this->assertNotNull($log, 'Pendurar um grupo muda o que um cliente paga — sem trilha não há como auditar depois.');
        $this->assertSame($admin->id, (int) $log->causer_id);
        $this->assertSame($raiz->id, (int) $log->subject_id);

        $props = json_decode($log->properties, true);

        $this->assertNull($props['antes'][0]['parent_id'], 'A trilha guarda o estado ANTES.');
        $this->assertSame($sub->id, $props['antes'][0]['id']);
        $this->assertSame($raiz->id, $props['depois'][0]['parent_id'], 'E o estado DEPOIS.');
        $this->assertSame($raiz->id, $props['pai_id']);
        $this->assertSame('2026-08', $props['mes']);

        // A prévia do impacto no momento da decisão — o número que a pessoa
        // viu quando aprovou, não um recálculo posterior.
        $this->assertEqualsWithDelta(15_500.00, (float) $props['previa']['total_cobranca_antes'], 0.01);
        $this->assertEqualsWithDelta(9_500.00, (float) $props['previa']['total_cobranca_depois'], 0.01);
        $this->assertEqualsWithDelta(-6_000.00, (float) $props['previa']['delta'], 0.01);
        $this->assertCount(2, $props['previa']['linhas_antes']);
        $this->assertCount(1, $props['previa']['linhas_depois']);
    }

    #[Test]
    public function despendurar_volta_o_parent_id_para_nulo_e_tambem_registra_a_trilha(): void
    {
        [$raiz, $sub] = $this->cenario();
        $sub->update(['parent_id' => $raiz->id]);

        $admin = $this->admin();

        $response = $this->actingAs($admin)->delete(route('admin.contratos.grupos.hierarquia.despendurar'), [
            'grupo_ids' => [$sub->id],
            'mes'       => '2026-08',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertNull($sub->fresh()->parent_id);

        $log = DB::table('activity_log')
            ->where('log_name', GrupoCobrancaHierarquiaController::LOG_NAME)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(
            $raiz->id,
            (int) $log->subject_id,
            'O sujeito da trilha é a raiz de onde o grupo saiu — é lá que a cobrança muda.'
        );

        $props = json_decode($log->properties, true);

        $this->assertSame($raiz->id, $props['antes'][0]['parent_id']);
        $this->assertNull($props['depois'][0]['parent_id']);
        $this->assertNull($props['pai_id']);
        $this->assertEqualsWithDelta(6_000.00, (float) $props['previa']['delta'], 0.01, 'Desfazer a junção faz a cobrança subir de volta.');
    }

    // ─── A regra vem do model, não de uma cópia na rota ───────────────────

    #[Test]
    public function pendurar_num_grupo_que_ja_tem_pai_e_recusado_com_a_mensagem_do_model(): void
    {
        $avo   = CompanyGroup::create(['name' => 'Avô', 'color' => '#000']);
        $pai   = CompanyGroup::create(['name' => 'Pai', 'color' => '#000', 'parent_id' => $avo->id]);
        $outro = CompanyGroup::create(['name' => 'Outro', 'color' => '#000']);

        $response = $this->actingAs($this->admin())
            ->from('/administrativo/contratos')
            ->post(route('admin.contratos.grupos.hierarquia.pendurar'), [
                'grupo_ids' => [$outro->id],
                'pai_id'    => $pai->id,
            ]);

        $response->assertSessionHasErrors('grupo_ids');
        $this->assertNull($outro->fresh()->parent_id, 'Nada pode ter sido gravado.');

        $erro = session('errors')->get('grupo_ids')[0];
        $this->assertStringContainsString('a hierarquia tem um nível só.', $erro);
    }

    #[Test]
    public function pendurar_um_grupo_que_ja_e_pai_de_outros_e_recusado(): void
    {
        $raiz = CompanyGroup::create(['name' => 'Raiz', 'color' => '#000']);
        CompanyGroup::create(['name' => 'Filho', 'color' => '#000', 'parent_id' => $raiz->id]);
        $outraRaiz = CompanyGroup::create(['name' => 'Outra Raiz', 'color' => '#000']);

        $response = $this->actingAs($this->admin())
            ->from('/administrativo/contratos')
            ->post(route('admin.contratos.grupos.hierarquia.pendurar'), [
                'grupo_ids' => [$raiz->id],
                'pai_id'    => $outraRaiz->id,
            ]);

        $response->assertSessionHasErrors('grupo_ids');
        $this->assertNull($raiz->fresh()->parent_id);
    }

    #[Test]
    public function pendurar_criando_ciclo_e_recusado(): void
    {
        $a = CompanyGroup::create(['name' => 'A', 'color' => '#000']);
        $b = CompanyGroup::create(['name' => 'B', 'color' => '#000', 'parent_id' => $a->id]);

        // A→B→A: `A` já é pai de `B`, então `A` não pode ganhar pai.
        $response = $this->actingAs($this->admin())
            ->from('/administrativo/contratos')
            ->post(route('admin.contratos.grupos.hierarquia.pendurar'), [
                'grupo_ids' => [$a->id],
                'pai_id'    => $b->id,
            ]);

        $response->assertSessionHasErrors('grupo_ids');
        $this->assertNull($a->fresh()->parent_id);
        $this->assertSame($a->id, (int) $b->fresh()->parent_id);
    }

    #[Test]
    public function pendurar_um_grupo_nele_mesmo_e_recusado(): void
    {
        $a = CompanyGroup::create(['name' => 'A', 'color' => '#000']);

        $response = $this->actingAs($this->admin())
            ->from('/administrativo/contratos')
            ->post(route('admin.contratos.grupos.hierarquia.pendurar'), [
                'grupo_ids' => [$a->id],
                'pai_id'    => $a->id,
            ]);

        $response->assertSessionHasErrors('grupo_ids');
        $this->assertNull($a->fresh()->parent_id);
    }

    #[Test]
    public function uma_recusa_no_meio_do_lote_nao_deixa_metade_pendurada(): void
    {
        $raiz = CompanyGroup::create(['name' => 'Raiz', 'color' => '#000']);
        $ok   = CompanyGroup::create(['name' => 'Pode', 'color' => '#000']);

        // Este já é pai de alguém — a trava recusa quando chegar nele.
        $naoPode = CompanyGroup::create(['name' => 'Não pode', 'color' => '#000']);
        CompanyGroup::create(['name' => 'Filho', 'color' => '#000', 'parent_id' => $naoPode->id]);

        $response = $this->actingAs($this->admin())
            ->from('/administrativo/contratos')
            ->post(route('admin.contratos.grupos.hierarquia.pendurar'), [
                'grupo_ids' => [$ok->id, $naoPode->id],
                'pai_id'    => $raiz->id,
            ]);

        $response->assertSessionHasErrors('grupo_ids');
        $this->assertNull($ok->fresh()->parent_id, 'A transação tem de desfazer o que já tinha sido gravado.');
        $this->assertNull($naoPode->fresh()->parent_id);
        $this->assertSame(
            0,
            DB::table('activity_log')->where('log_name', GrupoCobrancaHierarquiaController::LOG_NAME)->count(),
            'Nada gravado, nenhuma trilha.'
        );
    }

    // ─── Permissão ────────────────────────────────────────────────────────

    #[Test]
    public function quem_tem_admin_contratos_via_setor_consegue_pendurar(): void
    {
        [$raiz, $sub] = $this->cenario();

        $user = $this->userComPermissaoViaSetor(Permissions::ADMIN_CONTRATOS);

        $response = $this->actingAs($user)->post(route('admin.contratos.grupos.hierarquia.pendurar'), [
            'grupo_ids' => [$sub->id],
            'pai_id'    => $raiz->id,
            'mes'       => '2026-08',
        ]);

        $response->assertRedirect();
        $this->assertSame($raiz->id, (int) $sub->fresh()->parent_id);
    }

    #[Test]
    public function quem_nao_tem_a_permissao_leva_403_nas_tres_rotas(): void
    {
        [$raiz, $sub] = $this->cenario();

        $semPermissao = User::factory()->create(['role' => 'consultor']);

        $this->actingAs($semPermissao)
            ->getJson(route('admin.contratos.grupos.hierarquia.previa', ['grupo_ids' => [$sub->id], 'pai_id' => $raiz->id]))
            ->assertForbidden();

        $this->actingAs($semPermissao)
            ->post(route('admin.contratos.grupos.hierarquia.pendurar'), ['grupo_ids' => [$sub->id], 'pai_id' => $raiz->id])
            ->assertForbidden();

        $this->actingAs($semPermissao)
            ->delete(route('admin.contratos.grupos.hierarquia.despendurar'), ['grupo_ids' => [$sub->id]])
            ->assertForbidden();

        $this->assertNull($sub->fresh()->parent_id);
    }

    // ─── O NPS não sente nada ─────────────────────────────────────────────

    #[Test]
    public function pendurar_nao_remaneja_nenhuma_empresa_de_grupo(): void
    {
        [$raiz, $sub] = $this->cenario();

        $empresasDoSubAntes = Company::where('company_group_id', $sub->id)->pluck('id')->sort()->values();

        $this->actingAs($this->admin())->post(route('admin.contratos.grupos.hierarquia.pendurar'), [
            'grupo_ids' => [$sub->id],
            'pai_id'    => $raiz->id,
            'mes'       => '2026-08',
        ])->assertRedirect();

        // ⛔ `companies.company_group_id` é o que `nps_group_surveys` e
        // `NpsGrupoCoberturaService` usam. A árvore é aditiva: nada aqui
        // pode remanejar empresa de grupo, senão o NPS sente.
        $this->assertEquals(
            $empresasDoSubAntes,
            Company::where('company_group_id', $sub->id)->pluck('id')->sort()->values()
        );
    }
}
