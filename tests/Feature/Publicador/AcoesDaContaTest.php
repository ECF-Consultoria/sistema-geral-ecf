<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\MlAcervoItem;
use App\Models\PubTarefa;
use App\Services\Publicador\AcervoTriagemService;
use App\Services\Publicador\AcoesDaContaService;
use App\Services\Publicador\AdiamentoDeAcoes;
use App\Services\Publicador\PainelVisaoGeralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 179 Plano 02 — "O que fazer agora" com severidade, ordem, sub-lista e
 * adiamento que PERSISTE.
 *
 * ═══ O que este arquivo prova, e por que cada gate não é decorativo ═════════
 *
 * 1. **NÃO-REGRESSÃO, o gate central.** `AcoesDaContaService` **consome**
 *    `oQueFazerAgora()` em vez de reescrevê-lo. O teste
 *    `test_toda_linha_de_hoje_virou_acao_com_contagem_exata()` passa a saída
 *    REAL do serviço de hoje para `acoes()` e cobra `count($linhas) + 1`
 *    ações. ⚠️ Contagem EXATA, não "contém": uma linha engolida passaria por um
 *    `assertContains`, e a regra inviolável do projeto é que nada do que a tela
 *    entrega hoje pode sumir.
 *
 * 2. **VETO AO PREJUÍZO por asserção NEGATIVA (VG-12 / EXMP-01).** A ação
 *    "publicação com prejuízo" não existe porque a rentabilidade não existe.
 *    ⚠️ Positiva não serviria: uma fase futura poderia acrescentar a ação e o
 *    teste seguiria verde. E a varredura roda sobre o cenário CHEIO — asserção
 *    de ausência com dado vazio é vazia por construção.
 *
 * 3. **O ADIAMENTO com viagem no tempo.** Não basta "a chave sai da lista": o
 *    teste cobra o instante da volta (08:00, não meia-noite) e o isolamento por
 *    usuário e por conta, que é a mitigação de T-179-06.
 *
 * 4. **CUSTO: zero consulta, COM ISCA.** `acoes()` recebe tudo pronto. A isca é
 *    um trecho que SABEMOS que consulta — sem ela, um `enableQueryLog()` mal
 *    posicionado daria zero por construção (a sonda já errou três vezes nas
 *    fases anteriores, as duas últimas por erro do medidor, não do código).
 *
 * @group phase179
 */
class AcoesDaContaTest extends TestCase
{
    use RefreshDatabase;

    private AcoesDaContaService $service;

    private AdiamentoDeAcoes $adiamento;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->adiamento = new AdiamentoDeAcoes();
        $this->service = new AcoesDaContaService($this->adiamento);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══ Fixtures LITERAIS ═══════════════════════════════════════════════════
    //
    // ⚠️ `$jornada` entra como fixture literal de propósito: os planos 179-01 e
    // 179-02 são da MESMA wave, e a prova de que o shape real de
    // `JornadaDosProdutosService::jornada()` casa com esta fixture é do plano
    // 179-04 (a ligação no controller). Aqui o contrato é o do payload.

    private function alvo(?string $chave = 'empresa-1'): array
    {
        return ['mlb_empresa' => null, 'company' => null, 'programa' => 'polos', 'chave' => $chave];
    }

    /** Um item de estágio no shape EXATO do plano 179-01. */
    private function itemDeEstagio(int $id, string $nome, string $sku, string $nota): array
    {
        return [
            'produto_id' => $id,
            'nome' => $nome,
            'sku' => $sku,
            'nota' => $nota,
            'capa' => null,
            'cobertura' => ['f1_classico' => false, 'f1_premium' => false, 'f2_classico' => false, 'f2_premium' => false],
            'cta_chave' => 'publicar_fase1',
            'url' => 'https://exemplo/produto/'.$id,
        ];
    }

    /**
     * @param  array<string, array{total:int, itens:list<array>}>  $porEstagio
     */
    private function jornada(array $porEstagio = [], int $total = 0): array
    {
        $estagios = [];
        foreach (['none', 'draft', 'ready', 'done'] as $chave) {
            $estagios[] = [
                'chave' => $chave,
                'total' => $porEstagio[$chave]['total'] ?? 0,
                'share_pct' => 0,
                'itens' => $porEstagio[$chave]['itens'] ?? [],
                'ver_todos_url' => 'https://exemplo/produtos?estagio='.$chave,
            ];
        }

        return [
            'total' => $total,
            'publicados' => 0,
            'pct' => 0,
            'tem_produtos' => $total > 0,
            'proximo_passo' => 'none',
            'estagios' => $estagios,
        ];
    }

    private function jornadaVazia(): array
    {
        return $this->jornada();
    }

    /** Uma linha de `oQueFazerAgora()` no shape real, com a `chave` da Task 1. */
    private function linha(string $chave, ?int $numero = 3, array $extra = []): array
    {
        return array_merge([
            'texto' => 'Texto da linha '.$chave,
            'numero' => $numero,
            'destino' => ['rota' => 'mlb.anuncios.publicador.produtos', 'params' => ['conta' => 'empresa-1', 'filtro' => $chave]],
            'chave' => $chave,
        ], $extra);
    }

    private function produto(string $nome, string $sku, string $chaveStatus, string $rotulo): array
    {
        return ['id' => random_int(1, 99999), 'nome' => $nome, 'sku' => $sku, 'status' => ['chave' => $chaveStatus, 'rotulo' => $rotulo, 'faltam' => 0]];
    }

    /** @return list<string> */
    private function chavesDe(array $saida): array
    {
        return array_column($saida['acoes'], 'chave');
    }

    private function acao(array $saida, string $chave): ?array
    {
        foreach ($saida['acoes'] as $a) {
            if ($a['chave'] === $chave) {
                return $a;
            }
        }

        return null;
    }

    // ═══ 1. Severidade e ordem (VG-09) ═══════════════════════════════════════

    public function test_cada_chave_tem_a_severidade_do_mapa(): void
    {
        $esperado = [
            'reconectar' => 'critico',
            'com_problema' => 'critico',
            'pausados' => 'atencao',
            'rascunhos_pendencias' => 'atencao',
            'ficha_incompleta' => 'atencao',
            'perdendo_catalogo' => 'atencao',
            'foto_insuficiente' => 'atencao',
            'prontos_fase2' => 'oportunidade',
            'conferidos' => 'oportunidade',
            'sem_publicacao' => 'oportunidade',
            'portal_novas' => 'oportunidade',
            'alavancas' => 'oportunidade',
        ];

        $this->assertSame($esperado, AcoesDaContaService::SEVERIDADE, 'o mapa é o contrato: doze chaves, nesta ordem');

        foreach ($esperado as $chave => $severidade) {
            if ($chave === 'sem_publicacao') {
                continue; // nasce da jornada, não de uma linha — tem teste próprio
            }
            $saida = $this->service->acoes($this->alvo(), [$this->linha($chave)], $this->jornadaVazia(), [], null);
            $this->assertSame($severidade, $saida['acoes'][0]['severidade'], "a severidade de '{$chave}'");
        }
    }

    public function test_as_acoes_saem_ordenadas_critico_atencao_oportunidade(): void
    {
        // Entrada EMBARALHADA de propósito: se o serviço só repassasse a ordem de
        // entrada, este teste reprovaria.
        $linhas = [
            $this->linha('portal_novas'),   // oportunidade
            $this->linha('pausados'),       // atencao
            $this->linha('com_problema'),   // critico
            $this->linha('conferidos'),     // oportunidade
            $this->linha('ficha_incompleta'), // atencao
        ];

        $saida = $this->service->acoes($this->alvo(), $linhas, $this->jornadaVazia(), [], null);

        $this->assertSame(
            ['com_problema', 'pausados', 'ficha_incompleta', 'portal_novas', 'conferidos'],
            $this->chavesDe($saida),
            'crítico → atenção → oportunidade, e DENTRO da severidade a ordem de entrada é preservada'
        );
        $this->assertSame(
            ['critico', 'atencao', 'atencao', 'oportunidade', 'oportunidade'],
            array_column($saida['acoes'], 'severidade')
        );
    }

