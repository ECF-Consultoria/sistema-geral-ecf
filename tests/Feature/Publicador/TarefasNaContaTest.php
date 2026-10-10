<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubTarefa;
use App\Models\User;
use App\Services\Publicador\AcervoTriagemService;
use App\Services\Publicador\PainelVisaoGeralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Publicador\Concerns\CenarioTarefas;
use Tests\TestCase;

/**
 * As tarefas pós-publicação dentro da conta (09/10/2026): a linha "Publicados aguardando alavancas" no
 * "O que fazer agora" (acrescentada no fim, sem mexer nas linhas do handoff), a contagem da aba
 * Alavancas, o `?item=` das Alavancas e o contador do menu. Isolamento por conta em tudo.
 */
class TarefasNaContaTest extends TestCase
{
    use CenarioTarefas;
    use RefreshDatabase;

    private const BASE = '/mlb/anuncios/publicador';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function polo(?Company $company = null): MlbEmpresa
    {
        $e = MlbEmpresa::create(['nome' => 'Polo X', 'projeto' => 'POLOS', 'company_id' => $company?->id])->fresh();
        MlToken::create([
            'mlb_empresa_id' => $e->id, 'ml_user_id' => '123456', 'access_token' => 'APP_USR-x',
            'refresh_token' => 'TG-x', 'expires_at' => now()->addHours(5), 'status' => 'active',
        ]);

        return $e;
    }

    private function props(string $url): array
    {
        return $this->actingAs($this->admin)->get($url)->assertOk()->viewData('page')['props'];
    }

    public function test_visao_geral_ganha_a_linha_no_fim_e_a_aba_alavancas_conta_so_as_abertas_da_conta(): void
    {
        $e = $this->polo();
        $this->tarefaDa($e, ['MLB1' => 'gold_special']);
        $this->tarefaDa($e, ['MLB2' => 'gold_special']);
        $this->tarefaDa($e, ['MLB3' => 'gold_special'])->update(['status' => PubTarefa::FEITA, 'concluida_em' => now()]);
        $this->tarefaDa($this->polo(), ['MLB4' => 'gold_special']); // outra conta

        $p = $this->props(self::BASE."/empresas/empresa-{$e->id}/visao-geral");

        $linha = collect($p['oQueFazerAgora'])->firstWhere('texto', 'Publicados aguardando alavancas');
        $this->assertNotNull($linha);
        $this->assertSame(2, $linha['numero']);
        $this->assertSame(['rota' => 'mlb.anuncios.publicador.tarefas.index', 'params' => ['conta' => "empresa-{$e->id}"]], $linha['destino']);
        $this->assertSame($linha, collect($p['oQueFazerAgora'])->last(), 'acrescentada no fim: a ordem do handoff fica');
        $this->assertSame(2, $p['abas']['alavancas_pendentes']);

        $this->assertSame(2, $this->props(self::BASE."/empresas/empresa-{$e->id}")['abas']['alavancas_pendentes'], 'aba Produtos também');
    }

    public function test_sem_tarefa_aberta_nao_aparece_linha_nem_contagem(): void
    {
        $e = $this->polo();

        $p = $this->props(self::BASE."/empresas/empresa-{$e->id}/visao-geral");

        $this->assertNull(collect($p['oQueFazerAgora'])->firstWhere('texto', 'Publicados aguardando alavancas'));
        $this->assertSame(0, $p['abas']['alavancas_pendentes']);
    }

    public function test_o_servico_acrescenta_a_linha_por_ultimo_sem_mexer_nas_outras(): void
    {
        $company = Company::factory()->create();
        $this->tarefaDa($company, ['MLB1' => 'gold_special']);
        $servico = new PainelVisaoGeralService(new AcervoTriagemService());
        $alvo = ['mlb_empresa' => null, 'company' => $company, 'programa' => 'gestao', 'chave' => $company->chaveContaMl()];
        $tela = ['token' => 'ativo', 'link_reconexao' => null, 'company_id' => $company->id, 'portal' => []];

        $linhas = $servico->oQueFazerAgora($alvo, $tela, ['com_problema' => 2, 'publicados' => 0, 'conferidos' => 1], ['chips' => []], [], ['situacao' => 'sincronizado', 'novas' => 0]);

        $this->assertSame(['Produtos com problema na publicação', 'Conferidos, prontos para publicar', 'Publicados aguardando alavancas'], array_column($linhas, 'texto'));

        // Conta que precisa de reconexão continua com UMA linha só (regra do handoff).
        $this->assertCount(1, $servico->oQueFazerAgora($alvo, ['token' => 'expirado', 'link_reconexao' => null] + $tela, [], ['chips' => []], [], []));
    }

    public function test_alavancas_aceita_o_item_da_tarefa_e_conta_as_abertas(): void
    {
        $e = $this->polo();
        $this->tarefaDa($e, ['MLB1001' => 'gold_special']);

        $p = $this->props(self::BASE."/empresas/empresa-{$e->id}/alavancas?aba=promocoes&item=MLB1001");
        $this->assertSame('MLB1001', $p['alavancas']['item']);
        $this->assertSame(1, $p['alavancas']['tarefas_abertas']);

        $this->assertNull($this->props(self::BASE."/empresas/empresa-{$e->id}/alavancas?item=MLB1001;DROP")['alavancas']['item']);
        $this->assertNull($this->props(self::BASE."/empresas/empresa-{$e->id}/alavancas")['alavancas']['item']);
    }

    public function test_o_link_da_fila_pela_company_chega_na_chave_canonica_com_aba_e_item(): void
    {
        $company = Company::factory()->create();
        $e = $this->polo($company);

        $this->actingAs($this->admin)
            ->get(self::BASE."/empresas/company-{$company->id}/alavancas?aba=promocoes&item=MLB1001")
            ->assertRedirect(route('mlb.anuncios.publicador.alavancas.index', ['conta' => "empresa-{$e->id}", 'aba' => 'promocoes', 'item' => 'MLB1001']));

        // Aba ou item fora do formato não viajam.
        $this->actingAs($this->admin)
            ->get(self::BASE."/empresas/company-{$company->id}/alavancas?aba=inventada&item=xyz")
            ->assertRedirect(route('mlb.anuncios.publicador.alavancas.index', ['conta' => "empresa-{$e->id}"]));
    }

    public function test_contador_do_menu_sao_as_abertas_minhas_ou_de_ninguem_e_so_para_quem_ve_a_fila(): void
    {
        $company = Company::factory()->create();
        $caio = $this->comPermissao(nome: 'Caio');
        $this->tarefaDa($company, ['MLB1' => 'gold_special']);                                    // de ninguém
        $this->tarefaDa($company, ['MLB2' => 'gold_special'])->update(['responsavel_id' => $caio->id]);
        $this->tarefaDa($company, ['MLB3' => 'gold_special'])->update(['responsavel_id' => $this->admin->id]);
        $this->tarefaDa($company, ['MLB4' => 'gold_special'])->update(['responsavel_id' => $caio->id, 'status' => PubTarefa::FEITA]);

        $contador = fn (User $u) => $this->actingAs($u)->get(self::BASE.'/tarefas')->viewData('page')['props']['tarefas_alavancas'];

        $this->assertSame(2, $contador($caio), 'a dele aberta + a de ninguém');
        $this->assertSame(2, $contador($this->admin), 'a dele + a de ninguém; a do Caio não');

        $consultor = User::factory()->create(['role' => 'consultor']);
        $this->assertSame(0, $this->actingAs($consultor)->get('/notificacoes')->viewData('page')['props']['tarefas_alavancas']);
    }
}
