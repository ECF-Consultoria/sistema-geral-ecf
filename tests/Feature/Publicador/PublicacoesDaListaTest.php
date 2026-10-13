<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\MlAcervoItem;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Services\Publicador\PainelVisaoGeralService;
use App\Services\Publicador\PublicacoesDaListaService;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 180, plano 02 — o CONTRATO do `PublicacoesDaListaService`: o 4-join que
 * leva um `ml_item_id` do acervo até o produto que o originou, a busca no
 * sentido inverso (nome/SKU do produto -> `ml_item_id`) e o mapa de cobertura
 * F1/F2 × Clássico/Premium.
 *
 * ═══ O que este arquivo guarda (e uma asserção isolada não guardaria) ════════
 *
 * 1. **O ESCOPO POR EMPRESA, NOS DOIS SENTIDOS** (T-180-02-I). `pub_produtos` e
 *    `pub_publicacao_itens` são tabelas MULTI-EMPRESA: o grupo
 *    `where(function ...)` é a única coisa que separa a empresa A da B. Dois
 *    casos cobram o vazamento dos dois lados — produto da B achado pela busca da
 *    A, e `ml_item_id` que existe nas DUAS empresas recebendo a metodologia da
 *    errada. A mutação (a) desta rodada desagrupa o `OR` e os dois reprovam.
 * 2. **O CUSTO NÃO CRESCE COM O ACERVO** (T-180-02-D). Não basta contar
 *    consultas: o gate varre o SQL gerado e exige que `ml_acervo_itens` **não
 *    apareça**, e repete a medição com um acervo 40× maior para provar que a
 *    contagem não se mexe. Contagem de consulta medida por `assertCount` sobre
 *    `DB::getQueryLog()`, nunca por olho.
 * 3. **AUTOR SEM INVENÇÃO** (LIST-13/EXMP-01). Os cinco shapes reais de
 *    `pub_publicacoes.ator` e, em todos, a asserção NEGATIVA de que o nome não é
 *    `'—'`, `null`, `'NaN'` nem o nome de outra publicação.
 * 4. **AUSÊNCIA É A RESPOSTA** para o Legado. `ml_item_id` sem linha em
 *    `pub_publicacao_itens` fica FORA do mapa — quem escreve a palavra "Legado"
 *    é a tela. Cobrado por `assertArrayNotHasKey`, com o cenário produzindo
 *    entradas de verdade ao lado (senão a negativa seria vazia por construção).
 * 5. **NENHUM CAMPO É OBJETO E NENHUM SEGREDO SAI** — varredura de todo valor de
 *    todo item (só string/int/bool/null) e asserção negativa de `access_token`/
 *    `refresh_token` sobre um cenário que TEM `MlToken` gravado. É a herança da
 *    tela preta de 07/10/2026.
 */
class PublicacoesDaListaTest extends TestCase
{
    use RefreshDatabase;

    /** As 10 chaves do item, na ORDEM exata do contrato que os planos 180-04/08/09 ligam. */
    private const CHAVES_DO_ITEM = [
        'produto_id',
        'produto_nome',
        'produto_sku',
        'fase',
        'quantidade_kit',
        'estoque',
        'autor_nome',
        'autor_tipo',
        'publicada_em',
        'publicacao_id',
    ];

    private function servico(): PublicacoesDaListaService
    {
        return new PublicacoesDaListaService;
    }

    /**
     * Uma conta com Company, MlbEmpresa e token gravado. O token existe DE
     * PROPÓSITO: é o que torna a asserção negativa de segredo não-vazia.
     *
     * @return array{0: Company, 1: MlbEmpresa}
     */
    private function conta(string $nome = 'Polo das Publicações'): array
    {
        $company = Company::factory()->create(['name' => $nome]);
        $empresa = MlbEmpresa::create(['nome' => $nome, 'projeto' => 'POLOS', 'company_id' => $company->id]);
        MlToken::create([
            'company_id' => $company->id,
            'ml_user_id' => '1555596317',
            'access_token' => 'APP_USR-segredo-do-access',
            'refresh_token' => 'TG-segredo-do-refresh',
            'expires_at' => now()->addHours(5),
            'status' => 'active',
        ]);

        return [$company->fresh(), $empresa->fresh()];
    }

    private function produto(?Company $c, ?MlbEmpresa $e, string $sku, string $nome, array $extra = []): PubProduto
    {
        return PubProduto::create($extra + [
            'company_id' => $c?->id,
            'mlb_empresa_id' => $e?->id,
            'sku' => $sku,
            'nome' => $nome,
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);
    }

    /**
     * Uma publicação CONCLUÍDA do produto com um item CREATED no `ml_item_id` pedido.
     *
     * ⚠️ `pub_rascunhos.produto_id` é UNIQUE (um rascunho por produto): duas
     * publicações do mesmo produto são REVISÕES do mesmo rascunho, como no editor.
     */
    private function publicada(
        PubProduto $produto,
        string $mlItemId,
        string $listingType = 'gold_special',
        Carbon|string|null $concluidaEm = null,
        ?array $ator = ['equipe' => true, 'id' => 7, 'nome' => 'Ana Ribeiro'],
        string $status = PubPublicacaoItem::CREATED,
    ): PubPublicacao {
        $rascunho = PubRascunho::firstOrCreate(
            ['produto_id' => $produto->id],
            ['status' => PubRascunho::PUBLISHED, 'revisao' => 1],
        );

        $publicacao = PubPublicacao::create([
            'rascunho_id' => $rascunho->id,
            'revisao' => PubPublicacao::where('rascunho_id', $rascunho->id)->count() + 1,
            'modelo_publicacao' => 'items',
            'status' => PubPublicacao::PUBLISHED,
            'chave_idempotencia' => (string) Str::uuid(),
            'concluida_em' => $concluidaEm ?? now()->subDay(),
            'ator' => $ator,
        ]);

        PubPublicacaoItem::create([
            'publicacao_id' => $publicacao->id,
            'indice' => PubPublicacaoItem::where('publicacao_id', $publicacao->id)->count(),
            'listing_type_id' => $listingType,
            'variante_chave' => ChaveCanonica::UNICA,
            'status' => $status,
            'ml_item_id' => $mlItemId,
            'payload' => ['family_name' => 'Anúncio de '.$produto->nome],
        ]);

        return $publicacao;
    }

    /** Um item do acervo — existe só para PROVAR que o serviço não o consulta. */
    private function acervo(Company $c, string $mlItemId): MlAcervoItem
    {
        return MlAcervoItem::create([
            'company_id' => $c->id,
            'ml_item_id' => $mlItemId,
            'title' => 'Item '.$mlItemId,
            'status' => 'active',
            'available_quantity' => 10,
            'sold_quantity' => 0,
            'nota_ecf' => 60,
            'motivos' => [],
            'severidade' => MlAcervoItem::SEVERIDADE_SAUDAVEL,
            'origem' => MlAcervoItem::ORIGEM_LEGADO,
            'coletado_em' => now(),
        ]);
    }

    /** @return array{0: mixed, 1: list<array<string, mixed>>} o retorno e o log de consultas */
    private function medindo(callable $acao): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $retorno = $acao();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return [$retorno, $log];
    }

