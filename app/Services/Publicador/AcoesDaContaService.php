<?php

namespace App\Services\Publicador;

/**
 * "O que fazer agora" com severidade, ordem, sub-lista e adiamento — o contrato
 * de servidor do bloco central da Visão geral nova (Fase 179, Plano 02).
 *
 * ═══ ELE CONSOME `oQueFazerAgora()`, NÃO O REESCREVE ════════════════════════
 *
 * A regra inviolável do projeto é que **nada do que a tela entrega hoje pode
 * sumir**. Por isso este serviço **recebe as linhas prontas** de
 * `PainelVisaoGeralService::oQueFazerAgora()` e só as enriquece: toda linha de
 * hoje vira ação **por construção**, e o gate central do plano
 * (`test_toda_linha_de_hoje_virou_acao_com_contagem_exata`) cobra isso com
 * contagem EXATA, não com `assertContains`.
 *
 * Reescrever a classificação aqui teria duas regras para o mesmo número, e a
 * ordem fixa das linhas — que é a do handoff e tem teste desde a Fase 173 —
 * passaria a existir em dois lugares.
 *
 * ═══ ZERO CONSULTA ══════════════════════════════════════════════════════════
 *
 * `$linhas`, `$jornada` e `$produtos` chegam prontos do controller (molde de
 * `oQueFazerAgora()`/`noArPorFase()`/`JornadaDosProdutosService`). Medido por
 * `DB::enableQueryLog()` **com isca** — sem a isca, um `enableQueryLog()` mal
 * posicionado daria zero por construção. O único acesso externo é UMA leitura de
 * cache do adiamento, travada por `->once()`.
 *
 * ═══ O QUE É DO CLIENTE, E NÃO SAI DAQUI ════════════════════════════════════
 *
 * O **rótulo do CTA** ("Criar combos", "Revisar", "Publicar"), o **rótulo da
 * severidade** ("CRÍTICO"), as cores e o literal "RECOMENDADO AGORA" são de TELA
 * e moram no cliente, indexados por `chave`/`severidade` — mesmo precedente do
 * `GRUPO_RECENTES` do 177-06, das chaves de grupo do 178-01 e dos estágios do
 * 179-01. **Não existe chave `cta` no payload**, e há asserção negativa.
 *
 * O **filtro por severidade também é do cliente**: o protótipo filtra no render
 * e as contagens são sobre a lista inteira (`Visao Geral.dc.html:511-514`). O
 * servidor manda tudo e as contagens — a assinatura de `acoes()` não tem
 * parâmetro de severidade, e um teste trava a lista de parâmetros.
 *
 * `titulo` e `descricao`, ao contrário, **são** do servidor: interpolam número,
 * nome de produto e contagem de pendência REAIS. São dado, não rótulo.
 *
 * ═══ A AÇÃO QUE NÃO EXISTE ══════════════════════════════════════════════════
 *
 * ⚠️ Não há ação de **"publicação com prejuízo"** / rentabilidade / margem. O
 * sistema não mede repasse nem custo por anúncio: a ação nasceria decorativa, um
 * botão sem destino. VG-12/EXMP-01 só admitem ação que o sistema sustenta, e o
 * veto é provado por asserção **negativa** sobre o JSON em cenário cheio —
 * positiva deixaria uma fase futura acrescentar a ação com o teste verde.
 *
 * ⚠️ Pelo mesmo motivo não existe **"Reconectar ERP"**: a Fase 177-03 mediu e o
 * ERP não é integração neste sistema (o contrato só tem `erp.declarado`).
 */
