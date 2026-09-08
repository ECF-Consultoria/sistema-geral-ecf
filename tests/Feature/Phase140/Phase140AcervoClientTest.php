<?php

namespace Tests\Feature\Phase140;

use App\Exceptions\ClicksignException;
use App\Services\Clicksign\ClicksignClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 140 Plan 140-01 (TAB-01) — cobre os dois métodos novos do
 * `ClicksignClient` que a Fase 140 precisa: listar envelopes (paginado) e
 * listar documentos de um envelope. Nenhum teste aqui chama a rede de
 * verdade — `Http::fake()` em todos, mesmo padrão de `ClicksignClientModeloTest`.
 * O acervo real (429 envelopes, 123 de gestão de ADS) está MEDIDO em
 * `140-CONTEXT.md` (D-01/D-02) — este teste não remede, só prova o contrato
 * dos dois métodos novos.
 */
class Phase140AcervoClientTest extends TestCase
{
    private const TOKEN = 'token-clicksign-falso';
    private const BASE  = 'https://sandbox.clicksign.com/api/v3';

    private const ENVELOPE_ID = '00000000-0000-4000-8000-000000000001';
    private const DOCUMENT_ID = '00000000-0000-4000-8000-000000000002';

    private function client(): ClicksignClient
    {
        return new ClicksignClient(token: self::TOKEN, baseUrl: self::BASE);
    }

    // ─── listarEnvelopes() ───

    #[Test]
    public function listar_envelopes_manda_page_number_e_page_size_e_devolve_lista_desembrulhada(): void
    {
        Http::fake([
            self::BASE . '/envelopes*' => Http::response([
                'data' => [
                    [
                        'id'         => self::ENVELOPE_ID,
                        'type'       => 'envelopes',
                        'attributes' => [
                            'name'   => 'Contrato Gestao de Ads ECF - Empresa Teste',
                            'status' => 'closed',
                        ],
                    ],
                ],
                // 'enviar()' descarta 'meta'/'links' de propósito — a
                // paginação não pode contar com este número.
                'meta' => ['record_count' => 429],
            ], 200),
        ]);

        $envelopes = $this->client()->listarEnvelopes(pagina: 2, porPagina: 100);

        Http::assertSent(function ($request) {
            $this->assertSame('GET', $request->method());

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            $this->assertSame('2', $query['page']['number'] ?? null);
            $this->assertSame('100', $query['page']['size'] ?? null);

            return true;
        });

        $this->assertIsArray($envelopes);
        $this->assertSame(self::ENVELOPE_ID, $envelopes[0]['id']);
    }

    #[Test]
    public function listar_envelopes_pagina_vazia_devolve_array_vazio_sem_lancar(): void
    {
        Http::fake([
            self::BASE . '/envelopes*' => Http::response(['data' => []], 200),
        ]);

        $envelopes = $this->client()->listarEnvelopes(pagina: 5, porPagina: 100);

        $this->assertSame([], $envelopes);
    }

    #[Test]
    public function listar_envelopes_com_erro_4xx_lanca_clicksign_exception_pelo_caminho_comum(): void
    {
        Http::fake([
            self::BASE . '/envelopes*' => Http::response(
                ['errors' => [['code' => 'unauthorized', 'status' => 401]]],
                401
            ),
        ]);

        try {
            $this->client()->listarEnvelopes();
            $this->fail('Esperava ClicksignException por 401.');
        } catch (ClicksignException $e) {
            $this->assertSame(401, $e->httpStatus);
        }
    }

    // ─── listarDocumentos() ───

    #[Test]
    public function listar_documentos_faz_get_em_envelopes_id_documents_e_devolve_lista(): void
    {
        Http::fake([
            self::BASE . '/envelopes/*/documents' => Http::response([
                'data' => [
                    [
                        'id'         => self::DOCUMENT_ID,
                        'type'       => 'documents',
                        'attributes' => [
                            'filename' => 'contrato.pdf',
                        ],
                        'links' => [
                            'files' => [
                                'original' => 'https://s3.example.com/original?X-Amz-Expires=299',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $documentos = $this->client()->listarDocumentos(self::ENVELOPE_ID);

        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && $request->url() === self::BASE . '/envelopes/' . self::ENVELOPE_ID . '/documents';
        });

        $this->assertSame(self::DOCUMENT_ID, $documentos[0]['id']);
    }

    #[Test]
    public function listar_documentos_com_erro_4xx_lanca_clicksign_exception(): void
    {
        Http::fake([
            self::BASE . '/envelopes/*/documents' => Http::response(
                ['errors' => [['code' => 'not_found', 'status' => 404]]],
                404
            ),
        ]);

        try {
            $this->client()->listarDocumentos(self::ENVELOPE_ID);
            $this->fail('Esperava ClicksignException por 404.');
        } catch (ClicksignException $e) {
            $this->assertSame(404, $e->httpStatus);
        }
    }
}
