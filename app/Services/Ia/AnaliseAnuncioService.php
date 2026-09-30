<?php

namespace App\Services\Ia;

use Illuminate\Http\Client\ConnectionException;
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
 *
 * A 4ª chamada (`ficha`, 30/09/2026) NÃO é MAG T8: é o preenchimento do
 * cadastro (atributos da categoria, variações, pacote, garantia) para a IA
 * deixar o anúncio inteiro em rascunho. Prompt próprio, regra própria.
 */
class AnaliseAnuncioService
{
    /**
     * Erros em que vale passar para o modelo reserva: sobrecarga, falha de
     * gateway e modelo que saiu do ar/não está liberado para a conta (a NVIDIA
     * devolve 404 "Function not found for account" e 410 em fim de vida).
     */
    private const HTTP_TROCA_MODELO = [404, 408, 410, 429, 500, 502, 503, 504];

    /** Abaixo disto de prazo restante nem vale abrir outra chamada. */
    private const PRAZO_MINIMO_S = 20;

    /**
     * Instante (microtime) em que TODA a geração tem que ter parado.
     *
     * POR QUE EXISTE. A versão anterior retentava cada chamada 3× com timeout
     * de 300s — um provedor mudo custava 900s+ numa etapa só, o worker matava o
     * processo no teto do job, a análise ficava em "rodando" e a tela
     * perguntava para sempre. Com prazo, cada chamada recebe só o tempo que
     * sobra, e a etapa FALHA com mensagem antes de o worker precisar matar.
     */
    private ?float $prazo = null;

    public function comPrazo(float $instante): static
    {
        $this->prazo = $instante;

        return $this;
    }

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

    /**
     * Etapa 4 — Ficha técnica da categoria já escolhida.
     *
     * Devolve o JSON CRU do modelo: quem confere cada valor contra o catálogo
     * do ML é o `RascunhoAnuncioIaService`. Aqui só se pergunta.
     *
     * NÃO é parte da metodologia MAG T8 (que para na descrição): é o
     * preenchimento do cadastro, e a regra dele é a oposta da copy — nada de
     * persuasão, só o que as especificações sustentam.
     */
    public function ficha(string $produto, string $specs, string $titulo, string $caminhoCategoria, string $catalogo): array
    {
        $r = $this->chamar($this->promptFicha($produto, $specs, $titulo, $caminhoCategoria, $catalogo), 6000);

        return [
            'dados' => $r['json'],
            'meta'  => $r['meta'],
        ];
    }

    // ═══ Chamada ao provedor ══════════════════════════════════════════════════

