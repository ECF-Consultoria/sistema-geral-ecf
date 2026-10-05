<?php

namespace App\Services\Creative;

use App\Services\Creative\Contracts\ImageGenerationProvider;
use App\Services\Creative\Contracts\ImageJudgementProvider;
use App\Services\Creative\Dto\CreativeGenerationRequest;
use App\Services\Creative\Dto\CreativeGenerationResult;
use App\Services\Creative\Dto\CreativeJudgementRequest;
use App\Services\Creative\Dto\CreativeJudgementResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Provedor Gemini (Interactions API) para o Creative Engine V0.1 — prova
 * técnica isolada de geração de imagem, antes de qualquer tabela/job/rota
 * (plano-incubadora-v1 §20). Reusa as lições pagas em produção pelo
 * `AnaliseAnuncioService` (texto, NVIDIA): sem retry no mesmo modelo, troca
 * de modelo em falha trocável, connectTimeout separado do timeout de
 * geração, mensagem amigável traduzida do status HTTP.
 *
 * DIFERENÇAS do molde de texto que são deliberadas e não bug:
 * - auth por header `x-goog-api-key`, NÃO `Bearer` (a API da Gemini não usa
 *   OAuth Bearer aqui — conferido na doc oficial em 2026-10-01);
 * - endpoint único `/interactions` para texto E imagem, diferenciado pelo
 *   `response_format` do corpo, não por rota;
 * - o base64 da resposta é decodificado ANTES de devolver ao chamador — quem
 *   usa este provider nunca deveria lidar com base64 diretamente.
 *
 * FORMA DA RESPOSTA — MEDIDA, não deduzida da documentação. A página de docs
 * resume a resposta como `interaction.outputText` / `interaction.output_image
 * .data`, e isso NÃO é o que a API devolve. Chamada real em 2026-10-01
 * (gemini-3.5-flash-lite, HTTP 200) devolveu objeto PLANO:
 *
 *   {id, status, usage, created, updated, service_tier, object, model,
 *    steps: [ {type:"thought", signature:"…"},
 *             {type:"model_output", content:[{type:"text", text:"ok"}]} ]}
 *
 * Por isso a extração varre `steps[]` atrás do passo `model_output` e lê o
 * `content[]` dele. Os caminhos da documentação ficam como ÚLTIMO recurso:
 * se a API mudar de volta, nada quebra.
 *
 * A forma do content de IMAGEM foi CONFIRMADA na primeira geração real, em
 * 2026-10-01 com tier pago (gemini-3.1-flash-image, HTTP 200):
 *
 *   steps: [ {type:"thought"},
 *            {type:"model_output",
 *             content:[{type:"image", mime_type:"image/jpeg", data:"<base64>"}]} ]
 *
 * O extrator ainda aceita as outras grafias (`image_data`, `inline_data.data`)
 * de propósito: custa nada e errar o caminho aqui produz o sintoma mais
 * confuso possível — HTTP 200 lido como "respondeu sem imagem", que faz o
 * provider trocar de modelo e falhar em todos.
 *
 * SEGURANÇA (§17): a chave nunca é logada, o body inteiro do pedido nunca é
 * logado, e o base64 (de entrada OU de saída) jamais aparece em log — só
 * modelo, latência, status, tamanho em bytes e quantidade de referências.
 */
class GeminiImageProvider implements ImageGenerationProvider, ImageJudgementProvider
{
    /**
     * Erros em que vale passar para o modelo reserva: sobrecarga, timeout de
     * gateway, modelo fora do ar ou que saiu de linha. Mesma lista do
     * `AnaliseAnuncioService::HTTP_TROCA_MODELO`, adaptada à Gemini.
     */
    private const HTTP_TROCA_MODELO = [404, 408, 410, 429, 500, 502, 503, 504];

