<?php

namespace App\Services\Clicksign;

use App\Exceptions\ClicksignException;
use App\Models\ContratoAssinaturaSignatario;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fase 126 Plan 126-01 (CLICK-01) — client HTTP para a API Clicksign v3
 * (conceito de Envelope). A Fase 126-01 cobriu a fundação — headers, retry,
 * log seguro — exercitada por `criarEnvelope()` + `anexarDocumento()`. Este
 * plano (126-02) acrescenta signatário, requisito, ativação, consulta,
 * notificação e cancelamento sobre o mesmo núcleo privado `enviar()` — como
 * ele nasceu seguro, os demais métodos herdam a garantia — mais o método
 * composto `montarEnvelope()`, que executa a sequência inteira com rollback
 * (D-12) se algo falhar no meio.
 *
 * Referência: `.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md` — respostas
 * reais medidas contra o sandbox, com precedência sobre a doc oficial (dois
 * pontos dela estavam errados: prefixo do token no header e formato do
 * `content_base64`).
 *
 * ⚠️ Restrição medida (`126-CONTEXT.md` §restricao_medida): `montarEnvelope()`
 * consome 15 chamadas contra uma janela medida de 20 (§1 do empírico). Não
 * acrescentar nenhuma chamada redundante (ex.: reconsultar o envelope após
 * cada passo) — dois contratos seguidos já batem em 429. A Fase 127 precisa
 * espaçar a geração em lote.
 *
 * O client é feito para rodar dentro de um job de fila (D-14) — sem estado
 * de request, sem `sleep()` longo. A Fase 127 chama `montarEnvelope()` de
 * dentro de um job (precedente: `app/Jobs/AnalyzeCompanySugadoresJob.php`).
 */
class ClicksignClient
{
    /**
     * Mapa D-08 → vocabulário de `role` da qualificação (§6 do empírico:
     * `action: "agree"` + `role: "sign" | "party" | "contractor"`). Só esses
     * três valores foram MEDIDOS no sandbox, e nenhum deles é literalmente
     * "contratante"/"contratada"/"testemunha" — a correspondência abaixo é a
     * mais próxima por sentido comum de contrato de prestação de serviço:
     *
     * ⚠️ **NÃO MEDIDO** — confirmar no checkpoint humano do plano 126-06.
     *  - `PAPEL_CONTRATADA`  → `contractor` (quem presta o serviço — a ECF;
     *    "contractor" em inglês nomeia quem executa o trabalho sob contrato).
     *  - `PAPEL_CONTRATANTE` → `party` (a outra parte do contrato).
     *  - `PAPEL_TESTEMUNHA`  → `sign` (sem papel contratual, só assina).
     *
     * @var array<string, string>
     */
    public const PAPEL_PARA_CLICKSIGN_ROLE = [
        ContratoAssinaturaSignatario::PAPEL_CONTRATADA  => 'contractor',
        ContratoAssinaturaSignatario::PAPEL_CONTRATANTE => 'party',
        ContratoAssinaturaSignatario::PAPEL_TESTEMUNHA  => 'sign',
    ];

    private ?string $token;
    private ?string $baseUrl;

    /**
     * Token e base URL são injetáveis para teste; fallback para
     * `config('services.clicksign.*')` — nunca `env()` direto aqui.
     */
    public function __construct(?string $token = null, ?string $baseUrl = null)
    {
        $this->token   = $token ?? config('services.clicksign.access_token');
        $this->baseUrl = $baseUrl ?? config('services.clicksign.base_url');
    }

    /**
     * POST /envelopes — cria o envelope. Devolve o bloco `data` da resposta.
     *
     * @param  array<string, mixed>  $atributos
     * @return array<string, mixed>
     */
    public function criarEnvelope(array $atributos): array
    {
        return $this->enviar('post', '/envelopes', [
            'data' => [
                'type'       => 'envelopes',
                'attributes' => $atributos,
            ],
        ], 'criar envelope');
    }

