<?php

namespace Tests\Feature\Trilha;

use App\Models\BlingConexao;
use App\Models\BlingVinculo;
use App\Models\Company;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Models\TrilhaMapeamento;
use App\Models\TrilhaOnda;
use App\Models\TrilhaOndaItem;
use App\Models\User;
use App\Services\Portal\Estrutura\Produtos\VariacaoImagensService;
use App\Services\Trilha\CadastroNaTrilha;
use App\Services\Trilha\TrilhaDaEmpresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * Cores, tamanhos e exclusões no cadastro da trilha, fatia F4 (3ª entrega, 03/11/2026; desenho §5.6, §5.9, §6.2 rotas
 * 3, 14 e 15):
 *
 * - a rota das linhas aceita várias variações e as NOVAS (sem `id`) no mesmo pedido: a 1ª cor nomeia a variação que já
 *   existe no MESMO pedido; a cor nova herda custo e caixas da 1ª (não o estoque) e ganha a sua oferta simples;
 * - a conferência das cores (a régua do `CoresDoGrupo`, a que o Publicador usa para separar em produto à parte, em
 *   silêncio): sem nome com 2+, repetida (sem caixa nem acento, e o PAR cor+tamanho), de outro tipo, sem o 2º valor —
 *   422 por linha com a `chave` que a tela mandou, e NADA grava (nem a linha boa do mesmo pedido);
 * - dois eixos (a camiseta MLB31447, 2 cores × 3 tamanhos): gravam certo, e a Lista conta as fotos por COR;
 * - excluir a cor (rota 14): peça de combo/kit é 422 e nada muda; a dona da galeria passa as fotos para outro tamanho;
 *   a última variação só sai pelo "Excluir produto";
 * - excluir o produto (rota 15) em dois passos, com as montadas confirmadas: o combo sai da onda, a cascata leva as
 *   linhas das ondas e o mapeamento;
 * - o produto já mapeado que ganha cor: `mapear_de_novo` e a marca do mapeamento fica.
 *
 * O custo (o Publicador recebe o produto de 2 cores UMA vez, num grupo) está no {@see CustoDoCadastroTest}; a guarda das
 * rotas 14 e 15, no {@see CadastroNaTrilhaAcessoTest}.
 */
class CoresNaTrilhaTest extends TestCase
{
    use MontaTrilha;
    use RefreshDatabase;

    private Company $empresa;

    private User $dev;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Cache::flush();
        Storage::fake(VariacaoImagensService::DISCO);
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', TrilhaOnda::FUSO));
        config(['trilha.empresas' => [], 'trilha.trabalho.empresas' => [], 'publicador.preparo_ia.ativo' => true]);
        $this->empresa = $this->empresaDaTrilha();
        $this->dev = User::factory()->create(['role' => 'admin', 'active' => true, 'name' => 'Rafael']);
        $this->dev->forceFill(['is_dev' => true])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══ Cor nova (rota 3) ═════════════════════════════════════════════════

    public function test_a_primeira_cor_nomeia_a_que_existe_no_mesmo_pedido_e_a_nova_herda_custo_e_caixas_e_ganha_oferta(): void
    {
        [$produto, $v1] = $this->produtoNovo('Poltrona Ripada', 'POLT-1');
        $this->linhas($produto, [['id' => $v1->id, 'codigo' => 'POLT-1', 'nome' => 'Poltrona Ripada', 'custo' => '890,00', 'estoque' => '4',
            'volumes' => [['c' => 80, 'l' => 80, 'a' => 90, 'kg' => 18.5]]]])->assertOk();

        $r = $this->linhas($produto, [
            ['id' => $v1->id, 'chave' => "v{$v1->id}", 'codigo' => 'POLT-1', 'nome' => 'Poltrona Ripada', 'eixo' => 'cor', 'valor' => 'Bege'],
            ['chave' => 'n1', 'codigo' => 'POLT-2', 'nome' => 'Poltrona Ripada', 'eixo' => 'cor', 'valor' => 'Areia'],
        ])->assertOk()->json();

        $this->assertSame([], $r['erros']);
        $this->assertSame([1, 1], [$r['totais']['criadas'], $r['totais']['atualizadas']]);
        [$bege, $areia] = $produto->variacoes()->get()->all();
        $this->assertSame([(int) $v1->id, 'cor', 'Bege'], [(int) $bege->id, $bege->eixo, $bege->valor]);
        // A nova: o custo e as caixas da 1ª; o estoque, não (é de cada cor); a ordem, a seguinte.
        $this->assertSame(['POLT-2', 'cor', 'Areia', 890.0, null, 2], [$areia->codigo, $areia->eixo, $areia->valor, $areia->custo, $areia->estoque, (int) $areia->ordem]);
        $this->assertSame([[80.0, 80.0, 90.0, 18.5]], $areia->volumes()->get()->map(fn ($x) => [(float) $x->comprimento, (float) $x->largura, (float) $x->altura, (float) $x->peso])->all());
        // Cada cor com a sua oferta simples (SKU = Ref, "Produto — Cor").
        $this->assertSame([['POLT-1', 'Poltrona Ripada — Bege'], ['POLT-2', 'Poltrona Ripada — Areia']],
            EstruturaOferta::query()->where('company_id', $this->empresa->id)->orderBy('id')->get()->map(fn ($o) => [$o->sku, $o->nome])->all());

        // O retrato que volta já é o novo: as duas cores, o eixo do produto, e a falta das fotos.
        $this->assertSame('cor', $r['produto']['eixo']);
        $this->assertSame(['Bege', 'Areia'], array_column($r['variacoes'], 'valor'));
        $this->assertContains('fotos (0 de 3)', $r['situacao']['faltas']);
        $this->assertFalse($r['mapear_de_novo']);
        // Incompleto: nada vai ao Publicador (nem pela oferta nova).
        Queue::assertNothingPushed();
        $this->assertSame([CadastroNaTrilha::PASSO_CRIAR, CadastroNaTrilha::PASSO_LINHAS, CadastroNaTrilha::PASSO_LINHAS], $this->passos());
    }

