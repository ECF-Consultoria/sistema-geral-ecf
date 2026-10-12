<?php

namespace App\Services\Publicador;

use App\Models\EstruturaAnuncio;
use App\Models\EstruturaPublicacao;
use App\Models\PubProduto;

/**
 * O CONTRATO DO SERVIDOR da "Jornada dos produtos" (Fase 179, v26.0).
 *
 * Responde uma pergunta só: **onde cada produto desta conta está na metodologia
 * de publicação?** A resposta são QUATRO estágios exclusivos e totais — o anel
 * de 176px, a barra segmentada e o painel de estágio do protótipo
 * (`new_layout_publicador/Visao Geral.dc.html:40-155`) saem todos daqui.
 *
 * ═══ Por que a aritmética vem ANTES do pixel ════════════════════════════════
 *
 * O protótipo mostra `{{ pubPct }}` no meio do anel e `{{ st.share }}` em cada
 * cartão. Dois números que não fecham fazem a tela mentir: um quinto caso sem
 * casa tira produto do anel e o `N de M produtos` passa a discordar da soma dos
 * quatro cartões; uma conta sem produto divide por zero e imprime `NaN`. Por
 * isso aqui a classificação é um if-else encadeado com **default**, o `pct` tem
 * guard de zero, e o teste
 * `tests/Feature/Publicador/JornadaDosProdutosTest.php` cobra totalidade e
 * exclusividade em TODOS os cenários.
 *
 * ═══ O que este serviço NÃO faz ═════════════════════════════════════════════
 *
 * - **Não escreve consulta nenhuma.** A lista chega pronta — a MESMA que o
 *   controller já carrega com `ProgramasPublicadorService::produtosParaTela()`,
 *   do jeito que `PainelVisaoGeralService::oQueFazerAgora()` e `noArPorFase()`
 *   já recebem hoje. O gate de custo cobra `0` consultas, com isca.
 * - **Não reordena nada.** A ordem dentro de cada estágio é a de
 *   `produtosParaTela()` (`atualizado_em` desc), igual à tela de Produtos.
 * - **Não manda rótulo de tela.** `"Sem publicação"`, `"Em andamento"`,
 *   `"Fase 1 publicada"`, `"Fase 1 + Fase 2"`, `"Criar combo"` e
 *   `"PRÓXIMO PASSO"` são literais de UI e moram no cliente (plano 179-05),
 *   como `GRUPO_RECENTES` do 177-06 e as chaves de grupo do 178-01. Daqui sai
 *   `chave`, número, item e URL — mais nada. Cor, `step`, `label` e `sub`
 *   também são do cliente.
 * - **Não inventa o que o sistema não sabe.** O protótipo escreve
 *   *"Rascunho parado em Imagens há 3 dias"* e **não existe coluna de etapa
 *   parada** (divergência D-1, medida na Fase 178): a `nota` é montada só de
 *   campo MEDIDO.
 * - **Nenhuma chamada HTTP ao Mercado Livre.** Tudo o que a Jornada mostra já
 *   está gravado.
 *
 * ═══ Um campo-objeto já derrubou a página inteira ═══════════════════════════
 *
 * Em 07/10 um campo do presenter chegou como objeto e foi renderizado como
 * texto ("Objects are not valid as a React child"). Por isso o item tem **oito
 * campos fechados**, todos escalares menos `cobertura`, que é um array de
 * EXATAMENTE quatro booleanos. O teste compara `array_keys()` contra um array
 * literal e varre todo valor com `is_array`/`is_object`.
 */
class JornadaDosProdutosService
{
    /**
     * As chaves dos estágios, na ORDEM do contrato — a do protótipo
     * (`keys = ['none','draft','ready','done']`, `Visao Geral.dc.html:466`).
     * A wave 2/3 renderiza nesta sequência; a barra e o anel dependem dela.
     */
    public const ESTAGIOS = ['none', 'draft', 'ready', 'done'];

    /**
     * Quantos itens cada estágio devolve no painel. 6 é o do protótipo
     * (`list.slice(0, 6)`, `Visao Geral.dc.html:496`) — o resto vai para
     * `ver_todos_url`.
     */
    public const TETO_DE_ITENS = 6;

