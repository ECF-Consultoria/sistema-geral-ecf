<?php

namespace Tests\Feature\Publicador\Alavancas;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\PubAlavancaEscrita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Publicador\Alavancas\Concerns\CenarioAlavancas;
use Tests\TestCase;

/** 166-10 (D-05, AL166-07): histórico por empresa, paginado, filtrável e sem acesso cruzado. */
class HistoricoTelaTest extends TestCase
{
    use CenarioAlavancas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function url(string $conta, string $sufixo = 'historico'): string
    {
        return "/mlb/anuncios/publicador/empresas/{$conta}/alavancas/{$sufixo}";
    }

    private function linha(array $campos = []): PubAlavancaEscrita
    {
        return PubAlavancaEscrita::create($campos + [
            'conta_chave' => $this->ancora->chaveContaMl(),
            'ml_seller_id' => '1555596317',
            'ator_nome' => 'Admin',
            'alavanca' => 'promocao',
            'acao' => 'entrar',
            'resultado' => PubAlavancaEscrita::OK,
            'mlb_empresa_id' => $this->ancora instanceof MlbEmpresa ? $this->ancora->id : null,
            'company_id' => $this->ancora instanceof Company ? $this->ancora->id : null,
        ]);
    }

    private function outraEmpresa(): PubAlavancaEscrita
    {
        $outra = MlbEmpresa::create(['nome' => 'Outra', 'projeto' => 'POLOS']);

        return PubAlavancaEscrita::create([
            'mlb_empresa_id' => $outra->id, 'conta_chave' => $outra->chaveContaMl(), 'ml_seller_id' => '99',
            'ator_nome' => 'Outro', 'alavanca' => 'cupom', 'acao' => 'criar', 'resultado' => PubAlavancaEscrita::OK,
        ]);
    }

    public function test_linha_de_outra_empresa_nao_aparece(): void
    {
        $this->montarAlavancas();
        $minha = $this->linha();
        $this->outraEmpresa();

        $r = $this->actingAs($this->admin)->getJson($this->url($this->ancora->chaveContaMl()))->assertOk();

        $this->assertSame([$minha->id], array_column($r->json('linhas'), 'id'));
        $this->assertSame(['pagina' => 1, 'por_pagina' => 20, 'total' => 1, 'ultima' => 1], $r->json('paginacao'));
        $this->assertSame(['id', 'quando', 'enviado_em', 'ator_nome', 'alavanca', 'acao', 'promotion_type', 'promotion_id', 'item_id',
            'resultado', 'http_status', 'erro_codigo', 'mensagem', 'lote_uuid'], array_keys($r->json('linhas.0')));
    }

    public function test_uma_empresa_com_as_duas_ancoras_ve_as_linhas_de_ambas(): void
    {
        $this->montarAlavancas();
        $company = Company::factory()->create();
        $empresa = MlbEmpresa::create(['nome' => 'Polo', 'projeto' => 'POLOS', 'company_id' => $company->id]);
        $a = $this->linha(['company_id' => $company->id]);
        $b = $this->linha(['company_id' => null, 'mlb_empresa_id' => $empresa->id]);

        $r = $this->actingAs($this->admin)->getJson($this->url("empresa-{$empresa->id}"))->assertOk();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($r->json('linhas'), 'id'));
    }

    public function test_mais_novas_primeiro_e_vinte_por_pagina(): void
    {
        $this->montarAlavancas();
        for ($i = 0; $i < 25; $i++) {
            $this->linha();
        }

        $p1 = $this->actingAs($this->admin)->getJson($this->url($this->ancora->chaveContaMl()))->json();
        $p2 = $this->actingAs($this->admin)->getJson($this->url($this->ancora->chaveContaMl()).'?pagina=2')->json();

        $this->assertCount(20, $p1['linhas']);
        $this->assertCount(5, $p2['linhas']);
        $this->assertGreaterThan($p1['linhas'][1]['id'], $p1['linhas'][0]['id']);
        $this->assertSame(25, $p1['paginacao']['total']);
        $this->assertSame(2, $p1['paginacao']['ultima']);
        $this->assertSame(2, $p2['paginacao']['pagina']);
    }

    public function test_filtros_por_alavanca_e_resultado_e_valor_fora_da_lista_da_422(): void
    {
        $this->montarAlavancas();
        $this->linha(['alavanca' => 'promocao', 'resultado' => 'OK']);
        $cupomErro = $this->linha(['alavanca' => 'cupom', 'resultado' => 'ERRO']);
        $this->linha(['alavanca' => 'cupom', 'resultado' => 'OK']);
        $base = $this->url($this->ancora->chaveContaMl());

        $this->assertCount(2, $this->actingAs($this->admin)->getJson("{$base}?alavanca=cupom")->json('linhas'));
        $this->assertSame([$cupomErro->id], array_column($this->actingAs($this->admin)->getJson("{$base}?alavanca=cupom&resultado=ERRO")->json('linhas'), 'id'));
        $this->actingAs($this->admin)->getJson("{$base}?alavanca=xpto")->assertStatus(422);
        $this->actingAs($this->admin)->getJson("{$base}?resultado=talvez")->assertStatus(422);
    }

    public function test_detalhe_abre_payload_e_resposta_crua(): void
    {
        $this->montarAlavancas();
        $l = $this->linha(['metodo' => 'POST', 'caminho' => '/seller-promotions/items/MLB1', 'payload' => ['a' => 1],
            'resumo' => ['titulo' => 'x'], 'resposta' => ['message' => 'bad'], 'http_status' => 400, 'resultado' => 'ERRO']);

        $r = $this->actingAs($this->admin)->getJson($this->url($this->ancora->chaveContaMl(), "historico/{$l->id}"))->assertOk();

        $this->assertSame(['a' => 1], $r->json('payload'));
        $this->assertSame(['message' => 'bad'], $r->json('resposta'));
        $this->assertSame(['titulo' => 'x'], $r->json('resumo'));
        $this->assertSame('POST', $r->json('metodo'));
        $this->assertSame('/seller-promotions/items/MLB1', $r->json('caminho'));
        $this->assertSame($this->ancora->chaveContaMl(), $r->json('conta_chave'));
        $this->assertSame('1555596317', $r->json('ml_seller_id'));
    }

    public function test_detalhe_de_outra_empresa_e_404(): void
    {
        $this->montarAlavancas();
        $alheia = $this->outraEmpresa();

        $this->actingAs($this->admin)->getJson($this->url($this->ancora->chaveContaMl(), "historico/{$alheia->id}"))->assertNotFound();
    }

    public function test_historico_abre_mesmo_sem_token_e_sem_chamar_o_ml(): void
    {
        $this->montarAlavancas();
        $sem = Company::factory()->create();
        $l = PubAlavancaEscrita::create(['company_id' => $sem->id, 'conta_chave' => "company-{$sem->id}", 'ml_seller_id' => '7',
            'ator_nome' => 'Admin', 'alavanca' => 'atacado', 'acao' => 'aplicar', 'resultado' => 'OK']);

        $r = $this->actingAs($this->admin)->getJson($this->url("company-{$sem->id}"))->assertOk();

        $this->assertSame([$l->id], array_column($r->json('linhas'), 'id'));
        $this->assertSame([], $this->chamadas);
    }
}
