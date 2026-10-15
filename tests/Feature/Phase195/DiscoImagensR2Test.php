<?php

namespace Tests\Feature\Phase195;

use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fase 195 (v28.0) — disco `imagens`, disco `r2` e o comando de teste de conexão.
 */
class DiscoImagensR2Test extends TestCase
{
    /** R2-02: sem IMAGENS_DISK, o disco das imagens é o local de sempre. */
    public function test_disco_das_imagens_e_local_por_padrao(): void
    {
        $this->assertSame('local', config('filesystems.imagens'));
    }

    /** R2-01: o disco r2 monta um adaptador S3 privado com prefixo e endpoint da conta, sem conectar. */
    public function test_disco_r2_monta_adaptador_s3_com_prefixo_e_endpoint(): void
    {
        config([
            'filesystems.disks.r2.key' => 'chave-falsa',
            'filesystems.disks.r2.secret' => 'segredo-falso',
            'filesystems.disks.r2.bucket' => 'ecf-imagens',
            'filesystems.disks.r2.endpoint' => 'https://conta.r2.cloudflarestorage.com',
            'filesystems.disks.r2.root' => 'production',
        ]);

        $disco = Storage::build(config('filesystems.disks.r2'));

        $this->assertInstanceOf(AwsS3V3Adapter::class, $disco);
        $this->assertSame('https://conta.r2.cloudflarestorage.com', (string) $disco->getClient()->getEndpoint());
        $this->assertSame('auto', $disco->getClient()->getRegion());
        $this->assertSame('production', config('filesystems.disks.r2.root'));
        $this->assertSame('private', config('filesystems.disks.r2.visibility'));
        $this->assertTrue(config('filesystems.disks.r2.throw'));
    }

    /** R2-03: ida e volta completa no disco configurado, sem deixar o objeto de teste para trás. */
    public function test_comando_grava_le_confere_e_apaga(): void
    {
        Storage::fake('local');

        $this->artisan('imagens:testar-conexao')
            ->expectsOutputToContain('Conexão OK com o disco [local]')
            ->assertSuccessful();

        $this->assertSame([], Storage::disk('local')->allFiles('_teste-conexao'));
    }

    /** R2-03: falha de gravação vira FAILURE com o passo que falhou, e o segredo nunca aparece. */
    public function test_comando_falha_sem_vazar_segredo(): void
    {
        config(['filesystems.disks.r2.secret' => 'SEGREDO-QUE-NAO-PODE-VAZAR']);

        $quebrado = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $quebrado->shouldReceive('put')->andThrow(new \RuntimeException('Could not connect to R2'));
        Storage::set('r2', $quebrado);

        $this->artisan('imagens:testar-conexao', ['--disco' => 'r2'])
            ->expectsOutputToContain('falhou ao gravar')
            ->doesntExpectOutputToContain('SEGREDO-QUE-NAO-PODE-VAZAR')
            ->assertFailed();
    }

    public function test_comando_recusa_disco_inexistente(): void
    {
        $this->artisan('imagens:testar-conexao', ['--disco' => 'nao-existe'])
            ->expectsOutputToContain('não existe')
            ->assertFailed();
    }
}
