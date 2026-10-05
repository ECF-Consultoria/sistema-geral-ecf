<?php

namespace Tests\Feature\Phase165;

use App\Models\Configuracao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Phase165\Concerns\CenarioCriativoDoPublicador;
use Tests\TestCase;

/**
 * Fase 165, plano 07, Task 1: a página do editor do Publicador (`Mlb/Publicador/Editor`)
 * recebe a prop `criativos_ia` — mesma chave/permissão do Creative Engine (D-06), mas SEM
 * exigir Company (diferente da ponte `criativos_ia.url` da tela de produtos, Fase 164).
 */
class EditorCriativosIaPropTest extends TestCase
{
    use CenarioCriativoDoPublicador;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function criativosIa(): bool
    {
        $page = $this->actingAs($this->admin())
            ->get('/mlb/anuncios/publicador/produtos/'.$this->produto->id.'/editor')
            ->assertOk()
            ->viewData('page');

        return $page['props']['criativos_ia'];
    }

    public function test_admin_com_chave_ligada_e_lista_vazia_recebe_true(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');

        $this->assertTrue($this->criativosIa());
    }

    public function test_chave_desligada_recebe_false(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        Configuracao::set('creative_engine_ativo', '0');

        $this->assertFalse($this->criativosIa());
    }

    public function test_admin_fora_da_lista_creative_engine_usuarios_recebe_false(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $outro = User::factory()->create(['role' => 'admin']);
        Configuracao::set('creative_engine_usuarios', (string) $outro->id);

        $this->assertFalse($this->criativosIa());
    }

    public function test_produto_de_mlb_empresa_sem_company_recebe_true(): void
    {
        // D-06: a loja vem da conta do PRODUTO (`PubProduto::contaOuNula()`), nunca exige
        // Company — diferente da ponte `criativos_ia.url` da tela de produtos (Fase 164).
        $this->montarCenarioCriativo('mlb_empresa');

        $this->assertNull($this->produto->company_id);
        $this->assertTrue($this->criativosIa());
    }
}