class AcoesDaContaService
{
    /**
     * `chave da linha => severidade`. A lista é **FECHADA** nestas doze chaves, e
     * é por isso que `AdiamentoDeAcoes` pode usá-la como validação (T-179-05) e
     * que o saco do cache não passa de doze entradas (T-179-07).
     *
     * ⚠️ **Chave desconhecida NÃO é descartada:** cai em `SEVERIDADE_PADRAO`
     * (`atencao`) e **aparece** na lista. Uma fase futura que acrescente linha em
     * `oQueFazerAgora()` sem passar por aqui não pode fazer a ação desaparecer da
     * tela em silêncio — regressão por omissão é a pior porque nada acusa. O que
     * ela perde é só poder ser **adiada**, e isso é consciente: o adiamento só
     * aceita chave desta lista.
     *
     * ⚠️ Chave nova aqui tem de entrar também na lista do plano 179-04 (a
     * validação `in_array` da action de adiar).
     */
    public const SEVERIDADE = [
        // Crítico: a conta ou o produto estão impedidos de vender agora.
        'reconectar' => 'critico',
        'com_problema' => 'critico',
        // Atenção: está no ar mas perdendo desempenho, ou parado no meio do caminho.
        'pausados' => 'atencao',
        'rascunhos_pendencias' => 'atencao',
        'ficha_incompleta' => 'atencao',
        'perdendo_catalogo' => 'atencao',
        'foto_insuficiente' => 'atencao',
        // Oportunidade: nada está quebrado; há faturamento a mais na mesa.
        'prontos_fase2' => 'oportunidade',
        'conferidos' => 'oportunidade',
        'sem_publicacao' => 'oportunidade',
        'portal_novas' => 'oportunidade',
        'alavancas' => 'oportunidade',
    ];

    /**
     * A ordem numérica da severidade — a MESMA aritmética do protótipo
     * (`SEV[a.sev][3] - SEV[b.sev][3]`, `Visao Geral.dc.html:425` e `:511`).
     */
    public const ORDEM_DA_SEVERIDADE = ['critico' => 0, 'atencao' => 1, 'oportunidade' => 2];

    /** Onde cai a chave que ainda não tem severidade própria. Aparece, nunca desaparece. */
    public const SEVERIDADE_PADRAO = 'atencao';

    /**
     * Quantos sub-itens cada ação mostra. 4 é o do protótipo
     * (`prods.filter(...).slice(0, 4)`, `Visao Geral.dc.html:506`) — o resto está
     * atrás do `destino`.
     */
    public const TETO_DE_ITENS = 4;

    public function __construct(private AdiamentoDeAcoes $adiamento) {}