    /**
     * ⚠️ A ordenação tem de ser ESTÁVEL. Dentro da mesma severidade vale a ordem
     * que `oQueFazerAgora()` já entrega — que é a ordem fixa do handoff, com teste
     * próprio desde a Fase 173. Um `usort` que desempatasse por nome de chave
     * reordenaria as três de `atencao` e desfaria aquela decisão sem avisar.
     */
    public function test_a_ordem_dentro_da_severidade_e_a_de_entrada(): void
    {
        // Em ordem alfabética DECRESCENTE: qualquer desempate por nome inverteria.
        $linhas = [
            $this->linha('rascunhos_pendencias'),
            $this->linha('perdendo_catalogo'),
            $this->linha('foto_insuficiente'),
            $this->linha('ficha_incompleta'),
            $this->linha('pausados'),
        ];

        $saida = $this->service->acoes($this->alvo(), $linhas, $this->jornadaVazia(), [], null);

        $this->assertSame(
            ['rascunhos_pendencias', 'perdendo_catalogo', 'foto_insuficiente', 'ficha_incompleta', 'pausados'],
            $this->chavesDe($saida),
            'todas são `atencao`: a ordem de entrada tem de sair intacta'
        );
    }

    /**
     * ⚠️ Chave desconhecida NÃO é descartada: cai em `atencao` e APARECE. Uma fase
     * futura que acrescente linha em `oQueFazerAgora()` sem mexer no mapa não pode
     * fazer a ação desaparecer da tela em silêncio — isso seria regressão por
     * omissão, que é o pior tipo porque nada acusa.
     */
    public function test_chave_desconhecida_cai_em_atencao_e_aparece(): void
    {
        $linhas = [$this->linha('com_problema'), $this->linha('inventada_numa_fase_futura', 7)];

        $saida = $this->service->acoes($this->alvo(), $linhas, $this->jornadaVazia(), [], null);

        $this->assertSame(['com_problema', 'inventada_numa_fase_futura'], $this->chavesDe($saida));
        $this->assertSame('atencao', $this->acao($saida, 'inventada_numa_fase_futura')['severidade']);
        $this->assertSame(7, $this->acao($saida, 'inventada_numa_fase_futura')['numero']);
        $this->assertSame(2, $saida['contagens']['todas'], 'a desconhecida CONTA');
    }

    public function test_linha_sem_chave_usavel_nao_entra(): void
    {
        // Shape antigo (pré-Task 1) ou chave vazia: não há como endereçar o
        // adiamento nem a severidade. Fora, sem lançar.
        $linhas = [
            ['texto' => 'Linha de shape antigo', 'numero' => 2, 'destino' => ['acao' => 'sincronizar']],
            $this->linha('', 1),
            $this->linha('com_problema'),
        ];

        $saida = $this->service->acoes($this->alvo(), $linhas, $this->jornadaVazia(), [], null);

        $this->assertSame(['com_problema'], $this->chavesDe($saida));
    }

    // ═══ 2. Contagens por severidade (VG-20) ═════════════════════════════════

    public function test_contagens_por_severidade_batem_com_a_lista(): void
    {
        $linhas = [
            $this->linha('reconectar', null),
            $this->linha('com_problema'),
            $this->linha('pausados'),
            $this->linha('conferidos'),
            $this->linha('portal_novas'),
            $this->linha('alavancas'),
        ];

        $saida = $this->service->acoes($this->alvo(), $linhas, $this->jornadaVazia(), [], null);

        $this->assertSame(['todas', 'critico', 'atencao', 'oportunidade'], array_keys($saida['contagens']));
        $this->assertSame(['todas' => 6, 'critico' => 2, 'atencao' => 1, 'oportunidade' => 3], $saida['contagens']);

        // ⚠️ O gate que importa: a contagem é EXATA e bate com a lista, não é um
        // número paralelo. VG-20 pede que o filtro por severidade não minta.
        $this->assertSame(count($saida['acoes']), $saida['contagens']['todas']);
        foreach (['critico', 'atencao', 'oportunidade'] as $sev) {
            $this->assertSame(
                count(array_filter($saida['acoes'], fn (array $a) => $a['severidade'] === $sev)),
                $saida['contagens'][$sev],
                "a contagem de '{$sev}' tem de ser a da lista"
            );
        }
    }

    public function test_linhas_vazias_devolvem_lista_vazia_e_contagens_em_zero(): void
    {
        $saida = $this->service->acoes($this->alvo(), [], $this->jornadaVazia(), [], null);

        $this->assertSame([], $saida['acoes']);
        $this->assertSame(['todas' => 0, 'critico' => 0, 'atencao' => 0, 'oportunidade' => 0], $saida['contagens']);
        $this->assertSame([], $saida['adiadas']);
        $this->assertIsString($saida['volta_em_iso']);
    }

    public function test_o_shape_do_retorno_e_fechado(): void
    {
        $saida = $this->service->acoes($this->alvo(), [$this->linha('com_problema')], $this->jornadaVazia(), [], null);

        $this->assertSame(['acoes', 'contagens', 'adiadas', 'volta_em_iso'], array_keys($saida));
        $this->assertSame(
            ['chave', 'severidade', 'titulo', 'descricao', 'numero', 'recomendada', 'itens', 'destino'],
            array_keys($saida['acoes'][0]),
            'campo a mais aqui é convite a inventar tela na wave 3'
        );
    }

    // ═══ 3. A recomendada (VG-10) ════════════════════════════════════════════

    public function test_a_primeira_da_lista_completa_e_a_recomendada(): void
    {
        $linhas = [$this->linha('portal_novas'), $this->linha('com_problema'), $this->linha('pausados')];

        $saida = $this->service->acoes($this->alvo(), $linhas, $this->jornadaVazia(), [], null);

        $this->assertSame([true, false, false], array_column($saida['acoes'], 'recomendada'));
        $this->assertSame('com_problema', $saida['acoes'][0]['chave'], 'a crítica é a primeira, logo é a recomendada');
        $this->assertSame(1, count(array_filter($saida['acoes'], fn (array $a) => $a['recomendada'] === true)), 'exatamente UMA recomendada');
    }

    /**
     * ⚠️ O filtro por severidade é do CLIENTE (o protótipo filtra no render e as
     * contagens são sobre a lista inteira: `Visao Geral.dc.html:511-514`). O
     * servidor manda TUDO e as contagens — não existe parâmetro de severidade.
     * Por isso a `recomendada` já sai decidida e é estável sob qualquer filtro
     * que o cliente aplique: ela é a primeira da lista COMPLETA.
     */
    public function test_a_recomendada_e_estavel_sob_filtro_do_cliente(): void
    {
        $linhas = [$this->linha('com_problema'), $this->linha('pausados'), $this->linha('portal_novas')];

        $saida = $this->service->acoes($this->alvo(), $linhas, $this->jornadaVazia(), [], null);

        // O cliente filtrando por `oportunidade` fica com UMA ação, e ela NÃO é a
        // recomendada — a recomendada continua sendo a crítica da lista completa.
        $soOportunidade = array_values(array_filter($saida['acoes'], fn (array $a) => $a['severidade'] === 'oportunidade'));
        $this->assertCount(1, $soOportunidade);
        $this->assertFalse($soOportunidade[0]['recomendada'], 'filtrar não promove ninguém a recomendado');

        // E o servidor não aceita parâmetro de severidade nenhum: a assinatura é fechada.
        $parametros = array_map(
            fn (\ReflectionParameter $p) => $p->getName(),
            (new \ReflectionMethod(AcoesDaContaService::class, 'acoes'))->getParameters()
        );
        $this->assertSame(['alvo', 'linhas', 'jornada', 'produtos', 'userId'], $parametros);
        foreach ($parametros as $nome) {
            $this->assertStringNotContainsStringIgnoringCase('sever', $nome);
            $this->assertStringNotContainsStringIgnoringCase('request', $nome);
            $this->assertStringNotContainsStringIgnoringCase('filtro', $nome);
        }
    }

