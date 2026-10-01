<?php

namespace Tests\Unit\Publicador\Erros;

use App\Support\Publicador\Erros\RespostaMl;
use PHPUnit\Framework\TestCase;

/**
 * `09` §1–2 — classificar a resposta do ML. As causas abaixo são as REAIS da
 * sondagem de 01/10 (`tests/fixtures-ml/sondagem/conta/`).
 */
class RespostaMlTest extends TestCase
{
    private static function fixture(string $categoria, string $arquivo): array
    {
        $e = json_decode(file_get_contents(dirname(__DIR__, 3)."/fixtures-ml/sondagem/conta/categorias/{$categoria}/{$arquivo}.json"), true);

        return [$e['status'], $e['resposta']];
    }

    public function test_validate_400_so_com_avisos_passou(): void
    {
        $r = new RespostaMl(...self::fixture('MLB193945', 'validate_base_up'));

        $this->assertSame(RespostaMl::WARNING, $r->classe);
        $this->assertTrue($r->semErro(), 'H-24: 400 só com avisos é aprovado');
        $this->assertSame(['shipping.lost_me1_by_user', 'item.shipping.mandatory_free_shipping'], array_column($r->avisos(), 'code'));
    }

    public function test_validate_com_erro_de_validacao(): void
    {
        $r = new RespostaMl(...self::fixture('MLB193945', 'validate_numero_sem_unidade'));

        $this->assertSame(RespostaMl::VALIDATION, $r->classe);
        $this->assertFalse($r->semErro());
        $this->assertSame([3708, 344], array_column($r->erros(), 'cause_id'));
    }

    public function test_erro_de_corpo_sem_cause(): void
    {
        // UP com title: `error` = "The fields [title] are invalid…", `cause` vazio.
        $r = new RespostaMl(...self::fixture('MLB193945', 'validate_base_title_e_family'));

        $this->assertSame(RespostaMl::VALIDATION, $r->classe);
        $this->assertCount(1, $r->erros());
        $this->assertSame('body.invalid_fields', $r->erros()[0]['code']);
        $this->assertStringContainsString('[title]', $r->erros()[0]['message']);
    }

    public function test_204_e_201(): void
    {
        $this->assertSame(RespostaMl::OK, (new RespostaMl(204, null))->classe);
        $this->assertTrue((new RespostaMl(204, null))->semErro());

        $criado = new RespostaMl(201, ['id' => 'MLB1', 'warnings' => []]);
        $this->assertTrue($criado->ok());
        $this->assertSame('MLB1', $criado->corpo['id']);
    }

    public function test_classes_de_falha(): void
    {
        $this->assertSame(RespostaMl::AUTH, (new RespostaMl(401, ['message' => 'invalid access token']))->classe);
        $this->assertSame(RespostaMl::PERMISSION, (new RespostaMl(403, ['message' => 'forbidden']))->classe);
        $this->assertSame(RespostaMl::PERMISSION, (new RespostaMl(400, ['error' => 'validation_error', 'cause' => [['code' => 'moderations.seller.not_authorized', 'cause_id' => 3250, 'type' => 'error']]]))->classe);
        $this->assertSame(RespostaMl::RATE_LIMIT, (new RespostaMl(429, ['message' => 'local_rate_limited']))->classe);
        $this->assertSame(RespostaMl::SERVER, (new RespostaMl(503, 'Service Unavailable'))->classe);
        $this->assertSame(RespostaMl::NETWORK, (new RespostaMl(0, null))->classe);
        $this->assertSame(RespostaMl::UNKNOWN_FORMAT, (new RespostaMl(400, '<html>erro</html>'))->classe);
    }

    public function test_so_estas_classes_podem_ter_criado_o_item_sem_dizer(): void
    {
        // RN-93: timeout/5xx depois de enviar = UNKNOWN; nunca reenviar sem reconciliar.
        $this->assertTrue((new RespostaMl(0, null))->podeTerCriado());
        $this->assertTrue((new RespostaMl(502, null))->podeTerCriado());
        $this->assertFalse((new RespostaMl(400, ['cause' => [['type' => 'error', 'code' => 'x']]]))->podeTerCriado());
        $this->assertFalse((new RespostaMl(429, null))->podeTerCriado());
    }
}