    /**
     * A CHAVE do botão de cada linha do painel — nunca o rótulo. O texto
     * (`"Publicar Fase 1"`, `"Continuar"`, `"Criar combo"`,
     * `"Ver publicações"` — `rowCta` do protótipo) é literal de tela e mora no
     * cliente.
     */
    private const CTA_POR_ESTAGIO = [
        'none' => 'publicar_fase1',
        'draft' => 'continuar',
        'ready' => 'criar_combo',
        'done' => 'ver_publicacoes',
    ];

    /**
     * A Jornada da conta já resolvida.
     *
     * `$alvo` é a saída de `ProgramasPublicadorService::resolver()` —
     * `{mlb_empresa, company, programa, chave}`. Daqui só a `chave` é lida: ela
     * é o que endereça a tela de Produtos filtrada em `ver_todos_url`. Sem
     * `chave` (D23 — e a conta resolvida sem company), as quatro URLs são
     * `null` e nada lança.
     *
     * `$produtos` é a lista de `produtosParaTela()`. ZERO consulta aqui.
     *
     * ═══ O UNIVERSO: produtos base não compostos ════════════════════════════
     *
     * Entra no anel quem é `eh_kit === false` e **não** é composto do
     * Planejamento (`ProgramasPublicadorService::ehComposto()`).
     *
     * - **Kit não é sujeito de jornada**: ele *é* a Fase 2 do base dele. Contá-lo
     *   somaria o mesmo produto duas vezes — uma como base em `done`, outra como
     *   kit — e o `N de M produtos` do meio do anel passaria a discordar da
     *   lista de Produtos.
     * - **Composto do Planejamento** (Combo/Kit/Combit do Portal) não é base de
     *   Fase 1 (KIT-06, 09/10/2026): não espera Fase 1 nem Fase 2.
     *
     * Lista de shape antigo (sem a chave `eh_kit`) entra no universo como base —
     * `?? false` de propósito: é assim que `bucketDaFase()` e `noArPorFase()`
     * tratam o shape antigo, e sumir com a linha seria pior que contá-la como
     * base.
     *
     * ═══ OS QUATRO ESTÁGIOS, nesta ordem de teste ═══════════════════════════
     *
     * | # | condição medida                              | estágio |
     * |---|----------------------------------------------|---------|
     * | 1 | no ar **e** `kits !== []`                    | `done`  |
     * | 2 | no ar **e** `kits === []`                    | `ready` |
     * | 3 | `status.chave === 'rascunho'`                | `none`  |
     * | 4 | qualquer outro (`conferir`,`pronto`,`publicando`,`erro`) | `draft` |
     *
     * ⚠️ **O ramo 4 é o default de propósito.** Um status novo que apareça
     * amanhã cai em `draft` em vez de desaparecer do anel: perder o produto
     * quebraria a totalidade (a soma dos quatro deixaria de ser o total) e a
     * tela mentiria sem avisar. Nunca troque o default por um `match` fechado.
     *
     * ⚠️ **`erro` em `draft` é divergência DECLARADA do protótipo**, que tem
     * quatro estágios e nenhum caso de erro. A restrição concreta é a
     * totalidade: um quinto caso fora dos quatro faria o `N de M produtos`
     * mentir. O erro de publicação continua tratado pela ação crítica
     * "Produtos com problema na publicação", que já existe em
     * `PainelVisaoGeralService::oQueFazerAgora()`.
     *
     * ═══ "NO AR" e "PRONTO PARA A FASE 2" têm UMA fonte só ══════════════════
     *
     * As duas vivem em `ProgramasPublicadorService` desde o plano **179-02B**
     * (12/10/2026): `noAr()` (status em `STATUS_NO_AR` **ou** `anuncios !== []`)
     * e `prontoParaFase2()` (base, não composta, no ar, sem kits). ⚠️ **Nunca
     * reimplemente nenhuma das duas aqui.** O segundo ramo de `noAr()` cobre a
     * base que falhou numa republicação mas tem anúncio CREATED vivo.
     *
     * ⚠️ **A divergência com `prontosParaFase2()` FOI RESOLVIDA em 12/10/2026,
     * por decisão do usuário.** Até então aquela regra usava só `STATUS_NO_AR`,
     * sem o segundo ramo, e a base com `status.chave = 'erro'` e anúncio vivo,
     * sem kit, entrava em `ready` aqui e **não** contava lá — o anel e a ação
     * mostravam números diferentes na mesma tela. Hoje o estágio `ready` **é** a
     * fonte única, por construção. A decisão inteira (a pergunta, as opções, a
     * escolha e a "Exclusão 2" do 178-01 que ela supera) está no docblock de
     * `ProgramasPublicadorService::prontoParaFase2()`, e
     * `ProntoParaFase2Test` reprova em quem a desfizer.
     *
     * @param  array{chave?: ?string}  $alvo  a saída de `ProgramasPublicadorService::resolver()`
     * @param  list<array>  $produtos  shape de `ProgramasPublicadorService::produtosParaTela()`
     * @return array{total: int, publicados: int, pct: int, tem_produtos: bool, proximo_passo: string,
     *     estagios: list<array{chave: string, total: int, share_pct: int,
     *     itens: list<array{produto_id: int, nome: string, sku: string, nota: string, capa: ?string,
     *     cobertura: array{f1_classico: bool, f1_premium: bool, f2_classico: bool, f2_premium: bool},
     *     cta_chave: string, url: string}>, ver_todos_url: ?string}>}
     */
    public function jornada(array $alvo, array $produtos): array
    {
        $chaveConta = $alvo['chave'] ?? null;
        $chaveConta = is_string($chaveConta) && $chaveConta !== '' ? $chaveConta : null;

        // O índice da família, montado UMA vez: a cobertura de Fase 2 procura os
        // kits do base aqui dentro, em memória, sem consulta nova.
        $porId = collect($produtos)->keyBy('id')->all();

        $porEstagio = array_fill_keys(self::ESTAGIOS, []);

        // ⚠️ `$total` é contado no UNIVERSO, não somando os quatro estágios.
        // Somá-los faria o gate de totalidade do teste passar por CONSTRUÇÃO: um
        // quinto caso que escapasse do if-else sairia dos dois lados da igualdade
        // ao mesmo tempo e ninguém veria. Contado aqui, ele sobra no total e o
        // teste reprova — que é o ponto deste plano.
        $total = 0;

        foreach ($produtos as $p) {
            if (! $this->noUniverso($p)) {
                continue;
            }
            $total++;
            $porEstagio[$this->estagioDe($p)][] = $p;
        }

        $publicados = count($porEstagio['ready']) + count($porEstagio['done']);

        $estagios = [];
        foreach (self::ESTAGIOS as $chave) {
            $estagios[] = [
                'chave' => $chave,
                'total' => count($porEstagio[$chave]),
                'share_pct' => $this->percentual(count($porEstagio[$chave]), $total),
                'itens' => $this->itens($porEstagio[$chave], $chave, $porId, $chaveConta),
                'ver_todos_url' => $this->verTodosUrl($chave, $chaveConta),
            ];
        }

        return [
            'total' => $total,
            // Igual ao protótipo: `published = cnt.ready + cnt.done` (`Visao Geral.dc.html:464`).
            'publicados' => $publicados,
            'pct' => $this->percentual($publicados, $total),
            'tem_produtos' => $total > 0,
            // `hot = cnt.ready > 0 ? 'ready' : 'none'` (`Visao Geral.dc.html:473`).
            'proximo_passo' => count($porEstagio['ready']) > 0 ? 'ready' : 'none',
            'estagios' => $estagios,
        ];
    }