    /**
     * As ações da conta, ordenadas, contadas e já sem as que o usuário adiou.
     *
     * `$alvo` é a saída de `ProgramasPublicadorService::resolver()`. Daqui só a
     * `chave` é lida: ela é o escopo de conta do adiamento.
     * `$linhas` é a saída de `PainelVisaoGeralService::oQueFazerAgora()`.
     * `$jornada` é a de `JornadaDosProdutosService::jornada()`.
     * `$produtos` é a de `ProgramasPublicadorService::produtosParaTela()`.
     * `$userId` nulo **não filtra nada** — chamador sem usuário, nunca a tela.
     *
     * @param  array{chave?: ?string}  $alvo
     * @param  list<array>  $linhas
     * @param  list<array>  $produtos
     * @return array{acoes: list<array{chave: string, severidade: string, titulo: string, descricao: string, numero: ?int, recomendada: bool, itens: list<array{nome: string, meta: string, motivo: string}>, destino: array}>, contagens: array{todas: int, critico: int, atencao: int, oportunidade: int}, adiadas: list<string>, volta_em_iso: string}
     */
    public function acoes(array $alvo, array $linhas, array $jornada, array $produtos, ?int $userId): array
    {
        $acoes = [];

        foreach ($linhas as $linha) {
            $chave = $linha['chave'] ?? null;
            // Linha de shape antigo (pré-Task 1) ou chave vazia: não há como lhe dar
            // severidade nem endereçar o adiamento dela. Fora, sem lançar.
            if (! is_string($chave) || $chave === '') {
                continue;
            }
            $acoes[] = $this->acaoDaLinha($chave, $linha, $jornada, $produtos);
        }

        // ⚠️ A ação NOVA entra DEPOIS das linhas de hoje e, por ser `oportunidade`,
        // cai no fim do bloco de oportunidades quando a ordenação estável roda. A
        // ordem fixa do handoff não se mexe.
        //
        // ⚠️ **MENOS quando a conta precisa de reconexão.** A regra do handoff é que
        // token fora de 'ativo' encerra a lista com UMA linha só — sem conta ativa,
        // nada mais funciona, e oferecer "publicar N produtos" ali seria um botão
        // que o Mercado Livre vai recusar. A presença da chave `reconectar` É o
        // sinal: `oQueFazerAgora()` só a devolve nesse caso, e sozinha. Sem esta
        // guarda a ação nova FURARIA uma regra que já tinha teste desde a Fase 173.
        if (! $this->temReconexaoPendente($acoes)) {
            $semPublicacao = $this->acaoSemPublicacao($jornada);
            if ($semPublicacao !== null) {
                $acoes[] = $semPublicacao;
            }
        }

        // ⚠️ `usort` é ESTÁVEL desde o PHP 8.0 — é o que garante que, dentro da mesma
        // severidade, vale a ordem que `oQueFazerAgora()` já entrega. Nenhum
        // desempate por nome de chave: ele desfaria a ordem fixa do handoff.
        usort(
            $acoes,
            fn (array $a, array $b) => self::ORDEM_DA_SEVERIDADE[$a['severidade']] <=> self::ORDEM_DA_SEVERIDADE[$b['severidade']]
        );

        // UMA leitura de cache por chamada (ver o desenho de saco único em
        // `AdiamentoDeAcoes`). Travada por `->once()` no teste.
        $adiadas = $userId !== null
            ? $this->adiamento->adiadas($userId, (string) ($alvo['chave'] ?? ''))
            : [];

        // ⚠️ O filtro vem ANTES das contagens e ANTES da `recomendada`: as contagens
        // são do que a tela mostra, e adiar a primeira tem de promover a próxima —
        // senão a lista ficaria sem nenhuma destacada, e o protótipo sempre destaca
        // uma (`acts[0]`, `Visao Geral.dc.html:514`).
        $acoes = array_values(array_filter($acoes, fn (array $a) => ! in_array($a['chave'], $adiadas, true)));

        $contagens = ['todas' => count($acoes), 'critico' => 0, 'atencao' => 0, 'oportunidade' => 0];
        foreach ($acoes as $acao) {
            $contagens[$acao['severidade']]++;
        }

        foreach ($acoes as $i => $acao) {
            // Sobrescrever a chave preserva a POSIÇÃO dela no array — o shape é travado
            // por `assertSame(array_keys(...))`.
            $acoes[$i]['recomendada'] = $i === 0;
        }

        return [
            'acoes' => $acoes,
            'contagens' => $contagens,
            'adiadas' => $adiadas,
            // A tela precisa do instante para o literal "Ações adiadas voltam amanhã às
            // 8h." não ser número solto no JSX (`Visao Geral.dc.html:229`).
            'volta_em_iso' => $this->adiamento->expiraEm()->toIso8601String(),
        ];
    }

    /** Uma linha de `oQueFazerAgora()` enriquecida — nunca recalculada. */
    private function acaoDaLinha(string $chave, array $linha, array $jornada, array $produtos): array
    {
        $numero = $this->inteiroOuNulo($linha['numero'] ?? null);

        return [
            'chave' => $chave,
            'severidade' => self::SEVERIDADE[$chave] ?? self::SEVERIDADE_PADRAO,
            // ⚠️ O título é o texto que a tela JÁ mostra hoje, byte a byte. Reescrevê-lo
            // seria mudar a tela de produção sem necessidade, e é o que o gate de
            // não-regressão compara (`$linha['texto'] === $acao['titulo']`).
            'titulo' => (string) ($linha['texto'] ?? ''),
            'descricao' => $this->descricao($chave, $linha),
            'numero' => $numero,
            'recomendada' => false,
            'itens' => $this->itens($chave, $jornada, $produtos),
            // O destino que a linha já traz, intacto: `{rota, params}` / `{acao}` /
            // `{acao, url}`. Remontá-lo aqui seria uma segunda fonte de URL.
            'destino' => is_array($linha['destino'] ?? null) ? $linha['destino'] : [],
        ];
    }

