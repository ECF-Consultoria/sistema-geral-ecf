<?php

namespace App\Services\Publicador\Aprovacao;

use App\Models\PubAprovacaoComposicao;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * A COMPOSIÇÃO FINANCEIRA de UMA oferta: as linhas, o resultado, a margem, o equilíbrio e o
 * preço-alvo. **Puro** — não lê banco, não lê API e não grava nada.
 *
 * ═══ A FÓRMULA É A DO PROTÓTIPO (`Aprovacao.dc.html`, `fin()` em `:1061-1079`) ═════════════════
 *
 *   total      = soma das linhas NÃO informativas
 *   resultado  = preço − total
 *   margem     = resultado / preço × 100
 *   somaFixa   = custo + tarifa fixa + frete + embalagem + outros   (as parcelas em R$)
 *   vr         = (comissão% + imposto%) / 100                       (as parcelas em %)
 *   equilibrio = c2(somaFixa / (1 − vr))
 *   precoAlvo  = c2(somaFixa / (1 − vr − mínimo%/100))
 *
 * ⚠️ `c2` arredonda **PARA CIMA** (`ceil(n × 100 − 1e-6) / 100`), não arredonda: equilíbrio e
 * preço-alvo são **teto**. Com `round()` o preço-alvo sairia **abaixo** do necessário e a oferta
 * aprovada ficaria abaixo do mínimo que o próprio painel pediu.
 *
 * ⚠️⚠️ **O PROTÓTIPO GANHA NA FÓRMULA, NOS RÓTULOS E NA ORDEM — NUNCA NOS NÚMEROS.** As taxas do
 * `fin()` são **ficção declarada pelo próprio protótipo** (`:1064` *"taxa de exemplo"*, `:1066`
 * *"exemplo"*, `src: 'est'`). Os percentuais chegam aqui de fora, das fontes reais, por
 * {@see ComposicaoFinanceiraService}.
 *
 * ═══ ⚠️ `null` ONDE FALTA DADO — NUNCA `0.0` (FIN-05 / D4) ═════════════════════════════════════
 *
 * Faltando qualquer uma das **CINCO** de {@see PubAprovacaoComposicao::ANULAM_O_RESULTADO},
 * `total`, `resultado`, `margem`, `somaFixa`, `equilibrio` e `precoAlvo` são **todos `null`** e a
 * tela diz "Incompleto". Zero é um número, e a tela o mostraria como se fosse verdade.
 *
 * ⚠️⚠️ **A ÚNICA EXCEÇÃO DELIBERADA — decisão do usuário de 14/10/2026:** "Embalagem" sem fonte
 * nasce `Pendente` **VISÍVEL** e **NÃO** anula o resultado; o resultado existe com
 * `estimadoPorBaixo = true` e a nota diz *"sem o custo de embalagem"*. "Outros custos" nasce
 * **R$ 0,00 `Confirmado`** (é o que o protótipo faz, `:1070`).
 *
 * **A razão, escrita para ninguém "uniformizar" depois:** não existe fonte de embalagem **nem** de
 * outros custos no repositório (MEDIDO em 14/10/2026). Pela regra crua, **100%** das ofertas
 * sairiam "Incompleto", o bloqueio de frete dispararia sempre e **nada poderia ser aprovado** — um
 * portão que não deixa nada passar. As outras duas opções foram oferecidas e recusadas: campo novo
 * de custo de embalagem (trabalho a mais nesta fase) e as duas em zero (assumiria embalagem de
 * graça, **inflando a margem**).
 *
 * ➡️ **Consequência DERIVADA, declarada como dedução do planejador e não como fala do usuário:**
 * `equilibrio` e `precoAlvo` **também existem** com `estimadoPorBaixo`, carregando a mesma marca —
 * suprimi-los deixaria o atalho "preço para a margem mínima" (FIN-11) **sempre vazio**, e ele é a
 * ferramenta principal da tela.
 *
 * ➡️ **E os bloqueios `loss`/`margin` DISPARAM sobre o valor estimado** (plano 186-08). É
 * segurança, não detalhe: o resultado estimado é **otimista**, então a margem **real** é menor que
 * a mostrada — com aprovar-já-publica e **sem desfazer**, não bloquear seria o erro grave.
 *
 * ═══ ⚠️ A NOTA DA MARGEM: COR NUNCA É O ÚNICO SINAL (VOC-05 / FIN-10) ══════════════════════════
 *
 * `notaDaMargem` **sempre** acompanha a barra, com uma das quatro frases — *"acima do mínimo de
 * N%"*, *"abaixo do mínimo de N%"*, *"prejuízo por venda"* ou *"dados incompletos"*. ⚠️ O **N vem
 * do mínimo RESOLVIDO** (parâmetro da empresa ou config), nunca de número embutido: com o `12`
 * cravado, a tela mentiria para a empresa que configurou outro mínimo.
 *
 * ⚠️ **Decisão (9) do usuário, 14/10/2026 — cada margem diz de onde vem:** a nota do portão diz
 * *"com a tarifa real do Mercado Livre"*. Ver o docblock de {@see ComposicaoFinanceiraService}
 * para a divergência DE PROPÓSITO com a visão rápida do lote.
 *
 * ═══ ⚠️ VOC-03 / FIN-14 — O VOCABULÁRIO TRAVADO ════════════════════════════════════════════════
 *
 * Os dois nomes são {@see PubAprovacaoComposicao::ROTULO_RESULTADO} (*"Resultado estimado por
 * venda"*) e {@see PubAprovacaoComposicao::ROTULO_MARGEM} (*"Margem de contribuição estimada"*).
 * ⚠️ **Nunca** o outro nome, aquele que promete descontar despesa fixa e devolução — nada aqui as
 * desconta, e usá-lo seria afirmação falsa. Ele existe como campo REAL e legítimo em
 * `EstruturaPrecificacaoParametros` (`:26`/`:30`/`:37`), do Portal, e **não é exposto aqui**.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
final readonly class ComposicaoDaOferta
{
    /**
     * @param  list<LinhaFinanceira>  $linhas  as 7 da conta, na ordem, mais as informativas
     */
    private function __construct(
        public float $preco,
        public array $linhas,
        public ?float $total,
        public ?float $resultado,
        public ?float $margem,
        public ?float $somaFixa,
        public ?float $equilibrio,
        public ?float $precoAlvo,
        public bool $incompleto,
        public bool $estimadoPorBaixo,
        public float $margemMinima,
        public string $margemMinimaOrigem,
        public string $notaDaMargem,
        public ?string $precoDeVariante,
        public ?string $notaDoPreco,
    ) {}

    /**
     * @param  list<LinhaFinanceira>  $linhas
     * @param  ?float  $comissaoPercentual  ponto percentual respondido pelo ML (`11`, não `0,11`)
     * @param  ?float  $impostoPercentual  ponto percentual dos parâmetros da empresa (ou da exceção)
     * @param  ?string  $precoDeVariante  a variante de que o preço saiu, quando há mais de um ativo
     * @param  int  $precosAtivos  quantos preços ativos o alvo tem (1 = o caso comum, sem nota)
     */
    public static function montar(
        float $preco,
        array $linhas,
        ?float $comissaoPercentual,
        ?float $impostoPercentual,
        float $margemMinima,
        string $margemMinimaOrigem,
        ?string $precoDeVariante = null,
        int $precosAtivos = 1,
    ): self {
        $daConta = array_values(array_filter($linhas, fn (LinhaFinanceira $l) => ! $l->informativa));

        // ⚠️ Só as CINCO anulam. Embalagem e outros custos NÃO estão na lista (exceção de 14/10).
        $incompleto = false;
        $estimadoPorBaixo = false;
        foreach ($daConta as $linha) {
            if (! $linha->ehPendente()) {
                continue;
            }
            if (in_array($linha->chave, PubAprovacaoComposicao::ANULAM_O_RESULTADO, true)) {
                $incompleto = true;

                continue;
            }
            $estimadoPorBaixo = true;
        }

        // ⚠️ Sem o percentual da comissão ou do imposto não há `vr`, e sem `vr` o equilíbrio sairia
        // IGUAL à soma fixa — um número plausível e errado. Trata como falta de dado.
        if ($comissaoPercentual === null || $impostoPercentual === null) {
            $incompleto = true;
        }

        if ($incompleto) {
            return new self(
                preco: $preco, linhas: array_values($linhas),
                total: null, resultado: null, margem: null, somaFixa: null, equilibrio: null, precoAlvo: null,
                incompleto: true, estimadoPorBaixo: $estimadoPorBaixo,
                margemMinima: $margemMinima, margemMinimaOrigem: $margemMinimaOrigem,
                notaDaMargem: 'dados incompletos',
                precoDeVariante: $precosAtivos > 1 ? $precoDeVariante : null,
                notaDoPreco: self::notaDoPreco($precoDeVariante, $precosAtivos),
            );
        }

        $total = 0.0;
        foreach ($daConta as $linha) {
            $total += (float) ($linha->valor ?? 0.0);
        }
        $total = round($total, 2);

        $resultado = round($preco - $total, 2);
        $margem = $preco > 0 ? $resultado / $preco * 100 : null;

        // A ORDEM de `$daConta` é a do protótipo, e o filtro preserva a ordem (`:1076`).
        $somaFixa = 0.0;
        foreach ($daConta as $linha) {
            if (in_array($linha->chave, ['custo', 'tarifa_fixa', 'frete', 'embalagem', 'outros'], true)) {
                $somaFixa += (float) ($linha->valor ?? 0.0);
            }
        }

        $vr = ((float) $comissaoPercentual + (float) $impostoPercentual) / 100;

        return new self(
            preco: $preco, linhas: array_values($linhas),
            total: $total, resultado: $resultado, margem: $margem, somaFixa: $somaFixa,
            equilibrio: self::teto($somaFixa, 1 - $vr),
            precoAlvo: self::teto($somaFixa, 1 - $vr - $margemMinima / 100),
            incompleto: false, estimadoPorBaixo: $estimadoPorBaixo,
            margemMinima: $margemMinima, margemMinimaOrigem: $margemMinimaOrigem,
            notaDaMargem: self::notaDaMargem($margem, $margemMinima, $estimadoPorBaixo),
            precoDeVariante: $precosAtivos > 1 ? $precoDeVariante : null,
            notaDoPreco: self::notaDoPreco($precoDeVariante, $precosAtivos),
        );
    }

    /** As 7 da conta, na ordem. A informativa de FIN-08 fica fora. */
    /** @return list<LinhaFinanceira> */
    public function linhasDaConta(): array
    {
        return array_values(array_filter($this->linhas, fn (LinhaFinanceira $l) => ! $l->informativa));
    }

    /** @return list<LinhaFinanceira> */
    public function linhasInformativas(): array
    {
        return array_values(array_filter($this->linhas, fn (LinhaFinanceira $l) => $l->informativa));
    }

    public function linha(string $chave): ?LinhaFinanceira
    {
        foreach ($this->linhas as $linha) {
            if ($linha->chave === $chave) {
                return $linha;
            }
        }

        return null;
    }

    /** De qual lado do fallback o mínimo veio, em português. */
    public function rotuloDoMinimo(): string
    {
        return PubAprovacaoComposicao::ORIGENS_DO_MINIMO[$this->margemMinimaOrigem] ?? $this->margemMinimaOrigem;
    }

    /** @return list<array<string, mixed>> a forma que vai para `pub_aprovacao_composicoes.linhas` */
    public function linhasCongeladas(): array
    {
        return array_map(fn (LinhaFinanceira $l) => $l->paraLinhaCongelada(), $this->linhas);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────

    /** O `c2` do protótipo: arredondamento **PARA CIMA**, com a tolerância de 1e-6 dele. */
    private static function teto(float $numerador, float $divisor): ?float
    {
        if ($divisor <= 0.0) {
            return null;
        }

        return ceil($numerador / $divisor * 100 - 1e-6) / 100;
    }

    /** ⚠️ As quatro frases de FIN-10, com o N do mínimo RESOLVIDO e a fonte da tarifa. */
    private static function notaDaMargem(?float $margem, float $minimo, bool $estimadoPorBaixo): string
    {
        $frase = match (true) {
            $margem === null => 'dados incompletos',
            $margem < 0 => 'prejuízo por venda',
            $margem < $minimo => 'abaixo do mínimo de '.self::percentual($minimo),
            default => 'acima do mínimo de '.self::percentual($minimo),
        };

        // ⚠️ Decisão (9): a tela NOMEIA a fonte do número.
        $frase .= ' · com a tarifa real do Mercado Livre';

        if ($estimadoPorBaixo) {
            $frase .= ' · sem o custo de embalagem';
        }

        return $frase;
    }

    /** ⚠️ Decisão (5): manda o PIOR CASO, e a tela NOMEIA a variante. Com um preço só, sem nota. */
    private static function notaDoPreco(?string $precoDeVariante, int $precosAtivos): ?string
    {
        if ($precosAtivos <= 1) {
            return null;
        }

        $nota = 'pior caso entre '.$precosAtivos.' variantes';

        return $precoDeVariante === null ? $nota : $nota.' · '.$precoDeVariante;
    }

    /** `12` vira "12%"; `12,5` vira "12,5%". Em pt-BR, sem casa decimal inútil. */
    private static function percentual(float $n): string
    {
        $casas = abs($n - round($n)) < 0.0005 ? 0 : 1;

        return number_format($n, $casas, ',', '.').'%';
    }
}
