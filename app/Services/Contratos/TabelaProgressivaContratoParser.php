<?php

namespace App\Services\Contratos;

/**
 * TabelaProgressivaContratoParser — texto de contrato → tipo de cobrança + faixas + CNPJ +
 * razão social (Fase 140 Plano 02).
 *
 * D-03 do CONTEXT: nem todo contrato de "Gestão de ADS" tem tabela progressiva — 6 de 11 da
 * amostra são VALOR FIXO ("parcelas mensais e iguais de R$ 3.000,00"). É exatamente aqui que o
 * sistema errava antes desta fase: pegava contrato de valor fixo e classificava em faixa. Por
 * isso a checagem de valor fixo roda ANTES de qualquer tentativa de reconhecer tabela — prioridade
 * de exclusão (ver `pareceValorFixo()`).
 *
 * D-04 do CONTEXT: DUAS notações de tabela convivem em contratos reais e as duas são lidas pelo
 * MESMO reconhecedor de "marcos" (linha por linha), unificando o tratamento:
 *
 * - notação nova (ALUMEN, mar/2026): `Até R$500.000,00`, `A partir de R$ 1.000.000,00`,
 *   `Acima de R$ 5.000.000,00` — valor em reais por extenso na própria linha.
 * - notação antiga (DESK DESIGN, dez/2025) — abreviada: `-100M`, `+100M`, `+1MM`, `+15MM`.
 *   ⚠️ `M` = mil e `MM` = milhão — NUNCA o contrário. Ler `100M` como 100 milhões erra por mil
 *   vezes, e o número vira cobrança real (ver `extrairPontoDeLimiar()` e os testes dedicados a
 *   essa armadilha).
 * - notação antiga — por extenso: `até 100 mil`, `a partir de 1 milhão`, `acima de 15 milhões`.
 *
 * Cada linha do texto é testada contra os três formatos, nessa ordem; a primeira que casar decide
 * o "marco" daquela linha (fecha uma faixa ou abre a próxima). Um `-100M` / `Até R$ X` FECHA a
 * faixa corrente no limiar lido; um `+100M` / `A partir de R$ X` / `Acima de R$ X` ABRE uma faixa
 * cujo limite superior é o limiar do PRÓXIMO marco (ou aberto, se for o último). Essa é a mesma
 * lógica nas duas notações — só a leitura do número muda.
 *
 * Contrato de retorno de `analisar()`: `array{tipo, faixas, valor_fixo, cnpj, razao_social,
 * avisos}`, onde cada faixa tem exatamente o shape de `App\Models\EmpresaFaixaFaturamento`
 * (`ordem`, `limite_superior`, `valor`, `valor_e_piso`) — o plano 140-05 grava direto, sem
 * tradutor no meio.
 */
class TabelaProgressivaContratoParser
{
    /**
     * Mínimo de marcos reconhecidos para aceitar como tabela (D-04) — texto com só uma ou duas
     * linhas batendo no padrão vira `indefinido` com aviso, nunca uma tabela pela metade.
     */
    private const MINIMO_MARCOS_PARA_TABELA = 3;

    /**
     * @return array{
     *     tipo: 'tabela'|'valor_fixo'|'indefinido',
     *     faixas: array<int, array{ordem: int, limite_superior: ?float, valor: float, valor_e_piso: bool}>,
     *     valor_fixo: ?float,
     *     cnpj: ?string,
     *     razao_social: ?string,
     *     avisos: array<int, string>,
     * }
     */
    public function analisar(string $texto): array
    {
        [$cnpj, $razaoSocial, $avisosCnpj] = $this->extrairCnpjERazaoSocial($texto);

        // Prioridade de exclusão (D-03): valor fixo é checado ANTES de tentar reconhecer tabela.
        if ($this->pareceValorFixo($texto)) {
            return [
                'tipo'         => 'valor_fixo',
                'faixas'       => [],
                'valor_fixo'   => $this->extrairValorFixo($texto),
                'cnpj'         => $cnpj,
                'razao_social' => $razaoSocial,
                'avisos'       => $avisosCnpj,
            ];
        }

        $marcos = $this->extrairMarcos($texto);

        if (count($marcos) >= self::MINIMO_MARCOS_PARA_TABELA) {
            $faixas = $this->marcosParaFaixas($marcos);

            return [
                'tipo'         => 'tabela',
                'faixas'       => $faixas,
                'valor_fixo'   => null,
                'cnpj'         => $cnpj,
                'razao_social' => $razaoSocial,
                'avisos'       => array_merge($avisosCnpj, $this->avisarInconsistencias($faixas)),
            ];
        }

        // Um ou dois marcos batidos não é tabela pela metade — é indício insuficiente.
        $avisosIndefinido = $avisosCnpj;
        if (count($marcos) > 0) {
            $avisosIndefinido[] = 'texto tem indícios de tabela progressiva, mas menos de '
                . self::MINIMO_MARCOS_PARA_TABELA . ' faixas foram reconhecidas — não completar pela metade';
        }

        return [
            'tipo'         => 'indefinido',
            'faixas'       => [],
            'valor_fixo'   => null,
            'cnpj'         => $cnpj,
            'razao_social' => $razaoSocial,
            'avisos'       => $avisosIndefinido,
        ];
    }