    /** Todo valor de todo item é escalar ou null — a trava da tela preta de 07/10. */
    private function assertSoEscalares(array $mapa, string $onde): void
    {
        foreach ($mapa as $chave => $item) {
            $this->assertIsArray($item, "{$onde}: a entrada {$chave} deveria ser um array de campos");
            foreach ($item as $campo => $valor) {
                $this->assertTrue(
                    $valor === null || is_scalar($valor),
                    "{$onde}: {$chave}.{$campo} não é escalar nem null — objeto renderizado como texto já derrubou a página inteira",
                );
            }
        }
    }

    // ═══ Task 1 — a metodologia vem do dado real ════════════════════════════

    /**
     * @group phase180
     */
    public function test_metodologia_vem_do_dado_real(): void
    {
        [$company, $empresa] = $this->conta();

        $poltrona = $this->produto($company, $empresa, 'POL-OSLO', 'Poltrona Oslo');
        $luminaria = $this->produto($company, $empresa, 'LUM-ARCO-BR', 'Luminária de Arco', [
            'fase' => 2, 'quantidade_kit' => 2, 'estoque_calculado' => true,
        ]);
        $mesa = $this->produto($company, $empresa, 'MES-NORD', 'Mesa Nórdica');

        $this->publicada($poltrona, 'MLB001', 'gold_special', '2026-10-01 10:00:00');
        $this->publicada($luminaria, 'MLB002', 'gold_pro', '2026-10-02 11:00:00', ['equipe' => true, 'id' => 9]);
        $this->publicada($mesa, 'MLB003', 'gold_special', '2026-10-03 12:00:00');

        // O acervo da página: 50 ids, dos quais 3 nasceram no Publicador. Os 50
        // existem como LINHA no acervo — é o que torna honesta a prova de que o
        // serviço não os consulta.
        $ids = [];
        for ($n = 1; $n <= 50; $n++) {
            $id = 'MLB'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            $this->acervo($company, $id);
            $ids[] = $id;
        }

        [$mapa, $log] = $this->medindo(fn () => $this->servico()->metodologiaPorMlItemId($company, $ids));

        // ─── UMA consulta, e ela não toca o acervo ───
        $this->assertCount(1, $log, 'metodologiaPorMlItemId tem de ser UMA consulta, não uma por item');
        $this->assertStringNotContainsString(
            'ml_acervo_itens',
            $log[0]['query'],
            'o acervo (1.080.206 linhas / 1.776 MB) NÃO pode entrar no SQL — o custo cresce com a página, nunca com o acervo',
        );
        $this->assertStringContainsString('pub_publicacao_itens', $log[0]['query'], 'a ponte com o acervo é pub_publicacao_itens.ml_item_id');

        // ─── 3 entradas, e NENHUMA para os outros 47 (o Legado é a AUSÊNCIA) ───
        $this->assertCount(3, $mapa, 'só os 3 que nasceram no Publicador entram no mapa');
        // Ordenado antes de comparar: o mapa é indexado por `ml_item_id` e a ORDEM
        // dele é a da consulta (mais recente primeiro) — quem consome indexa pela
        // chave, então a ordem não é contrato e não deve ser travada aqui.
        $chaves = array_keys($mapa);
        sort($chaves);
        $this->assertSame(['MLB001', 'MLB002', 'MLB003'], $chaves);
        foreach (array_slice($ids, 3) as $semPublicador) {
            $this->assertArrayNotHasKey($semPublicador, $mapa, "{$semPublicador} não nasceu no Publicador: tem de ficar FORA do mapa");
        }

        // ─── O contrato: as 10 chaves, na ordem, em toda entrada ───
        foreach ($mapa as $chave => $item) {
            $this->assertSame(self::CHAVES_DO_ITEM, array_keys($item), "a entrada {$chave} saiu com chaves/ordem fora do contrato");
        }

        // ─── Os valores do dado real ───
        $this->assertSame([
            'produto_id' => $poltrona->id,
            'produto_nome' => 'Poltrona Oslo',
            'produto_sku' => 'POL-OSLO',
            'fase' => 1,
            'quantidade_kit' => 1,
            'estoque' => null,
            'autor_nome' => 'Ana Ribeiro',
            'autor_tipo' => 'pessoa',
            'publicada_em' => Carbon::parse('2026-10-01 10:00:00')->toIso8601String(),
            'publicacao_id' => $mapa['MLB001']['publicacao_id'],
        ], $mapa['MLB001']);

        // Fase/kit vêm de `pub_produtos`, QUALIFICADOS (a coluna `fase` colide com
        // `estrutura_ofertas.fase`, que é string e está no mesmo select).
        $this->assertSame(2, $mapa['MLB002']['fase'], 'fase do kit vem de pub_produtos.fase');
        $this->assertSame(2, $mapa['MLB002']['quantidade_kit']);
        $this->assertSame('Luminária de Arco', $mapa['MLB002']['produto_nome']);

        // `estoque_calculado` é BOOLEANO: nunca pode virar quantidade aqui.
        $this->assertNull($mapa['MLB002']['estoque'], 'estoque_calculado é boolean — devolver numero aqui seria inventado');

        // ─── Nada de objeto, nada de segredo ───
        $this->assertSoEscalares($mapa, 'metodologiaPorMlItemId');
        $json = json_encode($mapa, JSON_UNESCAPED_UNICODE);
        foreach (['access_token', 'refresh_token', 'APP_USR', 'TG-segredo'] as $segredo) {
            $this->assertStringNotContainsString($segredo, $json, "o payload vazou {$segredo} — a conta desta empresa TEM token gravado neste cenário");
        }
    }

