<?php

namespace Tests\Feature\Publicador\Lote;

use App\Models\EstruturaAnuncio;
use App\Models\EstruturaOferta;
use App\Models\EstruturaPrecificacao;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubValidacao;
use App\Services\Portal\Estrutura\PrecificacaoEstrutura;
use App\Services\Publicador\DadosEfetivosService;
use App\Services\Publicador\Fila\EfetivosEmLote;
use App\Services\Publicador\Fila\ResumoRapidoService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\Payload\Alvo;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\RegeneradorVariantes;
use App\Support\Publicador\Variacao\ValorEixo;
use App\Support\Publicador\Variacao\Variante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Publicador\Concerns\CenarioPlanejamentoDaFase;
use Tests\TestCase;

/**
 * A VISÃO RÁPIDA da publicação em lote (10/10/2026): títulos (e se são iguais), preço por tipo com a origem,
 * custo, frete, margem estimada, estoque, variações, anúncios, fotos e a conferência — SEM o Mercado Livre e com
 * um número FIXO de consultas (3 ou 12 produtos custam o mesmo). E o `EfetivosEmLote` dá, produto a produto, o
 * MESMO resultado do `DadosEfetivosService::daProduto` (simples, agrupado, kit da Fase N, sem oferta).
 *
 * Cenário: a cadeira de jantar de três cores do relato de 09/10 (`CenarioPlanejamentoDaFase`), conta da Polos
 * com o token na Company.
 */