    // ═══ O universo e os estágios ════════════════════════════════════════════

    /** Base não composto — ver o docblock de `jornada()`. */
    private function noUniverso(array $p): bool
    {
        return ($p['eh_kit'] ?? false) === false
            && ! ProgramasPublicadorService::ehComposto($p);
    }

    /** O estágio de UM produto: if-else encadeado, exclusivo e total, com default. */
    private function estagioDe(array $p): string
    {
        if (ProgramasPublicadorService::noAr($p) && ($p['kits'] ?? []) !== []) {
            return 'done';
        }
        // ⚠️ O estágio `ready` **É** a regra de "pronto para a Fase 2", por
        // construção — não uma reimplementação dela (Fase 179-02B). As condições
        // de universo (`eh_kit`, composto) são redundantes aqui porque
        // `noUniverso()` já filtrou; a redundância é o preço barato de a regra
        // ter UMA fonte, e é ela que faz o anel e a ação nunca mais divergirem.
        if (ProgramasPublicadorService::prontoParaFase2($p)) {
            return 'ready';
        }
        // `rascunho` é a chave de "NÃO EXISTE rascunho" (`EditorRascunhoService::prontidao()`).
        if (($p['status']['chave'] ?? null) === 'rascunho') {
            return 'none';
        }

        // Default de propósito: `conferir`, `pronto`, `publicando`, `erro` e
        // qualquer status novo. Ver o aviso no docblock de `jornada()`.
        return 'draft';
    }

