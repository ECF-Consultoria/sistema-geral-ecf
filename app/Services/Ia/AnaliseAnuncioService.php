<?php

namespace App\Services\Ia;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gera a Análise Estratégica da metodologia MAG T8 (Parte 1) e, a partir dela,
 * os campos que o wizard precisa: títulos e descrição.
 *
 * O prompt é a Parte 1 do painel do usuário, palavra por palavra — os 9 passos
 * da metodologia da ECF. Não "melhoramos" o prompt: ele é o produto, e mexer
 * nele muda o resultado que a equipe já valida hoje no chat.
 *
 * REALIDADE MEDIDA do provedor (21/09/2026, `oc/muse-spark-1.3` via 9router):
 *  - 103s para uma análise completa; 1.095 tokens de entrada, 8.797 de saída
 *  - por isso isto SEMPRE roda em Job, nunca no request
 *  - 503 "Service temporarily overloaded" é comum em tier gratuito → retry
 *  - modelo de raciocínio devolve HTTP 200 com conteúdo VAZIO se `max_tokens`
 *    for curto: ele gasta o orçamento "pensando". Daí o teto alto.
 */
class AnaliseAnuncioService
{
    /** Erros que valem retentar: sobrecarga e falha transitória de gateway. */
    private const HTTP_RETENTAVEIS = [408, 429, 500, 502, 503, 504];

    /**
     * @param  string  $produto  Nome do produto (digitado pelo publicador)
     * @param  string  $loja     Nome da empresa/loja (vem da conta ML)
     * @param  string  $specs    Especificações reais, texto livre
     *
     * @return array{analise: array, titulos: array, descricao: string, _meta: array}
     *
     * @throws \RuntimeException quando o provedor falha ou devolve algo inaproveitável
     */
    public function gerar(string $produto, string $loja, string $specs): array
    {
        $cfg = config('services.llm');

        if (empty($cfg['base_url'])) {
            throw new \RuntimeException('[IA] LLM_BASE_URL não configurado.');
        }

        $t0 = microtime(true);

        $resposta = Http::withToken((string) $cfg['key'])
            ->timeout((int) $cfg['timeout'])
            // Conectar é rápido ou não é: separar isso do tempo de geração evita
            // esperar 300s por um endpoint que está fora do ar.
            ->connectTimeout(15)
            ->retry(3, 5000, function ($exception, $request) {
                $status = method_exists($exception, 'response') ? $exception->response?->status() : null;

                return $status === null || in_array($status, self::HTTP_RETENTAVEIS, true);
            }, throw: false)
            ->post(rtrim((string) $cfg['base_url'], '/') . '/chat/completions', [
                'model'       => $cfg['model'],
                'temperature' => 0.7,
                'max_tokens'  => (int) $cfg['max_tokens'],
                'messages'    => [[
                    'role'    => 'user',
                    'content' => $this->prompt($produto, $loja, $specs),
                ]],
            ]);

        $duracaoMs = (int) round((microtime(true) - $t0) * 1000);

        if (! $resposta->successful()) {
            $corpo = mb_substr($resposta->body(), 0, 400);
            Log::warning('[IA] Falha do provedor ao gerar análise', [
                'status'  => $resposta->status(),
                'modelo'  => $cfg['model'],
                'produto' => $produto,
                'corpo'   => $corpo,
            ]);

            throw new \RuntimeException($this->mensagemAmigavel($resposta->status(), $corpo));
        }

        $json    = $resposta->json();
        $conteudo = (string) data_get($json, 'choices.0.message.content', '');

        // HTTP 200 com conteúdo vazio é o sintoma clássico de modelo de
        // raciocínio que gastou todo o `max_tokens` antes de escrever a
        // resposta. Reportar como erro claro, não como "resultado vazio".
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
            Log::warning('[IA] Resposta não era JSON válido', [
                'modelo'  => $cfg['model'],
                'inicio'  => mb_substr($conteudo, 0, 300),
            ]);

            throw new \RuntimeException('A IA não devolveu um JSON válido. Tente gerar novamente.');
        }

