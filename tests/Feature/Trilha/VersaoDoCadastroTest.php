<?php

namespace Tests\Feature\Trilha;

use App\Models\Company;
use App\Models\EstruturaAmbiente;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaFamilia;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoAtributo;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Models\EstruturaProdutoVolume;
use App\Models\TrilhaOnda;
use App\Models\User;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use App\Services\Portal\Estrutura\Produtos\VariacaoImagensService;
use App\Services\Trilha\CadastroNaTrilha;
use App\Services\Trilha\VersaoDoCadastro;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * A rede contra a ABA VELHA no cadastro da trilha, fatia F6 (3ª entrega, 03/11/2026; desenho §6.3 "Versão", D19):
 *
 * - o retrato traz a `versao` do cadastro ({@see VersaoDoCadastro}: sha1 do JSON canônico de produto, ambientes,
 *   variações, caixas e ficha);
 * - as escritas que mudam conteúdo (rotas 3, 4, 14 e 15) com a versão VELHA: 409 `{versao_atual, message}` SEM
 *   `travado` (a tela distingue a trava, que vira só leitura, da versão, que pede "Recarregar") e NADA muda — nem
 *   evento, nem fila, nem consulta ao ML;
 * - em dia: grava e devolve a versão nova, que vale para a escrita seguinte (o "Salvar" da gaveta encadeia linhas →
 *   ficha);
 * - o que fica FORA da versão: o estoque (o Bling grava por fora) e as fotos (enviar só acrescenta; excluir age por id);
 * - a categoria trocada pelo Portal depois que a gaveta abriu → 409;
 * - sem `versao` no corpo, tudo como antes (compatível);
 * - a trava vem antes da versão; quatro consultas fixas.
 *
 * O ML é de mentira por URL (as respostas reais da sondagem de 01/10/2026, MLB193945 Cadeiras para Escritório), como no
 * {@see FichaNaTrilhaTest}.
 */
class VersaoDoCadastroTest extends TestCase
{
    use MontaTrilha;
    use RefreshDatabase;

    private const CADEIRA = 'MLB193945';

    /** O pai real da cadeira (não é folha): só para a linha da aba velha levar uma troca de categoria. */
    private const PAI = 'MLB436389';

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
        Cache::put('ml_app_token_coleta', 'app-token-teste', 3600);
        config(['trilha.empresas' => [], 'trilha.trabalho.empresas' => [], 'publicador.preparo_ia.ativo' => true]);
        $this->empresa = $this->empresaDaTrilha();
        $this->dev = User::factory()->create(['role' => 'admin', 'active' => true, 'name' => 'Rafael']);
        $this->dev->forceFill(['is_dev' => true])->save();

        Http::fake(fn (Request $r) => $this->responder($r));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══ A aba velha: 409 sem `travado`, e nada muda ═══════════════════════

    /** @return array<string, array{0: string}> */
    public static function escritasConferidas(): array
    {
        return [
            'linhas (rota 3)'              => ['linhas'],
            'ficha técnica (rota 4)'       => ['ficha'],
            'excluir a cor (rota 14)'      => ['variacao'],
            'prévia da exclusão (rota 15)' => ['previa'],
            'excluir o produto (rota 15)'  => ['produto'],
        ];
    }

    #[DataProvider('escritasConferidas')]
    public function test_a_versao_velha_e_409_sem_travado_e_nada_muda(string $rota): void
    {
        $p = $this->cadeira();
        $velha = $this->abrir($p['produto'])['versao'];
        // A outra aba (ou outra pessoa) salva o título depois que esta abriu.
        $atual = $this->linhas($p, ['titulo' => 'Cadeira De Escritório Alfa'])->assertOk()->json('versao');
        $this->assertNotSame($velha, $atual);

        $antes = $this->doBanco($p['produto']);
        $eventos = count($this->passos());
        $naFila = $this->naFila();
        $noMl = count(Http::recorded());

        $r = $this->escrever($rota, $p, $velha)->assertStatus(409)->json();

        $this->assertSame(['versao_atual', 'message'], array_keys($r), 'sem `travado`: a tela não vira só leitura');
        $this->assertSame($atual, $r['versao_atual']);
        $this->assertSame(CadastroNaTrilha::MSG_VERSAO, $r['message']);
        $this->assertSame($antes, $this->doBanco($p['produto']), 'nada muda');
        $this->assertCount($eventos, $this->passos(), 'nenhum evento da trilha');
        $this->assertSame($naFila, $this->naFila(), 'nada vai para a fila');
        $this->assertCount($noMl, Http::recorded(), 'nem a categoria nova é consultada no ML');
    }