    /** Percentual inteiro com guard de zero — `NaN` na tela é bug, não estado. */
    private function percentual(int $parte, int $total): int
    {
        return $total === 0 ? 0 : (int) round($parte / $total * 100);
    }

    // ═══ Os itens do painel ══════════════════════════════════════════════════

    /**
     * Os itens de um estágio: no máximo `TETO_DE_ITENS`, na ordem que
     * `produtosParaTela()` entregou.
     *
     * ⚠️ **Item sem destino usável é DESCARTADO** (nunca um clique mudo), e o
     * `total` do estágio **não** muda por isso — ele conta o universo, não a
     * amostra. É contraintuitivo de propósito: o número do cartão é o que o anel
     * e a barra usam, e tem de fechar com o total. Acontece quando `$alvo` chega
     * sem a chave da conta (D23) e o estágio usa `url_produto`, que só existe
     * quando `produtosParaTela()` recebeu a chave. Mesma regra provada na Fase
     * 178-01 (`test_item_de_prontos_fase2_sem_destino_fica_fora_do_grupo`).
     *
     * O descarte vem ANTES do corte de 6: cortar primeiro devolveria menos itens
     * do que o painel cabe só porque os primeiros não tinham destino.
     *
     * @param  list<array>  $produtos
     * @param  array<int, array>  $porId
     * @return list<array>
     */
    private function itens(array $produtos, string $estagio, array $porId, ?string $chaveConta): array
    {
        $itens = [];

        foreach ($produtos as $p) {
            $url = $this->urlDoItem($p, $estagio);
            if ($url === null) {
                continue;
            }

            $capa = $p['capa'] ?? null;
            $itens[] = [
                'produto_id' => (int) ($p['id'] ?? 0),
                'nome' => (string) ($p['nome'] ?? ''),
                'sku' => (string) ($p['sku'] ?? ''),
                'nota' => $this->nota($p, $estagio),
                'capa' => is_string($capa) && $capa !== '' ? $capa : null,
                'cobertura' => $this->cobertura($p, $porId),
                'cta_chave' => self::CTA_POR_ESTAGIO[$estagio],
                'url' => $url,
            ];

            if (count($itens) === self::TETO_DE_ITENS) {
                break;
            }
        }

        return $itens;
    }

