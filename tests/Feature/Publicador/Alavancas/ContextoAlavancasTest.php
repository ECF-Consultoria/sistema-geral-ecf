<?php

namespace Tests\Feature\Publicador\Alavancas;

use App\Models\PubAlavancaEscrita;
use App\Services\Publicador\Alavancas\CacheAlavancas;
use App\Services\Publicador\Alavancas\ContextoAlavancas;
use App\Services\Publicador\Alavancas\LeitorContaAlavancas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Publicador\Alavancas\Concerns\CenarioAlavancas;
use Tests\TestCase;

/** 166-02: a conta das Alavancas para as duas âncoras, o /users/me enxuto e o cache por conta. */
class ContextoAlavancasTest extends TestCase
{
    use CenarioAlavancas;
    use RefreshDatabase;

    public function test_company_vira_conta_com_o_vendedor_do_token(): void
    {
        $this->montarAlavancas('company');
        $c = $this->contaAlavanca();

        $this->assertSame($this->ancora->id, $c->conta->id);
        $this->assertSame('1555596317', $c->sellerId);
        $this->assertSame("company-{$this->ancora->id}", $c->chaveTela);
        $this->assertSame("company-{$this->ancora->id}", $c->chaveConta());
        $this->assertTrue($c->liberada());
        $this->assertCount(0, $this->chamadas);
    }

    public function test_mlb_empresa_sem_company_vira_o_mesmo_contexto(): void
    {
        $this->montarAlavancas('mlb_empresa');
        $c = $this->contaAlavanca();

        $this->assertSame($this->ancora->id, $c->conta->id);
        $this->assertNull($c->company);
        $this->assertSame('1555596317', $c->sellerId);
        $this->assertSame("empresa-{$this->ancora->id}", $c->chaveConta());
    }

    public function test_sem_token_ou_token_revogado_nao_vira_contexto_e_nao_chama_o_ml(): void
    {
        $this->montarAlavancas('company');
        $ctx = app(ContextoAlavancas::class);
        $alvo = $ctx->resolver($this->ancora->chaveContaMl());

        $this->ancora->mlToken->update(['status' => 'revoked']);
        $alvo['company'] = $this->ancora->fresh();
        $this->assertNull($ctx->daTela($alvo));

        $this->ancora->mlToken()->delete();
        $alvo['company'] = $this->ancora->fresh();
        $this->assertNull($ctx->daTela($alvo));
        $this->assertCount(0, $this->chamadas);
    }

    public function test_leitor_devolve_so_o_que_as_alavancas_usam_e_cacheia(): void
    {
        $this->montarAlavancas('company');
        $c = $this->contaAlavanca();
        $leitor = app(LeitorContaAlavancas::class);

        $u = $leitor->ler($c);
        $this->assertSame('1555596317', $u['seller_id']);
        $this->assertSame('MGSTOREL', $u['nickname']);
        $this->assertTrue($u['business']);
        $this->assertSame('5_green', $u['reputacao']);
        $this->assertSame('MLB', $u['site']);
        $this->assertArrayNotHasKey('address', $u);
        $this->assertCount(1, $this->chamadas);

        $leitor->ler($c);
        $this->assertCount(1, $this->chamadas, 'a 2ª leitura sai do cache');

        $leitor->ler($c, true);
        $this->assertCount(2, $this->chamadas, 'fresco chama de novo');
    }

    public function test_leitor_lanca_quando_o_ml_falha_e_nao_guarda_o_erro(): void
    {
        $this->montarAlavancas('company');
        $c = $this->contaAlavanca();
        $this->responder('GET', '#^/users/me$#', ['message' => 'x'], 500);
        $leitor = app(LeitorContaAlavancas::class);

        try {
            $leitor->ler($c);
            $this->fail('devia lançar');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('/users/me falhou', $e->getMessage());
        }

        $this->rotasMl = [];
        $this->assertSame('MGSTOREL', $leitor->ler($c)['nickname']);
    }

    public function test_cache_recalcula_depois_de_invalidar_so_na_mesma_conta(): void
    {
        $this->montarAlavancas('company');
        $a = $this->contaAlavanca();
        $cache = app(CacheAlavancas::class);
        $n = 0;
        $fn = function () use (&$n) { return ++$n; };

        $this->assertSame(1, $cache->lembrar($a, 'panorama', ['x' => 1], 60, $fn));
        $this->assertSame(1, $cache->lembrar($a, 'panorama', ['x' => 1], 60, $fn));

        // Invalidar OUTRA conta não afeta esta.
        $this->montarAlavancas('mlb_empresa');
        $b = $this->contaAlavanca();
        $cache->invalidar($b);
        $this->assertSame(1, $cache->lembrar($a, 'panorama', ['x' => 1], 60, $fn));

        $cache->invalidar($a);
        $this->assertSame(2, $cache->lembrar($a, 'panorama', ['x' => 1], 60, $fn));
    }

    public function test_da_linha_reconstroi_pelas_ancoras_e_devolve_null_sem_elas(): void
    {
        $this->montarAlavancas('mlb_empresa');
        $ctx = app(ContextoAlavancas::class);
        $linha = new PubAlavancaEscrita(['mlb_empresa_id' => $this->ancora->id, 'company_id' => null]);

        $c = $ctx->daLinha($linha);
        $this->assertSame("empresa-{$this->ancora->id}", $c->chaveConta());

        $this->assertNull($ctx->daLinha(new PubAlavancaEscrita(['mlb_empresa_id' => null, 'company_id' => null])));
    }
}
