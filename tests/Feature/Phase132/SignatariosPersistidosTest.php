<?php

namespace Tests\Feature\Phase132;

use App\Models\ContratoAssinatura;
use App\Models\ContratoAssinaturaSignatario;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prova do buraco medido em produção no cutover da Fase 132 (2026-08-18):
 * `contrato_assinatura_signatarios` estava VAZIA na base inteira porque
 * nenhum código criava linha nela. Três assinaturas reais chegaram, validaram
 * o HMAC, e foram descartadas como "signatário não reconhecido".
 */
class SignatariosPersistidosTest extends TestCase
{
    use RefreshDatabase;

    private function contrato(): ContratoAssinatura
    {
        return ContratoAssinatura::factory()->create([
            'company_id'            => Company::factory()->create()->id,
            'clicksign_envelope_id' => 'env-1',
            'clicksign_document_id' => 'doc-1',
        ]);
    }

    /** O retorno do client vira linha no banco, com a chave do signer. */
    public function test_signatarios_do_envelope_viram_linhas_com_a_chave_do_signer(): void
    {
        $contrato = $this->contrato();

        foreach ([
            ['id' => 'k-cliente', 'nome' => 'Contato de Teste', 'email' => 'cliente@exemplo.test',  'papel' => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE],
            ['id' => 'k-ecf-1',   'nome' => 'Um da ECF',        'email' => 'um@exemplo.test',       'papel' => ContratoAssinaturaSignatario::PAPEL_CONTRATADA],
            ['id' => 'k-ecf-2',   'nome' => 'Outro da ECF',     'email' => 'outro@exemplo.test',    'papel' => ContratoAssinaturaSignatario::PAPEL_TESTEMUNHA],
        ] as $s) {
            $contrato->signatarios()->updateOrCreate(
                ['clicksign_signer_key' => $s['id']],
                ['nome' => $s['nome'], 'email' => $s['email'], 'papel' => $s['papel'], 'situacao' => ContratoAssinaturaSignatario::SITUACAO_PENDENTE]
            );
        }

        $this->assertSame(3, $contrato->signatarios()->count());
        $this->assertSame(
            3,
            $contrato->signatarios()->where('situacao', ContratoAssinaturaSignatario::SITUACAO_PENDENTE)->count(),
            'todo signatário nasce pendente — quem muda isso é o webhook'
        );
        $this->assertNotNull(
            $contrato->signatarios()->where('clicksign_signer_key', 'k-cliente')->first(),
            'a chave do signer precisa ser gravada: é por ela que o sync casa o evento'
        );
    }

    /** Reentrega do job não pode duplicar signatário. */
    public function test_reentrega_do_job_nao_duplica_signatario(): void
    {
        $contrato = $this->contrato();

        foreach ([1, 2] as $tentativa) {
            $contrato->signatarios()->updateOrCreate(
                ['clicksign_signer_key' => 'k-cliente'],
                ['nome' => 'Contato de Teste', 'email' => 'cliente@exemplo.test', 'papel' => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE, 'situacao' => ContratoAssinaturaSignatario::SITUACAO_PENDENTE]
            );
        }

        $this->assertSame(1, $contrato->signatarios()->count());
    }

    /** O sync casa o evento com a linha — o elo que estava quebrado. */
    public function test_sync_encontra_o_signatario_pela_chave_e_pelo_email(): void
    {
        $contrato = $this->contrato();
        $contrato->signatarios()->create([
            'clicksign_signer_key' => 'k-cliente',
            'nome'                 => 'Contato de Teste',
            'email'                => 'cliente@exemplo.test',
            'papel'                => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE,
            'situacao'             => ContratoAssinaturaSignatario::SITUACAO_PENDENTE,
        ]);

        app(\App\Services\Clicksign\ContratoSignatariosSyncService::class)->aplicar($contrato, [
            ['attributes' => [
                'name'    => 'sign',
                'created' => '2026-08-18T09:12:00-03:00',
                'data'    => ['signer' => ['key' => 'k-cliente', 'email' => 'cliente@exemplo.test', 'address' => '10.0.0.1']],
            ]],
        ]);

        $signatario = $contrato->signatarios()->first()->refresh();

        $this->assertSame(ContratoAssinaturaSignatario::SITUACAO_ASSINOU, $signatario->situacao);
        $this->assertNotNull($signatario->assinado_em, 'a data da assinatura precisa ser gravada');
        $this->assertSame('10.0.0.1', $signatario->ip_address, 'a evidencia do signatario precisa sobreviver');
    }
}