    /**
     * Gera a imagem tentando o modelo principal e, se ele estiver fora/
     * sobrecarregado/mudo, os reservas de `GEMINI_IMAGE_MODEL_FALLBACK`, NA
     * ORDEM, sem jamais retentar o MESMO modelo — a lição medida em
     * 29/09/2026 no provedor de texto: modelo mudo continua mudo na 2ª
     * tentativa, e insistir só queima o prazo.
     */
    public function gerarImagem(CreativeGenerationRequest $request): CreativeGenerationResult
    {
        $cfg = $this->configGemini();

        $modelos = $this->listaDeModelos($cfg['image_model'] ?? null, $cfg['image_fallbacks'] ?? '');

        $erro = null;

        foreach ($modelos as $modelo) {
            try {
                return $this->gerarImagemComModelo($cfg, $modelo, $request);
            } catch (FalhaDeGeracaoTrocavel $e) {
                $erro = $e;
                Log::warning("[Creative] Modelo {$modelo} falhou, tentando o próximo: {$e->getMessage()}");
            }
        }

        throw new \RuntimeException($erro?->getMessage() ?? 'Nenhum modelo de imagem configurado (GEMINI_IMAGE_MODEL).');
    }

    /**
     * Texto, com a MESMA disciplina de reserva da imagem.
     *
     * POR QUE A RESERVA EXISTE AQUI. Medido em 2026-10-01: o
     * `gemini-3.8-flash` devolveu 503 "experiencing high demand" em chamadas
     * seguidas enquanto o `gemini-3.5-flash-lite` respondia em 1,3s com a
     * MESMA chave. Sem reserva, um modelo congestionado faz o comando de teste
     * dizer que a conexão falhou — e manda o operador procurar problema na
     * chave, que está boa.
     */
    public function gerarTexto(string $prompt): string
    {
        $cfg = $this->configGemini();

        $modelos = $this->listaDeModelos($cfg['text_model'] ?? null, $cfg['text_fallbacks'] ?? '');

        $erro = null;

        foreach ($modelos as $modelo) {
            try {
                return $this->gerarTextoComModelo($cfg, $modelo, $prompt);
            } catch (FalhaDeGeracaoTrocavel $e) {
                $erro = $e;
                Log::warning("[Creative] Modelo de texto {$modelo} falhou, tentando o próximo: {$e->getMessage()}");
            }
        }

        throw new \RuntimeException($erro?->getMessage() ?? 'Nenhum modelo de texto configurado (GEMINI_TEXT_MODEL).');
    }

    /**
     * Julga N imagens contra o prompt (Fase 162, D-06) — MESMA disciplina de
     * reserva: tenta `judge_model`, depois `judge_fallbacks` NA ORDEM, nunca
     * retentando o MESMO modelo. Implementa `ImageJudgementProvider`; esta
     * classe é compartilhada com a Fase 165 — `gerarImagem()`/`gerarTexto()`
     * não mudam nenhuma linha.
     */
    public function julgar(CreativeJudgementRequest $pedido): CreativeJudgementResult
    {
        $cfg = $this->configGemini();

        $modelos = $this->listaDeModelos($cfg['judge_model'] ?? null, $cfg['judge_fallbacks'] ?? '');

        $erro = null;

        foreach ($modelos as $modelo) {
            try {
                return $this->julgarComModelo($cfg, $modelo, $pedido);
            } catch (FalhaDeGeracaoTrocavel $e) {
                $erro = $e;
                Log::warning("[Creative] Modelo de juiz {$modelo} falhou, tentando o próximo: {$e->getMessage()}");
            }
        }

        throw new \RuntimeException($erro?->getMessage() ?? 'Nenhum modelo de juiz configurado (GEMINI_JUDGE_MODEL).');
    }