    // ═══ VALOR FIXO ═══

    /**
     * D-03: valor fixo é "parcelas mensais e iguais de R$ X" SEM nenhuma ocorrência de "faixa",
     * "progressiv" ou "faturamento mensal" — as três palavras que aparecem em TODO contrato com
     * tabela (mesmo na notação abreviada, que traz "Faturamento" no cabeçalho da tabela).
     */
    private function pareceValorFixo(string $texto): bool
    {
        if (!preg_match('/parcelas\s+mensais\s+e\s+iguais\s+de\s+R\$\s*[\d.]+,\d{2}/iu', $texto)) {
            return false;
        }

        $textoLower = mb_strtolower($texto);

        foreach (['faixa', 'progressiv', 'faturamento mensal'] as $palavraQueDescartaValorFixo) {
            if (str_contains($textoLower, $palavraQueDescartaValorFixo)) {
                return false;
            }
        }

        return true;
    }

    private function extrairValorFixo(string $texto): ?float
    {
        if (preg_match('/parcelas\s+mensais\s+e\s+iguais\s+de\s+R\$\s*([\d.]+,\d{2})/iu', $texto, $m)) {
            return $this->paraFloat($m[1]);
        }

        return null;
    }

    // ═══ TABELA — reconhecimento de marcos, linha a linha ═══

    /**
     * Varre o texto linha a linha e devolve os "marcos" reconhecidos, na ordem em que aparecem no
     * documento. Cada marco é `['tipo' => 'fecha'|'abre', 'limiar' => float, 'valor' => float,
     * 'valor_e_piso' => bool]`.
     *
     * @return array<int, array{tipo: string, limiar: float, valor: float, valor_e_piso: bool}>
     */
    private function extrairMarcos(string $texto): array
    {
        $marcos = [];

        foreach (preg_split('/\r\n|\r|\n/', $texto) as $linha) {
            $ponto = $this->extrairPontoDeLimiar($linha);

            if ($ponto === null) {
                continue;
            }

            $resto = substr($linha, $ponto['fim']);

            // Valor do investimento na mesma linha. "a partir de R$ X" é o CASO DO VALOR (não do
            // limite) que marca `valor_e_piso = true` — a faixa cobra NO MÍNIMO aquilo, sem ser
            // preço fechado. Um "R$ X" simples é preço fechado.
            if (preg_match('/a\s*partir\s*de\s*R\$\s*([\d.]+,\d{2})/iu', $resto, $mv)) {
                $valor        = $this->paraFloat($mv[1]);
                $valorEhPiso  = true;
            } elseif (preg_match('/R\$\s*([\d.]+,\d{2})/u', $resto, $mv)) {
                $valor        = $this->paraFloat($mv[1]);
                $valorEhPiso  = false;
            } else {
                // Linha bateu no padrão de limiar, mas não tem valor associado — não é dado de
                // faixa (provavelmente cabeçalho ou frase solta), ignora.
                continue;
            }

            $marcos[] = [
                'tipo'         => $ponto['tipo'],
                'limiar'       => $ponto['limiar'],
                'valor'        => $valor,
                'valor_e_piso' => $valorEhPiso,
            ];
        }

        return $marcos;
    }

