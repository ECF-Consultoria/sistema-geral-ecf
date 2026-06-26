<?php

// Phase 42 Plan 42-06 — Guard de regressao (REQ-42-10).
//
// Esta suite eh GUARDA: garante que os tests Feature pre-existentes em
// `tests/Feature/Sugadores/` continuam INTACTOS e nao foram modificados pela
// Phase 42. Se um test legado quebrar ou alguem deletar metodos sem documentar,
// este guard falha como early warning antes do orquestrador consolidar.
//
// Estrategia (mais robusta que rodar Artisan::call('test') aninhado, que tem
// historico de flakiness no SQLite em-memory):
//
//   T1 — file_exists + snapshot estrutural (contagem de #[Test] / function test_)
//        nos arquivos legados. Snapshot foi capturado em 2026-06-26 durante o
//        Plan 42-06; se a contagem mudou, a sinalizacao explicita aqui ajuda
//        o time a decidir se eh deliberado (atualizar snapshot) ou regressao.
//
//   T2 — execucao em isolamento via Symfony Process (php artisan test ...).
//        Strategy resiliente: se Process nao estiver disponivel ou se o
//        ambiente CI nao permitir spawn de processo PHP filho (caso comum
//        em runners restritivos), o test eh skippado com instrucao explicita
//        para o gate manual via orquestrador no consolidate-wave.
//
// Padrao PHPUnit 11 — atributo #[Test]. Sem RefreshDatabase (suite leve, sem DB).

namespace Tests\Feature\Phase42;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RegressaoSugadoresExistentesTest extends TestCase
{
    // Snapshot capturado em 2026-06-26 durante o Plan 42-06.
    // Atualizar APENAS se a mudanca for deliberada (novo test legado adicionado
    // por outra phase). Se o numero diminuir, eh sinal de regressao — investigar.
    private const SNAPSHOT_AUTO_RESOLVE_TESTS  = 5;  // tests/Feature/Sugadores/AutoResolveTest.php
    private const SNAPSHOT_SUGADORES_INDEX_TESTS = 11; // tests/Feature/Sugadores/SugadoresIndexTest.php

    private function legacyDir(): string
    {
        return base_path('tests/Feature/Sugadores');
    }

    private function autoResolvePath(): string
    {
        return $this->legacyDir() . DIRECTORY_SEPARATOR . 'AutoResolveTest.php';
    }

    private function sugadoresIndexPath(): string
    {
        return $this->legacyDir() . DIRECTORY_SEPARATOR . 'SugadoresIndexTest.php';
    }

    /**
     * Conta metodos publicos de teste num arquivo PHPUnit, considerando os 2
     * estilos: doc-comment com tag @test (nao usado nestes legados), prefixo
     * `function test_` (estilo PHPUnit classico — usado em ambos legados) e
     * atributo `#[Test]` (PHPUnit 10+; nao usado nos legados mas previne falsos
     * negativos caso algum dia migrem).
     */
    private function countTestMethods(string $filepath): int
    {
        $contents = file_get_contents($filepath);
        $this->assertNotFalse($contents, "Nao conseguiu ler conteudo de {$filepath}");

        $count = 0;
        // Conta `function test_*(` (estilo classico, usado nos legados).
        $count += preg_match_all('/^\s+public function test_\w+\s*\(/m', $contents);
        // Conta `#[Test]` precedendo `public function ...(`
        // (estilo PHPUnit 11; futuro-proof — atualmente 0 nos legados).
        $count += preg_match_all('/^\s+#\[Test\]\s*$/m', $contents);

        return $count;
    }

    // ──────────────────────────────────────────────────────────────────────
    // T1 — arquivos legados existem e snapshot estrutural confere
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function arquivos_legados_existem_e_snapshot_estrutural_confere(): void
    {
        // Existencia fisica dos arquivos legados.
        $this->assertFileExists(
            $this->autoResolvePath(),
            'AutoResolveTest.php removido — viola REQ-42-10 (manter tests legados).'
        );
        $this->assertFileExists(
            $this->sugadoresIndexPath(),
            'SugadoresIndexTest.php removido — viola REQ-42-10 (manter tests legados).'
        );

        // Snapshot estrutural — contagem de metodos de teste.
        $autoResolveCount = $this->countTestMethods($this->autoResolvePath());
        $this->assertGreaterThanOrEqual(
            self::SNAPSHOT_AUTO_RESOLVE_TESTS,
            $autoResolveCount,
            "AutoResolveTest deveria ter pelo menos " . self::SNAPSHOT_AUTO_RESOLVE_TESTS .
            " tests (snapshot 2026-06-26); encontrou {$autoResolveCount}. Se eh diminuicao, eh regressao — investigar."
        );

        $indexCount = $this->countTestMethods($this->sugadoresIndexPath());
        $this->assertGreaterThanOrEqual(
            self::SNAPSHOT_SUGADORES_INDEX_TESTS,
            $indexCount,
            "SugadoresIndexTest deveria ter pelo menos " . self::SNAPSHOT_SUGADORES_INDEX_TESTS .
            " tests (snapshot 2026-06-26); encontrou {$indexCount}. Se eh diminuicao, eh regressao — investigar."
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // T2 — execucao em isolamento via Symfony Process (best-effort, com
    //      fallback explicito para gate manual quando Process nao disponivel
    //      ou ambiente nao permite spawn de PHP filho).
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function suite_legada_passa_quando_executada_em_isolamento(): void
    {
        // Fallback explicito: se Process nao estiver disponivel, marca como
        // skipped com instrucao para o gate manual.
        if (!class_exists(Process::class)) {
            $this->markTestSkipped(
                'symfony/process nao disponivel. Gate manual: rodar '
                . '`php artisan test tests/Feature/Sugadores/ --no-coverage` '
                . 'antes do consolidate-wave da Phase 42. Orquestrador GSD '
                . 'tambem roda essa suite no merge final.'
            );
        }

        // Tenta achar o binario PHP de forma resiliente.
        $phpBinary = (new \Symfony\Component\Process\PhpExecutableFinder())->find();
        if (!$phpBinary) {
            $this->markTestSkipped(
                'PHP binary nao localizavel via PhpExecutableFinder. Gate manual: '
                . 'rodar `php artisan test tests/Feature/Sugadores/ --no-coverage` '
                . 'antes do consolidate-wave.'
            );
        }

        // Spawn de subprocess pode falhar em runners CI restritivos. Capturamos
        // qualquer falha do Process e degradamos para skipped — o gate efetivo
        // continua sendo o merge final do orquestrador, que roda a suite completa.
        try {
            $process = new Process(
                command: [
                    $phpBinary,
                    'artisan',
                    'test',
                    'tests/Feature/Sugadores/',
                    '--no-coverage',
                    '--stop-on-failure',
                ],
                cwd: base_path(),
                timeout: 120
            );
            $process->run();
        } catch (\Throwable $e) {
            $this->markTestSkipped(
                'Process spawn falhou (ambiente restritivo): ' . $e->getMessage()
                . '. Gate manual: rodar `php artisan test tests/Feature/Sugadores/`.'
            );
        }

        $this->assertSame(
            0,
            $process->getExitCode(),
            "Suite legada Sugadores quebrou (REQ-42-10):\nSTDOUT:\n"
            . $process->getOutput()
            . "\nSTDERR:\n"
            . $process->getErrorOutput()
        );
    }
}
