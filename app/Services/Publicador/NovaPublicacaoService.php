<?php

namespace App\Services\Publicador;

use App\Models\PubProduto;

/**
 * O CONTRATO DO SERVIDOR para o modal "Nova publicação" (Fase 178, v26.0).
 *
 * Responde uma pergunta só: **qual é o próximo passo de cada produto desta
 * conta?** A resposta são três grupos — o que espera a Fase 2, o que nunca foi
 * publicado e o que está em rascunho —, cada item com nota de contexto e
 * **destino real**.
 *
 * Existe porque NOVA-08 é literal: *"os três grupos vêm do servidor, das fontes
 * que já existem; nenhuma classificação feita no cliente"*. Quando o modal da
 * wave 2 chegar, não vai poder inventar número nenhum — a classificação já
 * existe aqui e é testada em `tests/Feature/Publicador/NovaPublicacaoDoHeaderTest.php`.
 *
 * ═══ O que este serviço NÃO faz ══════════════════════════════════════════════
 *
 * - **Não escreve consulta nenhuma.** A fonte é UMA chamada a
 *   `ProgramasPublicadorService::produtosParaTela()`, a consulta mais pesada do
 *   módulo — nunca duas, nunca `produtosQuery()` à mão. Qualquer dado que o
 *   modal precise tem de existir naquele presenter antes de aparecer aqui.
 * - **Não reordena nada.** A ordem dentro de cada grupo é a que
 *   `produtosParaTela()` já devolve (`atualizado_em` desc): o produto mexido
 *   agora é o primeiro da lista, igual à tela de Produtos. Reordenar aqui faria
 *   o modal e a lista discordarem sobre "o que está no topo".
 * - **Não manda rótulo de tela.** `"PRONTOS PARA FASE 2"`, o hint
 *   `"reaproveita a Fase 1"` e o CTA `"Criar combo"` são literais de UI e moram
 *   no cliente (wave 2), como `GRUPO_RECENTES`/`GRUPO_TODAS` do plano 177-06. O
 *   servidor manda a `chave` do grupo e nada mais.
 * - **Não filtra grupo vazio.** "Grupo sem item não aparece" (NOVA-03) é regra
 *   de RENDER, não de payload: as três chaves vêm SEMPRE, o shape fica estável,
 *   o teste fica direto — e o cliente precisa filtrar de qualquer jeito depois
 *   da busca local. Quem "consertar" isso aqui quebra 2 testes.
 * - **Nenhuma chamada HTTP ao Mercado Livre** (D-05). Tudo o que o modal mostra
 *   já está gravado.
 *
 * ═══ Um campo-objeto já derrubou a página inteira ════════════════════════════
 *
 * Em 07/10 um campo do presenter chegou como objeto e foi renderizado como
 * texto ("Objects are not valid as a React child"). Por isso o item deste
 * contrato tem **seis campos fechados e todos escalares**: `produto_id` int,
 * `nome`/`sku`/`nota`/`url` string, `capa` string ou null. Nenhum array, nenhum
 * objeto, nenhum campo a mais — campo a mais aqui é convite a inventar tela na
 * wave 2.
 */
class NovaPublicacaoService
{
    /** As chaves dos grupos, na ORDEM do contrato. A wave 2 renderiza nesta sequência. */
    public const GRUPOS = ['prontos_fase2', 'sem_publicacao', 'rascunhos'];

    public function __construct(private ProgramasPublicadorService $programas) {}

