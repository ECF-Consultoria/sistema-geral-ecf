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
}
