<?php

namespace Tests\Fixtures;

/**
 * Fase 126 Plan 126-01 (D-15) — payloads do sandbox Clicksign v3, cópia
 * ANONIMIZADA das respostas reais registradas em
 * `.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md`.
 *
 * Regra de anonimização (achado WR-07 do review da Fase 125 — a primeira
 * versão de uma fixture deste projeto já vazou IP público real e chave real
 * de signatário para o histórico do git):
 *   - todo e-mail termina em `@example.com`;
 *   - todo IP fica na faixa de documentação RFC 5737 (`203.0.113.0/24`);
 *   - todo identificador é o UUID sintético `00000000-0000-4000-8000-0000000000NN`.
 *
 * Payloads de ERRO são cópia LITERAL do arquivo empírico — não são invenção
 * do dev (D-15: um mock inventado já mascarou um bug real neste projeto, o
 * caso `toObjectId` do HubSpot). Campos sem registro literal no empírico
 * estão marcados **NÃO MEDIDO** no docblock do método correspondente.
 */
class ClicksignSandboxFixtures
{
    /**
     * POST /envelopes — resposta de sucesso.
     * Defaults conforme CLICKSIGN-SANDBOX-EMPIRICO.md §5 (medido): status
     * `draft`, `remind_interval: 3`, `deadline_partial_signature_action:
     * "closed"`, `rubric_enabled: true`.
     *
     * @return array<string, mixed>
     */
    public static function envelopeCriado(): array
    {
        return [
            'data' => [
                'id'   => '00000000-0000-4000-8000-000000000001',
                'type' => 'envelopes',
                'attributes' => [
                    'name'                               => 'Contrato de teste — ECF Admin',
                    'locale'                              => 'pt-BR',
                    'auto_close'                          => true,
                    'remind_interval'                     => 3,
                    'block_after_refusal'                 => true,
                    'deadline_partial_signature_action'   => 'closed',
                    'rubric_enabled'                      => true,
                    'status'                               => 'draft',
                    'deadline_at'                         => '2026-09-09T23:59:59.000-03:00',
                    'created_at'                          => '2026-08-10T10:00:00.000-03:00',
                    'updated_at'                          => '2026-08-10T10:00:00.000-03:00',
                ],
            ],
        ];
    }

    /**
     * POST /envelopes/{id}/documents — resposta de sucesso.
     *
     * @return array<string, mixed>
     */
    public static function documentoCriado(): array
    {
        return [
            'data' => [
                'id'   => '00000000-0000-4000-8000-000000000002',
                'type' => 'documents',
                'attributes' => [
                    'filename'   => 'contrato-teste.pdf',
                    'status'     => 'draft',
                    'created_at' => '2026-08-10T10:01:00.000-03:00',
                    'updated_at' => '2026-08-10T10:01:00.000-03:00',
                ],
            ],
        ];
    }

    /**
     * POST /envelopes/{id}/signers — resposta de sucesso.
     * Nome e e-mail são fictícios (D-15), fora do escopo do que o empírico
     * mediu de fato — só a FORMA do recurso é o que importa aqui.
     *
     * @return array<string, mixed>
     */
    public static function signatarioCriado(): array
    {
        return [
            'data' => [
                'id'   => '00000000-0000-4000-8000-000000000003',
                'type' => 'signers',
                'attributes' => [
                    'name'              => 'Signatário de Teste',
                    'email'             => 'signatario@example.com',
                    'has_documentation' => false,
                    'documentation'     => null,
                    'birthday'          => null,
                    'phone_number'      => null,
                    'created_at'        => '2026-08-10T10:02:00.000-03:00',
                    'updated_at'        => '2026-08-10T10:02:00.000-03:00',
                ],
            ],
        ];
    }

    /**
     * POST /envelopes/{id}/requirements — resposta de sucesso.
     * Vocabulário `action`/`role`/`auth` conforme
     * CLICKSIGN-SANDBOX-EMPIRICO.md §6 (medido).
     *
     * @return array<string, mixed>
     */
    public static function requisitoCriado(): array
    {
        return [
            'data' => [
                'id'   => '00000000-0000-4000-8000-000000000004',
                'type' => 'requirements',
                'attributes' => [
                    'action'     => 'agree',
                    'role'       => 'sign',
                    'auth'       => null,
                    'created_at' => '2026-08-10T10:03:00.000-03:00',
                    'updated_at' => '2026-08-10T10:03:00.000-03:00',
                ],
                'relationships' => [
                    'document' => ['data' => ['id' => '00000000-0000-4000-8000-000000000002', 'type' => 'documents']],
                    'signer'   => ['data' => ['id' => '00000000-0000-4000-8000-000000000003', 'type' => 'signers']],
                ],
            ],
        ];
    }

    /**
     * PATCH /envelopes/{id} (ativação) — resposta de sucesso, `status: running`.
     *
     * @return array<string, mixed>
     */
    public static function envelopeAtivado(): array
    {
        $envelope = self::envelopeCriado();
        $envelope['data']['attributes']['status']     = 'running';
        $envelope['data']['attributes']['updated_at'] = '2026-08-10T10:04:00.000-03:00';

        return $envelope;
    }