    /**
     * Os três grupos do modal para a conta já resolvida.
     *
     * `$alvo` é a saída de `ProgramasPublicadorService::resolver()` —
     * `{mlb_empresa, company, programa, chave}`. A `chave` é passada adiante de
     * propósito: é ela que faz cada linha vir com `url_produto` pronto.
     *
     * ═══ A regra de `prontos_fase2` ═════════════════════════════════════════
     *
     * ⚠️ **A REGRA NÃO MORA AQUI.** Ela é **delegada** à fonte única
     * `ProgramasPublicadorService::prontoParaFase2()` desde o plano **179-02B**
     * (12/10/2026): base (`eh_kit === false`), não composta, **no ar** e
     * `kits === []`.
     *
     * Até então havia aqui uma **CÓPIA** da regra — e foi exatamente a cópia que
     * divergiu: ela usava só `STATUS_NO_AR`, enquanto o estágio `ready` da
     * Jornada (Fase 179-01) usava os **dois** ramos de "no ar" (status **ou**
     * anúncio CREATED vivo). Na mesma tela o anel dizia um número e a ação dizia
     * outro. **Nunca reimplemente a conjunção aqui de novo.**
     *
     * `test_regra_de_prontos_fase2_bate_com_a_da_visao_geral()` compara este
     * grupo contra `PainelVisaoGeralService::prontosParaFase2()` por reflexão —
     * ⚠️ e desde 12/10/2026 o cenário dele **inclui a base em `erro` com anúncio
     * vivo**, sem a qual o gate era vazio por construção (passava com as duas
     * definições que existe para comparar). `ProntoParaFase2Test` compara os três
     * consumidores por **conjunto de SKU**.
     *
     * ⚠️ **Sem o fallback por `contagemProdutos`.** Aquele método tem o ramo
     * `$cienteDeFase ? … : contagemProdutos['publicados']` porque recebe listas
     * de shape antigo. Aqui a lista é sempre a de `produtosParaTela()`, que é
     * ciente de fase, e o fallback devolveria um NÚMERO sem itens
     * correspondentes — o modal não teria o que mostrar. Produto sem a chave
     * `eh_kit` é tratado como **não classificável** (ver as exclusões).
     *
     * ═══ EXCLUSÕES DECLARADAS — não "consertar" ═════════════════════════════
     *
     * Produto que cai num destes casos fica fora dos TRÊS grupos, de propósito:
     *
     * 1. `status.chave = 'publicando'` → está em voo. O próximo passo não é do
     *    usuário; mostrá-lo no modal convidaria a mexer no que está publicando.
     * 2. ⚠️ **REESCRITA em 12/10/2026 — não a restaure para a forma antiga.**
     *    `status.chave = 'erro'` **SEM anúncio CREATED vivo** → erro de
     *    publicação é **triagem**, e a casa dela é o bloco "O que fazer agora" da
     *    Visão geral (Fase 179). Este modal é a ENTRADA de uma publicação nova,
     *    não a fila de conserto.
     *
     *    ⚠️ Mas o `erro` **COM anúncio vivo**, sem kit e não composto, **ENTRA em
     *    `prontos_fase2`** por decisão do usuário de 12/10/2026 — ele está
     *    vendendo a Fase 1 e o próximo passo dele é mesmo criar o combo. A
     *    redação anterior desta exclusão (*"`status.chave = 'erro'` → erro de
     *    publicação é triagem"*, sem a ressalva) excluía os dois casos e era a
     *    origem da divergência com o anel da Visão geral. A pergunta levada ao
     *    usuário, as opções e a escolha estão no docblock de
     *    `ProgramasPublicadorService::prontoParaFase2()`.
     * 3. `status.chave = 'publicado'` (ou `parcial`) **com `kits` não vazio** →
     *    a base já tem a Fase 2 criada; não espera mais nada.
     * 4. Composto do Planejamento do Portal (`ehComposto`) → KIT-06: não é base
     *    de Fase 1 nem espera Fase 2. Vale para qualquer status.
     * 5. Produto **sem a chave `eh_kit`** → lista de shape antigo, não
     *    classificável. Melhor sair do modal do que entrar no grupo errado.
     *
     * @param  array{mlb_empresa?: ?\App\Models\MlbEmpresa, company?: ?\App\Models\Company, chave?: ?string}  $alvo
     * @return array{total: int, grupos: list<array{chave: string, itens: list<array{produto_id: int, nome: string, sku: string, nota: string, capa: ?string, url: string}>}>}
     */
    public function grupos(array $alvo): array
    {
        // UMA chamada. É a fonte única — a `chave` entra para `url_produto` vir pronto.
        $produtos = $this->programas->produtosParaTela(
            $alvo['mlb_empresa'] ?? null,
            $alvo['company'] ?? null,
            $alvo['chave'] ?? null,
        );

        $prontos = [];
        $semPublicacao = [];
        $rascunhos = [];

        foreach ($produtos as $p) {
            // Exclusão 5: shape antigo, não classificável.
            if (! array_key_exists('eh_kit', $p)) {
                continue;
            }
            // Exclusão 4: composto do Planejamento (KIT-06), em qualquer status.
            if (ProgramasPublicadorService::ehComposto($p)) {
                continue;
            }

            $chave = $p['status']['chave'] ?? null;

            // A FONTE ÚNICA da regra (Fase 179-02B) — nunca a conjunção inline.
            if (ProgramasPublicadorService::prontoParaFase2($p)) {
                // NOVA-07: item sem destino usável NÃO entra. `url_produto` só é
                // null quando `$alvo` chega sem a chave da conta.
                $destino = $p['url_produto'] ?? null;
                if (is_string($destino) && $destino !== '') {
                    $prontos[] = $this->item($p, $this->notaDeProntoParaFase2($p), $destino);
                }

                continue;
            }

            // `rascunho` é a chave de "NÃO EXISTE rascunho" (EditorRascunhoService::prontidao).
            if ($chave === 'rascunho') {
                $semPublicacao[] = $this->item($p, $this->notaDeSemPublicacao($p), $this->editor($p));

                continue;
            }

            if ($chave === 'conferir' || $chave === 'pronto') {
                $rascunhos[] = $this->item($p, $this->notaDeRascunho($p), $this->editor($p));

                continue;
            }

            // Exclusões 1, 2 e 3: publicando, erro, e o no-ar que já tem kit.
        }

        return [
            'total' => count($prontos) + count($semPublicacao) + count($rascunhos),
            'grupos' => [
                ['chave' => 'prontos_fase2', 'itens' => $prontos],
                ['chave' => 'sem_publicacao', 'itens' => $semPublicacao],
                ['chave' => 'rascunhos', 'itens' => $rascunhos],
            ],
        ];
    }