    /**
     * A ação NOVA de VG-12: "N produtos sem publicação". `oQueFazerAgora()` não
     * tem essa linha hoje.
     *
     * ⚠️ **FONTE ÚNICA:** o número é o `total` do estágio `none` da Jornada,
     * **nunca** recontado aqui nem tirado do tamanho da amostra de `itens` (que o
     * plano 179-01 corta em 6). Recontar é a mesma armadilha do "+N" da pilha de
     * tiles, onde `itens.length - 4` mentiria sobre um estágio de 13 produtos.
     *
     * ⚠️ E **não é** `contagemProdutos['sem_oferta']`: aquilo é produto sem oferta
     * do Portal, outra coisa — o docblock de `contagemProdutos()` registra a
     * diferença. Por isso este serviço nem recebe `contagemProdutos`.
     *
     * Com o estágio zerado a ação **não nasce**: "nenhum produto sem publicação"
     * não é uma tarefa.
     */
    private function acaoSemPublicacao(array $jornada): ?array
    {
        $estagio = $this->estagio($jornada, 'none');
        $total = $this->inteiroOuNulo($estagio['total'] ?? null) ?? 0;

        if ($total <= 0) {
            return null;
        }

        $universo = $this->inteiroOuNulo($jornada['total'] ?? null) ?? 0;
        $destino = $estagio['ver_todos_url'] ?? null;

        return [
            'chave' => 'sem_publicacao',
            'severidade' => self::SEVERIDADE['sem_publicacao'],
            'titulo' => 'Produtos ainda sem publicação',
            // Os dois números são medidos: o do estágio e o universo do anel. A frase
            // só os relaciona, não afirma nada a mais.
            'descricao' => $universo > 0
                ? "{$total} de {$universo} produtos da conta ainda não tiveram a Fase 1 publicada."
                : 'Produtos cadastrados que ainda não tiveram a Fase 1 publicada.',
            'numero' => $total,
            'recomendada' => false,
            'itens' => $this->itensDoEstagio($jornada, 'none'),
            'destino' => is_string($destino) && $destino !== '' ? ['acao' => 'abrir', 'url' => $destino] : [],
        ];
    }

