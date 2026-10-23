<?php

namespace Tests\Feature\Publicador\Agente;

use App\Models\PubAgenteConfiguracao;
use App\Models\PubAgenteExecucao;
use App\Models\PubAprovacaoEvento;
use App\Models\PubAprovacaoOferta;
use App\Models\User;
use App\Services\Publicador\Agente\AtividadeDoAgente;
use App\Services\Publicador\Agente\ConfiguracaoDoAgenteService;
use App\Services\Publicador\Aprovacao\HistoricoDaOfertaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * O REGISTRO DE ATIVIDADE DO AGENTE (E1, 23/10/2026) — e o que cada caso existe para pegar.
 *
 * ⚠️⚠️ **A pergunta respondida antes de cada caso: "o que precisaria estar errado no mundo para ele
 * falhar?"** — *"nada"* é achado, não aprovação.
 *
 *  1. UNIÃO       → a tela mostrar só uma das três fontes. O caso cria evento nas TRÊS e cobra as
 *                   três na mesma lista, **ordenadas por `quando` decrescente**.
 *  2. PÁGINA      → ordenação instável. `quando` sozinho não desempata (duas linhas no mesmo
 *                   segundo), e aí a MESMA linha aparece em duas páginas e outra em nenhuma. O caso
 *                   junta as duas páginas e cobra **conjunto igual ao total, sem repetição**.
 *  3. DEDUPE      → `UNION` em vez de `UNION ALL`. Duas pausas idênticas no mesmo segundo são
 *                   eventos legítimos; `UNION` engoliria uma — perder registro de auditoria em
 *                   silêncio é o pior defeito possível nesta tela.
 *  4. OUTRA LOJA  → vazamento entre contas. É literalmente o que a demonstração da E1 cobra
 *                   (*"outra loja permanece igual"*).
 *  5. FILTROS     → filtro que não filtra, e filtro forjado que vira erro na cara da pessoa (aqui
 *                   ele é **descartado em silêncio**, como na fila de Aprovação).
 *  6. RESULTADO   → o filtro de resultado é do PREPARO. O caso prova que ele **exclui** as outras
 *                   duas fontes de propósito (a tela avisa) em vez de as trazer sem filtrar.
 *  7. DATA        → intervalo invertido devolvendo lista vazia em silêncio. As pontas são trocadas.
 *  8. LINKS       → link que leva a 404. A rota da Aprovação recebe **`Company`**, então conta sem
 *                   `Company` **não** ganha esse link — e o caso cobra as duas direções.
 *  9. BIJEÇÃO     → estado novo em `PubAgenteExecucao` sem rótulo, aparecendo cru na tela.
 * 10. HONESTIDADE → a linha da configuração **não afirma o que mudou** (esta fatia tem o snapshot,
 *                   não o diff). O caso trava a frase.
 */