    public function test_a_cor_nova_com_custo_e_caixas_proprios_nao_herda_e_uma_cor_a_mais_entra_herdando_o_eixo(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA', ['Azul', 'Verde']);

        $this->linhas($p['produto'], [
            ['chave' => 'n1', 'codigo' => 'VA-3', 'nome' => 'Vaso Alfa', 'eixo' => 'cor', 'valor' => 'Rosa', 'custo' => '55,50', 'volumes' => [['c' => 10, 'l' => 10, 'a' => 10, 'kg' => 1]]],
            // Sem eixo na linha: o serviço herda o da 1ª variação (a cor).
            ['chave' => 'n2', 'codigo' => 'VA-4', 'nome' => 'Vaso Alfa', 'valor' => 'Cinza'],
        ])->assertOk()->assertJsonPath('erros', []);

        $rosa = EstruturaProdutoVariacao::query()->where('codigo', 'VA-3')->sole();
        $cinza = EstruturaProdutoVariacao::query()->where('codigo', 'VA-4')->sole();
        $this->assertSame([55.5, [[10.0, 10.0, 10.0, 1.0]]], [$rosa->custo, $rosa->volumes()->get()->map(fn ($x) => [(float) $x->comprimento, (float) $x->largura, (float) $x->altura, (float) $x->peso])->all()]);
        $this->assertSame(['cor', 100.0], [$cinza->eixo, $cinza->custo]);
        $this->assertSame(4, EstruturaOferta::query()->where('company_id', $this->empresa->id)->count());
    }

    // ═══ A conferência das cores ═══════════════════════════════════════════

    /**
     * Cada caso: [cores do produto (`null` = sem eixo; "Cor/Tam" = cor e tamanho), as linhas do pedido (a 1ª edita a
     * variação que existe), o campo marcado na linha nova, o motivo].
     *
     * @return array<string, array{0: list<?string>, 1: list<array>, 2: string, 3: string}>
     */
    public static function coresRecusadas(): array
    {
        return [
            'sem nome com duas ou mais' => [['Bege'], [['chave' => 'n1', 'codigo' => 'X-2', 'nome' => 'Vaso Alfa', 'eixo' => 'cor']], 'linhas.0.valor', 'sem_valor'],
            'repetida sem caixa nem acento' => [['Café'], [['chave' => 'n1', 'codigo' => 'X-2', 'nome' => 'Vaso Alfa', 'eixo' => 'cor', 'valor' => ' CAFE ']], 'linhas.0.valor', 'repetida'],
            'outro tipo de variação' => [['Bege'], [['chave' => 'n1', 'codigo' => 'X-2', 'nome' => 'Vaso Alfa', 'eixo' => 'tamanho', 'valor' => 'G']], 'linhas.0.eixo', 'outro_tipo'],
            'o par cor e tamanho repetido' => [['Azul/P'], [['chave' => 'n1', 'codigo' => 'X-2', 'nome' => 'Vaso Alfa', 'eixo' => 'cor', 'valor' => 'azul', 'eixo_2' => 'tamanho', 'valor_2' => 'p']], 'linhas.0.valor_2', 'repetida'],
            'sem o tamanho' => [['Azul/P'], [['chave' => 'n1', 'codigo' => 'X-2', 'nome' => 'Vaso Alfa', 'eixo' => 'cor', 'valor' => 'Azul']], 'linhas.0.valor_2', 'sem_valor_2'],
        ];
    }

