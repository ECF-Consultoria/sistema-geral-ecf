<?php

namespace App\Console\Commands;

use App\Models\MlAnuncioCriativo;
use App\Services\Creative\CreativeJuiz;
use Illuminate\Console\Command;

/**
 * Prova real do juiz de visão (Fase 162, D-06) contra um criativo que JÁ
 * EXISTE — lê a imagem gerada, as fotos originais e o Product Truth
 * gravado, pergunta ao Gemini e grava o veredito na tabela. Dá para auditar
 * os criativos já em produção, um por um, sem gerar imagem nova.
 *
 * Ao contrário de `GeminiJuizProviderTest`, este comando chama a API de
 * VERDADE — custa crédito (molde `CreativeTestGemini`). Rodar com cautela.
 */
class CreativeValidarCriativo extends Command
{
    protected $signature = 'creative:validar-criativo
        {criativo : id ou token do criativo em ml_anuncio_criativos}
        {--json : Imprime o veredito em JSON em vez de tabela}';

    protected $description = 'Julga um criativo JÁ GERADO contra as fotos originais e o Product Truth gravado (chama a API de VERDADE, custa crédito)';

    public function handle(CreativeJuiz $juiz): int
    {
        $identificador = (string) $this->argument('criativo');

        $criativo = ctype_digit($identificador)
            ? MlAnuncioCriativo::find((int) $identificador)
            : MlAnuncioCriativo::where('token', $identificador)->first();

        if ($criativo === null) {
            $this->error("Criativo '{$identificador}' não encontrado.");

            return self::FAILURE;
        }

        if (empty($criativo->imagem_path)) {
            $this->error('Este criativo não tem imagem gerada (imagem_path vazio) — nada para validar.');

            return self::FAILURE;
        }

        $this->warn('Esta chamada CUSTA crédito (~US$ 0,003 por validação, chamada de texto com 2–4 imagens).');

        try {
            $validacao = $juiz->julgarEGravar($criativo);
        } catch (\Throwable $e) {
            // Mensagem já é amigável e em pt-BR (GeminiImageProvider) — nunca
            // estourar stack trace com payload.
            $this->error('Falha ao validar: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($validacao->paraColuna(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("Status: {$validacao->status}");
        $this->line('Fidelidade: '.($validacao->fidelidade ?? '—'));
        $this->line('Motivo: '.($validacao->motivoCurto ?? '—'));
        $this->line('Mensagem: '.$validacao->mensagem);

        if ($validacao->problemas !== []) {
            $this->table(
                ['Tipo', 'Gravidade', 'Explicação'],
                array_map(fn (array $p) => [$p['tipo'], $p['gravidade'], $p['explicacao']], $validacao->problemas),
            );
        } else {
            $this->comment('Nenhum problema reportado.');
        }

        return self::SUCCESS;
    }
}
