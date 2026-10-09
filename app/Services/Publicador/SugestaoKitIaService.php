<?php

namespace App\Services\Publicador;

use App\Jobs\Publicador\GerarSugestaoKitIaJob;
use App\Models\PubRascunho;
use App\Services\Ia\AnaliseAnuncioService;
use App\Support\Publicador\RegraViolada;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * "Sugerir com IA" do painel "Criar Fase N" (§4 da ETAPA-3, Fase 175): a IA
 * reescreve **só o TÍTULO e a DESCRIÇÃO** do kit a partir dos da Fase 1.
 *
 * SKU, SELLER_SKU e estoque continuam inteiramente da `PreviaDaFaseService`
 * (regra simples, conferível e sem custo) — a IA não encosta neles.
 *
 * ═══ Por que é um serviço IRMÃO do `PalavrasChaveService`, e não um alvo novo lá ══
 *
 * A FORMA é a mesma (pedir → Job → cache → `estado()`), e é de propósito: o
 * contrato já está provado em produção e a tela já sabe acompanhá-lo. O que
 * NÃO dá para reaproveitar é o conteúdo. `PalavrasChaveService::ALVOS` e
 * `executar()` estão em produção, e o ramo de título deles chama a API de
 * termos mais buscados do ML (`TermosMaisBuscadosService`): prompt de SEO por
 * busca de categoria, errado para um kit, que não disputa busca nova — ele só
 * precisa dizer, no título já conferido da Fase 1, que agora são N unidades.
 * Então aquele arquivo fica INTOCADO e este tem alvos, prompt e chave de cache
 * próprios.
 *
 * ═══ Três decisões que não são dedutíveis do código ═════════════════════════
 *
 * 1. **O pedido é escopado ao rascunho do BASE.** No momento do painel o
 *    rascunho do kit AINDA NÃO EXISTE — ele nasce no Confirmar
 *    (`CriarFaseService`). Logo a chave é a do rascunho do base, com um
 *    prefixo próprio (`publicador:kit-ia:`) para não colidir com os pedidos de
 *    `modelo`/`titulo_gold_*` do `PalavrasChaveService`, que vivem no MESMO
 *    rascunho.
 *
 * 2. **A quantidade entra na chave.** Trocar o N no painel muda o resultado
 *    esperado: o pedido de "Kit 2" não pode responder ao painel de "Kit 4", e
 *    o operador que volta para 2 reaproveita o que já ficou pronto.
 *
 * 3. **Nada é gravado no rascunho por trás da pessoa.** O resultado fica no
 *    cache por pedido; quem aplica é a tela, pelo caminho normal de edição.
 *    Mesma disciplina (e mesma razão) do `PalavrasChaveService`.
 *
 * O pós-processamento é daqui, nunca do modelo: o prefixo `Kit {N} ` do título
 * e a frase obrigatória da descrição são GARANTIDOS no servidor. Número errado
 * (ou perdido) num texto que a pessoa vai aprovar é pior que número nenhum — o
 * modelo esquece a instrução e o texto sai coerente consigo mesmo.
 */
class SugestaoKitIaService
{
    /** Lista FECHADA e própria desta classe (a do `PalavrasChaveService` é outra). */
    public const ALVOS = ['titulo', 'descricao'];

    /** Mesmo TTL do pedido de palavras-chave: meia hora cobre a IA mais lenta com folga. */
    private const TTL_PEDIDO = 1800;

    public function __construct(
        private AnaliseAnuncioService $ia,
        private CategorySchemaRepository $schemas,
    ) {}

    /**
     * Põe o pedido na fila e devolve o id dele.
     *
     * `$base` é o rascunho do produto BASE (decisão 1 do docblock da classe).
     *
     * @throws RegraViolada alvo fora de `ALVOS`
     */
    public function pedir(PubRascunho $base, string $alvo, int $quantidade): string
    {
        $this->exigirAlvo($alvo);

        $pedido = (string) Str::uuid();
        Cache::put(
            self::chave($base->id, $alvo, $quantidade),
            ['pedido' => $pedido, 'status' => 'rodando', 'valor' => null, 'erro' => null],
            self::TTL_PEDIDO,
        );
        GerarSugestaoKitIaJob::dispatch($base->id, $alvo, $quantidade, $pedido);

        return $pedido;
    }

