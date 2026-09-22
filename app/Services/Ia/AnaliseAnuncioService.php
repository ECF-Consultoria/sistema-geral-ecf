<?php

namespace App\Services\Ia;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Metodologia MAG T8 em TRÊS chamadas curtas: Análise, Títulos, Descrição.
 *
 * POR QUE TRÊS E NÃO UMA. A primeira versão pedia tudo num JSON só e morria:
 * medido em produção em 21/09/2026, o provedor devolvia 503 "Service
 * temporarily overloaded" no prompt inteiro, enquanto a MESMA conta respondia
 * os títulos sozinhos em 30 segundos. Saída curta é o que esse tier aguenta.
 *
 * Ganho além de funcionar: cada parte é salva assim que fica pronta, então o
 * publicador vê a análise aparecer enquanto os títulos ainda estão saindo — e
 * se a descrição falhar, ele não perde as duas anteriores.
 *
 * Os prompts são a metodologia da ECF, não invenção nossa. Não "melhorar" sem
 * combinar: é o resultado que a equipe valida no chat hoje.
 */
class AnaliseAnuncioService
{
    /** Erros que valem retentar: sobrecarga e falha transitória de gateway. */
    private const HTTP_RETENTAVEIS = [408, 429, 500, 502, 503, 504];

    // ═══ As três etapas ═══════════════════════════════════════════════════════

    /** Etapa 1 — Análise Estratégica (os 9 passos). */
    public function analise(string $produto, string $loja, string $specs): array
    {
        $r = $this->chamar($this->promptAnalise($produto, $loja, $specs), 6000);

        return [
            'dados' => (array) ($r['json']['analise'] ?? $r['json']),
            'meta'  => $r['meta'],
        ];
    }

    /** Etapa 2 — Títulos sob o ruleset ECF. */
    public function titulos(string $produto, string $loja, string $specs, array $analise): array
    {
        $r = $this->chamar($this->promptTitulos($produto, $loja, $specs, $analise), 2500);

        return [
            'dados' => $this->normalizarTitulos($r['json']['titulos'] ?? [], $loja, $produto),
            'meta'  => $r['meta'],
        ];
    }

    /** Etapa 3 — Descrição do anúncio. */
    public function descricao(string $produto, string $loja, string $specs, array $analise): array
    {
        $r = $this->chamar($this->promptDescricao($produto, $loja, $specs, $analise), 3000);

        return [
            'dados' => trim((string) ($r['json']['descricao'] ?? '')),
            'meta'  => $r['meta'],
        ];
    }

    // ═══ Chamada ao provedor ══════════════════════════════════════════════════