    // ═══ 4. A ação NOVA `sem_publicacao` (VG-12) ═════════════════════════════

    public function test_sem_publicacao_nasce_do_estagio_none_da_jornada(): void
    {
        $jornada = $this->jornada([
            'none' => ['total' => 9, 'itens' => [
                $this->itemDeEstagio(1, 'Puff Redondo', 'PUF-1', 'Recebido do Portal do Cliente'),
                $this->itemDeEstagio(2, 'Banqueta Ripa', 'BAN-2', 'Cadastro manual no Publicador'),
            ]],
        ], total: 20);

        $saida = $this->service->acoes($this->alvo(), [], $jornada, [], null);

        $acao = $this->acao($saida, 'sem_publicacao');
        $this->assertNotNull($acao);
        $this->assertSame('oportunidade', $acao['severidade']);

        // ⚠️ FONTE ÚNICA: o número é o `total` do estágio (9), NUNCA a contagem dos
        // `itens` (2 — a amostra vem cortada em 6 pelo plano 179-01). Recontar aqui
        // é a mesma armadilha do "+N" da pilha de tiles.
        $this->assertSame(9, $acao['numero'], 'o número é o total do estágio, não o tamanho da amostra');
        $this->assertCount(2, $acao['itens']);
        $this->assertSame('https://exemplo/produtos?estagio=none', $acao['destino']['url'] ?? $acao['destino']['href'] ?? null);
    }

    public function test_sem_publicacao_nao_aparece_com_estagio_none_zerado(): void
    {
        $jornada = $this->jornada(['ready' => ['total' => 4, 'itens' => []]], total: 4);

        $saida = $this->service->acoes($this->alvo(), [$this->linha('com_problema')], $jornada, [], null);

        $this->assertNull($this->acao($saida, 'sem_publicacao'), 'nenhum produto sem publicação: a ação não nasce');
        $this->assertSame(['com_problema'], $this->chavesDe($saida));
    }

    /**
     * ⚠️ `sem_publicacao` entra no FIM da lista de oportunidades, depois das linhas
     * que `oQueFazerAgora()` já ordena — a ordem fixa do handoff não se mexe.
     */
    public function test_sem_publicacao_entra_depois_das_oportunidades_de_hoje(): void
    {
        $jornada = $this->jornada(['none' => ['total' => 3, 'itens' => []]], total: 10);
        $linhas = [$this->linha('prontos_fase2'), $this->linha('conferidos'), $this->linha('portal_novas')];

        $saida = $this->service->acoes($this->alvo(), $linhas, $jornada, [], null);

        $this->assertSame(
            ['prontos_fase2', 'conferidos', 'portal_novas', 'sem_publicacao'],
            $this->chavesDe($saida)
        );
    }

    // ═══ 5. Os sub-itens (VG-11) ═════════════════════════════════════════════

    public function test_com_problema_traz_ate_quatro_itens_dos_produtos_em_erro(): void
    {
        $produtos = [
            $this->produto('Erro 1', 'E-1', 'erro', 'não publicado'),
            $this->produto('Erro 2', 'E-2', 'erro', 'não publicado'),
            $this->produto('Erro 3', 'E-3', 'erro', 'não publicado'),
            $this->produto('Erro 4', 'E-4', 'erro', 'não publicado'),
            $this->produto('Erro 5', 'E-5', 'erro', 'não publicado'),
            $this->produto('Pronto', 'P-1', 'pronto', 'conferido'),
        ];

        $saida = $this->service->acoes($this->alvo(), [$this->linha('com_problema', 5)], $this->jornadaVazia(), $produtos, null);

        $acao = $this->acao($saida, 'com_problema');
        $this->assertCount(4, $acao['itens'], 'teto de 4 — o resto está atrás do destino');
        $this->assertSame(['nome', 'meta', 'motivo'], array_keys($acao['itens'][0]));
        $this->assertSame(['Erro 1', 'Erro 2', 'Erro 3', 'Erro 4'], array_column($acao['itens'], 'nome'));
        $this->assertSame(['E-1', 'E-2', 'E-3', 'E-4'], array_column($acao['itens'], 'meta'), 'meta é o SKU');
        $this->assertSame('não publicado', $acao['itens'][0]['motivo'], 'o motivo é o rótulo REAL do status');

        // ⚠️ O número da ação continua sendo o da linha (5), não o dos itens (4):
        // a amostra é amostra.
        $this->assertSame(5, $acao['numero']);

        foreach ($acao['itens'] as $item) {
            foreach ($item as $campo => $valor) {
                $this->assertIsString($valor, "o campo '{$campo}' do item é string");
                $this->assertNotSame('', trim($valor), 'nunca vazio, nunca "—"');
                $this->assertNotSame('—', trim($valor));
            }
        }
    }

    public function test_conferidos_traz_os_produtos_prontos(): void
    {
        $produtos = [
            $this->produto('Erro', 'E-1', 'erro', 'não publicado'),
            $this->produto('Conferido A', 'C-A', 'pronto', 'conferido'),
            $this->produto('Conferido B', 'C-B', 'pronto', 'conferido'),
        ];

        $acao = $this->acao($this->service->acoes($this->alvo(), [$this->linha('conferidos', 2)], $this->jornadaVazia(), $produtos, null), 'conferidos');

        $this->assertSame(['Conferido A', 'Conferido B'], array_column($acao['itens'], 'nome'));
    }

    public function test_os_itens_dos_estagios_vem_da_jornada_com_a_nota_como_motivo(): void
    {
        $jornada = $this->jornada([
            'ready' => ['total' => 2, 'itens' => [$this->itemDeEstagio(10, 'Base no ar', 'B-10', '1 unidade · 2 no ar')]],
            'draft' => ['total' => 1, 'itens' => [$this->itemDeEstagio(11, 'Em preenchimento', 'B-11', 'Rascunho em preenchimento · faltam 3')]],
        ], total: 5);
        $linhas = [$this->linha('prontos_fase2', 2), $this->linha('rascunhos_pendencias', 1)];

        $saida = $this->service->acoes($this->alvo(), $linhas, $jornada, [], null);

        $this->assertSame(
            [['nome' => 'Base no ar', 'meta' => 'B-10', 'motivo' => '1 unidade · 2 no ar']],
            $this->acao($saida, 'prontos_fase2')['itens']
        );
        $this->assertSame('Rascunho em preenchimento · faltam 3', $this->acao($saida, 'rascunhos_pendencias')['itens'][0]['motivo']);
    }

    /**
     * ⚠️ `pausados` e os três motivos de acervo vêm com `itens: []` de PROPÓSITO:
     * não existe lista por anúncio sem uma consulta nova, e este serviço faz ZERO
     * consulta. Item inventado aqui seria enfeite.
     */
    public function test_pausados_e_os_motivos_de_acervo_vem_sem_itens(): void
    {
        $produtos = [$this->produto('Erro', 'E-1', 'erro', 'não publicado')];
        $linhas = [
            $this->linha('pausados'),
            $this->linha('ficha_incompleta'),
            $this->linha('perdendo_catalogo'),
            $this->linha('foto_insuficiente'),
            $this->linha('portal_novas'),
            $this->linha('alavancas'),
            $this->linha('reconectar', null),
        ];

        $saida = $this->service->acoes($this->alvo(), $linhas, $this->jornadaVazia(), $produtos, null);

        foreach ($saida['acoes'] as $acao) {
            $this->assertSame([], $acao['itens'], "'{$acao['chave']}' não tem lista por anúncio sem consulta nova");
        }
    }