    /**
     * POST /envelopes/{envelopeId}/documents — anexa o PDF ao envelope.
     * `content_base64` sai como Data URI completo (§2 do empírico — a doc
     * oficial exemplifica base64 puro, e isso dá 400 real). Antes de
     * qualquer requisição, guarda o limite de tamanho (gate #5, NÃO MEDIDO).
     *
     * @return array<string, mixed>
     */
    public function anexarDocumento(string $envelopeId, string $nomeArquivo, string $pdfBinario): array
    {
        $limite = (int) config('services.clicksign.max_upload_bytes');

        if ($limite > 0 && strlen($pdfBinario) > $limite) {
            throw new ClicksignException(
                "O PDF do contrato excede o limite de upload aceito pela Clicksign ({$limite} bytes)."
            );
        }

        $conteudo = 'data:application/pdf;base64,' . base64_encode($pdfBinario);

        return $this->enviar('post', "/envelopes/{$envelopeId}/documents", [
            'data' => [
                'type'       => 'documents',
                'attributes' => [
                    'filename'       => $nomeArquivo,
                    'content_base64' => $conteudo,
                ],
            ],
        ], 'anexar documento');
    }

    /**
     * POST /envelopes/{envelopeId}/signers — adiciona um signatário ao
     * envelope. `group = 1` explícito para TODOS (D-09: assinatura
     * simultânea, sem ordenação — não confiar no default `1` medido em §5 do
     * empírico, que pode não valer para sempre).
     *
     * @return array<string, mixed>
     */
    public function adicionarSignatario(string $envelopeId, string $nome, string $email): array
    {
        return $this->enviar('post', "/envelopes/{$envelopeId}/signers", [
            'data' => [
                'type'       => 'signers',
                'attributes' => [
                    'name'            => $nome,
                    'email'           => $email,
                    'communicate_by'  => 'email',
                    'group'           => 1,
                ],
            ],
        ], 'adicionar signatário');
    }

    /**
     * POST /envelopes/{envelopeId}/requirements — requisito de QUALIFICAÇÃO
     * (`action: "agree"`), o eixo que diz o papel do signatário no contrato.
     * Mapeia o vocabulário interno (D-08 da Fase 125) para o vocabulário
     * medido da API via `PAPEL_PARA_CLICKSIGN_ROLE`. Papel fora do mapa
     * lança `ClicksignException` ANTES de qualquer requisição sair — nunca
     * manda valor inválido para a API.
     *
     * @return array<string, mixed>
     */
    public function criarRequisitoQualificacao(string $envelopeId, string $documentId, string $signerId, string $papelInterno): array
    {
        $role = self::PAPEL_PARA_CLICKSIGN_ROLE[$papelInterno] ?? null;

        if ($role === null) {
            throw new ClicksignException(
                "Papel de signatário desconhecido para a Clicksign: \"{$papelInterno}\"."
            );
        }

        return $this->criarRequisito($envelopeId, $documentId, $signerId, [
            'action' => 'agree',
            'role'   => $role,
        ], 'criar requisito de qualificação');
    }

    /**
     * POST /envelopes/{envelopeId}/requirements — requisito de AUTENTICAÇÃO
     * (`action: "provide_evidence"`), o eixo que diz COMO o signatário prova
     * identidade. D-07: só e-mail (`auth: "email"`) — método configurável
     * por contrato está explicitamente deferido no CONTEXT.
     *
     * @return array<string, mixed>
     */
    public function criarRequisitoAutenticacao(string $envelopeId, string $documentId, string $signerId): array
    {
        return $this->criarRequisito($envelopeId, $documentId, $signerId, [
            'action' => 'provide_evidence',
            'auth'   => 'email',
        ], 'criar requisito de autenticação');
    }

    /**
     * Núcleo comum dos dois requisitos acima: ambos exigem
     * `relationships.document` E `relationships.signer` (§6 do empírico).
     *
     * @param  array<string, mixed>  $atributos
     * @return array<string, mixed>
     */
    private function criarRequisito(string $envelopeId, string $documentId, string $signerId, array $atributos, string $contexto): array
    {
        return $this->enviar('post', "/envelopes/{$envelopeId}/requirements", [
            'data' => [
                'type'          => 'requirements',
                'attributes'    => $atributos,
                'relationships' => [
                    'document' => ['data' => ['type' => 'documents', 'id' => $documentId]],
                    'signer'   => ['data' => ['type' => 'signers', 'id' => $signerId]],
                ],
            ],
        ], $contexto);
    }

