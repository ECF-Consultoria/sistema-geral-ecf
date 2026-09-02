<?php

namespace Tests\Feature\Onboarding;

use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Item "Outros Canais de Venda" do checklist público (pedido de 02/09/2026).
 *
 * O item passou de uma pergunta (faixa de faturamento) para duas: em QUAL canal o cliente
 * mais vende e, só para quem vende em algum, a faixa. A faixa continua na chave `valor` —
 * mudou a pergunta ao lado, não o campo já existente.
 *
 * @group onboarding
 */
class CanaisVendaTest extends TestCase
{
    use RefreshDatabase;

    private function criarImpl(array $implOpts = []): MlbImplementacao
    {
        $criador = User::factory()->create();

        $empresa = MlbEmpresa::create([
            'nome'       => 'Loja Canais ' . Str::random(4),
            'tipo'       => 'POLO',
            'projeto'    => 'POLOS',
            'fase'       => 'M1',
            'polo'       => 'Arapongas',
            'estagio'    => 'Não Listado',
            'criado_por' => $criador->id,
        ]);

        return MlbImplementacao::create(array_merge([
            'empresa_id' => $empresa->id,
            'token'      => Str::random(48),
            'dados'      => MlbImplementacao::dadosPadrao(),
        ], $implOpts));
    }

    private function salvar(MlbImplementacao $impl, string $campo, $valor)
    {
        return $this->patch(route('implementacao.salvar', $impl->token), [
            'id'    => 'canais_faturamento',
            'campo' => $campo,
            'valor' => $valor,
        ]);
    }

    // ─── Catálogo ────────────────────────────────────────────────────────────

    public function test_os_cinco_canais_pedidos_estao_no_catalogo(): void
    {
        $opcoes = MlbImplementacao::CANAL_VENDA_OPCOES;

        foreach (['Shopee', 'Amazon', 'Madeira Madeira', 'Magalu', 'Web Continental'] as $canal) {
            $this->assertContains($canal, $opcoes);
        }

        // 'Outro' abre campo de texto; a sentinela vem primeiro porque é a saída de quem
        // não vende fora do Mercado Livre.
        $this->assertContains('Outro', $opcoes);
        $this->assertSame(MlbImplementacao::CANAL_NENHUM, $opcoes[0]);
    }

    public function test_a_faixa_perdeu_a_opcao_de_quem_nao_vende(): void
    {
        // 'Não vendo em outros canais' era uma FAIXA até 01/09/2026 — virou resposta da
        // pergunta do canal. Manter nas duas listas daria dois jeitos de dizer o mesmo.
        $this->assertNotContains(MlbImplementacao::CANAL_NENHUM, MlbImplementacao::CANAL_FAIXA_OPCOES);

        $this->assertSame(
            ['Até 50k', 'De 50 a 100k', 'De 100 a 500k', 'Acima de 500k'],
            MlbImplementacao::CANAL_FAIXA_OPCOES
        );
    }

    public function test_item_expoe_as_duas_listas_para_o_front(): void
    {
        $item = array_column(MlbImplementacao::CHECKLIST, null, 'id')['canais_faturamento'];

        $this->assertSame('canais_venda', $item['tipo']);
        $this->assertSame(MlbImplementacao::CANAL_VENDA_OPCOES, $item['opcoes_canal']);
        $this->assertSame(MlbImplementacao::CANAL_FAIXA_OPCOES, $item['opcoes']);
    }

    public function test_padrao_do_item_tem_as_chaves_novas_sem_perder_valor(): void
    {
        $padrao = MlbImplementacao::dadosPadrao()['itens']['canais_faturamento'];

        $this->assertSame(['canais' => [], 'outro' => '', 'valor' => '', 'feito' => false], $padrao);
    }

    // ─── Múltipla escolha e o formato antigo ─────────────────────────────────

    public function test_canais_selecionados_normaliza_lista(): void
    {
        $this->assertSame(
            ['Shopee', 'Amazon'],
            MlbImplementacao::canaisSelecionados(['canais' => ['Shopee', ' Amazon ', '']])
        );
    }

    public function test_canais_selecionados_le_o_formato_de_escolha_unica(): void
    {
        // A pergunta nasceu de escolha única na manhã de 02/09/2026 e virou múltipla no
        // mesmo dia — havia ficha de cliente já respondida com `canal` (string) no banco.
        $this->assertSame(['Shopee'], MlbImplementacao::canaisSelecionados(['canal' => 'Shopee']));
        $this->assertSame([], MlbImplementacao::canaisSelecionados(['canal' => '']));
        $this->assertSame([], MlbImplementacao::canaisSelecionados([]));
    }