    // ═══ Em dia: grava e devolve a nova ════════════════════════════════════

    public function test_em_dia_grava_e_devolve_a_versao_nova_que_vale_para_a_escrita_seguinte(): void
    {
        $p = $this->cadeira();
        $v0 = $this->abrir($p['produto'])['versao'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $v0);

        // O "Salvar" da gaveta: as linhas com a versão do retrato e, com a que elas devolveram, a ficha.
        $r1 = $this->linhas($p, ['custo' => '555,00'], $v0)->assertOk()->json();
        $this->assertSame(555.0, $p['variacoes'][0]->fresh()->custo);
        $this->assertNotSame($v0, $r1['versao']);
        $this->assertSame(VersaoDoCadastro::de($this->empresa, $p['produto']), $r1['versao']);

        $r2 = $this->ficha($p, [['id' => 'BRAND', 'valor' => 'ECF']], $r1['versao'])->assertOk()->json();
        $this->assertSame(['BRAND' => 'ECF'], EstruturaProdutoAtributo::query()->where('produto_id', $p['produto']->id)->pluck('valor', 'atributo_id')->all());
        $this->assertNotSame($r1['versao'], $r2['versao']);

        // A do começo já não vale — nem para quem acabou de gravar com ela.
        $this->linhas($p, ['custo' => '1,00'], $v0)->assertStatus(409);
        $this->assertSame(555.0, $p['variacoes'][0]->fresh()->custo);

        // Remover a cor e excluir o produto, cada pedido com a versão do anterior.
        $r3 = $this->excluirVariacao($p['produto'], $p['variacoes'][1], $r2['versao'])->assertOk()->json();
        $this->assertSame(1, $p['produto']->variacoes()->count());
        $this->assertNotSame($r2['versao'], $r3['versao']);
        $this->previa($p['produto'], $r3['versao'])->assertOk();
        $this->excluirProduto($p['produto'], [], $r3['versao'])->assertOk();
        $this->assertNull(EstruturaProduto::query()->find($p['produto']->id));
    }

    // ═══ O que fica fora da versão ═════════════════════════════════════════

    public function test_o_estoque_que_o_bling_grava_nao_muda_a_versao(): void
    {
        $p = $this->cadeira();
        $v0 = $this->abrir($p['produto'])['versao'];

        // A varredura periódica do Bling: só a coluna `estoque`, pelo caminho dedicado.
        app(ProdutoCadastroService::class)->gravarEstoqueDoBling($this->empresa, [$p['variacoes'][0]->id => 42], AtorDoPortal::sistema());
        $this->assertSame(42, $p['variacoes'][0]->fresh()->estoque);

        $r = $this->abrir($p['produto']);
        $this->assertSame($v0, $r['versao']);
        $this->assertSame(42, $r['variacoes'][0]['estoque']);
        // A gaveta aberta ANTES da leitura do Bling salva sem 409.
        $this->linhas($p, ['custo' => '321,00'], $v0)->assertOk();
        $this->assertSame(321.0, $p['variacoes'][0]->fresh()->custo);
    }

