<?php

namespace Tests\Feature\Quick260916;

use App\Http\Controllers\ForaDoFechamentoController;
use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use App\Models\GrupoFaixaFaturamento;
use App\Models\Servico;
use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 260916-onn — as duas portas de "não participa do fechamento" (ficha da
 * empresa e tela de grupos), as rotas e a lista da tela do fechamento.
 */
class ForaDoFechamentoTelaTest extends TestCase
{
    use RefreshDatabase;

    private const MOTIVO = 'Contrato de valor fixo, sem tabela progressiva';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ─── Fixtures ─────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function userComPermissaoViaSetor(string $permissionKey): User
    {
        $setor = Setor::create([
            'nome'   => 'Setor Fora Fechamento '.uniqid(),
            'slug'   => 'fora-fechamento-teste-'.uniqid(),
            'active' => true,
        ]);
        SetorPermissao::create(['setor_id' => $setor->id, 'permission_key' => $permissionKey]);

        $user = User::factory()->create(['role' => 'consultor']);
        $setor->membros()->attach($user->id, ['is_principal' => true, 'assigned_at' => now()]);

        return $user;
    }

    private function criarEmpresa(string $nome, array $overrides = []): Company
    {
        $servico = Servico::firstOrCreate(
            ['nome' => 'Gestão'],
            ['valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true]
        );
        $servico->update(['plataforma' => 'Mercado Livre', 'setor' => Servico::SETOR_PERFORMANCE]);

        $company = Company::factory()->create(array_merge([
            'name'             => $nome,
            'adman_account_id' => 'cust-'.uniqid(),
        ], $overrides));

        ContratoServico::factory()->paraServico($servico)->create([
            'company_id'       => $company->id,
            'ativo'            => true,
            'data_contratacao' => '2026-01-10',
            'valor_contratado' => 0,
        ]);

        AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => '2026-08-10',
            'revenue'        => 100_000.00,
        ]);

