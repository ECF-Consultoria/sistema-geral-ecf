<?php

namespace Tests\Feature\Onboarding;

use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Opção "Caso tenha anúncios no Mercado Livre e queira jardinagem/otimização de anúncios"
 * no link do cliente, logo abaixo da Planilha de Produtos (TKT-0010, DEV-39).
 *
 * Decisão do desenvolvedor (08/10/2026): é uma OPÇÃO, não um item do checklist. Por isso
 * mora como sub-chave do item existente (`dados.itens.planilha_produtos.jardinagem`):
 *   - os itens 11–17 não mudam de número;
 *   - o progresso (x de 17) e as pendências não mudam — as fichas em 100% seguem em 100%;
 *   - grava pelo mesmo autosave do link, sem migration.
 * A equipe vê quem marcou na ficha interna e na coluna "Jardinagem" do Painel Polos.
 *
 * @group onboarding
 */
class JardinagemAnunciosTest extends TestCase
{
    use RefreshDatabase;

    private function criarImpl(?array $dados = null): MlbImplementacao
    {
        $empresa = MlbEmpresa::create([
            'nome'    => 'Loja Jardinagem ' . Str::random(4),
            'tipo'    => 'POLO',
            'projeto' => 'POLOS',
            'fase'    => 'M1',
            'polo'    => 'Arapongas',
            'estagio' => 'Não Listado',
        ]);

        return MlbImplementacao::create([
            'empresa_id' => $empresa->id,
            'token'      => Str::random(48),
            'dados'      => $dados,
        ]);
    }

    private function salvar(MlbImplementacao $impl, string $id, string $campo, $valor)
    {
        return $this->patchJson(route('implementacao.salvar', $impl->token), [
            'id'    => $id,
            'campo' => $campo,
            'valor' => $valor,
        ]);
    }

    /** Ficha com todos os itens do CHECKLIST marcados (17/17) e produtos na planilha. */
    private function dadosConcluidos(): array
    {
        $dados = MlbImplementacao::dadosPadrao();
        foreach ($dados['itens'] as $id => $item) {
            $dados['itens'][$id]['feito'] = true;
        }
        $dados['itens']['planilha_produtos']['produtos'] = [['sku' => 'SKU-1', 'produto' => 'Cadeira']];

        return $dados;
    }

    // ─── Não é item do checklist ─────────────────────────────────────────────

    public function test_nao_vira_item_novo_nem_renumera_o_checklist(): void
    {
        $ids = array_column(MlbImplementacao::CHECKLIST, 'id');

        $this->assertCount(17, $ids);
        $this->assertNotContains('jardinagem', $ids);
        // Planilha de Produtos segue sendo o 10 e Drive com Imagens o 11.
        $this->assertSame('planilha_produtos', $ids[9]);
        $this->assertSame('drive_imagens', $ids[10]);

        $this->assertFalse(MlbImplementacao::dadosPadrao()['itens']['planilha_produtos']['jardinagem']);
    }

    // ─── Aparece e grava pelo link ───────────────────────────────────────────

