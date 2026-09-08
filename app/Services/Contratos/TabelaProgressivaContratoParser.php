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
 *
 * ═══ CORREÇÕES PÓS-RODADA REAL (140-02, 2026-09-08) ═══
 *
 * A rodada real contra a conta de produção (`clicksign:extrair-tabelas --limite=10`, executada
 * pelo 140-03) revelou dois defeitos que não apareciam nas fixtures fictícias originais:
 *
 * 1. **CNPJ/razão social vinham sempre da ECF, nunca do cliente.** A extração pegava o PRIMEIRO
 *    CNPJ do documento, mas a ORDEM entre ECF e cliente VARIA entre contratos — medido: DESK
 *    DESIGN (ECF primeiro) vs. ALUMEN (cliente primeiro). A correção lê pelo RÓTULO
 *    (CONTRATANTE/CONTRATADA), que aparece literalmente nos contratos, e não pela posição — ver
 *    `extrairCnpjERazaoSocial()` e `CNPJS_ECF_CONHECIDOS` (rede de segurança).
 * 2. **Contratos antigos (ago/2025) têm os dígitos apagados no PDF** — problema de fonte no
 *    documento, não do parser: o texto extrai com `R$  .   ,   ` (dígitos viraram espaço), mas o
 *    valor por extenso entre parênteses sobrevive. Decisão: marcar essas linhas com um tipo
 *    PRÓPRIO (`numeros_ilegiveis`), nunca deixá-las cair no genérico `indefinido` ("não deu para
 *    entender a cobrança") — ver `pareceNumerosIlegiveis()` e a justificativa no SUMMARY do plano
 *    140-02 sobre por que NÃO se tentou converter o valor por extenso em número.
 */
class TabelaProgressivaContratoParser
{
    /**
     * Mínimo de marcos reconhecidos para aceitar como tabela (D-04) — texto com só uma ou duas
     * linhas batendo no padrão vira `indefinido` com aviso, nunca uma tabela pela metade.
     */
    private const MINIMO_MARCOS_PARA_TABELA = 3;

    /**
     * CNPJs conhecidos da PRÓPRIA ECF — as duas pessoas jurídicas que assinam como CONTRATADA nos
     * modelos de contrato. Não é dado sensível: o `63.381.851/0001-41` já está literal e versionado
     * em `.planning/phases/126-.../126-VARIAVEIS-DO-MODELO.md` desde a Fase 126 (qualificação fixa
     * do modelo `.docx`) — é o CNPJ público da própria empresa, não segredo nem dado de cliente.
     *
     * Rede de segurança (correção pós-rodada real, 2026-09-08): se o CNPJ que a extração aponta
     * como "do cliente" bater com um destes, a leitura falhou — devolve `null` em vez do nosso
     * próprio CNPJ, porque este campo vira chave de casamento de cobrança (ver `ehCnpjDaEcf()`).
     * Configuração adicional (não substitui esta lista) pode vir de
     * `config('services.clicksign.cnpj_ecf')`, aceitando um ou mais CNPJs separados por vírgula.
     *
     * @var array<int, string>
     */
    private const CNPJS_ECF_CONHECIDOS = [
        '39783867000104', // ECF Comércio Treinamento e Desenvolvimento LTDA
        '63381851000141', // ECF Negócios Digitais LTDA
    ];

    private const REGEX_CNPJ = '/\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}/';

    /**
     * @return array{
     *     tipo: 'tabela'|'valor_fixo'|'indefinido'|'numeros_ilegiveis',
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

        // Correção pós-rodada real: contratos antigos (ago/2025) chegam com os dígitos apagados
        // pelo PDF ("R$  .   ,   ") — nem valor fixo nem tabela batem, porque os dois exigem
        // dígitos de verdade. Isso NÃO é "indefinido" (não é falta de sinal — é sinal presente e
        // ilegível), então ganha tipo próprio para não sair do relatório como se tivesse sido lido.
        if (count($marcos) === 0 && $this->pareceNumerosIlegiveis($texto)) {
            return [
                'tipo'         => 'numeros_ilegiveis',
                'faixas'       => [],
                'valor_fixo'   => null,
                'cnpj'         => $cnpj,
                'razao_social' => $razaoSocial,
                'avisos'       => array_merge($avisosCnpj, [
                    'os números deste contrato não são legíveis no arquivo (dígitos vieram como espaços) — precisa abrir o contrato à mão',
                ]),
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

    // ═══ NÚMEROS ILEGÍVEIS (dígitos apagados no PDF) ═══

    /**
     * Detecta a marca estrutural de um contrato onde os DÍGITOS de valores em reais viraram espaço
     * em branco no texto extraído — problema de fonte do PDF, não do parser (2026-09-08, contratos
     * `contrato_gestao_ads_meli_*` de ago/2025). Um valor válido SEMPRE tem dígitos entre "R$" e o
     * ponto de milhar e entre o ponto e a vírgula de centavos (ex.: "R$ 3.000,00"); esta regex só
     * casa quando esses dígitos estão AUSENTES (só espaço), o que nunca acontece num valor lido
     * corretamente — por isso é seguro contra falso positivo em texto normal.
     *
     * ⚠️ Decisão registrada no SUMMARY do plano 140-02: NÃO tentamos ler o valor por extenso
     * (ex.: "três mil reais") e convertê-lo em número. O projeto já tem conversão número→extenso em
     * `ContratoPdfService::quantidadeDeParcelasPorExtenso()`, mas o sentido INVERSO (extenso→número)
     * é onde um erro de interpretação vira valor de cobrança errado sem parecer errado — para os
     * poucos contratos afetados, marcar honestamente para conferência manual é mais seguro que
     * arriscar um número.
     */
    private function pareceNumerosIlegiveis(string $texto): bool
    {
        return preg_match('/R\$?\s*\.\s*,\s*/u', $texto) === 1;
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
     * Um contrato tem DOIS CNPJs: o da ECF (contratada) e o do cliente (contratante).
     *
     * ⚠️ Correção pós-rodada real (2026-09-08): a versão original desta função pegava "o primeiro
     * CNPJ que não é o da ECF configurada" — e a rodada real mostrou que a ORDEM entre ECF e
     * cliente VARIA entre contratos (DESK DESIGN: ECF primeiro; ALUMEN: cliente primeiro), então
     * "primeiro" acertava por acaso ou errava, e SEM `cnpj_ecf` configurado (o caso de produção)
     * sempre devolvia o primeiro CNPJ do documento — que na maioria dos contratos é o da própria
     * ECF, porque ela costuma abrir a qualificação. As 10 linhas da rodada real vieram todas com o
     * MESMO CNPJ (o nosso) por causa disso.
     *
     * A leitura agora é por RÓTULO: o CNPJ mais próximo da palavra "CONTRATANTE" no texto é o do
     * cliente — os contratos sempre trazem esse rótulo literal na cláusula de qualificação,
     * independente de qual pessoa jurídica vem primeiro. Só cai no fallback por eliminação (ver
     * `escolherCnpjDoCliente()`) quando nenhum rótulo é encontrado perto de nenhum CNPJ.
     *
     * Rede de segurança final: se o CNPJ escolhido (por rótulo OU por fallback) bater com um dos
     * `CNPJS_ECF_CONHECIDOS` (ou com `config('services.clicksign.cnpj_ecf')`), a leitura falhou —
     * devolve `null` em vez do CNPJ da própria ECF. Melhor sem dado do que com dado errado: este
     * campo vira chave de casamento de cobrança (ver `ehCnpjDaEcf()`).
     *
     * @return array{0: ?string, 1: ?string, 2: array<int, string>} [cnpj, razao_social, avisos]
     */
    private function extrairCnpjERazaoSocial(string $texto): array
    {
        if (!preg_match_all(self::REGEX_CNPJ, $texto, $matches, PREG_OFFSET_CAPTURE)) {
            return [null, null, []];
        }

        // Deduplica por valor normalizado, mantendo a PRIMEIRA ocorrência (posição) de cada CNPJ
        // distinto — é essa posição que localiza o rótulo e a razão social na cláusula de
        // qualificação. ⚠️ Lista sequencial de propósito, NUNCA `$distintos[$normalizado] = ...`:
        // chave de array toda-dígitos é convertida para `int` pelo PHP, e um CNPJ normalizado É
        // toda dígitos — guardar como chave silenciosamente troca o tipo que o chamador espera.
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

        $avisos = [];
        if (count($distintos) === 1) {
            $avisos[] = 'só um CNPJ encontrado no texto — não deu para confirmar contra o CNPJ da ECF';
        }

        $cnpjParaRotulo = $this->mapearCnpjParaRotulo($texto, $distintos);
        $candidato      = $this->escolherCnpjDoCliente($distintos, $cnpjParaRotulo, $avisos);

        if ($candidato === null) {
            return [null, null, $avisos];
        }

        if ($this->ehCnpjDaEcf($candidato['cnpj'])) {
            $avisos[] = 'o CNPJ identificado é da própria ECF, não do cliente — descartado (rede de segurança)';

            return [null, null, $avisos];
        }

        return [$candidato['cnpj'], $this->extrairRazaoSocial($texto, $candidato['offset']), $avisos];
    }

    /**
     * Associa cada CNPJ distinto ao rótulo ("contratante"/"contratada") que o qualifica, usando os
     * DOIS jeitos como contratos reais escrevem a cláusula de qualificação:
     *
     * 1. **Rótulo ANTES, com dois-pontos**: `"CONTRATANTE: NOME LTDA, ..., CNPJ X"` — busca o CNPJ
     *    mais próximo À FRENTE do rótulo (nunca um CNPJ que vem antes dele no texto).
     * 2. **Rótulo DEPOIS, sem dois-pontos**: `"NOME LTDA, CNPJ X, ..., doravante denominada
     *    CONTRATANTE"` — busca o CNPJ mais próximo ATRÁS do rótulo.
     *
     * ⚠️ Por que direção importa (bug corrigido em 2026-09-08, pós-rodada real): usar "rótulo mais
     * próximo por distância, em qualquer direção" errava quando um CNPJ ficava entre dois rótulos
     * de cláusulas VIZINHAS (`"CONTRATADA: ..., CNPJ da ECF.\nCONTRATANTE: ..."` — o CNPJ da ECF,
     * por estar no fim da própria linha, acabava mais PERTO do "CONTRATANTE" da linha seguinte do
     * que do "CONTRATADA" que na verdade o qualifica). Buscar só NA DIREÇÃO que o padrão de
     * qualificação realmente usa (à frente para o rótulo com dois-pontos) elimina essa ambiguidade.
     *
     * @param  array<int, array{cnpj: string, offset: int}>  $distintos
     * @return array<int, 'contratante'|'contratada'> chave = offset do CNPJ (do array $distintos)
     */
    private function mapearCnpjParaRotulo(string $texto, array $distintos): array
    {
        $porOffset = [];

        if (preg_match_all('/(CONTRATANTE|CONTRATADA)\s*:/iu', $texto, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as [$rotuloTexto, $rotuloOffset]) {
                $cnpj = $this->cnpjMaisProximoNaDirecao($distintos, $rotuloOffset, 'frente');

                if ($cnpj !== null) {
                    $porOffset[$cnpj['offset']] = mb_strtolower($rotuloTexto);
                }
            }
        }

        if (preg_match_all('/denominad[ao]\s+(CONTRATANTE|CONTRATADA)/iu', $texto, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as [$rotuloTexto, $rotuloOffset]) {
                $cnpj = $this->cnpjMaisProximoNaDirecao($distintos, $rotuloOffset, 'tras');

                // Padrão 1 (dois-pontos) tem precedência se os dois já resolveram o mesmo CNPJ.
                if ($cnpj !== null && !isset($porOffset[$cnpj['offset']])) {
                    $porOffset[$cnpj['offset']] = mb_strtolower($rotuloTexto);
                }
            }
        }

        return $porOffset;
    }

    /**
     * CNPJ distinto mais próximo de um rótulo, só na direção pedida (`'frente'`: CNPJ com offset
     * maior que o do rótulo; `'tras'`: offset menor), dentro de uma janela máxima — evita cruzar
     * para o CNPJ de uma cláusula vizinha.
     *
     * @param  array<int, array{cnpj: string, offset: int}>  $distintos
     * @return array{cnpj: string, offset: int}|null
     */
    private function cnpjMaisProximoNaDirecao(array $distintos, int $offsetRotulo, string $direcao, int $janelaMaxima = 400): ?array
    {
        $melhor          = null;
        $melhorDistancia = null;

        foreach ($distintos as $item) {
            if ($direcao === 'frente' && $item['offset'] < $offsetRotulo) {
                continue;
            }

            if ($direcao === 'tras' && $item['offset'] > $offsetRotulo) {
                continue;
            }

            $distancia = abs($item['offset'] - $offsetRotulo);

            if ($distancia > $janelaMaxima) {
                continue;
            }

            if ($melhorDistancia === null || $distancia < $melhorDistancia) {
                $melhorDistancia = $distancia;
                $melhor          = $item;
            }
        }

        return $melhor;
    }

    /**
     * Escolhe o CNPJ do cliente dentre os distintos encontrados no texto:
     *
     * 1. Primeiro CNPJ (na ordem em que aparece no documento) mapeado para o rótulo "contratante"
     *    por `mapearCnpjParaRotulo()` — caminho principal, funciona com ECF ou cliente em qualquer
     *    ordem no documento.
     * 2. Sem nenhum rótulo reconhecível: fallback por eliminação (o comportamento antigo) —
     *    primeiro CNPJ que não é um CNPJ conhecido da ECF, com aviso de confiança menor.
     * 3. Nenhum dos dois: devolve o primeiro CNPJ distinto mesmo assim, deixando a rede de
     *    segurança do chamador (`ehCnpjDaEcf()` em `extrairCnpjERazaoSocial()`) decidir se descarta.
     *
     * @param  array<int, array{cnpj: string, offset: int}>  $distintos
     * @param  array<int, string>  $cnpjParaRotulo  chave = offset do CNPJ
     * @param  array<int, string>  $avisos
     * @return array{cnpj: string, offset: int}|null
     */
    private function escolherCnpjDoCliente(array $distintos, array $cnpjParaRotulo, array &$avisos): ?array
    {
        if ($distintos === []) {
            return null;
        }

        foreach ($distintos as $item) {
            if (($cnpjParaRotulo[$item['offset']] ?? null) === 'contratante') {
                return $item;
            }
        }

        foreach ($distintos as $item) {
            if (!$this->ehCnpjDaEcf($item['cnpj'])) {
                $avisos[] = 'CNPJ do cliente identificado por eliminação — nenhum rótulo "CONTRATANTE" foi encontrado perto de nenhum CNPJ neste texto — conferir manualmente';

                return $item;
            }
        }

        return $distintos[0];
    }

    /**
     * `true` quando o CNPJ normalizado é um dos CNPJs conhecidos da PRÓPRIA ECF —
     * `CNPJS_ECF_CONHECIDOS` (hardcoded, precedente já versionado desde a Fase 126) mais qualquer
     * CNPJ adicional em `config('services.clicksign.cnpj_ecf')` (aceita lista separada por vírgula,
     * para adicionar uma terceira pessoa jurídica sem alterar código).
     */
    private function ehCnpjDaEcf(string $cnpjNormalizado): bool
    {
        if (in_array($cnpjNormalizado, self::CNPJS_ECF_CONHECIDOS, true)) {
            return true;
        }

        $configurado = config('services.clicksign.cnpj_ecf');

        if (!$configurado) {
            return false;
        }

        foreach (explode(',', (string) $configurado) as $cnpjConfigurado) {
            if (preg_replace('/\D/', '', trim($cnpjConfigurado)) === $cnpjNormalizado) {
                return true;
            }
        }

        return false;
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
