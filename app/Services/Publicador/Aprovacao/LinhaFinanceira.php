<?php

namespace App\Services\Publicador\Aprovacao;

use App\Models\PubAprovacaoComposicao;
use App\Support\Publicador\RegraViolada;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * UMA LINHA da composição financeira do portão: **valor, origem e nota que NOMEIA a fonte**.
 *
 * As 7 chaves possíveis são as de {@see PubAprovacaoComposicao::LINHAS} (medidas no `fin()` do
 * protótipo, `Aprovacao.dc.html:1061-1079`), mais a linha **informativa** do frete pago pelo
 * comprador (FIN-08), que aparece e **não entra na soma**.
 *
 * ═══ ⚠️⚠️ A ORIGEM É **DERIVADA**, NUNCA UM PARÂMETRO LIVRE ════════════════════════════════════
 *
 * `Confirmado` / `Estimado` / `Pendente` saem de `valor === null` e de `fonteRespondeu`:
 *
 *   valor nulo  OU  fonte não respondeu   ⇒  **Pendente** (e o valor é forçado a `null`)
 *   fonte respondeu + valor + estimada    ⇒  **Estimado**
 *   fonte respondeu + valor               ⇒  **Confirmado**
 *
 * ⚠️ Linha nula com origem `Confirmado` é **impossível de construir**, não "proibida por
 * convenção": a tela mostraria "Confirmado" ao lado de um número que ninguém leu, e a composição
 * somaria zero no lugar da ausência.
 *
 * ═══ ⚠️ `0` MEDIDO ≠ AUSENTE ═══════════════════════════════════════════════════════════════════
 *
 * `valor = 0.0` com fonte que respondeu é **`Confirmado` mostrando R$ 0,00**. É a mesma régua do
 * `numeroMedido()` de `AbasDeStatus.jsx` (*"`null` é 'não medido' e `0` é 'medido e é zero'"*), e é
 * o caso REAL do Mercado Livre: das **17** ocorrências de `sale_fee_details` medidas nas fixtures
 * em 14/10/2026, **16 têm `fixed_fee: 0`** — zero porque o preço sondado está acima do limiar, não
 * porque o campo esteja morto. Tratar esse zero como `Pendente` travaria a composição inteira.
 *
 * ═══ ⚠️ A NOTA É OBRIGATÓRIA E NOMEIA A FONTE (DADO-13 / VOC-04) ═══════════════════════════════
 *
 * Construir sem nota **lança**. A nota é o que torna a composição auditável depois — e não há
 * desfazer (PUB-09): nota genérica ("origem automática") prova nada e foi recusada.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
final readonly class LinhaFinanceira
{
    /** Nota vazia: a linha deixaria de nomear a fonte e a composição deixaria de ser auditável. */
    public const REGRA_NOTA_VAZIA = 'DADO-13-NOTA-VAZIA';

    public ?float $valor;

    public string $origem;

    public function __construct(
        public string $chave,
        public string $rotulo,
        public string $detalhe,
        ?float $valor,
        bool $fonteRespondeu,
        public string $nota,
        bool $estimada = false,
        public bool $informativa = false,
    ) {
        if (trim($nota) === '') {
            throw new RegraViolada(
                self::REGRA_NOTA_VAZIA,
                'Falta dizer de onde veio este valor.',
                ['chave' => $chave]
            );
        }

        $respondeu = $fonteRespondeu && $valor !== null;

        $this->valor = $respondeu ? round((float) $valor, 2) : null;
        $this->origem = match (true) {
            ! $respondeu => PubAprovacaoComposicao::PENDENTE,
            $estimada => PubAprovacaoComposicao::ESTIMADO,
            default => PubAprovacaoComposicao::CONFIRMADO,
        };
    }

    /**
     * ⚠️ `float` NÃO ANULÁVEL de propósito: aqui nem o TypeError deixa passar uma linha nula
     * dizendo-se confirmada.
     */
    public static function confirmada(string $chave, string $rotulo, string $detalhe, float $valor, string $nota): self
    {
        return new self($chave, $rotulo, $detalhe, $valor, true, $nota);
    }

    public static function estimada(string $chave, string $rotulo, string $detalhe, float $valor, string $nota): self
    {
        return new self($chave, $rotulo, $detalhe, $valor, true, $nota, estimada: true);
    }

    /** Sem parâmetro de valor nenhum: a ausência não tem número. */
    public static function pendente(string $chave, string $rotulo, string $detalhe, string $nota): self
    {
        return new self($chave, $rotulo, $detalhe, null, false, $nota);
    }

    /**
     * FIN-08 — a linha que **aparece e não entra na soma** ("Frete pago pelo comprador").
     *
     * ⚠️ Ela fica fora do total, fora de `incompleto` e fora de `estimadoPorBaixo`: não é uma
     * lacuna da conta, é informação de contexto.
     */
    public static function informativa(string $chave, string $rotulo, string $detalhe, ?float $valor, string $nota): self
    {
        return new self($chave, $rotulo, $detalhe, $valor, $valor !== null, $nota, informativa: true);
    }

    public function ehPendente(): bool
    {
        return $this->origem === PubAprovacaoComposicao::PENDENTE;
    }

    /** O que a pessoa lê — a chave é identificador e nunca chega à tela (VOC-06). */
    public function rotuloDaOrigem(): string
    {
        return PubAprovacaoComposicao::ORIGENS_DA_LINHA[$this->origem] ?? $this->origem;
    }

    /**
     * A forma que vai para `pub_aprovacao_composicoes.linhas` (json).
     *
     * @return array{chave: string, rotulo: string, detalhe: string, valor: ?float, origem: string, nota: string, informativa: bool}
     */
    public function paraLinhaCongelada(): array
    {
        return [
            'chave' => $this->chave,
            'rotulo' => $this->rotulo,
            'detalhe' => $this->detalhe,
            'valor' => $this->valor,
            'origem' => $this->origem,
            'nota' => $this->nota,
            'informativa' => $this->informativa,
        ];
    }
}
