<?php

namespace App\Services\Publicador;

use App\Models\Company;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\PubPublicacao;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * O CONTRATO DO SERVIDOR dos blocos do cockpit da Visão geral que não são a
 * Jornada (plano 179-01) nem as Ações (plano 179-02): o banner de integração, a
 * Saúde da conta, Publicações no Mercado Livre (com o acervo de imagens), a
 * faixa de Desempenho e o par Publicações recentes + Atividade recente.
 *
 * Fase 179, plano 03 (v26.0). Zero chamada ao Mercado Livre: tudo lido do que já
 * está gravado — a coleta é de job (`mlb.anuncios.meus.atualizar`), nunca do
 * request (D-05/REG-06).
 *
 * ═══ A disciplina que atravessa todos os métodos ═════════════════════════════
 *
 * 1. **"Nunca medimos" NÃO é "medimos e é zero"** (VG-18). Acervo nunca coletado
 *    devolve `null` — nunca `0`, que mentiria "esta conta não tem anúncio". E o
 *    inverso também vale: acervo coletado e vazio devolve `0`, que é zero
 *    MEDIDO. Os dois casos convivem nesta mesma tela.
 * 2. **Nenhum rótulo de tela no payload.** O servidor manda `chave`, números,
 *    booleanos e URLs; `Tudo conectado`, `Requer ação`, `Atualizar`,
 *    `Reconectar`, cor e formato de data são do cliente (planos 179-07 e
 *    179-08). Jargão na tela é regra permanente deste projeto, e rótulo no
 *    payload é o jeito de perdê-la de vista.
 * 3. **Nenhum campo de item é objeto.** Só string, int, bool ou null — herança
 *    da tela preta de 07/10/2026 em produção, em que um campo-objeto renderizado
 *    como texto derrubou a página inteira ("Objects are not valid as a React
 *    child").
 * 4. **Nenhum destino inventado.** Toda `url` sai de `route()` com valores que a
 *    action de destino aceita de verdade; onde a tela não existe, a `url` é
 *    `null` e o cliente não desenha link (EXMP-03).
 * 5. **Nenhuma consulta nova pelo mesmo dado.** O que o controller já calculou
 *    chega por argumento: contadores do cabeçalho, indicadores, defasagem,
 *    últimas publicações, atividade da equipe e a lista de produtos.
 *
 * ═══ As divergências DECLARADAS, cada uma com a restrição concreta ═══════════
 *
 * Nenhuma é preferência de estilo; cada uma é a ausência de uma fonte, medida.
 *
 * | # | O protótipo desenha | O que nasce | Restrição |
 * |---|---|---|---|
 * | 1 | "ERP Bling desconectado" (linha âmbar, banner e CTA "Reconectar ERP") | a linha do ERP só com `declarado` | **o ERP não é integração neste sistema** — ver `banner()` e `saude()` |
 * | 2 | barra `Ativas / Pausadas / Com erro` | 4 faixas + a legenda "pedem ação" | **não existe status de erro** em `MlAcervoItem` — ver `resumoDePublicacoes()` |
 * | 3 | "Acervo de imagens … Abrir →" | os dois números, **sem link** | não existe tela de acervo POR CONTA — ver `acervoDeImagens()` |
 * | 4 | KPIs "Vendas", "Visitas" e "Conversão" | **não nascem** | sem fonte: `sold_quantity` é acumulado da vida do anúncio, `visitas` é NULL em 1.374.527 linhas e não existe tabela de pedidos — ver `desempenho()` |
 * | 5 | a faixa inteira é `<a>` para a tela Desempenho | `url: null`, e a faixa é `<section>` | **a tela Desempenho não existe** (é a Fase 182) — ver `desempenho()` |
 */
class CockpitDaContaService
{
    /** A janela da faixa de Desempenho, em dias. A janela anterior tem o mesmo tamanho. */
    public const DIAS = 30;

    /**
     * As quatro faixas da barra empilhada, NESTA ORDEM: `chave` da faixa =>
     * [coluna `status` de `MlAcervoItem`, valor de `status` que a action
     * `MlbAnuncioController::meus()` aceita].
     *
     * ⚠️ Os valores da segunda coluna saem da lista FECHADA de `meus()`
     * (`acionaveis|ativos|pausados|em_revisao|encerrados|todos`): um filtro fora
     * dela cairia no default `acionaveis` e a faixa levaria a tela a um recorte
     * que não é o dela (T-179-14).
     */
    private const FAIXAS = [
        'ativas' => ['active', 'ativos'],
        'pausadas' => ['paused', 'pausados'],
        'em_revisao' => ['under_review', 'em_revisao'],
        'encerradas' => ['closed', 'encerrados'],
    ];

    public function __construct(private AcervoTriagemService $triagem) {}

    // ═══ Banner de integração (VG-02) ════════════════════════════════════════

    /**
     * O banner do topo — `null` quando não há nada a dizer.
     *
     * ⚠️ **O CASO DO ERP NÃO NASCE, e isto é uma divergência DECLARADA com
     * restrição concreta: o ERP não é integração neste sistema.** Ele é apenas
     * **declarado** na ficha de onboarding (ou pelo sync da planilha de Polos). A
     * Fase 177-03 fechou isso com **asserção negativa**
     * (`assertArrayNotHasKey('conectado', $erp)`) e o contrato do header expõe só
     * `erp.declarado`. Logo **não existe "ERP Bling desconectado" para medir**, e
     * um banner âmbar com CTA "Reconectar ERP" seria um botão sem destino —
     * proibido por EXMP-03.
     *
     * O banner nasce **orientado a dado** (`chave` + link) justamente para que o
     * dia em que exista integração de ERP de verdade o segundo caso seja uma
     * entrada no mapa do cliente, não um redesenho deste método.
     *
     * ⚠️ Título, descrição, cor e rótulo do CTA são do cliente, por `chave`.
     * Nenhum texto no payload.
     *
     * @param  array  $empresa  saída de `ProgramasPublicadorService::empresaParaTela()`
     * @return ?array{chave: string, link_reconexao: ?string}
     */
    public function banner(array $empresa): ?array
    {
        if (($empresa['token'] ?? null) === 'ativo') {
            return null;
        }

        return [
            'chave' => 'ml',
            'link_reconexao' => $empresa['link_reconexao'] ?? null,
        ];
    }

    // ═══ Saúde da conta (VG-13) ══════════════════════════════════════════════

    /**
     * O status geral e as TRÊS linhas da Saúde da conta, nesta ordem fixa:
     * Mercado Livre, ERP, Sincronização.
     *
     * ⚠️ **A linha do ERP tem `problema: false` SEMPRE, e não pode ganhar
     * `conectado`, `sincronizado_em` nem `atualizado_em`.** Não se acusa problema
     * em algo que não é medido, e as três chaves têm **asserção negativa** desde
     * a Fase 177-03. O que a linha diz é só `declarado` (o nome do ERP ou
     * `null`).
     *
     * ⚠️ **O ERP também nunca puxa o `geral` para baixo.** O protótipo usa o ERP
     * como o caso âmbar da tela; aqui o caso âmbar é a **defasagem da coleta**,
     * que é estado medido de verdade. Divergência declarada: trocar a origem do
     * âmbar foi a única forma de o indicador continuar significando algo.
     *
     * `geral`: `requer_acao` quando a linha do ML tem problema; senão `atencao`
     * quando a coleta está defasada **ou** nunca aconteceu; senão
     * `tudo_conectado`.
     *
     * `atualizar_url` é a rota que **já existe** (Fase 134, "Atualizar agora"):
     * ela **enfileira** `SyncMlAcervoCompanyJob` e nunca coleta no request
     * (D-05/REG-06, T-179-15).
     *
     * @param  array  $empresa  saída de `empresaParaTela()`
     * @param  array  $integracoes  saída de `PainelVisaoGeralService::integracoes()`
     * @param  array  $defasagem  saída de `AcervoTriagemService::defasagem()`
     * @return array{geral: string, linhas: list<array>}
     */
    public function saude(array $empresa, array $integracoes, array $defasagem, ?int $companyId): array
    {
        $estadoMl = (string) ($empresa['token'] ?? 'sem_token');
        $problemaMl = $estadoMl !== 'ativo';

        $nuncaColetado = (bool) ($defasagem['nunca_coletado'] ?? false);
        $defasado = (bool) ($defasagem['defasado'] ?? false);

        $linhas = [
            [
                'chave' => 'mercado_livre',
                // `ativo|expirado|sem_token` — o MESMO estado do `SeloConta` e do
                // header. ⚠️ Nenhum prazo de renovação: o contrato não sabe quando
                // o token renova, e "renova em 4 h" seria número inventado.
                'estado' => $estadoMl,
                'conta_nome' => $empresa['conta_nome'] ?? null,
                'conta_ml_id' => $empresa['conta_ml_id'] ?? null,
                'problema' => $problemaMl,
                'link_reconexao' => $empresa['link_reconexao'] ?? null,
            ],
            [
                'chave' => 'erp',
                'declarado' => $integracoes['erp']['valor'] ?? null,
                // ⚠️ SEMPRE falso. Ver o docblock deste método.
                'problema' => false,
            ],
            [
                'chave' => 'sincronizacao',
                'coletado_em' => $defasagem['coletado_em'] ?? null,
                'horas' => ($defasagem['horas'] ?? null) === null ? null : (int) $defasagem['horas'],
                'defasado' => $defasado,
                'nunca_coletado' => $nuncaColetado,
                'atualizar_url' => $companyId === null
                    ? null
                    : route('mlb.anuncios.meus.atualizar', ['company' => $companyId]),
            ],
        ];

        if ($problemaMl) {
            $geral = 'requer_acao';
        } elseif ($defasado || $nuncaColetado) {
            $geral = 'atencao';
        } else {
            $geral = 'tudo_conectado';
        }

        return ['geral' => $geral, 'linhas' => $linhas];
    }

    // ═══ Publicações no Mercado Livre (VG-14) ════════════════════════════════

    /**
     * A barra empilhada de publicações, a legenda de quatro faixas e o resíduo.
     *
     * ⚠️ **"Com erro" não existe e não vai ser inventado.** O protótipo desenha
     * `Ativas / Pausadas / Com erro`; `MlAcervoItem.status` só assume
     * `active|paused|under_review|closed` — **não existe status de erro** (medido
     * e travado por asserção negativa na Fase 177-03). O terceiro sinal real é
     * **"pedem ação"** (`severidade > 0`), que é exatamente o número do dot
     * vermelho da aba Publicações, pelo mesmo escopo. Divergência declarada.
     *
     * ⚠️ **`total` é o número do HEADER** (`$contadores['publicacoes']`), fonte
     * única — e a soma das quatro faixas é cobrada por IGUALDADE contra ele. Se
     * houver `status` fora dos quatro no banco, ele **tem** de aparecer: é para
     * isso que existe `outras` (o resíduo `total − soma`), que vale `0` quando
     * fecha. Sem o resíduo, a tela e o header discordariam sobre a mesma conta em
     * silêncio (T-179-11).
     *
     * `disponivel` falso em DOIS casos, e nos dois o retorno é todo `null`/vazio:
     * sem `Company` (D23 — 535 de 539 `MlbEmpresa` não têm) e com o acervo
     * **nunca coletado**. ⚠️ Zero seria mentira nos dois.
     *
     * `ativas_pct` é `null` quando não há o que dividir — `total` nulo OU `0`.
     * São coisas diferentes na tela e as duas são honestas: a primeira é "não
     * medimos", a segunda é "medimos e não há anúncio".
     *
     * `pedem_acao` só existe quando foi medido. ⚠️ O `<action>` do plano diz as
     * duas coisas ("`pedem_acao: null`" na lista do caso indisponível e "a
     * entrada **sai do retorno** quando o total é nulo"); venceu a segunda, que é
     * o que o `<behavior>` repete ("a legenda do 'Pedem ação' **sai**") e o que o
     * contrato do plano 179-07 já escreve (`pedem_acao?`). Uma regra só: a chave
     * existe quando, e só quando, há número medido.
     *
     * @param  array  $contadores  `CabecalhoPublicadorService::contadores()`
     * @return array{disponivel: bool, total: ?int, faixas: list<array>, outras: ?int, ativas_pct: ?int, pedem_acao?: array{total: int, url: string}}
     */
    public function resumoDePublicacoes(?Company $company, array $contadores): array
    {
        $total = $contadores['publicacoes'] ?? null;
        $pedemAcao = $contadores['publicacoes_pedem_acao'] ?? null;

        if ($company === null || $total === null) {
            return [
                'disponivel' => false,
                'total' => null,
                'faixas' => [],
                'outras' => null,
                'ativas_pct' => null,
            ];
        }

        $total = (int) $total;

        // UMA consulta agrupada, no MESMO escopo que a tela de Publicações usa
        // (`escopo()` carrega o `company_id`, que é a fronteira de segurança
        // inteira daquela tela — T-134-01). Nunca quatro `count()`.
        $porStatus = $this->triagem->escopo($company, '', 'todos')
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $faixas = [];
        $soma = 0;
        foreach (self::FAIXAS as $chave => [$coluna, $filtro]) {
            $numero = (int) ($porStatus[$coluna] ?? 0);
            $soma += $numero;
            $faixas[] = [
                'chave' => $chave,
                'total' => $numero,
                'url' => route('mlb.anuncios.meus', ['company' => $company->id, 'status' => $filtro]),
            ];
        }

        $resumo = [
            'disponivel' => true,
            'total' => $total,
            'faixas' => $faixas,
            // O resíduo explícito: status fora dos quatro conhecidos (ou nulo).
            'outras' => $total - $soma,
            'ativas_pct' => $total > 0 ? (int) round($faixas[0]['total'] / $total * 100) : null,
        ];

        if ($pedemAcao !== null) {
            $resumo['pedem_acao'] = [
                'total' => (int) $pedemAcao,
                'url' => route('mlb.anuncios.meus', ['company' => $company->id, 'status' => 'acionaveis']),
            ];
        }

        return $resumo;
    }

    /**
     * O bloco de baixo do mesmo card: quantas imagens de IA a conta já tem e
     * quantos packs.
     *
     * `packs` vem de `$indicadores['criativos_packs']` (fonte única, já calculada
     * por `PainelVisaoGeralService::criativosPacks()`); `imagens` é UMA consulta
     * em `MlAnuncioCriativo` com o mesmo recorte do acervo navegável
     * (`slot_indice` e `imagem_path` não nulos — slot sem imagem não é imagem, e
     * imagem sem slot não está no acervo).
     *
     * ⚠️ **Sem `url`, e isto é divergência DECLARADA.** Não existe tela de acervo
     * POR CONTA: o acervo navegável é endereçado por
     * `publicador/produtos/{produto}/acervo` e vive **dentro do editor**, que é
     * território de outro desenvolvedor nesta fase. "Abrir →" sem destino é botão
     * sem destino (EXMP-03), então o bloco mostra os dois números e nenhum link.
     *
     * @param  array  $alvo  saída de `ProgramasPublicadorService::resolver()`
     * @param  array  $indicadores  saída de `PainelVisaoGeralService::indicadores()`
     * @return array{imagens: ?int, packs: ?int}
     */
    public function acervoDeImagens(array $alvo, array $indicadores): array
    {
        $packs = $indicadores['criativos_packs'] ?? null;

        return [
            'imagens' => $this->contarImagens($alvo),
            'packs' => $packs === null ? null : (int) $packs,
        ];
    }

    // ═══ A faixa de Desempenho (VG-15) ═══════════════════════════════════════

    /**
     * Os TRÊS KPIs da faixa de 30 dias — todos REAIS, nenhum número inventado.
     *
     * | `chave` | `valor` | `delta` | `serie` | fonte |
     * |---|---|---|---|---|
     * | `publicacoes_criadas` | publicações concluídas nos últimos 30 dias | contra os 30 anteriores | 30 pontos diários | `pub_publicacoes.concluida_em` |
     * | `criativos_ia` | kits criados nos últimos 30 dias | idem | idem | `ml_anuncio_criativo_kits.created_at` |
     * | `publicacoes_com_venda` | `$indicadores['com_venda']` | **null** | **null** | acervo |
     *
     * ⚠️ **VENDAS, VISITAS E CONVERSÃO NÃO ENTRAM, em nenhuma forma.** Medido
     * (DESEMP-05): `sold_quantity` é o acumulado da **vida inteira** do anúncio e
     * não a venda do dia; a série de `ml_acervo_metricas_diarias` é esparsa (média
     * de 1,3 dias por anúncio); `visitas` é NULL em **100% de 1.374.527 linhas**;
     * e não existe tabela de pedidos. Qualquer um dos três seria número inventado
     * (EXMP-01). O teste tem asserção **negativa** contra as três chaves, rodada
     * sobre um cenário que TEM o dado que as produziria.
     *
     * ⚠️ **`url: null` é divergência DECLARADA de VG-15** ("o bloco inteiro é link
     * para a tela Desempenho"): a tela Desempenho **não existe** e a aba dela
     * **não aparece** — é a Fase 182 (decisão do usuário de 11/10/2026, registrada
     * em `REQUIREMENTS-v26.md`). Link para tela inexistente é botão sem destino
     * (EXMP-03). No dia em que a 182 existir, isto é **uma linha**: a `url` passa
     * a sair de `route()`.
     *
     * ✅ **A forma desta faixa — "os 3 KPIs reais, sem link" — foi CONFIRMADA PELO
     * USUÁRIO em 12/10/2026**, com as alternativas na mesa. Não é escolha de
     * planejador à espera de validação: é decisão tomada.
     *
     * ⚠️ **Aqui `dia sem linha` É zero medido — e isso NÃO contradiz o
     * `nunca_coletado` do acervo, que convive na mesma tela.** A janela é fechada e
     * a fonte é escrita por NÓS: se não há publicação no dia 7, nós sabemos que
     * nada foi publicado. É o oposto do acervo, que é COLETADO de terceiro — lá
     * "dia sem linha" pode ser falha de coleta, e é a armadilha
     * *"dia sem linha ≠ venda zero"* dos learnings deste projeto (Shopee). A
     * distinção é de ORIGEM do dado, não de gosto.
     *
     * ⚠️ **`criativos_ia` NÃO substitui `criativos_packs`**: o indicador do bloco
     * do acervo é a contagem **de sempre**; este KPI é a janela de 30 dias, que é
     * o que a faixa promete. Dois números com nomes diferentes, de propósito, e o
     * gate cobra os dois no mesmo retorno.
     *
     * @return array{kpis: list<array{chave: string, valor: ?int, de: ?int, delta: ?int, serie: ?list<int>}>, dias: int, url: ?string}
     */
    public function desempenho(array $alvo, array $indicadores): array
    {
        $comVenda = $indicadores['com_venda'] ?? null;
        $noAr = $indicadores['no_ar'] ?? null;

        $publicacoes = $this->janela($this->baseDePublicacoes($alvo), 'pub_publicacoes.concluida_em');
        $criativos = $this->janela($this->baseDeKits($alvo), 'ml_anuncio_criativo_kits.created_at');

        return [
            'kpis' => [
                $this->kpiDeJanela('publicacoes_criadas', $publicacoes),
                $this->kpiDeJanela('criativos_ia', $criativos),
                [
                    'chave' => 'publicacoes_com_venda',
                    // ⚠️ `null` ATRAVESSA: acervo nunca coletado não vira 0 (VG-18).
                    'valor' => $comVenda === null ? null : (int) $comVenda,
                    'de' => $noAr === null ? null : (int) $noAr,
                    // Sem delta e sem série de propósito: o acervo guarda o ESTADO
                    // de hoje, não uma linha do tempo de "com venda" por dia.
                    // Inventar uma seria série sintética (EXMP-01).
                    'delta' => null,
                    'serie' => null,
                ],
            ],
            'dias' => self::DIAS,
            // Ver o docblock: a tela Desempenho é a Fase 182.
            'url' => null,
        ];
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    /**
     * A base das publicações concluídas da conta: o MESMO desenho de escopo de
     * `PainelVisaoGeralService::baseQuery()` (join `pub_publicacoes` →
     * `pub_rascunhos` → `pub_produtos`, `orWhere` agrupados, `1 = 0` sem âncora).
     *
     * ⚠️ Registrado de propósito: são DOIS lugares que precisam concordar.
     * `baseQuery()` é `private` e o dono daquele arquivo nesta fase é o plano
     * 179-02 — não é chamável daqui, e copiar a cláusula "de cabeça" é exatamente
     * como as quatro cópias de "no ar" divergiram. O espelho é o método nomeado
     * acima.
     *
     * ⚠️ Aqui a linha é a PUBLICAÇÃO, não o item: `baseQuery()` parte de
     * `pub_publicacao_itens` e uma publicação de kit com variantes contaria o
     * mesmo evento N vezes. Os dois joins são N→1, então não há fan-out.
     */
    private function baseDePublicacoes(array $alvo): Builder
    {
        [$mlbEmpresaId, $companyId] = $this->ancoras($alvo);

        return PubPublicacao::query()
            ->join('pub_rascunhos', 'pub_rascunhos.id', '=', 'pub_publicacoes.rascunho_id')
            ->join('pub_produtos', 'pub_produtos.id', '=', 'pub_rascunhos.produto_id')
            ->whereNotNull('pub_publicacoes.concluida_em')
            ->where($this->daConta($mlbEmpresaId, $companyId, 'pub_produtos.mlb_empresa_id', 'pub_produtos.company_id'));
    }

    /**
     * A base dos kits de criativo da conta — mesmo escopo de dupla-âncora de
     * `PainelVisaoGeralService::criativosPacks()`, com as colunas QUALIFICADAS
     * (o `selectRaw` da janela usa o nome completo).
     */
    private function baseDeKits(array $alvo): Builder
    {
        [$mlbEmpresaId, $companyId] = $this->ancoras($alvo);

        return MlAnuncioCriativoKit::query()
            ->where($this->daConta(
                $mlbEmpresaId,
                $companyId,
                'ml_anuncio_criativo_kits.mlb_empresa_id',
                'ml_anuncio_criativo_kits.company_id',
            ));
    }

    /**
     * As duas janelas de 30 dias e a série diária, de UMA consulta agrupada por
     * dia cobrindo os 60 dias de uma vez.
     *
     * O calendário é preenchido em PHP: **nunca** se confia no banco devolver o
     * dia vazio, e é esse preenchimento que faz o dia sem linha valer `0` (zero
     * MEDIDO — ver o docblock de `desempenho()`). Linha fora das duas janelas é
     * ignorada por construção, porque a soma percorre o CALENDÁRIO e não as
     * linhas devolvidas.
     *
     * ⚠️ `$coluna` entra em `selectRaw`/`groupByRaw` e por isso é SEMPRE literal
     * deste arquivo — nunca vem de request. `DATE()` existe nos três bancos deste
     * projeto (MySQL 8 em produção, MariaDB 10.4 no dev local, SQLite nos testes).
     *
     * ⚠️ O `pluck()` depois do `selectRaw()` é seguro porque as colunas já estão
     * definidas: `onceWithColumns()` só as substitui quando `$this->columns` é
     * nulo. Sem o `selectRaw` antes, o `pluck` trocaria a projeção e o
     * `group by` cairia sozinho.
     *
     * @return array{valor: int, anterior: int, serie: list<int>}
     */
    private function janela(Builder $query, string $coluna): array
    {
        $porDia = $query
            ->where($coluna, '>=', Carbon::today()->subDays(self::DIAS * 2 - 1)->startOfDay())
            ->where($coluna, '<=', Carbon::today()->endOfDay())
            ->selectRaw("DATE({$coluna}) as dia, count(*) as total")
            ->groupByRaw("DATE({$coluna})")
            ->pluck('total', 'dia')
            ->all();

        $serie = [];
        for ($i = self::DIAS - 1; $i >= 0; $i--) {
            $serie[] = (int) ($porDia[Carbon::today()->subDays($i)->toDateString()] ?? 0);
        }

        $anterior = 0;
        for ($i = self::DIAS * 2 - 1; $i >= self::DIAS; $i--) {
            $anterior += (int) ($porDia[Carbon::today()->subDays($i)->toDateString()] ?? 0);
        }

        return ['valor' => array_sum($serie), 'anterior' => $anterior, 'serie' => $serie];
    }

    /**
     * Um KPI de janela. `delta` é a SUBTRAÇÃO das duas janelas — nunca o valor da
     * atual: janela anterior vazia dá `delta = valor`, e as duas iguais dão `0`,
     * que é informação ("sem variação") e não ausência de medida.
     *
     * @param  array{valor: int, anterior: int, serie: list<int>}  $janela
     */
    private function kpiDeJanela(string $chave, array $janela): array
    {
        return [
            'chave' => $chave,
            'valor' => $janela['valor'],
            // `de` só tem valor no KPI do acervo; a chave fica para a ORDEM do
            // contrato ser a mesma nos três (o cliente lê os três pelo mesmo mapa).
            'de' => null,
            'delta' => $janela['valor'] - $janela['anterior'],
            'serie' => $janela['serie'],
        ];
    }

    /**
     * Quantas imagens de IA a conta tem no acervo. UMA consulta; o mesmo recorte
     * de `PublicadorAcervoService::listar()`, com o escopo por dupla-âncora da
     * conta em vez do produto.
     */
    private function contarImagens(array $alvo): int
    {
        [$mlbEmpresaId, $companyId] = $this->ancoras($alvo);
        if ($mlbEmpresaId === null && $companyId === null) {
            return 0;
        }

        return MlAnuncioCriativo::query()
            ->whereNotNull('slot_indice')
            ->whereNotNull('imagem_path')
            ->where($this->daConta($mlbEmpresaId, $companyId, 'mlb_empresa_id', 'company_id'))
            ->count();
    }

    /**
     * O filtro de conta: `mlb_empresa_id = X` **OU** `company_id = Y`, SEMPRE
     * agrupado dentro de `where(function…)`.
     *
     * ⚠️ O agrupamento NÃO é estilo. `orWhere` solto sobe ao topo do WHERE e
     * **anula** o escopo por empresa — a consulta passaria a somar dado de outra
     * conta (T-179-10, armadilha documentada em `AcervoTriagemService::escopo()`).
     * Sem nenhuma das duas âncoras a consulta é FECHADA com `1 = 0`, nunca aberta
     * à base inteira.
     *
     * Um lugar só neste serviço, usado por todas as consultas escopadas por
     * dupla-âncora — a da barra de status usa `AcervoTriagemService::escopo()`,
     * que já carrega o `company_id`.
     */
    private function daConta(?int $mlbEmpresaId, ?int $companyId, string $colunaEmpresa, string $colunaCompany): \Closure
    {
        return function ($q) use ($mlbEmpresaId, $companyId, $colunaEmpresa, $colunaCompany) {
            if ($mlbEmpresaId !== null) {
                $q->orWhere($colunaEmpresa, $mlbEmpresaId);
            }
            if ($companyId !== null) {
                $q->orWhere($colunaCompany, $companyId);
            }
            if ($mlbEmpresaId === null && $companyId === null) {
                $q->whereRaw('1 = 0');
            }
        };
    }

    /** @return array{0: ?int, 1: ?int} */
    private function ancoras(array $alvo): array
    {
        return [($alvo['mlb_empresa'] ?? null)?->id, ($alvo['company'] ?? null)?->id];
    }
}