class VisaoRapidaDoLoteTest extends TestCase
{
    use CenarioPlanejamentoDaFase;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        $this->montarPlanejamento();
        config(['publicador.contas_liberadas' => ['companies' => [$this->empresaP->id], 'mlb_empresas' => []]]);
        // Fretes digitados no Portal: Preto 20 (só no Clássico — o Premium usa o do outro tipo), Azul 25 nos dois.
        EstruturaPrecificacao::create(['oferta_id' => $this->simplesP['Preto']->id, 'frete_classico' => 20]);
        EstruturaPrecificacao::create(['oferta_id' => $this->simplesP['Azul']->id, 'frete_classico' => 25, 'frete_premium' => 25]);
        $this->variacoesP['Azul']->update(['custo' => 120]);
    }

    private function alvo(): array
    {
        return app(ProgramasPublicadorService::class)->resolver('empresa-'.$this->mlbP->id);
    }

    private function linhas(?array $so = null): array
    {
        return app(ResumoRapidoService::class)->linhas($this->alvo(), $so);
    }

    private function linhaDe(PubProduto $p, ?array $linhas = null): array
    {
        return collect($linhas ?? $this->linhas())->firstWhere('produto_id', $p->id) ?? $this->fail("o produto {$p->id} não está na visão rápida");
    }

    /** O preço anunciado da Precificação, com os padrões da empresa — a mesma conta do Portal. */
    private static function anunciado(float $custo, float $frete, string $tipo): float
    {
        $comissao = $tipo === 'classico' ? 11.5 : 16.5;

        return PrecificacaoEstrutura::preco($custo, $frete, $comissao, 19.0, 0.0, 0.0, 20.0)['anunciado'];
    }

    // ═══ Valores ═════════════════════════════════════════════════════════════

    public function test_o_grupo_mostra_titulos_preco_custo_frete_margem_estoque_e_conferencia(): void
    {
        $grupo = $this->grupoComRascunho(PubRascunho::DRAFT, 4);
        $r = PubRascunho::where('produto_id', $grupo->id)->first();
        $repo = new RascunhoRepository();
        // Clássico digitado; Premium vem do planejado na aba Anúncios do Portal.
        $repo->gravarAlvos($r, [new Alvo('gold_special', 'Cadeira de Jantar Estofada Preta'), new Alvo('gold_pro', null)]);
        EstruturaAnuncio::create(['oferta_id' => $this->simplesP['Preto']->id, 'tipo' => 'premium', 'titulo' => 'Cadeira Jantar Estofada Premium', 'status' => 'ativo']);
        // A cor Branco sem estoque; o Premium do Azul digitado.
        $s = $repo->snapshot($r->fresh());
        $repo->gravarVariacao($r->fresh(), $s->eixos, array_map(function (Variante $v) {
            $cor = $v->valores['COLOR']->valueName;
            $dados = [...$v->dados, 'estoque' => $cor === 'Branco' ? 0 : 4];
            if ($cor === 'Azul') {
                $dados['precos'] = ['gold_pro' => 299.9];
            }

            return $v->comDados($dados);
        }, $s->variantes));
        foreach ([1, 2] as $i) {
            $r->imagens()->create(['caminho' => "x/{$i}.jpg", 'sha256' => str_repeat((string) $i, 64), 'mime' => 'image/jpeg', 'bytes' => 1000, 'largura' => 1200, 'altura' => 1200, 'upload_status' => PubImagem::ENVIADA]);
        }
        $r = $r->fresh();
        PubValidacao::create(['rascunho_id' => $r->id, 'revisao' => $r->revisao, 'camada' => 'L3', 'resultado' => 'AVISOS', 'plano_hash' => str_repeat('b', 64),
            'issues' => [
                ['regra' => 'V-REM-01', 'severidade' => 'WARNING', 'mensagem' => 'Frete grátis obrigatório nesta faixa.', 'camada' => 'L3', 'alvo' => ['etapa' => 'E10', 'campo' => 'frete'], 'ml_causa' => ['cause_id' => 350]],
                ['regra' => 'V-REM-01', 'severidade' => 'WARNING', 'mensagem' => 'ruído', 'camada' => 'L3', 'alvo' => [], 'ml_causa' => ['cause_id' => 4053, 'code' => 'shipping.lost_me1_by_user', 'type' => 'warning']],
            ],
            'respostas_ml' => ['conta' => ['sellerId' => '1555596317'], 'grande' => str_repeat('x', 5000)]]);

        $l = $this->linhaDe($grupo);

        $this->assertSame('Cadeira de Jantar Estofada Preta', $l['titulos']['gold_special']['texto']);
        $this->assertSame('digitado', $l['titulos']['gold_special']['origem']);
        $this->assertSame('Cadeira Jantar Estofada Premium', $l['titulos']['gold_pro']['texto']);
        $this->assertSame('portal', $l['titulos']['gold_pro']['origem']);
        $this->assertFalse($l['titulos_iguais']);

        // Preço: cada cor pelo SKU (Preto 100+20, Azul 120+25, Branco 100 sem frete); o Premium do Azul é digitado.
        $preto = self::anunciado(100, 20, 'classico');
        $azul = self::anunciado(120, 25, 'classico');
        $branco = self::anunciado(100, 0, 'classico');
        $this->assertEqualsWithDelta(min($preto, $azul, $branco), $l['precos']['gold_special']['min'], 0.001);
        $this->assertEqualsWithDelta(max($preto, $azul, $branco), $l['precos']['gold_special']['max'], 0.001);
        $this->assertSame('portal', $l['precos']['gold_special']['origem']);
        $this->assertSame('misto', $l['precos']['gold_pro']['origem'], 'Azul digitado, as outras do Portal');
        $this->assertSame(299.9, $l['precos']['gold_pro']['max']);

        $this->assertSame(['min' => 100.0, 'max' => 120.0, 'origem' => 'produto'], $l['custo']);
        $this->assertSame(20.0, $l['frete']['gold_special']['min']);
        $this->assertSame(25.0, $l['frete']['gold_special']['max']);
        $this->assertSame('digitado', $l['frete']['gold_special']['origem']);
        $this->assertSame('misto', $l['frete']['gold_pro']['origem'], 'Preto usa o frete do outro tipo; Azul o digitado');

        // Margem = preço − custo − frete − (comissão% + imposto%) × preço, por cor; o Branco (sem frete) marca.
        $margemPreto = round($preto - 100 - 20 - (11.5 + 19) / 100 * $preto, 2);
        $margemAzul = round($azul - 120 - 25 - (11.5 + 19) / 100 * $azul, 2);
        $margemBranco = round($branco - 100 - 0 - (11.5 + 19) / 100 * $branco, 2);
        $this->assertEqualsWithDelta(min($margemPreto, $margemAzul, $margemBranco), $l['margem']['gold_special']['min'], 0.01);
        $this->assertEqualsWithDelta(max($margemPreto, $margemAzul, $margemBranco), $l['margem']['gold_special']['max'], 0.01);
        $this->assertTrue($l['margem']['gold_special']['sem_frete'], 'o Branco não tem frete no Portal');
        $this->assertSame(11.5, $l['margem']['gold_special']['comissao']);
        $this->assertSame(19.0, $l['margem']['gold_special']['imposto']);

        $this->assertSame(['total' => 8, 'sem_estoque' => 1], $l['estoque']);
        $this->assertSame(3, $l['variacoes']);
        $this->assertSame(6, $l['anuncios'], 'User Products: um anúncio por tipo e por cor');
        $this->assertSame(2, $l['fotos']);
        $this->assertSame(['Preto', 'Azul', 'Branco'], $l['rotulos_variacoes']);

        $this->assertSame('AVISOS', $l['conferencia']['resultado']);
        $this->assertTrue($l['conferencia']['vale']);
        $this->assertSame(1, $l['conferencia']['avisos'], 'o 4053 é ruído e sai');
        $this->assertSame('Frete grátis obrigatório nesta faixa.', $l['conferencia']['pendencias'][0]['mensagem']);
        $this->assertSame('E10', $l['conferencia']['pendencias'][0]['alvo']['etapa'], 'o "Corrigir" sabe a etapa do editor');
        $this->assertTrue($l['pronto']);
        $this->assertTrue($l['avisos_ml']);
        $this->assertStringNotContainsString(str_repeat('x', 100), json_encode($l), 'as respostas cruas do ML não vêm');
    }

    public function test_titulos_iguais_sem_oferta_sem_custo_e_os_motivos_de_nao_estar_pronto(): void
    {
        $solto = PubProduto::create(['company_id' => $this->empresaP->id, 'mlb_empresa_id' => $this->mlbP->id, 'sku' => 'SOLTO', 'nome' => 'Banqueta', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        $repo = new RascunhoRepository();
        $r = $repo->criar($solto, [new Alvo('gold_special', 'Banqueta Alta Cozinha'), new Alvo('gold_pro', 'banqueta  alta COZINHA')]);
        $unica = $repo->snapshot($r)->variantes[0];
        $repo->gravarVariacao($r->fresh(), [], [$unica->comDados(['estoque' => 2, 'precos' => ['gold_special' => 89.9, 'gold_pro' => 99.9]])]);
        $semRascunho = PubProduto::create(['company_id' => $this->empresaP->id, 'sku' => 'NOVO', 'nome' => 'Aparador', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        $publicado = $this->grupoComRascunho(PubRascunho::PUBLISHED);

        $linhas = $this->linhas();
        $l = $this->linhaDe($solto, $linhas);

        $this->assertTrue($l['titulos_iguais'], 'mesmas palavras, sem caixa nem espaço a mais: o ML barra');
        $this->assertSame(['min' => 89.9, 'max' => 89.9, 'origem' => 'digitado', 'sem_preco' => 0], $l['precos']['gold_special']);
        $this->assertNull($l['custo'], 'sem oferta do Portal não há custo');
        $this->assertNull($l['margem']['gold_special']);
        $this->assertFalse($l['pronto']);
        $this->assertSame('Confira no Mercado Livre antes de agendar.', $l['motivo']);
        $this->assertTrue($l['pode_conferir']);

        $n = $this->linhaDe($semRascunho, $linhas);
        $this->assertNull($n['rascunho_id']);
        $this->assertSame('O anúncio ainda não foi aberto: confira para criá-lo.', $n['motivo']);

        $this->assertNotContains($publicado->id, array_column($linhas, 'produto_id'), 'publicado não entra na visão rápida');

        // Conferência vencida (o produto mudou depois) e conferência local (conta fora da lista).
        PubValidacao::create(['rascunho_id' => $r->id, 'revisao' => $r->fresh()->revisao, 'camada' => 'L3', 'resultado' => 'OK', 'plano_hash' => str_repeat('c', 64), 'issues' => []]);
        $repo->tocar($r->fresh());
        $this->assertSame('O produto mudou depois da conferência: confira de novo.', $this->linhaDe($solto)['motivo']);
        PubValidacao::create(['rascunho_id' => $r->id, 'revisao' => $r->fresh()->revisao, 'camada' => 'L3', 'resultado' => 'OK', 'plano_hash' => str_repeat('c', 64), 'issues' => []]);
        $this->assertTrue($this->linhaDe($solto)['pronto']);
        config(['publicador.contas_liberadas.companies' => []]);
        $this->assertSame('A publicação ainda não foi liberada para esta conta.', $this->linhaDe($solto)['motivo']);
    }

    // ═══ Número fixo de consultas ════════════════════════════════════════════

    /** Um produto do Portal de duas cores, agrupado, com rascunho em rascunho e a Precificação das cores. */
    private function grupoExtra(int $n): void
    {
        $p = EstruturaProduto::create(['company_id' => $this->empresaP->id, 'codigo' => "MES{$n}", 'nome' => "Mesa {$n}"]);
        $ofertas = [];
        foreach (['Preto', 'Azul'] as $i => $cor) {
            $v = EstruturaProdutoVariacao::create(['produto_id' => $p->id, 'company_id' => $this->empresaP->id, 'ordem' => $i,
                'codigo' => "MES{$n}-{$i}", 'eixo' => 'cor', 'valor' => $cor, 'custo' => 50 + $n, 'estoque' => 3]);
            $ofertas[$cor] = EstruturaOferta::create(['company_id' => $this->empresaP->id, 'variacao_id' => $v->id, 'sku' => "MES{$n}-{$i}", 'fase' => 'simples', 'nome' => "Mesa {$n} {$cor}"]);
            EstruturaPrecificacao::create(['oferta_id' => $ofertas[$cor]->id, 'frete_classico' => 15]);
        }
        $pub = PubProduto::create(['company_id' => $this->empresaP->id, 'mlb_empresa_id' => $this->mlbP->id, 'oferta_id' => $ofertas['Preto']->id,
            'estrutura_produto_id' => $p->id, 'sku' => "MES{$n}", 'nome' => "Mesa {$n}", 'origem' => PubProduto::ORIGEM_PORTAL]);
        $this->rascunhoDeCores($pub, ['Preto' => "MES{$n}-0", 'Azul' => "MES{$n}-1"], 3);
        $r = PubRascunho::where('produto_id', $pub->id)->first();
        PubValidacao::create(['rascunho_id' => $r->id, 'revisao' => $r->revisao, 'camada' => 'L3', 'resultado' => 'OK', 'plano_hash' => str_repeat('d', 64), 'issues' => []]);
        $r->imagens()->create(['caminho' => "m/{$n}.jpg", 'sha256' => hash('sha256', (string) $n), 'mime' => 'image/jpeg', 'bytes' => 1, 'largura' => 1200, 'altura' => 1200, 'upload_status' => PubImagem::ENVIADA]);
    }

    private function consultas(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $linhas = $this->linhas();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertNotEmpty($linhas);

        return $n;
    }

    public function test_o_numero_de_consultas_e_o_mesmo_com_3_e_com_12_rascunhos(): void
    {
        // Um kit da Fase N com os Combos 2 do Planejamento no meio, para os kits contarem também.
        $this->aceitarCombos(2);
        $grupo = $this->grupoComRascunho(PubRascunho::DRAFT, 6);
        $this->kitAntigo($grupo, 2);
        for ($n = 1; $n <= 1; $n++) {
            $this->grupoExtra($n);
        }
        $com3 = $this->consultas();
        $this->assertSame(3, count($this->linhas()));

        for ($n = 2; $n <= 10; $n++) {
            $this->grupoExtra($n);
        }
        $com12 = $this->consultas();
        $this->assertSame(12, count($this->linhas()));

        $this->assertSame($com3, $com12, "3 rascunhos: {$com3} consultas; 12 rascunhos: {$com12}");
        $this->assertLessThanOrEqual(45, $com12, 'um número FIXO e pequeno, não um por produto');
    }

    // ═══ Paridade com o DadosEfetivosService ═════════════════════════════════

    public function test_efetivos_em_lote_iguais_aos_do_servico_de_origem_produto_a_produto(): void
    {
        $this->aceitarCombos(2);
        $grupo = $this->grupoComRascunho(PubRascunho::DRAFT, 6);
        $kit = $this->kitAntigo($grupo, 2);
        EstruturaAnuncio::create(['oferta_id' => $this->simplesP['Preto']->id, 'tipo' => 'classico', 'titulo' => 'Cadeira Planejada', 'status' => 'ativo']);
        EstruturaAnuncio::create(['oferta_id' => $this->simplesP['Preto']->id, 'tipo' => 'premium', 'titulo' => 'No Ar', 'status' => 'ativo', 'codigo_mlb' => 'MLB123']);
        // Simples (uma oferta, sem grupo) e sem oferta nenhuma.
        $simples = PubProduto::create(['company_id' => $this->empresaP->id, 'oferta_id' => $this->simplesP['Azul']->id, 'sku' => 'CAD-AZ', 'nome' => 'Avulsa', 'origem' => PubProduto::ORIGEM_PORTAL]);
        (new RascunhoRepository())->criar($simples, [new Alvo('gold_special', null)]);
        $semOferta = PubProduto::create(['company_id' => $this->empresaP->id, 'sku' => 'X', 'nome' => 'X', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        (new RascunhoRepository())->criar($semOferta, [new Alvo('gold_special', 'X')]);

        $alvo = $this->alvo();
        $daConta = app(ProgramasPublicadorService::class)->produtosQuery($alvo['mlb_empresa'], $alvo['company'])->with(['oferta', 'estruturaProduto'])->get()->keyBy('id');
        $repo = new RascunhoRepository();
        $snapshots = [];
        foreach ($daConta as $p) {
            $r = PubRascunho::where('produto_id', $p->id)->first();
            if ($r !== null) {
                $snapshots[$p->id] = $repo->snapshot($r);
            }
        }
        $lote = app(EfetivosEmLote::class)->carregar($daConta->values(), $daConta, $snapshots)['efetivos'];

        $chaves = ['titulos', 'precos', 'mlbs', 'precos_por_variante'];
        foreach ([$grupo, $kit, $simples, $semOferta] as $p) {
            $origem = app(DadosEfetivosService::class)->daProduto(PubProduto::find($p->id));
            $this->assertSame(
                array_intersect_key($origem, array_flip($chaves)),
                array_intersect_key($lote[$p->id], array_flip($chaves)),
                "produto {$p->id} ({$p->nome}) diverge do DadosEfetivosService",
            );
        }
        // O que importa no kit: cada cor com o preço do Combo 2 dela (custo 2 × 100), não vazio.
        $this->assertCount(3, $lote[$kit->id]['precos_por_variante']);
        $this->assertNotNull(array_values($lote[$kit->id]['precos_por_variante'])[0]['gold_special']);
        $this->assertSame(['MLB123'], $lote[$grupo->id]['mlbs']);
        $this->assertSame('Cadeira Planejada', $lote[$grupo->id]['titulos']['gold_special']);
    }
}