    /**
     * Uma chamada, um JSON de volta.
     *
     * `max_tokens` por etapa em vez de um teto único: modelo de raciocínio
     * gasta orçamento "pensando" antes de escrever, e teto apertado devolve
     * HTTP 200 com conteúdo VAZIO — sintoma que confunde quem depura.
     *
     * @return array{json: array, meta: array}
     *
     * @throws \RuntimeException
     */
    private function chamar(string $prompt, int $maxTokens): array
    {
        $cfg = config('services.llm');

        if (empty($cfg['base_url'])) {
            throw new \RuntimeException('[IA] LLM_BASE_URL não configurado.');
        }

        $t0 = microtime(true);

        $resposta = Http::withToken((string) $cfg['key'])
            ->timeout((int) $cfg['timeout'])
            // Conectar é rápido ou não é. Separar do tempo de geração evita
            // esperar o timeout inteiro por um endpoint que está fora do ar.
            ->connectTimeout(15)
            ->retry(3, 5000, function ($exception) {
                $status = method_exists($exception, 'response') ? $exception->response?->status() : null;

                return $status === null || in_array($status, self::HTTP_RETENTAVEIS, true);
            }, throw: false)
            ->post(rtrim((string) $cfg['base_url'], '/') . '/chat/completions', [
                'model'       => $cfg['model'],
                'temperature' => 0.7,
                'max_tokens'  => $maxTokens,
                'messages'    => [['role' => 'user', 'content' => $prompt]],
            ]);

        $duracaoMs = (int) round((microtime(true) - $t0) * 1000);

        if (! $resposta->successful()) {
            $corpo = mb_substr($resposta->body(), 0, 400);
            Log::warning('[IA] Falha do provedor', [
                'status' => $resposta->status(),
                'modelo' => $cfg['model'],
                'corpo'  => $corpo,
            ]);

            throw new \RuntimeException($this->mensagemAmigavel($resposta->status(), $corpo));
        }

        $json     = $resposta->json();
        $conteudo = (string) data_get($json, 'choices.0.message.content', '');

        if (trim($conteudo) === '') {
            $pensou = mb_strlen((string) data_get($json, 'choices.0.message.reasoning_content', ''));

            throw new \RuntimeException(
                $pensou > 0
                    ? 'A IA gastou todo o orçamento de tokens raciocinando e não chegou a responder. Aumente LLM_MAX_TOKENS ou troque para um modelo sem raciocínio longo.'
                    : 'A IA respondeu vazio. Tente novamente em alguns minutos.'
            );
        }

        $dados = $this->extrairJson($conteudo);

        if ($dados === null) {
            Log::warning('[IA] Resposta não era JSON válido', ['inicio' => mb_substr($conteudo, 0, 300)]);

            throw new \RuntimeException('A IA não devolveu um JSON válido. Tente gerar novamente.');
        }

        return [
            'json' => $dados,
            'meta' => [
                // O modelo que RESPONDEU pode não ser o pedido: combo com
                // fallback troca por baixo. Registrar o real.
                'modelo'         => (string) ($json['model'] ?? $cfg['model']),
                'tokens_entrada' => (int) data_get($json, 'usage.prompt_tokens', 0),
                'tokens_saida'   => (int) data_get($json, 'usage.completion_tokens', 0),
                'duracao_ms'     => $duracaoMs,
            ],
        ];
    }

    // ═══ Prompts (metodologia MAG T8) ═════════════════════════════════════════

    private function blocoSpecs(string $specs): string
    {
        return trim($specs) !== ''
            ? "\n\n**Especificações Técnicas Reais do Produto (baseie-se nestes dados, não invente):**\n{$specs}"
            : '';
    }

    private function promptAnalise(string $produto, string $loja, string $specs): string
    {
        $bloco = $this->blocoSpecs($specs);

        return <<<TXT
        Para o produto **{$produto}**, gere a análise estratégica de um anúncio de alta conversão no Mercado Livre.

        Produto: **{$produto}**
        Nome da Empresa/Vendedor: **{$loja}**{$bloco}

        **Parte 1: Análise Estratégica**

        Passo 1: A Persona (Quem é o Cliente?): perfil detalhado do comprador ideal (demografia, interesses, dores, necessidades).
        Passo 2: O Mapa de Empatia (O que Pensa e Sente?): frustrações (dores) e desejos (ganhos) da persona.
        Passo 3: A Jornada de Compra: etapas de descoberta, consideração e decisão no Mercado Livre.
        Passo 4: Os Gatilhos Mentais: os 3 mais eficazes (Prova Social, Escassez, Autoridade).
        Passo 5: O "Trabalho a Ser Feito" (JTBD): a missão fundamental que o cliente quer realizar.
        Passo 6: A Proposta Única de Valor (PUV): em uma frase, por que escolher este produto e não outro.
        Passo 7: Funcionalidades-Chave: 3 a 5 que resolvem os problemas do cliente.
        Passo 8: O Diferencial Competitivo (Fator "Uau"): por que é superior às alternativas.
        Passo 9: A Prova Social: evidências de que o produto funciona.

        Responda APENAS com JSON válido, sem crases, sem texto antes ou depois. Seja direto: cada campo em no máximo 3 frases.
        {"analise":{"persona":"...","mapa_empatia":"...","jornada":"...","gatilhos":["...","...","..."],"jtbd":"...","puv":"...","funcionalidades":["..."],"diferencial":"...","prova_social":"..."}}
        TXT;
    }

