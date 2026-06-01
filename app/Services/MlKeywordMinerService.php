<?php

namespace App\Services;

/**
 * MlKeywordMinerService — mineração estatística de keywords de títulos de produtos ML.
 *
 * Implementado em PHP puro — sem intl/Normalizer (não disponível no XAMPP 8.2.12).
 * Usa mb_strtolower (mbstring, disponível) + strtr com ACCENT_MAP para normalização pt-BR.
 * Sem dependência de banco de dados, cache ou qualquer camada de infraestrutura Laravel.
 *
 * Fluxo principal:
 *   títulos → tokenizar() → ngrams() → rankingKeywords() → recomendacaoHeuristica()
 *
 * Nota D-05: A recomendação é 100% heurística (regras estáticas).
 * IA (Claude/Anthropic) está explicitamente fora do escopo desta fase (Fase 1 — sem IA).
 * A camada de IA é um Deferred Idea para a Fase 2.
 *
 * @see RESEARCH.md §Padrão 3 (mapa de acentos + tokenização)
 * @see RESEARCH.md §Exemplos (~120 stopwords pt-BR)
 * @see PATTERNS.md §MlKeywordMinerService (assinaturas exatas)
 */
class MlKeywordMinerService
{
    // ─── Mapa de acentos pt-BR ────────────────────────────────────────────────
    // Fonte: RESEARCH.md §Padrão 3 — cobre todos os caracteres acentuados do pt-BR
    // Inclui maiúsculas para cobrir casos onde mb_strtolower ainda não foi aplicado

    private const ACCENT_MAP = [
        // Minúsculas
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n',
        // Maiúsculas (convertidas antes do strtr via mb_strtolower, mas incluídas por segurança)
        'Á' => 'a', 'À' => 'a', 'Â' => 'a', 'Ã' => 'a', 'Ä' => 'a',
        'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
        'Í' => 'i', 'Ì' => 'i', 'Î' => 'i', 'Ï' => 'i',
        'Ó' => 'o', 'Ò' => 'o', 'Ô' => 'o', 'Õ' => 'o', 'Ö' => 'o',
        'Ú' => 'u', 'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u',
        'Ç' => 'c', 'Ñ' => 'n',
    ];

    // ─── Stopwords pt-BR (~120 palavras) ─────────────────────────────────────
    // Fonte: RESEARCH.md §Exemplos — compilação de listas canônicas pt-BR
    // + stopwords de domínio e-commerce que não agregam ao ranking de keywords
    // Nota: todas as entradas já estão normalizadas (sem acentos, lowercase)

    private const STOPWORDS_PT = [
        // Artigos
        'o', 'a', 'os', 'as', 'um', 'uma', 'uns', 'umas',
        // Preposições simples
        'de', 'do', 'da', 'dos', 'das', 'em', 'no', 'na', 'nos', 'nas',
        'por', 'para', 'com', 'sem', 'sob', 'ante', 'ate', 'apos',
        // Contrações comuns
        'pelo', 'pela', 'pelos', 'pelas', 'ao', 'aos', 'oa',
        // Conjunções
        'e', 'ou', 'mas', 'porem', 'contudo', 'todavia', 'entretanto',
        'que', 'se', 'porque', 'pois', 'como', 'quando', 'embora',
        // Pronomes
        'eu', 'tu', 'ele', 'ela', 'nos', 'vos', 'eles', 'elas',
        'me', 'te', 'lhe', 'si', 'meu', 'minha', 'seu', 'sua',
        'este', 'esta', 'esse', 'essa', 'aquele', 'aquela',
        'isto', 'isso', 'aquilo', 'tudo', 'nada', 'algo',
        // Advérbios de frequência / modo
        'nao', 'sim', 'so', 'ja', 'mais', 'menos', 'muito', 'pouco',
        'bem', 'mal', 'aqui', 'ali', 'la', 'ca', 'ainda', 'sempre',
        'nunca', 'talvez', 'quase', 'logo', 'depois', 'antes', 'agora',
        // Termos comuns em e-commerce que não agregam ao ranking (stopwords de domínio)
        'kit', 'novo', 'nova', 'original', 'oficial', 'unidade', 'peca',
        'item', 'produto', 'frete', 'gratis', 'desconto', 'oferta',
        'promocao', 'top', 'super', 'ultra', 'mega', 'pack', 'par',
        // Termos numéricos/técnicos genéricos que poluem o ranking
        'com', 'para', 'uma', 'ele', 'mhz', 'ghz', 'usb', 'led',
        'alta', 'alto', 'preto', 'branco', 'preto', 'cor',
    ];