    // ═══ O item ══════════════════════════════════════════════════════════════

    /**
     * Os seis campos do item, nesta ordem, todos escalares.
     *
     * `capa` já vem de `produtosParaTela()` passada por `miniaturaDaFoto()` (a
     * variação `-I.jpg`, ~4 kB) — o sufixo da URL é decisão do servidor e já
     * está tomada; nada de mexer nele aqui.
     *
     * @return array{produto_id: int, nome: string, sku: string, nota: string, capa: ?string, url: string}
     */
    private function item(array $p, string $nota, string $url): array
    {
        $capa = $p['capa'] ?? null;

        return [
            'produto_id' => (int) $p['id'],
            'nome' => (string) ($p['nome'] ?? ''),
            'sku' => (string) ($p['sku'] ?? ''),
            'nota' => $nota,
            'capa' => is_string($capa) && $capa !== '' ? $capa : null,
            'url' => $url,
            // 23/10/2026 (P-09/P-10): "Buscar produto ou MLB" não achava pelo MLB nem pelo SKU da variação. Só texto.
            'busca' => self::busca($p),
        ];
    }

    /** Os códigos dos anúncios publicados (com e sem "MLB") e os SKUs das variações, separados por espaço. */
    private static function busca(array $p): string
    {
        $termos = [];
        foreach ((array) ($p['anuncios'] ?? []) as $a) {
            $mlb = is_array($a) ? trim((string) ($a['ml_item_id'] ?? '')) : '';
            if ($mlb !== '') {
                $termos[] = $mlb;
                $termos[] = (string) preg_replace('/^MLB/i', '', $mlb);
            }
        }
        foreach ((array) ($p['skus_das_variantes'] ?? []) as $sku) {
            if (is_string($sku) && trim($sku) !== '') {
                $termos[] = trim($sku);
            }
        }

        return implode(' ', array_values(array_unique($termos)));
    }

    /** O editor do rascunho — o destino de `sem_publicacao` e de `rascunhos`. */
    private function editor(array $p): string
    {
        return route('mlb.anuncios.publicador.editor', ['produto' => (int) $p['id']]);
    }

    // ═══ As notas ════════════════════════════════════════════════════════════

    /**
     * ⚠️ As notas do protótipo do layout (`"Fase 1 publicada · Clássico e
     * Premium"`, `"Recebido hoje · Portal do Cliente"`, `"Rascunho parado em
     * Imagens"`) são **dados MOCK, não design**: o sistema não sabe em que etapa
     * o rascunho "parou" nem guarda a data no formato relativo daquele texto.
     * Divergência de CONTEÚDO declarada, com restrição concreta (o dado não
     * existe nessa forma) — **não** de layout: a geometria da nota (11.5px,
     * `pub-txt-3`) é cumprida intacta na wave 2.
     *
     * Toda nota abaixo é construída aqui, só de campo MEDIDO. Nenhuma afirma
     * algo não medido, e `"—"` nunca entra como se fosse informação.
     */
    private function notaDeProntoParaFase2(array $p): string
    {
        $noAr = count($p['anuncios'] ?? []);

        return $this->comRotuloDaFase($p, $noAr === 0 ? 'Fase 1 publicada' : "Fase 1 publicada · {$noAr} no ar");
    }

    private function notaDeSemPublicacao(array $p): string
    {
        $nota = match ($p['origem'] ?? null) {
            PubProduto::ORIGEM_PORTAL => 'Recebido do Portal do Cliente',
            PubProduto::ORIGEM_PUBLICADOR => 'Cadastro manual no Publicador',
            default => 'Origem não informada',
        };

        return $this->comRotuloDaFase($p, $nota);
    }

    private function notaDeRascunho(array $p): string
    {
        if (($p['status']['chave'] ?? null) === 'pronto') {
            return $this->comRotuloDaFase($p, 'Conferido, pronto para publicar');
        }

        $faltam = (int) ($p['status']['faltam'] ?? 0);
        $nota = $faltam > 0 ? "Rascunho em preenchimento · faltam {$faltam}" : 'Rascunho em preenchimento';

        return $this->comRotuloDaFase($p, $nota);
    }

    /**
     * Kit recebe o rótulo da fase no fim da nota ("… · Kit 2"). O texto vem de
     * `ProgramasPublicadorService::rotuloFase()`, que já está em `rotulo_fase` —
     * fonte ÚNICA, para o mesmo produto nunca aparecer com dois rótulos.
     */
    private function comRotuloDaFase(array $p, string $nota): string
    {
        if (($p['eh_kit'] ?? false) !== true) {
            return $nota;
        }
        $rotulo = trim((string) ($p['rotulo_fase'] ?? ''));

        return $rotulo === '' ? $nota : $nota.' · '.$rotulo;
    }
}
