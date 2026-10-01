<?php

namespace App\Services\Creative;

use App\Services\Creative\Contracts\ImageGenerationProvider;
use App\Services\Creative\Dto\CreativeGenerationRequest;
use App\Services\Creative\Dto\CreativeGenerationResult;
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
 * - resposta de imagem vem em base64 (`interaction.output_image.data`) que
 *   é decodificado ANTES de devolver ao chamador — quem usa este provider
 *   nunca deveria lidar com base64 diretamente.
 *
 * SEGURANÇA (§17): a chave nunca é logada, o body inteiro do pedido nunca é
 * logado, e o base64 (de entrada OU de saída) jamais aparece em log — só
 * modelo, latência, status, tamanho em bytes e quantidade de referências.
 */
class GeminiImageProvider implements ImageGenerationProvider
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

    public function gerarTexto(string $prompt): string
    {
        $cfg = $this->configGemini();

        $modelo = (string) ($cfg['text_model'] ?? '');

        $t0 = microtime(true);

        $resposta = $this->chamarInteractions($cfg, [
            'model' => $modelo,
            'input' => $prompt,
        ]);

        $duracaoMs = (int) round((microtime(true) - $t0) * 1000);

        $texto = (string) data_get($resposta, 'interaction.outputText', '');

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

        $base64 = data_get($resposta, 'interaction.output_image.data');

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

        $mimeSaida = (string) (data_get($resposta, 'interaction.output_image.mime_type') ?: ($cfg['mime'] ?? 'image/jpeg'));

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
            $status === 429 => 'O limite de uso da Gemini foi atingido neste momento. Tente novamente em alguns minutos.',
            $status === 503 => 'A Gemini está sobrecarregada neste momento. Tente novamente em alguns minutos.',
            $status === 404 => 'O modelo configurado não existe ou saiu do ar. Confira GEMINI_IMAGE_MODEL/GEMINI_TEXT_MODEL.',
            $status === 400 => 'A Gemini recusou o pedido (requisição malformada).',
            default => "A Gemini respondeu com erro {$status}. Tente novamente.",
        };
    }
}
