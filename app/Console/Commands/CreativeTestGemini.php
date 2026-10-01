<?php

namespace App\Console\Commands;

use App\Services\Creative\Contracts\ImageGenerationProvider;
use App\Services\Creative\Dto\CreativeGenerationRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Prova técnica manual do Creative Engine V0.1 (plano-incubadora-v1 §6.3):
 * responde "a Gemini consegue gerar um criativo fiel a partir da foto real
 * do produto?" com código de verdade, ANTES de existir tabela, job, rota ou
 * UI. Ao contrário do `GeminiImageProviderTest`, este comando chama a API
 * de VERDADE — custa crédito. Rodar com cautela.
 */
class CreativeTestGemini extends Command
{
    protected $signature = 'creative:test-gemini
        {--imagem=* : Caminho de foto local de referência; repetir a opção manda vários ângulos do MESMO produto}
        {--prompt= : Prompt alternativo}
        {--modelo= : Força um modelo de imagem só nesta execução (para comparar modelos, §19)}
        {--tamanho= : Força o image_size (512px|1K|2K|4K) — o lite não aceita 2K}
        {--saida= : Onde gravar a imagem gerada}';

    protected $description = 'Prova técnica manual: testa conectividade e geração de imagem com a Gemini (custa crédito)';

    public function handle(ImageGenerationProvider $provider): int
    {
        $chave = (string) config('services.creative.gemini.key', '');

        if ($chave === '') {
            $this->error('GEMINI_API_KEY não está preenchida no .env. Configure a chave antes de testar.');

            return self::FAILURE;
        }

        // Nunca imprimir a chave inteira — só o suficiente para confirmar
        // qual chave está em uso sem expô-la.
        $this->info('Chave da Gemini configurada (comprimento '.strlen($chave).', termina em "'.substr($chave, -4).'").');

        try {
            $this->testarTexto($provider);

            $caminhosImagem = (array) $this->option('imagem');

            if ($caminhosImagem !== []) {
                $this->testarImagem($provider, $caminhosImagem);
            } else {
                $this->comment('Nenhum --imagem informado: só a conectividade de texto foi testada.');
                $this->comment('Exemplo de uso: php artisan creative:test-gemini --imagem="/caminho/para/foto.jpg"');
            }
        } catch (\Throwable $e) {
            // A mensagem já é amigável e em pt-BR (GeminiImageProvider).
            // Nunca estourar stack trace com payload.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function testarTexto(ImageGenerationProvider $provider): void
    {
        $prompt = 'Responda apenas com a palavra OK.';

        $t0 = microtime(true);
        $resposta = $provider->gerarTexto($prompt);
        $latenciaMs = (int) round((microtime(true) - $t0) * 1000);

        $this->info('Teste de TEXTO:');
        // "ordem tentada", não "modelo usado": com reserva configurada quem
        // respondeu pode ser o 2º da fila, e imprimir só o principal mandaria
        // o operador tirar conclusão errada sobre qual modelo está de pé.
        // Qual deles atendeu sai no log com a tag [Creative].
        $cadeia = array_filter(array_map('trim', array_merge(
            [(string) config('services.creative.gemini.text_model')],
            explode(',', (string) config('services.creative.gemini.text_fallbacks')),
        )));
        $this->line('  ordem de modelos tentada: '.implode(' → ', $cadeia));
        $this->line("  latência: {$latenciaMs}ms");
        $this->line('  status: ok');
        $this->line('  resposta (primeiros 120 chars): '.mb_substr(trim($resposta), 0, 120));
    }

    /**
     * @param array<int, string> $caminhosImagem Ângulos do MESMO produto — é
     *                                           assim que o §8.2 trata as
     *                                           referências visuais: quanto
     *                                           mais ângulos, menos o modelo
     *                                           precisa inventar o que não vê.
     */
    private function testarImagem(ImageGenerationProvider $provider, array $caminhosImagem): void
    {
        $referencias = [];

        foreach ($caminhosImagem as $caminho) {
            if (! is_file($caminho) || ! is_readable($caminho)) {
                throw new \RuntimeException("Arquivo de imagem não encontrado ou ilegível: {$caminho}");
            }

            $referencias[] = [
                'mime'  => (string) (mime_content_type($caminho) ?: 'image/jpeg'),
                'bytes' => (string) file_get_contents($caminho),
            ];
        }

        $prompt = (string) ($this->option('prompt') ?: $this->promptDefaultDeFidelidade());

        // --modelo força UM modelo nesta execução: sobrescreve o principal E
        // zera as reservas, senão a comparação mentiria (o resultado poderia
        // vir de outro modelo que não o pedido, sem o operador notar).
        if ($modelo = $this->option('modelo')) {
            config([
                'services.creative.gemini.image_model'     => (string) $modelo,
                'services.creative.gemini.image_fallbacks' => '',
            ]);
        }

        $request = new CreativeGenerationRequest(
            $prompt,
            $referencias,
            imageSize: $this->option('tamanho') ? (string) $this->option('tamanho') : null,
        );

        $t0 = microtime(true);
        $resultado = $provider->gerarImagem($request);
        $latenciaMs = (int) round((microtime(true) - $t0) * 1000);

        $caminhoSaida = $this->option('saida')
            ? (string) $this->option('saida')
            : storage_path('app/private/creative-testes/'.now()->format('Ymd-His')."-{$resultado->modelo}.jpg");

        File::ensureDirectoryExists(dirname($caminhoSaida));
        file_put_contents($caminhoSaida, $resultado->bytes);

        $this->info('Teste de IMAGEM:');
        $this->line('  referências enviadas: '.count($referencias));
        $this->line('  modelo que respondeu: '.$resultado->modelo);
        $this->line("  latência: {$resultado->latenciaMs}ms (medida local: {$latenciaMs}ms)");
        $this->line('  tamanho: '.round($resultado->tamanhoBytes() / 1024, 1).' KB');
        $this->line('  arquivo gravado em: '.$caminhoSaida);
        $this->comment('Abra o arquivo acima e julgue se o produto foi PRESERVADO (§16 do plano canônico).');
    }

    /**
     * Prompt padrão do teste de fidelidade: pede fundo limpo e estilo
     * marketplace, mas NUNCA inventa texto nem especificação do produto —
     * isso é escopo do CreativePlanner (V0.4), fora deste spike.
     */
    private function promptDefaultDeFidelidade(): string
    {
        return 'Gere uma foto de produto profissional em fundo limpo (branco ou neutro), '
            .'estilo marketplace, preservando fielmente a forma, as cores e os detalhes do '
            .'produto na imagem de referência. Não adicione texto, logotipo nem especificações '
            .'inventadas.';
    }
}
