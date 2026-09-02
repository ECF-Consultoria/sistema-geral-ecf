<?php

namespace Tests\Feature\Onboarding;

use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 'Tiny ERP' saiu de ERP_OPCOES em 02/09/2026 e virou 'Tiny'. A migração de dados
 * reescreve as fichas já respondidas — sem ela o <select> do link do cliente, que é
 * nativo, exibe o campo EM BRANCO para quem respondeu com o texto antigo.
 *
 * @group onboarding
 */
class MigracaoTinyErpTest extends TestCase
{
    use RefreshDatabase;

    private function criarImpl(?string $erp): MlbImplementacao
    {
        $criador = User::factory()->create();

        $empresa = MlbEmpresa::create([
            'nome'       => 'Loja ERP ' . Str::random(4),
            'tipo'       => 'POLO',
            'projeto'    => 'POLOS',
            'fase'       => 'M1',
            'polo'       => 'Arapongas',
            'estagio'    => 'Não Listado',
            'criado_por' => $criador->id,
        ]);

        $dados = MlbImplementacao::dadosPadrao();
        if ($erp !== null) {
            data_set($dados, 'itens.erp.valor', $erp);
        }

        return MlbImplementacao::create([
            'empresa_id' => $empresa->id,
            'token'      => Str::random(48),
            'dados'      => $dados,
        ]);
    }

    private function rodarMigracao(): void
    {
        $migracao = require database_path('migrations/2026_09_02_120000_migrar_tiny_erp_para_tiny.php');
        $migracao->up();
    }

    public function test_ficha_com_texto_antigo_passa_a_gravar_tiny(): void
    {
        $impl = $this->criarImpl('Tiny ERP');

        $this->rodarMigracao();

        $this->assertSame('Tiny', data_get($impl->fresh()->dados, 'itens.erp.valor'));
    }

    public function test_migracao_nao_encosta_nas_demais_respostas(): void
    {
        $bling   = $this->criarImpl('Bling');
        $outro   = $this->criarImpl('Outro');
        $intacta = $this->criarImpl(null); // sentinela '---' do padrão

        $this->rodarMigracao();

        $this->assertSame('Bling', data_get($bling->fresh()->dados, 'itens.erp.valor'));
        $this->assertSame('Outro', data_get($outro->fresh()->dados, 'itens.erp.valor'));
        $this->assertSame('---',   data_get($intacta->fresh()->dados, 'itens.erp.valor'));
    }

    public function test_valor_migrado_esta_na_lista_que_o_cliente_ve(): void
    {
        $impl = $this->criarImpl('Tiny ERP');

        $this->rodarMigracao();

        $this->assertContains(
            data_get($impl->fresh()->dados, 'itens.erp.valor'),
            MlbImplementacao::ERP_OPCOES
        );
    }

    public function test_migracao_preserva_o_resto_da_ficha(): void
    {
        $impl = $this->criarImpl('Tiny ERP');
        $impl->update(['dados' => tap($impl->dados, function (&$d) {
            data_set($d, 'itens.erp.acesso', 'login: teste / senha: 123');
            data_set($d, 'itens.erp.feito', true);
        })]);

        $this->rodarMigracao();

        $dados = $impl->fresh()->dados;
        $this->assertSame('login: teste / senha: 123', data_get($dados, 'itens.erp.acesso'));
        $this->assertTrue(data_get($dados, 'itens.erp.feito'));
    }
}
