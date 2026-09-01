<?php

namespace Tests\Feature\Onboarding;

use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Revisão do checklist público e dos catálogos do Painel Polos (2026-09-01).
 *
 * Cobre as 7 mudanças da leva: sentinelas gravadas pelo cliente ('Falta Aceitar' /
 * 'Verificar'), a nova lista de Integrador Logístico, as 2 perguntas de perfil, o HUB
 * como dropdown, o enxugamento do ME1 e — o que mais importa — o merge que impede que
 * uma pergunta acrescentada ao CHECKLIST seja irrespondível nas fichas já salvas.
 *
 * @group onboarding
 */
class ChecklistCatalogos260901Test extends TestCase
{
    use RefreshDatabase;

    private function criarImpl(array $implOpts = []): MlbImplementacao
    {
        $criador = User::factory()->create();

        $empresa = MlbEmpresa::create([
            'nome'       => 'Loja Catalogo ' . Str::random(4),
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

    private function marcarFeito(MlbImplementacao $impl, string $id)
    {
        return $this->patch(route('implementacao.salvar', $impl->token), [
            'id'    => $id,
            'campo' => 'feito',
            'valor' => true,
        ]);
    }

    // ─── 1. Acesso Colaborador → "Falta Aceitar" ─────────────────────────────

    public function test_cliente_marcar_acesso_colaborador_grava_falta_aceitar(): void
    {
        $impl = $this->criarImpl(['acesso_colaborador' => null]);

        $this->marcarFeito($impl, 'acesso_colaborador')->assertOk();

        $this->assertSame('Falta Aceitar', $impl->refresh()->acesso_colaborador);
    }

    public function test_falta_aceitar_nao_rebaixa_com_acesso(): void
    {
        // 'Com acesso' é fato consumado registrado pela equipe — o clique do cliente
        // não pode desfazê-lo.
        $impl = $this->criarImpl(['acesso_colaborador' => 'Com acesso']);

        $this->marcarFeito($impl, 'acesso_colaborador')->assertOk();

        $this->assertSame('Com acesso', $impl->refresh()->acesso_colaborador);
    }

    public function test_falta_aceitar_sobrescreve_valor_mais_velho_da_planilha(): void
    {
        // 'Mensagem enviada' veio da planilha (70 fichas em 01/09/2026) e é informação
        // mais velha do que a declaração do cliente.
        $impl = $this->criarImpl(['acesso_colaborador' => 'Mensagem enviada']);

        $this->marcarFeito($impl, 'acesso_colaborador')->assertOk();

        $this->assertSame('Falta Aceitar', $impl->refresh()->acesso_colaborador);
    }

    public function test_falta_aceitar_nao_conta_como_entrante(): void
    {
        // A meta de entrantes (EntrantesM0Panel/MetasPanel) exige exatamente 'Com acesso'.
        // Como nunca rebaixamos, nenhuma empresa que já contava deixa de contar.
        $this->assertNotSame('Com acesso', 'Falta Aceitar');
        $this->assertContains('Falta Aceitar', MlbImplementacao::ONB_ACESSO_COLABORADOR_OPCOES);
    }

    // ─── 5. Programa Decola → "Verificar" ────────────────────────────────────

    public function test_cliente_marcar_programa_decola_grava_verificar(): void
    {
        $impl = $this->criarImpl(['decola' => 'Não']);

        $this->marcarFeito($impl, 'programa_decola')->assertOk();

        $this->assertSame('Verificar', $impl->refresh()->decola);
    }

    public function test_verificar_nao_rebaixa_sim(): void
    {
        $impl = $this->criarImpl(['decola' => 'Sim']);

        $this->marcarFeito($impl, 'programa_decola')->assertOk();

        $this->assertSame('Sim', $impl->refresh()->decola);
    }

    // ─── Armadilha: pergunta nova em ficha já salva ──────────────────────────

    public function test_ficha_com_json_antigo_aceita_responder_pergunta_nova(): void
    {
        // Antes do merge isto devolvia 422: salvarItem faz
        // abort_unless(isset($dados['itens'][$id])) e o JSON salvo não tinha a chave.
        // Em 01/09/2026 havia 10 fichas nessa situação — exatamente as que estão em uso.
        $antigo = MlbImplementacao::dadosPadrao();
        unset($antigo['itens']['produtos_perfil'], $antigo['itens']['canais_faturamento']);

        $impl = $this->criarImpl(['dados' => $antigo]);

        $this->patch(route('implementacao.salvar', $impl->token), [
            'id'    => 'produtos_perfil',
            'campo' => 'valor',
            'valor' => 'Produtos grandes, volumosos, multivolumes e/ou com mais de 50 kg',
        ])->assertOk();

        $this->assertSame(
            'Produtos grandes, volumosos, multivolumes e/ou com mais de 50 kg',
            $impl->refresh()->dados['itens']['produtos_perfil']['valor']
        );
    }

    public function test_merge_preserva_o_que_o_cliente_ja_tinha_salvo(): void
    {
        $antigo = MlbImplementacao::dadosPadrao();
        $antigo['itens']['erp']['valor']  = 'Bling';
        $antigo['itens']['erp']['acesso'] = 'login: teste';
        unset($antigo['itens']['canais_faturamento']);

        $mesclado = MlbImplementacao::mesclarItensPadrao($antigo);

        // Valor salvo vence o padrão…
        $this->assertSame('Bling', $mesclado['itens']['erp']['valor']);
        $this->assertSame('login: teste', $mesclado['itens']['erp']['acesso']);
        // …e a chave que faltava entra com o padrão.
        $this->assertArrayHasKey('canais_faturamento', $mesclado['itens']);
        $this->assertFalse($mesclado['itens']['canais_faturamento']['feito']);
    }

    public function test_merge_adiciona_subchave_nova_sem_apagar_a_antiga(): void
    {
        // HUB era textarea ('acesso'); virou dropdown ('valor'). O texto salvo continua lá.
        $antigo                        = MlbImplementacao::dadosPadrao();
        $antigo['itens']['hub']        = ['acesso' => 'Bling', 'feito' => true];

        $hub = MlbImplementacao::mesclarItensPadrao($antigo)['itens']['hub'];

        $this->assertSame('Bling', $hub['acesso']);
        $this->assertSame('---', $hub['valor']);
        $this->assertTrue($hub['feito']);
    }

    public function test_progresso_conta_sobre_o_checklist_atual(): void
    {
        // Ficha antiga em 100% não pode ficar presa no denominador de quando foi salva.
        $antigo = MlbImplementacao::dadosPadrao();
        unset($antigo['itens']['produtos_perfil'], $antigo['itens']['canais_faturamento']);
        foreach ($antigo['itens'] as $id => $dado) {
            $antigo['itens'][$id]['feito'] = true;
        }

        $impl = $this->criarImpl(['dados' => $antigo]);

        $total = count(MlbImplementacao::CHECKLIST);
        $this->assertSame($total, $impl->progresso()['total']);
        $this->assertSame($total - 2, $impl->progresso()['feitos']);
        $this->assertLessThan(100, $impl->progresso()['pct']);
    }

    // ─── 2, 3, 4, 7. Checklist ───────────────────────────────────────────────

    public function test_integrador_opcoes_revisadas(): void
    {
        $opcoes = MlbImplementacao::INTEGRADOR_OPCOES;

        foreach (['Sisfrete', 'Intelipost', 'Anymarket', 'Enviarei Apenas pelo Mercado Envios'] as $nova) {
            $this->assertContains($nova, $opcoes);
        }

        foreach (['Melhor Envio', 'DirectLog', 'Jadlog', 'Correios', 'Trabalhar apenas com Mercado Envios'] as $removida) {
            $this->assertNotContains($removida, $opcoes);
        }

        // Mantidas porque há ficha usando — remover deixaria o valor órfão no select.
        foreach (['Em Contratação', 'Frenet', 'Outro'] as $mantida) {
            $this->assertContains($mantida, $opcoes);
        }
    }

    public function test_perguntas_novas_estao_no_checklist_na_ordem_combinada(): void
    {
        $ids = array_column(MlbImplementacao::CHECKLIST, 'id');

        $this->assertSame(
            ['integrador_logistico', 'produtos_perfil', 'canais_faturamento', 'hub'],
            array_slice($ids, array_search('integrador_logistico', $ids, true), 4)
        );
    }

    public function test_select_opcoes_marca_feito_sozinho_e_hub_nao(): void
    {
        $porId = array_column(MlbImplementacao::CHECKLIST, null, 'id');

        $this->assertSame('select_opcoes', $porId['produtos_perfil']['tipo']);
        $this->assertSame('select_opcoes', $porId['canais_faturamento']['tipo']);

        // HUB é 'select' (não 'select_opcoes') para MANTER a trava anti-check-vazio.
        $this->assertSame('select', $porId['hub']['tipo']);
        $this->assertSame(MlbImplementacao::HUB_OPCOES, $porId['hub']['opcoes']);
        $this->assertTrue($porId['hub']['tem_acesso']);
    }

    public function test_hub_mantem_trava_anti_check_vazio(): void
    {
        $impl = $this->criarImpl();

        // Sem escolher o HUB, marcar feito é recusado.
        $this->marcarFeito($impl, 'hub')->assertStatus(422);

        $this->patch(route('implementacao.salvar', $impl->token), [
            'id' => 'hub', 'campo' => 'valor', 'valor' => 'Magis5',
        ])->assertOk();

        $this->marcarFeito($impl, 'hub')->assertOk();
        $this->assertTrue($impl->refresh()->dados['itens']['hub']['feito']);
    }

    // ─── 6. ME1 e Integradora ────────────────────────────────────────────────

    public function test_me1_opcoes_tem_apenas_os_cinco_valores(): void
    {
        $this->assertSame(
            ['Não é necessário', 'Precisa de ME1', 'Em contratação', 'Ativo', 'Não'],
            MlbImplementacao::ONB_ME1_OPCOES
        );
    }

    /**
     * As 12 variantes medidas no banco em 01/09/2026 (269 fichas).
     *
     * @dataProvider variantesDeMe1
     */
    public function test_normalizar_me1_cobre_as_variantes_do_banco(string $banco, ?string $esperado): void
    {
        $this->assertSame($esperado, MlbImplementacao::normalizarMe1($banco));
    }

    public static function variantesDeMe1(): array
    {
        return [
            'NÃO (91)'                      => ['NÃO', 'Não'],
            'Sem itens ainda (49)'          => ['Sem itens ainda', null],
            'Não é Necessario (41)'         => ['Não é Necessario', 'Não é necessário'],
            'Ativo (20)'                    => ['Ativo', 'Ativo'],
            'Precisa de ME1 (19)'           => ['Precisa de ME1', 'Precisa de ME1'],
            'EM CONTRATAÇÃO (16)'           => ['EM CONTRATAÇÃO', 'Em contratação'],
            'Conversando com Cliente (4)'   => ['Conversando com Cliente', 'Em contratação'],
            'Aguardando Contato (4)'        => ['Aguardando Contato', 'Em contratação'],
            'Pendente com Integradora (3)'  => ['Pendente com Integradora', 'Em contratação'],
            'VERIFICANDO (2)'               => ['VERIFICANDO', 'Em contratação'],
            'Preenchendo Tabela (1)'        => ['Preenchendo Tabela', 'Em contratação'],
            'vazio'                         => ['', null],
            'desconhecido passa verbatim'   => ['Status Novo Da Planilha', 'Status Novo Da Planilha'],
        ];
    }

    public function test_normalizar_integradora_alinha_a_grafia(): void
    {
        $this->assertSame('Intelipost', MlbImplementacao::normalizarIntegradora('Intelispost'));
        $this->assertSame('Anymarket', MlbImplementacao::normalizarIntegradora('Any'));
        // Desconhecido passa fiel — a planilha continua sendo a verdade.
        $this->assertSame('Frenet', MlbImplementacao::normalizarIntegradora('Frenet'));
        $this->assertNull(MlbImplementacao::normalizarIntegradora(''));
    }

    public function test_regra_automatica_do_me1_continua_valendo(): void
    {
        // 'Precisa de ME1' sobrevive ao enxugamento do catálogo e ao de-para.
        $this->assertContains('Precisa de ME1', MlbImplementacao::ONB_ME1_OPCOES);
        $this->assertSame('Precisa de ME1', MlbImplementacao::normalizarMe1('Precisa de ME1'));
    }
}