    /**
     * O destino de UMA linha do painel.
     *
     * `ready` e `done` → a tela do Produto (`url_produto`, já montada por
     * `produtosParaTela()` quando a chave da conta foi passada): é de lá que se
     * cria a Fase 2 e se vê o que está no ar. `none` e `draft` → o editor, que é
     * endereçado por `{produto}` e por isso não depende da conta.
     */
    private function urlDoItem(array $p, string $estagio): ?string
    {
        $id = (int) ($p['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        if ($estagio === 'ready' || $estagio === 'done') {
            $url = $p['url_produto'] ?? null;

            return is_string($url) && $url !== '' ? $url : null;
        }

        return route('mlb.anuncios.publicador.editor', ['produto' => $id]);
    }

    /**
     * Para onde vai o "Ver todos" de um estágio — a tela de Produtos filtrada,
     * montada por `route()` AQUI, nunca concatenada à mão nem no cliente.
     *
     * `ready` leva `fase=so_base` junto com `filtro=publicados`: é o MESMO par
     * que a linha "Prontos para a Fase 2" já usa hoje
     * (`PainelVisaoGeralService::oQueFazerAgora()` → `linhaDeProdutos(..., 'publicados', 'so_base')`).
     * Sem `so_base` a lista traria também os kits já publicados, e o número do
     * cartão não casaria com o que a tela de Produtos mostra.
     */
    private function verTodosUrl(string $estagio, ?string $chaveConta): ?string
    {
        if ($chaveConta === null) {
            return null;
        }

        $filtro = match ($estagio) {
            'none', 'draft' => ['filtro' => 'rascunho'],
            'ready' => ['filtro' => 'publicados', 'fase' => 'so_base'],
            'done' => ['filtro' => 'publicados'],
        };

        return route('mlb.anuncios.publicador.produtos', ['conta' => $chaveConta] + $filtro);
    }

    // ═══ A cobertura F1/F2 × Clássico/Premium ════════════════════════════════

    /**
     * As quatro células do protótipo (`cov()`, `Visao Geral.dc.html:424`):
     * Fase 1 e Fase 2, cada uma em Clássico e Premium.
     *
     * Em memória, zero consulta. **F1** são os anúncios do próprio base;
     * **F2** são os anúncios de TODOS os kits dele (não só do primeiro) —
     * procurados no índice `keyBy('id')` da própria lista.
     *
     * - Id de kit que não está na lista é **ignorado sem lançar**: o `SET NULL`
     *   da FK deixa família incompleta, e `produtosParaTela()` só monta `kits`
     *   com quem está no escopo — família quebrada não pode derrubar a tela.
     * - `listing_type_id` desconhecido (nem `gold_special` nem `gold_pro`) não
     *   liga célula nenhuma: `array_search` devolve `false` e a linha é pulada.
     *   Inventar Clássico para um tipo novo do ML seria afirmar o que não foi
     *   medido.
     *
     * ⚠️ As quatro células vêm SEMPRE, `false` quando não há anúncio — "nunca
     * publicamos" e "publicamos e saiu do ar" são coisas diferentes, mas
     * **ausente** nunca é resposta: a célula faltando viraria `undefined` na
     * wave 3.
     *
     * @param  array<int, array>  $porId
     * @return array{f1_classico: bool, f1_premium: bool, f2_classico: bool, f2_premium: bool}
     */
    private function cobertura(array $p, array $porId): array
    {
        $celulas = ['f1_classico' => false, 'f1_premium' => false, 'f2_classico' => false, 'f2_premium' => false];

        $this->marcarCobertura($celulas, $p['anuncios'] ?? [], 'f1');

        foreach ($p['kits'] ?? [] as $kitId) {
            $kit = $porId[(int) $kitId] ?? null;
            if (! is_array($kit)) {
                continue;
            }
            $this->marcarCobertura($celulas, $kit['anuncios'] ?? [], 'f2');
        }

        return $celulas;
    }

    /**
     * Liga as células de uma fase a partir de uma lista de anúncios CREATED.
     *
     * A tradução `listing_type_id` → Clássico/Premium é a de
     * `EstruturaPublicacao::LISTING_TYPES` (`classico => gold_special`,
     * `premium => gold_pro`) — a MESMA tabela que `ultimasPublicacoes()` usa.
     * Nunca uma string literal solta aqui.
     *
     * @param  array{f1_classico: bool, f1_premium: bool, f2_classico: bool, f2_premium: bool}  $celulas
     * @param  list<array{ml_item_id?: string, listing_type_id?: ?string}>  $anuncios
     */
    private function marcarCobertura(array &$celulas, array $anuncios, string $prefixo): void
    {
        foreach ($anuncios as $a) {
            $tipo = array_search((string) ($a['listing_type_id'] ?? ''), EstruturaPublicacao::LISTING_TYPES, true);
            if ($tipo === false) {
                continue;
            }
            $sufixo = $tipo === EstruturaAnuncio::TIPO_PREMIUM ? 'premium' : 'classico';
            $celulas[$prefixo.'_'.$sufixo] = true;
        }
    }

    // ═══ A nota de cada linha ════════════════════════════════════════════════

    /**
     * A frase curta da linha do painel — montada só de campo MEDIDO.
     *
     * ⚠️ O protótipo escreve *"Rascunho parado em Imagens há 3 dias"*,
     * *"Fase 1 concluída ontem"* e *"7 vendas na Fase 1 em 30 dias"*. São dados
     * MOCK, não design: **não existe coluna de etapa parada** nem de venda por
     * produto na Jornada (divergência D-1, medida e declarada na Fase 178). A
     * geometria da nota é cumprida intacta na wave 3; o CONTEÚDO é o que o
     * sistema sabe.
     *
     * Nunca `''` e nunca `'—'`: texto vazio no lugar de informação é o mesmo que
     * inventar — a wave 3 renderiza a nota direto.
     */
    private function nota(array $p, string $estagio): string
    {
        return match ($estagio) {
            'none' => $this->notaDeSemPublicacao($p),
            'draft' => $this->notaDeRascunho($p),
            default => $this->notaDeNoAr($p),
        };
    }

    /**
     * `none` é "nunca começou": a chave `rascunho` de
     * `EditorRascunhoService::prontidao()` significa que NÃO existe rascunho. A
     * nota diz então de onde o produto veio — o único campo medido que
     * acrescenta algo ali.
     *
     * ⚠️ Os três textos são EXATAMENTE os de
     * `NovaPublicacaoService::notaDeSemPublicacao()`: o mesmo produto aparece no
     * modal "Nova publicação" e aqui, e duas notas diferentes para o mesmo
     * produto em duas telas seriam contradição. ⚠️ O texto prescrito pelo plano
     * (*"Sem publicação ainda"*) foi RECUSADO: ele contém o rótulo de tela
     * `"Sem publicação"`, que o próprio gate do plano nega no payload.
     */
    private function notaDeSemPublicacao(array $p): string
    {
        return match ($p['origem'] ?? null) {
            PubProduto::ORIGEM_PORTAL => 'Recebido do Portal do Cliente',
            PubProduto::ORIGEM_PUBLICADOR => 'Cadastro manual no Publicador',
            default => 'Origem não informada',
        };
    }

    /**
     * ⚠️ `draft` recebe quatro status — `conferir`, `pronto`, `publicando` e
     * `erro` —, então a nota NÃO pode ser só "faltam N ou está pronto": dizer
     * *"Rascunho conferido, pronto para publicar"* para uma tentativa que
     * FALHOU seria falso na tela. Cada status fala por si, com o que foi medido.
     */
    private function notaDeRascunho(array $p): string
    {
        $chave = $p['status']['chave'] ?? null;

        if ($chave === 'erro') {
            return 'Tentativa de publicação não concluída';
        }
        if ($chave === 'publicando') {
            return 'Publicando agora';
        }
        if ($chave === 'pronto') {
            return 'Rascunho conferido, pronto para publicar';
        }

        $faltam = (int) ($p['status']['faltam'] ?? 0);

        return $faltam > 0 ? "Rascunho em preenchimento · faltam {$faltam}" : 'Rascunho em preenchimento';
    }

    /**
     * `ready` e `done`: o rótulo da fase da própria linha mais quantos anúncios
     * CREATED estão vivos.
     *
     * `rotulo_fase` vem de `produtosParaTela()` (que o monta com
     * `ProgramasPublicadorService::rotuloFase()`) e **nunca** é recalculado
     * aqui: fonte única, para o mesmo produto não aparecer com dois rótulos em
     * duas telas.
     *
     * `0 no ar` não é afirmado: a base pode estar `publicado` sem nenhum item
     * CREATED com `ml_item_id` gravado, e nesse caso a nota fica só com o
     * rótulo — "medimos e é zero" não é a mesma coisa que "está fora do ar"
     * (VG-18). Mesmo tratamento da Fase 178-01.
     */
    private function notaDeNoAr(array $p): string
    {
        $rotulo = trim((string) ($p['rotulo_fase'] ?? ''));
        $rotulo = $rotulo === '' ? 'Publicado' : $rotulo;
        $noAr = count($p['anuncios'] ?? []);

        return $noAr === 0 ? $rotulo : "{$rotulo} · {$noAr} no ar";
    }
}