    /**
     * A descrição: uma frase curta que diz **o que o número significa**, montada
     * só do que foi MEDIDO.
     *
     * ⚠️ O número não é prefixado na frase: ele já é o campo `numero`, e
     * "1 produtos" é o tipo de erro de concordância que o protótipo comete
     * (`cnt.ready + ' produtos prontos...'`) e que não precisa chegar à tela. Onde
     * há um **segundo** número medido (`legado`, `faltam`), ele entra — com
     * singular e plural certos.
     *
     * ⚠️ Chave desconhecida devolve `''`: não há nada medido a dizer sobre ela, e
     * inventar uma frase genérica seria enfeite. "Não sabemos" não é "é zero"
     * (VG-18) — a ação continua aparecendo, com título e número reais.
     */
    private function descricao(string $chave, array $linha): string
    {
        return match ($chave) {
            // A causa real do truncamento da lista: sem token ativo nada mais funciona.
            'reconectar' => 'A conta do Mercado Livre perdeu o acesso. Nada mais desta tela é confiável até reconectar.',
            'pausados' => $this->descricaoDePausados($linha),
            'com_problema' => 'A publicação foi recusada pelo Mercado Livre e o produto ficou sem anúncio no ar.',
            // É literalmente a regra de `prontosParaFase2()`: base no ar e sem nenhum kit.
            'prontos_fase2' => 'Fase 1 no ar e nenhum kit criado. O kit reaproveita os dados da Fase 1.',
            'conferidos' => 'Rascunhos já conferidos, esperando só a publicação.',
            'rascunhos_pendencias' => $this->descricaoDeRascunhos($linha),
            'portal_novas' => 'Ofertas novas chegaram do Portal do Cliente e ainda não foram sincronizadas.',
            // `sinais['ficha_obrigatoria']['ok'] === false` em `AnuncioSaudeService`.
            'ficha_incompleta' => 'Anúncios no ar sem os atributos que o Mercado Livre exige na ficha.',
            // `buybox` em `losing` ou `sharing_first_place` — perdeu OU divide, nunca "não avaliado".
            'perdendo_catalogo' => 'Anúncios de catálogo que perderam ou dividem a primeira posição com outro vendedor.',
            // `sinalFoto()` reprova quando NÃO HÁ nenhuma foto — o rótulo é mais suave que a medição.
            'foto_insuficiente' => 'Anúncios no ar sem nenhuma foto cadastrada.',
            // O checklist real de `PubTarefa::CHECKLIST_ALAVANCAS`.
            'alavancas' => 'Publicações no ar esperando promoção, ADS, atacado, cupom, afiliados ou lista de transmissão.',
            default => '',
        };
    }

    /** `legado` é medido por `AcervoTriagemService::legadoEntre()` e vem na linha. */
    private function descricaoDePausados(array $linha): string
    {
        $legado = $this->inteiroOuNulo($linha['legado'] ?? null) ?? 0;

        if ($legado <= 0) {
            return 'Anúncios que estão no ar mas não podem vender: pausados ou sem estoque.';
        }

        $trecho = $legado === 1
            ? '1 deles foi publicado fora do Publicador'
            : "{$legado} deles foram publicados fora do Publicador";

        return "Pausados ou sem estoque — {$trecho}.";
    }

    /** `exemplo` é o rascunho de MENOR `faltam` (o mais perto de pronto), medido na linha. */
    private function descricaoDeRascunhos(array $linha): string
    {
        $exemplo = is_array($linha['exemplo'] ?? null) ? $linha['exemplo'] : [];
        $nome = is_string($exemplo['nome'] ?? null) ? trim($exemplo['nome']) : '';
        $faltam = $this->inteiroOuNulo($exemplo['faltam'] ?? null) ?? 0;

        if ($nome === '' || $faltam <= 0) {
            return 'Rascunhos em preenchimento com campos obrigatórios pendentes.';
        }

        // Concordância certa: "falta 1 campo", nunca "faltam 1 campos".
        $trecho = $faltam === 1 ? 'falta 1 campo' : "faltam {$faltam} campos";

        return "{$nome} é o mais perto de pronto: {$trecho}.";
    }

    /**
     * Os sub-itens da ação (VG-11), teto de 4.
     *
     * ⚠️ `pausados` e os três motivos de acervo vêm com `[]` de PROPÓSITO: a lista
     * por anúncio exigiria uma consulta nova, e este serviço faz zero consulta.
     * Item inventado aqui seria enfeite — a lista completa está atrás do `destino`.
     *
     * @return list<array{nome: string, meta: string, motivo: string}>
     */
    private function itens(string $chave, array $jornada, array $produtos): array
    {
        return match ($chave) {
            'prontos_fase2' => $this->itensDoEstagio($jornada, 'ready'),
            'sem_publicacao' => $this->itensDoEstagio($jornada, 'none'),
            'rascunhos_pendencias' => $this->itensDoEstagio($jornada, 'draft'),
            'conferidos' => $this->itensDosProdutos($produtos, 'pronto'),
            'com_problema' => $this->itensDosProdutos($produtos, 'erro'),
            default => [],
        };
    }