    /**
     * O custo não cresce com o tamanho do acervo — a MESMA página medida com um
     * acervo 40× maior devolve o mesmo resultado com a MESMA contagem de consulta.
     *
     * ⚠️ Medido na SEGUNDA chamada também: cache de serviço vivo entre chamadas já
     * fez 12 produtos custarem MENOS que 6 nesta milestone (Fase 178).
     *
     * @group phase180
     */
    public function test_custo_nao_cresce_com_o_tamanho_do_acervo(): void
    {
        [$company, $empresa] = $this->conta();
        $produto = $this->produto($company, $empresa, 'POL-OSLO', 'Poltrona Oslo');
        $this->publicada($produto, 'MLB001');

        $pagina = ['MLB001'];
        for ($n = 2; $n <= 50; $n++) {
            $pagina[] = 'MLB'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
        }

        // Acervo pequeno
        foreach ($pagina as $id) {
            $this->acervo($company, $id);
        }
        [$mapaPequeno, $logPequeno] = $this->medindo(fn () => $this->servico()->metodologiaPorMlItemId($company, $pagina));

        // Acervo 40× maior — a PÁGINA é a mesma
        for ($n = 1000; $n < 3000; $n++) {
            $this->acervo($company, 'MLB'.$n);
        }
        [$mapaGrande, $logGrande] = $this->medindo(fn () => $this->servico()->metodologiaPorMlItemId($company, $pagina));
        // Segunda chamada seguida: nenhum cache escondido fazendo a conta parecer menor
        [$mapaRepetido, $logRepetido] = $this->medindo(fn () => $this->servico()->metodologiaPorMlItemId($company, $pagina));

        $this->assertCount(1, $logPequeno);
        $this->assertCount(1, $logGrande, 'acervo 40× maior não pode custar consulta a mais');
        $this->assertCount(1, $logRepetido, 'a segunda chamada tem de custar o MESMO — teto, não cache');
        $this->assertSame($mapaPequeno, $mapaGrande, 'o acervo maior não muda o resultado da página');
        $this->assertSame($mapaPequeno, $mapaRepetido);
        $this->assertCount(1, $mapaPequeno, 'o cenário tem de produzir entrada, senão as asserções acima são vazias por construção');
    }

    /**
     * Os cinco shapes reais de `pub_publicacoes.ator` — e em NENHUM o serviço
     * inventa nome (LIST-13 / EXMP-01).
     *
     * @group phase180
     */
    public function test_autor_nunca_e_inventado(): void
    {
        [$company, $empresa] = $this->conta();

        $casos = [
            // ml_item_id   => [ator gravado,                                      esperado,                                     tipo]
            'MLB-PESSOA' => [['equipe' => true, 'id' => 7, 'nome' => 'Ana Ribeiro'], 'Ana Ribeiro', 'pessoa'],
            'MLB-EQUIPE' => [['equipe' => true, 'id' => 9], PainelVisaoGeralService::QUEM_EQUIPE_SEM_NOME, 'desconhecido'],
            'MLB-ANTIGA' => [['equipe' => true, 'nome' => null], PainelVisaoGeralService::QUEM_ORIGEM_ANTIGA, 'desconhecido'],
            'MLB-NULO' => [null, PainelVisaoGeralService::QUEM_SEM_AUTOR, 'desconhecido'],
            // T-173-09: o ator do CLIENTE pelo Portal TEM nome gravado, e ele não
            // sai do servidor. Divergência deliberada da prescrição do plano.
            'MLB-CLIENTE' => [['equipe' => false, 'id' => 31, 'nome' => 'Fulano da Silva'], PainelVisaoGeralService::QUEM_CLIENTE, 'desconhecido'],
        ];

        $n = 0;
        foreach ($casos as $mlItemId => [$ator, , ]) {
            $produto = $this->produto($company, $empresa, 'SKU-'.(++$n), 'Produto '.$n);
            $this->publicada($produto, $mlItemId, 'gold_special', '2026-10-0'.$n.' 10:00:00', $ator);
        }

        $mapa = $this->servico()->metodologiaPorMlItemId($company, array_keys($casos));
        $this->assertCount(5, $mapa, 'os 5 casos têm de estar no mapa, senão as asserções abaixo são vazias');

        foreach ($casos as $mlItemId => [, $esperado, $tipo]) {
            $this->assertSame($esperado, $mapa[$mlItemId]['autor_nome'], "autor de {$mlItemId}");
            $this->assertSame($tipo, $mapa[$mlItemId]['autor_tipo'], "autor_tipo de {$mlItemId}");

            // ⚠️ A negativa: nunca o travessão da célula vazia, nunca null, nunca
            // 'NaN', nunca o nome de OUTRA publicação.
            $this->assertNotSame('—', $mapa[$mlItemId]['autor_nome']);
            $this->assertNotSame('NaN', $mapa[$mlItemId]['autor_nome']);
            $this->assertNotSame('', $mapa[$mlItemId]['autor_nome']);
            if ($mlItemId !== 'MLB-PESSOA') {
                $this->assertNotSame('Ana Ribeiro', $mapa[$mlItemId]['autor_nome'], "{$mlItemId} herdou o nome de outra publicação");
            }
            $this->assertNotSame('Fulano da Silva', $mapa[$mlItemId]['autor_nome'], 'nome de CLIENTE não sai do servidor (T-173-09)');
        }

        $this->assertSoEscalares($mapa, 'autor');
    }

