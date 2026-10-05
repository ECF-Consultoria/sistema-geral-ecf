<?php

namespace Tests\Unit\Publicador\Alavancas;

use App\Services\Publicador\Alavancas\AssinaturaDaPrevia;
use App\Services\Publicador\Alavancas\RequisicaoMl;
use Tests\TestCase;

class AssinaturaDaPreviaTest extends TestCase
{
    private function itens(): array
    {
        return [['item_id' => 'MLB1', 'preco' => 10.5], ['item_id' => 'MLB2', 'preco' => 20.0]];
    }

    public function test_confere_o_mesmo_canonico_conta_e_usuario(): void
    {
        $c = AssinaturaDaPrevia::canonico('convite.inscrever', $this->itens());
        $a = AssinaturaDaPrevia::gerar($c, 'company-1', 7);

        $this->assertTrue(AssinaturaDaPrevia::confere($a, $c, 'company-1', 7));
    }

    public function test_recusa_preco_mudado_outro_usuario_outra_conta(): void
    {
        $c = AssinaturaDaPrevia::canonico('x', $this->itens());
        $a = AssinaturaDaPrevia::gerar($c, 'company-1', 7);

        $itens = $this->itens();
        $itens[0]['preco'] = 11.0;
        $this->assertFalse(AssinaturaDaPrevia::confere($a, AssinaturaDaPrevia::canonico('x', $itens), 'company-1', 7));
        $this->assertFalse(AssinaturaDaPrevia::confere($a, AssinaturaDaPrevia::canonico('outra', $this->itens()), 'company-1', 7));
        $this->assertFalse(AssinaturaDaPrevia::confere($a, $c, 'company-1', 8));
        $this->assertFalse(AssinaturaDaPrevia::confere($a, $c, 'company-2', 7));
    }

    public function test_recusa_depois_de_onze_minutos(): void
    {
        $c = AssinaturaDaPrevia::canonico('x', $this->itens());
        $a = AssinaturaDaPrevia::gerar($c, 'company-1', 7);

        $this->travel(11)->minutes();

        $this->assertFalse(AssinaturaDaPrevia::confere($a, $c, 'company-1', 7));
    }

    public function test_recusa_malformada_ou_nula(): void
    {
        $c = AssinaturaDaPrevia::canonico('x', $this->itens());

        $this->assertFalse(AssinaturaDaPrevia::confere(null, $c, 'company-1', 7));
        $this->assertFalse(AssinaturaDaPrevia::confere('lixo', $c, 'company-1', 7));
        $this->assertFalse(AssinaturaDaPrevia::confere(str_repeat('a', 64).'.9999999999', $c, 'company-1', 7));
    }

    public function test_canonico_ignora_ordem_das_chaves_mas_respeita_a_ordem_dos_itens(): void
    {
        $a = AssinaturaDaPrevia::canonico('x', [['b' => 1, 'a' => ['z' => 1, 'y' => 2]], ['c' => 3]]);
        $b = AssinaturaDaPrevia::canonico('x', [['a' => ['y' => 2, 'z' => 1], 'b' => 1], ['c' => 3]]);
        $invertido = AssinaturaDaPrevia::canonico('x', [['c' => 3], ['b' => 1, 'a' => ['z' => 1, 'y' => 2]]]);

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $invertido);
    }

    public function test_requisicao_recusa_metodo_e_caminho_invalidos(): void
    {
        foreach ([['PATCH', '/x'], ['GET', 'x'], ['GET', '/x?a=1'], ['GET', 'https://a.com/x']] as [$m, $c]) {
            try {
                new RequisicaoMl($m, $c);
                $this->fail("Aceitou {$m} {$c}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_requisicao_recusa_escrita_em_publicidade(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Publicidade é só leitura nesta fase.');

        new RequisicaoMl('POST', '/advertising/MLB/advertisers/1/product_ads/campaigns');
    }

    public function test_requisicao_le_publicidade_por_get(): void
    {
        $r = new RequisicaoMl('GET', '/advertising/advertisers');

        $this->assertFalse($r->ehEscrita());
    }

    public function test_historico_remove_authorization_em_qualquer_caixa(): void
    {
        $r = new RequisicaoMl('POST', '/x', ['a' => 1], ['b' => 2], ['AUTHORIZATION' => 'Bearer t', 'authorization' => 'Bearer t', 'X-Version' => '3']);

        $h = $r->paraHistorico();

        $this->assertSame(['X-Version' => '3'], $h['cabecalhos']);
        $this->assertSame(['a' => 1], $h['query']);
        $this->assertSame(['b' => 2], $h['corpo']);
        $this->assertTrue($r->ehEscrita());
    }
}