    #[DataProvider('coresRecusadas')]
    public function test_a_variacao_que_o_publicador_separaria_e_422_por_linha_e_nada_grava(array $cores, array $novas, string $campo, string $motivo): void
    {
        $p = $this->produtoComCores($cores);
        $v = $p['variacoes'][0];
        $antes = $this->retratoDoBanco($p['produto']);

        // A linha boa (o custo da que existe) vai junto: nada dela grava.
        $r = $this->linhas($p['produto'], [['id' => $v->id, 'chave' => "v{$v->id}", 'codigo' => $v->codigo, 'nome' => 'Vaso Alfa', 'custo' => '1,00'], ...$novas])
            ->assertUnprocessable()
            ->assertJsonPath('message', CadastroNaTrilha::MSG_CORES)
            ->json();

        $campoDaLinha = str_replace('linhas.0.', 'linhas.1.', $campo);
        $this->assertArrayHasKey($campoDaLinha, $r['errors']);
        $erro = collect($r['erros'])->firstWhere('indice', 1);
        $this->assertSame(['n1', null, 'X-2', $motivo], [$erro['chave'], $erro['variacao_id'], $erro['codigo'], $erro['motivo']]);
        $this->assertSame([substr($campo, strlen('linhas.0.')) => $erro['mensagem']], $erro['campos']);
        $this->assertStringNotContainsString('Portal', json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('Publicador', json_encode($r['erros'], JSON_UNESCAPED_UNICODE));

        $this->assertSame($antes, $this->retratoDoBanco($p['produto']), 'nada gravou');
        $this->assertSame(1, EstruturaOferta::query()->where('company_id', $this->empresa->id)->count());
        $this->assertSame([], $this->passos());
        Queue::assertNothingPushed();
    }

    /** O caso que o desenho cita: a cor nova sem nomear a que já existe — o erro vai na linha DELA (pelo `id`). */
    public function test_cor_nova_sem_nomear_a_que_existe_marca_a_que_existe_e_nada_grava(): void
    {
        [$produto, $v1] = $this->produtoNovo('Poltrona', 'POLT-1');

        $r = $this->linhas($produto, [
            ['id' => $v1->id, 'chave' => "v{$v1->id}", 'codigo' => 'POLT-1', 'nome' => 'Poltrona'],
            ['chave' => 'n1', 'codigo' => 'POLT-2', 'nome' => 'Poltrona', 'eixo' => 'cor', 'valor' => 'Areia'],
        ])->assertUnprocessable()->json();

        $this->assertSame(['linhas.0.valor'], array_keys($r['errors']));
        $this->assertSame([0, "v{$v1->id}", (int) $v1->id, 'sem_valor'], [$r['erros'][0]['indice'], $r['erros'][0]['chave'], $r['erros'][0]['variacao_id'], $r['erros'][0]['motivo']]);
        $this->assertSame(1, $produto->variacoes()->count());

        // A que existe, fora do pedido: o erro vem pelo id dela (`variacoes.{id}.valor`), sem linha.
        $r = $this->linhas($produto, [['chave' => 'n1', 'codigo' => 'POLT-2', 'nome' => 'Poltrona', 'eixo' => 'cor', 'valor' => 'Areia']])
            ->assertUnprocessable()->json();
        $this->assertSame(["variacoes.{$v1->id}.valor"], array_keys($r['errors']));
        $this->assertSame([null, null, (int) $v1->id], [$r['erros'][0]['indice'], $r['erros'][0]['chave'], $r['erros'][0]['variacao_id']]);
        $this->assertSame(1, $produto->variacoes()->count());
    }

    /**
     * A 1ª cor que falha por OUTRO motivo (o custo inválido) não grava — e a cor nova do mesmo pedido, que gravaria
     * sozinha ao lado de uma variação sem nome, também não: 422 com o erro da linha (o que a pessoa tem de consertar),
     * sem o "falta a cor" de uma cor que ela digitou.
     */
    public function test_a_primeira_cor_que_falha_por_outro_motivo_segura_a_nova_e_o_erro_e_o_da_linha(): void
    {
        [$produto, $v1] = $this->produtoNovo('Poltrona', 'POLT-1');

        $r = $this->linhas($produto, [
            ['id' => $v1->id, 'chave' => "v{$v1->id}", 'codigo' => 'POLT-1', 'nome' => 'Poltrona', 'eixo' => 'cor', 'valor' => 'Bege', 'custo' => 'abc'],
            ['chave' => 'n1', 'codigo' => 'POLT-2', 'nome' => 'Poltrona', 'eixo' => 'cor', 'valor' => 'Areia'],
        ])->assertUnprocessable()->json();

        $this->assertSame(['linhas.0.custo'], array_keys($r['errors']));
        $this->assertSame([0, "v{$v1->id}", 'linha'], [$r['erros'][0]['indice'], $r['erros'][0]['chave'], $r['erros'][0]['motivo']]);
        $this->assertCount(1, $r['erros']);
        $this->assertSame([1, null], [$produto->variacoes()->count(), $v1->fresh()->valor]);
        $this->assertSame(1, EstruturaOferta::query()->where('company_id', $this->empresa->id)->count());
    }

    /** O que o produto já tinha fora da régua (cadastrado antes por outra porta) não trava o salvar de outra coisa. */
    public function test_o_problema_que_o_produto_ja_tinha_nao_trava_uma_cor_nova_certa(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA', ['Azul', 'azul']);

        $this->linhas($p['produto'], [['chave' => 'n1', 'codigo' => 'VA-3', 'nome' => 'Vaso Alfa', 'eixo' => 'cor', 'valor' => 'Verde']])
            ->assertOk()->assertJsonPath('erros', []);
        // A cor nova repetindo a que já se repetia: essa, sim, é nova fora da régua.
        $this->linhas($p['produto'], [['chave' => 'n2', 'codigo' => 'VA-4', 'nome' => 'Vaso Alfa', 'eixo' => 'cor', 'valor' => 'AZUL']])
            ->assertUnprocessable()->assertJsonValidationErrors(['linhas.0.valor']);

        $this->assertSame(['Azul', 'azul', 'Verde'], $p['produto']->variacoes()->pluck('valor')->all());
    }

    /** A variação do Bling: o SKU e o estoque não mudam (vêm de lá), mas a cor dela se nomeia no mesmo pedido da nova. */
    public function test_a_variacao_do_bling_ganha_a_cor_sem_mudar_sku_nem_estoque(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA');
        $v = $p['variacoes'][0];
        BlingConexao::create(['company_id' => $this->empresa->id, 'access_token' => 'a', 'refresh_token' => 'r',
            'status' => BlingConexao::STATUS_ATIVA, 'estoque_ativo' => true]);
        BlingVinculo::create(['company_id' => $this->empresa->id, 'bling_produto_id' => '987', 'variacao_id' => $v->id,
            'codigo_bling' => 'VA', 'situacao' => BlingVinculo::SITUACAO_VINCULADO]);

        $r = $this->linhas($p['produto'], [
            ['id' => $v->id, 'chave' => "v{$v->id}", 'codigo' => 'VA-NOVO', 'nome' => 'Vaso Alfa', 'eixo' => 'cor', 'valor' => 'Azul', 'estoque' => '9'],
            ['chave' => 'n1', 'codigo' => 'VA-2', 'nome' => 'Vaso Alfa', 'eixo' => 'cor', 'valor' => 'Verde'],
        ])->assertOk()->json();

        $this->assertSame(['VA', null, 'Azul'], [$v->fresh()->codigo, $v->fresh()->estoque, $v->fresh()->valor]);
        $this->assertStringContainsString('vêm do Bling', implode(' ', $r['avisos']));
        $this->assertSame([true, false], array_column($r['variacoes'], 'bling'));
    }

    // ═══ Dois eixos: a camiseta (MLB31447) ════════════════════════════════

    /**
     * Camiseta de teste, 2 cores × 3 tamanhos: a variação que existe vira Azul P no MESMO pedido das 5 novas; cada uma
     * ganha a sua oferta ("Camiseta — Azul · M"), herda custo e caixas. As fotos são da COR (na dona, a 1ª variação
     * dela): a Lista conta por cor — Azul com 3 na dona cobre P, M e G; Preto sai da falta quando a dona dele tem 3.
     */
    public function test_dois_eixos_gravam_certo_e_a_lista_conta_as_fotos_por_cor(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Camiseta Básica', 'CAM', [null],
            ['categoria_ml_id' => 'MLB31447', 'categoria_ml_nome' => 'Camisetas', 'categoria_ml_caminho' => 'Roupas › Camisetas']);
        $v1 = $p['variacoes'][0];
        $par = fn (string $codigo, string $cor, string $tam, array $mais = []) => ['chave' => "n-{$codigo}", 'codigo' => $codigo,
            'nome' => 'Camiseta Básica', 'eixo' => 'cor', 'valor' => $cor, 'eixo_2' => 'tamanho', 'valor_2' => $tam, ...$mais];

        $r = $this->linhas($p['produto'], [
            ['id' => $v1->id, ...$par('CAM', 'Azul', 'P', ['chave' => "v{$v1->id}"])],
            $par('CAM-AZ-M', 'Azul', 'M'), $par('CAM-AZ-G', 'Azul', 'G'),
            $par('CAM-PT-P', 'Preto', 'P'), $par('CAM-PT-M', 'Preto', 'M'), $par('CAM-PT-G', 'Preto', 'G'),
        ])->assertOk()->json();

        $this->assertSame([], $r['erros']);
        $this->assertSame([5, 1], [$r['totais']['criadas'], $r['totais']['atualizadas']]);
        $variacoes = $p['produto']->variacoes()->with('volumes')->get();
        $this->assertSame([['CAM', 'Azul', 'P'], ['CAM-AZ-M', 'Azul', 'M'], ['CAM-AZ-G', 'Azul', 'G'], ['CAM-PT-P', 'Preto', 'P'], ['CAM-PT-M', 'Preto', 'M'], ['CAM-PT-G', 'Preto', 'G']],
            $variacoes->map(fn ($v) => [$v->codigo, $v->valor, $v->valor_2])->all());
        foreach ($variacoes as $v) {
            $this->assertSame(['cor', 'tamanho', 100.0, 1], [$v->eixo, $v->eixo_2, $v->custo, $v->volumes->count()], $v->codigo);
        }
        $this->assertSame('Camiseta Básica — Preto · M', EstruturaOferta::query()->where('sku', 'CAM-PT-M')->value('nome'));
        $this->assertSame(['cor', 'tamanho'], [$r['produto']['eixo'], $r['produto']['eixo_2']]);
        // As donas: a 1ª variação de cada cor (a Azul P tem as fotos; o Preto, sem nenhuma, a 1ª dele).
        $this->assertSame([true, false, false, true, false, false], array_column($r['variacoes'], 'dona_da_cor'));
        $this->assertSame(['fotos (0 de 3)'], $r['situacao']['faltas'], 'Azul: as 3 da dona valem para P, M e G; Preto: nenhuma');

        $preto = $variacoes[3];
        $this->enviar($p['produto'], $preto, [$this->jpg('a.jpg'), $this->jpg('b.jpg')])->assertOk()->assertJsonPath('situacao.faltas', ['fotos (2 de 3)']);
        Queue::assertNothingPushed();
        $r = $this->enviar($p['produto'], $preto, [$this->jpg('c.jpg')])->assertOk()->json();

        $this->assertSame([[], 'mapa'], [$r['situacao']['faltas'], $r['situacao']['etapa']]);
        $this->assertSame([], TrilhaDaEmpresa::faltasDoProduto($this->empresa, (int) $p['produto']->id), 'a Lista diz o mesmo');
        $this->assertSame([], $this->produtoNaTrilha(app(TrilhaDaEmpresa::class)->montar($this->empresa->fresh()), (int) $p['produto']->id)['faltas']);
    }

