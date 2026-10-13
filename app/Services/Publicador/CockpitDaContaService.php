<?php

namespace App\Services\Publicador;

use App\Models\Company;
use App\Models\MlAnuncioCriativo;

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
 */
class CockpitDaContaService
{
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

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

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