    /**
     * Reenvio/idempotência: o mesmo `ml_item_id` em duas publicações do mesmo
     * rascunho. Vence a de `concluida_em` mais recente — determinístico.
     *
     * @group phase180
     */
    public function test_mesmo_anuncio_em_duas_publicacoes_vence_a_mais_recente(): void
    {
        [$company, $empresa] = $this->conta();
        $produto = $this->produto($company, $empresa, 'POL-OSLO', 'Poltrona Oslo');

        $velha = $this->publicada($produto, 'MLB001', 'gold_special', '2026-09-01 08:00:00', ['equipe' => true, 'id' => 7, 'nome' => 'Quem publicou antes']);
        $nova = $this->publicada($produto, 'MLB001', 'gold_special', '2026-10-05 09:00:00', ['equipe' => true, 'id' => 8, 'nome' => 'Quem publicou depois']);

        $mapa = $this->servico()->metodologiaPorMlItemId($company, ['MLB001']);

        $this->assertCount(1, $mapa, 'duas publicações do mesmo anúncio viram UMA entrada');
        $this->assertSame($nova->id, $mapa['MLB001']['publicacao_id'], 'tem de vencer a publicação mais recente');
        $this->assertSame('Quem publicou depois', $mapa['MLB001']['autor_nome']);
        $this->assertNotSame($velha->id, $mapa['MLB001']['publicacao_id']);
    }

    /**
     * Entrada suja NÃO lança e não vira bind (a herança da tela preta de 07/10).
     *
     * @group phase180
     */
    public function test_entrada_suja_nao_lanca_e_consulta_so_o_id_valido(): void
    {
        [$company, $empresa] = $this->conta();
        $produto = $this->produto($company, $empresa, 'POL-OSLO', 'Poltrona Oslo');
        $this->publicada($produto, 'MLB1');

        [$mapa, $log] = $this->medindo(
            fn () => $this->servico()->metodologiaPorMlItemId($company, [null, 123, ['x' => 1], '', '  ', 'MLB1']),
        );

        $this->assertSame(['MLB1'], array_keys($mapa), 'só o id válido entra');
        $this->assertCount(1, $log);
        $this->assertContains('MLB1', $log[0]['bindings'], 'o id válido tem de ter virado bind');
        foreach ([123, '', '  '] as $sujo) {
            $this->assertNotContains($sujo, $log[0]['bindings'], 'entrada suja não pode virar bind');
        }

        // Lista inteira inválida: devolve [] SEM consultar.
        [$vazio, $logVazio] = $this->medindo(
            fn () => $this->servico()->metodologiaPorMlItemId($company, [null, 7, ['a'], '   ']),
        );
        $this->assertSame([], $vazio);
        $this->assertCount(0, $logVazio, 'sem id válido não se consulta o banco');

        // Array vazio: idem.
        [$vazio2, $logVazio2] = $this->medindo(fn () => $this->servico()->metodologiaPorMlItemId($company, []));
        $this->assertSame([], $vazio2);
        $this->assertCount(0, $logVazio2);
    }

    /**
     * Status que não é CREATED não é "publicado" — não entra no mapa.
     *
     * @group phase180
     */
    public function test_status_que_nao_e_created_fica_fora(): void
    {
        [$company, $empresa] = $this->conta();

        $criado = $this->produto($company, $empresa, 'SKU-OK', 'Produto publicado');
        $this->publicada($criado, 'MLB-OK', 'gold_special', '2026-10-01 10:00:00');

        $naoCriados = [];
        foreach ([PubPublicacaoItem::PENDING, PubPublicacaoItem::SENT, PubPublicacaoItem::FAILED, PubPublicacaoItem::UNKNOWN] as $i => $status) {
            $p = $this->produto($company, $empresa, 'SKU-'.$status, 'Produto '.$status);
            $mlItemId = 'MLB-'.$status;
            $this->publicada($p, $mlItemId, 'gold_special', '2026-10-0'.($i + 2).' 10:00:00', null, $status);
            $naoCriados[] = $mlItemId;
        }

        $mapa = $this->servico()->metodologiaPorMlItemId($company, ['MLB-OK', ...$naoCriados]);

        $this->assertSame(['MLB-OK'], array_keys($mapa), 'só CREATED conta como publicado');
        foreach ($naoCriados as $fora) {
            $this->assertArrayNotHasKey($fora, $mapa);
        }
    }

    /**
     * Produto de origem `portal` mostra o nome/SKU VIVOS da oferta, não o snapshot
     * da coluna — `PubProduto::nomeExibido()`/`skuExibido()` é a fonte única dessa
     * regra, e continua valendo aqui SEM consulta extra.
     *
     * @group phase180
     */
    public function test_nome_do_produto_de_portal_segue_a_oferta_viva(): void
    {
        [$company, $empresa] = $this->conta();

        $oferta = EstruturaOferta::create([
            'company_id' => $company->id,
            'sku' => 'OFT-NOVO',
            'nome' => 'Poltrona Oslo (nome novo do Portal)',
            'fase' => EstruturaOferta::FASE_SIMPLES,
        ]);

        $produto = $this->produto($company, $empresa, 'SKU-SNAPSHOT-VELHO', 'Nome do snapshot velho', [
            'origem' => PubProduto::ORIGEM_PORTAL,
            'oferta_id' => $oferta->id,
        ]);
        $this->publicada($produto, 'MLB001');

        [$mapa, $log] = $this->medindo(fn () => $this->servico()->metodologiaPorMlItemId($company, ['MLB001']));

        $this->assertCount(1, $log, 'ler a oferta viva não pode custar uma segunda consulta');
        $this->assertSame('Poltrona Oslo (nome novo do Portal)', $mapa['MLB001']['produto_nome']);
        $this->assertSame('OFT-NOVO', $mapa['MLB001']['produto_sku']);
        $this->assertNotSame('Nome do snapshot velho', $mapa['MLB001']['produto_nome'], 'a tela de Publicações não pode discordar da de Produtos');
    }