    // ─── Regra de "respondeu o suficiente" ───────────────────────────────────

    /** @dataProvider respostas */
    public function test_item_tem_conteudo(array $dado, bool $esperado): void
    {
        $this->assertSame($esperado, MlbImplementacao::itemTemConteudo('canais_venda', $dado));
    }

    public static function respostas(): array
    {
        return [
            'nada respondido'          => [['canais' => [], 'outro' => '', 'valor' => ''], false],
            'não vende fora do ML'     => [['canais' => ['Não vendo em outros canais'], 'valor' => ''], true],
            'um canal sem faixa'       => [['canais' => ['Shopee'], 'valor' => ''], false],
            'um canal com faixa'       => [['canais' => ['Shopee'], 'valor' => 'De 50 a 100k'], true],
            'vários canais com faixa'  => [['canais' => ['Shopee', 'Amazon', 'Magalu'], 'valor' => 'Acima de 500k'], true],
            'vários canais sem faixa'  => [['canais' => ['Shopee', 'Amazon'], 'valor' => ''], false],
            'outro sem nome do canal'  => [['canais' => ['Amazon', 'Outro'], 'outro' => '', 'valor' => 'Até 50k'], false],
            'outro com nome do canal'  => [['canais' => ['Amazon', 'Outro'], 'outro' => 'Shein', 'valor' => 'Até 50k'], true],
            'faixa sem canal'          => [['canais' => [], 'valor' => 'Acima de 500k'], false],
            'formato antigo completo'  => [['canal' => 'Shopee', 'valor' => 'Até 50k'], true],
            'formato antigo sem faixa' => [['canal' => 'Shopee', 'valor' => ''], false],
        ];
    }

    // ─── Gravação pela rota pública ──────────────────────────────────────────

    public function test_cliente_responde_varios_canais_e_a_faixa(): void
    {
        $impl = $this->criarImpl();

        $this->salvar($impl, 'canais', ['Shopee', 'Madeira Madeira'])->assertOk();
        $this->salvar($impl, 'valor', 'De 100 a 500k')->assertOk();
        $this->salvar($impl, 'feito', true)->assertOk();

        $dado = $impl->refresh()->dados['itens']['canais_faturamento'];

        $this->assertSame(['Shopee', 'Madeira Madeira'], $dado['canais']);
        $this->assertSame('De 100 a 500k', $dado['valor']);
        $this->assertTrue($dado['feito']);
    }

    public function test_quem_nao_vende_em_outros_canais_fecha_o_item_sem_faixa(): void
    {
        $impl = $this->criarImpl();

        $this->salvar($impl, 'canais', [MlbImplementacao::CANAL_NENHUM])->assertOk();
        $this->salvar($impl, 'feito', true)->assertOk();

        $this->assertTrue($impl->refresh()->dados['itens']['canais_faturamento']['feito']);
    }

    public function test_marcar_feito_sem_a_faixa_e_recusado(): void
    {
        // Trava anti-check-vazio no servidor: marcar canais e parar ali não fecha o item.
        $impl = $this->criarImpl();

        $this->salvar($impl, 'canais', ['Shopee', 'Amazon'])->assertOk();
        $this->salvar($impl, 'feito', true)->assertStatus(422);

        $this->assertFalse($impl->refresh()->dados['itens']['canais_faturamento']['feito']);
    }

    public function test_marcar_feito_sem_nada_respondido_e_recusado(): void
    {
        $impl = $this->criarImpl();

        $this->salvar($impl, 'feito', true)->assertStatus(422);
    }

    // ─── Coluna "Outros canais" do Painel Polos ──────────────────────────────

    /** @dataProvider respostasDoPainel */
    public function test_resposta_para_a_coluna_do_painel(array $item, ?string $esperado): void
    {
        // respostaChecklist() só enxerga `valor` — a coluna mostraria a faixa e nunca o
        // canal, que é justamente o que o time pediu para ver.
        $dados = MlbImplementacao::dadosPadrao();
        $dados['itens']['canais_faturamento'] = $item + $dados['itens']['canais_faturamento'];

        $impl = $this->criarImpl(['dados' => $dados]);

        $this->assertSame($esperado, $impl->respostaCanaisVenda());
    }