    public function test_item_sem_nome_ou_sem_sku_nao_entra_na_sub_lista(): void
    {
        $produtos = [
            ['id' => 1, 'nome' => '', 'sku' => 'S-1', 'status' => ['chave' => 'erro', 'rotulo' => 'não publicado']],
            ['id' => 2, 'nome' => 'Sem SKU', 'sku' => '', 'status' => ['chave' => 'erro', 'rotulo' => 'não publicado']],
            $this->produto('Completo', 'S-3', 'erro', 'não publicado'),
        ];

        $acao = $this->acao($this->service->acoes($this->alvo(), [$this->linha('com_problema', 3)], $this->jornadaVazia(), $produtos, null), 'com_problema');

        $this->assertSame(['Completo'], array_column($acao['itens'], 'nome'), 'item que a tela não saberia descrever fica fora, nunca com "—"');
        $this->assertSame(3, $acao['numero'], 'o NÚMERO não muda: ele conta o universo, não a amostra mostrável');
    }

    // ═══ 6. Título e descrição — do servidor, sem rótulo de tela ═════════════

    public function test_titulo_e_o_texto_real_da_linha_e_a_descricao_e_medida(): void
    {
        $linhas = [
            ['texto' => 'Produtos com problema na publicação', 'numero' => 4, 'destino' => ['acao' => 'x'], 'chave' => 'com_problema'],
            ['texto' => 'Rascunhos com pendências', 'numero' => 2, 'exemplo' => ['nome' => 'Quase pronto', 'faltam' => 1], 'destino' => ['acao' => 'y'], 'chave' => 'rascunhos_pendencias'],
            ['texto' => 'Anúncios pausados ou sem estoque', 'numero' => 3, 'legado' => 2, 'destino' => ['acao' => 'z'], 'chave' => 'pausados'],
        ];

        $saida = $this->service->acoes($this->alvo(), $linhas, $this->jornadaVazia(), [], null);

        // O título é o texto que a tela JÁ mostra hoje, byte a byte: zero regressão.
        $this->assertSame('Produtos com problema na publicação', $this->acao($saida, 'com_problema')['titulo']);

        // A descrição interpola o que foi MEDIDO — `exemplo` e `legado` que a linha traz.
        $this->assertStringContainsString('Quase pronto', $this->acao($saida, 'rascunhos_pendencias')['descricao']);
        $this->assertStringContainsString('1 campo', $this->acao($saida, 'rascunhos_pendencias')['descricao'], 'singular: "falta 1 campo", nunca "faltam 1 campos"');
        $this->assertStringContainsString('2', $this->acao($saida, 'pausados')['descricao'], 'o legado medido aparece');

        foreach ($saida['acoes'] as $acao) {
            $this->assertNotSame('', $acao['titulo'], 'ação sem título não é clicável');
        }
    }

    /**
     * ⚠️ O rótulo do CTA e o rótulo da severidade são do CLIENTE, por
     * `chave`/`severidade` — mesmo precedente da Fase 178-01 e do 179-01. Nenhuma
     * chave `cta` no payload, e nenhum literal de tela do protótipo.
     */
    public function test_nenhum_rotulo_de_tela_no_payload(): void
    {
        $jornada = $this->jornada(['none' => ['total' => 2, 'itens' => []]], total: 9);
        $linhas = array_map(fn (string $c) => $this->linha($c), array_keys(AcoesDaContaService::SEVERIDADE));

        $saida = $this->service->acoes($this->alvo(), $linhas, $jornada, [], null);

        foreach ($saida['acoes'] as $acao) {
            $this->assertArrayNotHasKey('cta', $acao);
            $this->assertArrayNotHasKey('cta_rotulo', $acao);
            $this->assertArrayNotHasKey('sev_label', $acao);
            $this->assertArrayNotHasKey('sevLabel', $acao);
            $this->assertArrayNotHasKey('cor', $acao);
            $this->assertArrayNotHasKey('tint', $acao);
        }

        // Os literais do protótipo que são de TELA (`Visao Geral.dc.html:229, 511-520`).
        $json = json_encode($saida, JSON_UNESCAPED_UNICODE);
        foreach (['RECOMENDADO AGORA', 'Adiar até amanhã', 'Nada pendente neste filtro', 'Criar combos', 'Revisar'] as $literal) {
            $this->assertStringNotContainsString($literal, $json, "o literal de tela '{$literal}' mora no cliente");
        }
    }

    // ═══ 7. VETO AO PREJUÍZO — asserção NEGATIVA (VG-12 / EXMP-01) ═══════════

    /**
     * ⚠️ A ação "publicação com prejuízo" NÃO existe porque a rentabilidade não
     * existe neste sistema. Nasceria decorativa: botão sem destino.
     *
     * ⚠️ O cenário está CHEIO de propósito — todas as doze chaves, jornada com os
     * quatro estágios povoados, produtos em todos os status. Asserção de ausência
     * rodada com dado VAZIO é vazia por construção.
     */
    public function test_nenhuma_acao_de_prejuizo_ou_rentabilidade_em_cenario_cheio(): void
    {
        $jornada = $this->jornada([
            'none' => ['total' => 5, 'itens' => [$this->itemDeEstagio(1, 'A', 'A-1', 'Recebido do Portal do Cliente')]],
            'draft' => ['total' => 3, 'itens' => [$this->itemDeEstagio(2, 'B', 'B-1', 'Rascunho em preenchimento · faltam 2')]],
            'ready' => ['total' => 4, 'itens' => [$this->itemDeEstagio(3, 'C', 'C-1', '1 unidade · 2 no ar')]],
            'done' => ['total' => 2, 'itens' => [$this->itemDeEstagio(4, 'D', 'D-1', 'Kit 2 · 1 no ar')]],
        ], total: 14);
        $linhas = array_map(fn (string $c) => $this->linha($c, 3, ['legado' => 1, 'exemplo' => ['nome' => 'Quase pronto', 'faltam' => 2]]), array_keys(AcoesDaContaService::SEVERIDADE));
        $produtos = [
            $this->produto('Erro', 'E-1', 'erro', 'não publicado'),
            $this->produto('Pronto', 'P-1', 'pronto', 'conferido'),
            $this->produto('Publicado', 'PB-1', 'publicado', 'publicado'),
        ];

        $saida = $this->service->acoes($this->alvo(), $linhas, $jornada, $produtos, 0);

        // A prova de que o cenário NÃO está vazio (sem isto a varredura abaixo é decorativa).
        $this->assertGreaterThanOrEqual(12, count($saida['acoes']), 'o cenário tem de estar cheio para a asserção negativa valer');

        $json = json_encode($saida, JSON_UNESCAPED_UNICODE);
        foreach (['prejuizo', 'prejuízo', 'rentabilidade', 'margem', 'repasse', 'lucro', 'cmv'] as $proibido) {
            $this->assertStringNotContainsStringIgnoringCase($proibido, $json, "'{$proibido}' não tem fonte neste sistema: a ação nasceria decorativa");
        }

        // E a chave não existe no mapa de severidade — o que impede até de ser adiada.
        $this->assertArrayNotHasKey('prejuizo', AcoesDaContaService::SEVERIDADE);
        $this->assertArrayNotHasKey('rentabilidade', AcoesDaContaService::SEVERIDADE);

        // ⚠️ D-1 da Fase 177-03: o ERP NÃO é integração neste sistema (só
        // `erp.declarado`). Não nasce ação "Reconectar ERP" — seria botão sem destino.
        $this->assertArrayNotHasKey('reconectar_erp', AcoesDaContaService::SEVERIDADE);
        $this->assertStringNotContainsStringIgnoringCase('erp', $json);
    }