    /**
     * PATCH /envelopes/{envelopeId} — ativa o envelope (`status: "running"`).
     * D-13: `deadline_at` (30 dias, por padrão) e `remind_interval` (3 dias,
     * por padrão) vão EXPLÍCITOS no corpo, mesmo coincidindo com o default
     * medido em §5 do empírico — o comportamento não deve depender de
     * default de terceiro que pode mudar sem aviso. Os parâmetros já nascem
     * com default para a Fase 127 tornar o prazo configurável por contrato
     * (DADOS-06) sem tocar neste client.
     *
     * @return array<string, mixed>
     */
    public function ativarEnvelope(string $envelopeId, int $prazoDias = 30, int $lembreteDias = 3): array
    {
        return $this->enviar('patch', "/envelopes/{$envelopeId}", [
            'data' => [
                'id'         => $envelopeId,
                'type'       => 'envelopes',
                'attributes' => [
                    'status'          => 'running',
                    'deadline_at'     => now()->addDays($prazoDias)->toIso8601String(),
                    'remind_interval' => $lembreteDias,
                ],
            ],
        ], 'ativar envelope');
    }

    /**
     * GET /envelopes/{envelopeId} — consulta o estado atual do envelope.
     *
     * @return array<string, mixed>
     */
    public function consultarEnvelope(string $envelopeId): array
    {
        return $this->enviar('get', "/envelopes/{$envelopeId}", [], 'consultar envelope');
    }

    /**
     * GET /envelopes/{envelopeId}/documents/{documentId}/events — lista os
     * eventos do documento. ⚠️ §3 do empírico: a situação individual do
     * signatário NÃO existe no recurso `signers` (comparado antes×depois da
     * assinatura, só o campo `modified` mudou) — só aqui, no evento
     * `name: "sign"`. Quem for consumir isso na Fase 129 precisa saber.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listarEventosDoDocumento(string $envelopeId, string $documentId): array
    {
        return $this->enviar('get', "/envelopes/{$envelopeId}/documents/{$documentId}/events", [], 'listar eventos do documento');
    }

    /**
     * POST /envelopes/{envelopeId}/signers/{signerId}/notifications —
     * reenvia o link de assinatura. NÃO passa pelo `enviar()` comum: este
     * endpoint tem rate limit ANTI-SPAM próprio, separado da janela geral de
     * 20 requisições (§7 do empírico) — o 429 aqui é resposta ESPERADA, não
     * falha do sistema, e NÃO deve ser retentado (o `enviar()` retentaria
     * 429 três vezes, o que é exatamente o comportamento errado para um
     * limite anti-spam). A resposta de erro pode vir em TEXTO PURO (ex.:
     * "Too many requests"), não JSON:API — por isso a decodificação abaixo é
     * defensiva. `GET` neste endpoint devolve 404 porque ele é POST-only;
     * não confundir com "não existe".
     *
     * @return array<string, mixed>
     */
    public function reenviarNotificacao(string $envelopeId, string $signerId): array
    {
        $url = rtrim((string) $this->baseUrl, '/') . "/envelopes/{$envelopeId}/signers/{$signerId}/notifications";

        $res = $this->baseRequest()->post($url);

        if ($res->successful()) {
            Log::channel('ecf-webhooks')->info('[Clicksign] reenviar notificação', [
                'contexto' => 'reenviar notificação',
                'status'   => $res->status(),
            ]);

            return $res->json('data') ?? [];
        }

        if ($res->status() === 429) {
            Log::channel('ecf-webhooks')->warning('[Clicksign] Falha em reenviar notificação', [
                'contexto' => 'reenviar notificação',
                'status'   => 429,
            ]);

            throw new ClicksignException(
                'A Clicksign está limitando reenvios deste link. Aguarde alguns instantes antes de tentar de novo.',
                429
            );
        }

        // Decodificação defensiva: a resposta de erro deste endpoint pode
        // não ser JSON:API (§7 do empírico).
        $corpo = $res->json();
        $corpo = is_array($corpo) ? $corpo : [];

        Log::channel('ecf-webhooks')->warning('[Clicksign] Falha em reenviar notificação', [
            'contexto' => 'reenviar notificação',
            'status'   => $res->status(),
        ]);

        throw ClicksignException::fromResponse($res->status(), $corpo);
    }