    /**
     * Uma chamada de julgamento, um modelo. Monta o `input` exatamente como
     * MEDIDO em 2026-10-05: bloco de texto do prompt seguido de um bloco de
     * imagem por item de `$pedido->imagens`, na ordem recebida, e chama
     * `/interactions` SEM `response_format` — é a ausência dela que faz a
     * API devolver TEXTO em vez de imagem.
     */
    private function julgarComModelo(array $cfg, string $modelo, CreativeJudgementRequest $pedido): CreativeJudgementResult
    {
        $input = [['type' => 'text', 'text' => $pedido->prompt]];

        foreach ($pedido->imagens as $imagem) {
            $input[] = [
                'type'      => 'image',
                'mime_type' => $imagem['mime'],
                'data'      => base64_encode($imagem['bytes']),
            ];
        }

        $t0 = microtime(true);

        $resposta = $this->chamarInteractions($cfg, [
            'model' => $modelo,
            'input' => $input,
        ], $modelo);

        $duracaoMs = (int) round((microtime(true) - $t0) * 1000);

        $texto = $this->extrairTexto($resposta);

        if (trim($texto) === '') {
            Log::warning('[Creative] Veredito do juiz vazio', [
                'modelo'      => $modelo,
                'latencia_ms' => $duracaoMs,
                'qtd_imagens' => count($pedido->imagens),
            ]);

            throw new FalhaDeGeracaoTrocavel("O modelo {$modelo} respondeu vazio ao julgar a imagem.");
        }

        // GEN-05: JAMAIS o prompt, JAMAIS base64, JAMAIS a chave, JAMAIS o
        // corpo inteiro da resposta. O texto do veredito vai para a coluna
        // (Task 3), nunca para o log.
        Log::info('[Creative] Veredito do juiz', [
            'modelo'        => $modelo,
            'latencia_ms'   => $duracaoMs,
            'qtd_imagens'   => count($pedido->imagens),
            'tamanho_texto' => strlen($texto),
        ]);

        return new CreativeJudgementResult(texto: $texto, modelo: $modelo, latenciaMs: $duracaoMs);
    }

    private function gerarTextoComModelo(array $cfg, string $modelo, string $prompt): string
    {
        $t0 = microtime(true);

        $resposta = $this->chamarInteractions($cfg, [
            'model' => $modelo,
            'input' => $prompt,
        ]);

        $duracaoMs = (int) round((microtime(true) - $t0) * 1000);

        $texto = $this->extrairTexto($resposta);

        if (trim($texto) === '') {
            Log::warning('[Creative] Texto vazio', ['modelo' => $modelo, 'latencia_ms' => $duracaoMs]);

            throw new \RuntimeException('A Gemini respondeu vazio para o pedido de texto. Tente novamente.');
        }

        return $texto;
    }

    // ═══ Uma chamada, um modelo ════════════════════════════════════════════

    private function gerarImagemComModelo(array $cfg, string $modelo, CreativeGenerationRequest $request): CreativeGenerationResult
    {
        $input = [['type' => 'text', 'text' => $request->prompt]];

        foreach ($request->imagensReferencia as $imagem) {
            $input[] = [
                'type'      => 'image',
                'mime_type' => $imagem['mime'],
                'data'      => base64_encode($imagem['bytes']),
            ];
        }

        $t0 = microtime(true);

        $resposta = $this->chamarInteractions($cfg, [
            'model' => $modelo,
            'input' => $input,
            'response_format' => [
                'type'         => 'image',
                'mime_type'    => $cfg['mime'] ?? 'image/jpeg',
                'aspect_ratio' => $request->aspectRatio ?? ($cfg['aspect_ratio'] ?? '1:1'),
                'image_size'   => $request->imageSize ?? ($cfg['image_size'] ?? '2K'),
            ],
        ], $modelo);

        $duracaoMs = (int) round((microtime(true) - $t0) * 1000);

        $imagem = $this->extrairImagem($resposta);
        $base64 = $imagem['data'] ?? null;

        if (empty($base64)) {
            Log::warning('[Creative] Resposta 200 sem imagem', [
                'modelo'       => $modelo,
                'latencia_ms'  => $duracaoMs,
                'qtd_referencias' => count($request->imagensReferencia),
            ]);

            throw new FalhaDeGeracaoTrocavel(
                "O modelo {$modelo} respondeu sem imagem (pode ter recusado o pedido por política de conteúdo)."
            );
        }

        $bytes = base64_decode((string) $base64, true);

        if ($bytes === false) {
            throw new FalhaDeGeracaoTrocavel("O modelo {$modelo} devolveu base64 inválido.");
        }

        $mimeSaida = (string) ($imagem['mime'] ?: ($cfg['mime'] ?? 'image/jpeg'));

        Log::info('[Creative] Imagem gerada', [
            'modelo'       => $modelo,
            'latencia_ms'  => $duracaoMs,
            'tamanho_bytes' => strlen($bytes),
            'qtd_referencias' => count($request->imagensReferencia),
        ]);

        return new CreativeGenerationResult(
            bytes: $bytes,
            mime: $mimeSaida,
            modelo: $modelo,
            latenciaMs: $duracaoMs,
            status: 'sucesso',
            meta: ['qtd_referencias' => count($request->imagensReferencia)],
        );
    }