    // ─── Normalização e tokenização ───────────────────────────────────────────

    /**
     * Normaliza um token: converte para lowercase UTF-8 e remove acentos pt-BR.
     *
     * Usa mb_strtolower (mbstring) + strtr com ACCENT_MAP em vez de intl/Normalizer
     * (não disponível no XAMPP 8.2.12 e possivelmente no VPS Hostinger).
     *
     * @param  string  $token  Token ou frase a normalizar
     * @return string          Token normalizado: lowercase, sem acentos
     */
    public function normalizarToken(string $token): string
    {
        $lower = mb_strtolower($token, 'UTF-8');
        return strtr($lower, self::ACCENT_MAP);
    }

    /**
     * Tokeniza um texto em pt-BR: normaliza, remove pontuação, divide por espaços,
     * filtra tokens com ≤2 caracteres e remove stopwords.
     *
     * @param  string  $texto  Título ou texto a tokenizar
     * @return array           Lista de tokens relevantes
     */
    public function tokenizar(string $texto): array
    {
        $normalizado = $this->normalizarToken($texto);

        // Remove pontuação e caracteres especiais, mantendo letras, números e espaços
        // Pitfall 4: títulos ML têm símbolos como |, -, /, (, ) etc.
        $limpo = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalizado);

        // Divide por um ou mais espaços; descarta tokens vazios
        $tokens = preg_split('/\s+/u', trim($limpo), -1, PREG_SPLIT_NO_EMPTY);

