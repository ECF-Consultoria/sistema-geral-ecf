<?php

namespace App\Services\Fechamento;

/**
 * ValidadorTabelaFaixas — as regras compostas da tabela de faixas de faturamento, fora do
 * FormRequest (quick 260910-l7k).
 *
 * Nasceu como extração pura de `SalvarFaixasFaturamentoRequest::withValidator()` — MESMAS quatro
 * regras, MESMA ordem, MESMAS mensagens. O motivo da extração: as regras estavam presas ao
 * FormRequest, então a confirmação da leitura automática dos contratos
 * (`TabelasContratoController::confirmar()`) gravava a tabela SEM validar nada. Duas tabelas
 * malformadas entraram em produção por essa porta antes desta separação — a faixa aberta (a que
 * pega o MAIOR faturamento) com o MENOR preço da tabela.
 *
 * Disciplina preservada da origem: PARA NO PRIMEIRO CONFLITO (mensagem única, sem spam de erros) e
 * o `campo` aponta para o ÍNDICE ORIGINAL do payload, não o índice pós-ordenação — é o que permite
 * ao front destacar a linha certa.
 *
 * As regras, nesta ordem:
 *  (a) `ordem` não pode repetir.
 *  (b) no máximo UMA faixa pode ficar sem `limite_superior` (a faixa "sem teto"), e ela precisa ser
 *      a de MAIOR `ordem` — é o fim da régua.
 *  (b2) `valor_e_piso` só pode ser verdadeiro na faixa sem teto — marcar "piso" numa faixa que TEM
 *      teto deixaria o valor ambíguo na cobrança.
 *  (c) os `limite_superior` preenchidos precisam ser estritamente crescentes na ordem — faixa
 *      não-crescente é sobreposição.
 *
 * ⚠️ Não existe "buraco" possível neste schema: `limite_inferior` de cada faixa NUNCA é campo de
 * input — é DERIVADO do `limite_superior` da faixa anterior em
 * `FechamentoFaixaResolver::classificar()`. Uma régua que passa na regra (c) é sempre contígua por
 * construção.
 *
 * @see app/Http/Requests/SalvarFaixasFaturamentoRequest.php (quem delega para cá no cadastro manual)
 * @see app/Services/Fechamento/FechamentoFaixaResolver.php (quem LÊ a régua validada aqui)
 */
class ValidadorTabelaFaixas
{
    /**
     * Confere a tabela INTEIRA e devolve os conflitos encontrados.
     *
     * @param  array<int, array<string, mixed>>  $faixas  payload cru (mesma forma do input do FormRequest)
     * @return array<int, array{campo: string, mensagem: string}> — vazio = tabela válida
     */
    public function erros(array $faixas): array
    {
        if ($faixas === []) {
            // Tabela vazia é problema das regras primárias (`required|array|min:1`), não daqui.
            return [];
        }

        $itens = collect($faixas)
            ->values()
            ->map(function ($item, int $idx) {
                $item   = is_array($item) ? $item : [];
                $limite = $item['limite_superior'] ?? null;

                return [
                    'idx'             => $idx,
                    'ordem'           => isset($item['ordem']) ? (int) $item['ordem'] : null,
                    'limite_superior' => ($limite === null || $limite === '') ? null : (float) $limite,
                    'valor_e_piso'    => filter_var($item['valor_e_piso'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ];
            })
            ->sortBy('ordem')
            ->values();

        // ── (a) ordem não pode repetir ────────────────────────────────────
        $ordens = $itens->pluck('ordem');
        if ($ordens->unique()->count() !== $ordens->count()) {
            return [[
                'campo'    => 'faixas',
                'mensagem' => 'Cada faixa precisa de uma ordem única — há ordens repetidas na tabela.',
            ]];
        }

        // ── (b) no máximo uma faixa sem teto, e ela é a última ────────────
        $semTeto = $itens->filter(fn ($i) => $i['limite_superior'] === null)->values();

        if ($semTeto->count() > 1) {
            return $semTeto->map(fn ($item) => [
                'campo'    => "faixas.{$item['idx']}.limite_superior",
                'mensagem' => 'Apenas uma faixa pode ficar sem limite superior (sem teto) — a de maior ordem.',
            ])->all();
        }

        if ($semTeto->count() === 1) {
            $maiorOrdem = $itens->max('ordem');

            if ($semTeto->first()['ordem'] !== $maiorOrdem) {
                return [[
                    'campo'    => "faixas.{$semTeto->first()['idx']}.limite_superior",
                    'mensagem' => 'A faixa sem limite superior precisa ser a de maior ordem (a última da tabela).',
                ]];
            }
        }

        // ── (b2) valor_e_piso só na faixa sem teto ────────────────────────
        $pisoComTeto = $itens->first(fn ($i) => $i['valor_e_piso'] === true && $i['limite_superior'] !== null);

        if ($pisoComTeto !== null) {
            return [[
                'campo'    => "faixas.{$pisoComTeto['idx']}.valor_e_piso",
                'mensagem' => 'Só a faixa sem limite superior pode ser marcada como "valor é piso" — numa faixa com teto o valor ficaria ambíguo na cobrança.',
            ]];
        }

        // ── (c) limite_superior estritamente crescente na ordem ───────────
        $anterior = null;

        foreach ($itens as $item) {
            if ($anterior !== null
                && $anterior['limite_superior'] !== null
                && $item['limite_superior'] !== null
                && $item['limite_superior'] <= $anterior['limite_superior']) {
                return [[
                    'campo'    => "faixas.{$item['idx']}.limite_superior",
                    'mensagem' => "Essa faixa se sobrepõe à faixa {$anterior['ordem']}. Ajuste o limite antes de salvar.",
                ]];
            }

            $anterior = $item;
        }

        return [];
    }
}
