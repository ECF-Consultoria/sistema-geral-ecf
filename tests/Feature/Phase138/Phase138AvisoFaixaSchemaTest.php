<?php

namespace Tests\Feature\Phase138;

use App\Models\Company;
use App\Models\FechamentoSnapshot;
use App\Notifications\Categoria;
use App\Notifications\FaixaAlteradaNotification;
use App\Services\Fechamento\FechamentoSnapshotWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 138 Plano 02 — trava de schema e de idempotência do aviso de mudança
 * de faixa (D-02 + D-03). Nenhuma lógica de DISPARO é testada aqui — quem
 * decide se avisa é o plano 05. Este teste só prova que a infraestrutura
 * (colunas + categoria + Notification + rótulo na UI) está no lugar e que a
 * premissa central de D-03 é verdadeira: reconsolidar não apaga a marca de
 * "já avisei".
 */
class Phase138AvisoFaixaSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function as_quatro_colunas_existem_nas_duas_tabelas_e_aceitam_null(): void
    {
        $this->assertTrue(Schema::hasColumns('fechamento_snapshots', [
            'notificado_em', 'notificado_faixa_ordem',
        ]));
        $this->assertTrue(Schema::hasColumns('fechamento_grupo_snapshots', [
            'notificado_em', 'notificado_faixa_ordem',
        ]));

        $company = Company::create(['name' => 'Empresa Aviso Faixa', 'cnpj' => '90000000000101', 'active' => true]);

        $snapshot = FechamentoSnapshot::create([
            'company_id'     => $company->id,
            'mes_referencia' => Carbon::now()->startOfMonth()->toDateString(),
            'estado'         => FechamentoSnapshot::ESTADO_OK,
            'origem'         => FechamentoSnapshot::ORIGEM_CONSOLIDAR_MES,
            'gerado_em'      => now(),
        ]);

        $this->assertNull($snapshot->fresh()->notificado_em);
        $this->assertNull($snapshot->fresh()->notificado_faixa_ordem);
    }

    /**
     * A prova central de D-03: `FechamentoSnapshotWriter::sync()` grava com
     * `$existente->fill($dados)->save()`, e `$dados` vem do payload montado
     * por `ConsolidarMesFechamento`, que NUNCA inclui `notificado_em`/
     * `notificado_faixa_ordem`. Uma reconsolidação (mesma competência, com
     * motivo) precisa preservar as duas colunas — é essa a trava que impede
     * o "Refazer fechamento" de apagar a marca de "já avisei".
     */
    #[Test]
    public function reconsolidar_a_mesma_competencia_preserva_a_marca_de_ja_avisei(): void
    {
        $company = Company::create(['name' => 'Empresa Reconsolidacao Aviso', 'cnpj' => '90000000000102', 'active' => true]);
        $mes     = Carbon::now()->startOfMonth();

        $writer = app(FechamentoSnapshotWriter::class);

        $linhaOriginal = [
            'company_id'   => $company->id,
            'company_name' => $company->name,
            'faixa_ordem'  => 2,
            'estado'       => FechamentoSnapshot::ESTADO_OK,
        ];

        // Primeira consolidação — sem motivo, congela a competência.
        $writer->sync($mes, [$linhaOriginal], [], FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES);

        $snapshot = FechamentoSnapshot::query()
            ->where('company_id', $company->id)
            ->whereDate('mes_referencia', $mes->toDateString())
            ->firstOrFail();

        // Marca "já avisei" gravada pelo notificador (fora do escopo deste
        // plano) — simulada aqui direto no banco.
        $avisadoEm = now();
        $snapshot->forceFill([
            'notificado_em'          => $avisadoEm,
            'notificado_faixa_ordem' => 2,
        ])->save();

        // "Refazer fechamento" — mesma competência, faixa não mudou no
        // payload, mas precisa de motivo porque a competência já está
        // congelada (trava de D-12 revisado da Fase 137).
        $writer->sync(
            $mes,
            [$linhaOriginal],
            [],
            FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES,
            null,
            'Refazer fechamento — reconsolidação de teste (Fase 138)'
        );

        // Reconsulta ao banco, nunca confiança em stdout/contador em memória
        // (disciplina registrada em .planning/learnings/desempenho-bonificacao.md §4).
        $recarregado = FechamentoSnapshot::query()
            ->where('company_id', $company->id)
            ->whereDate('mes_referencia', $mes->toDateString())
            ->firstOrFail();

        $this->assertNotNull($recarregado->notificado_em, 'A reconsolidação apagou notificado_em — a idempotência de D-03 quebrou.');
        $this->assertSame(2, $recarregado->notificado_faixa_ordem, 'A reconsolidação apagou notificado_faixa_ordem — a idempotência de D-03 quebrou.');
        $this->assertEqualsWithDelta($avisadoEm->timestamp, $recarregado->notificado_em->timestamp, 2, 'notificado_em não deveria mudar numa reconsolidação que o notificador não tocou.');
    }

    #[Test]
    public function faixa_alterada_notification_produz_payload_canonico_de_seis_chaves(): void
    {
        $notification = new FaixaAlteradaNotification(
            'Empresa X mudou de faixa',
            'A empresa X passou da faixa 2 para a faixa 3.',
            ['company_id' => 42]
        );

        $payload = $notification->toArray(new \stdClass);

        $this->assertSame([
            'titulo', 'mensagem', 'categoria', 'autor_user_id', 'url', 'meta',
        ], array_keys($payload));
        $this->assertSame(Categoria::FAIXA_ALTERADA->value, $payload['categoria']);
        $this->assertSame('faixa_alterada', $payload['categoria']);
        $this->assertNull($payload['autor_user_id']);
        $this->assertNull($payload['url']);
        $this->assertSame(['company_id' => 42], $payload['meta']);
    }

    /**
     * Trava de ARQUIVO (mesma técnica de `Phase137FinanceiroUiContratoTest`)
     * — o projeto não roda nenhum test runner de JS, então esta é a única
     * defesa barata contra "categoria sem rótulo", que renderia como
     * "Manual" em silêncio na tela de notificações.
     */
    #[Test]
    public function notificacoes_index_jsx_tem_o_rotulo_de_mudanca_de_faixa(): void
    {
        $conteudo = file_get_contents(resource_path('js/Pages/Notificacoes/Index.jsx'));

        $this->assertStringContainsString('faixa_alterada', $conteudo);
        $this->assertStringContainsString('Mudança de faixa', $conteudo);
    }
}
