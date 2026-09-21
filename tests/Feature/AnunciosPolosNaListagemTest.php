<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\MlToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `/mlb/anuncios` passa a listar as empresas de Polos que autorizaram o ML.
 *
 * Elas sempre estiveram invisíveis aqui: o painel listava
 * `Company::whereHas('mlToken')`, e empresa de Polos não tem `Company` nem
 * (até 21/09/2026) token guardado.
 *
 * `withoutVite()` de propósito: sem ele o teste depende de
 * `public/build/manifest.json` e devolve 500 em qualquer worktree sem build —
 * foi assim que o teste irmão deste módulo ficou cego sem ninguém notar.
 */
class AnunciosPolosNaListagemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Empresa de Polos que passou pelo OAuth (com o carimbo que o callback grava). */
    private function empresaDePolos(string $nome, bool $autorizou = true, bool $arquivada = false): MlbEmpresa
    {
        $empresa = MlbEmpresa::create([
            'nome'         => $nome,
            'projeto'      => 'POLOS',
            'cust_id'      => '465723451',
            'arquivado_em' => $arquivada ? now() : null,
        ]);

        MlbImplementacao::create([
            'empresa_id' => $empresa->id,
            'token'      => 'tok-' . $empresa->id . '-' . uniqid(),
            'dados'      => $autorizou
                ? ['ml_oauth' => [
                    'autorizado_em' => '2026-08-12T14:03:00.000000Z',
                    'cust_id'       => '465723451',
                    'nickname'      => 'UNITYMOVEIS',
                    'divergente'    => false,
                ]]
                : ['itens' => []],
        ]);

        return $empresa->fresh();
    }

    private function cards(): array
    {
        return $this->actingAs($this->admin())
            ->get(route('mlb.anuncios.index'))
            ->assertOk()
            ->viewData('page')['props']['empresas'];
    }

    public function test_empresa_de_polos_que_autorizou_aparece_no_painel(): void
    {
        $this->empresaDePolos('Unity Móveis');

        $nomes = collect($this->cards())->pluck('nome');

        $this->assertContains('Unity Móveis', $nomes->all());
    }

    public function test_empresa_de_polos_sem_autorizacao_nao_aparece(): void
    {
        // Cust ID preenchido à mão por um consultor NÃO é autorização — o
        // critério é o carimbo que só o callback do OAuth grava.
        $this->empresaDePolos('Nunca Autorizou', autorizou: false);

        $nomes = collect($this->cards())->pluck('nome');

        $this->assertNotContains('Nunca Autorizou', $nomes->all());
    }

    public function test_empresa_de_polos_arquivada_nao_aparece(): void
    {
        // scopeAtivas() — empresa arquivada saiu do projeto e não entra em
        // listagem nenhuma de Polos (learnings de Polos §3).
        $this->empresaDePolos('Saiu do Projeto', arquivada: true);

        $nomes = collect($this->cards())->pluck('nome');

        $this->assertNotContains('Saiu do Projeto', $nomes->all());
    }

    public function test_quem_autorizou_antes_da_correcao_aparece_como_falta_reconectar(): void
    {
        $empresa = $this->empresaDePolos('Unity Móveis');

        $card = collect($this->cards())->firstWhere('nome', 'Unity Móveis');

        $this->assertSame('polos', $card['origem']);
        $this->assertFalse($card['conectada'], 'Sem token guardado, a empresa não está conectada.');
        $this->assertFalse($card['pode_publicar'], 'Card sem token não pode levar ao wizard.');
        $this->assertNotNull($card['link_reconexao'], 'Precisa oferecer o caminho de reconexão.');
        $this->assertSame('empresa-' . $empresa->id, $card['id'], 'A âncora do card é a MlbEmpresa, não uma Company.');
        $this->assertStringContainsString('2026', (string) $card['autorizado_em']);
    }

    public function test_empresa_de_polos_com_token_aparece_como_conectada(): void
    {
        $empresa = $this->empresaDePolos('Já Reconectou');

        MlToken::create([
            'mlb_empresa_id' => $empresa->id,
            'ml_user_id'     => '465723451',
            'access_token'   => 'APP_USR-x',
            'refresh_token'  => 'TG-x',
            'expires_at'     => now()->addHours(5),
            'status'         => 'active',
        ]);

        $card = collect($this->cards())->firstWhere('nome', 'Já Reconectou');

        $this->assertTrue($card['conectada']);
        $this->assertTrue($card['tem_token']);
    }

    public function test_company_conectada_segue_igual_e_clicavel(): void
    {
        // Regressão: a fonte antiga não pode mudar de forma. O `id` continua
        // numérico, senão as URLs e favoritos existentes quebram.
        $company = Company::factory()->create(['name' => 'Cliente Consultoria']);

        MlToken::create([
            'company_id'    => $company->id,
            'ml_user_id'    => '111222333',
            'access_token'  => 'APP_USR-y',
            'refresh_token' => 'TG-y',
            'expires_at'    => now()->addHours(5),
            'status'        => 'active',
        ]);

        $card = collect($this->cards())->firstWhere('nome', 'Cliente Consultoria');

        $this->assertSame($company->id, $card['id']);
        $this->assertSame('consultoria', $card['origem']);
        $this->assertTrue($card['pode_publicar']);
    }
}
