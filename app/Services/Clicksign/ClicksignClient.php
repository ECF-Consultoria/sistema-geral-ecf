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
 * log seguro — exercitada por `criarEnvelope()` + `anexarDocumento()`. O
 * plano 126-02 acrescentou signatário, requisito, ativação, consulta,
 * notificação e cancelamento sobre o mesmo núcleo privado `enviar()` — como
 * ele nasceu seguro, os demais métodos herdam a garantia.
 *
 * **Dois caminhos de documento (Fase 126 Plan 126-07, D-16):**
 *   - `montarEnvelope()` — upload de PDF binário (`anexarDocumento()`).
 *     Continua existindo como capacidade genérica do client, com os gates
 *     #4/#5 medidos pendurados nele.
 *   - `montarEnvelopePorModelo()` — instancia um `.docx` cadastrado na
 *     Clicksign (`anexarDocumentoPorModelo()`). O contrato passou a sair
 *     daqui — a D-16 reverteu a renderização local (D-02 original).
 * Ambos compartilham o mesmo rollback (D-12) via `montarEnvelopeComum()` e
 * o mesmo orçamento de **15 chamadas contra a janela medida de 20**.
 *
 * Referência: `.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md` — respostas
 * reais medidas contra o sandbox, com precedência sobre a doc oficial (dois
 * pontos dela estavam errados: prefixo do token no header e formato do
 * `content_base64`).
 *
 * ⚠️ Restrição medida (`126-CONTEXT.md` §restricao_medida): cada envelope
 * consome 15 chamadas contra uma janela medida de 20 (§1 do empírico). Não
 * acrescentar nenhuma chamada redundante (ex.: reconsultar o envelope após
 * cada passo) — dois contratos seguidos já batem em 429. A Fase 127 precisa
 * espaçar a geração em lote.
 *
 * O client é feito para rodar dentro de um job de fila (D-14) — sem estado
 * de request, sem `sleep()` longo. A Fase 127 chama `montarEnvelope()`/
 * `montarEnvelopePorModelo()` de dentro de um job (precedente:
 * `app/Jobs/AnalyzeCompanySugadoresJob.php`).
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
     * POST /envelopes/{envelopeId}/documents — variante por MODELO (D-16),
     * MESMO endpoint de `anexarDocumento()`, corpo diferente. Medido em
     * CLICKSIGN-SANDBOX-EMPIRICO.md §9.6: `attributes.template` — não
     * `template_id` (a tentativa com `template_id` da §9.4 falhou por nome
     * de campo errado, não por rota inexistente). `content_base64` NUNCA
     * entra aqui — ele some da lista de obrigatórios quando `template` está
     * presente; misturar os dois não foi medido e não é o payload certo.
     *
     * Duas guardas ANTES de qualquer requisição — o custo de descobrir isso
     * na API é uma chamada da janela de 20 e uma mensagem genérica:
     *   - toda chave de `$variaveis` casa com `/^[a-z0-9_]+$/i` — `@`, `#`
     *     e `!` são recusados pela Clicksign no cadastro do modelo (medido,
     *     §9.4), e chave numérica faria o hash virar array JSON na
     *     serialização.
     *
     * ⚠️ **O ponto que os testes vigiam:** `template.data` sai como objeto
     * (`{}`), NUNCA como array (`[]`) — array PHP vazio serializa como `[]`
     * e a API responde "data deve ser um hash" (medido, §9.6). `stdClass`
     * vazio força objeto mesmo sem variável nenhuma.
     *
     * @param  array<string, mixed>  $variaveis  valores de `{{chave}}` do `.docx`
     * @return array<string, mixed>
     */
    public function anexarDocumentoPorModelo(string $envelopeId, string $nomeArquivo, string $templateId, array $variaveis): array
    {
        // ⚠️ MEDIDO no sandbox em 11/08/2026 (gate do plano 126-11): o
        // documento instanciado a partir de modelo NASCE de um `.docx`, e a
        // API exige que o `filename` reflita isso. Medido com modelo real:
        //   "contrato.docx"          => 201 ACEITO
        //   "contrato_sondagem.docx" => 201 ACEITO
        //   "Sondagem-modelo.pdf"    => 400 "filename não está em um formato válido"
        //   "contrato" (sem extensão) => 400, mesma mensagem
        // A guarda é local de propósito: descobrir isso na API custa uma das
        // 20 requisições/min e devolve mensagem genérica que não diz qual é o
        // formato certo. Note que `anexarDocumento()` (upload de binário) usa
        // `.pdf` normalmente — a exigência é DESTE caminho, não do outro.
        if (!preg_match('/\.docx$/i', $nomeArquivo)) {
            throw new ClicksignException(
                "O nome de arquivo \"{$nomeArquivo}\" não serve para documento gerado a partir de modelo: a Clicksign exige extensão .docx (medido — .pdf e nome sem extensão devolvem 400 \"filename não está em um formato válido\")."
            );
        }

        foreach (array_keys($variaveis) as $chave) {
            if (!is_string($chave) || !preg_match('/^[a-z0-9_]+$/i', $chave)) {
                throw new ClicksignException(
                    "Nome de variável inválido para o modelo: \"{$chave}\". A Clicksign recusa \"@\", \"#\" e \"!\" no nome do modelo (§9.4 do empírico), e chave numérica não é aceita."
                );
            }
        }

        // stdClass vazio força "{}" no JSON — array PHP vazio serializaria
        // como "[]" e a API recusa (medido, §9.6). Ver docblock acima.
        $data = empty($variaveis) ? new \stdClass() : $variaveis;

        return $this->enviar('post', "/envelopes/{$envelopeId}/documents", [
            'data' => [
                'type'       => 'documents',
                'attributes' => [
                    'filename' => $nomeArquivo,
                    'template' => [
                        'key'  => $templateId,
                        'data' => $data,
                    ],
                ],
            ],
        ], 'anexar documento por modelo');
    }

    /**
     * POST /envelopes/{envelopeId}/signers — adiciona um signatário ao
     * envelope. `group = 1` explícito para TODOS (D-09: assinatura
     * simultânea, sem ordenação — não confiar no default `1` medido em §5 do
     * empírico, que pode não valer para sempre).
     *
     * ⚠️ NÃO mandar `communicate_by` aqui. Ele aparece na RESPOSTA do recurso
     * de signatário (§5 do empírico), mas não é atributo aceito na entrada:
     * medido no sandbox em 10/08/2026 (checkpoint do plano 126-06), a API
     * devolve `400 bad_request` com ponteiro `/data/attributes/communicate_by`
     * e detalhe "communicate_by não está disponível" — tanto para o valor
     * `email` quanto para `whatsapp`. Enviar o campo quebra 100% dos
     * envelopes no primeiro signatário. Só `name`/`email`/`group` passam.
     *
     * @return array<string, mixed>
     */
    public function adicionarSignatario(string $envelopeId, string $nome, string $email): array
    {
        return $this->enviar('post', "/envelopes/{$envelopeId}/signers", [
            'data' => [
                'type'       => 'signers',
                'attributes' => [
                    'name'  => $nome,
                    'email' => $email,
                    'group' => 1,
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
     * GET /envelopes/{envelopeId}/documents/{documentId} — consulta o
     * documento dentro do envelope. Fase 129 plano 06 (CLICK-11, D-12).
     *
     * ⚠️ Documento em `draft` **não tem** bloco de arquivo nenhum — §10.4 do
     * `CLICKSIGN-SANDBOX-EMPIRICO.md` (MEDIDO): a Clicksign só materializa o
     * PDF depois da ativação do envelope. Não é código incompleto, é a API.
     *
     * ⚠️ O link de download que este endpoint devolve (`files.original` /
     * `files.signed` / `files.ziped`) é uma URL S3 pré-assinada com
     * `X-Amz-Expires=300` — **vale 5 minutos** (§7 do empírico, MEDIDO).
     * Quem chama este método tem que baixar o arquivo IMEDIATAMENTE — nunca
     * guardar o link, nunca reusar numa tentativa posterior (D-12).
     *
     * @return array<string, mixed>
     */
    public function consultarDocumento(string $envelopeId, string $documentId): array
    {
        return $this->enviar('get', "/envelopes/{$envelopeId}/documents/{$documentId}", [], 'consultar documento');
    }

    /**
     * GET /templates — lista os modelos cadastrados na conta (paginação
     * JSON:API, `page[number]`/`page[size]`, default 20 — §1 do empírico).
     * Devolve a LISTA já desembrulhada (`data`), não o envelope JSON:API
     * inteiro (`meta`/`links` ficam de fora, mesmo padrão de
     * `listarEventosDoDocumento()`).
     *
     * Fase 126 Plan 126-07 (CLICK-01, D-16) — parte do caminho de MODELO:
     * o contrato passa a sair de um `.docx` cadastrado na Clicksign, não de
     * renderização local (ver docblock de classe).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listarModelos(int $pagina = 1, int $porPagina = 20): array
    {
        return $this->enviar('get', '/templates', [], 'listar modelos', [
            'page' => [
                'number' => $pagina,
                'size'   => $porPagina,
            ],
        ]);
    }

    /**
     * POST /templates — cadastra um modelo `.docx` na conta.
     *
     * ⚠️ `content_base64` sai como Data URI COMPLETO com o MIME de `.docx`
     * — a mesma armadilha do gate #4 do empírico (que já custou uma sessão
     * de debug com o PDF de upload). Base64 puro não funciona aqui, assim
     * como não funciona em `anexarDocumento()`.
     *
     * Guarda ANTES de qualquer requisição: `$nome` tem que terminar em
     * `.docx` — regra de negócio documentada do recurso (`modelo-campos-e-
     * regras-de-negocio`), não uma opinião deste client.
     *
     * `color` só entra em `attributes` quando informado — enviar `color:
     * null` não foi medido e não é necessário (a API já tem default
     * `#1474f5`, §2 da 126-RESEARCH-MODELOS.md).
     *
     * @return array<string, mixed>
     */
    public function criarModelo(string $nome, string $docxBinario, ?string $cor = null): array
    {
        if (!str_ends_with(strtolower($nome), '.docx')) {
            throw new ClicksignException(
                "O nome do modelo precisa terminar em \".docx\": \"{$nome}\"."
            );
        }

        $atributos = [
            'name'            => $nome,
            'content_base64'  => 'data:application/vnd.openxmlformats-officedocument.wordprocessingml.document;base64,' . base64_encode($docxBinario),
        ];

        if ($cor !== null) {
            $atributos['color'] = $cor;
        }

        return $this->enviar('post', '/templates', [
            'data' => [
                'type'       => 'templates',
                'attributes' => $atributos,
            ],
        ], 'criar modelo');
    }

    /**
     * DELETE /templates/{templateId} — exclui um modelo. Mesmo contrato de
     * `cancelarEnvelope()`: devolve booleano e NUNCA propaga exceção nova —
     * quem chama este método é rotina de limpeza/sondagem, onde uma falha
     * ao excluir não pode substituir o erro que interessa. Loga só
     * `template_id` e `status`.
     *
     * ⚠️ **Dívida da D-16 (126-CONTEXT.md):** a doc oficial da Clicksign diz
     * que excluir um modelo remove "todas as suas instâncias associadas" e
     * NÃO esclarece se isso inclui documento já gerado/assinado
     * (126-RESEARCH-MODELOS.md §3, confiança BAIXA). Enquanto o plano
     * 126-11 não medir isso contra o sandbox, este método NÃO deve ser
     * chamado contra um modelo com envelope ainda `running` gerado a partir
     * dele.
     */
    public function excluirModelo(string $templateId): bool
    {
        try {
            $this->enviar('delete', "/templates/{$templateId}", [], 'excluir modelo');

            return true;
        } catch (ClicksignException $e) {
            Log::channel('ecf-webhooks')->warning('[Clicksign] Falha ao excluir modelo', [
                'template_id' => $templateId,
                'status'      => $e->httpStatus,
            ]);

            return false;
        }
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
     * ✅ **MEDIDO no sandbox em 14/08/2026** (quick 260814-d9s,
     * `CLICKSIGN-SANDBOX-EMPIRICO.md` §14): a API v3 é JSON:API e exige o
     * membro `data` no corpo — um `POST` sem corpo (o bug original) devolve
     * `400` com "data deve ser informado(a)". O corpo mínimo aceito é
     * `data.type = "notifications"` com `data.attributes` presente. `{}`
     * vazio é suficiente — nenhum atributo é obrigatório. `attributes` tem
     * que serializar como OBJETO (`{}`), nunca como array PHP vazio (que
     * `json_encode()` transformaria em `[]` — mesma armadilha do §9.6 do
     * empírico em `anexarDocumentoPorModelo()`, redescoberta nesta sessão: a
     * primeira tentativa com array vazio devolveu `400 "attributes deve ser
     * um hash"`). Por isso `new \stdClass()` abaixo, não `[]`.
     *
     * @return array<string, mixed>
     */
    public function reenviarNotificacao(string $envelopeId, string $signerId): array
    {
        $url = rtrim((string) $this->baseUrl, '/') . "/envelopes/{$envelopeId}/signers/{$signerId}/notifications";

        $res = $this->baseRequest()->post($url, [
            'data' => [
                'type'       => 'notifications',
                'attributes' => new \stdClass(),
            ],
        ]);

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
     * ✅ **MEDIDO** no sandbox em 10/08/2026 (checkpoint do plano 126-06):
     * `PATCH` com `status: "canceled"` NÃO existe — a API devolve
     * `400 bad_request` com detalhe "status deve estar em: draft, running".
     * O descarte de um envelope em rascunho é `DELETE /envelopes/{id}`, que
     * responde `204` e faz o `GET` seguinte devolver `404`. É exatamente o
     * caminho de que o rollback da D-12 precisa, porque ele só roda antes da
     * ativação — o envelope ainda está em `draft`.
     *
     * ⚠️ Envelope já ATIVADO (`running`) não foi medido: `DELETE` pode ser
     * recusado nesse estado. Quando a Fase 127 introduzir cancelamento pós-
     * ativação, medir antes de assumir.
     */
    public function cancelarEnvelope(string $envelopeId): bool
    {
        try {
            $this->enviar('delete', "/envelopes/{$envelopeId}", [], 'cancelar envelope');

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
     * Monta um envelope de ponta a ponta com o documento por UPLOAD de PDF
     * binário: cria o envelope, anexa o documento, adiciona os 4
     * signatários (o `$signatarioCliente` + os 3 fixos da ECF de
     * `config('services.clicksign.signatarios_ecf')`, D-08), cria os 8
     * requisitos (qualificação + autenticação por signatário) e ativa.
     * Caminho feliz: 15 requisições — ver o docblock de classe sobre a
     * restrição medida de janela.
     *
     * A sequência (signatários, requisitos, ativação, rollback D-12) é
     * compartilhada com `montarEnvelopePorModelo()` via
     * `montarEnvelopeComum()` — só a forma de anexar o documento muda entre
     * os dois (Fase 126 Plan 126-07, D-16).
     *
     * `$ativar = false` (Fase 127 Plan 127-02, D-02) é o caminho que PARA no
     * rascunho: monta o envelope inteiro e não ativa — quem envia ao cliente
     * é o Comercial, pela interface da Clicksign, porque não existe
     * pré-visualizar sem ativar (§10.4 do empírico) e ativar dispara e-mail
     * ao cliente. Com `$ativar = false`, os parâmetros `$prazoDias`/
     * `$lembreteDias` de `ativarEnvelope()` NÃO rodam — prazo e lembrete
     * precisam ir na CRIAÇÃO do envelope (D-03), dentro de `$dadosEnvelope`,
     * responsabilidade de quem chama.
     *
     * @param  array<string, mixed>  $dadosEnvelope  atributos de `criarEnvelope()`
     * @param  array{nome: string, email: string, papel: string}  $signatarioCliente  papel esperado: `contratante`
     * @return array{envelope_id: string, document_id: string, signatarios: array<int, array<string, mixed>>}
     */
    public function montarEnvelope(array $dadosEnvelope, string $nomeArquivo, string $pdfBinario, array $signatarioCliente, bool $ativar = true): array
    {
        return $this->montarEnvelopeComum(
            $dadosEnvelope,
            $signatarioCliente,
            'anexar documento',
            fn (string $envelopeId) => $this->anexarDocumento($envelopeId, $nomeArquivo, $pdfBinario),
            $ativar
        );
    }

    /**
     * Monta um envelope de ponta a ponta com o documento por MODELO (D-16):
     * cria o envelope, instancia o `.docx` cadastrado via
     * `anexarDocumentoPorModelo()`, e segue a MESMA sequência de
     * `montarEnvelope()` (4 signatários, 8 requisitos, ativação, 15
     * chamadas, rollback D-12) por baixo de `montarEnvelopeComum()`.
     *
     * `$ativar = false` (Fase 127 Plan 127-02, D-02) é o caminho que PARA no
     * rascunho: monta o envelope inteiro e não ativa — quem envia ao cliente
     * é o Comercial, pela interface da Clicksign, porque não existe
     * pré-visualizar sem ativar (§10.4 do empírico) e ativar dispara e-mail
     * ao cliente. Com `$ativar = false`, os parâmetros `$prazoDias`/
     * `$lembreteDias` de `ativarEnvelope()` NÃO rodam — prazo e lembrete
     * precisam ir na CRIAÇÃO do envelope (D-03), dentro de `$dadosEnvelope`,
     * responsabilidade de quem chama.
     *
     * @param  array<string, mixed>  $dadosEnvelope  atributos de `criarEnvelope()`
     * @param  array<string, mixed>  $variaveis  valores de `{{chave}}` do `.docx`
     * @param  array{nome: string, email: string, papel: string}  $signatarioCliente  papel esperado: `contratante`
     * @return array{envelope_id: string, document_id: string, signatarios: array<int, array<string, mixed>>}
     */
    public function montarEnvelopePorModelo(array $dadosEnvelope, string $nomeArquivo, string $templateId, array $variaveis, array $signatarioCliente, bool $ativar = true): array
    {
        return $this->montarEnvelopeComum(
            $dadosEnvelope,
            $signatarioCliente,
            'anexar documento por modelo',
            fn (string $envelopeId) => $this->anexarDocumentoPorModelo($envelopeId, $nomeArquivo, $templateId, $variaveis),
            $ativar
        );
    }

    /**
     * Núcleo comum aos dois caminhos de `montarEnvelope*()` (Fase 126 Plan
     * 126-07): cria o envelope, anexa o documento pela forma que o
     * `$anexarDocumento` (closure) decidir — upload ou modelo —, adiciona
     * os 4 signatários, cria os 8 requisitos e ativa.
     *
     * Rollback (D-12): o `envelope_id` é guardado assim que a criação
     * retorna. Se QUALQUER passo seguinte falhar, `cancelarEnvelope()` é
     * chamado (não lança) e a exceção ORIGINAL é propagada — nunca a do
     * cancelamento, porque o operador precisa ver por que o processo falhou,
     * não que o cancelamento deu certo. Se a criação do PRÓPRIO envelope
     * falhar, nada é cancelado — não há o que cancelar.
     *
     * `$ativar` (Fase 127 Plan 127-02, D-02) controla só a ÚLTIMA etapa de
     * dentro do `try` — quando `false`, a ativação simplesmente não roda e o
     * `try`/`catch` continua cobrindo a MESMA sequência, com o MESMO
     * rollback (D-04). Não duplica a sequência nem cria um segundo caminho
     * de rollback.
     *
     * @param  array<string, mixed>  $dadosEnvelope  atributos de `criarEnvelope()`
     * @param  array{nome: string, email: string, papel: string}  $signatarioCliente  papel esperado: `contratante`
     * @param  \Closure(string): array<string, mixed>  $anexarDocumento  recebe o `$envelopeId`, devolve o bloco `data` do documento criado
     * @return array{envelope_id: string, document_id: string, signatarios: array<int, array<string, mixed>>}
     */
    private function montarEnvelopeComum(array $dadosEnvelope, array $signatarioCliente, string $passoAnexar, \Closure $anexarDocumento, bool $ativar = true): array
    {
        $envelope   = $this->criarEnvelope($dadosEnvelope);
        $envelopeId = $envelope['id'];

        $passoAtual = $passoAnexar;

        try {
            $documento  = $anexarDocumento($envelopeId);
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

            if ($ativar) {
                $passoAtual = 'ativar envelope';
                $this->ativarEnvelope($envelopeId);
            }

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
     * `$query` (Fase 126 Plan 126-07, CLICK-01) é parâmetro OPCIONAL novo —
     * `listarModelos()` é o primeiro chamador que precisa de query string
     * (paginação JSON:API, `page[number]`/`page[size]`). Nenhum chamador
     * existente muda: default `[]` preserva o comportamento anterior.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function enviar(string $metodo, string $caminho, array $payload, string $contexto, array $query = []): array
    {
        $url = rtrim((string) $this->baseUrl, '/') . '/' . ltrim($caminho, '/');

        $opcoes = [];

        if (!empty($payload)) {
            $opcoes['json'] = $payload;
        }

        if (!empty($query)) {
            $opcoes['query'] = $query;
        }

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
            ->send($metodo, $url, $opcoes);

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