    // ═══ Task 2 — a busca por nome de produto e o mapa de cobertura ═════════

    /**
     * @group phase180
     */
    public function test_busca_acha_pelo_nome_do_produto(): void
    {
        [$company, $empresa] = $this->conta();

        $poltrona = $this->produto($company, $empresa, 'POL-OSLO', 'Poltrona Oslo');
        $this->publicada($poltrona, 'MLB-POLTRONA-1', 'gold_special', '2026-10-01 10:00:00');
        $this->publicada($poltrona, 'MLB-POLTRONA-2', 'gold_pro', '2026-10-02 10:00:00');

        $mesa = $this->produto($company, $empresa, 'MES-NORD', 'Mesa Nórdica');
        $this->publicada($mesa, 'MLB-MESA', 'gold_special', '2026-10-03 10:00:00');

        // Outra empresa, com produto que casa O MESMO termo (T-180-02-I)
        [$outraCompany, $outraEmpresa] = $this->conta('Polo rival');
        $outraPoltrona = $this->produto($outraCompany, $outraEmpresa, 'POL-RIVAL', 'Poltrona do rival');
        $this->publicada($outraPoltrona, 'MLB-RIVAL', 'gold_special', '2026-10-04 10:00:00');

        [$ids, $log] = $this->medindo(fn () => $this->servico()->mlItemIdsPorProduto($company, 'Poltrona'));

        $this->assertCount(1, $log, 'a busca tem de ser UMA consulta');
        $this->assertStringNotContainsString('ml_acervo_itens', $log[0]['query'], 'a busca começa em pub_produtos, nunca no acervo');

        sort($ids);
        $this->assertSame(['MLB-POLTRONA-1', 'MLB-POLTRONA-2'], $ids, 'os dois anúncios daquele produto, e só eles');
        $this->assertNotContains('MLB-MESA', $ids, 'produto que não casa o termo não entra');
        $this->assertNotContains('MLB-RIVAL', $ids, 'produto de OUTRA empresa não pode vazar na busca da empresa A (T-180-02-I)');

        // O termo é BIND, nunca interpolação (T-180-02-T)
        $this->assertContains('%Poltrona%', $log[0]['bindings'], 'o termo tem de ser bind posicional');

        // SKU também acha, e com a caixa trocada (ASCII — vale em SQLite e em MySQL)
        $this->assertSame(['MLB-MESA'], $this->servico()->mlItemIdsPorProduto($company, 'mes-nord'));
    }