    /**
     * Tenta reconhecer, numa única linha, um "ponto de limiar" de faturamento nas TRÊS formas
     * conhecidas (D-04): abreviada (`-100M`/`+1MM`), por extenso (`até 100 mil`/`a partir de 1
     * milhão`) ou nova (`Até R$500.000,00`). Devolve `null` se a linha não bate em nenhuma.
     *
     * @return array{tipo: 'fecha'|'abre', limiar: float, fim: int}|null
     */
    private function extrairPontoDeLimiar(string $linha): ?array
    {
        // Notação antiga ABREVIADA: -100M, +100M, +1MM, +15MM.
        // ⚠️ M = mil, MM = milhão. Case-sensitive de propósito: a convenção do contrato é sempre
        // maiúscula, e manter sensível a caso evita que a letra solta de outra palavra confunda o
        // reconhecedor. O `\b` no fim impede que isto case dentro de "mil"/"milhão" (que começam
        // com letra minúscula e continuam com letras, nunca fecham fronteira de palavra ali).
        if (preg_match('/([+-])\s*(\d+(?:[.,]\d+)?)\s*(MM|M)\b/', $linha, $m, PREG_OFFSET_CAPTURE)) {
            $numero = $this->paraFloatAbreviado($m[2][0]);
            $mult   = $m[3][0] === 'MM' ? 1_000_000 : 1_000; // MM primeiro no cast: nunca inverter

            return [
                'tipo'   => $m[1][0] === '-' ? 'fecha' : 'abre',
                'limiar' => $numero * $mult,
                'fim'    => $m[0][1] + strlen($m[0][0]),
            ];
        }

        // Notação antiga POR EXTENSO: até/a partir de/acima de + número + mil|milhão|milhões.
        if (preg_match('/(Até|A\s*partir\s*de|Acima\s*de)\s+(\d+(?:[.,]\d+)?)\s*(mil|milh(?:ão|ões|ao))\b/iu', $linha, $m, PREG_OFFSET_CAPTURE)) {
            $numero = $this->paraFloatAbreviado($m[2][0]);
            $mult   = str_starts_with(mb_strtolower($m[3][0]), 'milh') ? 1_000_000 : 1_000;

            return [
                'tipo'   => mb_strtolower(trim($m[1][0])) === 'até' ? 'fecha' : 'abre',
                'limiar' => $numero * $mult,
                'fim'    => $m[0][1] + strlen($m[0][0]),
            ];
        }

        // Notação NOVA: até/a partir de/acima de + valor em R$ direto.
        if (preg_match('/(Até|A\s*partir\s*de|Acima\s*de)\s+R\$\s*([\d.]+,\d{2})/iu', $linha, $m, PREG_OFFSET_CAPTURE)) {
            return [
                'tipo'   => mb_strtolower(trim($m[1][0])) === 'até' ? 'fecha' : 'abre',
                'limiar' => $this->paraFloat($m[2][0]),
                'fim'    => $m[0][1] + strlen($m[0][0]),
            ];
        }

        return null;
    }

    /**
     * Converte a lista de marcos (na ordem do documento) em faixas no shape de
     * `EmpresaFaixaFaturamento`. Um marco `fecha` vira faixa com `limite_superior` no próprio
     * limiar; um marco `abre` vira faixa cujo `limite_superior` é o limiar do PRÓXIMO marco (ou
     * `null`, se não houver próximo). A ÚLTIMA faixa é sempre forçada a `limite_superior = null`
     * — regra de negócio explícita: tabela progressiva sempre termina aberta.
     *
     * @param  array<int, array{tipo: string, limiar: float, valor: float, valor_e_piso: bool}>  $marcos
     * @return array<int, array{ordem: int, limite_superior: ?float, valor: float, valor_e_piso: bool}>
     */
    private function marcosParaFaixas(array $marcos): array
    {
        $faixas = [];

        foreach (array_values($marcos) as $i => $marco) {
            $limiteSuperior = $marco['tipo'] === 'fecha'
                ? $marco['limiar']
                : ($marcos[$i + 1]['limiar'] ?? null);

            $faixas[] = [
                'ordem'           => $i + 1,
                'limite_superior' => $limiteSuperior,
                'valor'           => $marco['valor'],
                'valor_e_piso'    => $marco['valor_e_piso'],
            ];
        }

        if ($faixas !== []) {
            $faixas[count($faixas) - 1]['limite_superior'] = null;
        }

        return $faixas;
    }

    /**
     * Faixas fora de ordem ou com valor decrescente entram em `avisos`, sem serem descartadas em
     * silêncio — quem lê o relatório decide se confia ou vai conferir no contrato original.
     *
     * @param  array<int, array{ordem: int, limite_superior: ?float, valor: float, valor_e_piso: bool}>  $faixas
     * @return array<int, string>
     */
    private function avisarInconsistencias(array $faixas): array
    {
        $avisos = [];

        for ($i = 1; $i < count($faixas); $i++) {
            $anterior = $faixas[$i - 1];
            $atual    = $faixas[$i];

            if ($atual['valor'] < $anterior['valor']) {
                $avisos[] = "faixa {$atual['ordem']} tem valor menor que a faixa anterior — conferir manualmente";
            }

            if ($anterior['limite_superior'] !== null
                && $atual['limite_superior'] !== null
                && $atual['limite_superior'] < $anterior['limite_superior']) {
                $avisos[] = "faixa {$atual['ordem']} tem limite superior menor que a faixa anterior — conferir manualmente";
            }
        }

        return $avisos;
    }

    // ═══ CNPJ E RAZÃO SOCIAL ═══