        return $company;
    }

    private function inertiaHeaders(): array
    {
        return [
            'X-Inertia'         => 'true',
            'X-Inertia-Version' => (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request()),
        ];
    }

    private function props(TestResponse $response): array
    {
        return json_decode($response->getContent(), true)['props'];
    }

    // ─── Empresa ──────────────────────────────────────────────────────────

    #[Test]
    public function marcar_empresa_grava_por_e_em_pela_sessao_ignorando_o_corpo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-16 10:00:00'));
        $admin   = $this->admin();
        $outro   = User::factory()->create();
        $empresa = $this->criarEmpresa('Rações Soldera');

        $this->actingAs($admin)
            ->post(route('admin.contratos.fora-fechamento.empresa.marcar', $empresa), [
                'motivo'                 => self::MOTIVO,
                'fora_do_fechamento_por' => $outro->id,
                'fora_do_fechamento_em'  => '2020-01-01 00:00:00',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $empresa->refresh();
        $this->assertTrue($empresa->fora_do_fechamento);
        $this->assertSame(self::MOTIVO, $empresa->fora_do_fechamento_motivo);
        $this->assertSame($admin->id, $empresa->fora_do_fechamento_por, 'Quem marcou é o usuário da sessão.');
        $this->assertSame('2026-09-16 10:00:00', $empresa->fora_do_fechamento_em->format('Y-m-d H:i:s'));

        // Trilha.
        $this->assertTrue(
            DB::table('activity_log')
                ->where('subject_type', Company::class)
                ->where('subject_id', $empresa->id)
                ->where('properties', 'like', '%fora_do_fechamento%')
                ->exists(),
            'Marcar fica registrado na trilha da empresa.'
        );
    }

    #[Test]
    public function marcar_sem_motivo_ou_com_motivo_curto_da_422(): void
    {
        $admin   = $this->admin();
        $empresa = $this->criarEmpresa('Sem Motivo');
        $grupo   = CompanyGroup::create(['name' => 'Grupo Sem Motivo', 'color' => '#000']);

        $this->actingAs($admin)
            ->postJson(route('admin.contratos.fora-fechamento.empresa.marcar', $empresa), [])
            ->assertStatus(422)
            ->assertJsonPath('errors.motivo.0', 'Diga por que não participa do fechamento.');

        $this->actingAs($admin)
            ->postJson(route('admin.contratos.fora-fechamento.empresa.marcar', $empresa), ['motivo' => 'curto'])
            ->assertStatus(422)
            ->assertJsonPath('errors.motivo.0', 'Escreva um motivo com pelo menos 10 caracteres.');

        $this->actingAs($admin)
            ->postJson(route('admin.contratos.fora-fechamento.grupo.marcar', $grupo), [])
            ->assertStatus(422);

        $this->assertFalse($empresa->refresh()->fora_do_fechamento);
        $this->assertFalse($grupo->refresh()->fora_do_fechamento);
    }

    #[Test]
    public function quem_nao_tem_permissao_de_contratos_leva_403(): void
    {
        $empresa = $this->criarEmpresa('Protegida');
        $grupo   = CompanyGroup::create(['name' => 'Grupo Protegido', 'color' => '#000']);

        $semNada  = User::factory()->create(['role' => 'consultor']);
        $soEntrada = $this->userComPermissaoViaSetor(Permissions::COMERCIAL_ENTRADA);

        foreach ([$semNada, $soEntrada] as $user) {
            $this->actingAs($user)
                ->post(route('admin.contratos.fora-fechamento.empresa.marcar', $empresa), ['motivo' => self::MOTIVO])
                ->assertForbidden();
            $this->actingAs($user)
                ->delete(route('admin.contratos.fora-fechamento.empresa.desmarcar', $empresa))
                ->assertForbidden();
            $this->actingAs($user)
                ->post(route('admin.contratos.fora-fechamento.grupo.marcar', $grupo), ['motivo' => self::MOTIVO])
                ->assertForbidden();
            $this->actingAs($user)
                ->delete(route('admin.contratos.fora-fechamento.grupo.desmarcar', $grupo))
                ->assertForbidden();
        }

        $this->assertFalse($empresa->refresh()->fora_do_fechamento);
        $this->assertFalse($grupo->refresh()->fora_do_fechamento);

        // O controller repete a checagem (a ficha também abre para a Entrada).
        $this->assertStringContainsString(
            'abort_unless',
            file_get_contents(app_path('Http/Controllers/ForaDoFechamentoController.php'))
        );
    }

    #[Test]
    public function quem_tem_permissao_de_contratos_por_setor_consegue_marcar(): void
    {
        $user    = $this->userComPermissaoViaSetor(Permissions::ADMIN_CONTRATOS);
        $empresa = $this->criarEmpresa('Por Setor');

        $this->actingAs($user)
            ->post(route('admin.contratos.fora-fechamento.empresa.marcar', $empresa), ['motivo' => self::MOTIVO])
            ->assertRedirect();

        $this->assertTrue($empresa->refresh()->fora_do_fechamento);
        $this->assertSame($user->id, $empresa->fora_do_fechamento_por);
    }

    #[Test]
    public function desmarcar_empresa_volta_a_entrar_e_fica_na_trilha(): void
    {
        $admin   = $this->admin();
        $empresa = $this->criarEmpresa('Vai E Volta');

        $this->actingAs($admin)->post(route('admin.contratos.fora-fechamento.empresa.marcar', $empresa), ['motivo' => self::MOTIVO]);
        $antes = DB::table('activity_log')->where('subject_type', Company::class)->where('subject_id', $empresa->id)->count();

        $this->actingAs($admin)
            ->delete(route('admin.contratos.fora-fechamento.empresa.desmarcar', $empresa))
            ->assertRedirect();

        $empresa->refresh();
        $this->assertFalse($empresa->fora_do_fechamento);
        $this->assertNull($empresa->fora_do_fechamento_motivo);
        $this->assertNull($empresa->fora_do_fechamento_por);
        $this->assertNull($empresa->fora_do_fechamento_em);

        $this->assertSame($antes + 1, DB::table('activity_log')->where('subject_type', Company::class)->where('subject_id', $empresa->id)->count(), 'Desmarcar fica na trilha.');

        // Volta a entrar no fechamento ao vivo.
        Carbon::setTestNow(Carbon::parse('2026-09-15'));
        $ids = collect($this->actingAs($admin)->get('/administrativo/financeiro?mes=2026-08')->assertOk()->viewData('page')['props']['companies'])->pluck('id')->all();
        $this->assertContains($empresa->id, $ids);
    }

    #[Test]
    public function marcar_nao_gera_contrato(): void
    {
        $admin   = $this->admin();
        $empresa = $this->criarEmpresa('Sem Contrato Novo', [
            'cnpj'          => null,
            'email_cliente' => null,
            'nome_contato'  => null,
        ]);
        $grupo = CompanyGroup::create(['name' => 'Grupo Sem Contrato', 'color' => '#000']);
        $empresa->update(['company_group_id' => $grupo->id]);

        $antes = ContratoAssinatura::count();

        $this->actingAs($admin)->post(route('admin.contratos.fora-fechamento.empresa.marcar', $empresa), ['motivo' => self::MOTIVO]);
        $this->actingAs($admin)->delete(route('admin.contratos.fora-fechamento.empresa.desmarcar', $empresa));
        $this->actingAs($admin)->post(route('admin.contratos.fora-fechamento.grupo.marcar', $grupo), ['motivo' => self::MOTIVO]);

        $this->assertSame($antes, ContratoAssinatura::count(), 'Nenhum contrato criado por marcar/desmarcar.');
    }

    // ─── Grupo ────────────────────────────────────────────────────────────

    #[Test]
    public function marcar_grupo_grava_trilha_e_mantem_a_tabela(): void
    {
        $admin = $this->admin();
        $wenus = CompanyGroup::create(['name' => 'Wenus', 'color' => '#000']);
        GrupoFaixaFaturamento::create([
            'company_group_id' => $wenus->id, 'ordem' => 1,
            'limite_superior'  => null, 'valor' => 4_000.00, 'valor_e_piso' => true,
        ]);
        $this->criarEmpresa('Wenus A', ['company_group_id' => $wenus->id]);

        $this->actingAs($admin)
            ->post(route('admin.contratos.fora-fechamento.grupo.marcar', $wenus), [
                'motivo'                 => 'Valor fixo de R$ 4.000 por mês',
                'fora_do_fechamento_por' => 999,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $wenus->refresh();
        $this->assertTrue($wenus->fora_do_fechamento);
        $this->assertSame($admin->id, $wenus->fora_do_fechamento_por);
        $this->assertNotNull($wenus->fora_do_fechamento_em);

        $this->assertSame(1, GrupoFaixaFaturamento::where('company_group_id', $wenus->id)->count(), 'Marcar não apaga a tabela.');

        $log = DB::table('activity_log')
            ->where('log_name', ForaDoFechamentoController::LOG_NAME)
            ->where('subject_id', $wenus->id)
            ->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, (int) $log->causer_id);
        $this->assertSame('Valor fixo de R$ 4.000 por mês', json_decode($log->properties, true)['motivo']);

        $this->actingAs($admin)
            ->delete(route('admin.contratos.fora-fechamento.grupo.desmarcar', $wenus))
            ->assertRedirect();

        $this->assertFalse($wenus->refresh()->fora_do_fechamento);
        $this->assertSame(2, DB::table('activity_log')->where('log_name', ForaDoFechamentoController::LOG_NAME)->where('subject_id', $wenus->id)->count(), 'Desmarcar o grupo fica na trilha.');
        $this->assertSame(1, GrupoFaixaFaturamento::where('company_group_id', $wenus->id)->count());
    }

    #[Test]
    public function a_tela_de_grupos_mostra_quem_marcou_nos_grupos_e_nos_de_dentro(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-16 10:00:00'));
        $admin = $this->admin();
        $mae   = CompanyGroup::create(['name' => 'Mãe', 'color' => '#000']);
        $filho = CompanyGroup::create(['name' => 'Filho', 'color' => '#000', 'parent_id' => $mae->id]);
        $solto = CompanyGroup::create(['name' => 'Solto', 'color' => '#000']);
        $this->criarEmpresa('Do Filho', ['company_group_id' => $filho->id]);
        $this->criarEmpresa('Do Solto', ['company_group_id' => $solto->id]);

        $solto->update([
            'fora_do_fechamento'        => true,
            'fora_do_fechamento_motivo' => self::MOTIVO,
            'fora_do_fechamento_por'    => $admin->id,
            'fora_do_fechamento_em'     => now(),
        ]);
        $filho->update(['fora_do_fechamento' => true, 'fora_do_fechamento_motivo' => self::MOTIVO]);

        $grupos = collect($this->props(
            $this->actingAs($admin)->withHeaders($this->inertiaHeaders())->get(route('admin.contratos.grupos.index', ['mes' => '2026-08']))->assertOk()
        )['grupos'])->keyBy('id');

        $this->assertSame([
            'marcado'  => true,
            'motivo'   => self::MOTIVO,
            'por_nome' => $admin->name,
            'em'       => now()->toIso8601String(),
        ], $grupos[$solto->id]['fora_do_fechamento']);
        $this->assertFalse($grupos[$mae->id]['fora_do_fechamento']['marcado']);
        $this->assertTrue($grupos[$mae->id]['dentro'][0]['fora_do_fechamento']['marcado']);
    }

    // ─── Ficha da empresa ────────────────────────────────────────────────

    #[Test]
    public function a_ficha_da_empresa_mostra_a_marcacao_e_o_grupo_que_a_tira(): void
    {
        $admin = $this->admin();
        $mae   = CompanyGroup::create(['name' => 'Cobrança Mãe', 'color' => '#000']);
        $filho = CompanyGroup::create(['name' => 'Filho', 'color' => '#000', 'parent_id' => $mae->id]);
        $mae->update(['fora_do_fechamento' => true, 'fora_do_fechamento_motivo' => self::MOTIVO]);

        $doFilho = $this->criarEmpresa('Empresa Do Filho', ['company_group_id' => $filho->id]);
        $solta   = $this->criarEmpresa('Empresa Solta');

        $propsFilho = $this->props(
            $this->actingAs($admin)->withHeaders($this->inertiaHeaders())->get(route('admin.contratos.show', $doFilho))->assertOk()
        );
        $this->assertFalse($propsFilho['fora_do_fechamento']['marcado']);
        $this->assertSame($mae->id, $propsFilho['fora_do_fechamento']['grupo']['id']);
        $this->assertSame(self::MOTIVO, $propsFilho['fora_do_fechamento']['grupo']['motivo']);

        $this->actingAs($admin)->post(route('admin.contratos.fora-fechamento.empresa.marcar', $solta), ['motivo' => self::MOTIVO]);

        $propsSolta = $this->props(
            $this->actingAs($admin)->withHeaders($this->inertiaHeaders())->get(route('admin.contratos.show', $solta))->assertOk()
        );
        $this->assertTrue($propsSolta['fora_do_fechamento']['marcado']);
        $this->assertSame($admin->name, $propsSolta['fora_do_fechamento']['por_nome']);
        $this->assertNull($propsSolta['fora_do_fechamento']['grupo']);

        // Quem só tem a Entrada abre a ficha, mas não vê (nem pode usar) a marcação.
        $entrada = $this->userComPermissaoViaSetor(Permissions::COMERCIAL_ENTRADA);
        $propsEntrada = $this->props(
            $this->actingAs($entrada)->withHeaders($this->inertiaHeaders())->get(route('admin.contratos.show', $solta))->assertOk()
        );
        $this->assertNull($propsEntrada['fora_do_fechamento']);
    }

    // ─── Tela do fechamento ───────────────────────────────────────────────

    #[Test]
    public function a_tela_do_fechamento_tira_os_marcados_e_lista_quem_nao_participa_com_link(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));
        $admin = $this->admin();

        $normal  = $this->criarEmpresa('Cliente Normal');
        $soldera = $this->criarEmpresa('Rações Soldera');
        $soldera->update(['fora_do_fechamento' => true, 'fora_do_fechamento_motivo' => 'Contrato de Brigada sem tabela progressiva']);

        $wenus = CompanyGroup::create(['name' => 'Wenus', 'color' => '#000']);
        $sub   = CompanyGroup::create(['name' => 'Wenus Sul', 'color' => '#000', 'parent_id' => $wenus->id]);
        $wa    = $this->criarEmpresa('Wenus A', ['company_group_id' => $wenus->id]);
        $wb    = $this->criarEmpresa('Wenus B', ['company_group_id' => $sub->id]);
        $wenus->update(['fora_do_fechamento' => true, 'fora_do_fechamento_motivo' => 'Valor fixo de R$ 4.000 por mês']);

        $props = $this->actingAs($admin)->get('/administrativo/financeiro?mes=2026-08')
            ->assertOk()
            ->viewData('page')['props'];

        $linhas = collect($props['companies']);
        $ids    = $linhas->pluck('id')->all();
        $this->assertContains($normal->id, $ids);
        $this->assertNotContains($soldera->id, $ids);
        $this->assertNotContains($wa->id, $ids);
        $this->assertNotContains($wb->id, $ids);
        $this->assertFalse($linhas->contains(fn ($l) => ($l['company_group_id'] ?? null) === $wenus->id), 'A linha do grupo não aparece.');

        $this->assertSame([
            [
                'tipo'     => 'grupo',
                'id'       => $wenus->id,
                'name'     => 'Wenus',
                'motivo'   => 'Valor fixo de R$ 4.000 por mês',
                'empresas' => ['Wenus A', 'Wenus B'],
                'url'      => '/administrativo/contratos/grupos',
            ],
            [
                'tipo'     => 'empresa',
                'id'       => $soldera->id,
                'name'     => 'Rações Soldera',
                'motivo'   => 'Contrato de Brigada sem tabela progressiva',
                'empresas' => [],
                'url'      => '/administrativo/contratos/empresa/'.$soldera->id,
            ],
        ], $props['nao_participam_do_fechamento']);

        // Nunca chave nova nas linhas.
        foreach ($props['companies'] as $linha) {
            $this->assertArrayNotHasKey('nao_participam_do_fechamento', $linha);
            $this->assertArrayNotHasKey('fora_do_fechamento', $linha);
        }
    }

    #[Test]
    public function sem_ninguem_marcado_a_lista_vem_vazia(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));
        $this->criarEmpresa('Cliente Normal');

        $props = $this->actingAs($this->admin())->get('/administrativo/financeiro?mes=2026-08')->assertOk()->viewData('page')['props'];

        $this->assertSame([], $props['nao_participam_do_fechamento']);
    }

    // ─── Copy ─────────────────────────────────────────────────────────────

    #[Test]
    public function a_copy_nova_nao_tem_jargao(): void
    {
        $financeiro = file_get_contents(resource_path('js/Pages/Admin/Financeiro.jsx'));
        $this->assertStringContainsString('<NaoParticipamAviso itens={nao_participam_do_fechamento} mesFechado={competencia_fechada} />', $financeiro);

        $inicio = strpos($financeiro, 'function NaoParticipamAviso');
        $fim    = strpos($financeiro, 'function SemDataInicioAviso', $inicio);
        $blocos = [
            'Financeiro.jsx'             => substr($financeiro, $inicio, $fim - $inicio),
            'ParticipacaoFechamento.jsx' => file_get_contents(resource_path('js/Components/Fechamento/ParticipacaoFechamento.jsx')),
        ];

        $detalhe = file_get_contents(resource_path('js/Pages/Admin/ContratoDetalhe.jsx'));
        $i       = strpos($detalhe, 'Quick 260916-onn — porta da EMPRESA');
        $this->assertNotFalse($i);
        $blocos['ContratoDetalhe.jsx'] = substr($detalhe, $i, strpos($detalhe, '</Card>', $i) - $i);

        $grupos = file_get_contents(resource_path('js/Pages/Admin/GruposCobranca.jsx'));
        $j      = strpos($grupos, 'function ParticipacaoDoGrupo');
        $this->assertNotFalse($j);
        $blocos['GruposCobranca.jsx'] = substr($grupos, $j, strpos($grupos, '/** Um grupo de cobrança já montado', $j) - $j);

        $this->assertStringContainsString('Não participam do fechamento', $blocos['Financeiro.jsx']);
        $this->assertStringContainsString('Não participa do fechamento', $blocos['ParticipacaoFechamento.jsx']);

        foreach ($blocos as $arquivo => $bloco) {
            // Só o texto visível: sem comentários e sem identificadores.
            $texto = preg_replace('/^[ \t]*\/\/.*$/m', '', preg_replace('/\/\*.*?\*\//s', '', preg_replace('/\{\/\*.*?\*\/\}/s', '', $bloco)));
            preg_match_all('/>([^<>{}]+)</u', $texto, $m1);
            preg_match_all("/'([^'\\n]*)'/u", $texto, $m2);
            preg_match_all('/`([^`]*)`/u', $texto, $m3);
            $visivel = implode(' | ', array_merge($m1[1], $m2[1], $m3[1]));

            foreach (['flag', 'snapshot', 'competência', 'rollup', 'raiz'] as $termo) {
                $this->assertStringNotContainsStringIgnoringCase($termo, $visivel, "Termo técnico em {$arquivo}: {$termo}");
            }
        }

        // `fora_do_fechamento` só como identificador, nunca como texto na tela.
        foreach ($blocos as $arquivo => $bloco) {
            preg_match_all('/>([^<>{}]+)</u', $bloco, $m);
            $this->assertStringNotContainsString('fora_do_fechamento', implode(' ', $m[1]), "Identificador na tela: {$arquivo}");
        }
    }

    #[Test]
    public function as_classes_de_tailwind_novas_existem_na_escala(): void
    {
        $conteudo = file_get_contents(resource_path('js/Components/Fechamento/ParticipacaoFechamento.jsx'))
            .file_get_contents(resource_path('js/Pages/Admin/Financeiro.jsx'));

        foreach (['px-4.5', 'gap-4.5', 'py-5.5', 'py-4.5', 'px-5.5'] as $inexistente) {
            $this->assertStringNotContainsString($inexistente, $conteudo);
        }
    }
}
