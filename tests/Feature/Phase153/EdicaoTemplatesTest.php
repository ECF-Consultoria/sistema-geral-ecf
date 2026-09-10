<?php

namespace Tests\Feature\Phase153;

use App\Models\BoasVindasTemplate;
use App\Models\Servico;
use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Fase 153 (COMUNIC-03) — a tela de edição dos textos, sem deploy.
 *
 * Prova também as duas decisões que separam esta tela da de Padrões do MLB:
 * a permissão em OR (D-F) e o fato de salvar UM texto não tocar os outros — o
 * oposto do `salvarPadroes()`, que reescreve o JSON inteiro.
 */
class EdicaoTemplatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();
    }

    private function userComPermissaoViaSetor(string $permissionKey): User
    {
        $setor = Setor::create([
            'nome'   => 'Setor Edicao 153',
            'slug'   => 'edicao-153-'.uniqid(),
            'active' => true,
        ]);
        SetorPermissao::create(['setor_id' => $setor->id, 'permission_key' => $permissionKey]);
        $user = User::factory()->create(['role' => 'consultor']);
        $setor->membros()->attach($user->id, ['is_principal' => true, 'assigned_at' => now()]);

        return $user;
    }

    private function servico(string $nome): Servico
    {
        return Servico::create([
            'nome'           => $nome,
            'valor_padrao'   => 100,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_PERFORMANCE,
            'exige_contrato' => true,
        ]);
    }

    // ─── D-F — permissão em OR, sem chave nova ──────────────────────────────

    public function test_as_tres_rotas_usam_o_or_das_duas_permissoes(): void
    {
        $esperado = 'permission:'.Permissions::ADMIN_CONTRATOS.','.Permissions::COMERCIAL_ENTRADA;

        foreach (['admin.boas-vindas.index', 'admin.boas-vindas.salvar', 'admin.boas-vindas.remover'] as $nome) {
            $rota = Route::getRoutes()->getByName($nome);
            $this->assertNotNull($rota, "A rota {$nome} precisa existir.");
            $this->assertContains($esperado, $rota->gatherMiddleware(), "{$nome} deve usar o OR das duas permissões.");
        }
    }

    public function test_usuario_sem_nenhuma_das_duas_recebe_403(): void
    {
        $user = User::factory()->create(['role' => 'consultor']);

        $this->actingAs($user)->get(route('admin.boas-vindas.index'))->assertStatus(403);
    }

    public function test_perfil_de_entrada_abre_a_tela(): void
    {
        $user = $this->userComPermissaoViaSetor(Permissions::COMERCIAL_ENTRADA);

        $this->actingAs($user)->get(route('admin.boas-vindas.index'))->assertOk();
    }

    // ─── O genérico nunca chega vazio à tela ────────────────────────────────

    public function test_sem_generico_cadastrado_a_tela_mostra_o_texto_de_fabrica(): void
    {
        $user = $this->userComPermissaoViaSetor(Permissions::ADMIN_CONTRATOS);

        $props = $this->actingAs($user)->get(route('admin.boas-vindas.index'))->viewData('page')['props'];

        $this->assertFalse($props['generico']['cadastrado']);
        $this->assertSame(BoasVindasTemplate::TEXTO_GENERICO_PADRAO, $props['generico']['texto']);
        $this->assertNotEmpty($props['placeholders']);
    }

    // ─── COMUNIC-03 — editar sem deploy, com autoria ────────────────────────

    public function test_salvar_o_generico_grava_texto_e_autoria(): void
    {
        $user = $this->userComPermissaoViaSetor(Permissions::ADMIN_CONTRATOS);

        $this->actingAs($user)
            ->post(route('admin.boas-vindas.salvar'), ['texto' => 'NOVO TEXTO PADRAO'])
            ->assertStatus(302)
            ->assertSessionHas('success');

        $t = BoasVindasTemplate::generico();

        $this->assertSame('NOVO TEXTO PADRAO', $t->texto);
        $this->assertSame($user->id, $t->atualizado_por);
        $this->assertSame(1, BoasVindasTemplate::whereNull('servico_id')->count());
    }

    /**
     * A diferença que motivou a tela nova existir (D-C/D-F): salvar UM texto não
     * pode tocar os outros. O `salvarPadroes()` do MLB substitui o JSON inteiro
     * pelas chaves validadas, e é assim que chave nova some em silêncio.
     */
    public function test_salvar_um_servico_nao_apaga_os_outros_textos(): void
    {
        $user = $this->userComPermissaoViaSetor(Permissions::ADMIN_CONTRATOS);
        $a = $this->servico('Serviço A 153');
        $b = $this->servico('Serviço B 153');

        BoasVindasTemplate::salvarGenerico('GENERICO');
        BoasVindasTemplate::salvarParaServico($a->id, 'TEXTO A');

        $this->actingAs($user)
            ->post(route('admin.boas-vindas.salvar'), ['servico_id' => $b->id, 'texto' => 'TEXTO B'])
            ->assertStatus(302);

        $this->assertSame('GENERICO', BoasVindasTemplate::generico()->texto);
        $this->assertSame('TEXTO A', BoasVindasTemplate::where('servico_id', $a->id)->value('texto'));
        $this->assertSame('TEXTO B', BoasVindasTemplate::where('servico_id', $b->id)->value('texto'));
    }

    public function test_remover_o_texto_do_servico_o_devolve_ao_generico(): void
    {
        $user = $this->userComPermissaoViaSetor(Permissions::ADMIN_CONTRATOS);
        $s = $this->servico('Serviço C 153');

        BoasVindasTemplate::salvarGenerico('GENERICO');
        BoasVindasTemplate::salvarParaServico($s->id, 'PROPRIO');

        $this->actingAs($user)->delete(route('admin.boas-vindas.remover', $s))->assertStatus(302);

        $this->assertSame(0, BoasVindasTemplate::where('servico_id', $s->id)->count());
        $this->assertSame('GENERICO', BoasVindasTemplate::generico()->texto, 'remover o do serviço não pode tocar o genérico.');
    }

    public function test_texto_vazio_e_recusado(): void
    {
        $user = $this->userComPermissaoViaSetor(Permissions::ADMIN_CONTRATOS);

        $this->actingAs($user)
            ->post(route('admin.boas-vindas.salvar'), ['texto' => ''])
            ->assertSessionHasErrors('texto');

        $this->assertNull(BoasVindasTemplate::generico());
    }

    public function test_servico_inexistente_e_recusado(): void
    {
        $user = $this->userComPermissaoViaSetor(Permissions::ADMIN_CONTRATOS);

        $this->actingAs($user)
            ->post(route('admin.boas-vindas.salvar'), ['servico_id' => 999999, 'texto' => 'x'])
            ->assertSessionHasErrors('servico_id');
    }

    // ─── D-A — a mensagem do Polos não é tocada por esta tela ───────────────

    /**
     * Defesa da decisão: esta fase não migra nem reescreve o texto do Polos, que
     * segue em `mlb_configuracoes.implementacao_defaults`. Se alguém apontar
     * este controller para lá, este teste cai.
     */
    public function test_esta_tela_nao_escreve_na_configuracao_do_mlb(): void
    {
        $user = $this->userComPermissaoViaSetor(Permissions::ADMIN_CONTRATOS);

        $antes = \App\Models\MlbConfiguracao::get()->implementacao_defaults;

        $this->actingAs($user)->post(route('admin.boas-vindas.salvar'), ['texto' => 'IRRELEVANTE'])->assertStatus(302);

        $this->assertSame($antes, \App\Models\MlbConfiguracao::get()->fresh()->implementacao_defaults);
    }
}