    /** `motivo` é a `nota` que o plano 179-01 já montou de campo medido. */
    private function itensDoEstagio(array $jornada, string $chaveEstagio): array
    {
        $estagio = $this->estagio($jornada, $chaveEstagio);
        $brutos = is_array($estagio['itens'] ?? null) ? $estagio['itens'] : [];

        $itens = [];
        foreach ($brutos as $item) {
            if (! is_array($item)) {
                continue;
            }
            $montado = $this->item($item['nome'] ?? null, $item['sku'] ?? null, $item['nota'] ?? null);
            if ($montado !== null) {
                $itens[] = $montado;
            }
            if (count($itens) >= self::TETO_DE_ITENS) {
                break;
            }
        }

        return $itens;
    }

    /** `motivo` é o `rotulo` REAL do status (`EditorRascunhoService::prontidao()`). */
    private function itensDosProdutos(array $produtos, string $chaveStatus): array
    {
        $itens = [];
        foreach ($produtos as $p) {
            if (! is_array($p) || ($p['status']['chave'] ?? null) !== $chaveStatus) {
                continue;
            }
            $montado = $this->item($p['nome'] ?? null, $p['sku'] ?? null, $p['status']['rotulo'] ?? $p['status']['chave'] ?? null);
            if ($montado !== null) {
                $itens[] = $montado;
            }
            if (count($itens) >= self::TETO_DE_ITENS) {
                break;
            }
        }

        return $itens;
    }

    /**
     * Um sub-item, ou `null` quando falta o que descrever.
     *
     * ⚠️ **Item sem nome, sem SKU ou sem motivo fica FORA**, nunca entra com `''`
     * nem com `'—'`: a linha de 40px do protótipo mostra os três lado a lado, e
     * um travessão ali seria dado inventado. Mesmo precedente do "item sem destino
     * é descartado" (NOVA-07 / 179-01). O **número** da ação não muda com isso —
     * ele conta o universo, não a amostra mostrável.
     *
     * @return ?array{nome: string, meta: string, motivo: string}
     */
    private function item(mixed $nome, mixed $sku, mixed $motivo): ?array
    {
        $nome = is_string($nome) || is_numeric($nome) ? trim((string) $nome) : '';
        $sku = is_string($sku) || is_numeric($sku) ? trim((string) $sku) : '';
        $motivo = is_string($motivo) || is_numeric($motivo) ? trim((string) $motivo) : '';

        if ($nome === '' || $sku === '' || $motivo === '' || $motivo === '—') {
            return null;
        }

        return ['nome' => $nome, 'meta' => $sku, 'motivo' => $motivo];
    }

    /**
     * A conta precisa de reconexão?
     *
     * `oQueFazerAgora()` devolve a chave `reconectar` **só** nesse caso, e sozinha
     * — a presença dela na lista É o sinal, sem este serviço precisar reler o token
     * (que seria uma segunda fonte para o mesmo fato).
     *
     * @param  list<array>  $acoes
     */
    private function temReconexaoPendente(array $acoes): bool
    {
        foreach ($acoes as $acao) {
            if (($acao['chave'] ?? null) === 'reconectar') {
                return true;
            }
        }

        return false;
    }

    /** O estágio da Jornada por chave, ou `[]` — nunca lança por jornada ausente (D23). */
    private function estagio(array $jornada, string $chave): array
    {
        foreach (is_array($jornada['estagios'] ?? null) ? $jornada['estagios'] : [] as $estagio) {
            if (is_array($estagio) && ($estagio['chave'] ?? null) === $chave) {
                return $estagio;
            }
        }

        return [];
    }

    /** Inteiro de verdade ou `null` — nunca `0` por coerção de string vazia ou objeto. */
    private function inteiroOuNulo(mixed $valor): ?int
    {
        if (is_int($valor)) {
            return $valor;
        }

        return is_numeric($valor) ? (int) $valor : null;
    }
}