    public function test_link_do_cliente_entrega_a_opcao_desmarcada(): void
    {
        $impl = $this->criarImpl();
        $this->withoutVite();

        $this->get(route('implementacao.workspace', $impl->token))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Mlb/ImplementacaoPublica')
                ->where('impl.dados.itens.planilha_produtos.jardinagem', false));
    }

    public function test_cliente_marca_e_desmarca_pelo_autosave(): void
    {
        $impl = $this->criarImpl(MlbImplementacao::dadosPadrao());

        $this->salvar($impl, 'planilha_produtos', 'jardinagem', true)->assertOk();
        $this->assertTrue($impl->refresh()->dados['itens']['planilha_produtos']['jardinagem']);
        $this->assertTrue($impl->querJardinagem());

        $this->salvar($impl, 'planilha_produtos', 'jardinagem', false)->assertOk();
        $this->assertFalse($impl->refresh()->dados['itens']['planilha_produtos']['jardinagem']);
        $this->assertFalse($impl->querJardinagem());
    }

    public function test_valor_vira_booleano_mesmo_vindo_como_texto(): void
    {
        $impl = $this->criarImpl(MlbImplementacao::dadosPadrao());

        $this->salvar($impl, 'planilha_produtos', 'jardinagem', 'false')->assertOk();
        $this->assertFalse($impl->refresh()->dados['itens']['planilha_produtos']['jardinagem']);

        $this->salvar($impl, 'planilha_produtos', 'jardinagem', '1')->assertOk();
        $this->assertTrue($impl->refresh()->dados['itens']['planilha_produtos']['jardinagem']);
    }

    public function test_marcar_nao_apaga_os_produtos_nem_o_feito_do_item_10(): void
    {
        $impl = $this->criarImpl(MlbImplementacao::dadosPadrao());
        $produtos = [['sku' => 'CAD-001', 'produto' => 'Cadeira Gamer']];

        $this->salvar($impl, 'planilha_produtos', 'produtos', $produtos)->assertOk();
        $this->salvar($impl, 'planilha_produtos', 'feito', true)->assertOk();
        $this->salvar($impl, 'planilha_produtos', 'jardinagem', true)->assertOk();

        $item = $impl->refresh()->dados['itens']['planilha_produtos'];
        $this->assertSame($produtos, $item['produtos']);
        $this->assertTrue($item['feito']);
        $this->assertTrue($item['jardinagem']);
    }

    public function test_marcar_fica_no_log_de_atividade(): void
    {
        $impl = $this->criarImpl(MlbImplementacao::dadosPadrao());

        $this->salvar($impl, 'planilha_produtos', 'jardinagem', true)->assertOk();

        $this->assertTrue(
            DB::table('activity_log')
                ->where('log_name', 'implementacao')
                ->where('description', 'like', '%jardinagem%')
                ->exists()
        );
    }

    // ─── Ficha antiga ────────────────────────────────────────────────────────

    public function test_ficha_antiga_sem_a_chave_abre_e_grava(): void
    {
        // JSON salvo antes do TKT-0010: o item 10 não tem 'jardinagem'.
        $dados = MlbImplementacao::dadosPadrao();
        $dados['itens']['planilha_produtos'] = ['produtos' => [['sku' => 'X1']], 'feito' => true];
        $impl = $this->criarImpl($dados);

        $this->assertFalse($impl->querJardinagem());

        $this->withoutVite();
        $this->get(route('implementacao.workspace', $impl->token))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('impl.dados.itens.planilha_produtos.jardinagem', false));

        $this->salvar($impl, 'planilha_produtos', 'jardinagem', true)->assertOk();

        $item = $impl->refresh()->dados['itens']['planilha_produtos'];
        $this->assertTrue($item['jardinagem']);
        $this->assertSame([['sku' => 'X1']], $item['produtos']);
        $this->assertTrue($item['feito']);
    }

    // ─── Progresso e pendências NÃO mudam ────────────────────────────────────

    public function test_ficha_concluida_segue_em_100_por_cento(): void
    {
        $impl = $this->criarImpl($this->dadosConcluidos());

        $antes = $impl->progresso();
        $this->assertSame(['feitos' => 17, 'total' => 17, 'pct' => 100], $antes);

        $resposta = $this->salvar($impl, 'planilha_produtos', 'jardinagem', true)->assertOk();
        $this->assertSame($antes, $resposta->json('progresso'));
        $this->assertSame($antes, $impl->refresh()->progresso());
        $this->assertSame('concluido', $impl->statusEnvio());
    }

    public function test_ficha_antiga_concluida_sem_a_chave_segue_em_100_por_cento(): void
    {
        $dados = $this->dadosConcluidos();
        unset($dados['itens']['planilha_produtos']['jardinagem']);

        $this->assertSame(100, $this->criarImpl($dados)->progresso()['pct']);
    }

    public function test_marcar_nao_conta_como_item_feito(): void
    {
        $impl = $this->criarImpl(MlbImplementacao::dadosPadrao());

        $resposta = $this->salvar($impl, 'planilha_produtos', 'jardinagem', true)->assertOk();

        $this->assertSame(0, $resposta->json('progresso.feitos'));
        $this->assertSame(17, $resposta->json('progresso.total'));
        $this->assertFalse($impl->refresh()->dados['itens']['planilha_produtos']['feito']);
    }

    // ─── A equipe vê ─────────────────────────────────────────────────────────

    public function test_ficha_interna_mostra_quem_marcou(): void
    {
        $impl = $this->criarImpl(MlbImplementacao::dadosPadrao());
        $this->salvar($impl, 'planilha_produtos', 'jardinagem', true)->assertOk();

        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('mlb.implementacao.ficha', $impl->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('impl.jardinagem', true));
    }

    public function test_painel_polos_mostra_a_coluna_jardinagem(): void
    {
        $marcou = $this->criarImpl(MlbImplementacao::dadosPadrao());
        $this->salvar($marcou, 'planilha_produtos', 'jardinagem', true)->assertOk();

        $naoMarcou = $this->criarImpl(MlbImplementacao::dadosPadrao());

        $this->withoutVite();
        $resposta = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('mlb.polos-painel'))
            ->assertOk();

        $porFicha = collect($resposta->viewData('page')['props']['empresas'])->keyBy('impl_id');

        $this->assertSame('Sim', $porFicha[$marcou->id]['jardinagem']);
        $this->assertNull($porFicha[$naoMarcou->id]['jardinagem']);
    }

    public function test_empresa_sem_ficha_manda_jardinagem_null(): void
    {
        MlbEmpresa::create([
            'nome' => 'Loja Sem Ficha Jard', 'tipo' => 'POLO', 'projeto' => 'POLOS',
            'fase' => 'M2', 'polo' => 'Arapongas',
        ]);

        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('mlb.polos-painel'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('empresas.0.jardinagem', null));
    }
    public function test_exportacao_do_painel_traz_a_coluna_jardinagem(): void
    {
        // O bloco financeiro (admin) chama Adman/ECF Drive — sem o fake o teste espera a rede.
        Http::fake();

        $impl = $this->criarImpl(MlbImplementacao::dadosPadrao());
        $this->salvar($impl, 'planilha_produtos', 'jardinagem', true)->assertOk();

        $resposta = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('mlb.polos-painel.exportar'))
            ->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'jard') . '.xlsx';
        file_put_contents($tmp, $resposta->streamedContent());
        $planilha = IOFactory::load($tmp);
        @unlink($tmp);

        $linhas = $planilha->getActiveSheet()->toArray();
        $planilha->disconnectWorksheets();

        $col = array_search('Jardinagem', $linhas[0], true);
        $this->assertNotFalse($col, "coluna 'Jardinagem' sumiu da planilha");
        $this->assertSame('Sim', $linhas[1][$col]);
    }
}
