<?php

namespace App\Jobs;

use App\Models\ContratoAssinatura;
use App\Models\ContratoAssinaturaSignatario;
use App\Services\Clicksign\ClicksignClient;
use App\Services\Clicksign\ContratoVariaveisModeloService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fase 127 Plano 127-05 (CLICK-02, CLICK-08, DADOS-06) — trabalhador da fila
 * que monta UM envelope na Clicksign para UM contrato (= um serviço, D-06 da
 * Fase 127-01) e **para no rascunho** (D-02): nunca ativa, nunca envia ao
 * cliente. Quem envia é o Comercial, pela interface da Clicksign.
 *
 * **Por que fila, com espaçamento (D-01):** cada envelope consome 15
 * chamadas contra a janela MEDIDA de 20/min da Clicksign
 * (`126-CONTEXT.md`/`127-CONTEXT.md` §restricao_medida). Empresa com 2
 * serviços = 30 chamadas = estouro garantido se fosse laço síncrono. O
 * espaçamento vem do bucket `RateLimiter::for('clicksign-envelope', ...)`
 * (`AppServiceProvider::boot()`), aplicado via `middleware()` abaixo.
 *
 * Padrão de job copiado de `app/Jobs/SyncAdmanCompanyJob.php` (mesmo
 * formato de problema: uma chamada de API "composta", não paginação) e
 * `app/Jobs/AnalyzeCompanySugadoresJob.php` (padrão de `failed()` com tag
 * de log e ids, nunca PII).
 */
class GerarContratoAssinaturaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 3 tentativas — a janela da Clicksign é de 1 MINUTO, não ~1h como a da
     * Adman (`AnalyzeCompanySugadoresJob` usa 4 tries por isso). Não precisa
     * de tantas tentativas para a janela resetar.
     */
    public int $tries = 3;

    /**
     * 300s — INFERIDO, não medido: 15 chamadas sequenciais com timeout HTTP
     * de 30s cada (`ClicksignClient::baseRequest()`) e até 3 retries
     * internos por chamada (`ClicksignClient::enviar()`, D-11). Quem for
     * calibrar depois precisa medir o tempo real de uma montagem completa.
     */
    public int $timeout = 300;

    public function __construct(public readonly ContratoAssinatura $contratoAssinatura)
    {
    }

    /**
     * Backoff: 1min, 5min, 15min — copiado de `SyncAdmanCompanyJob`, mesmo
     * formato de problema (chamada de API simples, não paginação).
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /**
     * D-01 — dois mecanismos, não um:
     *
     * `RateLimited('clicksign-envelope')` é o throttle propriamente dito
     * (1/min, `AppServiceProvider::boot()`). Sozinho, ele tem uma pequena
     * janela de corrida: `tooManyAttempts()` e `hit()` são duas chamadas
     * separadas, não uma operação atômica única — em teoria dois workers
     * concorrentes podem passar pelo check quase juntos. Com um envelope
     * custando 15 das 20 chamadas/min, essa corrida rara na pior hipótese
     * soma 30 chamadas e estoura 429.
     *
     * `WithoutOverlapping('clicksign-envelope-global')` fecha essa lacuna
     * sem custo de infra novo: usa `Cache::lock()`, que funciona com o
     * cache `database` (`DatabaseLock`, confirmado em
     * `127-RESEARCH.md` Q1) — o store padrão de produção deste projeto.
     * `releaseAfter(5)`: se não conseguir o lock, reagenda o job em 5s em
     * vez de descartar — o slot deve liberar rápido, é lock de exclusão
     * mútua, não de rate limit.
     *
     * T-127-13 (DoS no rate limit da conta Clicksign) é mitigado pelos
     * dois juntos.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('clicksign-envelope-global'))->releaseAfter(5),
            new RateLimited('clicksign-envelope'),
        ];
    }

    /**
     * Monta o envelope de ponta a ponta e para no rascunho (D-02).
     *
     * 1. Guard de reentrega (T-127-16): contrato que já tem
     *    `clicksign_envelope_id` não monta um segundo envelope — a fila
     *    pode reentregar o mesmo job (ex.: worker derrubado no meio).
     * 2. Guard de modelo (D-21): serviço sem `clicksign_template_id` nem
     *    `CLICKSIGN_TEMPLATE_ID` padrão falha ANTES de qualquer chamada
     *    HTTP — gerar contrato com modelo errado é pior que não gerar.
     * 3. `ContratoVariaveisModeloService::montar()` — puro, sem HTTP.
     *    `$complementos` mistura duas origens de propósito: `endereco` é
     *    dado de EMPRESA, lido AO VIVO de `Company` (mesma disciplina de
     *    nome_contato/email_cliente, que também não são snapshot);
     *    `dia_vencimento`/`data_primeira_parcela` são dado de SERVIÇO, lidos
     *    do `servicos_snapshot` CONGELADO do próprio `$contrato` — nunca da
     *    tabela `contratos_servico` ao vivo (D-04; quem gravou os dois no
     *    snapshot foi `ContratoClicksignService::iniciarParaEmpresa()`, na
     *    hora de CRIAR o contrato). Os `campos_pendentes` que ainda
     *    restarem (defesa em profundidade — `ContratoDadosMinimosService`
     *    já trava a geração sem eles, Quick 260819-guy Tarefa 3) não
     *    bloqueiam; só a QUANTIDADE é logada (nunca os valores).
     * 4. `$dadosEnvelope` leva `deadline_at`/`remind_interval` já na
     *    CRIAÇÃO (D-03) — mesma forma literal usada em
     *    `ClicksignClient::ativarEnvelope()`, reusada aqui, não reinventada.
     * 5. `$nomeArquivo` termina em `.docx` (§10.2 do empírico, MEDIDO: `.pdf`
     *    aqui devolve 400) e, desde a Quick 260819-guy (Tarefa 6), carrega o
     *    slug ASCII de razão social + serviço — não mais `contrato-{id}.docx`
     *    genérico — para a lista de Rascunhos do painel da Clicksign dizer de
     *    qual empresa/serviço se trata.
     * 6. `$signatarioCliente` vem de `Company::nome_contato`/`email_cliente`
     *    — os dados mínimos já foram checados por
     *    `ContratoDadosMinimosService` ANTES do dispatch deste job
     *    (plano 127-06, orquestrador), não é responsabilidade repetir aqui.
     * 7. `ativar: false` (D-02) — o próprio ponto desta fase.
     * 8. Grava pelo `save()` do model — nunca `update()` de query builder
     *    nem `saveQuietly()`: o hook `saving` de `ContratoAssinatura` é o
     *    que ALIMENTA a trava de unicidade (D-06); pular o hook desliga a
     *    trava em silêncio. `status` continua `rascunho` e `enviado_em`
     *    NÃO é tocado — com a D-02 a ativação acontece FORA do sistema, e
     *    quem sabe que foi enviado é o webhook da Fase 129. Um `enviado_em`
     *    otimista aqui seria mentira no banco.
     * 9. Nenhuma reconsulta ao envelope para "confirmar" etapa (Pitfall 4
     *    da pesquisa) — o `ClicksignClient` já devolve cada resultado
     *    desembrulhado.
     * 10. Nenhum rollback duplicado (D-04) — ele já vive dentro de
     *     `ClicksignClient::montarEnvelopeComum()`; este job só deixa a
     *     exceção propagar, acionando `tries`/`backoff`/`failed()`.
     */
    public function handle(ClicksignClient $client, ContratoVariaveisModeloService $variaveisService): void
    {
        $contrato = $this->contratoAssinatura->fresh(['company', 'servico']);

        if (filled($contrato->clicksign_envelope_id)) {
            return;
        }

        $servico    = $contrato->servico;
        $templateId = $servico?->clicksignTemplateId();

        if (blank($templateId)) {
            throw new \RuntimeException(sprintf(
                'Serviço "%s" (id %d) não tem modelo Clicksign configurado (nem CLICKSIGN_TEMPLATE_ID padrão) — o contrato #%d não pode ser gerado sem um modelo definido.',
                $servico?->nome ?? '?',
                (int) $contrato->servico_id,
                $contrato->id
            ));
        }

        $company = $contrato->company;

        // Quick 260819-guy — ver item 3 do docblock acima: endereco é lido
        // AO VIVO da empresa; dia_vencimento/data_primeira_parcela vêm do
        // snapshot CONGELADO (nunca de `$contrato->servico`/`ContratoServico`
        // ao vivo — o snapshot é sempre um array de UM item, D-06).
        $servicoSnapshot = $contrato->servicos_snapshot[0] ?? [];

        $complementos = [
            'endereco'              => $company->endereco,
            'dia_vencimento'        => isset($servicoSnapshot['dia_vencimento']) ? (string) $servicoSnapshot['dia_vencimento'] : null,
            'data_primeira_parcela' => $servicoSnapshot['data_primeira_parcela'] ?? null,
        ];

        $resultadoVariaveis = $variaveisService->montar($contrato, $complementos);
        $variaveis          = $resultadoVariaveis['variaveis'];

        Log::info('[GerarContratoAssinaturaJob] variáveis do modelo montadas', [
            'contrato_id'                 => $contrato->id,
            'company_id'                  => $company->id,
            'quantidade_campos_pendentes' => count($resultadoVariaveis['campos_pendentes']),
        ]);

        // D-03 — deadline_at/remind_interval vão na CRIAÇÃO do envelope,
        // mesma forma literal de ClicksignClient::ativarEnvelope().
        $dadosEnvelope = [
            'name'            => "Contrato — {$servico->nome} — {$company->name}",
            'deadline_at'     => now()->addDays($contrato->prazoDiasEfetivo())->toIso8601String(),
            'remind_interval' => $contrato->lembreteDiasEfetivo(),
        ];

        // Quick 260819-guy (Tarefa 6) — antes era sempre "contrato-{id}.docx",
        // e é EXATAMENTE isso que a lista de Rascunhos do painel da Clicksign
        // mostra: impossível saber de qual empresa/serviço se trata sem abrir
        // o envelope. Slug conservador ASCII de razão social (fallback
        // `company->name`) + nome do serviço. §10.2 do empírico (MEDIDO):
        // documento por modelo exige .docx — a guarda em
        // `ClicksignClient::anexarDocumentoPorModelo()` continua valendo,
        // então o slug NUNCA pode comer a extensão.
        $nomeArquivo = $this->nomeArquivoContrato(
            (string) ($company->razao_social ?: $company->name),
            (string) $servico->nome,
            $contrato->id,
        );

        $signatarioCliente = [
            'nome'  => (string) $company->nome_contato,
            'email' => (string) $company->email_cliente,
            'papel' => ContratoAssinaturaSignatario::PAPEL_CONTRATANTE,
        ];

        // D-02 — ativar: false. D-04 (rollback) já vive dentro do client.
        $resultado = $client->montarEnvelopePorModelo(
            $dadosEnvelope,
            $nomeArquivo,
            $templateId,
            $variaveis,
            $signatarioCliente,
            ativar: false,
        );

        $contrato->clicksign_envelope_id = $resultado['envelope_id'];
        $contrato->clicksign_document_id = $resultado['document_id'];

        // Persiste os signatários que a Clicksign acabou de criar.
        //
        // Sem isto a tabela `contrato_assinatura_signatarios` fica VAZIA e o
        // sistema nunca sabe quem assinou: `ContratoSignatariosSyncService`
        // só ATUALIZA linha existente — nunca cria, de propósito (T-129-16,
        // para o webhook não conseguir inventar quem assina). Medido em
        // produção em 2026-08-18: três assinaturas reais chegaram, validaram
        // o HMAC e foram todas para `naoReconhecidos` porque não havia linha
        // para casar. Sem elas também não funcionam o reenvio de aviso da
        // Fase 131 (a rota liga `{signatario}`) nem a guarda de evidência
        // (`ip_address`, `auths`, `evidencia_signer`).
        //
        // `updateOrCreate` pela chave do signer torna a reentrega do job
        // idempotente — o guard de reentrega já barra o caso normal, mas
        // aqui a idempotência é barata e o dano de duplicar seria silencioso.
        foreach ($resultado['signatarios'] ?? [] as $signatarioCriado) {
            $contrato->signatarios()->updateOrCreate(
                ['clicksign_signer_key' => $signatarioCriado['id']],
                [
                    'nome'     => $signatarioCriado['nome'],
                    'email'    => $signatarioCriado['email'],
                    'papel'    => $signatarioCriado['papel'],
                    'situacao' => ContratoAssinaturaSignatario::SITUACAO_PENDENTE,
                ]
            );
        }

        // status permanece rascunho; enviado_em NÃO é tocado (D-02) — quem
        // sabe que foi enviado é o webhook da Fase 129.
        $contrato->save();

        Log::info('[GerarContratoAssinaturaJob] envelope montado em rascunho', [
            'contrato_id' => $contrato->id,
            'company_id'  => $company->id,
        ]);
    }

    /**
     * T-127-14 (DoS auto-infligido) — sem isto o contrato ficaria preso na
     * trava "em andamento" (D-01/D-06) para sempre, e ninguém enxergaria o
     * motivo até a Fase 131 existir (Pitfall 3 da pesquisa). Grava
     * `status = erro` pelo `save()` do model — o hook `saving` precisa
     * rodar para zerar `company_id_em_andamento`/`servico_id_em_andamento`
     * e liberar o slot para uma nova tentativa.
     *
     * T-127-15 (Information Disclosure) — `erro_mensagem` nunca carrega
     * PII do signatário (WR-11): a mensagem de exceção de terceiro pode
     * ecoar e-mail; `podarPii()` remove antes de gravar.
     */
    public function failed(\Throwable $e): void
    {
        $contrato = $this->contratoAssinatura->fresh() ?? $this->contratoAssinatura;

        Log::error('[GerarContratoAssinaturaJob] Falha definitiva ao montar envelope', [
            'contrato_id' => $contrato->id,
            'company_id'  => $contrato->company_id,
        ]);

        $contrato->status        = ContratoAssinatura::STATUS_ERRO;
        $contrato->erro_mensagem = $this->podarPii($e->getMessage());
        $contrato->save();
    }

    /**
     * Quick 260819-guy (Tarefa 6) — nome de arquivo identificável na lista
     * de Rascunhos do painel da Clicksign: `{slug(empresa-servico)}.docx`.
     * Cai em `contrato-{id}.docx` (o nome antigo) se o slug sair vazio —
     * ex.: razão social/serviço composto só de caracteres que o slug
     * descarta — para nunca gerar `.docx` sozinho.
     */
    private function nomeArquivoContrato(string $empresa, string $servico, int $contratoIdFallback): string
    {
        $slug = $this->slugArquivo("{$empresa}-{$servico}");

        return $slug === '' ? "contrato-{$contratoIdFallback}.docx" : "{$slug}.docx";
    }

    /**
     * Slug conservador ASCII (Quick 260819-guy): só `[A-Za-z0-9_-]` sobra.
     * `Str::ascii()` transliteral o acento SEM baixar caixa (diferente de
     * `Str::slug()`, que força minúsculo) — "Gestão" vira "Gestao", não
     * "gestao", porque o caso real medido no plano
     * (`"Embralumi - Novo(a) Deal"` + `"Gestão"`) espera
     * `Embralumi-Novo-a-Deal-Gestao.docx`. Espaço, parêntese e qualquer
     * outra pontuação viram hífen; hífens repetidos colapsam; limite de
     * tamanho evita nome de arquivo absurdamente longo.
     */
    private function slugArquivo(string $texto): string
    {
        $ascii = Str::ascii($texto);
        $slug  = preg_replace('/[^A-Za-z0-9_-]+/', '-', $ascii) ?? '';
        $slug  = preg_replace('/-{2,}/', '-', $slug) ?? '';
        $slug  = trim($slug, '-');

        return mb_substr($slug, 0, 150);
    }

    /**
     * Remove e-mail da mensagem de erro antes de gravar (WR-11) e limita o
     * tamanho — mensagem de exceção de terceiro não é um dado confiável
     * para gravar sem tratamento.
     */
    private function podarPii(string $mensagem): string
    {
        $semEmail = preg_replace('/[^\s]+@[^\s]+/', '[e-mail removido]', $mensagem) ?? $mensagem;

        return mb_substr($semEmail, 0, 500);
    }
}