    /** O pedido mais recente deste (alvo, quantidade); nulo = nenhum. */
    public function estado(PubRascunho $base, string $alvo, int $quantidade): ?array
    {
        $e = Cache::get(self::chave($base->id, $alvo, $quantidade));

        return is_array($e) ? $e : null;
    }

    /**
     * Roda no Job: grava `pronto` ou `erro` — só se o pedido ainda for o mais
     * recente (`concluir()`).
     *
     * @throws \Throwable o erro é relançado DEPOIS de registrado, para o Job logar
     */
    public function executar(PubRascunho $base, string $alvo, int $quantidade, string $pedido, ?float $prazo = null): void
    {
        try {
            $this->exigirAlvo($alvo);
            $valor = $alvo === 'descricao'
                ? $this->descricao($base, $quantidade, $prazo)
                : $this->titulo($base, $quantidade, $prazo);

            if (trim($valor) === '') {
                throw new \RuntimeException('A IA não devolveu nada aproveitável. Tente de novo.');
            }

            $this->concluir($base->id, $alvo, $quantidade, $pedido, ['status' => 'pronto', 'valor' => $valor, 'erro' => null]);
        } catch (\Throwable $e) {
            $this->concluir($base->id, $alvo, $quantidade, $pedido, ['status' => 'erro', 'valor' => null, 'erro' => $e->getMessage()]);

            throw $e;
        }
    }

    /** Marca o pedido como falho (Job que caiu sem passar pelo `executar`). */
    public function falhou(int $rascunhoBaseId, string $alvo, int $quantidade, string $pedido, string $mensagem): void
    {
        $this->concluir($rascunhoBaseId, $alvo, $quantidade, $pedido, ['status' => 'erro', 'valor' => null, 'erro' => $mensagem]);
    }

    /** Chave PRÓPRIA — nunca a de `PalavrasChaveService::chave()`. */
    public static function chave(int $rascunhoBaseId, string $alvo, int $quantidade): string
    {
        return "publicador:kit-ia:{$rascunhoBaseId}:{$alvo}:{$quantidade}";
    }

    // ═══ Pós-processamento (puro) ════════════════════════════════════════════

    /**
     * O título do kit como ele pode ir para a tela: com a marca `Kit {N}`
     * garantida e cortado na última palavra inteira que cabe no máximo da
     * categoria (`PalavrasChaveService::ajustarTitulo`, o cortador do módulo —
     * reaproveitado, nunca reescrito).
     *
     * O prefixo entra no COMEÇO porque o corte é pelo fim: assim o N nunca se
     * perde, nem no título que estoura o limite.
     */
    public static function ajustarTituloDoKit(string $bruto, int $n, int $maximo): string
    {
        $limpo = trim($bruto);
        if (! self::temMarcaDeKit($limpo, $n)) {
            $limpo = "Kit {$n} {$limpo}";
        }

        return PalavrasChaveService::ajustarTitulo($limpo, $maximo > 0 ? $maximo : PreviaDaFaseService::MAX_TITULO_PADRAO);
    }

    /**
     * A descrição do kit com a frase obrigatória da §4 no COMEÇO, uma só vez.
     *
     * Quando o modelo escreveu a frase no meio do texto, ela sai de lá e volta
     * para a primeira linha: prepender sem remover duplicaria a frase, e deixar
     * como veio deixaria a contagem perdida no meio do parágrafo.
     */
    public static function ajustarDescricaoDoKit(string $bruto, string $nomeBase, int $n): string
    {
        $texto = trim($bruto);
        if ($texto === '') {
            return '';
        }

        $frase = self::fraseDoKit($nomeBase, $n);
        if (str_starts_with($texto, $frase)) {
            return $texto;
        }

        $semFrase = trim(str_replace($frase, '', $texto));
        $semFrase = (string) preg_replace('/[ \t]+/u', ' ', $semFrase);

        return $semFrase === '' ? $frase : $frase."\n\n".$semFrase;
    }

    /** A frase obrigatória da §4 — a MESMA de `PreviaDaFaseService::descricaoSugerida()`. */
    public static function fraseDoKit(string $nomeBase, int $n): string
    {
        return "Este kit contém {$n} unidades de ".trim($nomeBase).'.';
    }

