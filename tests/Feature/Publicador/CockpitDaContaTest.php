<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\MlAcervoItem;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\MlToken;
use App\Models\PubImagem;
use App\Models\PubImagemAtribuicao;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\PubValidacao;
use App\Services\Publicador\AcervoTriagemService;
use App\Services\Publicador\CabecalhoPublicadorService;
use App\Services\Publicador\CockpitDaContaService;
use App\Services\Publicador\PainelVisaoGeralService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 179, plano 03 — o CONTRATO do servidor dos cinco blocos do cockpit que
 * não são a Jornada nem as Ações: banner de integração, Saúde da conta,
 * Publicações no Mercado Livre (com o acervo de imagens), a faixa de Desempenho
 * e o par Publicações recentes + Atividade recente.
 *
 * ═══ O que este arquivo guarda (e uma asserção isolada não guardaria) ════════
 *
 * 1. **A SOMA DA BARRA FECHA.** As quatro faixas mais o resíduo `outras` são
 *    EXATAMENTE o `total`, que é o número do contador do header — em três
 *    distribuições diferentes de status, uma delas com `status` fora dos quatro
 *    conhecidos. Cobrado por IGUALDADE, nunca por "menor que": se a tela e o
 *    header discordarem sobre a mesma conta, reprova aqui (T-179-11).
 * 2. **`null` × `0`, OS DOIS LADOS JUNTOS.** Só o par prova algo (lição da
 *    177-03): acervo nunca coletado devolve `total === null`; acervo coletado e
 *    vazio devolve `total === 0`. E o retorno inteiro é varrido: nenhum `0` onde
 *    o indicador de entrada era `null`.
 * 3. **O ERP é SÓ declarado.** Asserção NEGATIVA das três chaves de conexão
 *    (`conectado`, `sincronizado_em`, `atualizado_em`) e varredura do JSON pelo
 *    literal `Conectado` na linha do ERP — ele não é integração neste sistema, e
 *    nunca puxa o status geral para baixo.
 * 4. **Vendas, visitas e conversão NÃO EXISTEM no retorno**, por asserção
 *    negativa rodada sobre dado que as PRODUZIRIA (acervo com `sold_quantity`
 *    gravado). Negativa de propósito: a positiva deixaria uma fase futura
 *    acrescentar a chave e o teste seguir verde.
 * 5. **Nenhum rótulo de tela no payload** — chaves negadas (`rotulo`, `label`,
 *    `cor`, `color`…) e varredura do JSON pelos literais do protótipo (`Tudo`,
 *    `HOJE`, `ONTEM`, `Atualizar`, `Reconectar`, `Tudo conectado`, `Requer
 *    ação`). Evitar jargão na tela é regra permanente deste projeto; rótulo no
 *    payload é o jeito de perdê-la de vista.
 * 6. **D23 varrido:** `Company` nula nos seis métodos — nada lança, tudo honesto.
 * 7. **Todo campo é escalar**, com array permitido só nos contêineres nomeados
 *    (`faixas`, `linhas`, `kpis`, `serie`, `itens`, `grupos`, `eventos`) e em
 *    `quem`, que JÁ atravessa hoje em produção como `{tipo, nome}`. Foi um
 *    campo-objeto renderizado como texto que derrubou a página inteira em
 *    07/10/2026.
 * 8. **CUSTO.** Os seis métodos juntos custam menos de 10 consultas, e o MESMO
 *    número com 12 e com 24 produtos — é a estabilidade que pega N+1, não o
 *    teto. ⚠️ **Com isca:** o mesmo teste mede um trecho que SABEMOS que
 *    consulta (`produtosParaTela()`) e exige `> 0`; sem a isca, um
 *    `enableQueryLog()` mal posicionado daria zero e o teto passaria por
 *    construção.
 * 9. **Nenhum segredo da conta no payload:** nem `access_token`, nem
 *    `refresh_token`, nem `APP_USR`/`TG-`.
 *
 * ⚠️ Nenhum teste aqui chama o Mercado Livre: `Http::fake()` sem handler
 * intercepta tudo. Este contrato só lê o que já está gravado.
 *
 * ⚠️ Os testes rodam em SQLite; produção é MySQL 8 e o dev local é MariaDB 10.4.
 * Este plano não cria migration (é tudo leitura).
 *
 * @group phase179
 */
class CockpitDaContaTest extends TestCase
{
    use RefreshDatabase;

    /** As chaves da Saúde, em ORDEM. */
    private const CAMPOS_DA_SAUDE = ['geral', 'linhas'];

    /** As três linhas da Saúde, em ORDEM FIXA. */
    private const ORDEM_DAS_LINHAS = ['mercado_livre', 'erp', 'sincronizacao'];

    /** As quatro faixas da barra, em ORDEM FIXA. */
    private const ORDEM_DAS_FAIXAS = ['ativas', 'pausadas', 'em_revisao', 'encerradas'];

    /** As chaves do item de "Publicações recentes", em ORDEM. Campo a mais aqui é convite a inventar tela na wave 3. */
    private const CAMPOS_DO_RECENTE = ['produto_id', 'produto_nome', 'titulo', 'capa', 'fase', 'rotulo_fase',
        'tipo', 'ml_item_id', 'situacao', 'vendas', 'quando', 'quem', 'nova'];

