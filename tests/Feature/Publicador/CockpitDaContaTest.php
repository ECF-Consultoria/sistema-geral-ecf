<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\MlAcervoItem;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\MlToken;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubRascunho;
use App\Services\Publicador\AcervoTriagemService;
use App\Services\Publicador\CabecalhoPublicadorService;
use App\Services\Publicador\CockpitDaContaService;
use App\Services\Publicador\PainelVisaoGeralService;
use App\Services\Publicador\ProgramasPublicadorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
            'expires_at' => $tokenStatus === 'expired' ? now()->subHours(2) : now()->addHours(5),
            'status' => $tokenStatus === 'expired' ? 'expired' : 'active',
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

    public function test_token_expirado_abre_o_banner_do_ml_e_a_saude_requer_acao(): void
    {
        [$e, $c] = $this->conta(tokenStatus: 'expired');
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
        $this->assertSame('expirado', $linha['estado']);
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
        [$e, $c] = $this->conta(tokenStatus: 'expired');
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
}
