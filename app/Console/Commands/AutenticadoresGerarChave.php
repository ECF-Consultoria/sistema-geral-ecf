<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Gera uma chave de 32 bytes (AES-256) para cifrar os secrets TOTP do módulo
 * Autenticadores 2FA. NÃO escreve no .env automaticamente — o .env é
 * compartilhado/sensível; o valor é só impresso para você colar à mão em
 * AUTENTICADORES_ENC_KEY (local e, no deploy, na VPS).
 */
class AutenticadoresGerarChave extends Command
{
    protected $signature = 'autenticadores:gerar-chave';

    protected $description = 'Gera a chave dedicada (AUTENTICADORES_ENC_KEY) para cifrar os secrets TOTP';

    public function handle(): int
    {
        $key = 'base64:' . base64_encode(random_bytes(32));

        $this->newLine();
        $this->line('Cole esta linha no .env (local e, no deploy, na VPS):');
        $this->newLine();
        $this->line("    AUTENTICADORES_ENC_KEY={$key}");
        $this->newLine();
        $this->warn('Guarde-a como uma senha. Sem ela, os secrets já gravados NÃO podem ser decifrados.');

        return self::SUCCESS;
    }
}