    /**
     * Os ÚNICOS lugares onde array é esperado no payload. Fora deles, todo campo
     * é escalar — `quem` entra na lista porque `{tipo, nome}` JÁ atravessa hoje em
     * produção e o cliente a lê campo a campo (`quem.nome ?? quem.tipo`).
     */
    private const CONTEINERES = ['banner', 'saude', 'publicacoes_ml', 'acervo_imagens', 'desempenho', 'recentes',
        'atividade', 'linhas', 'faixas', 'pedem_acao', 'kpis', 'serie', 'itens', 'grupos', 'eventos', 'quem'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    /**
     * Uma conta de Polos com token ativo. Sem Company (D23 — 535 de 539
     * `MlbEmpresa` não têm Company) o token fica na própria `MlbEmpresa`.
     *
     * @return array{0: MlbEmpresa, 1: ?Company}
     */
    private function conta(bool $comCompany = true, string $nome = 'Polo do Cockpit', string $tokenStatus = 'active'): array
    {
        $company = $comCompany ? Company::factory()->create(['name' => $nome]) : null;
        $empresa = MlbEmpresa::create(['nome' => $nome, 'projeto' => 'POLOS', 'company_id' => $company?->id]);
        MlToken::create([
            'company_id' => $company?->id,
            'mlb_empresa_id' => $company === null ? $empresa->id : null,
            'ml_user_id' => '1555596317',
            'access_token' => 'APP_USR-segredo',
            'refresh_token' => 'TG-segredo',
            // `expired` = o caso real das tardes: ainda ativo, só vencido; `revoked` = desconectado de verdade.
            'expires_at' => $tokenStatus === 'active' ? now()->addHours(5) : now()->subHours(2),
            'status' => $tokenStatus === 'revoked' ? 'revoked' : 'active',
        ]);

        return [$empresa->fresh(), $company];
    }

    /** Uma conta SEM token nenhum — o caso `sem_token` de `empresaParaTela()`. */
    private function contaSemToken(string $nome = 'Polo sem token'): array
    {
        $company = Company::factory()->create(['name' => $nome]);
        $empresa = MlbEmpresa::create(['nome' => $nome, 'projeto' => 'POLOS', 'company_id' => $company->id]);

        return [$empresa->fresh(), $company];
    }

    private function erpDeclarado(MlbEmpresa $e, string $valor): void
    {
        MlbImplementacao::create([
            'empresa_id' => $e->id,
            'token' => (string) Str::uuid(),
            'dados' => ['itens' => ['erp' => ['valor' => $valor, 'outro' => '', 'acesso' => '', 'feito' => true]]],
        ]);
    }

    private function acervo(Company $c, string $mlItemId, string $status = 'active', int $vendidos = 0, array $extra = []): MlAcervoItem
    {
        return MlAcervoItem::create(array_merge([
            'company_id' => $c->id,
            'ml_item_id' => $mlItemId,
            'title' => 'Item '.$mlItemId,
            'status' => $status,
            'available_quantity' => 10,
            'sold_quantity' => $vendidos,
            'nota_ecf' => 60,
            'motivos' => [],
            'severidade' => MlAcervoItem::SEVERIDADE_SAUDAVEL,
            'origem' => MlAcervoItem::ORIGEM_LEGADO,
            'coletado_em' => now(),
        ], $extra));
    }

    private function produto(MlbEmpresa $e, ?Company $c, string $sku, array $extra = []): PubProduto
    {
        return PubProduto::create($extra + [
            'mlb_empresa_id' => $e->id,
            'company_id' => $c?->id,
            'sku' => $sku,
            'nome' => 'Cadeira '.$sku,
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);
    }

    /**
     * Uma publicação CONCLUÍDA do produto, na data pedida — a fonte do KPI
     * `publicacoes_criadas`. Um rascunho por publicação (é o que o editor faz a
     * cada revisão).
     */
    private function publicacao(PubProduto $p, Carbon|string $concluidaEm, string $status = PubPublicacao::PUBLISHED): PubPublicacao
    {
        // ⚠️ `pub_rascunhos.produto_id` é UNIQUE (um rascunho por produto): duas
        // publicações do mesmo produto são REVISÕES do mesmo rascunho, como no
        // editor — criar um rascunho por publicação viola a restrição real.
        $rascunho = PubRascunho::firstOrCreate(
            ['produto_id' => $p->id],
            ['status' => PubRascunho::PUBLISHED, 'revisao' => 1],
        );

        return PubPublicacao::create([
            'rascunho_id' => $rascunho->id,
            'revisao' => PubPublicacao::where('rascunho_id', $rascunho->id)->count() + 1,
            'modelo_publicacao' => 'items',
            'status' => $status,
            'chave_idempotencia' => (string) Str::uuid(),
            'concluida_em' => $concluidaEm,
            'ator' => ['equipe' => true, 'id' => 1, 'nome' => 'Dev'],
        ]);
    }

    /** Um kit de criativo da conta, na data pedida — a fonte do KPI `criativos_ia`. */
    private function kitDeCriativo(MlbEmpresa $e, ?Company $c, Carbon|string $criadoEm, int $imagens = 3): MlAnuncioCriativoKit
    {
        $kit = MlAnuncioCriativoKit::create([
            'token' => Str::random(32),
            'company_id' => $c?->id,
            'mlb_empresa_id' => $e->id,
            'status' => MlAnuncioCriativoKit::STATUS_PRONTO,
            'imagens_geradas' => $imagens,
        ]);
        // `created_at` é carimbo automático: a data do cenário entra por update.
        $kit->forceFill(['created_at' => $criadoEm])->save();

        return $kit->fresh();
    }


    /**
     * Uma publicação CONCLUÍDA **com anúncio criado** — é o item CREATED que
     * `ultimasPublicacoes()` e a Atividade leem.
     */
    private function publicada(PubProduto $p, Carbon|string $quando, string $listingType = 'gold_special'): PubPublicacao
    {
        $publicacao = $this->publicacao($p, $quando);
        PubPublicacaoItem::create([
            'publicacao_id' => $publicacao->id,
            'indice' => 0,
            'listing_type_id' => $listingType,
            'variante_chave' => ChaveCanonica::UNICA,
            'status' => PubPublicacaoItem::CREATED,
            'ml_item_id' => 'MLB'.(9000000 + $publicacao->id),
            'payload' => ['family_name' => 'Anúncio de '.$p->nome],
        ]);

        return $publicacao;
    }

    /** Um evento no shape EXATO de `atividadeDaEquipe()` — tudo escalar. */
    private function evento(string $tipo, Carbon|string|null $quando, string $titulo = 'Cadeira Oslo'): array
    {
        return [
            'tipo' => $tipo,
            'quem' => 'Ana Ribeiro',
            'quando_iso' => $quando === null ? null : Carbon::parse($quando)->toIso8601String(),
            'titulo' => $titulo,
            'detalhe' => 'Fase 1 · 1 unidade',
        ];
    }

    /** O `$alvo` sem nenhuma âncora (D23 no limite) — a 4ª fonte não tem o que ler. */
    private function semAncora(): array
    {
        return ['mlb_empresa' => null, 'company' => null, 'programa' => 'gestao', 'chave' => null];
    }

    /** As publicações recentes da conta, com a lista de produtos real. */
    private function recentes(MlbEmpresa $e, ?Company $c): array
    {
        $alvo = $this->alvo($e);

        return $this->servico()->recentes(
            $this->painel()->ultimasPublicacoes($alvo),
            $this->programas()->produtosParaTela($e, $c, $alvo['chave']),
        );
    }

    /** Todos os eventos da atividade, achatados na ordem dos grupos. */
    private function eventos(array $atividade): array
    {
        $eventos = [];
        foreach ($atividade['grupos'] as $grupo) {
            foreach ($grupo['eventos'] as $evento) {
                $eventos[] = $evento;
            }
        }

        return $eventos;
    }

    /**
     * Os SETE blocos do cockpit sobre um cenário cheio — a base das varreduras.
     *
     * ⚠️ As asserções de não-vacuidade ficam AQUI, não em cada teste: varrer um
     * payload vazio por rótulo, objeto ou segredo passaria por construção.
     */
    private function cockpitCompleto(): array
    {
        // Revogado = o problema do ML de verdade (o vencido renova sozinho desde 20/10/2026): é ele que dá banner.
        [$e, $c] = $this->conta(tokenStatus: 'revoked', nome: 'Conta cheia');
        $this->erpDeclarado($e, 'Bling');
        foreach (range(1, 4) as $i) {
            $this->acervo($c, 'MLBX'.$i, $i === 2 ? 'paused' : ($i === 3 ? 'under_review' : 'active'), $i, $i === 1 ? [
                'severidade' => MlAcervoItem::SEVERIDADE_CRITICA,
                'motivos' => [MlAcervoItem::MOTIVO_PAUSADO],
            ] : []);
        }
        $produto = $this->produto($e, $c, 'SKU-CHEIA');
        $this->publicada($produto, now()->subHours(2));
        $this->kitDeCriativo($e, $c, now()->subHours(4));
        MlAnuncioCriativo::create([
            'token' => Str::random(32), 'company_id' => $c->id, 'mlb_empresa_id' => $e->id,
            'slot' => 'hero', 'slot_indice' => 0, 'imagem_path' => 'criativos/cheia.jpg',
            'status' => MlAnuncioCriativo::STATUS_PENDENTE,
        ]);

        $alvo = $this->alvo($e);
        $empresa = $this->programas()->empresaParaTela($alvo);
        $produtos = $this->programas()->produtosParaTela($e, $c, $alvo['chave']);
        $indicadores = $this->painel()->indicadores($alvo, $this->programas()->contagemProdutos($produtos), $produtos);
        $defasagem = $this->defasagem($c);
        $servico = $this->servico();

        $cockpit = [
            'banner' => $servico->banner($empresa),
            'saude' => $servico->saude($empresa, $this->painel()->integracoes($alvo, $empresa), $defasagem, $c->id),
            'publicacoes_ml' => $servico->resumoDePublicacoes($c, app(CabecalhoPublicadorService::class)->contadores($alvo, $defasagem)),
            'acervo_imagens' => $servico->acervoDeImagens($alvo, $indicadores),
            'desempenho' => $servico->desempenho($alvo, $indicadores),
            'recentes' => $servico->recentes($this->painel()->ultimasPublicacoes($alvo), $produtos),
            'atividade' => $servico->atividade($alvo, $this->painel()->atividadeDaEquipe($alvo)),
        ];

        // Não-vacuidade: cada bloco tem conteúdo de verdade.
        $this->assertNotNull($cockpit['banner']);
        $this->assertCount(3, $cockpit['saude']['linhas']);
        $this->assertTrue($cockpit['publicacoes_ml']['disponivel']);
        $this->assertSame(4, $cockpit['publicacoes_ml']['total']);
        $this->assertArrayHasKey('pedem_acao', $cockpit['publicacoes_ml']);
        $this->assertSame(1, $cockpit['acervo_imagens']['imagens']);
        $this->assertCount(30, $this->kpi($cockpit['desempenho'], 'publicacoes_criadas')['serie']);
        $this->assertNotSame([], $cockpit['recentes']['itens']);
        $this->assertNotSame([], $cockpit['atividade']['grupos']);

        return $cockpit;
    }

    /** Quantas consultas os métodos do cockpit fazem — e SÓ eles. */
    private function consultasDoCockpit(MlbEmpresa $e, ?Company $c): int
    {
        // Tudo o que o CONTROLLER já calcula fica fora da medição, porque é o que
        // acontece em produção: estes argumentos chegam prontos.
        $alvo = $this->alvo($e);
        $empresa = $this->programas()->empresaParaTela($alvo);
        $integracoes = $this->painel()->integracoes($alvo, $empresa);
        $defasagem = $this->defasagem($c);
        $contadores = app(CabecalhoPublicadorService::class)->contadores($alvo, $defasagem);
        $produtos = $this->programas()->produtosParaTela($e, $c, $alvo['chave']);
        $indicadores = $this->painel()->indicadores($alvo, $this->programas()->contagemProdutos($produtos), $produtos);
        $ultimas = $this->painel()->ultimasPublicacoes($alvo);
        $daEquipe = $this->painel()->atividadeDaEquipe($alvo);
        $servico = $this->servico();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $servico->banner($empresa);
        $servico->saude($empresa, $integracoes, $defasagem, $c?->id);
        $servico->resumoDePublicacoes($c, $contadores);
        $servico->acervoDeImagens($alvo, $indicadores);
        $servico->desempenho($alvo, $indicadores);
        $servico->recentes($ultimas, $produtos);
        $servico->atividade($alvo, $daEquipe);
        $quantas = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $quantas;
    }

    /**
     * Varredura recursiva: nenhum campo é objeto, e array só nos contêineres
     * NOMEADOS. Foi um campo-objeto renderizado como texto que derrubou a página
     * inteira em 07/10/2026 ("Objects are not valid as a React child").
     */
    private function varrerEscalares(array $payload, string $caminho): void
    {
        foreach ($payload as $chave => $valor) {
            $onde = $caminho === '' ? (string) $chave : $caminho.'.'.$chave;
            $this->assertFalse(is_object($valor), "objeto em `{$onde}` — nenhum campo do payload pode ser objeto");
            if (is_array($valor)) {
                $this->assertTrue(
                    is_int($chave) || in_array((string) $chave, self::CONTEINERES, true),
                    "array em `{$onde}`, que não é contêiner nomeado",
                );
                $this->varrerEscalares($valor, $onde);

                continue;
            }
            $this->assertTrue($valor === null || is_scalar($valor), "campo `{$onde}` não é escalar");
        }
    }

    /** Varredura recursiva por uma chave de TELA que não pode existir no payload. */
    private function varrerChaveAusente(array $payload, string $proibida, string $caminho): void
    {
        foreach ($payload as $chave => $valor) {
            $onde = $caminho === '' ? (string) $chave : $caminho.'.'.$chave;
            $this->assertNotSame($proibida, (string) $chave, "a chave de tela `{$proibida}` apareceu em `{$onde}`");
            if (is_array($valor)) {
                $this->varrerChaveAusente($valor, $proibida, $onde);
            }
        }
    }

    private function programas(): ProgramasPublicadorService
    {
        return app(ProgramasPublicadorService::class);
    }

    private function painel(): PainelVisaoGeralService
    {
        return app(PainelVisaoGeralService::class);
    }

    private function servico(): CockpitDaContaService
    {
        return app(CockpitDaContaService::class);
    }

    /** O `$alvo` canônico da conta, do MESMO `resolver()` que o controller usa. */
    private function alvo(MlbEmpresa $e): array
    {
        return $this->programas()->resolver('empresa-'.$e->id);
    }

    private function empresa(MlbEmpresa $e): array
    {
        return $this->programas()->empresaParaTela($this->alvo($e));
    }

    /** As integrações REAIS (é daqui que sai `erp.valor`). */
    private function integracoes(MlbEmpresa $e): array
    {
        $alvo = $this->alvo($e);

        return $this->painel()->integracoes($alvo, $this->programas()->empresaParaTela($alvo));
    }

    private function defasagem(?Company $c): array
    {
        return $c === null
            ? ['coletado_em' => null, 'horas' => null, 'defasado' => false, 'nunca_coletado' => true, 'motivo' => null]
            : app(AcervoTriagemService::class)->defasagem($c);
    }

    /** Os contadores REAIS do header — a fonte única do `total` da barra. */
    private function contadores(MlbEmpresa $e): array
    {
        return app(CabecalhoPublicadorService::class)->contadores($this->alvo($e));
    }

    /** A Saúde montada com as entradas reais da conta. */
    private function saude(MlbEmpresa $e, ?Company $c): array
    {
        return $this->servico()->saude($this->empresa($e), $this->integracoes($e), $this->defasagem($c), $c?->id);
    }

    /** O resumo de publicações montado com o contador real do header. */
    private function resumo(MlbEmpresa $e, ?Company $c): array
    {
        return $this->servico()->resumoDePublicacoes($c, $this->contadores($e));
    }

    /** A faixa de Desempenho da conta, com os indicadores reais do painel. */
    private function desempenho(MlbEmpresa $e): array
    {
        $alvo = $this->alvo($e);

        return $this->servico()->desempenho($alvo, $this->painel()->indicadores($alvo, [], []));
    }

    private function kpi(array $desempenho, string $chave): array
    {
        foreach ($desempenho['kpis'] as $kpi) {
            if ($kpi['chave'] === $chave) {
                return $kpi;
            }
        }
        $this->fail("o KPI '{$chave}' não veio na faixa de Desempenho");
    }

    private function linha(array $saude, string $chave): array
    {
        foreach ($saude['linhas'] as $linha) {
            if ($linha['chave'] === $chave) {
                return $linha;
            }
        }
        $this->fail("a linha '{$chave}' não veio na Saúde da conta");
    }

    /** chave => total das faixas, para comparar a barra de uma vez. */
    private function faixas(array $resumo): array
    {
        return array_combine(array_column($resumo['faixas'], 'chave'), array_column($resumo['faixas'], 'total'));
    }

    /**
     * ⚠️ O GATE CENTRAL da barra: as quatro faixas MAIS o resíduo são
     * EXATAMENTE o total do header. Igualdade, nunca "menor que".
     */
    private function assertBarraFecha(array $resumo): void
    {
        $this->assertSame(
            $resumo['total'],
            array_sum(array_column($resumo['faixas'], 'total')) + $resumo['outras'],
            'a soma das 4 faixas + o resíduo deixou de ser o total do header — a tela e o header discordariam da mesma conta',
        );
    }

    // ═══ 1. Banner de integração (VG-02) ═════════════════════════════════════

    public function test_token_ativo_e_erp_declarado_nao_tem_banner_e_a_saude_esta_toda_conectada(): void
    {
        [$e, $c] = $this->conta();
        $this->erpDeclarado($e, 'Bling');
        $this->acervo($c, 'MLB1');

        $this->assertNull($this->servico()->banner($this->empresa($e)), 'conta conectada não tem banner');

        $saude = $this->saude($e, $c);
        $this->assertSame(self::CAMPOS_DA_SAUDE, array_keys($saude));
        $this->assertSame('tudo_conectado', $saude['geral']);
        $this->assertSame(self::ORDEM_DAS_LINHAS, array_column($saude['linhas'], 'chave'));
        $this->assertSame('Bling', $this->linha($saude, 'erp')['declarado']);
        $this->assertFalse($this->linha($saude, 'mercado_livre')['problema']);
    }

    public function test_token_vencido_renova_sozinho_e_so_o_revogado_abre_o_banner_do_ml(): void
    {
        // 20/10/2026: vencido (o caso de toda tarde) não é desconectado — a 1ª chamada renova.
        [$e, $c] = $this->conta(tokenStatus: 'expired');
        $this->assertNull($this->servico()->banner($this->empresa($e)));
        $this->assertSame('ativo', $this->linha($this->saude($e, $c), 'mercado_livre')['estado']);
        $this->assertFalse($this->linha($this->saude($e, $c), 'mercado_livre')['problema']);

        MlToken::query()->update(['status' => 'revoked']);
        $this->erpDeclarado($e, 'Bling');
        $this->acervo($c, 'MLB1');
        // O link de reconexão REAL é o da ficha de implementação da empresa.
        $empresa = $this->empresa($e);

        $banner = $this->servico()->banner($empresa);
        $this->assertSame(['chave', 'link_reconexao'], array_keys($banner));
        $this->assertSame('ml', $banner['chave']);
        $this->assertNotNull($banner['link_reconexao'], 'a ficha existe, então o link de reconexão é real');
        $this->assertSame($empresa['link_reconexao'], $banner['link_reconexao']);

        $saude = $this->saude($e, $c);
        $this->assertSame('requer_acao', $saude['geral']);
        $linha = $this->linha($saude, 'mercado_livre');
        $this->assertSame('sem_token', $linha['estado']);
        $this->assertTrue($linha['problema']);
        $this->assertSame($empresa['link_reconexao'], $linha['link_reconexao']);
    }

    public function test_sem_token_abre_o_mesmo_banner_e_a_linha_do_ml_nunca_traz_prazo_de_renovacao(): void
    {
        [$e, $c] = $this->contaSemToken();
        $this->acervo($c, 'MLB1');

        $banner = $this->servico()->banner($this->empresa($e));
        $this->assertSame('ml', $banner['chave'], 'o banner é o mesmo: a chave é a integração, não o motivo');

        $linha = $this->linha($this->saude($e, $c), 'mercado_livre');
        $this->assertSame('sem_token', $linha['estado']);
        $this->assertTrue($linha['problema']);
        // ⚠️ O contrato NÃO sabe quando o token renova — "renova em 4 h" do
        // protótipo seria número inventado (EXMP-01).
        foreach (['renova_em', 'expira_em', 'expires_at', 'prazo', 'renovacao'] as $inventada) {
            $this->assertArrayNotHasKey($inventada, $linha, "a linha do ML não pode prometer prazo ({$inventada})");
        }
    }

    public function test_erp_nao_informado_nao_tem_problema_e_nunca_vira_banner(): void
    {
        [$e, $c] = $this->conta();
        $this->acervo($c, 'MLB1');
        // Nenhuma ficha de implementação: o ERP não foi declarado.

        $saude = $this->saude($e, $c);
        $erp = $this->linha($saude, 'erp');
        $this->assertNull($erp['declarado'], 'ERP não informado é null, nunca "Não informado" (o rótulo é do cliente)');
        $this->assertFalse($erp['problema'], 'não se acusa problema em algo que não é medido');

        // ⚠️ O caso "ERP desconectado" NÃO EXISTE neste sistema: o banner só tem
        // o caso alcançável (o ML). Com o ML ativo, não há banner nenhum.
        $this->assertNull($this->servico()->banner($this->empresa($e)));
        $this->assertSame('tudo_conectado', $saude['geral'], 'o ERP nunca puxa o status geral para baixo');
    }

    public function test_a_linha_do_erp_nao_tem_nenhuma_chave_de_conexao(): void
    {
        [$e, $c] = $this->conta();
        $this->erpDeclarado($e, 'Bling');
        $this->acervo($c, 'MLB1');

        $erp = $this->linha($this->saude($e, $c), 'erp');

        // Asserção NEGATIVA, com a mesma lista da Fase 177-03.
        $this->assertSame(['chave', 'declarado', 'problema'], array_keys($erp));
        $this->assertArrayNotHasKey('conectado', $erp);
        $this->assertArrayNotHasKey('sincronizado_em', $erp);
        $this->assertArrayNotHasKey('atualizado_em', $erp);
        // E o literal da tela não pode ter vazado para dentro da linha.
        $this->assertStringNotContainsStringIgnoringCase(
            'conectado',
            json_encode($erp, JSON_UNESCAPED_UNICODE),
            'a linha do ERP não pode conter o literal "Conectado" — ele não é medido',
        );
    }

    public function test_o_erp_declarado_nunca_puxa_o_geral_e_a_defasagem_da_coleta_puxa(): void
    {
        [$e, $c] = $this->conta();
        // Sem ERP declarado E com a coleta defasada: o âmbar é da COLETA, que é
        // estado medido — divergência declarada contra o protótipo, que põe o ERP
        // como o caso âmbar da tela.
        $this->acervo($c, 'MLB1', 'active', 0, ['coletado_em' => now()->subHours(72)]);

        $saude = $this->saude($e, $c);
        $this->assertSame('atencao', $saude['geral']);
        $sinc = $this->linha($saude, 'sincronizacao');
        $this->assertTrue($sinc['defasado']);
        $this->assertFalse($sinc['nunca_coletado']);
        $this->assertFalse($this->linha($saude, 'erp')['problema']);
    }

    public function test_o_problema_do_ml_vence_a_defasagem_no_status_geral(): void
    {
        [$e, $c] = $this->conta(tokenStatus: 'revoked');
        $this->acervo($c, 'MLB1', 'active', 0, ['coletado_em' => now()->subHours(72)]);

        $this->assertSame('requer_acao', $this->saude($e, $c)['geral']);
    }

    // ═══ 2. A linha de Sincronização ═════════════════════════════════════════

    public function test_acervo_nunca_coletado_diz_nunca_coletado_e_nao_finge_uma_hora(): void
    {
        [$e, $c] = $this->conta();
        // Nenhuma linha de acervo: NUNCA foi coletado.

        $saude = $this->saude($e, $c);
        $sinc = $this->linha($saude, 'sincronizacao');

        $this->assertTrue($sinc['nunca_coletado']);
        $this->assertNull($sinc['coletado_em'], '"há 0 min" seria mentira: nunca medimos');
        $this->assertNull($sinc['horas']);
        $this->assertFalse($sinc['defasado'], 'defasado é sobre a ÚLTIMA coleta; sem coleta não há atraso a medir');
        $this->assertSame('atencao', $saude['geral']);
        // A rota é a que JÁ existe e ENFILEIRA o job (D-05/REG-06, T-179-15).
        $this->assertSame(route('mlb.anuncios.meus.atualizar', ['company' => $c->id]), $sinc['atualizar_url']);
    }

    public function test_acervo_coletado_traz_iso_e_horas(): void
    {
        [$e, $c] = $this->conta();
        $this->acervo($c, 'MLB1', 'active', 0, ['coletado_em' => now()->subHours(3)]);

        $sinc = $this->linha($this->saude($e, $c), 'sincronizacao');

        $this->assertFalse($sinc['nunca_coletado']);
        $this->assertNotNull($sinc['coletado_em']);
        $this->assertSame($sinc['coletado_em'], (string) $sinc['coletado_em'], 'ISO é texto, nunca objeto');
        $this->assertIsInt($sinc['horas']);
        $this->assertSame(3, $sinc['horas']);
        $this->assertFalse($sinc['defasado'], '3 h está dentro do limite de 24 h de config/mlb_acervo.php');
    }

    public function test_sem_company_a_linha_de_sincronizacao_nao_tem_destino(): void
    {
        [$e, $c] = $this->conta(comCompany: false);
        $this->assertNull($c);

        $sinc = $this->linha($this->saude($e, null), 'sincronizacao');
        $this->assertNull($sinc['atualizar_url'], 'D23: sem Company a rota não pode ser montada — nunca um link quebrado');
        $this->assertTrue($sinc['nunca_coletado']);
    }

    // ═══ 3. Publicações no Mercado Livre (VG-14) ═════════════════════════════

    public function test_as_quatro_faixas_somam_o_total_do_header(): void
    {
        [$e, $c] = $this->conta();
        foreach (range(1, 49) as $i) {
            $this->acervo($c, 'MLBA'.$i, 'active');
        }
        foreach (range(1, 7) as $i) {
            $this->acervo($c, 'MLBP'.$i, 'paused');
        }
        foreach (range(1, 2) as $i) {
            $this->acervo($c, 'MLBR'.$i, 'under_review');
        }
        foreach (range(1, 3) as $i) {
            $this->acervo($c, 'MLBC'.$i, 'closed');
        }

        $resumo = $this->resumo($e, $c);

        $this->assertTrue($resumo['disponivel']);
        $this->assertSame(61, $resumo['total'], 'o total é o contador do header, fonte única');
        $this->assertSame(self::ORDEM_DAS_FAIXAS, array_column($resumo['faixas'], 'chave'));
        $this->assertSame(['ativas' => 49, 'pausadas' => 7, 'em_revisao' => 2, 'encerradas' => 3], $this->faixas($resumo));
        $this->assertSame(0, $resumo['outras'], 'sem status estranho no banco, o resíduo é zero');
        $this->assertBarraFecha($resumo);
        $this->assertSame(80, $resumo['ativas_pct'], 'round(49/61*100)');
    }

    public function test_a_soma_da_barra_fecha_em_tres_distribuicoes_inclusive_com_status_desconhecido(): void
    {
        // (a) só ativos
        [$e1, $c1] = $this->conta(nome: 'Conta A');
        foreach (range(1, 4) as $i) {
            $this->acervo($c1, 'MLBA'.$i, 'active');
        }
        $this->assertBarraFecha($this->resumo($e1, $c1));

        // (b) com encerrados e em revisão
        [$e2, $c2] = $this->conta(nome: 'Conta B');
        $this->acervo($c2, 'MLBB1', 'closed');
        $this->acervo($c2, 'MLBB2', 'closed');
        $this->acervo($c2, 'MLBB3', 'under_review');
        $this->acervo($c2, 'MLBB4', 'paused');
        $resumoB = $this->resumo($e2, $c2);
        $this->assertSame(['ativas' => 0, 'pausadas' => 1, 'em_revisao' => 1, 'encerradas' => 2], $this->faixas($resumoB));
        $this->assertSame(0, $resumoB['ativas_pct'], 'nenhuma ativa com 4 anúncios é 0% — medido, não ausente');
        $this->assertBarraFecha($resumoB);

        // (c) um status FORA dos quatro conhecidos: ele TEM de aparecer no resíduo.
        [$e3, $c3] = $this->conta(nome: 'Conta C');
        $this->acervo($c3, 'MLBC1', 'active');
        $this->acervo($c3, 'MLBC2', 'payment_required');
        $resumoC = $this->resumo($e3, $c3);
        $this->assertSame(2, $resumoC['total']);
        $this->assertSame(1, $resumoC['outras'], 'status fora dos quatro aparece no resíduo em vez de desaparecer');
        $this->assertBarraFecha($resumoC);
    }

    public function test_cada_faixa_leva_a_publicacoes_com_um_filtro_que_a_action_meus_aceita(): void
    {
        [$e, $c] = $this->conta();
        $this->acervo($c, 'MLB1');

        $resumo = $this->resumo($e, $c);

        // A lista FECHADA da action (`MlbAnuncioController::meus()`): valor fora
        // dela cairia no default `acionaveis` e a faixa levaria ao recorte errado.
        $aceitos = ['acionaveis', 'ativos', 'pausados', 'em_revisao', 'encerrados', 'todos'];
        $esperados = ['ativas' => 'ativos', 'pausadas' => 'pausados', 'em_revisao' => 'em_revisao', 'encerradas' => 'encerrados'];
        foreach ($resumo['faixas'] as $faixa) {
            $filtro = $esperados[$faixa['chave']];
            $this->assertContains($filtro, $aceitos);
            $this->assertSame(
                route('mlb.anuncios.meus', ['company' => $c->id, 'status' => $filtro]),
                $faixa['url'],
                "a faixa {$faixa['chave']} tem de apontar para status={$filtro}",
            );
        }

        $this->assertSame(
            route('mlb.anuncios.meus', ['company' => $c->id, 'status' => 'acionaveis']),
            $resumo['pedem_acao']['url'],
        );
    }

    public function test_contador_nulo_deixa_o_resumo_indisponivel_sem_nenhum_zero(): void
    {
        [$e, $c] = $this->conta();
        // Nenhuma linha de acervo => o contador do header é NULL (nunca coletado).
        $contadores = $this->contadores($e);
        $this->assertNull($contadores['publicacoes'], 'a premissa do caso: o header já diz null');

        $resumo = $this->resumo($e, $c);

        $this->assertFalse($resumo['disponivel']);
        $this->assertNull($resumo['total'], 'zero seria mentira: o acervo nunca foi coletado');
        $this->assertSame([], $resumo['faixas']);
        $this->assertNull($resumo['outras']);
        $this->assertNull($resumo['ativas_pct']);
        // ⚠️ Varredura: nenhum `0` no retorno INTEIRO quando a entrada era null —
        // e neste ramo não há URL nenhuma, então nenhum dígito legítimo pode
        // mascarar o gate.
        $this->assertStringNotContainsString(
            '0',
            json_encode($resumo, JSON_UNESCAPED_UNICODE),
            'nenhum número apareceu onde não houve medição',
        );
    }

    public function test_acervo_coletado_e_vazio_tem_total_zero_medido_e_pct_nulo(): void
    {
        [$e, $c] = $this->conta();

        // ─── Lado A: NUNCA medimos ───────────────────────────────────────────
        $this->assertNull($this->contadores($e)['publicacoes'], 'sem linha nenhuma, o header diz null');
        $this->assertNull($this->resumo($e, $c)['total']);

        // ─── Lado B: medimos e é ZERO ────────────────────────────────────────
        // ⚠️ Só o PAR prova algo (lição da 177-03): um dos dois sozinho passaria
        // tanto com a honestidade quanto com um `?? 0` escondido.
        $resumoZero = $this->servico()->resumoDePublicacoes($c, ['publicacoes' => 0, 'publicacoes_pedem_acao' => 0]);
        $this->assertTrue($resumoZero['disponivel']);
        $this->assertSame(0, $resumoZero['total'], 'zero MEDIDO aparece como zero');
        $this->assertNull($resumoZero['ativas_pct'], 'não há o que dividir — nunca NaN, nunca 0%');
        $this->assertSame([0, 0, 0, 0], array_column($resumoZero['faixas'], 'total'));
        $this->assertSame(0, $resumoZero['outras']);
        $this->assertBarraFecha($resumoZero);
        $this->assertSame(0, $resumoZero['pedem_acao']['total']);
    }

    public function test_d23_sem_company_o_resumo_fica_indisponivel_e_nada_lanca(): void
    {
        [$e, $c] = $this->conta(comCompany: false);
        $this->assertNull($c);

        $resumo = $this->servico()->resumoDePublicacoes(null, $this->contadores($e));

        $this->assertFalse($resumo['disponivel']);
        $this->assertNull($resumo['total']);
        $this->assertSame([], $resumo['faixas']);
        $this->assertNull($resumo['ativas_pct']);
        $this->assertArrayNotHasKey('pedem_acao', $resumo);
    }

    public function test_a_legenda_de_pedem_acao_sai_quando_o_contador_e_nulo(): void
    {
        [$e, $c] = $this->conta();
        $this->acervo($c, 'MLB1', 'active', 0, ['severidade' => MlAcervoItem::SEVERIDADE_CRITICA, 'motivos' => [MlAcervoItem::MOTIVO_PAUSADO]]);

        // Com número medido, a legenda existe e bate com o dot da aba Publicações.
        $comNumero = $this->resumo($e, $c);
        $this->assertArrayHasKey('pedem_acao', $comNumero);
        $this->assertSame($this->contadores($e)['publicacoes_pedem_acao'], $comNumero['pedem_acao']['total']);
        $this->assertSame(1, $comNumero['pedem_acao']['total']);

        // Contador nulo com total medido (entrada inconsistente): a legenda SAI em
        // vez de desenhar um zero que ninguém mediu.
        $semNumero = $this->servico()->resumoDePublicacoes($c, ['publicacoes' => 5, 'publicacoes_pedem_acao' => null]);
        $this->assertTrue($semNumero['disponivel']);
        $this->assertArrayNotHasKey('pedem_acao', $semNumero);
    }

    public function test_pedem_acao_bate_com_o_dot_da_aba_publicacoes(): void
    {
        [$e, $c] = $this->conta();
        foreach (range(1, 3) as $i) {
            $this->acervo($c, 'MLBS'.$i, 'active', 0, [
                'severidade' => MlAcervoItem::SEVERIDADE_ATENCAO,
                'motivos' => [MlAcervoItem::MOTIVO_FICHA_INCOMPLETA],
            ]);
        }
        $this->acervo($c, 'MLBOK', 'active');

        $resumo = $this->resumo($e, $c);
        $triagem = app(AcervoTriagemService::class)->triagem($c, '', 'acionaveis');

        $this->assertSame(3, $resumo['pedem_acao']['total']);
        $this->assertSame(
            $triagem['total'],
            $resumo['pedem_acao']['total'],
            'é o MESMO número do topo da tela de Publicações (severidade > 0), pelo mesmo escopo',
        );
        $this->assertSame(4, $resumo['total'], 'e o total continua sendo o acervo inteiro');
    }

    // ═══ 4. O acervo de imagens ══════════════════════════════════════════════

    public function test_acervo_de_imagens_conta_slot_com_arquivo_e_reusa_os_packs(): void
    {
        [$e, $c] = $this->conta();
        $kit = MlAnuncioCriativoKit::create(['token' => Str::random(32), 'company_id' => $c->id, 'mlb_empresa_id' => $e->id, 'status' => MlAnuncioCriativoKit::STATUS_PRONTO]);
        // Duas imagens de verdade.
        foreach ([0, 1] as $i) {
            MlAnuncioCriativo::create([
                'token' => Str::random(32), 'company_id' => $c->id, 'mlb_empresa_id' => $e->id,
                'kit_id' => $kit->id, 'slot' => 'hero', 'slot_indice' => $i,
                'imagem_path' => "criativos/{$i}.jpg", 'status' => MlAnuncioCriativo::STATUS_PENDENTE,
            ]);
        }
        // Slot SEM arquivo não é imagem.
        MlAnuncioCriativo::create([
            'token' => Str::random(32), 'company_id' => $c->id, 'mlb_empresa_id' => $e->id,
            'kit_id' => $kit->id, 'slot' => 'hero', 'slot_indice' => 9,
            'imagem_path' => null, 'status' => MlAnuncioCriativo::STATUS_PENDENTE,
        ]);
        // Arquivo sem slot também não (é o recorte de `PublicadorAcervoService`).
        MlAnuncioCriativo::create([
            'token' => Str::random(32), 'company_id' => $c->id, 'mlb_empresa_id' => $e->id,
            'kit_id' => $kit->id, 'slot' => 'hero', 'slot_indice' => null,
            'imagem_path' => 'criativos/solta.jpg', 'status' => MlAnuncioCriativo::STATUS_PENDENTE,
        ]);

        $alvo = $this->alvo($e);
        $indicadores = ['criativos_packs' => $this->painel()->criativosPacks($alvo)];
        $acervo = $this->servico()->acervoDeImagens($alvo, $indicadores);

        $this->assertSame(['imagens', 'packs'], array_keys($acervo));
        $this->assertSame(2, $acervo['imagens']);
        $this->assertSame(1, $acervo['packs'], 'packs vem do indicador já calculado — fonte única');
        // ⚠️ Sem link: não existe tela de acervo por CONTA (EXMP-03).
        $this->assertArrayNotHasKey('url', $acervo);
    }

    public function test_acervo_de_imagens_nao_vaza_imagem_de_outra_conta(): void
    {
        [$e, $c] = $this->conta(nome: 'Minha conta');
        [$e2, $c2] = $this->conta(nome: 'Conta vizinha');
        foreach ([[$c, $e, 'minha'], [$c2, $e2, 'vizinha']] as [$company, $empresa, $tag]) {
            MlAnuncioCriativo::create([
                'token' => Str::random(32), 'company_id' => $company->id, 'mlb_empresa_id' => $empresa->id,
                'slot' => 'hero', 'slot_indice' => 0, 'imagem_path' => "criativos/{$tag}.jpg",
                'status' => MlAnuncioCriativo::STATUS_PENDENTE,
            ]);
        }

        $this->assertSame(1, $this->servico()->acervoDeImagens($this->alvo($e), [])['imagens']);
        $this->assertNull($this->servico()->acervoDeImagens($this->alvo($e), [])['packs'], 'indicador ausente é null, nunca 0');
    }

    public function test_d23_acervo_de_imagens_sem_ancora_nenhuma_devolve_zero_sem_varrer_a_base(): void
    {
        [$e, $c] = $this->conta();
        MlAnuncioCriativo::create([
            'token' => Str::random(32), 'company_id' => $c->id, 'mlb_empresa_id' => $e->id,
            'slot' => 'hero', 'slot_indice' => 0, 'imagem_path' => 'criativos/x.jpg',
            'status' => MlAnuncioCriativo::STATUS_PENDENTE,
        ]);

        // Alvo sem nenhuma âncora: a consulta é FECHADA, nunca aberta à base.
        $semAncora = ['mlb_empresa' => null, 'company' => null, 'programa' => 'gestao', 'chave' => null];
        $this->assertSame(0, $this->servico()->acervoDeImagens($semAncora, [])['imagens']);
    }

    // ═══ 5. A faixa de Desempenho (VG-15) ════════════════════════════════════

    public function test_publicacoes_criadas_mede_as_duas_janelas_e_a_serie_de_trinta_dias(): void
    {
        [$e, $c] = $this->conta();
        $produto = $this->produto($e, $c, 'SKU-1');
        // 16 na janela atual: 12 hoje, 4 há 20 dias.
        foreach (range(1, 12) as $i) {
            $this->publicacao($produto, now()->subHours(2));
        }
        foreach (range(1, 4) as $i) {
            $this->publicacao($produto, now()->subDays(20));
        }
        // 11 na janela anterior (dia 40).
        foreach (range(1, 11) as $i) {
            $this->publicacao($produto, now()->subDays(40));
        }
        // E uma FORA das duas janelas (dia 70): não entra em nenhum dos números.
        $this->publicacao($produto, now()->subDays(70));

        $kpi = $this->kpi($this->desempenho($e), 'publicacoes_criadas');

        $this->assertSame(['chave', 'valor', 'de', 'delta', 'serie'], array_keys($kpi));
        $this->assertSame(16, $kpi['valor']);
        $this->assertSame(5, $kpi['delta'], '16 − 11: o delta é a SUBTRAÇÃO das janelas, nunca o valor da atual');
        $this->assertNull($kpi['de'], '`de` só existe no KPI do acervo');
        $this->assertCount(30, $kpi['serie']);
        $this->assertSame(12, $kpi['serie'][29], 'a última posição é HOJE');
        $this->assertSame(4, $kpi['serie'][9], 'há 20 dias = a 10ª posição de uma série que termina hoje');
        $this->assertSame(0, $kpi['serie'][0], 'dia sem publicação é zero MEDIDO (a janela é fechada e a fonte é nossa)');
        $this->assertSame(16, array_sum($kpi['serie']), 'a série e o valor contam a MESMA janela');
        foreach ($kpi['serie'] as $ponto) {
            $this->assertIsInt($ponto, 'nenhum ponto da série pode ser null: dia vazio aqui é 0, não ausência');
        }
    }

    public function test_o_delta_cobre_janela_anterior_vazia_queda_total_e_empate(): void
    {
        // (a) nada antes, 4 agora
        [$e1, $c1] = $this->conta(nome: 'Conta delta A');
        $p1 = $this->produto($e1, $c1, 'SKU-A');
        foreach (range(1, 4) as $i) {
            $this->publicacao($p1, now()->subDays(3));
        }
        $this->assertSame(4, $this->kpi($this->desempenho($e1), 'publicacoes_criadas')['delta']);

        // (b) 4 antes, nada agora
        [$e2, $c2] = $this->conta(nome: 'Conta delta B');
        $p2 = $this->produto($e2, $c2, 'SKU-B');
        foreach (range(1, 4) as $i) {
            $this->publicacao($p2, now()->subDays(45));
        }
        $kpiB = $this->kpi($this->desempenho($e2), 'publicacoes_criadas');
        $this->assertSame(0, $kpiB['valor']);
        $this->assertSame(-4, $kpiB['delta'], 'queda total é delta negativo, nunca 0 nem null');

        // (c) 3 e 3: empate
        [$e3, $c3] = $this->conta(nome: 'Conta delta C');
        $p3 = $this->produto($e3, $c3, 'SKU-C');
        foreach (range(1, 3) as $i) {
            $this->publicacao($p3, now()->subDays(5));
            $this->publicacao($p3, now()->subDays(45));
        }
        $kpiC = $this->kpi($this->desempenho($e3), 'publicacoes_criadas');
        $this->assertSame(3, $kpiC['valor']);
        $this->assertSame(0, $kpiC['delta'], 'empate é 0 MEDIDO ("sem variação"), não ausência de medida');
    }

    public function test_criativos_ia_mede_a_janela_dos_kits_e_convive_com_criativos_packs(): void
    {
        [$e, $c] = $this->conta();
        // 3 kits na janela atual, 1 na anterior, 1 fora das duas.
        foreach ([2, 6, 29] as $dias) {
            $this->kitDeCriativo($e, $c, now()->subDays($dias));
        }
        $this->kitDeCriativo($e, $c, now()->subDays(35));
        $this->kitDeCriativo($e, $c, now()->subDays(80));

        $kpi = $this->kpi($this->desempenho($e), 'criativos_ia');
        $this->assertSame(3, $kpi['valor']);
        $this->assertSame(2, $kpi['delta'], '3 − 1');
        $this->assertCount(30, $kpi['serie']);
        $this->assertSame(1, $kpi['serie'][27], 'há 2 dias');
        $this->assertSame(1, $kpi['serie'][0], 'há 29 dias é a primeira posição da série');

        // ⚠️ Dois números com nomes diferentes, de propósito, no MESMO retorno:
        // `criativos_packs` é a contagem de SEMPRE (bloco do acervo) e
        // `criativos_ia` é a janela de 30 dias (a faixa).
        $packs = $this->painel()->criativosPacks($this->alvo($e));
        $this->assertSame(5, $packs, 'o indicador de sempre conta os cinco kits');
        $this->assertSame(5, $this->servico()->acervoDeImagens($this->alvo($e), ['criativos_packs' => $packs])['packs']);
        $this->assertNotSame($packs, $kpi['valor'], 'um não substitui o outro');
    }

    public function test_publicacoes_com_venda_vem_do_acervo_sem_delta_e_sem_serie(): void
    {
        [$e, $c] = $this->conta();
        $this->acervo($c, 'MLBV1', 'active', 3);
        $this->acervo($c, 'MLBV2', 'active', 0);
        $this->acervo($c, 'MLBV3', 'paused', 7);

        $indicadores = $this->painel()->indicadores($this->alvo($e), [], []);
        $kpi = $this->kpi($this->servico()->desempenho($this->alvo($e), $indicadores), 'publicacoes_com_venda');

        $this->assertSame(2, $kpi['valor'], 'é o `com_venda` do acervo, fonte única');
        $this->assertSame($indicadores['com_venda'], $kpi['valor']);
        $this->assertSame(3, $kpi['de'], 'e o `de` é o `no_ar`');
        $this->assertSame($indicadores['no_ar'], $kpi['de']);
        // ⚠️ Sem delta e sem série: o acervo guarda o ESTADO de hoje, não uma
        // linha do tempo de "com venda" por dia. Inventar uma seria série sintética.
        $this->assertNull($kpi['delta']);
        $this->assertNull($kpi['serie']);
    }

    public function test_com_venda_nunca_coletado_fica_nulo_e_nunca_zero(): void
    {
        [$e, $c] = $this->conta();
        // Nenhuma linha de acervo: `indicadores()` devolve null nos dois.
        $indicadores = $this->painel()->indicadores($this->alvo($e), [], []);
        $this->assertNull($indicadores['com_venda'], 'a premissa do caso');

        $kpi = $this->kpi($this->servico()->desempenho($this->alvo($e), $indicadores), 'publicacoes_com_venda');
        $this->assertNull($kpi['valor'], '"nunca medimos" não vira 0');
        $this->assertNull($kpi['de']);
    }

    public function test_a_faixa_nao_tem_kpi_de_venda_de_visita_nem_de_conversao(): void
    {
        [$e, $c] = $this->conta();
        // ⚠️ ISCA DA NEGATIVA: o cenário TEM o dado que produziria os três KPIs —
        // acervo com `sold_quantity` gravado e `visitas`/`price_to_win` na linha.
        // Sem isso, a asserção de ausência passaria por falta de dado.
        $this->acervo($c, 'MLBK1', 'active', 11, ['visitas_30d' => 320]);
        $this->acervo($c, 'MLBK2', 'active', 4, ['visitas_30d' => 90]);
        $produto = $this->produto($e, $c, 'SKU-K');
        $this->publicacao($produto, now()->subDays(1));

        $desempenho = $this->desempenho($e);
        $chaves = array_column($desempenho['kpis'], 'chave');

        $this->assertSame(['publicacoes_criadas', 'criativos_ia', 'publicacoes_com_venda'], $chaves, 'três KPIs, nesta ordem, e SÓ eles');
        foreach (['vendas', 'vendas_30d', 'visitas', 'conversao', 'conversão', 'faturamento', 'receita'] as $inventado) {
            $this->assertNotContains($inventado, $chaves, "não existe fonte para o KPI `{$inventado}` (DESEMP-05)");
        }
        // E nenhuma delas pode ter entrado por outro caminho no payload.
        $json = json_encode($desempenho, JSON_UNESCAPED_UNICODE);
        foreach (['vendas', 'visitas', 'conversao', 'conversão', 'sold_quantity'] as $inventado) {
            $this->assertStringNotContainsString($inventado, $json);
        }
    }

    public function test_a_faixa_de_desempenho_nao_tem_destino_porque_a_tela_nao_existe(): void
    {
        [$e, $c] = $this->conta();

        $desempenho = $this->desempenho($e);

        $this->assertSame(['kpis', 'dias', 'url'], array_keys($desempenho));
        $this->assertSame(30, $desempenho['dias']);
        // ⚠️ Divergência DECLARADA de VG-15: a tela Desempenho é a Fase 182 e não
        // existe. Link para tela inexistente é botão sem destino (EXMP-03). O
        // cliente desenha `<section>`, não `<a>`.
        $this->assertNull($desempenho['url']);
        $this->assertArrayNotHasKey('rota', $desempenho);
    }

    public function test_d23_sem_company_os_kpis_de_publicacao_e_criativo_continuam_reais(): void
    {
        [$e, $c] = $this->conta(comCompany: false);
        $this->assertNull($c);
        $produto = $this->produto($e, null, 'SKU-SC');
        $this->publicacao($produto, now()->subDays(2));
        $this->kitDeCriativo($e, null, now()->subDays(2));

        $desempenho = $this->servico()->desempenho($this->alvo($e), $this->painel()->indicadores($this->alvo($e), [], []));

        $this->assertSame(1, $this->kpi($desempenho, 'publicacoes_criadas')['valor'], 'a âncora da MlbEmpresa basta');
        $this->assertSame(1, $this->kpi($desempenho, 'criativos_ia')['valor']);
        $this->assertNull($this->kpi($desempenho, 'publicacoes_com_venda')['valor'], 'sem Company não há acervo para medir');
    }

    public function test_os_kpis_de_janela_nao_contam_dado_de_outra_conta(): void
    {
        [$e, $c] = $this->conta(nome: 'Minha conta');
        [$e2, $c2] = $this->conta(nome: 'Conta vizinha');
        $this->publicacao($this->produto($e, $c, 'SKU-MINHA'), now()->subDays(1));
        foreach (range(1, 4) as $i) {
            $this->publicacao($this->produto($e2, $c2, 'SKU-VIZ'.$i), now()->subDays(1));
            $this->kitDeCriativo($e2, $c2, now()->subDays(1));
        }
        $this->kitDeCriativo($e, $c, now()->subDays(1));

        $desempenho = $this->desempenho($e);
        $this->assertSame(1, $this->kpi($desempenho, 'publicacoes_criadas')['valor']);
        $this->assertSame(1, $this->kpi($desempenho, 'criativos_ia')['valor']);
    }
    // ═══ 6. Publicações recentes (VG-16) ═════════════════════════════════════

    public function test_recentes_traz_cinco_da_mais_nova_para_a_mais_velha(): void
    {
        [$e, $c] = $this->conta();
        // 6 publicações COM anúncio criado (é isso que `ultimasPublicacoes()` lê).
        foreach (range(1, 6) as $i) {
            $produto = $this->produto($e, $c, 'SKU-R'.$i);
            $this->publicada($produto, now()->subHours($i * 5));
        }

        $recentes = $this->recentes($e, $c);

        $this->assertTrue($recentes['disponivel']);
        $this->assertCount(5, $recentes['itens'], 'o card mostra 5 — a 6ª mais velha fica fora');
        $this->assertSame(
            ['Cadeira SKU-R1', 'Cadeira SKU-R2', 'Cadeira SKU-R3', 'Cadeira SKU-R4', 'Cadeira SKU-R5'],
            array_column($recentes['itens'], 'produto_nome'),
            'da mais nova para a mais velha — a 6ª (SKU-R6) é a que fica fora',
        );
        $this->assertSame(self::CAMPOS_DO_RECENTE, array_keys($recentes['itens'][0]));
    }

    public function test_o_teto_de_cinco_vale_mesmo_quando_a_fonte_manda_mais(): void
    {
        // ⚠️ Teto PRÓPRIO deste serviço: se um dia a fonte subir o limite de 5, o
        // card não vira uma lista de 30 linhas em silêncio. `recentes()` é função
        // pura, então o cenário entrega 7 itens direto.
        $itens = [];
        foreach (range(1, 7) as $i) {
            $itens[] = ['titulo' => 'Anúncio '.$i, 'produto_id' => $i, 'quando' => now()->subHours($i)->toIso8601String()];
        }

        $recentes = $this->servico()->recentes(['disponivel' => true, 'itens' => $itens], []);

        $this->assertCount(5, $recentes['itens']);
        $this->assertSame('Anúncio 1', $recentes['itens'][0]['titulo'], 'corta o FIM da lista, nunca o começo');
    }

    public function test_nova_e_as_ultimas_vinte_e_quatro_horas_medidas_nunca_a_posicao_na_lista(): void
    {
        $agora = now();
        $recentes = $this->servico()->recentes([
            'disponivel' => true,
            'itens' => [
                ['titulo' => 'há 3 h', 'quando' => $agora->copy()->subHours(3)->toIso8601String()],
                ['titulo' => 'há 30 h', 'quando' => $agora->copy()->subHours(30)->toIso8601String()],
                ['titulo' => 'sem data', 'quando' => null],
                ['titulo' => 'data ilegível', 'quando' => 'ontem de tarde'],
            ],
        ], []);

        $this->assertTrue($recentes['itens'][0]['nova']);
        $this->assertFalse($recentes['itens'][1]['nova'], '30 h está fora da janela');
        $this->assertFalse($recentes['itens'][2]['nova'], 'sem data não é nova');
        $this->assertFalse($recentes['itens'][3]['nova'], 'data ilegível não lança e não é nova');
    }

    public function test_a_capa_vem_do_produto_por_id_e_e_nula_quando_o_produto_nao_esta_na_lista(): void
    {
        [$e, $c] = $this->conta();
        $produto = $this->produto($e, $c, 'SKU-CAPA');
        $publicacao = $this->publicada($produto, now()->subHour());
        // A foto que `produtosParaTela()` resolve: galeria geral já enviada ao ML.
        $rascunho = PubRascunho::where('produto_id', $produto->id)->first();
        $imagem = PubImagem::create([
            'rascunho_id' => $rascunho->id,
            'ml_url' => 'https://http2.mlstatic.com/D_NQ_NP_2X_123456-F.jpg',
            'upload_status' => PubImagem::ENVIADA,
        ]);
        PubImagemAtribuicao::create([
            'imagem_id' => $imagem->id,
            'grupo_chave' => 'GENERAL',
            'grupo_hash' => ChaveCanonica::hash('GENERAL'),
            'posicao' => 0,
        ]);

        $alvo = $this->alvo($e);
        $produtos = $this->programas()->produtosParaTela($e, $c, $alvo['chave']);
        $ultimas = $this->painel()->ultimasPublicacoes($alvo);
        $item = $this->servico()->recentes($ultimas, $produtos)['itens'][0];

        $this->assertSame($produto->id, $item['produto_id']);
        $this->assertSame($produtos[0]['capa'], $item['capa'], 'a capa é a MESMA que a lista de produtos já resolveu');
        $this->assertNotNull($item['capa']);
        $this->assertStringContainsString('-I.jpg', $item['capa'], 'a miniatura de ~4 kB, resolvida no servidor');

        // ⚠️ Produto fora da lista recebida (fora do escopo, ou apagado) fica SEM
        // foto — nunca com a foto de outro produto.
        $semProduto = $this->servico()->recentes($ultimas, [])['itens'][0];
        $this->assertNull($semProduto['capa']);
        $this->assertSame($produto->id, $semProduto['produto_id'], 'e o id continua lá');
    }

    public function test_recentes_nao_recalcula_nada_do_que_a_fonte_ja_decidiu(): void
    {
        [$e, $c] = $this->conta();
        $produto = $this->produto($e, $c, 'SKU-INTACTO');
        $this->publicada($produto, now()->subHours(2));

        $alvo = $this->alvo($e);
        $ultimas = $this->painel()->ultimasPublicacoes($alvo);
        $daFonte = $ultimas['itens'][0];
        $item = $this->servico()->recentes($ultimas, [])['itens'][0];

        // Nenhuma chave nova inventada, nenhum valor recalculado.
        foreach (['titulo', 'tipo', 'situacao', 'vendas', 'fase', 'rotulo_fase', 'ml_item_id', 'quando', 'produto_nome'] as $campo) {
            $this->assertSame($daFonte[$campo], $item[$campo], "`{$campo}` tem de vir como veio da fonte única");
        }
        // `quem` continua sendo {tipo, nome} — a estrutura que JÁ atravessa hoje.
        $this->assertSame($daFonte['quem'], $item['quem']);
        $this->assertSame(['tipo', 'nome'], array_keys($item['quem']));
    }

    public function test_tipo_nulo_nao_lanca_e_nao_inventa_classico(): void
    {
        [$e, $c] = $this->conta();
        $produto = $this->produto($e, $c, 'SKU-TIPO');
        // `listing_type_id` fora do catálogo: a fonte devolve `tipo` nulo.
        $this->publicada($produto, now()->subHour(), 'tipo_que_nao_existe');

        $item = $this->recentes($e, $c)['itens'][0];

        $this->assertNull($item['tipo'], 'listing desconhecido fica nulo — o cliente desenha a ausência');
        $this->assertNotSame('Clássico', $item['tipo']);
    }

    public function test_fonte_indisponivel_deixa_recentes_indisponivel(): void
    {
        [$e, $c] = $this->conta(comCompany: false);

        $ultimas = $this->painel()->ultimasPublicacoes($this->alvo($e));
        $this->assertFalse($ultimas['disponivel'], 'D23: a premissa do caso');

        $recentes = $this->servico()->recentes($ultimas, []);
        $this->assertFalse($recentes['disponivel']);
        $this->assertSame([], $recentes['itens']);
    }

    // ═══ 7. Atividade recente (VG-17 / VG-20) ════════════════════════════════

    public function test_a_atividade_agrupa_em_hoje_ontem_e_data_nesta_ordem(): void
    {
        // O caso ancora o relogio porque os deslocamentos abaixo sao relativos a
        // `now()`: rodando antes das 09:00 locais, o `subHours(9)` cai no dia
        // ANTERIOR e os grupos viram [2,3,1], falhando sem nenhuma regressao de
        // codigo. Medido falhando as 08:06 e passando as 09:01 (plano 179-05).
        Carbon::setTestNow(Carbon::today()->setTime(14, 0));

        $eventos = [
            $this->evento('publicou', now()->subHours(2)),
            $this->evento('criativos', now()->subHours(5)),
            $this->evento('conferiu', now()->subHours(9)),
            $this->evento('publicou', now()->subDay()->setTime(17, 48)),
            $this->evento('criativos', now()->subDay()->setTime(10, 12)),
            $this->evento('publicou', now()->subDays(8)->setTime(11, 20)),
        ];

        $atividade = $this->servico()->atividade($this->semAncora(), ['disponivel' => true, 'itens' => $eventos]);

        $this->assertTrue($atividade['disponivel']);
        $this->assertSame(
            ['hoje', 'ontem', now()->subDays(8)->toDateString()],
            array_column($atividade['grupos'], 'chave'),
            'três grupos, em ordem decrescente de data',
        );
        $this->assertSame([3, 2, 1], array_map(fn (array $g) => count($g['eventos']), $atividade['grupos']));
        $this->assertSame(['chave', 'data_iso', 'eventos'], array_keys($atividade['grupos'][0]));
        $this->assertSame(now()->toDateString(), $atividade['grupos'][0]['data_iso']);
        // ⚠️ `HOJE`, `ONTEM` e `8 OUT` são do cliente: aqui só chave e data.
        $this->assertStringNotContainsStringIgnoringCase('hoje', json_encode($atividade['grupos'][2], JSON_UNESCAPED_UNICODE));
    }

    public function test_evento_sem_data_vai_para_o_ultimo_grupo_nunca_para_o_topo(): void
    {
        $eventos = [
            $this->evento('publicou', null),
            $this->evento('criativos', now()->subHours(2)),
            $this->evento('conferiu', now()->subDays(3)),
        ];

        $grupos = $this->servico()->atividade($this->semAncora(), ['disponivel' => true, 'itens' => $eventos])['grupos'];

        $this->assertSame(['hoje', now()->subDays(3)->toDateString(), 'sem_data'], array_column($grupos, 'chave'));
        $this->assertNull($grupos[2]['data_iso']);
        $this->assertSame('publicou', $grupos[2]['eventos'][0]['tipo'], 'o evento sem data é o do FIM');
    }

    public function test_as_tres_fontes_reais_chegam_intactas_e_a_quarta_e_somada(): void
    {
        [$e, $c] = $this->conta();
        // (1) publicou — publicação com anúncio criado
        $pubProduto = $this->produto($e, $c, 'SKU-PUB');
        $this->publicada($pubProduto, now()->subHours(2));
        // (2) criativos — kit com imagem gerada
        $this->kitDeCriativo($e, $c, now()->subHours(4));
        // (3) conferiu — validação que passou
        $confProduto = $this->produto($e, $c, 'SKU-CONF');
        $rascunho = PubRascunho::create(['produto_id' => $confProduto->id, 'status' => PubRascunho::VALIDATED, 'revisao' => 1]);
        PubValidacao::create([
            'rascunho_id' => $rascunho->id, 'revisao' => 1, 'camada' => 'L3', 'resultado' => 'OK',
            'created_at' => now()->subHours(6), 'updated_at' => now()->subHours(6),
        ]);

        $alvo = $this->alvo($e);
        $daEquipe = $this->painel()->atividadeDaEquipe($alvo);
        $this->assertSame(
            ['publicou', 'criativos', 'conferiu'],
            array_values(array_unique(array_column($daEquipe['itens'], 'tipo'))),
            'as três fontes reais da premissa',
        );

        $atividade = $this->servico()->atividade($alvo, $daEquipe);
        $eventos = $this->eventos($atividade);

        // ⚠️ VG-20: as três chegam BYTE A BYTE — nenhuma reimplementada aqui.
        foreach ($daEquipe['itens'] as $original) {
            $this->assertContains($original, $eventos, 'evento da fonte real saiu ou foi alterado no caminho');
        }
        // E a 4ª fonte é real: os dois produtos recebidos nos últimos 30 dias.
        $recebidos = array_values(array_filter($eventos, fn (array $ev) => $ev['tipo'] === 'produto_recebido'));
        $this->assertCount(2, $recebidos, 'sem esta fonte o chip "Produtos" nasceria sempre vazio (EXMP-01)');
        $this->assertSame(['tipo', 'quem', 'quando_iso', 'titulo', 'detalhe'], array_keys($recebidos[0]));
        $this->assertSame(PainelVisaoGeralService::QUEM_EQUIPE_SEM_NOME, $recebidos[0]['quem']);
        $this->assertSame('Cadeira SKU-CONF', $recebidos[0]['titulo']);
        $this->assertSame('SKU-CONF', $recebidos[0]['detalhe'], 'o SKU é o identificador que o usuário tem na mão');
    }

    public function test_produto_do_portal_nunca_expoe_nome_nem_id_de_cliente(): void
    {
        [$e, $c] = $this->conta();
        $this->produto($e, $c, 'SKU-PORTAL', ['origem' => PubProduto::ORIGEM_PORTAL]);
        $this->produto($e, $c, 'SKU-OUTRA', ['origem' => 'importacao_antiga']);

        $eventos = $this->eventos($this->servico()->atividade($this->alvo($e), ['disponivel' => false, 'itens' => []]));
        $quemPorTitulo = array_combine(array_column($eventos, 'titulo'), array_column($eventos, 'quem'));

        // T-173-09: o rótulo agregado, nunca o nome ou o id de quem enviou.
        $this->assertSame(PainelVisaoGeralService::QUEM_CLIENTE, $quemPorTitulo['Cadeira SKU-PORTAL']);
        $this->assertSame(PainelVisaoGeralService::QUEM_ORIGEM_ANTIGA, $quemPorTitulo['Cadeira SKU-OUTRA']);
        // As constantes REUSADAS, nunca uma string nova.
        foreach ($eventos as $evento) {
            $this->assertContains($evento['quem'], [
                PainelVisaoGeralService::QUEM_CLIENTE,
                PainelVisaoGeralService::QUEM_EQUIPE_SEM_NOME,
                PainelVisaoGeralService::QUEM_ORIGEM_ANTIGA,
            ]);
        }
    }

    public function test_a_quarta_fonte_nao_vaza_produto_de_outra_conta_nem_produto_antigo(): void
    {
        [$e, $c] = $this->conta(nome: 'Minha conta');
        [$e2, $c2] = $this->conta(nome: 'Conta vizinha');
        $this->produto($e, $c, 'SKU-MEU');
        foreach (range(1, 4) as $i) {
            $this->produto($e2, $c2, 'SKU-VIZINHO'.$i);
        }
        // Produto de 60 dias atrás não é "recebido recentemente".
        $antigo = $this->produto($e, $c, 'SKU-ANTIGO');
        $antigo->forceFill(['created_at' => now()->subDays(60)])->save();

        $eventos = $this->eventos($this->servico()->atividade($this->alvo($e), ['disponivel' => false, 'itens' => []]));

        $this->assertSame(['Cadeira SKU-MEU'], array_column($eventos, 'titulo'));
    }

    public function test_sem_fonte_nenhuma_a_atividade_fica_indisponivel_sem_grupo_vazio(): void
    {
        [$e, $c] = $this->conta(comCompany: false);

        $daEquipe = $this->painel()->atividadeDaEquipe($this->alvo($e));
        $this->assertFalse($daEquipe['disponivel'], 'D23: a premissa do caso');

        $atividade = $this->servico()->atividade($this->alvo($e), $daEquipe);
        $this->assertFalse($atividade['disponivel']);
        $this->assertSame([], $atividade['grupos'], '"não sabemos" não desenha um grupo vazio');
    }

    public function test_o_limite_corta_a_linha_do_tempo_pelos_mais_recentes(): void
    {
        $eventos = [];
        foreach (range(1, 9) as $i) {
            $eventos[] = $this->evento('publicou', now()->subHours($i), 'Evento '.$i);
        }

        $atividade = $this->servico()->atividade($this->semAncora(), ['disponivel' => true, 'itens' => $eventos], 4);

        $this->assertCount(4, $this->eventos($atividade));
        $this->assertSame('Evento 1', $this->eventos($atividade)[0]['titulo'], 'os mais recentes sobrevivem ao corte');
        // Limite zero não lança e não inventa grupo.
        $zerado = $this->servico()->atividade($this->semAncora(), ['disponivel' => true, 'itens' => $eventos], 0);
        $this->assertFalse($zerado['disponivel']);
        $this->assertSame([], $zerado['grupos']);
    }

    public function test_nenhum_campo_de_evento_e_objeto_nem_string_vazia(): void
    {
        [$e, $c] = $this->conta();
        $produto = $this->produto($e, $c, 'SKU-ESCALAR');
        $this->publicada($produto, now()->subHours(2));
        $this->kitDeCriativo($e, $c, now()->subHours(3));
        // Produto SEM nome existe (importação antiga do Portal): `titulo` vira
        // null, nunca `''` — `''` daria um alvo mudo na frase da tela.
        PubProduto::create(['mlb_empresa_id' => $e->id, 'company_id' => $c->id, 'sku' => 'SKU-SEM-NOME', 'nome' => '', 'origem' => PubProduto::ORIGEM_PORTAL]);

        $alvo = $this->alvo($e);
        $eventos = $this->eventos($this->servico()->atividade($alvo, $this->painel()->atividadeDaEquipe($alvo)));

        $this->assertNotSame([], $eventos, 'o cenário tem de produzir eventos, senão a varredura é vazia por construção');
        $semNome = array_values(array_filter($eventos, fn (array $ev) => $ev['titulo'] === null));
        // DOIS eventos sem alvo, os dois honestos: o produto sem nome (4ª fonte) e
        // o kit de criativo sem `pub_rascunho_id`, que não tem produto para nomear
        // (leftJoin de `atividadeDaEquipe()`). Nos dois o campo é null, nunca `''`.
        $this->assertCount(2, $semNome, 'o produto sem nome e o kit sem rascunho viram titulo null');
        $this->assertSame(
            ['produto_recebido', 'criativos'],
            array_column($semNome, 'tipo'),
            'os dois casos de alvo ausente são estes — nenhum outro tipo perdeu o título pelo caminho',
        );
        foreach ($eventos as $evento) {
            foreach ($evento as $campo => $valor) {
                $this->assertFalse(is_array($valor), "`{$campo}` não pode ser array num evento");
                $this->assertFalse(is_object($valor), "`{$campo}` não pode ser objeto num evento (a tela preta de 07/10)");
                $this->assertNotSame('', $valor, "`{$campo}` vazio tem de ser null, nunca ''");
            }
        }
    }

    // ═══ 8. Os gates do contrato inteiro ═════════════════════════════════════

    public function test_nenhum_campo_do_payload_e_objeto_fora_dos_conteineres_nomeados(): void
    {
        $cockpit = $this->cockpitCompleto();

        $this->varrerEscalares($cockpit, '');
    }

    public function test_nenhum_rotulo_de_tela_atravessa_o_servidor(): void
    {
        $cockpit = $this->cockpitCompleto();
        $json = json_encode($cockpit, JSON_UNESCAPED_UNICODE);

        // ⚠️ Negativa de propósito: a positiva deixaria uma fase futura
        // acrescentar rótulo e o teste seguir verde.
        foreach (['rotulo', 'label', 'titulo_kpi', 'cor', 'color', 'icone', 'sub', 'cta'] as $chaveDeTela) {
            $this->varrerChaveAusente($cockpit, $chaveDeTela, '');
        }
        foreach (['Tudo conectado', 'Requer ação', 'Atualizar', 'Reconectar', 'HOJE', 'ONTEM', 'Com erro', 'Ativas', 'Pausadas', 'Publicações criadas', 'Acervo de imagens', 'Ver desempenho'] as $literal) {
            $this->assertStringNotContainsString($literal, $json, "o literal de tela `{$literal}` não pode sair do servidor");
        }
        // "Tudo" só existe como chip do cliente; a chave do servidor é `tudo`? Não:
        // o servidor não manda chip nenhum — a lista de chips é do plano 179-08.
        $this->assertStringNotContainsString('"chips"', $json);
    }

    public function test_o_payload_nao_vaza_segredo_da_conta(): void
    {
        $json = json_encode($this->cockpitCompleto(), JSON_UNESCAPED_UNICODE);

        foreach (['access_token', 'refresh_token', 'APP_USR', 'TG-'] as $segredo) {
            $this->assertStringNotContainsString($segredo, $json, "o payload não pode carregar `{$segredo}`");
        }
    }

    public function test_d23_os_seis_metodos_com_company_nula_nao_lancam(): void
    {
        [$e, $c] = $this->conta(comCompany: false);
        $alvo = $this->alvo($e);
        $empresa = $this->programas()->empresaParaTela($alvo);
        $servico = $this->servico();

        $cockpit = [
            'banner' => $servico->banner($empresa),
            'saude' => $servico->saude($empresa, $this->painel()->integracoes($alvo, $empresa), $this->defasagem(null), null),
            'publicacoes_ml' => $servico->resumoDePublicacoes(null, app(CabecalhoPublicadorService::class)->contadores($alvo)),
            'acervo_imagens' => $servico->acervoDeImagens($alvo, $this->painel()->indicadores($alvo, [], [])),
            'desempenho' => $servico->desempenho($alvo, $this->painel()->indicadores($alvo, [], [])),
            'recentes' => $servico->recentes($this->painel()->ultimasPublicacoes($alvo), []),
            'atividade' => $servico->atividade($alvo, $this->painel()->atividadeDaEquipe($alvo)),
        ];

        // ⚠️ Sem Company a conta pode estar PERFEITAMENTE conectada (o token mora
        // na própria `MlbEmpresa` nas 535 de 539 contas sem Company): o banner é
        // nulo porque o token está ativo, não porque falta Company. D23 não é
        // sinônimo de problema de integração.
        $this->assertNull($cockpit['banner']);
        // ⚠️ O `geral` fica em `atencao` pela SINCRONIZAÇÃO, não pelo ML: sem
        // Company não existe acervo para coletar, e a linha diz `nunca_coletado`
        // com `atualizar_url` nulo — exatamente o que o contrato do header já faz
        // para as contas sem Company (a tela aplica ali o literal "disponível só
        // para empresas cadastradas"). É a MESMA regra do caso medido, sem exceção
        // escondida para D23.
        $this->assertSame('atencao', $cockpit['saude']['geral']);
        $this->assertTrue($cockpit['saude']['linhas'][2]['nunca_coletado']);
        $this->assertFalse($cockpit['saude']['linhas'][0]['problema'], 'o ML está conectado neste cenário');
        $this->assertFalse($cockpit['publicacoes_ml']['disponivel']);
        $this->assertFalse($cockpit['recentes']['disponivel']);
        $this->assertFalse($cockpit['atividade']['disponivel']);
        $this->assertNull($cockpit['saude']['linhas'][2]['atualizar_url']);
        $this->assertNull($this->kpi($cockpit['desempenho'], 'publicacoes_com_venda')['valor']);
        $this->varrerEscalares($cockpit, '');
    }

    public function test_o_custo_dos_seis_metodos_e_estavel_entre_doze_e_vinte_e_quatro_produtos(): void
    {
        [$e, $c] = $this->conta();
        foreach (range(1, 20) as $i) {
            $this->acervo($c, 'MLBQ'.$i, $i % 4 === 0 ? 'paused' : 'active', $i % 3);
        }
        foreach (range(1, 12) as $i) {
            $produto = $this->produto($e, $c, 'SKU-Q'.$i);
            if ($i % 3 === 0) {
                $this->publicada($produto, now()->subDays($i));
            }
            if ($i % 4 === 0) {
                $this->kitDeCriativo($e, $c, now()->subDays($i));
            }
        }

        // ⚠️ ISCA: um trecho que SABEMOS que consulta. Sem ela, um
        // `enableQueryLog()` mal posicionado daria zero e o teto passaria por
        // construção (o gate vazio que as três waves desta fase pegaram).
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->programas()->produtosParaTela($e, $c, 'empresa-'.$e->id);
        $isca = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertGreaterThan(0, $isca, 'a isca não mediu nada: o contador de consultas está no lugar errado');

        $com12 = $this->consultasDoCockpit($e, $c);
        $this->assertLessThan(10, $com12, "os seis métodos custaram {$com12} consultas");

        // 12 produtos a mais: o MESMO número. É isto que pega N+1 — o teto, não.
        foreach (range(13, 24) as $i) {
            $this->produto($e, $c, 'SKU-Q'.$i);
        }
        $com24 = $this->consultasDoCockpit($e, $c);
        $this->assertSame($com12, $com24, 'o custo cresceu com o número de produtos: há N+1 no caminho');
    }
}