class RegistroDeAtividadeDoAgenteTest extends TestCase
{
    use CenarioDoAgente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarAgente();
    }

    private function atividade(array $filtros = [], int $pagina = 1): array
    {
        return app(AtividadeDoAgente::class)->paraConta($this->conta(), $filtros, $pagina);
    }

    /** Todas as linhas da página, achatadas dos grupos de dia. */
    private function linhas(array $r): array
    {
        $tudo = [];
        foreach ($r['dias'] as $d) {
            foreach ($d['linhas'] as $l) {
                $tudo[] = $l;
            }
        }

        return $tudo;
    }

    /** Uma execução de preparo crua, com a data que o caso precisa. */
    private function execucao(array $dados = []): PubAgenteExecucao
    {
        $n = PubAgenteExecucao::query()->count() + 1;

        return PubAgenteExecucao::create([
            'uuid' => sprintf('%08d-0000-4000-8000-%012d', $n, $n),
            'conta_chave' => $this->conta()['chave'],
            'origem' => PubAgenteExecucao::ORIGEM_AUTOMATICA,
            'configuracao_versao' => 1,
            'chave_logica' => str_pad((string) $n, 64, 'k'),
            'estado' => PubAgenteExecucao::CONCLUIDA,
            'iniciada_em' => now(),
            'concluida_em' => now(),
            ...$dados,
        ]);
    }

    /** Uma oferta no portão desta loja, com um evento registrado pela porta única. */
    private function eventoDaOferta(string $tipo, string $texto, string $codigo = 'OFE'): PubAprovacaoEvento
    {
        // O cenario resolve o rascunho pelo produto DO PORTAL (`rascunhoDo(EstruturaProduto)`),
        // nao pelo `PubProduto` — e e por isso que os dois sao pegos aqui.
        // Codigo PROPRIO por padrao: `estrutura_produtos` tem unique (company_id, codigo), e o
        // caso que cria um produto E um evento criaria dois 'CAD' na mesma empresa.
        $doPortal = $this->produtoDoPortal($codigo);
        $pub = $this->noPublicador($doPortal);
        $r = $this->rascunhoDo($doPortal);
        $alvo = $r->alvos()->first();
        $oferta = PubAprovacaoOferta::create([
            'alvo_id' => $alvo->id, 'rascunho_id' => $r->id, 'produto_id' => $pub->id,
            'company_id' => $this->empresa->id, 'origem' => PubAprovacaoOferta::ORIGEM_MANUAL,
        ]);

        return app(HistoricoDaOfertaService::class)->registrar($oferta, $this->admin, $tipo, $texto);
    }

    public function test_as_tres_fontes_entram_na_mesma_lista_em_ordem_decrescente_atividade_e1(): void
    {
        // (1) configuração: `salvar` grava versão; (2) preparo; (3) evento da oferta.
        $this->ligar();
        $pub = $this->noPublicador();
        $this->execucao(['produto_id' => $pub->id, 'concluida_em' => now()->subMinutes(10), 'iniciada_em' => now()->subMinutes(12)]);
        $this->eventoDaOferta(PubAprovacaoEvento::APROVACAO, 'aprovada na conferência');

        $r = $this->atividade();
        $linhas = $this->linhas($r);

        $fontes = array_values(array_unique(array_column($linhas, 'fonte')));
        sort($fontes);
        $this->assertSame(
            [AtividadeDoAgente::FONTE_APROVACAO, AtividadeDoAgente::FONTE_CONFIGURACAO, AtividadeDoAgente::FONTE_PREPARO],
            $fontes,
            'faltou fonte na união — a tela mostraria só parte da atividade',
        );

        // ⚠️ A ordenação é cobrada de verdade: `quando` nunca sobe de uma linha para a seguinte.
        $quandos = array_column($linhas, 'quando');
        $decrescente = $quandos;
        rsort($decrescente);
        $this->assertSame($decrescente, $quandos, 'a atividade não veio do mais novo para o mais antigo');

        // ⚠️ ISCA: a sonda não está medindo lista vazia.
        $this->assertGreaterThanOrEqual(3, count($linhas), 'a atividade veio com menos de três linhas — o cenário não montou');

        // E cada linha diz a fonte em pt-BR: nome de tabela não vai para a tela.
        foreach ($linhas as $l) {
            $this->assertNotSame('', trim((string) $l['fonte_rotulo']), 'linha sem rótulo de fonte');
            $this->assertNotSame($l['fonte'], $l['fonte_rotulo'], 'o rótulo da fonte é a chave crua');
        }
    }

    public function test_a_paginacao_nao_repete_nem_perde_linha_atividade_e1(): void
    {
        // ⚠️⚠️ TODAS no MESMO segundo, de propósito: é a condição em que ordenação por `quando`
        // sozinho fica instável. Com `POR_PAGINA + 3` linhas a segunda página existe.
        $quando = now()->subHour();
        $quantas = AtividadeDoAgente::POR_PAGINA + 3;
        for ($i = 0; $i < $quantas; $i++) {
            $this->execucao(['iniciada_em' => $quando, 'concluida_em' => $quando]);
        }

        $p1 = $this->atividade([], 1);
        $p2 = $this->atividade([], 2);

        $this->assertSame($quantas, $p1['total'], 'o total não bate com o que existe no banco');
        $this->assertSame(2, $p1['paginas'], 'o número de páginas não bate com o total');
        $this->assertCount(AtividadeDoAgente::POR_PAGINA, $this->linhas($p1), 'a primeira página não veio cheia');
        $this->assertCount(3, $this->linhas($p2), 'a segunda página não trouxe o resto');

        $ids = array_merge(array_column($this->linhas($p1), 'id'), array_column($this->linhas($p2), 'id'));
        $this->assertCount($quantas, $ids, 'as duas páginas somadas não dão o total');
        $this->assertCount($quantas, array_unique($ids), 'a MESMA linha apareceu em duas páginas — a ordenação é instável');
    }

    public function test_a_uniao_nao_deduplica_evento_identico_atividade_e1(): void
    {
        $this->ligar();
        $cfg = PubAgenteConfiguracao::query()->where('conta_chave', $this->conta()['chave'])->firstOrFail();

        // Duas versões com o MESMO snapshot, autor e segundo. `UNION` engoliria uma.
        $quando = now()->subMinutes(5);
        foreach ([90, 91] as $v) {
            DB::table('pub_agente_configuracao_versoes')->insert([
                'configuracao_id' => $cfg->id, 'versao' => $v,
                'dados' => json_encode(['habilitado' => true, 'controle' => 'pausado']),
                'autor_id' => $this->admin->id, 'criada_em' => $quando,
            ]);
        }

        $linhas = array_values(array_filter(
            $this->linhas($this->atividade(['fonte' => AtividadeDoAgente::FONTE_CONFIGURACAO])),
            fn (array $l) => in_array($l['versao'], [90, 91], true),
        ));

        $this->assertCount(2, $linhas, 'uma das duas versões idênticas foi engolida — `UNION` em vez de `UNION ALL` perde auditoria em silêncio');
    }

    public function test_a_atividade_de_outra_loja_nao_aparece_atividade_e1(): void
    {
        $minha = $this->execucao();

        // Execução de OUTRA conta, com chave diferente.
        $outra = $this->execucao(['conta_chave' => 'company-999999']);

        $ids = array_column($this->linhas($this->atividade()), 'id');

        $this->assertContains(AtividadeDoAgente::FONTE_PREPARO."-{$minha->id}", $ids, 'a execução desta loja não apareceu');
        $this->assertNotContains(AtividadeDoAgente::FONTE_PREPARO."-{$outra->id}", $ids, 'a atividade de OUTRA loja vazou para esta tela');
    }

    public function test_os_filtros_recortam_e_o_filtro_forjado_e_descartado_em_silencio_atividade_e1(): void
    {
        $this->ligar();
        $pub = $this->noPublicador();
        $manual = $this->execucao(['origem' => PubAgenteExecucao::ORIGEM_MANUAL, 'produto_id' => $pub->id, 'solicitada_por' => $this->admin->id]);
        $auto = $this->execucao(['origem' => PubAgenteExecucao::ORIGEM_AUTOMATICA]);

        // fonte
        $soPreparo = $this->linhas($this->atividade(['fonte' => AtividadeDoAgente::FONTE_PREPARO]));
        $this->assertNotEmpty($soPreparo, 'o filtro de fonte zerou a lista');
        $this->assertSame([AtividadeDoAgente::FONTE_PREPARO], array_values(array_unique(array_column($soPreparo, 'fonte'))), 'o filtro de fonte deixou passar outra fonte');

        // origem — ⚠️ `ao_salvar` conta como MANUAL (alguém salvou e isso disparou o preparo)
        $soManual = array_column($this->linhas($this->atividade(['origem' => AtividadeDoAgente::ORIGEM_MANUAL])), 'id');
        $this->assertContains(AtividadeDoAgente::FONTE_PREPARO."-{$manual->id}", $soManual, 'o preparo manual saiu do filtro "uma pessoa"');
        $this->assertNotContains(AtividadeDoAgente::FONTE_PREPARO."-{$auto->id}", $soManual, 'o preparo automático entrou no filtro "uma pessoa"');

        // produto
        $soProduto = $this->linhas($this->atividade(['produto_id' => $pub->id]));
        $this->assertNotEmpty($soProduto, 'o filtro por produto zerou a lista');
        foreach ($soProduto as $l) {
            $this->assertSame((int) $pub->id, $l['produto']['id'] ?? null, 'o filtro por produto deixou passar outro produto');
        }

        // ⚠️ FORJADO: descartado em silêncio, não vira erro nem lista vazia.
        $forjado = $this->atividade(['fonte' => 'tabela_inventada', 'origem' => 'alienigena', 'resultado' => 'nao_existe', 'produto_id' => 'abc']);
        $this->assertNull($forjado['filtros']['fonte'], 'fonte forjada não foi descartada');
        $this->assertNull($forjado['filtros']['origem'], 'origem forjada não foi descartada');
        $this->assertNull($forjado['filtros']['resultado'], 'resultado forjado não foi descartado');
        $this->assertNull($forjado['filtros']['produto_id'], 'produto forjado não foi descartado');
        $this->assertNotEmpty($this->linhas($forjado), 'filtro forjado esvaziou a lista em vez de ser ignorado');
    }

    public function test_o_filtro_de_resultado_e_do_preparo_e_exclui_as_outras_fontes_atividade_e1(): void
    {
        $this->ligar();
        $this->eventoDaOferta(PubAprovacaoEvento::APROVACAO, 'aprovada');
        $bloqueada = $this->execucao(['estado' => PubAgenteExecucao::BLOQUEADA, 'motivo' => 'sem foto']);
        $this->execucao(['estado' => PubAgenteExecucao::CONCLUIDA]);

        $r = $this->atividade(['resultado' => PubAgenteExecucao::BLOQUEADA]);
        $linhas = $this->linhas($r);

        $this->assertCount(1, $linhas, 'o filtro de resultado trouxe mais do que as execuções bloqueadas');
        $this->assertSame(AtividadeDoAgente::FONTE_PREPARO."-{$bloqueada->id}", $linhas[0]['id'], 'veio a execução errada');
        $this->assertSame('Faltam dados', $linhas[0]['resultado_rotulo'], 'o estado apareceu cru na tela');

        // ⚠️ E a tela sabe que ficou de fora: o serviço devolve o filtro aplicado, e o componente
        // mostra o aviso. Sem ele a pessoa acharia que perdeu registro.
        $this->assertSame(PubAgenteExecucao::BLOQUEADA, $r['filtros']['resultado'], 'o filtro aplicado não voltou nas props — a tela não teria como avisar');
    }

    public function test_intervalo_de_data_invertido_tem_as_pontas_trocadas_atividade_e1(): void
    {
        $velha = $this->execucao(['iniciada_em' => now()->subDays(5), 'concluida_em' => now()->subDays(5)]);
        $nova = $this->execucao(['iniciada_em' => now(), 'concluida_em' => now()]);

        $ontem = now()->setTimezone('America/Sao_Paulo')->subDay()->format('Y-m-d');
        $hoje = now()->setTimezone('America/Sao_Paulo')->format('Y-m-d');

        // "de hoje até ontem" é erro de digitação — e lista vazia faria a pessoa achar que não houve nada.
        $r = $this->atividade(['de' => $hoje, 'ate' => $ontem]);

        $this->assertSame($ontem, $r['filtros']['de'], 'as pontas do intervalo não foram trocadas');
        $this->assertSame($hoje, $r['filtros']['ate'], 'as pontas do intervalo não foram trocadas');

        $ids = array_column($this->linhas($r), 'id');
        $this->assertContains(AtividadeDoAgente::FONTE_PREPARO."-{$nova->id}", $ids, 'o recorte de hoje não trouxe a execução de hoje');
        $this->assertNotContains(AtividadeDoAgente::FONTE_PREPARO."-{$velha->id}", $ids, 'o recorte de dois dias trouxe a execução de cinco dias atrás');
    }

    public function test_os_links_so_nascem_com_destino_real_atividade_e1(): void
    {
        $pub = $this->noPublicador();
        $comProduto = $this->execucao(['produto_id' => $pub->id]);
        $semProduto = $this->execucao(['produto_id' => null]);

        $porId = collect($this->linhas($this->atividade()))->keyBy('id');

        $linhaComProduto = $porId[AtividadeDoAgente::FONTE_PREPARO."-{$comProduto->id}"];
        $this->assertArrayHasKey('produto', $linhaComProduto['links'], 'a linha com produto não ganhou link do editor');
        $this->assertArrayHasKey('previa', $linhaComProduto['links'], 'a linha com produto não ganhou link da prévia');
        $this->assertStringContainsString((string) $pub->id, $linhaComProduto['links']['previa'], 'o link da prévia não aponta para o produto da linha');

        // ⚠️ Link só nasce com destino (decisão D4): linha sem produto não ganha link nenhum.
        $linhaSemProduto = $porId[AtividadeDoAgente::FONTE_PREPARO."-{$semProduto->id}"];
        $this->assertSame([], $linhaSemProduto['links'], 'linha sem produto ganhou link que não leva a lugar nenhum');
        $this->assertNull($linhaSemProduto['produto'], 'linha sem produto veio com produto');
    }

    public function test_o_link_da_aprovacao_exige_company_porque_a_rota_recebe_company_atividade_e1(): void
    {
        $this->eventoDaOferta(PubAprovacaoEvento::APROVACAO, 'aprovada');
        $conta = $this->conta();

        // Com `Company` o link existe e aponta para a fila daquela empresa.
        $comCompany = collect($this->linhas($this->atividade()))
            ->first(fn (array $l) => $l['fonte'] === AtividadeDoAgente::FONTE_APROVACAO);
        $this->assertNotNull($comCompany, 'o evento da oferta não entrou na atividade');
        $this->assertArrayHasKey('oferta', $comCompany['links'], 'o evento da oferta não ganhou link para a Aprovação');
        $this->assertStringContainsString("/meus/{$this->empresa->id}/aprovacao", $comCompany['links']['oferta'], 'o link da Aprovação não usa a rota de `{company}`');

        // ⚠️⚠️ SEM `Company` o link NÃO nasce: `MlbPublicadorAprovacaoController::index` recebe
        // `Company` por route model binding, então montar a URL com a chave da conta daria 404 —
        // e link que leva a 404 é pior que link ausente.
        $semCompany = [...$conta, 'company' => null];
        $linhas = $this->linhas(app(AtividadeDoAgente::class)->paraConta($semCompany, ['fonte' => AtividadeDoAgente::FONTE_PREPARO]));
        foreach ($linhas as $l) {
            $this->assertArrayNotHasKey('oferta', $l['links'], 'conta sem Company ganhou link da Aprovação, que daria 404');
        }
    }

    public function test_todo_estado_de_execucao_tem_rotulo_em_pt_br_atividade_e1(): void
    {
        // ⚠️ BIJEÇÃO nas DUAS direções: estado novo sem rótulo apareceria cru na tela, e rótulo de
        // estado que não existe mais é linha morta.
        $estados = [
            PubAgenteExecucao::ADMITIDA, PubAgenteExecucao::PREPARANDO, PubAgenteExecucao::AGUARDANDO_APROVACAO,
            PubAgenteExecucao::CONCLUIDA, PubAgenteExecucao::BLOQUEADA, PubAgenteExecucao::FALHOU, PubAgenteExecucao::CANCELADA,
        ];
        sort($estados);
        $comRotulo = array_keys(AtividadeDoAgente::ROTULO_DO_ESTADO);
        sort($comRotulo);

        $this->assertSame($estados, $comRotulo, 'os rótulos de estado divergiram dos estados de `PubAgenteExecucao`');

        foreach (AtividadeDoAgente::ROTULO_DO_ESTADO as $estado => $rotulo) {
            $this->assertNotSame($estado, $rotulo, "o estado `{$estado}` aparece cru na tela");
        }

        // E as três fontes e as duas origens também têm rótulo — nome de tabela não vai para a tela.
        foreach (AtividadeDoAgente::FONTES as $f) {
            $this->assertArrayHasKey($f, AtividadeDoAgente::ROTULO_DA_FONTE, "a fonte `{$f}` não tem rótulo");
        }
    }

    public function test_a_linha_da_configuracao_nao_afirma_o_que_mudou_atividade_e1(): void
    {
        // ⚠️⚠️ Esta fatia tem o SNAPSHOT de cada versão, não o DIFF entre duas. Então a linha diz
        // "Configuração salva (versão N)" e mostra o ESTADO naquela versão — ela **não** afirma
        // "agente pausado" como se a pausa fosse a mudança. Declarar menos do que se sabe é
        // honesto; declarar mais não é.
        $this->ligar();
        app(ConfiguracaoDoAgenteService::class)->pausar($this->conta(), $this->admin, 'ajuste de fim de semana');

        $linhas = $this->linhas($this->atividade(['fonte' => AtividadeDoAgente::FONTE_CONFIGURACAO]));
        $this->assertNotEmpty($linhas, 'a versão da configuração não entrou na atividade');

        $maisNova = $linhas[0];
        $this->assertStringContainsString('Configuração salva', $maisNova['titulo'], 'o título da linha de configuração mudou de forma');
        $this->assertStringContainsString('versão', $maisNova['titulo'], 'o título não diz de qual versão se trata');
        $this->assertSame('Agente pausado nesta versão', $maisNova['resultado_rotulo'], 'o estado daquela versão não foi lido do snapshot');

        // ⚠️ A frase diz "NESTA VERSÃO" de propósito: sem isso ela se leria como "o agente foi
        // pausado agora", que é justamente o que esta fatia não sabe.
        $this->assertStringContainsString('nesta versão', $maisNova['resultado_rotulo'], 'a frase afirma mais do que o snapshot permite');

        // E o autor real, não "Sistema".
        $this->assertSame($this->admin->name, $maisNova['autor'], 'a versão salva por uma pessoa saiu como Sistema');
    }

    public function test_snapshot_torto_nao_derruba_a_linha_atividade_e1(): void
    {
        $this->ligar();
        $cfg = PubAgenteConfiguracao::query()->where('conta_chave', $this->conta()['chave'])->firstOrFail();

        // ⚠️ JSON inválido no banco (coluna json aceita isso no SQLite; e dado antigo pode ser
        // qualquer coisa). `json_decode` devolve `null` e a linha fica SEM desfecho — nunca lança.
        DB::table('pub_agente_configuracao_versoes')->insert([
            'configuracao_id' => $cfg->id, 'versao' => 77, 'dados' => 'isto nao e json',
            'autor_id' => null, 'criada_em' => now(),
        ]);

        $linhas = array_values(array_filter(
            $this->linhas($this->atividade(['fonte' => AtividadeDoAgente::FONTE_CONFIGURACAO])),
            fn (array $l) => $l['versao'] === 77,
        ));

        $this->assertCount(1, $linhas, 'a versão com snapshot torto derrubou ou sumiu da lista');
        $this->assertNull($linhas[0]['resultado_rotulo'], 'o snapshot torto virou texto inventado em vez de desfecho ausente');
        // Versão sem autor é do sistema, e a tela diz isso sem fingir saber quem era.
        $this->assertSame(PubAprovacaoEvento::AUTOR_SISTEMA, $linhas[0]['autor'], 'versão sem autor não saiu como Sistema');
    }

    public function test_os_dias_vem_agrupados_com_hoje_e_ontem_por_extenso_atividade_e1(): void
    {
        $this->execucao(['iniciada_em' => now(), 'concluida_em' => now()]);
        $this->execucao(['iniciada_em' => now()->subDay(), 'concluida_em' => now()->subDay()]);
        $this->execucao(['iniciada_em' => now()->subDays(4), 'concluida_em' => now()->subDays(4)]);

        $r = $this->atividade();
        $rotulos = array_column($r['dias'], 'rotulo');

        $this->assertContains('Hoje', $rotulos, 'o dia de hoje não saiu por extenso');
        $this->assertContains('Ontem', $rotulos, 'ontem não saiu por extenso');

        // ⚠️ Os outros dias saem com a data completa. "3 d" foi RECUSADO pelo usuário nesta
        // milestone — a abreviação de dia não vai para a tela.
        $outros = array_values(array_diff($rotulos, ['Hoje', 'Ontem']));
        $this->assertNotEmpty($outros, 'o terceiro dia não virou grupo próprio');
        foreach ($outros as $rotulo) {
            $this->assertMatchesRegularExpression('/^\d{2}\/\d{2}\/\d{4}$/', $rotulo, "o rótulo de dia `{$rotulo}` não é a data completa");
            $this->assertStringNotContainsString(' d', $rotulo, 'a abreviação de dia voltou para a tela');
        }

        // Cada grupo tem pelo menos uma linha: grupo vazio seria cabeçalho sem conteúdo.
        foreach ($r['dias'] as $d) {
            $this->assertNotEmpty($d['linhas'], "o grupo do dia {$d['dia']} veio vazio");
        }
    }

    public function test_loja_sem_atividade_nenhuma_devolve_lista_vazia_sem_lancar_atividade_e1(): void
    {
        // ⚠️ O caminho em que não há configuração, execução nem oferta: as três partes da união
        // somem, e o serviço tem de devolver estrutura completa — não `null`, não exceção. A tela
        // lê `dias`, `total` e `opcoes` sem guarda nenhuma a mais.
        $r = $this->atividade();

        $this->assertSame([], $r['dias'], 'loja sem atividade não veio com lista vazia');
        $this->assertSame(0, $r['total'], 'loja sem atividade veio com total diferente de zero');
        $this->assertSame(1, $r['paginas'], 'loja sem atividade veio com zero páginas — a tela dividiria por zero');
        $this->assertSame(1, $r['pagina'], 'a página corrente saiu fora do intervalo');
        $this->assertNotEmpty($r['opcoes']['fontes'], 'as opções do filtro não vieram');
    }

    public function test_pagina_fora_do_intervalo_volta_para_a_ultima_atividade_e1(): void
    {
        $this->execucao();

        // ⚠️ `?pagina=999` na URL (link velho, ou a pessoa editando a mão) não pode devolver tela
        // vazia: ela veria "1 registro" no topo e nada na lista.
        $r = $this->atividade([], 999);

        $this->assertSame(1, $r['pagina'], 'a página fora do intervalo não foi trazida de volta');
        $this->assertCount(1, $this->linhas($r), 'a página corrigida veio vazia');

        // E página zero ou negativa também.
        $this->assertSame(1, $this->atividade([], 0)['pagina'], 'página zero não foi corrigida');
        $this->assertSame(1, $this->atividade([], -5)['pagina'], 'página negativa não foi corrigida');
    }

    public function test_a_linha_da_configuracao_DIZ_o_que_mudou_campo_por_campo_e1(): void
    {
        // ⚠️⚠️ **ISTO FECHA UMA LACUNA QUE EU HAVIA DECLARADO.** Antes a linha dizia só o ESTADO da
        // versão, porque comparar versões VIZINHAS quebra na fronteira da página. Agora a anterior
        // é buscada **explicitamente por número**, numa consulta para a página inteira.
        $this->ligar(['limite_diario' => 5, 'modalidade' => 'gold_special']);
        $cfg = app(ConfiguracaoDoAgenteService::class)->daConta($this->conta());

        app(ConfiguracaoDoAgenteService::class)->salvar($this->conta(), [
            'habilitado' => true, 'limite_diario' => 9, 'modalidade' => 'gold_pro', 'dias_semana' => [1, 7],
        ], (int) $cfg->versao, $this->admin);

        $linhas = $this->linhas($this->atividade(['fonte' => AtividadeDoAgente::FONTE_CONFIGURACAO]));
        $maisNova = $linhas[0];
        $porCampo = collect($maisNova['mudancas'])->keyBy('campo');

        $this->assertArrayHasKey('limite_diario', $porCampo, 'a mudança do limite diário não apareceu');
        $this->assertSame('5', $porCampo['limite_diario']['de'], 'o valor ANTERIOR do limite está errado');
        $this->assertSame('9', $porCampo['limite_diario']['para'], 'o valor NOVO do limite está errado');
        $this->assertSame('Limite por dia', $porCampo['limite_diario']['rotulo'], 'o campo aparece cru na tela');

        // ⚠️ A modalidade sai com o RÓTULO da tela, não com `gold_pro` — jargão não vai para a tela.
        $this->assertSame('Clássico', $porCampo['modalidade']['de'], 'a modalidade anterior saiu crua');
        $this->assertSame('Premium', $porCampo['modalidade']['para'], 'a modalidade nova saiu crua');

        // ⚠️ E os dias saem por extenso.
        $this->assertSame('todo dia', $porCampo['dias_semana']['de'], 'calendário vazio deveria ler "todo dia"');
        $this->assertSame('Seg, Dom', $porCampo['dias_semana']['para'], 'os dias não saíram por extenso');

        // ⚠️⚠️ O QUE **NÃO** MUDOU NÃO APARECE. Sem isto o diff listaria os dez campos sempre, e
        // a pessoa teria de achar a mudança no meio do ruído.
        $this->assertArrayNotHasKey('teto_fila', $porCampo, 'um campo que NÃO mudou entrou no diff');
        $this->assertArrayNotHasKey('estoque_minimo', $porCampo, 'um campo que NÃO mudou entrou no diff');
    }

    public function test_versao_1_nao_inventa_diff_contra_o_nada_e1(): void
    {
        // ⚠️ A versão 1 **não tem anterior**. Lista vazia significa *"não sei dizer"*, nunca
        // *"nada mudou"* — e o título diz "criada", não "salva", para não sugerir que algo existia.
        $this->ligar();

        $linhas = $this->linhas($this->atividade(['fonte' => AtividadeDoAgente::FONTE_CONFIGURACAO]));
        $this->assertCount(1, $linhas, 'o cenário deveria ter exatamente uma versão');
        $this->assertSame(1, $linhas[0]['versao'], 'a versão não é a primeira');
        $this->assertSame([], $linhas[0]['mudancas'], 'a versão 1 inventou um diff contra o nada');
        $this->assertSame('Configuração criada', $linhas[0]['titulo'], 'a versão 1 diz "salva" em vez de "criada"');
    }

    public function test_o_escopo_no_diff_e_CONTAGEM_e_nunca_id_de_produto_e1(): void
    {
        // ⚠️ Id de produto na tela é jargão (regra permanente deste projeto), e uma lista de
        // dezenas de números não diz nada a ninguém.
        $this->ligar();
        $cfg = app(ConfiguracaoDoAgenteService::class)->daConta($this->conta());
        app(ConfiguracaoDoAgenteService::class)->salvar($this->conta(), [
            'habilitado' => true, 'escopo' => ['incluir' => [4242, 4343], 'excluir' => [5151]],
        ], (int) $cfg->versao, $this->admin);

        $linhas = $this->linhas($this->atividade(['fonte' => AtividadeDoAgente::FONTE_CONFIGURACAO]));
        $porCampo = collect($linhas[0]['mudancas'])->keyBy('campo');

        $this->assertArrayHasKey('escopo', $porCampo, 'a mudança de escopo não apareceu');
        $this->assertSame('todos os produtos', $porCampo['escopo']['de'], 'escopo vazio deveria ler "todos os produtos"');
        $this->assertSame('2 incluído(s), 1 excluído(s)', $porCampo['escopo']['para'], 'o escopo não saiu como contagem');

        // ⚠️⚠️ E nenhum id de produto vaza para o texto do diff.
        $texto = json_encode($linhas[0]['mudancas'], JSON_UNESCAPED_UNICODE);
        foreach (['4242', '4343', '5151'] as $id) {
            $this->assertStringNotContainsString($id, $texto, "o id de produto {$id} vazou para o diff da tela");
        }
    }

    public function test_todo_campo_da_versao_tem_rotulo_em_pt_br_e1(): void
    {
        // ⚠️⚠️ BIJEÇÃO nos DOIS sentidos: chave nova no snapshot **sem rótulo** apareceria CRUA
        // na tela (`estoque_minimo: 1 → 4`), e rótulo de chave que não existe mais é linha morta.
        $doSnapshot = PubAgenteConfiguracao::CHAVES_DA_VERSAO;
        sort($doSnapshot);
        $comRotulo = array_keys(AtividadeDoAgente::ROTULO_DO_CAMPO);
        sort($comRotulo);

        $this->assertSame($doSnapshot, $comRotulo, 'os rótulos de campo divergiram das chaves da versão');

        foreach (AtividadeDoAgente::ROTULO_DO_CAMPO as $campo => $rotulo) {
            $this->assertNotSame($campo, $rotulo, "o campo `{$campo}` aparece cru na tela");
        }
    }

    public function test_snapshot_torto_de_qualquer_lado_nao_inventa_diff_e1(): void
    {
        // ⚠️ JSON inválido de QUALQUER um dos dois lados → lista vazia. Nunca lança, nunca inventa.
        $this->ligar();
        $cfgM = PubAgenteConfiguracao::query()->where('conta_chave', $this->conta()['chave'])->firstOrFail();

        DB::table('pub_agente_configuracao_versoes')->insert([
            ['configuracao_id' => $cfgM->id, 'versao' => 40, 'dados' => 'isto nao e json', 'autor_id' => null, 'criada_em' => now()],
            ['configuracao_id' => $cfgM->id, 'versao' => 41, 'dados' => json_encode(['limite_diario' => 3]), 'autor_id' => null, 'criada_em' => now()],
        ]);

        $porVersao = collect($this->linhas($this->atividade(['fonte' => AtividadeDoAgente::FONTE_CONFIGURACAO])))->keyBy('versao');

        // A 41 tem a 40 como anterior, e a 40 é lixo → sem diff, sem exceção.
        $this->assertArrayHasKey(41, $porVersao, 'a versão 41 não entrou na atividade');
        $this->assertSame([], $porVersao[41]['mudancas'], 'snapshot anterior torto virou diff inventado');
        $this->assertArrayHasKey(40, $porVersao, 'a versão 40 derrubou ou sumiu da lista');
        $this->assertSame([], $porVersao[40]['mudancas'], 'snapshot torto virou diff inventado');
    }
}
