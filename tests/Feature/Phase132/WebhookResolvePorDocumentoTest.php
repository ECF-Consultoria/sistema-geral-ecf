<?php

namespace Tests\Feature\Phase132;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prova da correção medida no cutover da Fase 132 (2026-08-18).
 *
 * O corpo real do webhook da Clicksign é baseado em DOCUMENTO (`document.key`),
 * mas a resolução do contrato comparava com a coluna do ENVELOPE. Três
 * assinaturas reais chegaram em produção com HMAC válido e foram todas
 * descartadas; nenhuma empresa seria liberada.
 */
class WebhookResolvePorDocumentoTest extends TestCase
{
    use RefreshDatabase;

    private function contrato(string $envelope, ?string $documento): ContratoAssinatura
    {
        return ContratoAssinatura::factory()->create([
            'company_id'             => Company::factory()->create()->id,
            'clicksign_envelope_id'  => $envelope,
            'clicksign_document_id'  => $documento,
        ]);
    }

    /** O caso que quebrou em produção: o id que chega é o do documento. */
    public function test_resolve_o_contrato_pela_chave_do_documento(): void
    {
        $contrato = $this->contrato('5d2458b6-envelope', '2927d917-documento');

        $achado = ContratoAssinatura::resolverPorReferenciaClicksign('2927d917-documento');

        $this->assertNotNull($achado, 'evento com a chave do documento precisa achar o contrato');
        $this->assertSame($contrato->id, $achado->id);
    }

    /** Não-regressão: quem já casava pelo envelope continua casando. */
    public function test_continua_resolvendo_pela_chave_do_envelope(): void
    {
        $contrato = $this->contrato('5d2458b6-envelope', '2927d917-documento');

        $achado = ContratoAssinatura::resolverPorReferenciaClicksign('5d2458b6-envelope');

        $this->assertNotNull($achado);
        $this->assertSame($contrato->id, $achado->id);
    }

    /** Contrato sem document_id não pode quebrar a resolução por envelope. */
    public function test_contrato_sem_document_id_ainda_resolve_por_envelope(): void
    {
        $contrato = $this->contrato('so-envelope', null);

        $this->assertSame(
            $contrato->id,
            ContratoAssinatura::resolverPorReferenciaClicksign('so-envelope')?->id
        );
    }

    /** Referência desconhecida continua sendo ignorada, sem estourar. */
    public function test_referencia_desconhecida_devolve_nulo(): void
    {
        $this->contrato('5d2458b6-envelope', '2927d917-documento');

        $this->assertNull(ContratoAssinatura::resolverPorReferenciaClicksign('nao-existe'));
    }

    /** Referência vazia ou nula não vira uma varredura na tabela. */
    public function test_referencia_vazia_devolve_nulo_sem_consultar(): void
    {
        $this->contrato('5d2458b6-envelope', '2927d917-documento');

        $this->assertNull(ContratoAssinatura::resolverPorReferenciaClicksign(null));
        $this->assertNull(ContratoAssinatura::resolverPorReferenciaClicksign(''));
    }

    /** O envelope tem precedência: é o identificador canônico do nosso lado. */
    public function test_envelope_tem_precedencia_sobre_documento(): void
    {
        $porEnvelope = $this->contrato('chave-repetida', 'documento-a');
        $porDocumento = $this->contrato('envelope-b', 'chave-repetida');

        $achado = ContratoAssinatura::resolverPorReferenciaClicksign('chave-repetida');

        $this->assertSame(
            $porEnvelope->id,
            $achado?->id,
            'com a mesma chave nas duas colunas, o casamento por envelope vence'
        );
        $this->assertNotSame($porDocumento->id, $achado?->id);
    }
}