    /**
     * Caixa e acento: o que é REAL em todo banco e o que depende do collation.
     *
     * ⚠️ Medido, não presumido: o `LIKE` do SQLite dobra só ASCII — `'luminaria'`
     * NÃO casa `'Luminária'` lá. Em produção (MySQL 8 `utf8mb4_0900_ai_ci`,
     * MariaDB `utf8mb4_general_ci`) casa, e é por isso que o serviço NÃO normaliza
     * em PHP: normalizar e comparar com a coluna crua daria MENOS resultados.
     * Este teste cobra o comportamento de cada driver em vez de fingir que são iguais.
     *
     * @group phase180
     */
    public function test_busca_ignora_caixa_e_o_acento_depende_do_collation(): void
    {
        [$company, $empresa] = $this->conta();

        $poltrona = $this->produto($company, $empresa, 'POL-OSLO', 'Poltrona Oslo');
        $this->publicada($poltrona, 'MLB-POLTRONA', 'gold_special', '2026-10-01 10:00:00');

        $luminaria = $this->produto($company, $empresa, 'LUM-ARCO-BR', 'Luminária de Arco');
        $this->publicada($luminaria, 'MLB-LUMINARIA', 'gold_special', '2026-10-02 10:00:00');

        $busca = fn (string $termo) => $this->servico()->mlItemIdsPorProduto($company, $termo);

        // Caixa, em termo ASCII puro: vale em QUALQUER banco
        $this->assertSame(['MLB-POLTRONA'], $busca('poltrona'), 'minúscula tem de achar');
        $this->assertSame(['MLB-POLTRONA'], $busca('POLTRONA'), 'MAIÚSCULA tem de achar');
        $this->assertSame(['MLB-POLTRONA'], $busca('oslo'), 'parte do nome tem de achar');
        $this->assertSame(['MLB-LUMINARIA'], $busca('LUM-ARCO-BR'), 'o SKU do produto tem de achar');

        // Termo COM o acento: casa em qualquer banco (bytes iguais)
        $this->assertSame(['MLB-LUMINARIA'], $busca('Luminária'), 'o termo acentuado tem de achar o nome acentuado');

        // Termo SEM o acento: é o collation que decide
        $semAcento = $busca('luminaria');
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->assertSame(
                [],
                $semAcento,
                'MEDIDO: o LIKE do SQLite não dobra acento. Em produção o collation _ai_ci dobra — '
                .'e é exatamente por isso que o serviço não normaliza em PHP.',
            );
        } else {
            $this->assertSame(['MLB-LUMINARIA'], $semAcento, 'collation _ai_ci: termo sem acento tem de achar o nome acentuado');
        }
    }

    /**
     * Termo vazio / só espaços: `[]` e ZERO consulta.
     *
     * @group phase180
     */
    public function test_busca_com_termo_vazio_nao_consulta(): void
    {
        [$company, $empresa] = $this->conta();
        $produto = $this->produto($company, $empresa, 'POL-OSLO', 'Poltrona Oslo');
        $this->publicada($produto, 'MLB-POLTRONA');

        foreach (['', '   ', "\t", "\n "] as $termo) {
            [$ids, $log] = $this->medindo(fn () => $this->servico()->mlItemIdsPorProduto($company, $termo));
            $this->assertSame([], $ids, 'termo vazio não pode devolver a empresa inteira');
            $this->assertCount(0, $log, 'termo vazio não pode consultar o banco (um LIKE %% varreria tudo)');
        }

        // E o cenário PRODUZ resultado com termo de verdade — senão o vazio acima
        // seria vazio por construção.
        $this->assertSame(['MLB-POLTRONA'], $this->servico()->mlItemIdsPorProduto($company, 'Poltrona'));
    }

    /**
     * Item que não é CREATED não entra NEM na busca (o par do caso de metodologia).
     *
     * @group phase180
     */
    public function test_busca_ignora_status_que_nao_e_created(): void
    {
        [$company, $empresa] = $this->conta();

        $ok = $this->produto($company, $empresa, 'POL-OK', 'Poltrona publicada');
        $this->publicada($ok, 'MLB-OK', 'gold_special', '2026-10-01 10:00:00');

        foreach ([PubPublicacaoItem::PENDING, PubPublicacaoItem::SENT, PubPublicacaoItem::FAILED, PubPublicacaoItem::UNKNOWN] as $i => $status) {
            $p = $this->produto($company, $empresa, 'POL-'.$status, 'Poltrona '.$status);
            $this->publicada($p, 'MLB-'.$status, 'gold_special', '2026-10-0'.($i + 2).' 10:00:00', null, $status);
        }

        $this->assertSame(['MLB-OK'], $this->servico()->mlItemIdsPorProduto($company, 'Poltrona'), 'só CREATED entra na busca');
    }

    /**
     * O teto de 2000: termo de 1 caractere numa empresa com 2001 anúncios casáveis.
     *
     * @group phase180
     */
    public function test_busca_respeita_o_teto_de_dois_mil_ids(): void
    {
        [$company, $empresa] = $this->conta();

        // Um produto só, com 2001 itens CREATED na MESMA publicação — o teto é de
        // `ml_item_id`, não de produto.
        $produto = $this->produto($company, $empresa, 'POL-OSLO', 'Poltrona Oslo');
        $publicacao = $this->publicada($produto, 'MLB-0', 'gold_special', '2026-10-01 10:00:00');

        $linhas = [];
        for ($n = 1; $n < 2001; $n++) {
            $linhas[] = [
                'publicacao_id' => $publicacao->id,
                'indice' => $n,
                'listing_type_id' => 'gold_special',
                'variante_chave' => ChaveCanonica::UNICA.'-'.$n,
                'status' => PubPublicacaoItem::CREATED,
                'ml_item_id' => 'MLB-'.$n,
            ];
        }
        foreach (array_chunk($linhas, 500) as $lote) {
            DB::table('pub_publicacao_itens')->insert($lote);
        }

        $this->assertSame(
            2001,
            PubPublicacaoItem::where('publicacao_id', $publicacao->id)->count(),
            'o cenário precisa dos 2001 casáveis, senão o teto não é exercitado',
        );

        $ids = $this->servico()->mlItemIdsPorProduto($company, 'o');

        $this->assertCount(
            PublicacoesDaListaService::TETO_IDS_POR_BUSCA,
            $ids,
            'sem teto a lista viraria um IN (...) de 2001 valores dentro da consulta do acervo',
        );
        $this->assertSame(2000, PublicacoesDaListaService::TETO_IDS_POR_BUSCA, 'o teto declarado é 2000 — ~40 páginas de 50');
    }

    /**
     * A cobertura F1/F2 × Clássico/Premium — as 4 chaves que `MapaDeCobertura.jsx`
     * consome, e o que NÃO acende nenhuma célula.
     *
     * @group phase180
     */
    public function test_cobertura_por_produto_acende_so_as_celulas_reais(): void
    {
        [$company, $empresa] = $this->conta();

        // F1 Clássico + F2 Premium (o cenário do critério de aceite)
        $f1c = $this->produto($company, $empresa, 'SKU-F1C', 'Produto F1 Clássico');
        $this->publicada($f1c, 'MLB-F1C', 'gold_special', '2026-10-01 10:00:00');

        $f2p = $this->produto($company, $empresa, 'SKU-F2P', 'Produto F2 Premium', ['fase' => 2, 'quantidade_kit' => 2]);
        $this->publicada($f2p, 'MLB-F2P', 'gold_pro', '2026-10-02 10:00:00');

        // Tipo DESCONHECIDO: conta no total, não acende célula nenhuma
        $desconhecido = $this->produto($company, $empresa, 'SKU-DESC', 'Produto de tipo desconhecido');
        $this->publicada($desconhecido, 'MLB-DESC', 'gold_premium', '2026-10-03 10:00:00');

        // Fase FORA de {1,2} (dado antigo): conta no total, não acende célula
        $fase7 = $this->produto($company, $empresa, 'SKU-F7', 'Produto da fase 7', ['fase' => 7, 'quantidade_kit' => 7]);
        $this->publicada($fase7, 'MLB-F7', 'gold_special', '2026-10-04 10:00:00');

        // Produto sem publicação nenhuma: entrada presente, tudo false, total 0
        $semNada = $this->produto($company, $empresa, 'SKU-ZERO', 'Produto sem publicação');

        // Produto de OUTRA empresa pedido junto (T-180-02-I)
        [$outraCompany, $outraEmpresa] = $this->conta('Polo rival');
        $rival = $this->produto($outraCompany, $outraEmpresa, 'SKU-RIVAL', 'Produto do rival');
        $this->publicada($rival, 'MLB-RIVAL', 'gold_special', '2026-10-05 10:00:00');

        $pedidos = [$f1c->id, $f2p->id, $desconhecido->id, $fase7->id, $semNada->id, $rival->id, 999999];
        [$cobertura, $log] = $this->medindo(fn () => $this->servico()->coberturaPorProduto($company, $pedidos));

        $this->assertCount(1, $log, 'coberturaPorProduto tem de ser UMA consulta agregada');
        $this->assertSame($pedidos, array_keys($cobertura), 'todo id pedido recebe entrada, na ordem do pedido');
        foreach ($cobertura as $pid => $celulas) {
            $this->assertSame(
                ['f1_classico', 'f1_premium', 'f2_classico', 'f2_premium', 'total'],
                array_keys($celulas),
                "as chaves do produto {$pid} têm de ser EXATAMENTE as de MapaDeCobertura.jsx",
            );
        }

        $this->assertSame(['f1_classico' => true, 'f1_premium' => false, 'f2_classico' => false, 'f2_premium' => false, 'total' => 1], $cobertura[$f1c->id]);
        $this->assertSame(['f1_classico' => false, 'f1_premium' => false, 'f2_classico' => false, 'f2_premium' => true, 'total' => 1], $cobertura[$f2p->id]);

        // `gold_premium` NÃO existe no ML: conta no total e não cai no Clássico
        // "porque é o mais comum".
        $this->assertSame(['f1_classico' => false, 'f1_premium' => false, 'f2_classico' => false, 'f2_premium' => false, 'total' => 1], $cobertura[$desconhecido->id]);
        $this->assertSame(['f1_classico' => false, 'f1_premium' => false, 'f2_classico' => false, 'f2_premium' => false, 'total' => 1], $cobertura[$fase7->id]);

        // Nada publicado = tudo false com total 0 (medido, não "não sabemos")
        $this->assertSame(['f1_classico' => false, 'f1_premium' => false, 'f2_classico' => false, 'f2_premium' => false, 'total' => 0], $cobertura[$semNada->id]);
        // Produto de outra empresa: o escopo tirou as linhas dele — igual a id inexistente
        $this->assertSame($cobertura[$semNada->id], $cobertura[$rival->id], 'produto de outra empresa não pode trazer cobertura');
        $this->assertSame($cobertura[$semNada->id], $cobertura[999999]);

        $this->assertSoEscalares($cobertura, 'coberturaPorProduto');
    }

    /**
     * Um produto com as QUATRO células e o total somando os quatro itens — e a
     * contagem de consulta não muda com o número de produtos pedidos.
     *
     * @group phase180
     */
    public function test_cobertura_soma_o_total_e_custa_uma_consulta_so(): void
    {
        [$company, $empresa] = $this->conta();

        $base = $this->produto($company, $empresa, 'SKU-BASE', 'Produto base');
        $this->publicada($base, 'MLB-B-C', 'gold_special', '2026-10-01 10:00:00');
        $this->publicada($base, 'MLB-B-P', 'gold_pro', '2026-10-02 10:00:00');

        $kit = $this->produto($company, $empresa, 'SKU-KIT', 'Produto kit', ['fase' => 2, 'quantidade_kit' => 2, 'produto_base_id' => $base->id]);
        $this->publicada($kit, 'MLB-K-C', 'gold_special', '2026-10-03 10:00:00');
        $this->publicada($kit, 'MLB-K-P', 'gold_pro', '2026-10-04 10:00:00');

        [$doisProdutos, $logDois] = $this->medindo(fn () => $this->servico()->coberturaPorProduto($company, [$base->id, $kit->id]));

        $this->assertCount(1, $logDois);
        $this->assertSame(['f1_classico' => true, 'f1_premium' => true, 'f2_classico' => false, 'f2_premium' => false, 'total' => 2], $doisProdutos[$base->id]);
        $this->assertSame(['f1_classico' => false, 'f1_premium' => false, 'f2_classico' => true, 'f2_premium' => true, 'total' => 2], $doisProdutos[$kit->id]);

        // 9 produtos (o resto sem publicação): continua UMA consulta — o custo é do
        // agregado, não do número de produtos.
        $ids = [$base->id, $kit->id];
        for ($n = 3; $n <= 9; $n++) {
            $ids[] = $this->produto($company, $empresa, 'SKU-'.$n, 'Produto '.$n)->id;
        }
        [$noveProdutos, $logNove] = $this->medindo(fn () => $this->servico()->coberturaPorProduto($company, $ids));
        // E DE NOVO: cache de serviço vivo entre chamadas já fez 12 custarem menos que 6 (Fase 178)
        [, $logRepetido] = $this->medindo(fn () => $this->servico()->coberturaPorProduto($company, $ids));

        $this->assertCount(1, $logNove, '9 produtos não podem custar consulta a mais que 2');
        $this->assertCount(1, $logRepetido, 'a segunda chamada custa o mesmo — teto, não cache');
        $this->assertCount(9, $noveProdutos);
        $this->assertSame($doisProdutos[$base->id], $noveProdutos[$base->id], 'pedir mais produtos não muda a cobertura de um deles');
    }

    /**
     * `$produtoIds` vazio ou todo inválido: `[]` sem consultar, e sem lançar.
     *
     * @group phase180
     */
    public function test_cobertura_com_entrada_suja_nao_lanca(): void
    {
        [$company, $empresa] = $this->conta();
        $produto = $this->produto($company, $empresa, 'SKU-OK', 'Produto ok');
        $this->publicada($produto, 'MLB-OK');

        [$sujo, $logSujo] = $this->medindo(fn () => $this->servico()->coberturaPorProduto($company, [null, 'abc']));
        $this->assertSame([], $sujo, 'id inválido não pode virar bind');
        $this->assertCount(0, $logSujo);

        [$vazio, $logVazio] = $this->medindo(fn () => $this->servico()->coberturaPorProduto($company, []));
        $this->assertSame([], $vazio);
        $this->assertCount(0, $logVazio);

        // Mistura: só o id válido sobrevive — e o cenário produz cobertura de verdade
        $misturado = $this->servico()->coberturaPorProduto($company, [null, 'abc', ['x'], 0, -3, $produto->id]);
        $this->assertSame([$produto->id], array_keys($misturado));
        $this->assertSame(1, $misturado[$produto->id]['total']);
    }

    // ═══ Escopo por empresa — os dois sentidos (T-180-02-I) ═════════════════

    /**
     * O caso que a mutação de desagrupamento (`where` -> `orWhere` solto) tem de
     * reprovar: o MESMO `ml_item_id` existe em `pub_publicacao_itens` de duas
     * empresas, e a empresa A só pode receber a metodologia DELA.
     *
     * @group phase180
     */
    public function test_escopo_por_empresa_nao_vaza(): void
    {
        [$companyA, $empresaA] = $this->conta('Polo A');
        [$companyB, $empresaB] = $this->conta('Polo B');

        $produtoA = $this->produto($companyA, $empresaA, 'SKU-A', 'Produto da empresa A');
        $this->publicada($produtoA, 'MLB-DE-A', 'gold_special', '2026-10-01 10:00:00', ['equipe' => true, 'id' => 7, 'nome' => 'Dono do A']);

        // ⚠️ O MESMO ml_item_id na empresa B, mais RECENTE: se o escopo vazar, ele
        // ganha a ordenação e a empresa A passa a ver o produto da B.
        $produtoB = $this->produto($companyB, $empresaB, 'SKU-B', 'Produto da empresa B');
        $this->publicada($produtoB, 'MLB-DE-A', 'gold_special', '2026-12-01 10:00:00', ['equipe' => true, 'id' => 8, 'nome' => 'Dono do B']);
        $this->publicada($produtoB, 'MLB-SO-DE-B', 'gold_special', '2026-12-02 10:00:00');

        $mapa = $this->servico()->metodologiaPorMlItemId($companyA, ['MLB-DE-A', 'MLB-SO-DE-B']);

        $this->assertSame(['MLB-DE-A'], array_keys($mapa), 'o anúncio que só existe na empresa B não pode aparecer para a A');
        $this->assertSame($produtoA->id, $mapa['MLB-DE-A']['produto_id'], 'a metodologia é a do produto DA EMPRESA A');
        $this->assertSame('Produto da empresa A', $mapa['MLB-DE-A']['produto_nome']);
        $this->assertSame('Dono do A', $mapa['MLB-DE-A']['autor_nome'], 'autor da empresa B não pode vazar');

        // Busca, o sentido inverso
        $this->assertSame([], $this->servico()->mlItemIdsPorProduto($companyA, 'empresa B'), 'produto da B não pode ser achado pela busca da A');
        $this->assertSame(['MLB-DE-A'], $this->servico()->mlItemIdsPorProduto($companyA, 'empresa A'));

        // Cobertura
        $cobertura = $this->servico()->coberturaPorProduto($companyA, [$produtoA->id, $produtoB->id]);
        $this->assertSame(1, $cobertura[$produtoA->id]['total']);
        $this->assertSame(0, $cobertura[$produtoB->id]['total'], 'produto da B não pode trazer cobertura na conta da A');
    }

    /**
     * Empresa SEM `MlbEmpresa` nenhuma: o braço da âncora não casa nada e o
     * resultado é o do `company_id` — nada a mais, nada a menos.
     *
     * @group phase180
     */
    public function test_empresa_sem_ancora_mlb_resolve_pelo_company_id(): void
    {
        $company = Company::factory()->create(['name' => 'Empresa sem MlbEmpresa']);
        $this->assertSame(0, MlbEmpresa::where('company_id', $company->id)->count(), 'a premissa do caso: nenhuma âncora');

        $produto = $this->produto($company, null, 'SKU-SO-COMPANY', 'Produto só com company');
        $this->publicada($produto, 'MLB-SO-COMPANY');

        // Produto ancorado SÓ numa MlbEmpresa de OUTRA empresa: não pode entrar
        [$outraCompany, $outraEmpresa] = $this->conta('Polo com âncora');
        $daAncoraAlheia = $this->produto(null, $outraEmpresa, 'SKU-ANCORA', 'Produto da âncora alheia');
        $this->publicada($daAncoraAlheia, 'MLB-ANCORA');

        [$mapa, $log] = $this->medindo(
            fn () => $this->servico()->metodologiaPorMlItemId($company, ['MLB-SO-COMPANY', 'MLB-ANCORA']),
        );

        $this->assertCount(1, $log, 'resolver a âncora não pode custar uma segunda consulta');
        $this->assertSame(['MLB-SO-COMPANY'], array_keys($mapa));
        $this->assertSame($produto->id, $mapa['MLB-SO-COMPANY']['produto_id']);

        // E a âncora, quando existe, continua valendo: produto ancorado só na
        // MlbEmpresa da PRÓPRIA empresa entra.
        $soAncora = $this->produto(null, $outraEmpresa, 'SKU-SO-ANCORA', 'Produto só pela âncora');
        $this->publicada($soAncora, 'MLB-PELA-ANCORA');
        $this->assertSame(
            ['MLB-PELA-ANCORA'],
            array_keys($this->servico()->metodologiaPorMlItemId($outraCompany, ['MLB-PELA-ANCORA'])),
            'o braço da âncora tem de continuar achando o produto sem company_id',
        );
    }

    /**
     * ⚠️ O gate que a prescrição do plano teria quebrado: o nome do CLIENTE está
     * gravado em `pub_publicacoes.ator` e não pode sair do servidor (T-173-09).
     *
     * @group phase180
     */
    public function test_nome_de_cliente_nunca_sai_no_payload(): void
    {
        [$company, $empresa] = $this->conta();
        $produto = $this->produto($company, $empresa, 'SKU-CLI', 'Produto publicado pelo cliente');
        $this->publicada($produto, 'MLB-CLI', 'gold_special', '2026-10-01 10:00:00', [
            'equipe' => false, 'id' => 404, 'nome' => 'Cliente Confidencial Ltda',
        ]);

        // A premissa: o nome ESTÁ no banco (senão a negativa é vazia por construção)
        $this->assertStringContainsString(
            'Cliente Confidencial Ltda',
            (string) DB::table('pub_publicacoes')->value('ator'),
            'o cenário precisa do nome do cliente GRAVADO para a negativa valer',
        );

        $mapa = $this->servico()->metodologiaPorMlItemId($company, ['MLB-CLI']);

        $this->assertSame(PainelVisaoGeralService::QUEM_CLIENTE, $mapa['MLB-CLI']['autor_nome']);
        $this->assertStringNotContainsString(
            'Cliente Confidencial Ltda',
            json_encode($mapa, JSON_UNESCAPED_UNICODE),
            'nome de cliente não sai do servidor (T-173-09)',
        );
        $this->assertStringNotContainsString('404', (string) $mapa['MLB-CLI']['autor_nome'], 'id de cliente não sai do servidor');
    }
}