    /**
     * Faz a chamada HTTP crua ao endpoint `/interactions` e devolve o JSON
     * decodificado. Trata erro de conexão e de status aqui, ponto único para
     * texto e imagem.
     *
     * @throws FalhaDeGeracaoTrocavel|\RuntimeException
     */
    private function chamarInteractions(array $cfg, array $body, ?string $modeloParaLog = null): array
    {
        $modelo = $modeloParaLog ?? (string) ($body['model'] ?? '');

        try {
            $resposta = Http::withHeaders(['x-goog-api-key' => $cfg['key']])
                ->timeout((int) $cfg['timeout'])
                // Conectar é rápido ou não é — separar do tempo de geração
                // evita esperar o timeout inteiro por um endpoint fora do ar.
                ->connectTimeout((int) $cfg['connect_timeout'])
                ->post(rtrim((string) $cfg['base_url'], '/').'/interactions', $body);
        } catch (ConnectionException $e) {
            Log::warning('[Creative] Provedor não respondeu', [
                'modelo'    => $modelo,
                'timeout_s' => $cfg['timeout'],
                'erro'      => $e->getMessage(),
            ]);

            throw new FalhaDeGeracaoTrocavel("A Gemini não respondeu em {$cfg['timeout']}s. Tente novamente em alguns minutos.");
        }

        if (! $resposta->successful()) {
            $corpo = mb_substr($resposta->body(), 0, 400);

            Log::warning('[Creative] Falha do provedor', [
                'status' => $resposta->status(),
                'modelo' => $modelo,
                'corpo'  => $corpo,
            ]);

            $mensagem = $this->mensagemAmigavel($resposta->status());

            throw in_array($resposta->status(), self::HTTP_TROCA_MODELO, true)
                ? new FalhaDeGeracaoTrocavel($mensagem)
                : new \RuntimeException($mensagem);
        }

        return (array) $resposta->json();
    }

    // ═══ Extração da resposta ══════════════════════════════════════════════

    /**
     * Blocos de `content` do passo `model_output`, na ordem.
     *
     * O passo `thought` é ignorado de propósito: ele traz só a `signature` do
     * raciocínio, nunca conteúdo útil, e lê-lo como saída devolveria lixo.
     *
     * @return array<int, array<string, mixed>>
     */
    private function blocosDeSaida(array $resposta): array
    {
        $blocos = [];

        foreach ((array) ($resposta['steps'] ?? []) as $passo) {
            if (($passo['type'] ?? null) !== 'model_output') {
                continue;
            }

            foreach ((array) ($passo['content'] ?? []) as $bloco) {
                if (is_array($bloco)) {
                    $blocos[] = $bloco;
                }
            }
        }

        return $blocos;
    }

    /**
     * Texto da resposta: concatena todos os blocos de texto do `model_output`.
     *
     * Concatena em vez de pegar o primeiro porque modelo longo pode quebrar a
     * saída em vários blocos — pegar só o [0] truncaria a resposta em silêncio.
     */
    private function extrairTexto(array $resposta): string
    {
        $partes = [];

        foreach ($this->blocosDeSaida($resposta) as $bloco) {
            if (($bloco['type'] ?? null) === 'text' && isset($bloco['text'])) {
                $partes[] = (string) $bloco['text'];
            }
        }

        if ($partes !== []) {
            return implode('', $partes);
        }

        // Último recurso: as grafias que a documentação descreve.
        return (string) (data_get($resposta, 'interaction.outputText') ?? data_get($resposta, 'outputText') ?? '');
    }