    /**
     * O título já carrega a marca deste kit (`Kit 2`, `KIT 02`, "Cadeiras Kit 2"…)?
     *
     * Fronteira de palavra nos dois lados: `Kit 20` NÃO é a marca de `Kit 2` —
     * sem isso, um kit de 2 com título "Kit 20 Cadeiras" sairia sem o próprio N.
     */
    private static function temMarcaDeKit(string $titulo, int $n): bool
    {
        return preg_match('/\bkit\s*0*'.$n.'\b/iu', $titulo) === 1;
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    private function titulo(PubRascunho $base, int $quantidade, ?float $prazo): string
    {
        $maximo = $this->maxTitulo($base);
        $bruto = $this->chamarIa('titulo', $base, $quantidade, $maximo, $prazo);

        return self::ajustarTituloDoKit($bruto, $quantidade, $maximo);
    }

    private function descricao(PubRascunho $base, int $quantidade, ?float $prazo): string
    {
        $bruto = $this->chamarIa('descricao', $base, $quantidade, $this->maxTitulo($base), $prazo);

        return self::ajustarDescricaoDoKit($bruto, $this->nomeDoBase($base), $quantidade);
    }

    /**
     * A chamada à IA e a ÚNICA porta de entrada do texto dela.
     *
     * ⚠️ O vazio é recusado AQUI, no texto CRU — não depois do
     * pós-processamento: `ajustarTituloDoKit('')` devolveria "Kit 2", que
     * parece um título pronto e viraria um `status: pronto` mentiroso na tela.
     */
    private function chamarIa(string $alvo, PubRascunho $base, int $quantidade, int $maximo, ?float $prazo): string
    {
        $ia = $prazo !== null ? $this->ia->comPrazo($prazo) : $this->ia;

        $bruto = (string) $ia->textoDeKit(
            $alvo,
            $this->nomeDoBase($base),
            $this->tituloDoBase($base),
            $base->descricao,
            $quantidade,
            $maximo,
        )['dados'];

        if (trim($bruto) === '') {
            throw new \RuntimeException('A IA não devolveu nada aproveitável. Tente de novo.');
        }

        return $bruto;
    }

    private function nomeDoBase(PubRascunho $base): string
    {
        return (string) ($base->produto?->nomeExibido() ?? '');
    }

    /**
     * O título da Fase 1 que serve de ponto de partida: o do primeiro tipo de
     * anúncio ATIVO (mesma ordem da `PreviaDaFaseService::titulos()`). Alvo sem
     * título cai no nome do produto — a IA precisa de algo para reescrever.
     */
    private function tituloDoBase(PubRascunho $base): string
    {
        foreach ($base->alvos()->where('ativo', true)->orderBy('posicao')->orderBy('id')->get() as $alvo) {
            $titulo = trim((string) $alvo->titulo);
            if ($titulo !== '') {
                return $titulo;
            }
        }

        return $this->nomeDoBase($base);
    }

    /**
     * O `max_title_length` da categoria do base, com fallback 60 — mesma
     * tolerância da `PreviaDaFaseService::maxTitulo()`: qualquer falha de
     * leitura (categoria nova, ML fora, token recusado) vira o padrão, nunca um
     * 500 no meio do pedido de IA.
     */
    private function maxTitulo(PubRascunho $base): int
    {
        if (! $base->categoria_id) {
            return PreviaDaFaseService::MAX_TITULO_PADRAO;
        }

        try {
            $maximo = (int) ($this->schemas->obter((string) $base->categoria_id)->settings()['max_title_length'] ?? 0);

            return $maximo > 0 ? $maximo : PreviaDaFaseService::MAX_TITULO_PADRAO;
        } catch (\Throwable) {
            return PreviaDaFaseService::MAX_TITULO_PADRAO;
        }
    }

    /** @throws RegraViolada */
    private function exigirAlvo(string $alvo): void
    {
        if (! in_array($alvo, self::ALVOS, true)) {
            throw new RegraViolada('KIT-IA-01', 'A IA do kit só sugere título e descrição.');
        }
    }

    private function concluir(int $rascunhoBaseId, string $alvo, int $quantidade, string $pedido, array $resultado): void
    {
        $chave = self::chave($rascunhoBaseId, $alvo, $quantidade);
        $atual = Cache::get($chave);
        // Um pedido mais novo já está na fila: este resultado perdeu a vez.
        if (is_array($atual) && ($atual['pedido'] ?? null) !== $pedido) {
            return;
        }
        Cache::put($chave, ['pedido' => $pedido, ...$resultado], self::TTL_PEDIDO);
    }
}