    /**
     * Uma chamada, um JSON de volta — tentando o modelo principal e, se ele
     * estiver fora/sobrecarregado/mudo, os reservas de `LLM_MODEL_FALLBACK`.
     *
     * Sem retentar o MESMO modelo: medido em 29/09/2026 na NVIDIA, modelo que
     * não responde em 150s continua sem responder na 2ª vez (fila do lado
     * deles). Trocar de modelo resolve; insistir só queima o prazo.
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

        $modelos = collect([$cfg['model'] ?? null])
            ->merge(explode(',', (string) ($cfg['fallbacks'] ?? '')))
            ->map(fn ($m) => trim((string) $m))
            ->filter()
            ->unique()
            ->values();

        $erro = null;

        foreach ($modelos as $modelo) {
            try {
                return $this->chamarModelo($cfg, $modelo, $prompt, $maxTokens);
            } catch (FalhaTrocavel $e) {
                // Guarda o erro e tenta o próximo; se todos falharem, é esta a
                // mensagem que chega ao publicador.
                $erro = $e;
                Log::warning("[IA] Modelo {$modelo} falhou, tentando o próximo: {$e->getMessage()}");
            }
        }

        throw new \RuntimeException($erro?->getMessage() ?? 'Nenhum modelo de IA configurado (LLM_MODEL).');
    }

    /**
     * Uma chamada a UM modelo. Lança `FalhaTrocavel` quando outro modelo pode
     * resolver (sobrecarga, timeout, modelo indisponível) e `RuntimeException`
     * quando não adianta trocar (chave recusada, prazo acabou).
     */
    private function chamarModelo(array $cfg, string $modelo, string $prompt, int $maxTokens): array
    {
        $timeout = (int) $cfg['timeout'];

        if ($this->prazo !== null) {
            $resta = (int) floor($this->prazo - microtime(true));

            if ($resta < self::PRAZO_MINIMO_S) {
                throw new \RuntimeException('A geração passou do tempo limite. Tente novamente — o que já ficou pronto foi mantido.');
            }

            $timeout = min($timeout, $resta);
        }

        $t0 = microtime(true);

        try {
            $resposta = Http::withToken((string) $cfg['key'])
                ->timeout($timeout)
                // Conectar é rápido ou não é. Separar do tempo de geração evita
                // esperar o timeout inteiro por um endpoint que está fora do ar.
                ->connectTimeout(15)
                ->post(rtrim((string) $cfg['base_url'], '/') . '/chat/completions', [
                    'model'       => $modelo,
                    'temperature' => 0.7,
                    'max_tokens'  => $maxTokens,
                    'messages'    => [['role' => 'user', 'content' => $prompt]],
                ]);
        } catch (ConnectionException $e) {
            Log::warning('[IA] Provedor não respondeu', ['modelo' => $modelo, 'timeout_s' => $timeout, 'erro' => $e->getMessage()]);

            throw new FalhaTrocavel("A IA não respondeu em {$timeout}s. Tente novamente em alguns minutos.");
        }

        $duracaoMs = (int) round((microtime(true) - $t0) * 1000);

        if (! $resposta->successful()) {
            $corpo = mb_substr($resposta->body(), 0, 400);
            Log::warning('[IA] Falha do provedor', [
                'status' => $resposta->status(),
                'modelo' => $modelo,
                'corpo'  => $corpo,
            ]);

            $mensagem = $this->mensagemAmigavel($resposta->status(), $corpo);

            throw in_array($resposta->status(), self::HTTP_TROCA_MODELO, true)
                ? new FalhaTrocavel($mensagem)
                : new \RuntimeException($mensagem);
        }

        $json     = $resposta->json();
        $conteudo = (string) data_get($json, 'choices.0.message.content', '');

        if (trim($conteudo) === '') {
            $pensou = mb_strlen((string) data_get($json, 'choices.0.message.reasoning_content', ''));

            throw new FalhaTrocavel(
                $pensou > 0
                    ? 'A IA gastou todo o orçamento de tokens raciocinando e não chegou a responder. Aumente LLM_MAX_TOKENS ou troque para um modelo sem raciocínio longo.'
                    : 'A IA respondeu vazio. Tente novamente em alguns minutos.'
            );
        }

        $dados = $this->extrairJson($conteudo);

        if ($dados === null) {
            Log::warning('[IA] Resposta não era JSON válido', ['inicio' => mb_substr($conteudo, 0, 300)]);

            throw new FalhaTrocavel('A IA não devolveu um JSON válido. Tente gerar novamente.');
        }

        return [
            'json' => $dados,
            'meta' => [
                // O modelo que RESPONDEU pode não ser o pedido: combo com
                // fallback troca por baixo. Registrar o real.
                'modelo'         => (string) ($json['model'] ?? $modelo),
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

    /**
     * Prompt da ficha técnica. A lista de atributos vem pronta do
     * `RascunhoAnuncioIaService::catalogoParaPrompt()` — só os que o wizard
     * mostra, para a IA não preencher campo que o publicador não vê.
     */
    private function promptFicha(string $produto, string $specs, string $titulo, string $caminhoCategoria, string $catalogo): string
    {
        $bloco = $this->blocoSpecs($specs);

        return <<<TXT
        Preencha o cadastro do anúncio do produto **{$produto}** no Mercado Livre.

        Categoria já escolhida: {$caminhoCategoria}
        Título do anúncio: {$titulo}{$bloco}

        REGRAS (obrigatórias):
        1. Use SOMENTE o que o nome do produto e as especificações acima sustentam. NÃO invente marca, modelo, medida, material, quantidade nem certificação. Na dúvida, OMITA o atributo — campo vazio o publicador completa; valor inventado vira anúncio errado.
        2. Atributo com "opções": responda exatamente o texto de uma das opções listadas.
        3. Atributo "número + unidade": responda o número e a unidade, ex.: "45 cm".
        4. Atributo "número": só o número.
        5. Atributo "Sim/Não": responda "Sim" ou "Não".
        6. "variacoes": só quando as especificações trazem valores de um atributo de variação (ex.: cores ou tamanhos disponíveis). Uma entrada por combinação. Sem essa informação, lista vazia.
        7. "pacote": só se as especificações trazem medidas e peso do produto. Nesse caso estime o pacote EMBALADO (produto + embalagem), em gramas e centímetros. Sem medidas, null.
        8. "garantia": só se as especificações mencionam garantia (ex.: "90 dias", "12 meses"). Senão, null.

        ATRIBUTOS DA FICHA (ID | nome | formato). Os marcados com * são obrigatórios na categoria:
        {$catalogo}

        Responda APENAS com JSON válido, sem crases, usando os IDs como chaves:
        {"atributos":{"ID":"valor"},"variacoes":[{"ID":"valor"}],"pacote":{"peso_g":0,"comprimento_cm":0,"largura_cm":0,"altura_cm":0},"garantia":null}
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

        if ($status === 429) {
            return 'O limite de uso da IA foi atingido neste momento. Tente novamente em alguns minutos.';
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