    public static function respostasDoPainel(): array
    {
        return [
            'um canal e faixa'    => [['canais' => ['Shopee'], 'valor' => 'De 50 a 100k'], 'Shopee · De 50 a 100k'],
            'vários canais'       => [['canais' => ['Shopee', 'Amazon'], 'valor' => 'Acima de 500k'], 'Shopee, Amazon · Acima de 500k'],
            'outro canal'         => [['canais' => ['Outro'], 'outro' => 'Shein', 'valor' => 'Até 50k'], 'Outro: Shein · Até 50k'],
            'outro entre vários'  => [['canais' => ['Magalu', 'Outro'], 'outro' => 'Shein', 'valor' => 'Até 50k'], 'Magalu, Outro: Shein · Até 50k'],
            'não vende fora'      => [['canais' => ['Não vendo em outros canais']], 'Não vendo em outros canais'],
            // Ficha que respondeu quando o item só tinha a faixa continua legível.
            'só a faixa (antiga)' => [['valor' => 'Acima de 500k'], 'Acima de 500k'],
            'canais sem faixa'    => [['canais' => ['Magalu', 'Amazon']], 'Magalu, Amazon'],
            // A ficha 565 em produção respondeu assim, antes da múltipla escolha.
            'escolha única (565)' => [['canal' => 'Shopee', 'valor' => 'Até 50k'], 'Shopee · Até 50k'],
            'nada respondido'     => [[], null],
        ];
    }

    public function test_faixa_nao_acompanha_quem_disse_que_nao_vende(): void
    {
        // Resposta antiga de faixa não pode voltar a aparecer colada na sentinela.
        $dados = MlbImplementacao::dadosPadrao();
        $dados['itens']['canais_faturamento'] = [
            'canais' => [MlbImplementacao::CANAL_NENHUM],
            'outro'  => '',
            'valor'  => 'Acima de 500k',
            'feito'  => true,
        ];

        $impl = $this->criarImpl(['dados' => $dados]);

        $this->assertSame(MlbImplementacao::CANAL_NENHUM, $impl->respostaCanaisVenda());
    }

    // ─── Ficha antiga ────────────────────────────────────────────────────────

    public function test_ficha_salva_antes_da_pergunta_consegue_responder(): void
    {
        // Mesma armadilha do 01/09: sem o merge, salvarItem devolve 422 e o cliente não
        // consegue responder a pergunta nova. Aqui a ficha nem tinha o item.
        $antigo = MlbImplementacao::dadosPadrao();
        unset($antigo['itens']['canais_faturamento']);

        $impl = $this->criarImpl(['dados' => $antigo]);

        $this->salvar($impl, 'canais', ['Magalu'])->assertOk();
        $this->salvar($impl, 'valor', 'Até 50k')->assertOk();

        $dado = $impl->refresh()->dados['itens']['canais_faturamento'];

        $this->assertSame(['Magalu'], $dado['canais']);
        $this->assertSame('Até 50k', $dado['valor']);
    }

    public function test_faixa_ja_respondida_sobrevive_a_chegada_da_pergunta_dos_canais(): void
    {
        // A faixa continua em `valor`: quem respondeu quando o item era só a faixa não
        // perde a resposta ao ganhar a chave `canais`.
        $antigo = MlbImplementacao::dadosPadrao();
        $antigo['itens']['canais_faturamento'] = ['valor' => 'Acima de 500k', 'feito' => true];

        $dado = MlbImplementacao::mesclarItensPadrao($antigo)['itens']['canais_faturamento'];

        $this->assertSame('Acima de 500k', $dado['valor']);
        $this->assertSame([], $dado['canais']);
        $this->assertTrue($dado['feito']);
    }

    public function test_resposta_de_escolha_unica_sobrevive_a_multipla_escolha(): void
    {
        // Ficha 565 em produção respondeu 'Shopee' na chave `canal` (string) antes da
        // troca para múltipla escolha. O merge acrescenta `canais` vazio, e é por isso
        // que a leitura precisa do fallback — senão a resposta some da tela do cliente.
        $antigo = MlbImplementacao::dadosPadrao();
        $antigo['itens']['canais_faturamento'] = [
            'canal' => 'Shopee', 'outro' => '', 'valor' => 'Até 50k', 'feito' => true,
        ];

        $impl = $this->criarImpl(['dados' => $antigo]);
        $dado = MlbImplementacao::mesclarItensPadrao($impl->dados)['itens']['canais_faturamento'];

        $this->assertSame(['Shopee'], MlbImplementacao::canaisSelecionados($dado));
        $this->assertTrue(MlbImplementacao::itemTemConteudo('canais_venda', $dado));
        $this->assertSame('Shopee · Até 50k', $impl->respostaCanaisVenda());
    }
}