    public function test_o_payload_nao_vaza_segredo_da_conta(): void
    {
        $jornada = $this->jornada(['none' => ['total' => 1, 'itens' => [$this->itemDeEstagio(1, 'A', 'A-1', 'Recebido do Portal do Cliente')]]], total: 3);
        $saida = $this->service->acoes($this->alvo(), [$this->linha('reconectar', null, ['destino' => ['acao' => 'reconectar', 'url' => 'https://auth.mercadolivre.com.br/authorization?client_id=1']])], $jornada, [], 7);

        $json = json_encode($saida, JSON_UNESCAPED_UNICODE);
        foreach (['access_token', 'refresh_token', 'APP_USR', 'TG-', 'client_secret'] as $segredo) {
            $this->assertStringNotContainsString($segredo, $json, "'{$segredo}' nunca sai do servidor");
        }
    }

    // ═══ 8. Escalares — a tela preta de 07/10 ════════════════════════════════

    public function test_nenhum_campo_da_acao_e_objeto_fora_de_itens_e_destino(): void
    {
        $jornada = $this->jornada(['none' => ['total' => 2, 'itens' => [$this->itemDeEstagio(1, 'A', 'A-1', 'Recebido do Portal do Cliente')]]], total: 6);
        $linhas = array_map(fn (string $c) => $this->linha($c, 2, ['legado' => 1, 'exemplo' => ['nome' => 'X', 'faltam' => 1]]), array_keys(AcoesDaContaService::SEVERIDADE));
        $produtos = [$this->produto('Erro', 'E-1', 'erro', 'não publicado')];

        $saida = $this->service->acoes($this->alvo(), $linhas, $jornada, $produtos, null);

        foreach ($saida['acoes'] as $acao) {
            foreach ($acao as $campo => $valor) {
                if (in_array($campo, ['itens', 'destino'], true)) {
                    $this->assertIsArray($valor);

                    continue;
                }
                $this->assertFalse(is_array($valor) || is_object($valor), "o campo '{$campo}' tem de ser escalar (tela preta de 07/10)");
            }
            // E dentro de `itens`, tudo é string: nenhum segundo nível de array.
            foreach ($acao['itens'] as $item) {
                foreach ($item as $campo => $valor) {
                    $this->assertIsString($valor, "o campo '{$campo}' do item tem de ser string");
                }
            }
        }
    }

    // ═══ 9. O ADIAMENTO (VG-11) ══════════════════════════════════════════════

    public function test_adiar_guarda_por_usuario(): void
    {
        $this->adiamento->adiar(7, 'empresa-1', 'prontos_fase2');

        $this->assertSame(['prontos_fase2'], $this->adiamento->adiadas(7, 'empresa-1'));
        $this->assertSame([], $this->adiamento->adiadas(8, 'empresa-1'), 'o adiamento de um usuário não atinge outro (T-179-06)');
    }

    public function test_adiar_guarda_por_conta(): void
    {
        $this->adiamento->adiar(7, 'empresa-1', 'prontos_fase2');

        $this->assertSame([], $this->adiamento->adiadas(7, 'company-2'), 'o adiamento de uma conta não atinge outra (T-179-06)');
    }

    public function test_duas_chaves_coexistem_no_mesmo_saco_e_adiar_duas_vezes_nao_duplica(): void
    {
        $this->adiamento->adiar(7, 'empresa-1', 'prontos_fase2');
        $this->adiamento->adiar(7, 'empresa-1', 'pausados');
        $this->adiamento->adiar(7, 'empresa-1', 'prontos_fase2');

        $adiadas = $this->adiamento->adiadas(7, 'empresa-1');
        sort($adiadas);
        $this->assertSame(['pausados', 'prontos_fase2'], $adiadas);
    }

    /**
     * ⚠️ T-179-05: a `chaveAcao` vem do CLIENTE. Chave fora da lista fechada não é
     * gravada — senão o cliente poderia inflar o saco do cache com chaves
     * arbitrárias (T-179-07). A validação dura `in_array` na borda é do plano
     * 179-04; aqui o serviço se recusa a gravar.
     */
    public function test_chave_fora_da_lista_fechada_nao_e_gravada(): void
    {
        $this->adiamento->adiar(7, 'empresa-1', 'inventada_pelo_cliente');
        $this->adiamento->adiar(7, 'empresa-1', '../../admin');

        $this->assertSame([], $this->adiamento->adiadas(7, 'empresa-1'), 'o saco só aceita chave do mapa SEVERIDADE');
        $this->assertCount(12, AcoesDaContaService::SEVERIDADE, 'a lista é fechada em 12: o saco não passa disso (T-179-07)');
    }