    /**
     * PATCH /envelopes/{envelopeId} — cancela o envelope. Devolve booleano
     * de sucesso e NUNCA lança exceção nova: quem chama este método dentro
     * do rollback de `montarEnvelope()` (D-12) precisa que uma falha ao
     * cancelar não substitua o erro original. Loga só `envelope_id` e
     * `status` — nenhum dado do signatário.
     *
     * ⚠️ **NÃO MEDIDO:** o valor exato de `status` que a Clicksign espera
     * para cancelamento não foi observado no sandbox (a sessão empírica não
     * cobriu esse caminho — D-12 é quem introduz o cancelamento). `"canceled"`
     * é a suposição mais provável dado o vocabulário medido
     * (`draft`/`running`/`closed`), a confirmar no checkpoint humano do
     * plano 126-06.
     */
    public function cancelarEnvelope(string $envelopeId): bool
    {
        try {
            $this->enviar('patch', "/envelopes/{$envelopeId}", [
                'data' => [
                    'id'         => $envelopeId,
                    'type'       => 'envelopes',
                    'attributes' => [
                        'status' => 'canceled', // NÃO MEDIDO
                    ],
                ],
            ], 'cancelar envelope');

            return true;
        } catch (ClicksignException $e) {
            Log::channel('ecf-webhooks')->warning('[Clicksign] Falha ao cancelar envelope', [
                'envelope_id' => $envelopeId,
                'status'      => $e->httpStatus,
            ]);

            return false;
        }
    }

    /**
     * Monta um envelope de ponta a ponta: cria o envelope, anexa o
     * documento, adiciona os 4 signatários (o `$signatarioCliente` +
     * os 3 fixos da ECF de `config('services.clicksign.signatarios_ecf')`,
     * D-08), cria os 8 requisitos (qualificação + autenticação por
     * signatário) e ativa. Caminho feliz: 15 requisições — ver o docblock
     * de classe sobre a restrição medida de janela.
     *
     * Rollback (D-12): o `envelope_id` é guardado assim que a criação
     * retorna. Se QUALQUER passo seguinte falhar, `cancelarEnvelope()` é
     * chamado (não lança) e a exceção ORIGINAL é propagada — nunca a do
     * cancelamento, porque o operador precisa ver por que o processo falhou,
     * não que o cancelamento deu certo. Se a criação do PRÓPRIO envelope
     * falhar, nada é cancelado — não há o que cancelar.
     *
     * @param  array<string, mixed>  $dadosEnvelope  atributos de `criarEnvelope()`
     * @param  array{nome: string, email: string, papel: string}  $signatarioCliente  papel esperado: `contratante`
     * @return array{envelope_id: string, document_id: string, signatarios: array<int, array<string, mixed>>}
     */
    public function montarEnvelope(array $dadosEnvelope, string $nomeArquivo, string $pdfBinario, array $signatarioCliente): array
    {
        $envelope   = $this->criarEnvelope($dadosEnvelope);
        $envelopeId = $envelope['id'];

        $passoAtual = 'anexar documento';

        try {
            $documento  = $this->anexarDocumento($envelopeId, $nomeArquivo, $pdfBinario);
            $documentId = $documento['id'];

            $signatariosParaCriar = array_merge(
                [$signatarioCliente],
                config('services.clicksign.signatarios_ecf', [])
            );

            $signatariosCriados = [];

            foreach ($signatariosParaCriar as $signatario) {
                $papel = $signatario['papel'];

                $passoAtual = "adicionar signatário ({$papel})";
                $criado     = $this->adicionarSignatario($envelopeId, $signatario['nome'], $signatario['email']);
                $signerId   = $criado['id'];

                $passoAtual = "requisito de qualificação ({$papel})";
                $this->criarRequisitoQualificacao($envelopeId, $documentId, $signerId, $papel);

                $passoAtual = "requisito de autenticação ({$papel})";
                $this->criarRequisitoAutenticacao($envelopeId, $documentId, $signerId);

                $signatariosCriados[] = [
                    'id'    => $signerId,
                    'nome'  => $signatario['nome'],
                    'email' => $signatario['email'],
                    'papel' => $papel,
                ];
            }

            $passoAtual = 'ativar envelope';
            $this->ativarEnvelope($envelopeId);

            return [
                'envelope_id' => $envelopeId,
                'document_id' => $documentId,
                'signatarios' => $signatariosCriados,
            ];
        } catch (ClicksignException $e) {
            Log::channel('ecf-webhooks')->warning('[Clicksign] Rollback de envelope montado pela metade', [
                'envelope_id' => $envelopeId,
                'passo'       => $passoAtual,
                'status'      => $e->httpStatus,
            ]);

            $this->cancelarEnvelope($envelopeId);

            throw $e;
        }
    }