    // ═══ Excluir a cor (rota 14) ═══════════════════════════════════════════

    public function test_excluir_a_cor_que_e_peca_de_combo_ou_kit_e_422_com_o_texto_do_servico_e_nada_muda(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA', ['Azul', 'Verde', 'Rosa']);
        $q = $this->produtoCompleto($this->empresa, 'Vaso Beta', 'VB');
        $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_COMBO, 'VA-2-CB2', 'Combo 2 Vaso Alfa Verde', [[$p['simples'][1], 2]]);
        $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_KIT, 'KT-VA-VB', 'Kit Alfa Rosa e Beta', [[$p['simples'][2], 1], [$q['simples'][0], 1]]);
        $antes = $this->retratoDoBanco($p['produto']);
        $ofertas = EstruturaOferta::query()->count();

        $this->excluirVariacao($p['produto'], $p['variacoes'][1])
            ->assertUnprocessable()
            ->assertJsonPath('errors.oferta.0', 'Esta oferta entra em VA-2-CB2. Exclua ou edite essas variações antes.');
        $this->excluirVariacao($p['produto'], $p['variacoes'][2])
            ->assertUnprocessable()
            ->assertJsonPath('errors.oferta.0', 'Esta oferta entra em KT-VA-VB. Exclua ou edite essas variações antes.');

        $this->assertSame($antes, $this->retratoDoBanco($p['produto']));
        $this->assertSame($ofertas, EstruturaOferta::query()->count());
        $this->assertSame([], $this->passos());

        // A que não é peça de nada sai: o retrato novo volta, sem ela, e a oferta dela sai junto.
        $r = $this->excluirVariacao($p['produto'], $p['variacoes'][0])->assertOk()->json();
        $this->assertSame(['Verde', 'Rosa'], array_column($r['variacoes'], 'valor'));
        $this->assertSame([0, 'VA-1', 'Variação VA-1 excluída.'], [$r['anuncios_para_espera'], $r['sku'], $r['mensagem']]);
        $this->assertNull(EstruturaOferta::query()->find($p['simples'][0]->id));
        $this->assertSame([CadastroNaTrilha::PASSO_VARIACAO_EXCLUIDA], $this->passos());
        $evento = $this->eventosDaTrilha()->last();
        $this->assertSame(['trilha', (int) $p['variacoes'][0]->id, 'VA-1'], [$evento->getExtraProperty('via'), (int) $evento->getExtraProperty('variacao_id'), $evento->getExtraProperty('sku')]);
        // Exclusão não agenda o Publicador (como no Portal).
        Queue::assertNothingPushed();
    }

    /** Com cor e tamanho, as fotos da cor moram na dona: excluí-la passa a galeria para o próximo tamanho da cor. */
    public function test_excluir_a_dona_da_cor_passa_a_galeria_para_outro_tamanho(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Camiseta', 'CM', ['Azul', 'Azul', 'Preto']);
        foreach ($p['variacoes'] as $i => $v) {
            $v->update(['eixo_2' => 'tamanho', 'valor_2' => $i === 1 ? 'M' : 'P']);
        }
        [$azulP, $azulM] = $p['variacoes'];
        EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $azulM->id)->delete();
        // As fotos da dona com arquivo no disco (a galeria passa COPIANDO o arquivo).
        foreach (EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $azulP->id)->get() as $img) {
            Storage::disk(VariacaoImagensService::DISCO)->put($img->caminho, 'jpeg');
        }

        $r = $this->excluirVariacao($p['produto'], $azulP)->assertOk()->json();

        $this->assertSame(3, EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $azulM->id)->count());
        $this->assertSame([['Azul', 'M', true, 3], ['Preto', 'P', true, 3]],
            array_map(fn ($v) => [$v['valor'], $v['valor_2'], $v['dona_da_cor'], count($v['fotos'])], $r['variacoes']));
        $this->assertNotContains('fotos (0 de 3)', $r['situacao']['faltas']);
        $this->assertSame([], $r['situacao']['faltas']);
    }

    public function test_a_ultima_variacao_nao_sai_pela_rota_da_cor(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA');

        $this->excluirVariacao($p['produto'], $p['variacoes'][0])
            ->assertUnprocessable()
            ->assertJsonPath('errors.variacao.0', CadastroNaTrilha::MSG_ULTIMA_VARIACAO);

        $this->assertNotNull($p['produto']->fresh());
        $this->assertSame(1, $p['produto']->variacoes()->count());
        $this->assertStringContainsString('Excluir produto', CadastroNaTrilha::MSG_ULTIMA_VARIACAO);
        $this->assertSame([], $this->passos());
    }

    /** O produto que andou (aqui: a cor Verde tem anúncio) não exclui nada pela trilha: 409, nem a cor nem o produto. */
    public function test_o_produto_travado_nao_exclui_cor_nem_produto(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA', ['Azul', 'Verde']);
        EstruturaAnuncio::create(['oferta_id' => $p['simples'][1]->id, 'tipo' => EstruturaAnuncio::TIPO_CLASSICO,
            'status' => EstruturaAnuncio::STATUS_ATIVO, 'codigo_mlb' => 'MLB1234567890', 'titulo' => 'Vaso']);

        $this->excluirVariacao($p['produto'], $p['variacoes'][1])->assertStatus(409)->assertJsonPath('travado', true);
        $this->excluirVariacao($p['produto'], $p['variacoes'][0])->assertStatus(409);
        $this->actingAs($this->dev)->postJson($this->url($p['produto'], '/exclusao/previa'))->assertStatus(409);
        $this->actingAs($this->dev)->deleteJson($this->url($p['produto'], ''), ['montadas' => []])->assertStatus(409);

        $this->assertSame(2, $p['produto']->variacoes()->count());
        $this->assertSame(1, EstruturaAnuncio::query()->count());
    }

    // ═══ Excluir o produto (rota 15) ═══════════════════════════════════════

    public function test_excluir_o_produto_em_dois_passos_leva_as_montadas_e_o_que_a_trilha_tinha_dele(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA', ['Azul', 'Verde']);
        $q = $this->produtoCompleto($this->empresa, 'Vaso Beta', 'VB');
        $combo = $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_COMBO, 'VA-1-CB2', 'Combo 2 Vaso Alfa Azul', [[$p['simples'][0], 2]]);
        $kit = $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_KIT, 'KT-VA-VB', 'Kit Alfa e Beta', [[$p['simples'][1], 1], [$q['simples'][0], 1]]);
        $onda = $this->onda($this->empresa, '2026-11-20', 'Onda Futura');
        $this->itemNaOnda($onda, "s-{$p['produto']->id}");
        $this->itemNaOnda($onda, "c-{$p['produto']->id}-2");
        $this->itemNaOnda($onda, "o-{$kit->id}");
        $this->itemNaOnda($onda, "s-{$q['produto']->id}");
        TrilhaMapeamento::create(['company_id' => $this->empresa->id, 'produto_id' => $p['produto']->id, 'concluido_em' => now()]);

        // 1º passo: a prévia, sem tocar em nada.
        $previa = $this->actingAs($this->dev)->postJson($this->url($p['produto'], '/exclusao/previa'))->assertOk()->json();
        $this->assertSame([['id' => (int) $p['produto']->id, 'nome' => 'Vaso Alfa', 'codigo' => 'VA', 'variacoes' => 2, 'em_uso' => false]], $previa['produtos']);
        $this->assertSame([[(int) $combo->id, 'VA-1-CB2', 'combo'], [(int) $kit->id, 'KT-VA-VB', 'kit']],
            array_map(fn ($m) => [$m['id'], $m['sku'], $m['fase']], $previa['montadas']));
        $this->assertSame(['produtos' => 1, 'variacoes' => 2, 'montadas' => 2, 'em_uso' => 0], $previa['totais']);
        $this->assertSame(4, TrilhaOndaItem::query()->count());

        // Sem confirmar as montadas (ou só uma delas): 422 e nada sai.
        $this->excluirProduto($p['produto'], [])->assertUnprocessable()->assertJsonValidationErrors(['montadas']);
        $this->excluirProduto($p['produto'], [$combo->id])->assertUnprocessable()->assertJsonValidationErrors(['montadas']);
        $this->excluirProduto($p['produto'], ['x'])->assertUnprocessable()->assertJsonValidationErrors(['montadas.0']);
        $this->assertNotNull($p['produto']->fresh());
        $this->assertSame(4, TrilhaOndaItem::query()->count());

        // 2º passo, com as montadas da prévia.
        $r = $this->excluirProduto($p['produto'], array_column($previa['montadas'], 'id'))->assertOk()->json();

        $this->assertSame(['produtos' => 1, 'variacoes' => 2, 'montadas' => 2, 'ids' => [(int) $p['produto']->id], 'nao_encontrados' => 0,
            'mensagem' => 'Produto excluído. 2 ofertas montadas saíram junto.'], $r);
        $this->assertNull($p['produto']->fresh());
        $this->assertSame(0, EstruturaProdutoVariacao::query()->where('produto_id', $p['produto']->id)->count());
        $this->assertNull(EstruturaOferta::query()->find($combo->id));
        $this->assertNull(EstruturaOferta::query()->find($kit->id));
        // A trilha: o combo e o kit saíram da onda, a Simples e o mapeamento também (cascata); a onda e o Beta ficam.
        $this->assertSame([["s-{$q['produto']->id}"]], TrilhaOndaItem::query()->get()->map(fn ($i) => [$i->chave()])->all());
        $this->assertSame(0, TrilhaMapeamento::query()->count());
        $this->assertNotNull($onda->fresh());
        $this->assertNotNull(EstruturaOferta::query()->find($q['simples'][0]->id));
        $this->assertSame([CadastroNaTrilha::PASSO_PRODUTO_EXCLUIDO], $this->passos());
        Queue::assertNothingPushed();

        // De novo: o produto não existe mais.
        $this->excluirProduto($p['produto'], [])->assertNotFound();
    }

    public function test_excluir_o_produto_sem_montadas_e_um_passo_de_confirmacao_vazia(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA');

        $previa = $this->actingAs($this->dev)->postJson($this->url($p['produto'], '/exclusao/previa'))->assertOk()->json();
        $this->assertSame([], $previa['montadas']);

        $this->excluirProduto($p['produto'], [])->assertOk()->assertJsonPath('mensagem', 'Produto excluído.');
        $this->assertSame(0, EstruturaProduto::query()->count());
        $this->assertSame(0, EstruturaProdutoVariacaoImagem::query()->count());
    }

    // ═══ Produto já mapeado que ganha cor ══════════════════════════════════

    public function test_o_produto_mapeado_que_ganha_cor_avisa_mapear_de_novo_e_a_marca_fica(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA', ['Azul']);
        $marca = TrilhaMapeamento::create(['company_id' => $this->empresa->id, 'produto_id' => $p['produto']->id, 'concluido_em' => now()->subDay()]);
        $v = $p['variacoes'][0];

        // Mudar o custo não pede mapear de novo.
        $this->linhas($p['produto'], [['id' => $v->id, 'codigo' => 'VA', 'nome' => 'Vaso Alfa', 'custo' => '120,00']])
            ->assertOk()->assertJsonPath('mapear_de_novo', false);

        $r = $this->linhas($p['produto'], [['chave' => 'n1', 'codigo' => 'VA-2', 'nome' => 'Vaso Alfa', 'eixo' => 'cor', 'valor' => 'Verde']])
            ->assertOk()->json();

        $this->assertTrue($r['mapear_de_novo']);
        $this->assertSame('2026-11-04T10:00:00-03:00', $r['situacao']['mapeado_em']);
        $this->assertStringContainsString('mapeie de novo', $r['mensagem']);
        $agora = TrilhaMapeamento::query()->sole();
        $this->assertSame([(int) $marca->id, $marca->concluido_em->format('Y-m-d H:i:s')], [(int) $agora->id, $agora->concluido_em->format('Y-m-d H:i:s')]);
    }

    // ═══ apoio ═══

    /** Um produto criado pela trilha (nome + SKU): uma variação sem eixo. @return array{0: EstruturaProduto, 1: EstruturaProdutoVariacao} */
    private function produtoNovo(string $nome, string $codigo): array
    {
        $id = $this->actingAs($this->dev)->postJson("/trilha/{$this->empresa->id}/produtos", ['nome' => $nome, 'codigo' => $codigo])
            ->assertCreated()->json('produto.id');
        $produto = EstruturaProduto::query()->findOrFail($id);

        return [$produto, $produto->variacoes()->sole()];
    }

    /** O produto do `produtoCompleto` com cores; "Azul/P" = cor e tamanho. @param list<string> $cores */
    private function produtoComCores(array $cores): array
    {
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA', array_map(fn (string $c) => explode('/', $c)[0], $cores));
        foreach ($cores as $i => $c) {
            if (str_contains($c, '/')) {
                $p['variacoes'][$i]->update(['eixo_2' => 'tamanho', 'valor_2' => explode('/', $c)[1]]);
            }
        }

        return $p;
    }

    private function url(EstruturaProduto $produto, string $fim): string
    {
        return "/trilha/{$this->empresa->id}/produtos/{$produto->id}{$fim}";
    }

    private function linhas(EstruturaProduto $produto, array $linhas): TestResponse
    {
        return $this->semPortal($this->actingAs($this->dev)->postJson($this->url($produto, '/linhas'), ['linhas' => $linhas]));
    }

    private function excluirVariacao(EstruturaProduto $produto, EstruturaProdutoVariacao $v): TestResponse
    {
        return $this->semPortal($this->actingAs($this->dev)->deleteJson($this->url($produto, "/variacoes/{$v->id}")));
    }

    private function excluirProduto(EstruturaProduto $produto, array $montadas): TestResponse
    {
        return $this->semPortal($this->actingAs($this->dev)->deleteJson($this->url($produto, ''), ['montadas' => $montadas]));
    }

    private function enviar(EstruturaProduto $produto, EstruturaProdutoVariacao $v, array $arquivos): TestResponse
    {
        return $this->actingAs($this->dev)->post($this->url($produto, "/variacoes/{$v->id}/fotos"), ['imagens' => $arquivos], ['Accept' => 'application/json']);
    }

    private function jpg(string $nome): UploadedFile
    {
        return UploadedFile::fake()->image($nome, 800, 600);
    }

    /** Nenhuma resposta das rotas da trilha leva endereço do Portal. */
    private function semPortal(TestResponse $resposta): TestResponse
    {
        $this->assertStringNotContainsString('/portal/', (string) $resposta->getContent());
        $this->assertStringNotContainsString('\/portal\/', (string) $resposta->getContent());

        return $resposta;
    }

    /** O produto como está no banco: as variações (com eixo e valores), o custo e as caixas. */
    private function retratoDoBanco(EstruturaProduto $produto): array
    {
        return EstruturaProdutoVariacao::query()->where('produto_id', $produto->id)->with('volumes')->orderBy('id')->get()
            ->map(fn ($v) => [$v->id, $v->codigo, $v->eixo, $v->valor, $v->eixo_2, $v->valor_2, $v->custo, $v->volumes->count()])->all();
    }

    private function eventosDaTrilha()
    {
        return Activity::query()->where('log_name', 'portal')->orderBy('id')->get()
            ->filter(fn (Activity $a) => $a->getExtraProperty('evento') === 'trilha_cadastro_salvo')->values();
    }

    /** Os passos dos eventos `trilha_cadastro_salvo`, em ordem. @return list<string> */
    private function passos(): array
    {
        return $this->eventosDaTrilha()->map(fn (Activity $a) => (string) $a->getExtraProperty('passo'))->all();
    }
}