        // Filtra: remove tokens curtos (≤2 chars) e stopwords
        return array_values(array_filter($tokens, fn ($t) =>
            mb_strlen($t, 'UTF-8') > 2 && ! in_array($t, self::STOPWORDS_PT, true)
        ));
    }

    /**
     * Gera n-gramas a partir de um array de tokens.
     *
     * @param  array  $tokens  Tokens já tokenizados
     * @param  int    $n       Tamanho do n-grama (2 = bigrama, 3 = trigrama)
     * @return array           Lista de n-gramas como strings
     */
    public function ngrams(array $tokens, int $n): array
    {
        $result = [];
        $count  = count($tokens);

        for ($i = 0; $i <= $count - $n; $i++) {
            $result[] = implode(' ', array_slice($tokens, $i, $n));
        }

        return $result;
    }

    // ─── Ranking de frequência ────────────────────────────────────────────────

    /**
     * Gera o ranking de keywords a partir de uma lista de títulos.
     *
     * Combina unigramas + bigramas + trigramas de todos os títulos,
     * calcula frequência via array_count_values, ordena por frequência descendente
     * e retorna os top 30 com metadados (tipo, eh_tendencia).
     *
     * @param  array  $titulos  Lista de títulos de produtos (strings)
     * @param  array  $trends   Keywords tendência da API ML (para marcar eh_tendencia)
     * @return array            Lista de até 30 itens: [termo, frequencia, eh_tendencia, tipo]
     */
    public function rankingKeywords(array $titulos, array $trends = []): array
    {
        $todos = [];

        foreach ($titulos as $titulo) {
            $tokens = $this->tokenizar($titulo);

            // Merge de unigramas, bigramas e trigramas
            $todos = array_merge($todos, $tokens);
            $todos = array_merge($todos, $this->ngrams($tokens, 2));
            $todos = array_merge($todos, $this->ngrams($tokens, 3));
        }

        // Conta frequência e ordena: frequência decrescente; empate desfeito por ordem
        // alfabética crescente do termo — garante determinismo nos testes e no resultado final
        $freq = array_count_values($todos);

        // Constrói lista de pares [termo => frequência] e ordena:
        // 1° frequência decrescente, 2° alfabética crescente (desempate determinístico)
        $pares = [];
        foreach ($freq as $termo => $contagem) {
            $pares[] = ['termo' => $termo, 'frequencia' => $contagem];
        }
        usort($pares, function ($a, $b) {
            if ($b['frequencia'] !== $a['frequencia']) {
                return $b['frequencia'] <=> $a['frequencia']; // decrescente por frequência
            }
            return $a['termo'] <=> $b['termo']; // crescente alfabético no empate
        });

        // Monta o resultado com metadados (top 30)
        $resultado = [];
        foreach (array_slice($pares, 0, 30) as $par) {
            $palavras    = str_word_count($par['termo']);
            $resultado[] = [
                'termo'        => $par['termo'],
                'frequencia'   => $par['frequencia'],
                'eh_tendencia' => in_array($par['termo'], $trends, true),
                'tipo'         => match (true) {
                    $palavras === 1 => 'unigrama',
                    $palavras === 2 => 'bigrama',
                    default         => 'trigrama',
                },
            ];
        }

        return $resultado;
    }

    // ─── Agrupamento de perguntas ─────────────────────────────────────────────

    /**
     * Agrupa perguntas de compradores por tema (frequência de tokens).
     *
     * Tokeniza o texto de cada pergunta, conta frequência de termos
     * e retorna os top temas com exemplo de pergunta.
     *
     * @param  array  $perguntas  Lista de arrays com chave 'text' (texto da pergunta)
     * @return array              Top temas: [tema, frequencia, exemplo]
     */
    public function agruparPerguntas(array $perguntas): array
    {
        if (empty($perguntas)) {
            return [];
        }

        $freq    = [];
        $exemplos = [];

        foreach ($perguntas as $pergunta) {
            // Cada pergunta pode ser string direta ou array com chave 'text'
            $texto  = is_array($pergunta) ? ($pergunta['text'] ?? '') : (string) $pergunta;
            $tokens = $this->tokenizar($texto);

            foreach ($tokens as $token) {
                $freq[$token] = ($freq[$token] ?? 0) + 1;

                // Guarda o primeiro exemplo de pergunta que contém este token
                if (! isset($exemplos[$token])) {
                    $exemplos[$token] = $texto;
                }
            }
        }

        // Ordena por frequência e retorna os top 10 temas
        arsort($freq);

        $resultado = [];
        foreach (array_slice($freq, 0, 10, true) as $tema => $contagem) {
            $resultado[] = [
                'tema'       => $tema,
                'frequencia' => $contagem,
                'exemplo'    => $exemplos[$tema] ?? '',
            ];
        }

        return $resultado;
    }

    // ─── Recomendação heurística ──────────────────────────────────────────────

    /**
     * Gera uma recomendação heurística de título e palavras-chave prioritárias.
     *
     * Baseada exclusivamente em regras estáticas (D-05):
     *   - titulo_sugerido: keyword + top unigramas do ranking
     *   - palavras_top_incluir: top unigramas (frequência alta)
     *   - pontos_fortes_antecipar: temas das top dúvidas
     *   - aviso: disclaimer explícito de que é heurística (sem IA)
     *
     * IMPORTANTE: Este método NÃO chama nenhuma IA/LLM (Claude, OpenAI, etc.).
     * A camada de IA está diferida para a Fase 2 (Deferred Idea — D-05).
     *
     * @param  array   $ranking      Resultado de rankingKeywords()
     * @param  array   $topDuvidas   Resultado de agruparPerguntas()
     * @param  string  $keyword      Keyword original da busca
     * @return array                 [titulo_sugerido, palavras_top_incluir, pontos_fortes_antecipar, aviso]
     */
    public function recomendacaoHeuristica(array $ranking, array $topDuvidas, string $keyword): array
    {
        // Seleciona top unigramas do ranking para compor o título sugerido
        $unigramas = array_filter($ranking, fn ($item) => $item['tipo'] === 'unigrama');
        $unigramas = array_slice(array_values($unigramas), 0, 5);

        // Monta título sugerido: keyword + top unigramas (sem repetir a keyword)
        $keywordNormalizada = $this->normalizarToken($keyword);
        $termosTitulo       = [$keyword];

        foreach ($unigramas as $item) {
            $termoNorm = $this->normalizarToken($item['termo']);
            // Evita duplicar termos já presentes na keyword
            if (! str_contains($keywordNormalizada, $termoNorm)) {
                $termosTitulo[] = ucfirst($item['termo']);
            }
        }

        $tituloSugerido = implode(' ', array_unique($termosTitulo));

        // Palavras top a incluir nos primeiros 80 chars do título
        $palavrasTop = array_map(fn ($item) => $item['termo'], array_slice($unigramas, 0, 5));

        // Pontos fortes a antecipar (temas das top dúvidas dos compradores)
        $pontosFortes = array_map(fn ($d) => $d['tema'], array_slice($topDuvidas, 0, 3));

        return [
            'titulo_sugerido'        => $tituloSugerido,
            'palavras_top_incluir'   => $palavrasTop,
            'pontos_fortes_antecipar'=> $pontosFortes,
            // D-05: aviso explícito de que é heurística — sem IA nesta fase
            'aviso' => 'Recomendação heurística (regras) — análise qualitativa por IA disponível na Fase 2',
        ];
    }
}
