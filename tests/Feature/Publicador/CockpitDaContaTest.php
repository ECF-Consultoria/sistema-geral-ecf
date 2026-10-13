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
use App\Services\Publicador\AcervoTriagemService;
use App\Services\Publicador\CabecalhoPublicadorService;
use App\Services\Publicador\CockpitDaContaService;
use App\Services\Publicador\PainelVisaoGeralService;
use App\Services\Publicador\ProgramasPublicadorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
