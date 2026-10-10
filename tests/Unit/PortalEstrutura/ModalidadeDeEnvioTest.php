<?php

namespace Tests\Unit\PortalEstrutura;

use App\Models\Company;
use App\Services\Portal\Estrutura\Produtos\ModalidadeDeEnvio;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A modalidade de envio da conta (o `logistic_type` do ME2) a partir de
 * GET /users/{id}/shipping_preferences: é ela que decide os limites do Mercado Envios.
 */
class ModalidadeDeEnvioTest extends TestCase
{
    private function preferencia(array $tipos): array
    {
        return ['logistics' => [
            ['mode' => 'me2', 'types' => $tipos],
            ['mode' => 'custom', 'types' => [['type' => 'custom', 'default' => true, 'status' => 'active']]],
        ]];
    }

    /** A resposta real da #459 (sondagem de 01/10): drop_off padrão e Full ativo → Correios. */
    public function test_a_conta_459_despacha_pelos_correios(): void
    {
        $corpo = json_decode(file_get_contents(base_path('tests/fixtures-ml/sondagem/conta/shipping_preferences.json')), true)['resposta'];

        $this->assertSame('drop_off', ModalidadeDeEnvio::daPreferencia($corpo));
    }

    public function test_vale_o_tipo_padrao_ativo_de_despacho(): void
    {
        $this->assertSame('cross_docking', ModalidadeDeEnvio::daPreferencia($this->preferencia([
            ['type' => 'drop_off', 'default' => false, 'status' => 'active'],
            ['type' => 'cross_docking', 'default' => true, 'status' => 'active'],
        ])));
        $this->assertSame('xd_drop_off', ModalidadeDeEnvio::daPreferencia($this->preferencia([
            ['type' => 'xd_drop_off', 'default' => true, 'status' => 'active'],
            ['type' => 'self_service', 'default' => false, 'status' => 'active'],
        ])));
    }

    public function test_sem_padrao_de_despacho_vale_o_primeiro_ativo_e_o_full_so_sozinho(): void
    {
        // Full como padrão não decide o despacho do que não está no Full.
        $this->assertSame('drop_off', ModalidadeDeEnvio::daPreferencia($this->preferencia([
            ['type' => 'fulfillment', 'default' => true, 'status' => 'active'],
            ['type' => 'drop_off', 'default' => false, 'status' => 'active'],
        ])));
        // Tipo inativo não conta.
        $this->assertSame('drop_off', ModalidadeDeEnvio::daPreferencia($this->preferencia([
            ['type' => 'cross_docking', 'default' => true, 'status' => 'inactive'],
            ['type' => 'drop_off', 'default' => false, 'status' => 'active'],
        ])));
        $this->assertSame('fulfillment', ModalidadeDeEnvio::daPreferencia($this->preferencia([
            ['type' => 'fulfillment', 'default' => true, 'status' => 'active'],
        ])));
    }

    public function test_sem_me2_ou_tipo_desconhecido_e_null(): void
    {
        $this->assertNull(ModalidadeDeEnvio::daPreferencia([]));
        $this->assertNull(ModalidadeDeEnvio::daPreferencia(['coverage' => []]));
        $this->assertNull(ModalidadeDeEnvio::daPreferencia($this->preferencia([
            ['type' => 'self_service', 'default' => true, 'status' => 'active'],
        ])));
    }

    public function test_guardada_no_cache_por_empresa(): void
    {
        Cache::flush();
        $empresa = new Company();
        $empresa->id = 987654;

        $this->assertNull(ModalidadeDeEnvio::emCache($empresa));

        ModalidadeDeEnvio::guardar(987654, 'cross_docking');
        $this->assertSame('cross_docking', ModalidadeDeEnvio::emCache($empresa));

        // "Lida, sem modalidade reconhecida" também fica guardada (não relê a cada clique), mas vale null.
        ModalidadeDeEnvio::guardar(987654, null);
        $this->assertTrue(Cache::has(ModalidadeDeEnvio::chave(987654)));
        $this->assertNull(ModalidadeDeEnvio::emCache($empresa));
    }
}
