<?php

namespace Tests\Unit\Publicador;

use App\Models\MlbEmpresa;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubRascunho;
use App\Services\Publicador\CapaDoKitService;
use App\Services\Publicador\CriarFaseService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\Payload\Alvo;
use App\Support\Publicador\RegraViolada;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\RegeneradorVariantes;
use App\Support\Publicador\Variacao\ValorEixo;
use App\Support\Publicador\Variacao\Variante;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 175 Plano 175-02 (§5 da ETAPA-3) — `CriarFaseService` e os helpers de
 * família do `PubProduto`.
 *
 * As duas primeiras baterias são dos helpers PUROS (`faseDaQuantidade` /
 * `proximaQuantidade`): é o que a §8 da spec pede ("funções puras com teste
 * unitário"). Elas não encostam no banco de propósito — a regra "menor N ≥ 2
 * que a família ainda não tem" é aritmética, e aritmética se prova sem fixture.
 *
 * ⚠️ `pub_produtos.fase` é o NÚMERO da fase. `estrutura_ofertas.fase` é o TIPO da
 * oferta no Portal (`simples|combo|kit|combit`) — nada a ver.
 *
 * @group phase175
 */
class CriarFaseServiceTest extends TestCase
{
    use RefreshDatabase;

    // ═══ Helpers puros do PubProduto (sem banco) ═════════════════════════════

    /** Kit N é a Fase N: a fase é DERIVADA da quantidade, não da ordem de criação. */
    public function test_fase_da_quantidade_e_a_propria_quantidade(): void
    {
        $this->assertSame(1, PubProduto::faseDaQuantidade(1), 'o base, de 1 unidade, é a Fase 1');
        $this->assertSame(2, PubProduto::faseDaQuantidade(2), 'Kit 2 é a Fase 2');
        $this->assertSame(5, PubProduto::faseDaQuantidade(5), 'Kit 5 é a Fase 5, não a 2ª fase criada');
        $this->assertSame(1, PubProduto::faseDaQuantidade(0), 'fase nenhuma é menor que a do base');
        $this->assertSame(1, PubProduto::faseDaQuantidade(-3), 'fase nenhuma é menor que a do base');
    }

    /** Menor inteiro ≥ 2 que a família ainda não tem — AQUI o buraco é reaproveitado. */
    public function test_proxima_quantidade_e_o_menor_inteiro_livre_acima_de_um(): void
    {
        $this->assertSame(2, PubProduto::proximaQuantidade([]), 'família vazia: o primeiro kit é o de 2');
        $this->assertSame(2, PubProduto::proximaQuantidade([1]), 'a unidade do base (1) nunca conta como kit');
        $this->assertSame(3, PubProduto::proximaQuantidade([1, 2]));
        $this->assertSame(3, PubProduto::proximaQuantidade([1, 2, 4]), 'família com Kit 2 e Kit 4 → 3');
        $this->assertSame(5, PubProduto::proximaQuantidade([4, 2, 1, 3]), 'lista fora de ordem');
        $this->assertSame(2, PubProduto::proximaQuantidade([5, 9]), 'nada entre 2 e 4: devolve 2');
        $this->assertSame(3, PubProduto::proximaQuantidade([2, 2, 2]), 'repetida não abre buraco');
    }

    // ═══ A família em Eloquent ═══════════════════════════════════════════════

    public function test_eh_kit_exige_base_e_duas_unidades(): void
    {
        $base = $this->base($this->empresa());

        $this->assertFalse($base->ehKit(), 'base nunca é kit');
        $this->assertFalse($this->kit($base, 1, 2)->ehKit(), 'aponta para o base mas leva 1 unidade: não é kit');
        $this->assertTrue($this->kit($base, 2, 3)->ehKit());
    }

    public function test_base_e_kits_resolvem_a_familia_nos_dois_sentidos(): void
    {
        $base = $this->base($this->empresa());
        $kit3 = $this->kit($base, 3, 3);
        $kit2 = $this->kit($base, 2, 2);

        $this->assertNull($base->base, 'o base não aponta para ninguém');
        $this->assertSame($base->id, $kit2->base->id);
        // `kits()` ordena por fase, não pela ordem de criação (o Kit 3 nasceu primeiro).
        $this->assertSame([$kit2->id, $kit3->id], $base->kits()->pluck('id')->all());
        $this->assertSame([], $kit2->kits()->pluck('id')->all(), 'kit nunca tem kit (sem cadeia)');
    }

    public function test_familia_e_a_mesma_lista_vista_do_base_ou_de_um_kit(): void
    {
        $base = $this->base($this->empresa());
        $kit4 = $this->kit($base, 4, 4);
        $kit2 = $this->kit($base, 2, 2);
        // Outro base da mesma empresa: não pode vazar para a família.
        $this->base($this->empresa('Outro polo'), 'MES-01', 'Mesa');

        $esperada = [$base->id, $kit2->id, $kit4->id];
        $this->assertSame($esperada, $base->familia()->pluck('id')->all(), 'vista do base');
        $this->assertSame($esperada, $kit4->familia()->pluck('id')->all(), 'vista de um kit');
        $this->assertSame([1, 2, 4], $base->familia()->pluck('fase')->all(), 'em ordem de fase');
    }

    /** Os casts das colunas do 175-01 — sem eles quem lê precisa converter à mão. */
    public function test_casts_das_colunas_de_fase(): void
    {
        $base = $this->base($this->empresa());
        $kit = $this->kit($base, 2, 2);
        $kit->update(['kit_sugestao_recusada_em' => '2026-10-08 13:45:00']);

        $lido = $kit->fresh();
        $this->assertSame(2, $lido->fase);
        $this->assertSame(2, $lido->quantidade_kit);
        $this->assertTrue($lido->estoque_calculado);
        $this->assertInstanceOf(CarbonInterface::class, $lido->kit_sugestao_recusada_em);
        $this->assertSame('2026-10-08 13:45:00', $lido->kit_sugestao_recusada_em->format('Y-m-d H:i:s'));

        $this->assertSame(1, $base->fresh()->fase);
        $this->assertFalse($base->fresh()->estoque_calculado);
        $this->assertNull($base->fresh()->kit_sugestao_recusada_em);
    }

    // ═══ CriarFaseService — recusas (nada é escrito) ═════════════════════════

    public function test_base_sem_rascunho_recusa_e_nao_cria_produto_nenhum(): void
    {
        $base = $this->base($this->empresa());

        try {
            $this->servico()->criar($base, $this->dados(2));
            $this->fail('devia ter recusado: o base não tem rascunho para clonar');
        } catch (RegraViolada $e) {
            $this->assertSame('KIT-01', $e->regra);
        }

        $this->assertSame(1, PubProduto::count(), 'só o base ficou no banco');
        $this->assertSame(0, PubRascunho::count());
    }

    public function test_base_que_ja_e_kit_recusa_sem_cadeia(): void
    {
        $base = $this->baseComRascunho();
        $kit = $this->servico()->criar($base, $this->dados(2));

        try {
            $this->servico()->criar($kit, $this->dados(3));
            $this->fail('devia ter recusado: kit de kit não existe');
        } catch (RegraViolada $e) {
            $this->assertSame('KIT-02', $e->regra);
        }

        $this->assertSame(2, PubProduto::count(), 'o base e o Kit 2; nada de terceiro produto');
    }

    public function test_quantidade_menor_que_dois_recusa(): void
    {
        $base = $this->baseComRascunho();

        foreach ([1, 0, -3] as $quantidade) {
            try {
                $this->servico()->criar($base, $this->dados($quantidade));
                $this->fail("devia ter recusado a quantidade {$quantidade}");
            } catch (RegraViolada $e) {
                $this->assertSame('KIT-03', $e->regra);
            }
        }

        $this->assertSame(1, PubProduto::count());
    }

    public function test_quantidade_repetida_na_familia_recusa_com_mensagem_de_campo(): void
    {
        $base = $this->baseComRascunho();
        $this->servico()->criar($base, $this->dados(2));

        try {
            $this->servico()->criar($base, $this->dados(2));
            $this->fail('devia ter recusado: já existe Kit 2');
        } catch (RegraViolada $e) {
            $this->assertSame('KIT-04', $e->regra);
            $this->assertSame('Já existe Kit 2 deste produto.', $e->getMessage());
            $this->assertSame('quantidade', $e->contexto['campo'] ?? null, 'a tela precisa saber em que campo pôr o erro');
        }

        $this->assertSame(2, PubProduto::count());
    }

    // ═══ CriarFaseService — o kit e o rascunho dele ══════════════════════════

    public function test_kit_nasce_com_as_ancoras_do_base_e_a_fase_da_quantidade(): void
    {
        $base = $this->baseComRascunho();

        $kit = $this->servico()->criar($base, $this->dados(3, sku: 'CAD-01-KIT3'));

        $this->assertSame($base->id, $kit->produto_base_id);
        $this->assertSame(3, $kit->quantidade_kit);
        $this->assertSame(3, $kit->fase, 'Kit 3 é a Fase 3: a fase vem da quantidade, não da ordem de criação');
        $this->assertTrue($kit->estoque_calculado);
        $this->assertTrue($kit->ehKit());
        $this->assertSame('CAD-01-KIT3', $kit->sku);
        $this->assertSame('Kit 3 Cadeira', $kit->nome);
        $this->assertSame(PubProduto::ORIGEM_PUBLICADOR, $kit->origem);
        $this->assertNull($kit->oferta_id, 'o SKU do kit não vai para o Portal (decisão 5)');
        // Âncoras vêm do BASE, nunca de `$dados` (T-175-05).
        $this->assertSame($base->mlb_empresa_id, $kit->mlb_empresa_id);
        $this->assertSame($base->company_id, $kit->company_id);

        // E o segundo kit da mesma família também é o degrau da quantidade DELE.
        $this->assertSame(4, $this->servico()->criar($base->fresh(), $this->dados(4, sku: 'CAD-01-KIT4'))->fase, 'Kit 4 é a Fase 4');
    }

    /**
     * O caso que prova o bug: com a fase cronológica este Kit 5 nascia "Fase 2" — o cartão
     * diria "Kit 5" (via `rotuloFase`) e a Visão geral contaria o MESMO produto no bucket
     * Fase 2, porque `ProgramasPublicadorService::bucketDaFase()` lê `fase`, não a quantidade.
     */
    public function test_primeiro_kit_de_cinco_unidades_nasce_na_fase_5(): void
    {
        $base = $this->baseComRascunho();

        $kit = $this->servico()->criar($base, $this->dados(5, sku: 'CAD-01-KIT5'));

        $this->assertSame(5, $kit->fase, 'primeiro kit da família, mas de 5 unidades: Fase 5');
        $this->assertSame(5, $kit->quantidade_kit);
    }

    /**
     * O caso do buraco: `proximaQuantidade` reaproveita o 3 (regra que já existia), e com a
     * fase cronológica o Kit 3 nascia "Fase 4" — e, como `familia()` ordena por `fase`, ele
     * apareceria DEPOIS do Kit 4 na tela.
     */
    public function test_familia_com_kit_2_e_kit_4_recebe_o_kit_3_na_fase_3_e_em_ordem(): void
    {
        $base = $this->baseComRascunho();
        $this->servico()->criar($base, $this->dados(2));
        $this->servico()->criar($base->fresh(), $this->dados(4));

        $familia = $base->fresh()->familia();
        $this->assertSame(3, PubProduto::proximaQuantidade($familia->pluck('quantidade_kit')->all()), 'o buraco de quantidade É reaproveitado');

        $kit3 = $this->servico()->criar($base->fresh(), $this->dados(3));
        $this->assertSame(3, $kit3->fase, 'Kit 3 é a Fase 3, não a 4ª fase criada');

        $this->assertSame([1, 2, 3, 4], $base->fresh()->familia()->pluck('fase')->all(), 'familia() ordena por fase: o Kit 3 vem antes do Kit 4');
        $this->assertSame([1, 2, 3, 4], $base->fresh()->familia()->pluck('quantidade_kit')->all(), 'fase e quantidade andam juntas');
    }

    public function test_rascunho_do_kit_copia_schema_e_condicoes_e_nasce_sem_historico(): void
    {
        $base = $this->baseComRascunho();
        $rb = $base->rascunho;
        // Histórico do base: nada disso pode aparecer no kit.
        $rb->validacoes()->create(['revisao' => $rb->revisao, 'camada' => 'L1', 'resultado' => 'PASS']);
        $rb->publicacoes()->create(['revisao' => $rb->revisao, 'modelo_publicacao' => 'UP',
            'chave_idempotencia' => (string) Str::uuid(), 'status' => PubPublicacao::PUBLISHED]);

        $kit = $this->servico()->criar($base, $this->dados(2, descricao: 'Este kit contém 2 unidades de Cadeira.'));
        $rk = $kit->rascunho;

        $this->assertSame($rb->categoria_id, $rk->categoria_id);
        $this->assertSame($rb->dominio_id, $rk->dominio_id, 'sem o domínio o kit renegocia o schema da categoria');
        $this->assertSame($rb->schema_hash, $rk->schema_hash, 'sem o hash o kit renegocia o schema da categoria');
        $this->assertSame($rb->condicao, $rk->condicao);
        $this->assertSame($rb->envio, $rk->envio);
        $this->assertSame($rb->garantia, $rk->garantia);
        $this->assertSame($rb->modelo_publicacao, $rk->modelo_publicacao);
        $this->assertSame($rb->identificacao, $rk->identificacao, 'coluna morta, copiada para não divergir da §5');
        $this->assertTrue($rk->fotos_por_variante);
        $this->assertFalse($rk->incluir_geral_nas_variantes);
        $this->assertSame('Este kit contém 2 unidades de Cadeira.', $rk->descricao, 'a descrição é de quem chama');

        // O que NÃO se copia.
        $this->assertSame(PubRascunho::DRAFT, $rk->status, 'o base estava PUBLISHED');
        $this->assertSame(1, $rk->revisao, 'o base estava na revisão 9');
        $this->assertNull($rk->step_state, 'o progresso de etapas do base não é o do kit');
        $this->assertNull($rk->conta_checada_em);
        $this->assertNull($rk->oferta_id);
        $this->assertSame(['equipe' => true, 'id' => 7], $rk->ator);
        $this->assertSame(0, $rk->validacoes()->count());
        $this->assertSame(0, $rk->publicacoes()->count());
        $this->assertSame(1, $rb->fresh()->validacoes()->count(), 'o histórico do base continua lá');
    }

    public function test_alvos_do_kit_tem_os_tipos_do_base_com_os_titulos_de_quem_chamou(): void
    {
        $base = $this->baseComRascunho();

        $kit = $this->servico()->criar($base, $this->dados(2, titulos: ['gold_special' => 'Kit 2 Cadeira Executiva ECF', 'gold_pro' => null]));

        $alvos = $kit->rascunho->alvos()->orderBy('posicao')->get();
        $this->assertSame(['gold_special', 'gold_pro'], $alvos->pluck('listing_type_id')->all());
        $this->assertSame('Kit 2 Cadeira Executiva ECF', $alvos[0]->titulo);
        $this->assertNull($alvos[1]->titulo, 'tipo sem título continua sem título');
        $this->assertSame([true, true], $alvos->pluck('ativo')->all());
    }

    public function test_atributos_do_kit_esvaziam_o_gtin_e_marcam_as_medidas_para_revisao(): void
    {
        $base = $this->baseComRascunho();

        $kit = $this->servico()->criar($base, $this->dados(2));

        $atributos = $kit->rascunho->atributos()->get()->keyBy('attribute_id');
        $this->assertFalse($atributos->has('GTIN'), 'o EAN é da unidade, não do kit');
        $this->assertSame('ECF', $atributos['BRAND']->value_name);
        $this->assertFalse($atributos['BRAND']->revisar, 'atributo comum não vira pendência');
        $this->assertSame('6000 g', $atributos['SELLER_PACKAGE_WEIGHT']->value_name, 'medida copiada');
        $this->assertTrue($atributos['SELLER_PACKAGE_WEIGHT']->revisar, 'e marcada para revisão: N unidades mudam o pacote');
        $this->assertTrue($atributos['SELLER_PACKAGE_HEIGHT']->revisar);
        $this->assertSame('17055160', $atributos['EMPTY_GTIN_REASON']->value_id ?? null, 'o motivo de não ter GTIN é copiado');
    }

    /**
     * ⚠️ O remapeamento de `pub_variante_eixo_valores`: a tabela tem PK composta
     * `(variante_id, eixo_id)` e não é citada na §5. Sem remapear, a variante do kit
     * nasce SEM combinação e a grade do editor fica inutilizável.
     */
    public function test_variantes_do_kit_tem_a_mesma_combinacao_apontando_para_os_eixos_novos(): void
    {
        $base = $this->baseComRascunho();

        $kit = $this->servico()->criar($base, $this->dados(2));

        $rb = $base->rascunho;
        $rk = $kit->rascunho;

        // 1. Eixos e valores são LINHAS NOVAS, com a mesma identidade.
        $eixoBase = $rb->eixos()->firstOrFail();
        $eixoKit = $rk->eixos()->firstOrFail();
        $this->assertNotSame($eixoBase->id, $eixoKit->id);
        $this->assertSame(['COLOR', 'Cor', true], [$eixoKit->attribute_id, $eixoKit->nome, $eixoKit->defines_picture]);
        $this->assertSame($eixoBase->valores->pluck('chave_hash')->all(), $eixoKit->valores->pluck('chave_hash')->all());
        $this->assertSame([], array_intersect($eixoBase->valores->pluck('id')->all(), $eixoKit->valores->pluck('id')->all()));

        // 2. A pivô do kit aponta SÓ para eixos e valores do kit — nunca para os do base.
        $idsDoKit = $rk->variantes()->pluck('id')->all();
        $pivo = DB::table('pub_variante_eixo_valores')->whereIn('variante_id', $idsDoKit)->get();
        $this->assertCount(2, $pivo, 'duas variantes, um eixo cada');
        $this->assertSame([$eixoKit->id], $pivo->pluck('eixo_id')->unique()->values()->all());
        $this->assertSame([], array_intersect($pivo->pluck('eixo_valor_id')->all(), $eixoBase->valores->pluck('id')->all()),
            'nenhuma linha do kit aponta para valor de eixo do BASE');
        $this->assertSame([], array_diff($pivo->pluck('eixo_valor_id')->all(), $eixoKit->valores->pluck('id')->all()));

        // 3. E a combinação lida de volta é a mesma do base, variante por variante.
        $repo = new RascunhoRepository();
        $doBase = collect($repo->snapshot($rb)->variantes)->mapWithKeys(fn ($v) => [$v->chave => $v->valores['COLOR']->valueName]);
        $doKit = collect($repo->snapshot($rk)->variantes)->mapWithKeys(fn ($v) => [$v->chave => $v->valores['COLOR']->valueName]);
        $this->assertSame(['COLOR=id:52049' => 'Preto', 'COLOR=id:52028' => 'Azul'], $doBase->all());
        $this->assertSame($doBase->all(), $doKit->all(), 'a grade do kit é a mesma grade do base');
    }

    public function test_variantes_do_kit_recebem_sku_e_estoque_de_quem_chamou_e_nunca_nascem_publicadas(): void
    {
        $base = $this->baseComRascunho();

        $kit = $this->servico()->criar($base, $this->dados(2));

        $variantes = $kit->rascunho->variantes()->orderBy('posicao')->get()->keyBy('combinacao_chave');
        $preto = $variantes['COLOR=id:52049'];
        $azul = $variantes['COLOR=id:52028'];

        $this->assertSame([3, null], [$preto->estoque, $preto->estoque_depositos], 'floor(7 ÷ 2) vindo de quem chamou');
        $this->assertSame(4, $azul->estoque);
        $this->assertSame(['SP' => 3, 'RJ' => 1], $azul->estoque_depositos);
        $this->assertFalse($preto->publicada, 'o kit nunca foi publicado');
        $this->assertTrue($preto->ativa);
        $this->assertFalse($preto->orfa);

        $sku = fn ($v) => $v->atributos()->where('attribute_id', 'SELLER_SKU')->value('value_name');
        $this->assertSame('CAD-01-PRETO-KIT2', $sku($preto));
        $this->assertSame('CAD-01-AZUL-KIT2', $sku($azul));
        $this->assertSame(0, $preto->atributos()->where('attribute_id', 'GTIN')->count(), 'GTIN da variante sai vazio');
        $this->assertSame('ECF', $preto->atributos()->where('attribute_id', 'MANUFACTURER')->value('value_name'),
            'os outros atributos de variante são copiados');
    }

    public function test_precos_do_kit_nascem_vazios_em_todos_os_alvos(): void
    {
        $base = $this->baseComRascunho();

        $kit = $this->servico()->criar($base, $this->dados(2));

        $alvosDoKit = $kit->rascunho->alvos()->pluck('id')->all();
        $precos = DB::table('pub_variante_precos')
            ->whereIn('variante_id', $kit->rascunho->variantes()->pluck('id'))->get();

        $this->assertCount(4, $precos, '2 variantes × 2 alvos');
        $this->assertSame([null], $precos->pluck('preco')->unique()->values()->all(), 'preço vazio (§5)');
        $this->assertSame([], array_diff($precos->pluck('alvo_id')->unique()->all(), $alvosDoKit),
            'nenhum preço do kit aponta para alvo do BASE');
        // O base continua com o preço que tinha.
        $this->assertSame('150.00', (string) $base->rascunho->variantes()->first()->precos()->first()->preco);
    }

    /** Falha no meio: o PubProduto, o rascunho, os alvos e os eixos já gravados somem junto. */
    public function test_falha_no_meio_nao_deixa_nada_no_banco(): void
    {
        $base = $this->baseComRascunho();
        $antes = [PubProduto::count(), PubRascunho::count(), DB::table('pub_eixos')->count(), DB::table('pub_rascunho_alvos')->count()];

        $servico = new class(new RascunhoRepository(), app(CapaDoKitService::class)) extends CriarFaseService
        {
            /** Estoura DEPOIS dos eixos: produto, rascunho, alvos, atributos e eixos já estão gravados. */
            protected function copiarVariantes(PubRascunho $base, PubRascunho $kit, array $dados, array $mapaEixoValor, array $mapaAlvo): void
            {
                throw new \RuntimeException('falha simulada depois de copiar os eixos');
            }
        };

        try {
            $servico->criar($base, $this->dados(2));
            $this->fail('a falha simulada devia ter subido');
        } catch (\RuntimeException $e) {
            $this->assertSame('falha simulada depois de copiar os eixos', $e->getMessage());
        }

        $this->assertSame($antes, [PubProduto::count(), PubRascunho::count(), DB::table('pub_eixos')->count(), DB::table('pub_rascunho_alvos')->count()],
            'a transação voltou tudo: nem o PubProduto, nem o rascunho parcial');
    }

    // ═══ Fixtures ════════════════════════════════════════════════════════════

    private function servico(): CriarFaseService
    {
        // A capa (175-06) vem do container: nenhum teste deste arquivo a pede,
        // e sem `capa` em $dados ela nem é consultada.
        return new CriarFaseService(new RascunhoRepository(), app(CapaDoKitService::class));
    }

    /** O que a prévia do 175-05 passaria para o serviço. */
    private function dados(int $quantidade, ?string $sku = null, ?array $titulos = null, ?string $descricao = null): array
    {
        return [
            'quantidade' => $quantidade,
            'sku' => $sku ?? 'CAD-01-KIT'.$quantidade,
            'seller_skus' => [
                'COLOR=id:52049' => 'CAD-01-PRETO-KIT'.$quantidade,
                'COLOR=id:52028' => 'CAD-01-AZUL-KIT'.$quantidade,
            ],
            'titulo_por_tipo' => $titulos ?? ['gold_special' => 'Kit '.$quantidade.' Cadeira Executiva ECF'],
            'descricao' => $descricao,
            'estoque_por_variante' => [
                'COLOR=id:52049' => ['estoque' => 3, 'depositos' => null],
                'COLOR=id:52028' => ['estoque' => 4, 'depositos' => ['SP' => 3, 'RJ' => 1]],
            ],
            'ator' => ['equipe' => true, 'id' => 7],
        ];
    }

    /** Base com rascunho cheio: categoria, atributos, um eixo com dois valores, duas variantes e dois alvos. */
    private function baseComRascunho(?MlbEmpresa $empresa = null): PubProduto
    {
        $base = $this->base($empresa ?? $this->empresa());
        $repo = new RascunhoRepository();
        $r = $repo->criar($base, [new Alvo('gold_special', 'Cadeira Executiva ECF'), new Alvo('gold_pro', null)]);

        $r->update([
            'categoria_id' => 'MLB1234', 'dominio_id' => 'MLB-CHAIRS', 'schema_hash' => str_repeat('a', 64),
            'condicao' => 'new', 'descricao' => 'Cadeira de uma unidade.',
            'envio' => ['modo' => 'me2', 'frete_gratis' => true, 'retirada' => false],
            'garantia' => ['tipo' => 'fabrica', 'valor' => 12, 'unidade' => 'meses'],
            'fotos_por_variante' => true, 'incluir_geral_nas_variantes' => false,
            'modelo_publicacao' => 'UP', 'identificacao' => ['legado' => 'coluna morta'],
            // Os três abaixo são justamente o que NÃO se copia.
            'step_state' => ['etapa' => 'fotos'], 'conta_checada_em' => now(),
            'status' => PubRascunho::PUBLISHED, 'revisao' => 9,
        ]);

        $repo->gravarAtributos($r, [
            'BRAND' => ['value_name' => 'ECF'],
            'GTIN' => ['value_name' => '7896553367645'],
            'EMPTY_GTIN_REASON' => ['value_id' => '17055160'],
            'SELLER_PACKAGE_WEIGHT' => ['value_name' => '6000 g', 'value_number' => 6000, 'value_unit' => 'g'],
            'SELLER_PACKAGE_HEIGHT' => ['value_name' => '90 cm'],
        ]);

        $eixos = [new Eixo('COLOR', 'Cor', 0, true, [new ValorEixo('52049', 'Preto'), new ValorEixo('52028', 'Azul')])];
        $regen = RegeneradorVariantes::regenerar($repo->snapshot($r)->variantes, $eixos);
        $variantes = array_map(fn (Variante $v) => $v->comDados([
            'estoque' => 7,
            'precos' => ['gold_special' => 150.0],
            'atributos' => [
                'SELLER_SKU' => ['value_name' => 'CAD-01-'.mb_strtoupper($v->valores['COLOR']->valueName)],
                'GTIN' => ['value_name' => '7896553367645'],
                'MANUFACTURER' => ['value_name' => 'ECF'],
            ],
        ]), $regen->variantes);
        $repo->gravarVariacao($r, $eixos, $variantes);

        return $base->fresh();
    }

    private function empresa(string $nome = 'Polo das Fases'): MlbEmpresa
    {
        return MlbEmpresa::create(['nome' => $nome, 'projeto' => 'POLOS']);
    }

    private function base(MlbEmpresa $empresa, string $sku = 'CAD-01', string $nome = 'Cadeira'): PubProduto
    {
        return PubProduto::create(['mlb_empresa_id' => $empresa->id, 'sku' => $sku, 'nome' => $nome,
            'origem' => PubProduto::ORIGEM_PUBLICADOR]);
    }

    private function kit(PubProduto $base, int $quantidade, int $fase): PubProduto
    {
        return PubProduto::create(['mlb_empresa_id' => $base->mlb_empresa_id, 'company_id' => $base->company_id,
            'sku' => $base->sku.'-KIT'.$quantidade, 'nome' => 'Kit '.$quantidade.' '.$base->nome,
            'origem' => PubProduto::ORIGEM_PUBLICADOR, 'produto_base_id' => $base->id,
            'quantidade_kit' => $quantidade, 'fase' => $fase, 'estoque_calculado' => true]);
    }
}
