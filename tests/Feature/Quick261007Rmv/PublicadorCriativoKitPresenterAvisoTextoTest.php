<?php

namespace Tests\Feature\Quick261007Rmv;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Phase165\Concerns\CenarioCriativoDoPublicador;
use Tests\TestCase;

/**
 * Quick 261007-rmv — `pode_ter_texto`/`faltam` chegam na resposta de
 * `GET .../criativos/kit/{kit}` (`PublicadorCriativoKitPresenter::paraTela()`),
 * lidos de `kit.plano` (gravado por `CreativePlanner::planejar()` no
 * momento do planejamento) — não há mais endpoint dedicado de "fatos"
 * (removido junto com o bloco de confirmação manual, Fase 169).
 */
class PublicadorCriativoKitPresenterAvisoTextoTest extends TestCase
{
    use CenarioCriativoDoPublicador;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioCriativo('mlb_empresa');
    }

    private function rotaStatus(int $kit): string
    {
        return route('mlb.anuncios.publicador.criativos.kit.status', ['produto' => $this->produto->id, 'kit' => $kit]);
    }

    public function test_kit_com_pode_ter_texto_falso_no_plano_devolve_faltam_preenchido(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 1);
        $kit->update(['plano' => [
            'estrategia' => ['publico' => 'a', 'proposta_de_valor' => 'b', 'direcao_visual' => 'c'],
            'pode_ter_texto' => false,
            'faltam' => ['Confirme mais 3 ponto(s) forte(s) do produto no cadastro do Mercado Livre para habilitar texto no slot de benefícios.'],
        ]]);

        $resp = $this->actingAs($this->admin())->getJson($this->rotaStatus($kit->id));

        $resp->assertOk();
        $resp->assertJsonPath('pode_ter_texto', false);
        $resp->assertJsonCount(1, 'faltam');
        $resp->assertJsonPath('faltam.0', 'Confirme mais 3 ponto(s) forte(s) do produto no cadastro do Mercado Livre para habilitar texto no slot de benefícios.');
    }

    public function test_kit_com_pode_ter_texto_verdadeiro_devolve_faltam_vazio(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 1);
        $kit->update(['plano' => [
            'estrategia' => ['publico' => 'a', 'proposta_de_valor' => 'b', 'direcao_visual' => 'c'],
            'pode_ter_texto' => true,
            'faltam' => [],
        ]]);

        $resp = $this->actingAs($this->admin())->getJson($this->rotaStatus($kit->id));

        $resp->assertOk();
        $resp->assertJsonPath('pode_ter_texto', true);
        $resp->assertJsonPath('faltam', []);
    }

    /** Kit sem `plano` nenhum (nunca passou pelo planejamento) não quebra a leitura. */
    public function test_kit_sem_plano_nao_quebra_e_devolve_faltam_vazio(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 1);
        $kit->update(['plano' => null]);

        $resp = $this->actingAs($this->admin())->getJson($this->rotaStatus($kit->id));

        $resp->assertOk();
        $resp->assertJsonPath('pode_ter_texto', null);
        $resp->assertJsonPath('faltam', []);
    }

    /** Nenhum endpoint dedicado de fatos continua registrado (removido nesta quick). */
    public function test_nenhuma_rota_de_fatos_continua_registrada(): void
    {
        foreach (['mlb.anuncios.publicador.criativos.fatos', 'mlb.anuncios.publicador.criativos.fatos.salvar', 'mlb.anuncios.publicador.criativos.fatos.remover'] as $nome) {
            $this->assertFalse(\Illuminate\Support\Facades\Route::has($nome), "A rota '{$nome}' deveria ter sido removida (quick 261007-rmv).");
        }
    }
}
