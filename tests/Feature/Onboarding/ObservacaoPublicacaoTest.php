<?php

namespace Tests\Feature\Onboarding;

use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Publicar em Massa?" → "Observações sobre publicação" (2026-09-02).
 *
 * O item 09 era um select de 4 opções e virou um campo de texto livre e OPCIONAL, cujo
 * conteúdo aparece na visão do Publicador. As duas decisões que este teste protege:
 *
 * 1. O `id` do item continua `publicar_em_massa`. Trocá-lo deixaria uma chave órfã em
 *    `dados.itens` das fichas já salvas e `progresso()` conta `count($itens)` — a órfã
 *    entraria no denominador e essas fichas nunca mais fechariam 100%.
 * 2. O campo é opcional: `itemTemConteudo` NÃO trava o check. Travar deixaria toda ficha
 *    sem observação presa abaixo de 100% para sempre.
 *
 * @group onboarding
 */
class ObservacaoPublicacaoTest extends TestCase
{
    use RefreshDatabase;

    private function criarImpl(array $implOpts = []): MlbImplementacao
    {
        $criador = User::factory()->create();

        $empresa = MlbEmpresa::create([
            'nome'       => 'Loja Obs ' . Str::random(4),
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

    private function salvar(MlbImplementacao $impl, string $id, string $campo, $valor)
    {
        return $this->patch(route('implementacao.salvar', $impl->token), [
            'id'    => $id,
            'campo' => $campo,
            'valor' => $valor,
        ]);
    }

    // ─── Forma do item no CHECKLIST ──────────────────────────────────────────

    public function test_item_virou_observacao_e_manteve_o_id(): void
    {
        $porId = array_column(MlbImplementacao::CHECKLIST, null, 'id');

        // O id NÃO muda — é a chave já gravada nas fichas do banco.
        $this->assertArrayHasKey('publicar_em_massa', $porId);

        $item = $porId['publicar_em_massa'];
        $this->assertSame('Observações sobre publicação', $item['titulo']);
        $this->assertSame('observacao', $item['tipo']);

        // O select morreu: sem lista de opções, nada de "Sim/Não" na tela.
        $this->assertArrayNotHasKey('opcoes', $item);
    }

    public function test_default_tem_a_chave_observacao_sem_perder_o_valor_antigo(): void
    {
        $padrao = MlbImplementacao::dadosPadrao()['itens']['publicar_em_massa'];

        $this->assertSame('', $padrao['observacao']);
        // 'valor' segue no padrão: é onde mora a resposta do select antigo nas fichas
        // já respondidas, e apagá-la do padrão a apagaria do merge.
        $this->assertArrayHasKey('valor', $padrao);
        $this->assertFalse($padrao['feito']);
    }

    // ─── Campo opcional: o check nunca trava ─────────────────────────────────

    public function test_observacao_e_opcional_e_nao_trava_o_check(): void
    {
        $this->assertTrue(MlbImplementacao::itemTemConteudo('observacao', []));
        $this->assertTrue(MlbImplementacao::itemTemConteudo('observacao', ['observacao' => '']));
    }

    public function test_cliente_sem_observacao_consegue_concluir_o_item(): void
    {
        $impl = $this->criarImpl();

        $this->salvar($impl, 'publicar_em_massa', 'feito', true)->assertOk();

        $this->assertTrue($impl->refresh()->dados['itens']['publicar_em_massa']['feito']);
    }

    // ─── O texto grava e chega ao Publicador ─────────────────────────────────

    public function test_texto_grava_em_observacao_e_o_publicador_le(): void
    {
        $impl = $this->criarImpl();
        $texto = "Não anunciar a linha infantil.\nPrioridade: SKUs 100-120.";

        $this->salvar($impl, 'publicar_em_massa', 'observacao', $texto)->assertOk();
        // O front encadeia o 'feito' logo depois do texto (ver ImplementacaoPublica.jsx).
        $this->salvar($impl, 'publicar_em_massa', 'feito', true)->assertOk();

        $item = $impl->refresh()->dados['itens']['publicar_em_massa'];
        $this->assertSame($texto, $item['observacao']);
        $this->assertTrue($item['feito']);

        // É este payload que a visão do Publicador renderiza. withoutVite(): a asserção é
        // sobre as props do Inertia, não sobre o bundle — sem isso o teste exige um
        // `npm run build` na árvore para achar o manifest.
        $this->withoutVite();

        $this->get(route('implementacao.publicador', $impl->token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('impl.dados.itens.publicar_em_massa.observacao', $texto));
    }

    // ─── Fichas antigas: nada se perde, nada trava ───────────────────────────

    public function test_ficha_antiga_ganha_a_chave_sem_perder_a_resposta_do_select(): void
    {
        // Ficha salva quando o item ainda era select: tem 'valor', não tem 'observacao'.
        $dados = MlbImplementacao::dadosPadrao();
        $dados['itens']['publicar_em_massa'] = ['valor' => 'Sim', 'feito' => true];

        $impl = $this->criarImpl(['dados' => $dados]);

        $merged = MlbImplementacao::mesclarItensPadrao($impl->refresh()->dados)['itens']['publicar_em_massa'];

        $this->assertSame('', $merged['observacao']); // chave nova entra pelo merge
        $this->assertSame('Sim', $merged['valor']);   // resposta antiga preservada
        $this->assertTrue($merged['feito']);

        // E o cliente consegue escrever a observação nessa ficha (sem 422 do
        // abort_unless que derrubava item acrescentado depois do JSON salvo).
        $this->salvar($impl, 'publicar_em_massa', 'observacao', 'ok')->assertOk();
        $this->assertSame('ok', $impl->refresh()->dados['itens']['publicar_em_massa']['observacao']);
    }
}
