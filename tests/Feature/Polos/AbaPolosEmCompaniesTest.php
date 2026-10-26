<?php

namespace Tests\Feature\Polos;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\Servico;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A aba "Polos" de `/companies` (26/10/2026).
 *
 * As empresas dos Polos ficam fora da aba Empresas de propósito (Phase 35) e
 * moram em `mlb_empresas`. A aba nova as lista à parte.
 *
 * O que estes testes protegem:
 *
 *  - **o recorte é o do resto do módulo Polos**: arquivada fica fora, e
 *    empresa de outro projeto (Assessoria) também; `projeto` vazio cai na
 *    fase, como em `MlbEmpresa::scopePrograma`;
 *  - **a lista só sai no partial reload** — abrir /companies paga um COUNT,
 *    não a lista inteira;
 *  - **quem não vê Polos não recebe nada**, nem pedindo a prop na mão;
 *  - **a aba Empresas não muda**: empresa com vínculo Polos continua fora dela.
 *
 * @group polos
 */
class AbaPolosEmCompaniesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin '.uniqid(), 'email' => 'admin.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'admin', 'active' => true,
        ]);
    }

    /** @param  array<int,string>  $permissions */
    private function usuarioCom(array $permissions, string $nome = 'Nao Admin'): User
    {
        $user = User::create([
            'name' => $nome.' '.uniqid(), 'email' => 'naoadmin.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'consultor', 'active' => true,
        ]);

        $setorId = DB::table('setores')->insertGetId([
            'nome' => 'Setor '.uniqid(), 'slug' => 'setor-'.uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($permissions as $key) {
            DB::table('setor_permissoes')->insert([
                'setor_id' => $setorId, 'permission_key' => $key,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('user_setores')->insert([
            'user_id' => $user->id, 'setor_id' => $setorId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    private function polo(string $nome, array $opts = []): MlbEmpresa
    {
        return MlbEmpresa::create(array_merge([
            'nome'    => $nome,
            'tipo'    => 'POLO',
            'projeto' => 'POLOS',
            'fase'    => 'M2',
            'polo'    => 'Arapongas',
            'cust_id' => (string) random_int(100000000, 999999999),
        ], $opts));
    }

    /** Props da carga normal de /companies. */
    private function carga(User $quem): array
    {
        $props = null;

        $this->actingAs($quem)
            ->get(route('companies.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    /** O que a aba recebe ao abrir (partial reload de `polos_empresas`). */
    private function listaDaAba(User $quem): mixed
    {
        $lista = 'nao-chegou';

        // Closure comum, não arrow fn: a arrow captura `$lista` por valor e a
        // atribuição de dentro não voltaria para cá.
        $this->actingAs($quem)
            ->get(route('companies.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$lista) {
                $page->reloadOnly('polos_empresas', function (AssertableInertia $r) use (&$lista) {
                    $lista = $r->toArray()['props']['polos_empresas'] ?? null;
                });
            });

        return $lista;
    }

    // ─── Recorte ────────────────────────────────────────────────────────

    #[Test]
    public function lista_so_as_empresas_ativas_dos_polos(): void
    {
        $beta  = $this->polo('Beta');
        // `projeto` vazio: a fase decide (fallback de FASE_PARA_PROJETO).
        $alfa  = $this->polo('Alfa', ['projeto' => null, 'fase' => 'M1']);
        // `projeto` digitado com caixa e espaço diferentes continua sendo Polos.
        $gama  = $this->polo('Gama', ['projeto' => ' Polos ']);
        // Em tratativa de churn continua; sem fase também.
        $delta = $this->polo('Delta', ['fase' => 'Protocolo Churn']);
        $semFase = $this->polo('Epsilon', ['fase' => null]);
        $this->polo('Arquivada', ['arquivado_em' => now()]);
        $this->polo('Assessoria', ['projeto' => 'Assessoria', 'fase' => 'ASSESSORIA']);
        // Churn sai da aba (pedido de 26/10/2026), com qualquer caixa/espaço.
        $this->polo('Churn', ['fase' => 'Churn']);
        $this->polo('Churn caixa', ['fase' => ' churn ']);

        $admin = $this->admin();

        $props = $this->carga($admin);
        $this->assertTrue($props['pode_ver_polos']);
        $this->assertSame(5, $props['polos_total']);

        $lista = $this->listaDaAba($admin);
        // Ordem alfabética, sem arquivada, sem outro projeto e sem Churn.
        $this->assertSame([$alfa->id, $beta->id, $delta->id, $semFase->id, $gama->id], array_column($lista, 'id'));
    }

    #[Test]
    public function a_lista_nao_vem_na_carga_da_pagina(): void
    {
        $this->polo('Alfa');

        $this->actingAs($this->admin())
            ->get(route('companies.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('polos_total', 1)
                ->missing('polos_empresas'));
    }

    #[Test]
    public function linha_traz_responsavel_da_ficha_regiao_oficial_e_os_links(): void
    {
        $daEmpresa = $this->usuarioCom([], 'Resp Empresa');
        $daFicha   = $this->usuarioCom([], 'Resp Ficha');

        $comFicha = $this->polo('Com ficha', [
            'polo'           => 'rio preto',
            'responsavel_id' => $daEmpresa->id,
            'problema'       => true,
        ]);
        $impl = MlbImplementacao::create([
            'empresa_id'       => $comFicha->id,
            'token'            => 'tok-'.uniqid(),
            'responsavel_id'   => $daFicha->id,
            'data_solicitacao' => '2026-09-15',
        ]);

        // Sem ficha: o responsável é o da empresa; grafia de região que não
        // casa com nenhuma oficial passa como foi digitada (não some).
        $semFicha = $this->polo('Sem ficha', ['polo' => 'Polo Novo', 'responsavel_id' => $daEmpresa->id]);

        $linhas = collect($this->listaDaAba($this->admin()))->keyBy('id');

        $a = $linhas[$comFicha->id];
        $this->assertSame($daFicha->name, $a['responsavel']);
        $this->assertSame('S. J. Rio Preto', $a['regiao']);
        $this->assertSame('2026-09-15', $a['entrada']);
        $this->assertSame($impl->id, $a['implementacao_id']);
        $this->assertTrue($a['problema']);

        $b = $linhas[$semFicha->id];
        $this->assertSame($daEmpresa->name, $b['responsavel']);
        $this->assertSame('Polo Novo', $b['regiao']);
        $this->assertNull($b['entrada']);
        $this->assertNull($b['implementacao_id']);
    }

    // ─── Quem vê ────────────────────────────────────────────────────────

    #[Test]
    public function quem_nao_ve_polos_nao_recebe_nada(): void
    {
        $this->polo('Alfa');
        $semPolos = $this->usuarioCom([Permissions::CORE_EMPRESAS]);

        $props = $this->carga($semPolos);
        $this->assertFalse($props['pode_ver_polos']);
        $this->assertSame(0, $props['polos_total']);

        // Pedir a prop na mão também não traz a lista.
        $this->assertNull($this->listaDaAba($semPolos));
    }

    #[Test]
    public function time_operacional_dos_polos_ve_a_aba_e_o_atalho_do_painel(): void
    {
        $alfa = $this->polo('Alfa');
        $operacional = $this->usuarioCom([Permissions::CORE_EMPRESAS, 'mlb.projetos']);

        $props = $this->carga($operacional);
        $this->assertTrue($props['pode_ver_polos']);
        $this->assertTrue($props['pode_abrir_painel_polos']);
        $this->assertSame([$alfa->id], array_column($this->listaDaAba($operacional), 'id'));
    }

    #[Test]
    public function financeiro_dos_polos_ve_a_aba_mas_sem_atalho_para_o_painel(): void
    {
        $this->polo('Alfa');
        $financeiro = $this->usuarioCom([Permissions::CORE_EMPRESAS, Permissions::MLB_FATURAMENTO_POLOS]);

        $props = $this->carga($financeiro);
        $this->assertTrue($props['pode_ver_polos']);
        // O Painel Polos exige `mlb.projetos`: mostrar o botão seria levar a 403.
        $this->assertFalse($props['pode_abrir_painel_polos']);
        $this->assertFalse($props['pode_abrir_ficha_polos']);
    }

    // ─── A aba Empresas não muda ────────────────────────────────────────

    #[Test]
    public function empresa_com_vinculo_polos_segue_fora_da_aba_empresas(): void
    {
        $gestao = Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->firstOrFail();

        $company = Company::create([
            'name' => 'Com vinculo '.uniqid(),
            'cnpj' => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true, 'status' => 'ativo', 'empresa_nova' => false,
        ]);
        ContratoServico::create([
            'company_id' => $company->id, 'servico_id' => $gestao->id,
            'valor_contratado' => 1500, 'data_contratacao' => now()->toDateString(), 'ativo' => true,
        ]);
        $polo = $this->polo('Com vinculo', ['company_id' => $company->id]);

        $admin = $this->admin();

        $this->assertNotContains($company->id, array_column($this->carga($admin)['companies'], 'id'));

        $linha = collect($this->listaDaAba($admin))->firstWhere('id', $polo->id);
        $this->assertSame($company->id, $linha['company_id']);
    }
}
