<?php

namespace Tests\Unit\Publicador\Alavancas;

use App\Services\Publicador\Alavancas\MapeadorErroAlavanca;
use App\Support\Publicador\Erros\RespostaMl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** AL166-20: formatos de erro das alavancas -> código + pt-BR, sem perder o corpo cru. */
class MapeadorErroAlavancaTest extends TestCase
{
    public static function casosDaDoc(): array
    {
        $json = json_decode(file_get_contents(__DIR__.'/../../../fixtures-ml/alavancas/doc/erros.json'), true);
        $casos = [];
        foreach ($json['casos'] as $i => $c) {
            $casos["{$i} {$c['codigo']}"] = [$c];
        }

        return $casos;
    }

    #[DataProvider('casosDaDoc')]
    public function test_cada_caso_da_doc_vira_codigo_e_mensagem_em_pt_br(array $caso): void
    {
        $r = new RespostaMl($caso['status'], $caso['corpo']);
        $t = MapeadorErroAlavanca::traduzir($r, '/x');

        $this->assertSame($caso['codigo'], $t['codigo']);
        $this->assertStringNotContainsString('O Mercado Livre recusou', $t['mensagem']);
        if (isset($caso['mensagem_contem'])) {
            $this->assertStringContainsString($caso['mensagem_contem'], $t['mensagem']);
        }
        $this->assertSame($caso['corpo'], $r->corpo);
    }

    public function test_item_version_tem_mensagem_de_revisar(): void
    {
        $t = MapeadorErroAlavanca::traduzir(new RespostaMl(409, ['error' => 'x', 'code' => 'item.version', 'status' => 409]));
        $this->assertStringContainsString('Alguém alterou o preço deste anúncio', $t['mensagem']);
    }

    public function test_423_tem_prioridade_sobre_o_corpo(): void
    {
        $t = MapeadorErroAlavanca::traduzir(new RespostaMl(423, ['code' => 'ENTITY_LOCKED']));
        $this->assertSame('423', $t['codigo']);
        $this->assertStringContainsString('ocupado por outra alteração', $t['mensagem']);
    }

    public function test_403_depende_do_caminho(): void
    {
        $r = new RespostaMl(403, ['message' => 'forbidden']);
        $this->assertStringContainsString('Promoções no DevCenter', MapeadorErroAlavanca::traduzir($r, '/seller-promotions/users/1')['mensagem']);
        $this->assertStringContainsString('Publicidade', MapeadorErroAlavanca::traduzir($r, '/advertising/advertisers')['mensagem']);
        $this->assertStringContainsString('não autorizou', MapeadorErroAlavanca::traduzir($r, '/items/1')['mensagem']);
    }

    public function test_429_rede_e_5xx(): void
    {
        $this->assertStringContainsString('esperar', MapeadorErroAlavanca::traduzir(new RespostaMl(429, null))['mensagem']);
        $rede = MapeadorErroAlavanca::traduzir(new RespostaMl(0, ['erro_de_rede' => 'timeout']));
        $this->assertSame('rede', $rede['codigo']);
        $this->assertStringContainsString('a alteração pode ter sido feita', $rede['mensagem']);
        $this->assertStringContainsString('a alteração pode ter sido feita', MapeadorErroAlavanca::traduzir(new RespostaMl(503, null))['mensagem']);
    }

    public function test_desconhecido_usa_a_mensagem_original(): void
    {
        $t = MapeadorErroAlavanca::traduzir(new RespostaMl(400, ['message' => 'm', 'cause' => [['error_code' => 'XYZ', 'error_message' => 'coisa estranha']]]));
        $this->assertSame('XYZ', $t['codigo']);
        $this->assertSame('O Mercado Livre recusou: coisa estranha', $t['mensagem']);
    }

    public function test_corpo_texto_nao_quebra(): void
    {
        $t = MapeadorErroAlavanca::traduzir(new RespostaMl(400, 'texto solto do ml'));
        $this->assertNull($t['codigo']);
        $this->assertSame('O Mercado Livre recusou: texto solto do ml', $t['mensagem']);
    }
}
