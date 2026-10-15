<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Teste de ida e volta do disco das imagens definitivas (v28.0, R2-03).
 *
 * Grava um objeto aleatório sob `_teste-conexao/`, lê de volta, confere o
 * sha256, apaga e confirma que sumiu. Roda ANTES de virar `IMAGENS_DISK=r2`
 * em produção: se qualquer passo falhar, a chave não vira.
 *
 * Nunca imprime chave nem segredo — só disco, driver, bucket, prefixo e tempos.
 */
class TestarConexaoImagens extends Command
{
    protected $signature = 'imagens:testar-conexao
        {--disco= : Disco a testar (default: config filesystems.imagens)}';

    protected $description = 'Grava, lê, confere e apaga um objeto de teste no disco das imagens (R2-03)';

    public function handle(): int
    {
        $nome = (string) ($this->option('disco') ?: config('filesystems.imagens'));
        $config = config("filesystems.disks.{$nome}");

        if (! is_array($config)) {
            $this->error("Disco [{$nome}] não existe em config/filesystems.php.");

            return self::FAILURE;
        }

        $this->line("Disco: {$nome} · driver: ".($config['driver'] ?? '?')
            .(isset($config['bucket']) ? ' · bucket: '.($config['bucket'] ?: '(vazio)') : '')
            .(isset($config['root']) && ($config['driver'] ?? '') === 's3' ? ' · prefixo: '.$config['root'] : ''));

        $caminho = '_teste-conexao/'.Str::uuid().'.bin';
        $conteudo = random_bytes(4096);
        $sha = hash('sha256', $conteudo);
        $passo = 'abrir o disco';

        try {
            $disco = Storage::disk($nome);

            $passo = 'gravar';
            $t = microtime(true);
            if ($disco->put($caminho, $conteudo) === false) {
                throw new \RuntimeException('put devolveu false');
            }
            $this->info(sprintf('✓ gravou  (%d ms)', (microtime(true) - $t) * 1000));

            $passo = 'ler';
            $t = microtime(true);
            $lido = $disco->get($caminho);
            if ($lido === null || hash('sha256', $lido) !== $sha) {
                throw new \RuntimeException('o conteúdo lido não confere com o gravado (sha256)');
            }
            $this->info(sprintf('✓ leu e conferiu sha256  (%d ms)', (microtime(true) - $t) * 1000));

            $passo = 'apagar';
            $t = microtime(true);
            $disco->delete($caminho);
            if ($disco->exists($caminho)) {
                throw new \RuntimeException('o objeto continua lá depois do delete');
            }
            $this->info(sprintf('✓ apagou  (%d ms)', (microtime(true) - $t) * 1000));
        } catch (\Throwable $e) {
            // Só a classe e a mensagem: o SDK da AWS não põe o segredo na mensagem.
            $this->error("✗ falhou ao {$passo}: ".class_basename($e).' — '.Str::limit($e->getMessage(), 300));

            return self::FAILURE;
        }

        $this->info("Conexão OK com o disco [{$nome}].");

        return self::SUCCESS;
    }
}