    private function promptTitulos(string $produto, string $loja, string $specs, array $analise): string
    {
        $bloco = $this->blocoSpecs($specs);
        $puv   = (string) ($analise['puv'] ?? '');
        $ctx   = $puv !== '' ? "\n\nProposta de valor definida na análise: {$puv}" : '';

        return <<<TXT
        Gere títulos para o anúncio do produto **{$produto}** no Mercado Livre.{$bloco}{$ctx}

        REGRAS DOS TÍTULOS (ruleset ECF, obrigatório em todas as variações):
        1. SEM PREPOSIÇÕES: proibido usar de, para, com, do, da, e, em.
        2. SEM CORES no título.
        3. PRODUTO/FUNÇÃO PRIMEIRO: o título começa pelo produto ou sua função.
        4. NUNCA inclua o nome da loja/vendedor ("{$loja}") no título — nem no
           começo, nem no meio, nem no fim. O título é do PRODUTO, não da loja.
           Se existir MARCA DO PRODUTO (fabricante), ela vai no final; a loja
           não é marca do produto e fica de fora.
        5. SEM CARACTERES ESPECIAIS: sem parênteses, traços, aspas ou pontuação.
        6. ENTRE 58 E 60 CARACTERES — conte de verdade, caractere por caractere.
           Preencha os 58-60 caracteres com termos de busca reais do produto
           (material, medida, capacidade, uso), nunca com o nome da loja.

        Gere de 3 a 5 variações, cada uma com combinação DIFERENTE de termos.
        PROIBIDO variações que são apenas reordenações das mesmas palavras.

        Responda APENAS com JSON válido, sem crases:
        {"titulos":[{"texto":"..."}]}
        TXT;
    }

    private function promptDescricao(string $produto, string $loja, string $specs, array $analise): string
    {
        $bloco = $this->blocoSpecs($specs);
        $jtbd  = (string) ($analise['jtbd'] ?? '');
        $puv   = (string) ($analise['puv'] ?? '');

        $ctx = '';
        if ($jtbd !== '') { $ctx .= "\n\nJTBD (use no parágrafo do problema): {$jtbd}"; }
        if ($puv !== '')  { $ctx .= "\nPUV (use no parágrafo da solução): {$puv}"; }

        return <<<TXT
        Escreva a descrição do anúncio do produto **{$produto}** no Mercado Livre.

        Nome da Empresa: **{$loja}**{$bloco}{$ctx}

        ESTRUTURA OBRIGATÓRIA:
        - Saudação amigável citando "{$loja}".
        - Parágrafo 1 (Problema): reconheça a necessidade do cliente de forma empática, usando o JTBD.
        - Parágrafo 2 (Solução): apresente o produto como solução ideal, destacando a PUV.
        - Parágrafo 3 (Oferta): chamada para ação clara e direta.
        - Lista de 3 a 5 especificações técnicas mais importantes, limpa e direta.
        - Despedida cordial.

        Responda APENAS com JSON válido, sem crases. Quebras de linha dentro do texto como \\n:
        {"descricao":"..."}
        TXT;
    }

    // ═══ Saída ════════════════════════════════════════════════════════════════