    public function test_expira_em_e_amanha_as_oito_no_fuso_da_aplicacao(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 12, 23, 30, 0, 'America/Sao_Paulo'));

        $expira = $this->adiamento->expiraEm();

        $this->assertSame('2026-10-13 08:00:00', $expira->copy()->setTimezone('America/Sao_Paulo')->format('Y-m-d H:i:s'));
        $this->assertSame('America/Sao_Paulo', $expira->getTimezone()->getName());
        $this->assertSame($expira->toIso8601String(), $this->service->acoes($this->alvo(), [], $this->jornadaVazia(), [], null)['volta_em_iso']);
    }

    /**
     * ⚠️ `tomorrow()`, não `now()->addDay()`: "Adiar até amanhã" é o DIA SEGUINTE do
     * calendário no fuso da APLICAÇÃO, não 24 horas a partir de agora. Com o
     * instante de teste em UTC caindo num dia diferente de São Paulo, a diferença
     * aparece: 13/10 em UTC é ainda 12/10 aqui.
     */
    public function test_expira_em_usa_o_dia_do_calendario_no_fuso_da_aplicacao(): void
    {
        // 2026-10-13 02:00 UTC === 2026-10-12 23:00 em São Paulo.
        Carbon::setTestNow(Carbon::create(2026, 10, 13, 2, 0, 0, 'UTC'));

        $expira = $this->adiamento->expiraEm()->copy()->setTimezone('America/Sao_Paulo');

        $this->assertSame('2026-10-13 08:00:00', $expira->format('Y-m-d H:i:s'), 'o dia seguinte é o de São Paulo, não o de UTC');
    }

    public function test_as_sete_e_cinquenta_e_nove_de_amanha_a_acao_ainda_esta_adiada(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 12, 15, 0, 0, 'America/Sao_Paulo'));
        $this->adiamento->adiar(7, 'empresa-1', 'pausados');

        Carbon::setTestNow(Carbon::create(2026, 10, 13, 7, 59, 0, 'America/Sao_Paulo'));
        $this->assertSame(['pausados'], $this->adiamento->adiadas(7, 'empresa-1'));
    }

    public function test_as_oito_de_amanha_a_acao_voltou(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 12, 15, 0, 0, 'America/Sao_Paulo'));
        $this->adiamento->adiar(7, 'empresa-1', 'pausados');

        Carbon::setTestNow(Carbon::create(2026, 10, 13, 8, 0, 0, 'America/Sao_Paulo'));
        $this->assertSame([], $this->adiamento->adiadas(7, 'empresa-1'), 'às 08:00 em ponto já voltou');
    }

    public function test_adiado_as_vinte_e_tres_volta_amanha_as_oito(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 12, 23, 0, 0, 'America/Sao_Paulo'));
        $this->adiamento->adiar(7, 'empresa-1', 'pausados');

        Carbon::setTestNow(Carbon::create(2026, 10, 13, 8, 0, 0, 'America/Sao_Paulo'));
        $this->assertSame([], $this->adiamento->adiadas(7, 'empresa-1'), 'adiar às 23h não compra 24 horas, compra até as 8h de amanhã');
    }

    /**
     * ⚠️ A ARMADILHA DO DESENHO, e o único teste que a pega.
     *
     * O TTL do saco é o da entrada MAIS DISTANTE. Então uma entrada individual pode
     * ter vencido enquanto o saco ainda está vivo no cache. Sem o filtro por
     * entrada em `adiadas()`, a ação adiada ontem às 23h continuaria escondida
     * depois das 8h de hoje — exatamente quando deveria voltar.
     */
    public function test_entrada_vencida_dentro_de_saco_ainda_vivo_nao_esconde_a_acao(): void
    {
        // Dia 12 às 23h: `pausados` vence dia 13 às 08:00.
        Carbon::setTestNow(Carbon::create(2026, 10, 12, 23, 0, 0, 'America/Sao_Paulo'));
        $this->adiamento->adiar(7, 'empresa-1', 'pausados');

        // Dia 13 às 07h: `com_problema` vence dia 14 às 08:00 — e o saco passa a ter
        // TTL até dia 14, carregando a entrada de `pausados` junto.
        Carbon::setTestNow(Carbon::create(2026, 10, 13, 7, 0, 0, 'America/Sao_Paulo'));
        $this->adiamento->adiar(7, 'empresa-1', 'com_problema');
        $adiadas = $this->adiamento->adiadas(7, 'empresa-1');
        sort($adiadas);
        $this->assertSame(['com_problema', 'pausados'], $adiadas, 'às 07h as duas ainda estão adiadas');

        // Dia 13 às 09h: o saco está vivo (vence dia 14), mas `pausados` já venceu.
        Carbon::setTestNow(Carbon::create(2026, 10, 13, 9, 0, 0, 'America/Sao_Paulo'));
        $this->assertSame(
            ['com_problema'],
            $this->adiamento->adiadas(7, 'empresa-1'),
            'o filtro por ENTRADA é obrigatório: o TTL do saco não serve de relógio para cada item'
        );

        // ⚠️ E a FRONTEIRA, exatamente às 08:00 do dia 13, com o saco ainda vivo até
        // o dia 14. Este é o único ponto do arquivo que mede o `>` do filtro por
        // entrada: o teste "às 8h voltou" do saco de UMA entrada passa por mérito do
        // próprio store (o `ArrayStore::get()` do Laravel compara com `>=` e esquece
        // a chave no instante exato), então ele NÃO prova nada sobre esta comparação.
        // Sem esta asserção, trocar `isAfter($agora)` por `! isBefore($agora)` —
        // deixando a ação adiada no instante em que deveria voltar — sobreviveria
        // calado. Medido: sobreviveu, até esta linha existir.
        Carbon::setTestNow(Carbon::create(2026, 10, 13, 8, 0, 0, 'America/Sao_Paulo'));
        $this->assertSame(
            ['com_problema'],
            $this->adiamento->adiadas(7, 'empresa-1'),
            'às 08:00 EM PONTO a entrada já voltou: o instante da volta é inclusivo, não exclusivo'
        );
    }

    public function test_saco_corrompido_no_cache_devolve_vazio_sem_lancar(): void
    {
        foreach (['uma string solta', 12345, [['chave' => 'pausados']], [1, 2, 3], ['pausados' => ['nao' => 'iso']], ['pausados' => 'banana']] as $i => $lixo) {
            Cache::put("publicador:adiar:{$i}:empresa-1", $lixo, now()->addHour());

            $this->assertSame([], $this->adiamento->adiadas($i, 'empresa-1'), 'saco corrompido não lança e não esconde nada');
        }

        // ⚠️ ISCA: o saco BOM, gravado pelo mesmo caminho, ainda funciona — senão
        // este teste passaria com um `adiadas()` que devolvesse `[]` sempre.
        $this->adiamento->adiar(99, 'empresa-1', 'pausados');
        $this->assertSame(['pausados'], $this->adiamento->adiadas(99, 'empresa-1'));
    }

    public function test_acao_adiada_nao_aparece_e_nao_conta(): void
    {
        $linhas = [$this->linha('com_problema'), $this->linha('pausados'), $this->linha('portal_novas')];
        $this->adiamento->adiar(7, 'empresa-1', 'pausados');

        $saida = $this->service->acoes($this->alvo(), $linhas, $this->jornadaVazia(), [], 7);

        $this->assertSame(['com_problema', 'portal_novas'], $this->chavesDe($saida));
        $this->assertSame(['todas' => 2, 'critico' => 1, 'atencao' => 0, 'oportunidade' => 1], $saida['contagens'], 'a contagem é sobre a lista JÁ sem as adiadas');
        $this->assertSame(['pausados'], $saida['adiadas']);
    }

    /**
     * ⚠️ A `recomendada` é marcada DEPOIS do filtro de adiadas. Se fosse antes,
     * adiar a primeira deixaria a lista inteira sem recomendada — e o protótipo
     * sempre destaca uma (`acts[0]`, `Visao Geral.dc.html:514`).
     */
    public function test_adiar_a_recomendada_promove_a_proxima(): void
    {
        $linhas = [$this->linha('com_problema'), $this->linha('pausados'), $this->linha('portal_novas')];

        $antes = $this->service->acoes($this->alvo(), $linhas, $this->jornadaVazia(), [], 7);
        $this->assertSame('com_problema', $antes['acoes'][0]['chave']);
        $this->assertTrue($antes['acoes'][0]['recomendada']);

        $this->adiamento->adiar(7, 'empresa-1', 'com_problema');

        $depois = $this->service->acoes($this->alvo(), $linhas, $this->jornadaVazia(), [], 7);
        $this->assertSame('pausados', $depois['acoes'][0]['chave']);
        $this->assertTrue($depois['acoes'][0]['recomendada'], 'a lista nunca fica sem recomendada');
        $this->assertSame(1, count(array_filter($depois['acoes'], fn (array $a) => $a['recomendada'] === true)));
    }

    public function test_user_id_nulo_nao_filtra_nada(): void
    {
        $this->adiamento->adiar(7, 'empresa-1', 'pausados');

        $saida = $this->service->acoes($this->alvo(), [$this->linha('pausados')], $this->jornadaVazia(), [], null);

        $this->assertSame(['pausados'], $this->chavesDe($saida), 'chamador sem usuário (nunca a tela) não aplica adiamento');
        $this->assertSame([], $saida['adiadas']);
    }

    /**
     * O adiamento PONTA A PONTA: adiar → `acoes()` de novo não traz a ação (é isso
     * que "recarregar a página não traz a ação de volta" significa), outro usuário
     * continua vendo, e amanhã às 8h ela voltou para os dois.
     */
    public function test_adiamento_ponta_a_ponta_sobrevive_a_recarga_e_expira_as_oito(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 12, 14, 0, 0, 'America/Sao_Paulo'));
        $linhas = [$this->linha('com_problema'), $this->linha('pausados')];

        $this->adiamento->adiar(7, 'empresa-1', 'pausados');

        // "Recarregar a página" = chamar `acoes()` outra vez, do zero.
        $this->assertSame(['com_problema'], $this->chavesDe($this->service->acoes($this->alvo(), $linhas, $this->jornadaVazia(), [], 7)));
        $this->assertSame(['com_problema'], $this->chavesDe($this->service->acoes($this->alvo(), $linhas, $this->jornadaVazia(), [], 7)));

        // Outro usuário, mesma conta: continua vendo.
        $this->assertSame(['com_problema', 'pausados'], $this->chavesDe($this->service->acoes($this->alvo(), $linhas, $this->jornadaVazia(), [], 8)));

        // Outra conta, mesmo usuário: continua vendo.
        $this->assertSame(['com_problema', 'pausados'], $this->chavesDe($this->service->acoes($this->alvo('company-2'), $linhas, $this->jornadaVazia(), [], 7)));

        Carbon::setTestNow(Carbon::create(2026, 10, 13, 8, 0, 0, 'America/Sao_Paulo'));
        $this->assertSame(['com_problema', 'pausados'], $this->chavesDe($this->service->acoes($this->alvo(), $linhas, $this->jornadaVazia(), [], 7)), 'às 8h voltou');
    }

    // ═══ 10. NÃO-REGRESSÃO: toda linha de hoje virou ação ════════════════════

    /**
     * ⚠️ O GATE MAIS IMPORTANTE DESTE PLANO. A saída REAL de `oQueFazerAgora()`
     * (o mesmo cenário que `PainelVisaoGeralServiceTest` usa para travar a ordem
     * fixa, com as dez linhas do ramo ativo) entra em `acoes()`, e cada linha tem
     * de sair como ação com o MESMO número e o MESMO destino.
     *
     * ⚠️ Contagem EXATA (`count($linhas) + 1`, o `+1` é `sem_publicacao`), não
     * `assertContains`: uma ação engolida passaria por "contém".
     */
    public function test_toda_linha_de_hoje_virou_acao_com_contagem_exata(): void
    {
        [$linhas, $alvo] = $this->linhasReaisDeHoje();

        $this->assertCount(10, $linhas, 'o cenário tem de produzir as DEZ linhas do ramo ativo, senão o gate não prova nada');

        $jornada = $this->jornada(['none' => ['total' => 6, 'itens' => []]], total: 16);
        $saida = $this->service->acoes($alvo, $linhas, $jornada, [], null);

        $this->assertCount(count($linhas) + 1, $saida['acoes'], 'cada linha virou ação, mais a nova `sem_publicacao` — nada foi engolido');

        $porChave = collect($saida['acoes'])->keyBy('chave');
        foreach ($linhas as $linha) {
            $chave = $linha['chave'];
            $this->assertTrue($porChave->has($chave), "a linha '{$chave}' sumiu");
            $this->assertSame($linha['numero'], $porChave[$chave]['numero'], "o número de '{$chave}' mudou");
            $this->assertSame($linha['destino'], $porChave[$chave]['destino'], "o destino de '{$chave}' mudou");
            $this->assertSame($linha['texto'], $porChave[$chave]['titulo'], "o texto de '{$chave}' mudou");
        }

        // Nenhuma chave de ação ficou fora do mapa de severidade (ou seja: nenhuma
        // linha de hoje caiu no default por acidente).
        foreach ($this->chavesDe($saida) as $chave) {
            $this->assertArrayHasKey($chave, AcoesDaContaService::SEVERIDADE, "a linha '{$chave}' não tem severidade própria no mapa");
        }
    }

    public function test_a_linha_unica_de_reconexao_virou_a_acao_critica(): void
    {
        $painel = new PainelVisaoGeralService(new AcervoTriagemService());
        $alvo = $this->alvo();
        $linhas = $painel->oQueFazerAgora($alvo, ['token' => 'expirado', 'link_reconexao' => 'https://reconectar', 'company_id' => null, 'portal' => []], ['com_problema' => 9], ['chips' => []], [], ['situacao' => 'novas', 'novas' => 4]);

        $this->assertCount(1, $linhas);

        // ⚠️ Jornada POVOADA de propósito: sem conta ativa, nem a ação nova pode
        // aparecer — a regra do handoff é UMA linha só, e ela não pode furar aqui.
        // ⚠️ Asserção de ausência rodada com o dado que a PRODUZIRIA: com
        // `none.total = 5`, `sem_publicacao` nasceria em qualquer outro cenário. Com
        // `none.total = 0` este teste seria vazio por construção.
        $jornadaPovoada = $this->jornada(['none' => ['total' => 5, 'itens' => []]], total: 9);
        $this->assertNotNull(
            $this->acao($this->service->acoes($this->alvo(), [], $jornadaPovoada, [], null), 'sem_publicacao'),
            'a prova de que esta jornada PRODUZ `sem_publicacao` quando a conta está ativa'
        );

        $saida = $this->service->acoes($alvo, $linhas, $jornadaPovoada, [], null);

        $this->assertSame(['reconectar'], $this->chavesDe($saida));
        $this->assertNull($this->acao($saida, 'sem_publicacao'), 'sem conta ativa nada mais aparece — nem a ação nova');
        $this->assertSame('critico', $saida['acoes'][0]['severidade']);
        $this->assertTrue($saida['acoes'][0]['recomendada']);
        $this->assertSame(['todas' => 1, 'critico' => 1, 'atencao' => 0, 'oportunidade' => 0], $saida['contagens']);
    }

    // ═══ 11. CUSTO: zero consulta, com isca ══════════════════════════════════

    public function test_acoes_nao_faz_nenhuma_consulta_com_isca(): void
    {
        [$linhas, $alvo] = $this->linhasReaisDeHoje();
        $jornada = $this->jornada([
            'none' => ['total' => 4, 'itens' => [$this->itemDeEstagio(1, 'A', 'A-1', 'Recebido do Portal do Cliente')]],
            'ready' => ['total' => 2, 'itens' => [$this->itemDeEstagio(2, 'B', 'B-1', '1 unidade · 2 no ar')]],
        ], total: 12);
        $produtos = [$this->produto('Erro', 'E-1', 'erro', 'não publicado')];

        DB::flushQueryLog();
        DB::enableQueryLog();
        $saida = $this->service->acoes($alvo, $linhas, $jornada, $produtos, null);
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(0, $consultas, 'tudo chega pronto: `acoes()` não consulta nada');
        $this->assertGreaterThan(0, count($saida['acoes']), 'e produziu ações de verdade — não zero por lista vazia');

        // ⚠️ ISCA: um trecho que SABEMOS que consulta. Sem ela, um
        // `enableQueryLog()` mal posicionado daria zero por construção.
        DB::flushQueryLog();
        DB::enableQueryLog();
        (new PainelVisaoGeralService(new AcervoTriagemService()))->ultimasPublicacoes(['company' => Company::factory()->create(), 'mlb_empresa' => null]);
        $daIsca = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $daIsca, 'a isca TEM de consultar: se ela deu zero, o medidor está quebrado, não o código');
    }

    /**
     * O adiamento custa UMA leitura de cache por chamada, não uma por ação.
     * Varrer o Redis por prefixo em request de tela é o que o desenho de "um saco
     * por usuário + conta" existe para evitar.
     */
    public function test_o_adiamento_le_o_cache_uma_vez_por_chamada(): void
    {
        $linhas = array_map(fn (string $c) => $this->linha($c), array_keys(AcoesDaContaService::SEVERIDADE));

        $falso = \Mockery::mock(AdiamentoDeAcoes::class);
        $falso->shouldReceive('adiadas')->once()->with(7, 'empresa-1')->andReturn(['pausados']);
        $falso->shouldReceive('expiraEm')->once()->andReturn(Carbon::create(2026, 10, 13, 8, 0, 0, 'America/Sao_Paulo'));

        $saida = (new AcoesDaContaService($falso))->acoes($this->alvo(), $linhas, $this->jornadaVazia(), [], 7);

        $this->assertNotContains('pausados', $this->chavesDe($saida));
    }

    // ═══ 12. O cabeçalho da ação × a sub-lista de "Prontos para a Fase 2" ════

    /**
     * ⚠️ A DIVERGÊNCIA FOI RESOLVIDA EM 12/10/2026 — este teste REAPONTOU.
     *
     * Até o plano 179-02B, `PainelVisaoGeralService::prontosParaFase2()` olhava
     * **só o status** (`STATUS_NO_AR`) e o estágio `ready` da Jornada usava os
     * DOIS ramos de `bucketDaFase()` — status **ou** anúncio CREATED vivo. A base
     * em `erro` **com anúncio vivo e sem kit** entrava em `ready` e **não** entrava
     * na linha. Na MESMA tela o anel dizia um número e a ação dizia outro; e
     * **dentro da mesma ação** a sub-lista mostrava 2 itens sob um cabeçalho que
     * dizia 1.
     *
     * **A DECISÃO DO USUÁRIO — 12/10/2026.** Pergunta levada a ele: *"Um produto
     * cuja publicação falhou, mas que JÁ TEM anúncio vivo no ar e sem kit, conta
     * como 'Pronto para Fase 2'?"* Opções: (1) alinhar os três lugares; (2) erro é
     * erro e fica fora de todo lugar; (3) ver o número real de produção primeiro.
     * **Escolha: a opção 1**, com o aviso explícito de que isso muda o modal da
     * Fase 178 já aprovado em produção. A regra virou uma só —
     * `ProgramasPublicadorService::prontoParaFase2()`.
     *
     * ═══ ⚠️ POR QUE ESTE TESTE TEM DOIS CASOS ════════════════════════════════
     *
     * Este teste é **SINTÉTICO**: `$linhas` e `$jornada` são montados à mão. Então
     * ele **não prova nada sobre o alinhamento** — os números vêm cravados na
     * entrada. Reapontar só os valores deixaria o gate vazio por construção.
     *
     * - **Caso A (alinhado):** linha = 2 e `ready` = 2. Prova o que o usuário vai
     *   ver: o número do cabeçalho **é igual** à contagem da sub-lista.
     * - **Caso B (ISCA):** a divergência ANTIGA cravada (linha = 1, `ready` = 2).
     *   ⚠️ O serviço **NÃO alinha nada por conta própria** — ele repassa o número
     *   da linha e os itens do estágio, cada um da sua fonte. É isto que prova que
     *   a igualdade do caso A vem das **REGRAS em `app/`**, e não de um alinhamento
     *   escondido dentro de `AcoesDaContaService`. A mutação M10 (recontar o número
     *   pelos itens do estágio) reprova exatamente aqui.
     *
     * O gate de verdade sobre as três regras está em `ProntoParaFase2Test`, que usa
     * produtos reais e compara **conjuntos de SKU**.
     */
    public function test_o_numero_da_acao_de_prontos_fase_2_bate_com_a_sub_lista(): void
    {
        // ─── Caso A: o cenário ALINHADO (o que a tela mostra desde 12/10/2026) ───
        $linhaDaAcao = $this->linha('prontos_fase2', 2);          // o que prontosParaFase2() conta HOJE
        $jornada = $this->jornada(['ready' => ['total' => 2, 'itens' => [    // o que o anel conta
            $this->itemDeEstagio(1, 'Base publicada', 'B-1', '1 unidade · 1 no ar'),
            $this->itemDeEstagio(2, 'Base em erro com anúncio vivo', 'B-2', '1 unidade · 1 no ar'),
        ]]], total: 2);

        $saida = $this->service->acoes($this->alvo(), [$linhaDaAcao], $jornada, [], null);
        $acao = $this->acao($saida, 'prontos_fase2');

        $doAnel = 2;
        $daAcao = $acao['numero'];

        $this->assertSame(2, $daAcao, 'ERA 1 antes do alinhamento de 12/10/2026, quando a ação contava só pelo status');
        $this->assertSame(
            0,
            $doAnel - $daAcao,
            'A DIVERGÊNCIA ERA 1 e foi RESOLVIDA em 12/10/2026 por decisão do usuário. Se '
            .'voltou a 1, alguém desfez o alinhamento — reler a 179-02B-SUMMARY antes de mexer.'
        );

        // ⚠️ O GATE DO CABEÇALHO: o número da linha e a contagem da sub-lista são
        // o MESMO número. Antes do alinhamento abrir a ação mostrava DOIS produtos
        // sob um cabeçalho que dizia UM — a divergência aparecia dentro da própria ação.
        $this->assertCount(2, $acao['itens'], 'os itens vêm do estágio `ready`, com o teto de 4');
        $this->assertSame(
            count($acao['itens']),
            $acao['numero'],
            'o cabeçalho da ação e a sub-lista dela discordam. ERA a contradição medida na '
            .'179-02 (2 itens sob um cabeçalho dizendo 1); desde 12/10/2026 as duas fontes '
            .'são a mesma regra e isto tem de bater.'
        );

        // ─── Caso B (ISCA): o serviço NÃO alinha nada por conta própria ──────────
        // A divergência antiga, cravada na ENTRADA. O serviço tem de repassar as duas
        // fontes fielmente: o `numero` da linha e os `itens` do estágio. Se ele
        // "consertasse" aqui, a igualdade do caso A seria dele e não das regras — e o
        // gate acima seria decorativo.
        $divergente = $this->service->acoes(
            $this->alvo(),
            [$this->linha('prontos_fase2', 1)],
            $jornada,
            [],
            null,
        );
        $daIsca = $this->acao($divergente, 'prontos_fase2');

        $this->assertSame(1, $daIsca['numero'], 'ISCA: o número tem de vir da LINHA, intacto');
        $this->assertCount(2, $daIsca['itens'], 'ISCA: os itens têm de vir do ESTÁGIO, intactos');
        $this->assertNotSame(
            count($daIsca['itens']),
            $daIsca['numero'],
            'ISCA QUEBRADA: o serviço passou a alinhar o número com os itens por conta '
            .'própria. Isso esconderia do usuário uma divergência futura entre as regras e '
            .'tornaria vazio o gate de igualdade do caso A acima.'
        );
    }

    // ═══ Cenário real compartilhado ══════════════════════════════════════════

    /**
     * As DEZ linhas do ramo ativo de `oQueFazerAgora()`, de verdade — o mesmo
     * cenário de `PainelVisaoGeralServiceTest::test_o_que_fazer_agora_todas_as_linhas…`.
     *
     * @return array{0: list<array>, 1: array}
     */
    private function linhasReaisDeHoje(): array
    {
        $company = Company::factory()->create();
        MlAcervoItem::create([
            'company_id' => $company->id, 'ml_item_id' => 'MLBA1', 'title' => 'Item', 'status' => 'paused',
            'available_quantity' => 10, 'sold_quantity' => 0, 'nota_ecf' => 60,
            'motivos' => [MlAcervoItem::MOTIVO_PAUSADO], 'severidade' => MlAcervoItem::SEVERIDADE_CRITICA,
            'origem' => MlAcervoItem::ORIGEM_LEGADO, 'coletado_em' => now(),
        ]);
        PubTarefa::create([
            'tipo' => PubTarefa::TIPO_ALAVANCAS, 'status' => PubTarefa::PENDENTE,
            'company_id' => $company->id, 'conta_chave' => $company->chaveContaMl(),
            'itens' => [['ml_item_id' => 'MLBA9', 'listing_type_id' => 'gold_special']],
            'publicado_em' => now()->subDay(), 'prazo' => now()->addDays(3)->toDateString(),
            'checklist' => PubTarefa::checklistInicial(),
        ]);

        $alvo = ['mlb_empresa' => null, 'company' => $company, 'programa' => 'gestao', 'chave' => $company->chaveContaMl()];

        $linhas = (new PainelVisaoGeralService(new AcervoTriagemService()))->oQueFazerAgora(
            $alvo,
            ['token' => 'ativo', 'link_reconexao' => null, 'company_id' => $company->id, 'portal' => []],
            ['com_problema' => 3, 'publicados' => 2, 'conferidos' => 1],
            ['chips' => [
                ['chave' => MlAcervoItem::MOTIVO_PAUSADO, 'label' => 'Pausado', 'count' => 2, 'cor' => 'red'],
                ['chave' => MlAcervoItem::MOTIVO_SEM_ESTOQUE, 'label' => 'Sem estoque', 'count' => 1, 'cor' => 'red'],
                ['chave' => MlAcervoItem::MOTIVO_FICHA_INCOMPLETA, 'label' => 'Ficha incompleta', 'count' => 2, 'cor' => 'amber'],
                ['chave' => MlAcervoItem::MOTIVO_PERDENDO_CATALOGO, 'label' => 'Perdendo catálogo', 'count' => 4, 'cor' => 'amber'],
                ['chave' => MlAcervoItem::MOTIVO_FOTO_INSUFICIENTE, 'label' => 'Foto insuficiente', 'count' => 1, 'cor' => 'amber'],
            ]],
            [],
            ['situacao' => 'novas', 'novas' => 5],
            [['nome' => 'Quase pronto', 'status' => ['chave' => 'conferir', 'faltam' => 2]]],
        );

        return [$linhas, $alvo];
    }
}