    public function test_enviar_e_excluir_foto_nao_mudam_a_versao(): void
    {
        $p = $this->cadeira();
        $v = $p['variacoes'][0];
        $v0 = $this->abrir($p['produto'])['versao'];

        $enviada = $this->actingAs($this->dev)->post($this->url($p['produto'], "/variacoes/{$v->id}/fotos"),
            ['imagens' => [UploadedFile::fake()->image('nova.jpg', 800, 600)]], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertCount(4, $enviada['variacoes'][0]['fotos']);
        $this->assertSame($v0, $enviada['versao'], 'enviar só acrescenta: nada a proteger');

        $nova = (int) collect($enviada['variacoes'][0]['fotos'])->last()['id'];
        $excluida = $this->actingAs($this->dev)->deleteJson($this->url($p['produto'], "/variacoes/{$v->id}/fotos/{$nova}"))->assertOk()->json();
        $this->assertCount(3, $excluida['variacoes'][0]['fotos']);
        $this->assertSame($v0, $excluida['versao'], 'excluir age pelo id da foto');

        $this->linhas($p, ['custo' => '1,00'], $v0)->assertOk();
    }

    /**
     * O JSON canônico, campo a campo: o que a gaveta mostra e grava muda a versão; o estoque, as fotos, o rótulo da ficha
     * e o `updated_at` não. E ela é lida do BANCO: o model de antes da gravação dá a versão de agora.
     */
    public function test_o_que_entra_muda_a_versao_e_o_que_fica_fora_nao(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA', ['Azul', 'Verde']);
        [$azul, $verde] = $p['variacoes'];
        $produto = $p['produto'];
        $this->atributo($produto, 'BRAND', 'ECF');
        $versao = fn () => VersaoDoCadastro::de($this->empresa, $produto);

        $muda = [
            'nome'          => fn () => EstruturaProduto::query()->whereKey($produto->id)->update(['nome' => 'Vaso Beta']),
            'título'        => fn () => EstruturaProduto::query()->whereKey($produto->id)->update(['titulo' => 'Vaso Beta Grande']),
            'descrição'     => fn () => EstruturaProduto::query()->whereKey($produto->id)->update(['descricao' => 'Outra descrição.']),
            'família'       => fn () => EstruturaProduto::query()->whereKey($produto->id)->update(['familia_id' => EstruturaFamilia::create(['company_id' => $this->empresa->id, 'nome' => 'Vasos'])->id]),
            'categoria'     => fn () => EstruturaProduto::query()->whereKey($produto->id)->update(['categoria_ml_id' => self::CADEIRA]),
            'ambiente'      => fn () => $produto->ambientes()->attach(EstruturaAmbiente::create(['company_id' => $this->empresa->id, 'nome' => 'Varanda'])->id),
            'SKU'           => fn () => $azul->update(['codigo' => 'VA-AZ']),
            'cor'           => fn () => $azul->update(['valor' => 'Azul-marinho']),
            '2º eixo'       => fn () => $verde->update(['eixo_2' => 'tamanho', 'valor_2' => 'G']),
            'ordem'         => fn () => $verde->update(['ordem' => 9]),
            'custo'         => fn () => $azul->update(['custo' => 101.5]),
            'caixa'         => fn () => EstruturaProdutoVolume::query()->where('variacao_id', $azul->id)->update(['peso' => 5.25]),
            'caixa nova'    => fn () => EstruturaProdutoVolume::create(['variacao_id' => $verde->id, 'ordem' => 2, 'comprimento' => 10, 'largura' => 10, 'altura' => 10, 'peso' => 1]),
            'valor da ficha' => fn () => EstruturaProdutoAtributo::query()->where('produto_id', $produto->id)->update(['valor' => 'Outra']),
            'campo na ficha' => fn () => $this->atributo($produto, 'MODEL', 'X1'),
        ];
        foreach ($muda as $oque => $mudar) {
            $antes = $versao();
            $mudar();
            $this->assertNotSame($antes, $versao(), "{$oque} muda a versão");
        }

        $naoMuda = [
            'estoque'         => fn () => $azul->update(['estoque' => 9]),
            'foto'            => fn () => EstruturaProdutoVariacaoImagem::create(['company_id' => $this->empresa->id, 'produto_id' => $produto->id,
                'variacao_id' => $azul->id, 'caminho' => 'estrutura/'.Str::random(8).'.jpg', 'nome_original' => 'x.jpg', 'mime' => 'image/jpeg',
                'tamanho' => 1000, 'ordem' => 7]),
            'rótulo da ficha' => fn () => EstruturaProdutoAtributo::query()->where('produto_id', $produto->id)->update(['atributo_nome' => 'Marca da loja']),
            'updated_at'      => function () use ($produto) {
                Carbon::setTestNow(Carbon::now()->addHour());
                EstruturaProduto::query()->whereKey($produto->id)->update(['updated_at' => now()]);
            },
            'o mesmo custo'   => fn () => $verde->update(['custo' => '110.00']),
        ];
        foreach ($naoMuda as $oque => $mudar) {
            $antes = $versao();
            $mudar();
            $this->assertSame($antes, $versao(), "{$oque} NÃO muda a versão");
        }

        // Determinística, e lida do banco (o `$produto` aqui é de antes de tudo isso).
        $this->assertSame($versao(), VersaoDoCadastro::de($this->empresa, $produto->fresh()));
    }

    // ═══ O Portal mudou por baixo ══════════════════════════════════════════

    public function test_a_categoria_trocada_pelo_portal_depois_que_a_gaveta_abriu_e_409(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Cadeira Alfa', 'CA', ['Azul', 'Verde'], ['categoria_ml_id' => null, 'categoria_ml_nome' => null]);
        $v = $p['variacoes'][0];
        $v0 = $this->abrir($p['produto'])['versao'];

        // O cliente escolhe a categoria no Portal (o MESMO serviço que o controller de lá chama).
        $r = app(ProdutoCadastroService::class)->gravarLinhas($this->empresa,
            [['id' => $v->id, 'produto_id' => $p['produto']->id, 'codigo' => $v->codigo, 'nome' => 'Cadeira Alfa', 'categoria_ml_id' => self::CADEIRA]],
            AtorDoPortal::daEquipe($this->dev), ProdutoCadastroService::MODO_GRADE);
        $this->assertSame([], $r['erros']);
        $this->assertSame(self::CADEIRA, $p['produto']->fresh()->categoria_ml_id);

        // A gaveta da trilha, aberta antes, salva o custo e a ficha: 409 nas duas, e o que o Portal gravou fica.
        $this->linhas($p, ['custo' => '999,00', 'titulo' => 'Cadeira Da Trilha'], $v0)->assertStatus(409)->assertJsonMissingPath('travado');
        $this->ficha($p, [['id' => 'BRAND', 'valor' => 'ECF']], $v0)->assertStatus(409)->assertJsonMissingPath('travado');
        $this->assertSame([self::CADEIRA, 100.0], [$p['produto']->fresh()->categoria_ml_id, $v->fresh()->custo]);
        $this->assertSame(0, EstruturaProdutoAtributo::query()->where('produto_id', $p['produto']->id)->count());

        // Recarregada, a gaveta vê a categoria do Portal e salva.
        $recarregada = $this->abrir($p['produto']);
        $this->assertSame(self::CADEIRA, $recarregada['produto']['categoria']['id']);
        $this->linhas($p, ['custo' => '999,00'], $recarregada['versao'])->assertOk();
    }

    // ═══ Compatível, e a ordem da guarda ═══════════════════════════════════

    public function test_sem_versao_no_corpo_segue_como_antes(): void
    {
        $p = $this->cadeira();
        $this->abrir($p['produto']);
        $this->linhas($p, ['titulo' => 'Cadeira De Escritório Alfa'])->assertOk();

        // A versão mudou, mas quem não manda nenhuma (nula ou vazia) não é conferido.
        $this->linhas($p, ['custo' => '777,00'])->assertOk();
        $this->linhas($p, ['custo' => '778,00'], '')->assertOk();
        $this->ficha($p, [['id' => 'BRAND', 'valor' => 'ECF']])->assertOk();
        $this->assertSame(778.0, $p['variacoes'][0]->fresh()->custo);
        $this->excluirVariacao($p['produto'], $p['variacoes'][1])->assertOk();
        $this->previa($p['produto'])->assertOk();
        $this->excluirProduto($p['produto'], [])->assertOk();
        $this->assertNull(EstruturaProduto::query()->find($p['produto']->id));
    }

    public function test_a_trava_vem_antes_da_versao_e_a_versao_que_nao_e_texto_e_422(): void
    {
        $p = $this->cadeira();
        $velha = $this->abrir($p['produto'])['versao'];
        $this->linhas($p, ['titulo' => 'Cadeira De Escritório Alfa'])->assertOk();

        $this->actingAs($this->dev)->postJson($this->url($p['produto'], '/linhas'), ['linhas' => [$this->linha($p, ['custo' => '1,00'])], 'versao' => ['x']])
            ->assertUnprocessable()->assertJsonValidationErrors('versao');

        // O produto andou (a cor no ar): a trava responde, com a versão velha e tudo — ela diz mais.
        EstruturaAnuncio::create(['oferta_id' => $p['simples'][1]->id, 'tipo' => EstruturaAnuncio::TIPO_CLASSICO,
            'status' => EstruturaAnuncio::STATUS_ATIVO, 'codigo_mlb' => 'MLB1234567890', 'titulo' => 'Cadeira']);
        $this->linhas($p, ['custo' => '1,00'], $velha)->assertStatus(409)->assertJsonPath('travado', true);
        $this->excluirVariacao($p['produto'], $p['variacoes'][1], $velha)->assertStatus(409)->assertJsonPath('travado', true);
    }

    public function test_a_versao_le_quatro_consultas_fixas(): void
    {
        $um = $this->produtoCompleto($this->empresa, 'Vaso Um', 'V1');
        $quatro = $this->produtoCompleto($this->empresa, 'Vaso Quatro', 'V4', ['Azul', 'Verde', 'Rosa', 'Cinza']);
        foreach ([$um, $quatro] as $p) {
            $this->atributo($p['produto'], 'BRAND', 'ECF');
            $this->atributo($p['produto'], 'MODEL', 'X1');
        }

        $contar = function (array $p): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            VersaoDoCadastro::de($this->empresa, $p['produto']);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->assertSame(4, $contar($um));
        $this->assertSame(4, $contar($quatro));
    }

    // ═══ apoio ═══

    /** A cadeira (categoria real MLB193945) com duas cores: completa nas fotos, com a ficha ainda vazia. */
    private function cadeira(): array
    {
        return $this->produtoCompleto($this->empresa, 'Cadeira Alfa', 'CA', ['Azul', 'Verde'], [
            'categoria_ml_id' => self::CADEIRA, 'categoria_ml_nome' => 'Cadeiras para Escritório',
            'categoria_ml_caminho' => 'Casa, Móveis e Decoração > Móveis para Casa > Cadeiras, Sofás e Banquetas > Cadeiras para Escritório',
        ]);
    }

    /** Cada escrita conferida com a versão `$versao` (a da aba velha, no teste do 409). */
    private function escrever(string $rota, array $p, string $versao): TestResponse
    {
        return match ($rota) {
            // A linha da aba velha leva também uma troca de categoria: a versão barra ANTES de consultar o ML.
            'linhas'   => $this->linhas($p, ['custo' => '999,00', 'categoria_ml_id' => self::PAI], $versao),
            'ficha'    => $this->ficha($p, [['id' => 'BRAND', 'valor' => 'ECF']], $versao),
            'variacao' => $this->excluirVariacao($p['produto'], $p['variacoes'][1], $versao),
            'previa'   => $this->previa($p['produto'], $versao),
            'produto'  => $this->excluirProduto($p['produto'], [], $versao),
        };
    }

    private function url(EstruturaProduto $produto, string $fim): string
    {
        return "/trilha/{$this->empresa->id}/produtos/{$produto->id}{$fim}";
    }

    private function abrir(EstruturaProduto $produto): array
    {
        return $this->semPortal($this->actingAs($this->dev)->getJson($this->url($produto, '/cadastro')))->assertOk()->json();
    }

    /** A linha da 1ª variação, com `codigo` e `nome` (o normalizador exige) e os campos pedidos. */
    private function linha(array $p, array $campos): array
    {
        $v = $p['variacoes'][0];

        return array_merge(['id' => $v->id, 'chave' => "v{$v->id}", 'codigo' => $v->codigo, 'nome' => $p['produto']->nome], $campos);
    }

    private function linhas(array $p, array $campos, ?string $versao = null): TestResponse
    {
        $corpo = ['linhas' => [$this->linha($p, $campos)]] + ($versao !== null ? ['versao' => $versao] : []);

        return $this->semPortal($this->actingAs($this->dev)->postJson($this->url($p['produto'], '/linhas'), $corpo));
    }

    private function ficha(array $p, array $atributos, ?string $versao = null): TestResponse
    {
        $corpo = ['atributos' => $atributos] + ($versao !== null ? ['versao' => $versao] : []);

        return $this->semPortal($this->actingAs($this->dev)->putJson($this->url($p['produto'], '/ficha-tecnica'), $corpo));
    }

    private function excluirVariacao(EstruturaProduto $produto, EstruturaProdutoVariacao $v, ?string $versao = null): TestResponse
    {
        return $this->semPortal($this->actingAs($this->dev)->deleteJson($this->url($produto, "/variacoes/{$v->id}"),
            $versao !== null ? ['versao' => $versao] : []));
    }

    private function previa(EstruturaProduto $produto, ?string $versao = null): TestResponse
    {
        return $this->semPortal($this->actingAs($this->dev)->postJson($this->url($produto, '/exclusao/previa'),
            $versao !== null ? ['versao' => $versao] : []));
    }

    private function excluirProduto(EstruturaProduto $produto, array $montadas, ?string $versao = null): TestResponse
    {
        return $this->semPortal($this->actingAs($this->dev)->deleteJson($this->url($produto, ''),
            ['montadas' => $montadas] + ($versao !== null ? ['versao' => $versao] : [])));
    }

    private function semPortal(TestResponse $resposta): TestResponse
    {
        $this->assertStringNotContainsString('/portal/', (string) $resposta->getContent());
        $this->assertStringNotContainsString('\/portal\/', (string) $resposta->getContent());

        return $resposta;
    }

    private function atributo(EstruturaProduto $produto, string $id, string $valor): void
    {
        EstruturaProdutoAtributo::create(['company_id' => $this->empresa->id, 'produto_id' => $produto->id,
            'atributo_id' => $id, 'atributo_nome' => $id, 'valor' => $valor]);
    }

    /** O produto inteiro como está no banco — o estoque, as fotos, as ofertas e o anúncio também. */
    private function doBanco(EstruturaProduto $produto): array
    {
        $variacoes = EstruturaProdutoVariacao::query()->where('produto_id', $produto->id)->orderBy('id');

        return [
            'produto'   => EstruturaProduto::query()->whereKey($produto->id)
                ->first(['codigo', 'nome', 'titulo', 'familia_id', 'categoria_ml_id', 'categoria_ml_nome', 'categoria_ml_caminho', 'descricao'])?->getAttributes(),
            'ambientes' => DB::table('estrutura_produto_ambiente')->where('produto_id', $produto->id)->orderBy('ambiente_id')->pluck('ambiente_id')->all(),
            'variacoes' => (clone $variacoes)->get(['id', 'codigo', 'ordem', 'eixo', 'valor', 'eixo_2', 'valor_2', 'custo', 'estoque'])->map->getAttributes()->all(),
            'caixas'    => EstruturaProdutoVolume::query()->whereIn('variacao_id', (clone $variacoes)->select('id'))->orderBy('id')->get()->map->getAttributes()->all(),
            'ficha'     => EstruturaProdutoAtributo::query()->where('produto_id', $produto->id)->orderBy('atributo_id')->get(['atributo_id', 'valor'])->map->getAttributes()->all(),
            'fotos'     => EstruturaProdutoVariacaoImagem::query()->where('produto_id', $produto->id)->count(),
            'ofertas'   => EstruturaOferta::query()->where('company_id', $this->empresa->id)->orderBy('id')->pluck('sku')->all(),
        ];
    }

    /** Os passos dos eventos `trilha_cadastro_salvo`, em ordem. @return list<string> */
    private function passos(): array
    {
        return Activity::query()->where('log_name', 'portal')->orderBy('id')->get()
            ->filter(fn (Activity $a) => $a->getExtraProperty('evento') === 'trilha_cadastro_salvo')
            ->map(fn (Activity $a) => (string) $a->getExtraProperty('passo'))->values()->all();
    }

    /** Quantos Jobs a fila recebeu (de qualquer classe). */
    private function naFila(): int
    {
        return collect(Queue::pushedJobs())->flatten(1)->count();
    }

    /** O ML de mentira, por URL: a cadeira e o pai dela; o resto é 404. */
    private function responder(Request $r)
    {
        $caminho = (string) parse_url($r->url(), PHP_URL_PATH);
        if (! preg_match('#/categories/(MLB\d+)(/[a-z_/]+)?$#', $caminho, $m)) {
            return Http::response([], 404);
        }
        [$id, $sub] = [$m[1], $m[2] ?? ''];
        if ($id === self::PAI && $sub === '') {
            return Http::response($this->fixture('pai_'.self::PAI.'.json'));
        }
        if ($id !== self::CADEIRA) {
            return Http::response(['message' => 'not_found'], 404);
        }

        return Http::response(match ($sub) {
            ''                       => $this->fixture('categoria.json'),
            '/attributes'            => $this->fixture('atributos.json'),
            '/technical_specs/input' => $this->fixture('technical_specs_input.json'),
            '/sale_terms'            => $this->fixture('sale_terms.json'),
            default                  => ['message' => 'not_found'],
        });
    }

    private function fixture(string $arquivo): array
    {
        return json_decode((string) file_get_contents(base_path('tests/fixtures-ml/sondagem/publico/categorias/'.self::CADEIRA."/{$arquivo}")), true)['resposta'];
    }
}