    /**
     * PATCH /envelopes/{id} (cancelamento) — resposta de sucesso.
     * ⚠️ **NÃO MEDIDO:** o arquivo empírico não registrou literalmente o
     * valor de `status` devolvido após um cancelamento (D-12 desta fase é
     * quem introduz o cancelamento — a sessão de sandbox não cobriu esse
     * caminho). `"canceled"` é a suposição mais provável dado o vocabulário
     * medido (`draft`/`running`/`closed`), mas não é fato observado.
     *
     * @return array<string, mixed>
     */
    public static function envelopeCancelado(): array
    {
        $envelope = self::envelopeCriado();
        $envelope['data']['attributes']['status']     = 'canceled'; // NÃO MEDIDO
        $envelope['data']['attributes']['updated_at'] = '2026-08-10T10:05:00.000-03:00';

        return $envelope;
    }

    /**
     * GET /envelopes/{id}/documents/{id}/events — evento `name: "sign"`,
     * cópia da forma do bloco `data.signer` de
     * CLICKSIGN-SANDBOX-EMPIRICO.md §3 (medido), com `address` na faixa
     * RFC 5737 e chave/e-mail sintéticos (D-15 — o IP e a chave reais desta
     * seção já vazaram uma vez para o git, achado WR-07).
     *
     * @return array<string, mixed>
     */
    public static function eventoSignDoDocumento(): array
    {
        return [
            'data' => [
                'id'   => '00000000-0000-4000-8000-000000000005',
                'type' => 'events',
                'attributes' => [
                    'name'    => 'sign',
                    'created' => '2026-08-10T12:00:00.000-03:00',
                    'data'    => [
                        'signer' => [
                            'sign_as'                   => 'contractor',
                            'key'                       => '00000000-0000-4000-8000-000000000003',
                            'email'                     => 'signatario@example.com',
                            'name'                      => 'Signatário de Teste',
                            'auths'                     => ['email'],
                            'address'                   => '203.0.113.10',
                            'latitude'                  => null,
                            'longitude'                 => null,
                            'selfie_enabled'            => false,
                            'handwritten_enabled'       => false,
                            'official_document_enabled' => false,
                            'liveness_enabled'          => false,
                            'facial_biometrics_enabled' => false,
                            'federal_data_validation'   => null,
                            'documentation'             => null,
                            'has_documentation'         => false,
                            'phone_number'              => null,
                            'phone_number_hash'         => null,
                            'communicate_by'            => 'email',
                            'url'                       => 'https://sandbox.clicksign.com/notarial/widget/signatures/00000000-0000-4000-8000-000000000003/redirect',
                        ],
                        // NÃO MEDIDO: `log_version` é reproduzido pelo valor
                        // exemplificado no empírico; `secret_hmac`/`account`
                        // não tiveram valor real anotado (só a existência do
                        // campo), por isso ficam com placeholders sintéticos.
                        'log_version'  => '1.1495.0',
                        'secret_hmac'  => null, // NÃO MEDIDO
                        'account'      => [
                            'key'                                 => '00000000-0000-4000-8000-000000000006',
                            'timestamp_signature_functionality'    => null, // NÃO MEDIDO
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * POST /envelopes/{id}/documents — erro 400, cópia LITERAL de
     * CLICKSIGN-SANDBOX-EMPIRICO.md §2: enviar `content_base64` como base64
     * puro (não Data URI) devolve exatamente este corpo. A doc oficial da
     * Clicksign está errada nesse ponto — este é o comportamento real.
     *
     * @return array<string, mixed>
     */
    public static function erroContentBase64NaoDataUri(): array
    {
        return [
            'errors' => [
                [
                    'code'   => 'bad_request',
                    'status' => 400,
                    'source' => ['pointer' => '/data/attributes/content_base64'],
                    'detail' => 'content_base64 Formatação do campo inválida. O valor deve ser um Data URI completo.',
                ],
            ],
        ];
    }

    /**
     * Erro 401 — cópia literal do `detail` registrado em
     * CLICKSIGN-SANDBOX-EMPIRICO.md §1 ("Access Token inválido").
     * ⚠️ **NÃO MEDIDO:** o `code` (`unauthorized`) não foi anotado
     * literalmente no empírico — só o `detail` e o status HTTP 401 foram.
     *
     * @return array<string, mixed>
     */
    public static function erro401TokenInvalido(): array
    {
        return [
            'errors' => [
                [
                    'code'   => 'unauthorized', // NÃO MEDIDO
                    'status' => 401,
                    'detail' => 'Access Token inválido',
                ],
            ],
        ];
    }

    /**
     * Erro 403 — cópia literal do `detail` registrado em
     * CLICKSIGN-SANDBOX-EMPIRICO.md §1 ("E-mail do usuário da API não
     * configurado"). ⚠️ **NÃO MEDIDO:** o `code` (`forbidden`) não foi
     * anotado literalmente — só o `detail` e o status HTTP 403 foram.
     *
     * @return array<string, mixed>
     */
    public static function erro403EmailApiNaoConfigurado(): array
    {
        return [
            'errors' => [
                [
                    'code'   => 'forbidden', // NÃO MEDIDO
                    'status' => 403,
                    'detail' => 'E-mail do usuário da API não configurado',
                ],
            ],
        ];
    }

    /**
     * POST /envelopes/{id}/signers/{id}/notifications — erro 429.
     * CLICKSIGN-SANDBOX-EMPIRICO.md §7 (medido): a resposta é **texto puro**
     * ("Too many requests"), não JSON:API — por isso o corpo aqui vem
     * embrulhado em `body`, e não em `errors[]` como os demais.
     *
     * @return array<string, mixed>
     */
    public static function erro429NotificacaoTexto(): array
    {
        return [
            'body' => 'Too many requests',
        ];
    }
}