    /**
     * Monta a requisição base: headers exatos do §1 do empírico + timeout de
     * 30s. NÃO usa `Http::withToken()` — esse helper do Laravel sempre
     * prefixa "Bearer " no Authorization, e a Clicksign devolve 401 com o
     * prefixo (medido). O Content-Type é fixado via `contentType()` DEPOIS
     * de `withHeaders()` de propósito: o Laravel já seta
     * 'Content-Type' => 'application/json' no construtor do PendingRequest
     * (via `asJson()`), e `withHeaders()` faz `array_merge_recursive` — se o
     * Content-Type entrasse dentro do array de `withHeaders()`, a mesma
     * chave em ambos os lados viraria um array de dois valores em vez de
     * sobrescrever. `contentType()` sempre atribui direto, sem esse risco.
     */
    private function baseRequest(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => $this->token,
            'Accept'        => 'application/vnd.api+json',
        ])->contentType('application/vnd.api+json')->timeout(30);
    }

    /**
     * Ponto único por onde toda chamada ao client passa: retry disciplinado
     * (D-11 — só em 429, 5xx e falha de conexão, nunca em 4xx de dado, no
     * máximo 3 tentativas com espera curta e crescente, teto bem abaixo de
     * 5s — o client roda dentro de um job da fila, D-14), log seguro
     * (T-126-01 — só `contexto`/`status`/`codigo`/`ponteiro`, nunca a
     * resposta ou a exceção inteiras) e conversão para `ClicksignException`.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function enviar(string $metodo, string $caminho, array $payload, string $contexto): array
    {
        $url = rtrim((string) $this->baseUrl, '/') . '/' . ltrim($caminho, '/');

        $res = $this->baseRequest()
            ->retry(3, static fn (int $tentativa) => $tentativa * 200, function (\Throwable $excecao) {
                if ($excecao instanceof ConnectionException) {
                    return true;
                }

                if ($excecao instanceof RequestException && $excecao->response !== null) {
                    $status = $excecao->response->status();

                    return $status === 429 || $status >= 500;
                }

                return false;
            }, false)
            ->send($metodo, $url, empty($payload) ? [] : ['json' => $payload]);

        if ($res->successful()) {
            Log::channel('ecf-webhooks')->info('[Clicksign] ' . $contexto, [
                'contexto' => $contexto,
                'status'   => $res->status(),
                'id'       => $res->json('data.id'),
            ]);

            return $res->json('data') ?? [];
        }

        // NUNCA logar $res, $res->body(), $res->json() inteiro nem o payload
        // enviado — a resposta de erro da Clicksign pode ecoar nome e e-mail
        // do signatário (achado WR-11 da Fase 125). Só os campos nomeados.
        $corpo    = $res->json() ?? [];
        $codigo   = $corpo['errors'][0]['code'] ?? null;
        $ponteiro = $corpo['errors'][0]['source']['pointer'] ?? null;

        Log::channel('ecf-webhooks')->warning('[Clicksign] Falha em ' . $contexto, [
            'contexto' => $contexto,
            'status'   => $res->status(),
            'codigo'   => $codigo,
            'ponteiro' => $ponteiro,
        ]);

        throw ClicksignException::fromResponse($res->status(), $corpo);
    }
}
