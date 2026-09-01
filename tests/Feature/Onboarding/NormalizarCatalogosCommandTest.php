<?php

namespace Tests\Feature\Onboarding;

use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `onboarding:normalizar-catalogos` — limpeza do legado das colunas de catálogo.
 *
 * Encurtar ONB_ME1_OPCOES não tira opção nenhuma da tela: o Painel Polos reinjeta no
 * dropdown todo valor presente no banco (valoresPresentes). Este comando é o que faz a
 * coluna de fato ter 5 opções, e o dry-run é obrigatório antes do --apply.
 *
 * @group onboarding
 */
class NormalizarCatalogosCommandTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function ficha(array $attrs): MlbImplementacao
    {
        $this->seq++;

        $empresa = MlbEmpresa::create([
            'nome'    => 'Loja Norm ' . $this->seq,
            'tipo'    => 'POLO',
            'projeto' => 'POLOS',
            'fase'    => 'M2',
            'polo'    => 'Arapongas',
        ]);

        return MlbImplementacao::create(array_merge([
            'empresa_id' => $empresa->id,
            'token'      => 'toknorm' . $this->seq,
        ], $attrs));
    }

    public function test_dry_run_nao_grava_nada(): void
    {
        $ficha = $this->ficha(['me1' => 'EM CONTRATAÇÃO', 'integradora' => 'Intelispost']);

        $this->artisan('onboarding:normalizar-catalogos')->assertExitCode(0);

        $ficha->refresh();
        $this->assertSame('EM CONTRATAÇÃO', $ficha->me1, 'dry-run gravou — não deveria.');
        $this->assertSame('Intelispost', $ficha->integradora);
    }

    public function test_apply_normaliza_caixa_e_acento(): void
    {
        $a = $this->ficha(['me1' => 'NÃO']);
        $b = $this->ficha(['me1' => 'Não é Necessario']);
        $c = $this->ficha(['me1' => 'EM CONTRATAÇÃO']);

        $this->artisan('onboarding:normalizar-catalogos', ['--apply' => true])->assertExitCode(0);

        $this->assertSame('Não', $a->refresh()->me1);
        $this->assertSame('Não é necessário', $b->refresh()->me1);
        $this->assertSame('Em contratação', $c->refresh()->me1);
    }

    public function test_apply_aplica_o_de_para_dos_estados_intermediarios(): void
    {
        $limpa = $this->ficha(['me1' => 'Sem itens ainda']);
        $prog  = $this->ficha(['me1' => 'Conversando com Cliente']);

        $this->artisan('onboarding:normalizar-catalogos', ['--apply' => true])->assertExitCode(0);

        $this->assertNull($limpa->refresh()->me1, "'Sem itens ainda' deve limpar a coluna.");
        $this->assertSame('Em contratação', $prog->refresh()->me1);
    }

    public function test_apply_deixa_a_coluna_dentro_do_catalogo(): void
    {
        foreach (['NÃO', 'Sem itens ainda', 'Não é Necessario', 'Ativo', 'Precisa de ME1',
                  'EM CONTRATAÇÃO', 'Conversando com Cliente', 'Aguardando Contato',
                  'Pendente com Integradora', 'VERIFICANDO', 'Preenchendo Tabela'] as $v) {
            $this->ficha(['me1' => $v]);
        }

        $this->artisan('onboarding:normalizar-catalogos', ['--apply' => true])->assertExitCode(0);

        $presentes = MlbImplementacao::whereNotNull('me1')->pluck('me1')->unique()->values()->all();

        foreach ($presentes as $v) {
            $this->assertContains($v, MlbImplementacao::ONB_ME1_OPCOES, "Sobrou fora do catálogo: {$v}");
        }
    }

    public function test_apply_preserva_precisa_de_me1_e_a_trava_manual(): void
    {
        // A regra automática das medidas grava 'Precisa de ME1'; o de-para não pode
        // colapsá-la, e me1_manual não pode ser tocado.
        $ficha = $this->ficha(['me1' => 'Precisa de ME1', 'me1_manual' => true]);

        $this->artisan('onboarding:normalizar-catalogos', ['--apply' => true])->assertExitCode(0);

        $ficha->refresh();
        $this->assertSame('Precisa de ME1', $ficha->me1);
        $this->assertTrue((bool) $ficha->me1_manual);
    }

    public function test_apply_alinha_a_grafia_da_integradora(): void
    {
        $a = $this->ficha(['integradora' => 'Intelispost']);
        $b = $this->ficha(['integradora' => 'Any']);
        $c = $this->ficha(['integradora' => 'Frenet']);

        $this->artisan('onboarding:normalizar-catalogos', ['--apply' => true])->assertExitCode(0);

        $this->assertSame('Intelipost', $a->refresh()->integradora);
        $this->assertSame('Anymarket', $b->refresh()->integradora);
        $this->assertSame('Frenet', $c->refresh()->integradora, 'Valor conhecido não pode mudar.');
    }

    public function test_apply_migra_o_hub_de_texto_para_dropdown(): void
    {
        $dados                        = MlbImplementacao::dadosPadrao();
        $dados['itens']['hub']        = ['acesso' => 'Não irá utilizar', 'feito' => true];
        $ficha                        = $this->ficha(['dados' => $dados]);

        $this->artisan('onboarding:normalizar-catalogos', ['--apply' => true])->assertExitCode(0);

        $hub = $ficha->refresh()->dados['itens']['hub'];
        $this->assertSame('Não utilizo', $hub['valor']);
        $this->assertSame('', $hub['acesso'], 'O texto era reafirmação do valor — deve sair.');
        $this->assertTrue($hub['feito'], 'Migrar não pode desmarcar o item.');
    }

    public function test_apply_nao_descarta_texto_de_hub_sem_destino(): void
    {
        // 'Bling' é ERP, não HUB — nenhuma informação de cliente é jogada fora.
        $dados                 = MlbImplementacao::dadosPadrao();
        $dados['itens']['hub'] = ['acesso' => 'Bling', 'feito' => true];
        $ficha                 = $this->ficha(['dados' => $dados]);

        $this->artisan('onboarding:normalizar-catalogos', ['--apply' => true])->assertExitCode(0);

        $hub = $ficha->refresh()->dados['itens']['hub'];
        $this->assertSame('Bling', $hub['acesso']);
    }

    public function test_apply_e_idempotente(): void
    {
        $this->ficha(['me1' => 'NÃO', 'integradora' => 'Any']);

        $this->artisan('onboarding:normalizar-catalogos', ['--apply' => true])->assertExitCode(0);

        // A segunda passada não tem o que fazer.
        $this->artisan('onboarding:normalizar-catalogos')
            ->expectsOutputToContain('DRY-RUN: 0 alterações')
            ->assertExitCode(0);
    }
}