    /**
     * Um contrato tem DOIS CNPJs: o da ECF (contratada) e o do cliente (contratante). Ignora o
     * CNPJ da ECF (`config('services.clicksign.cnpj_ecf')`) — sem essa config, degenera para "pega
     * o primeiro CNPJ que aparecer", que é a mesma regra ("primeiro que não for da ECF") quando não
     * há como saber qual é o da ECF. Registra aviso quando só existe um CNPJ no texto.
     *
     * @return array{0: ?string, 1: ?string, 2: array<int, string>} [cnpj, razao_social, avisos]
     */
    private function extrairCnpjERazaoSocial(string $texto): array
    {
        if (!preg_match_all('/\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}/', $texto, $matches, PREG_OFFSET_CAPTURE)) {
            return [null, null, []];
        }

        $ecfCnpjConfig  = config('services.clicksign.cnpj_ecf');
        $ecfNormalizado = $ecfCnpjConfig ? preg_replace('/\D/', '', (string) $ecfCnpjConfig) : null;

        // Deduplica por valor normalizado, mantendo a PRIMEIRA ocorrência (posição) de cada CNPJ
        // distinto — é essa posição que localiza a razão social na cláusula de qualificação.
        // ⚠️ Lista sequencial de propósito, NUNCA `$distintos[$normalizado] = ...`: chave de array
        // toda-dígitos é convertida para int pelo PHP, e um CNPJ normalizado É toda dígitos —
        // guardar como chave silenciosamente troca o tipo que o chamador espera (string).
        $distintos = [];
        $vistos    = [];
        foreach ($matches[0] as [$bruto, $offset]) {
            $normalizado = preg_replace('/\D/', '', $bruto);

            if (in_array($normalizado, $vistos, true)) {
                continue;
            }

            $vistos[]    = $normalizado;
            $distintos[] = ['cnpj' => $normalizado, 'offset' => $offset];
        }

        if (count($distintos) === 1) {
            $normalizado = $distintos[0]['cnpj'];
            $offset      = $distintos[0]['offset'];
            $aviso       = ['só um CNPJ encontrado no texto — não deu para confirmar contra o CNPJ da ECF'];

            if ($ecfNormalizado !== null && $normalizado === $ecfNormalizado) {
                return [null, null, $aviso];
            }

            return [$normalizado, $this->extrairRazaoSocial($texto, $offset), $aviso];
        }

        foreach ($distintos as $item) {
            if ($ecfNormalizado !== null && $item['cnpj'] === $ecfNormalizado) {
                continue;
            }

            return [$item['cnpj'], $this->extrairRazaoSocial($texto, $item['offset']), []];
        }

        // Todos os CNPJs distintos bateram com o da ECF (não deveria acontecer num contrato real)
        // — melhor devolver o primeiro do que devolver null quando existe candidato no texto.
        return [$distintos[0]['cnpj'], $this->extrairRazaoSocial($texto, $distintos[0]['offset']), []];
    }

    /**
     * Isola a razão social que precede a menção ao CNPJ na cláusula de qualificação, exigindo um
     * sufixo de pessoa jurídica reconhecido (LTDA, EIRELI, ME, EPP, S/A, S.A.) imediatamente antes
     * da palavra "CNPJ". Sem sufixo reconhecido por perto, devolve `null` — ⚠️ melhor null do que
     * um palpite que passa por leitura, porque este dado vira cadastro no plano 140-05.
     */
    private function extrairRazaoSocial(string $texto, int $offsetCnpj): ?string
    {
        $inicioJanela = max(0, $offsetCnpj - 200);
        $janela       = substr($texto, $inicioJanela, ($offsetCnpj - $inicioJanela) + 20);

        if (preg_match(
            '/([A-ZÀ-ÚÇ][A-ZÀ-ÚÇ0-9 ,.\-\/&]{1,100}?\b(?:LTDA\.?|EIRELI|ME|EPP|S\/A|S\.A\.?))\s*[,.]?\s*'
            . '(?:pessoa\s+jur[ií]dica[^,]{0,100},)?\s*(?:inscrit[ao]\s+no\s+)?CNPJ\b/u',
            $janela,
            $m
        )) {
            return trim(preg_replace('/\s+/', ' ', $m[1]), " ,.-");
        }

        return null;
    }

    // ═══ CONVERSÃO NUMÉRICA ═══

    /**
     * "3.000,00" / "500.000,00" (formato monetário brasileiro: ponto de milhar, vírgula decimal)
     * → float.
     */
    private function paraFloat(string $valorBr): float
    {
        return (float) str_replace(['.', ','], ['', '.'], $valorBr);
    }

    /**
     * Número que acompanha o sufixo abreviado (M/MM) ou por extenso (mil/milhão) — aqui não há
     * separador de milhar, só possivelmente um decimal com vírgula (ex.: "1,5MM").
     */
    private function paraFloatAbreviado(string $numero): float
    {
        return (float) str_replace(',', '.', $numero);
    }
}