    /**
     * Imagem da resposta, como `['data' => base64, 'mime' => string|null]`.
     *
     * ⚠️ INFERIDO, não medido — ver o aviso no docblock da classe. Aceita as
     * grafias plausíveis de onde o base64 pode vir porque errar o caminho aqui
     * produz o sintoma mais confuso possível: HTTP 200 tratado como "respondeu
     * sem imagem", que manda o provider trocar de modelo e falhar em todos.
     *
     * @return array{data: string|null, mime: string|null}
     */
    private function extrairImagem(array $resposta): array
    {
        foreach ($this->blocosDeSaida($resposta) as $bloco) {
            $base64 = $bloco['data']
                ?? $bloco['image_data']
                ?? data_get($bloco, 'inline_data.data')
                ?? data_get($bloco, 'image.data');

            if (! empty($base64) && is_string($base64)) {
                return [
                    'data' => $base64,
                    'mime' => (string) ($bloco['mime_type'] ?? data_get($bloco, 'inline_data.mime_type') ?? '') ?: null,
                ];
            }
        }

        // Último recurso: a grafia que a documentação descreve.
        $doc = data_get($resposta, 'interaction.output_image');

        return [
            'data' => is_array($doc) ? ($doc['data'] ?? null) : null,
            'mime' => is_array($doc) ? ($doc['mime_type'] ?? null) : null,
        ];
    }

    // ═══ Helpers ═══════════════════════════════════════════════════════════

    private function configGemini(): array
    {
        $cfg = config('services.creative.gemini', []);

        if (empty($cfg['key'])) {
            throw new \RuntimeException('Chave da Gemini não configurada. Preencha GEMINI_API_KEY no .env.');
        }

        return $cfg;
    }

    /** Monta a lista de modelos a tentar: principal + reservas, sem duplicar. */
    private function listaDeModelos(?string $principal, string $fallbacks): \Illuminate\Support\Collection
    {
        return collect([$principal])
            ->merge(explode(',', $fallbacks))
            ->map(fn ($m) => trim((string) $m))
            ->filter()
            ->unique()
            ->values();
    }

    /** Traduz o status HTTP para algo que o operador do comando entenda. */
    private function mensagemAmigavel(int $status): string
    {
        return match (true) {
            $status === 401 || $status === 403 => 'A chave da Gemini foi recusada. Confira GEMINI_API_KEY.',
            // NÃO dizer só "tente em alguns minutos": medido em 2026-10-01, o
            // 429 dos modelos de IMAGEM no tier grátis é "limit: 0 requests
            // per day" — nunca passa com o tempo, só com upgrade de tier.
            $status === 429 => 'A Gemini recusou por limite de uso (429). Pode ser cota do minuto — ou o tier da conta não liberar este modelo (os modelos de imagem são 0/dia no tier grátis). Confira em https://ai.dev/rate-limit.',
            $status === 503 => 'A Gemini está sobrecarregada neste momento. Tente novamente em alguns minutos.',
            // O 404 da Gemini é genérico ("Requested entity was not found") e
            // NÃO significa só "modelo inexistente": medido em 2026-10-01, o
            // gemini-3.1-flash-lite-image devolve 404 em QUALQUER pedido com
            // image_size=2K (o nosso default) e responde 200 no mesmo pedido
            // com 1K. Quem lesse só "não existe" ia caçar nome de modelo
            // errado por horas.
            $status === 404 => 'A Gemini recusou (404). Pode ser modelo inexistente/fora do ar — ou combinação não suportada por ESTE modelo, tipicamente GEMINI_IMAGE_SIZE (o lite não aceita 2K). Confira GEMINI_IMAGE_MODEL e GEMINI_IMAGE_SIZE.',
            $status === 400 => 'A Gemini recusou o pedido (requisição malformada).',
            default => "A Gemini respondeu com erro {$status}. Tente novamente.",
        };
    }
}