    /**
     * Extrai o JSON mesmo quando o modelo embrulha em crases ou escreve algo
     * antes/depois. Salvar a resposta é mais barato que mandar o publicador
     * esperar outra chamada inteira.
     */
    private function extrairJson(string $conteudo): ?array
    {
        $limpo = trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($conteudo)));

        $dados = json_decode($limpo, true);
        if (is_array($dados)) {
            return $dados;
        }

        $ini = strpos($limpo, '{');
        $fim = strrpos($limpo, '}');

        if ($ini === false || $fim === false || $fim <= $ini) {
            return null;
        }

        $dados = json_decode(substr($limpo, $ini, $fim - $ini + 1), true);

        return is_array($dados) ? $dados : null;
    }

    /**
     * Confere o ruleset ECF no servidor. Nada é aceito do modelo: ele erra a
     * contagem de caracteres e ignora regras quando o título fica curto.
     */
    private function normalizarTitulos(mixed $titulos, string $loja, string $produto): array
    {
        return collect(is_array($titulos) ? $titulos : [])
            ->map(function ($t) use ($loja, $produto) {
                $texto = trim((string) (is_array($t) ? ($t['texto'] ?? '') : $t));
                $n     = mb_strlen($texto);

                $temPreposicao = (bool) preg_match('/\b(de|para|com|do|da|e|em)\b/iu', $texto);
                $temLoja       = $this->mencionaLoja($texto, $loja, $produto);

                return [
                    'texto'           => $texto,
                    'caracteres'      => $n,
                    'tem_loja'        => $temLoja,
                    'dentro_da_regra' => $n >= 58 && $n <= 60 && ! $temPreposicao && ! $temLoja,
                ];
            })
            ->filter(fn ($t) => $t['texto'] !== '')
            ->values()
            ->all();
    }

    /**
     * O título carrega o nome da loja?
     *
     * O modelo confunde "marca no final" (que fala da marca do PRODUTO) com o
     * nome do vendedor e enfia a loja no fim para fechar os 58-60 caracteres,
     * queimando espaço que deveria ser termo de busca.
     *
     * Casa por nome completo e por palavra isolada — mas só quando a palavra
     * NÃO aparece no nome do produto. Sem essa ressalva, uma loja "Cadeiras
     * Brasil" reprovaria todo título de cadeira.
     */
    private function mencionaLoja(string $titulo, string $loja, string $produto): bool
    {
        $loja = trim($loja);

        if ($loja === '') {
            return false;
        }

        $t = $this->semAcento($titulo);
        $p = $this->semAcento($produto);

        if (str_contains($t, $this->semAcento($loja))) {
            return true;
        }

        foreach (preg_split('/\s+/', $this->semAcento($loja)) as $palavra) {
            if (mb_strlen($palavra) < 4 || str_contains($p, $palavra)) {
                continue;
            }

            if (preg_match('/\b' . preg_quote($palavra, '/') . '\b/u', $t)) {
                return true;
            }
        }

        return false;
    }

    /** Minúsculas sem acento — o modelo escreve "Moveis" e a loja é "Móveis". */
    private function semAcento(string $s): string
    {
        return strtr(mb_strtolower(trim($s)), [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e',
            'í' => 'i', 'î' => 'i', 'ì' => 'i', 'ï' => 'i',
            'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ò' => 'o', 'ö' => 'o',
            'ú' => 'u', 'û' => 'u', 'ù' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);
    }

    /** Traduz o erro do provedor para algo que o publicador entenda. */
    private function mensagemAmigavel(int $status, string $corpo): string
    {
        $c = strtolower($corpo);

        if ($status === 503 || str_contains($c, 'overloaded')) {
            return 'O provedor de IA está sobrecarregado neste momento. Tente novamente em alguns minutos.';
        }

        if ($status === 401 || $status === 403) {
            return 'A chave de API da IA foi recusada. Confira LLM_API_KEY.';
        }

        if ($status === 404 || str_contains($c, 'unsupported model')) {
            return 'O modelo configurado não existe ou saiu do ar. Confira LLM_MODEL.';
        }

        if ($status === 410) {
            return 'O modelo configurado chegou ao fim de vida no provedor. Escolha outro em LLM_MODEL.';
        }

        return "A IA respondeu com erro {$status}. Tente novamente.";
    }
}