        return [
            'analise'   => (array) ($dados['analise'] ?? []),
            'titulos'   => $this->normalizarTitulos($dados['titulos'] ?? []),
            'descricao' => trim((string) ($dados['descricao'] ?? '')),
            '_meta'     => [
                // O modelo que RESPONDEU pode não ser o pedido: combo com
                // fallback troca por baixo. Registrar o real, não o solicitado.
                'modelo'         => (string) ($json['model'] ?? $cfg['model']),
                'tokens_entrada' => (int) data_get($json, 'usage.prompt_tokens', 0),
                'tokens_saida'   => (int) data_get($json, 'usage.completion_tokens', 0),
                'duracao_ms'     => $duracaoMs,
            ],
        ];
    }

    // ═══ Prompt ═══════════════════════════════════════════════════════════════

    /**
     * Parte 1 da metodologia MAG T8 — os 9 passos, como no painel da ECF —
     * seguida do contrato de saída.
     */
    private function prompt(string $produto, string $loja, string $specs): string
    {
        $blocoSpecs = trim($specs) !== ''
            ? "\n\n**Especificações Técnicas Reais do Produto (baseie a análise nestes dados, não invente):**\n{$specs}"
            : '';

        return <<<TXT
        Para o produto **{$produto}**, gere um plano de marketing completo para um anúncio de alta conversão no Mercado Livre.

        Produto a ser Anunciado: **{$produto}**
        Nome da Empresa/Vendedor: **{$loja}**{$blocoSpecs}

        **Parte 1: Análise Estratégica**

        Passo 1: A Persona (Quem é o Cliente?): Descreva o perfil detalhado do comprador ideal (demografia, interesses, dores, necessidades).
        Passo 2: O Mapa de Empatia (O que Pensa e Sente?): Detalhe as frustrações (dores) e desejos (ganhos) da persona.
        Passo 3: A Jornada de Compra (Qual Caminho Percorre?): Mapeie as etapas de descoberta, consideração e decisão no Mercado Livre.
        Passo 4: Os Gatilhos Mentais (O que Leva à Compra?): Identifique os 3 gatilhos mentais mais eficazes (Prova Social, Escassez, Autoridade).
        Passo 5: O "Trabalho a Ser Feito" (JTBD): Qual é a "missão" fundamental que o cliente quer realizar com este produto?
        Passo 6: A Proposta Única de Valor (PUV): Em uma frase, responda: "Por que eu deveria escolher o seu produto e não outro?"
        Passo 7: Funcionalidades-Chave que Entregam Valor: Liste de 3 a 5 funcionalidades que resolvem os problemas do cliente.
        Passo 8: O Diferencial Competitivo (O Fator "Uau!"): Destaque o principal motivo pelo qual seu produto é superior às alternativas.
        Passo 9: A Prova Social e os Resultados (A Evidência): Reúna provas (depoimentos, dados) de que seu produto funciona.

        ---
        FORMATO DE RESPOSTA — responda APENAS com um JSON válido, sem crases, sem texto antes ou depois:

        {
          "analise": {
            "persona": "...", "mapa_empatia": "...", "jornada": "...",
            "gatilhos": ["...", "...", "..."], "jtbd": "...", "puv": "...",
            "funcionalidades": ["..."], "diferencial": "...", "prova_social": "..."
          },
          "titulos": [{"texto": "..."}],
          "descricao": "..."
        }

        REGRAS DOS TÍTULOS (ruleset ECF, obrigatório em todas as variações):
        1. SEM PREPOSIÇÕES: proibido usar de, para, com, do, da, e, em.
        2. SEM CORES no título.
        3. PRODUTO/FUNÇÃO PRIMEIRO: o título começa pelo produto ou sua função.
        4. MARCA NO FINAL, nunca no início.
        5. SEM CARACTERES ESPECIAIS: sem parênteses, traços, aspas ou pontuação.
        6. ENTRE 58 E 60 CARACTERES — conte de verdade, caractere por caractere.
        Gere de 3 a 5 variações, cada uma com combinação DIFERENTE de termos.
        PROIBIDO gerar variações que são apenas reordenações das mesmas palavras.

        DESCRIÇÃO: saudação citando "{$loja}"; três parágrafos curtos (problema, usando o JTBD; solução, usando a PUV; oferta com chamada para ação); lista de 3 a 5 especificações técnicas; despedida cordial.
        TXT;
    }

    // ═══ Saída ════════════════════════════════════════════════════════════════

    /**
     * Extrai o JSON mesmo quando o modelo desobedece e embrulha em crases ou
     * escreve algo antes/depois. Tentar salvar a resposta é mais barato que
     * mandar o publicador esperar outros 100 segundos.
     */
    private function extrairJson(string $conteudo): ?array
    {
        $limpo = trim($conteudo);
        $limpo = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $limpo);

        $dados = json_decode(trim((string) $limpo), true);
        if (is_array($dados)) {
            return $dados;
        }

        // Último recurso: recortar do primeiro "{" até o último "}".
        $ini = strpos($limpo, '{');
        $fim = strrpos($limpo, '}');

        if ($ini === false || $fim === false || $fim <= $ini) {
            return null;
        }

        $dados = json_decode(substr($limpo, $ini, $fim - $ini + 1), true);

        return is_array($dados) ? $dados : null;
    }

    /**
     * Normaliza os títulos e mede o que o modelo prometeu.
     *
     * A contagem de caracteres é feita AQUI, nunca aceita do modelo: ele erra
     * a conta com frequência. `dentro_da_regra` deixa a tela mostrar a verdade
     * em vez de fingir que todo título serve.
     */
    private function normalizarTitulos(mixed $titulos): array
    {
        return collect(is_array($titulos) ? $titulos : [])
            ->map(function ($t) {
                $texto = trim((string) (is_array($t) ? ($t['texto'] ?? '') : $t));
                $n     = mb_strlen($texto);

                return [
                    'texto'           => $texto,
                    'caracteres'      => $n,
                    'dentro_da_regra' => $n >= 58 && $n <= 60
                        && ! preg_match('/\b(de|para|com|do|da|e|em)\b/iu', $texto),
                ];
            })
            ->filter(fn ($t) => $t['texto'] !== '')
            ->values()
            ->all();
    }

    /** Traduz o erro do provedor para algo que o publicador entenda. */
    private function mensagemAmigavel(int $status, string $corpo): string
    {
        if ($status === 503 || str_contains(strtolower($corpo), 'overloaded')) {
            return 'O provedor de IA está sobrecarregado neste momento. Tente novamente em alguns minutos.';
        }

        if ($status === 401 || $status === 403) {
            return 'A chave de API da IA foi recusada. Confira LLM_API_KEY.';
        }

        if ($status === 404 || str_contains(strtolower($corpo), 'unsupported model')) {
            return 'O modelo configurado não existe ou saiu do ar. Confira LLM_MODEL.';
        }

        if ($status === 410) {
            return 'O modelo configurado chegou ao fim de vida no provedor. Escolha outro em LLM_MODEL.';
        }

        return "A IA respondeu com erro {$status}. Tente novamente.";
    }
}
