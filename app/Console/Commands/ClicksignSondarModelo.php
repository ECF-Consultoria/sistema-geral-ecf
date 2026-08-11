<?php

namespace App\Console\Commands;

use App\Exceptions\ClicksignException;
use App\Models\ContratoAssinatura;
use App\Services\Clicksign\ClicksignClient;
use App\Services\Clicksign\ContratoVariaveisModeloService;
use App\Services\ContratoPdfService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use ZipArchive;

/**
 * `clicksign:sondar-modelo` — Fase 126, Plano 126-10 (CLICK-01).
 *
 * O instrumento de medição do caminho de MODELO (D-16): lista os modelos da
 * conta, instancia um `.docx` cadastrado em documento, e confronta as
 * variáveis que `ContratoVariaveisModeloService::nomes()` emite com o que o
 * `.docx` realmente espera. É o instrumento que o gate humano do plano
 * 126-11 executa contra o sandbox real, e que a Fase 127 reusa contra a
 * conta de produção.
 *
 * **Por que este comando existe** (não é conveniência): o modo de falha da
 * integração por modelo é SILENCIOSO — um nome de variável divergente não
 * dá erro da API, o documento sai com o campo em branco. `Http::fake()` não
 * pega isso (confirma alegremente o que decidirmos mandar — foi assim que
 * `communicate_by`, §9.1 do empírico, passou nos testes da Fase 126-02 e
 * quebraria 100% dos envelopes). Só a API real responde, e só um `.docx`
 * real cadastrado fecha o confronto.
 *
 * **Seguro por padrão** (T-126-43 a T-126-48, `126-10-PLAN.md`):
 *   - dry-run é o padrão — sem `--confirmar`, nada é enviado;
 *   - fora do sandbox, aborta sem `--producao` explícito + confirmação
 *     interativa;
 *   - nunca imprime nem loga o token (`imprimirErroSeguro()` só usa os
 *     campos nomeados de `ClicksignException`, nunca o corpo bruto);
 *   - o envelope de sondagem é sempre descartado, inclusive quando a
 *     instanciação do modelo falha (mesmo princípio do rollback D-12);
 *   - imprime a contagem real de requisições feitas contra a janela medida
 *     de 20/min (`CLICKSIGN-SANDBOX-EMPIRICO.md` §1).
 */
class ClicksignSondarModelo extends Command
{
    protected $signature = 'clicksign:sondar-modelo
                            {--template= : UUID do modelo (default: config(services.clicksign.template_id))}
                            {--contrato= : ID de um ContratoAssinatura para montar variáveis reais (sem isso, usa um conjunto sintético)}
                            {--listar : Lista os modelos cadastrados na conta (GET /templates) — modo isolado}
                            {--criar-modelo= : Caminho de um .docx local para cadastrar como modelo antes de sondar}
                            {--baixar : Baixa o arquivo gerado (links.files.original — .docx, e só existe com o envelope ativado; link expira em 5 min)}
                            {--excluir-modelo : Ao final, exclui o modelo e reconsulta o documento (mede a dívida D-16)}
                            {--producao : Autoriza rodar fora do sandbox (exige confirmação interativa)}
                            {--confirmar : Executa de fato — sem esta flag, o comando só mostra o plano}';

    protected $description = 'Sonda o caminho de MODELO da Clicksign: confronta as variáveis que o código emite com o que o .docx real espera';

    public function handle(): int
    {
        if (!$this->garantirAmbienteSeguro()) {
            return self::FAILURE;
        }

        $client = new ClicksignClient();

        if ($this->option('listar')) {
            return $this->modoListar($client);
        }

        $caminhoDocx      = $this->option('criar-modelo');
        $nomesModeloLocal = null;

        if ($caminhoDocx !== null) {
            if (!is_file($caminhoDocx)) {
                $this->error("Arquivo não encontrado: \"{$caminhoDocx}\".");

                return self::FAILURE;
            }

            try {
                $nomesModeloLocal = $this->extrairVariaveisDoDocx($caminhoDocx);
            } catch (\Throwable $e) {
                $this->error('Não foi possível ler as variáveis do .docx: ' . $e->getMessage());

                return self::FAILURE;
            }
        }

        $templateId = (string) ($this->option('template') ?: config('services.clicksign.template_id') ?: '');

        if ($caminhoDocx === null && $templateId === '') {
            $this->error('Nenhum --template informado e a variável CLICKSIGN_TEMPLATE_ID não está configurada no .env. Configure-a ou informe --template=<uuid>.');

            return self::FAILURE;
        }

        $baixar        = (bool) $this->option('baixar');
        $excluirModelo = (bool) $this->option('excluir-modelo');

        if (!$this->option('confirmar')) {
            $this->imprimirPlano($caminhoDocx, $templateId, $baixar, $excluirModelo);

            return self::SUCCESS;
        }

        return $this->executar($client, $caminhoDocx, $templateId, $nomesModeloLocal, $baixar, $excluirModelo);
    }

    // =========================================================================
    // Guardas
    // =========================================================================

    /**
     * ANTES de qualquer requisição: se o ambiente configurado não for
     * `sandbox`, exige `--producao` explícito + confirmação interativa
     * citando o ambiente (T-126-43/T-126-44). Default é sandbox — o
     * operador tem que optar conscientemente por sair dele.
     */
    private function garantirAmbienteSeguro(): bool
    {
        $env = (string) config('services.clicksign.env');

        if ($env === 'sandbox') {
            return true;
        }

        if (!$this->option('producao')) {
            $this->error("Ambiente configurado é \"{$env}\", não \"sandbox\". Este comando recusa rodar fora do sandbox sem a flag --producao explícita (a Fase 127 reusa esta mesma ferramenta contra produção, então a guarda precisa valer para os dois lados).");

            return false;
        }

        if (!$this->confirm("ATENÇÃO: você está prestes a rodar contra o ambiente \"{$env}\" (produção). Confirma?", false)) {
            $this->warn('Abortado pelo operador — nenhuma requisição foi enviada.');

            return false;
        }

        return true;
    }

    // =========================================================================
    // Modo --listar (isolado — não roda a sondagem por envelope)
    // =========================================================================

    private function modoListar(ClicksignClient $client): int
    {
        if (!$this->option('confirmar')) {
            $this->info('Plano: GET /templates — lista os modelos cadastrados na conta (1 requisição).');
            $this->line('');
            $this->warn('DRY-RUN — nenhuma requisição foi enviada. Rode novamente com --confirmar para executar de fato.');

            return self::SUCCESS;
        }

        $requisicoes = 0;

        try {
            $modelos = $client->listarModelos();
            $requisicoes++;
        } catch (ClicksignException $e) {
            if ($e->httpStatus !== null) {
                $requisicoes++;
            }

            $this->imprimirErroSeguro($e);
            $this->imprimirContagem($requisicoes);

            return self::FAILURE;
        }

        if (empty($modelos)) {
            $this->info('Nenhum modelo cadastrado na conta.');
        } else {
            $linhas = array_map(fn (array $m) => [
                $m['id'] ?? '—',
                $m['attributes']['name'] ?? '—',
            ], $modelos);

            $this->table(['ID', 'Nome'], $linhas);
        }

        $this->imprimirContagem($requisicoes);

        return self::SUCCESS;
    }

    // =========================================================================
    // Dry-run — plano do que FARIA, sem tocar a rede
    // =========================================================================

    private function imprimirPlano(?string $caminhoDocx, string $templateId, bool $baixar, bool $excluirModelo): void
    {
        $this->info('Plano desta execução (nada será enviado sem --confirmar):');
        $this->line('');

        $total = 4;

        if ($caminhoDocx !== null) {
            $this->line("  0) POST /templates — cadastra \"{$caminhoDocx}\" como modelo (1 requisição)");
            $total++;
        }

        $referenciaModelo = $caminhoDocx !== null ? '(modelo recém-cadastrado)' : $templateId;

        $this->line('  1) POST /envelopes — cria o envelope de sondagem (1 requisição)');
        $this->line("  2) POST /envelopes/{id}/documents — instancia o modelo {$referenciaModelo} em documento (1 requisição)");
        $this->line('  3) GET /envelopes/{id}/documents/{id}/events — confirma que o documento foi gerado (1 requisição)');
        $this->line('  4) DELETE /envelopes/{id} — descarta o envelope de sondagem (1 requisição)');

        if ($baixar) {
            $this->line('  +1) baixa o PDF gerado por files.original (o link expira em 5 minutos)');
            $total++;
        }

        if ($excluirModelo) {
            $this->line('  +2) DELETE /templates/{id} e reconsulta o documento (mede a dívida D-16)');
            $total += 2;
        }

        $this->line('');
        $this->line("Total estimado: {$total} requisição(ões) — janela medida: 20/min.");
        $this->line('');
        $this->warn('DRY-RUN — nenhuma requisição foi enviada. Rode novamente com --confirmar para executar de fato.');
    }

    // =========================================================================
    // Execução real
    // =========================================================================

    private function executar(
        ClicksignClient $client,
        ?string $caminhoDocx,
        string $templateId,
        ?array $nomesModeloLocal,
        bool $baixar,
        bool $excluirModelo
    ): int {
        $requisicoes = 0;

        if ($caminhoDocx !== null) {
            try {
                $conteudo = file_get_contents($caminhoDocx);
                $modelo   = $client->criarModelo(basename($caminhoDocx), $conteudo);
                $requisicoes++;
                $templateId = $modelo['id'];
                $this->info("Modelo cadastrado: {$templateId}");
                $this->line('');
            } catch (ClicksignException $e) {
                if ($e->httpStatus !== null) {
                    $requisicoes++;
                }

                $this->imprimirErroSeguro($e);
                $this->imprimirContagem($requisicoes);

                return self::FAILURE;
            }
        }

        $variaveis = $this->montarVariaveis();

        try {
            $envelope = $client->criarEnvelope(['name' => 'Sondagem de modelo — ' . now()->toIso8601String()]);
            $requisicoes++;
        } catch (ClicksignException $e) {
            if ($e->httpStatus !== null) {
                $requisicoes++;
            }

            $this->imprimirErroSeguro($e);
            $this->imprimirContagem($requisicoes);

            return self::FAILURE;
        }

        $envelopeId = $envelope['id'];
        $documento  = [];

        try {
            // `.docx`, não `.pdf`: o documento nasce do modelo Word, e a API
            // recusa qualquer outra extensão (medido no gate do plano 126-11).
            $documento = $client->anexarDocumentoPorModelo($envelopeId, 'Sondagem-modelo.docx', $templateId, $variaveis);
            $requisicoes++;
            $documentId = $documento['id'];

            $eventosDocumento = $client->listarEventosDoDocumento($envelopeId, $documentId);
            $requisicoes++;
        } catch (ClicksignException $e) {
            if ($e->httpStatus !== null) {
                $requisicoes++;
            }

            // Descarte SEMPRE — o envelope de sondagem não pode virar lixo na
            // conta, nem quando a instanciação falha (mesmo princípio do
            // rollback D-12 do ClicksignClient).
            $client->cancelarEnvelope($envelopeId);
            $requisicoes++;

            $this->error('Falha ao instanciar o modelo em documento — o envelope de sondagem foi descartado mesmo assim.');
            $this->imprimirErroSeguro($e);
            $this->imprimirContagem($requisicoes);

            return self::FAILURE;
        }

        $this->info('Documento gerado a partir do modelo. Eventos encontrados: ' . count($eventosDocumento) . '.');
        $this->line('');

        $this->confrontarVariaveis(ContratoVariaveisModeloService::nomes(), $nomesModeloLocal);

        if ($nomesModeloLocal === null) {
            $this->line('');
            $this->warn('Confronto PARCIAL: nenhum .docx foi lido nesta execução (rode com --criar-modelo=<caminho> para confrontar de verdade). A coluna "Modelo espera" ficou como "desconhecido".');
        }

        if ($baixar) {
            $this->line('');
            $requisicoes += $this->baixarArquivoGerado($documento);
        }

        if ($excluirModelo) {
            $this->line('');
            $this->warn('Excluindo o modelo. LIMITAÇÃO: esta medição vale para um documento em envelope DRAFT — não é prova de que excluir o modelo é seguro para um contrato já assinado/CLOSED (dívida D-16, ver 126-RESEARCH-MODELOS.md §3).');

            $excluiuOk = $client->excluirModelo($templateId);
            $requisicoes++;

            $eventosApos = $client->listarEventosDoDocumento($envelopeId, $documentId);
            $requisicoes++;

            $this->line($excluiuOk ? 'Modelo excluído com sucesso.' : 'Falha ao excluir o modelo — ver log ecf-webhooks.');
            $this->line('Documento reconsultado após a exclusão — eventos encontrados: ' . count($eventosApos) . '.');
        }

        // Descarte final do envelope de sondagem — sempre, mesmo com
        // --excluir-modelo/--baixar já executados.
        $client->cancelarEnvelope($envelopeId);
        $requisicoes++;

        $this->line('');
        $this->line('Envelope de sondagem descartado.');
        $this->imprimirContagem($requisicoes);

        return self::SUCCESS;
    }

    // =========================================================================
    // Variáveis
    // =========================================================================

    /**
     * `--contrato=<id>` monta variáveis REAIS via
     * `ContratoVariaveisModeloService::montar()`. Sem isso, um conjunto
     * SINTÉTICO a partir de `nomes()` — suficiente para sondar a FORMA do
     * caminho (§9.6 do empírico), sem precisar de um contrato de verdade.
     *
     * @return array<string, string>
     */
    private function montarVariaveis(): array
    {
        $contratoId = $this->option('contrato');

        if ($contratoId !== null) {
            $contrato = ContratoAssinatura::find($contratoId);

            if ($contrato === null) {
                $this->warn("Contrato #{$contratoId} não encontrado — usando conjunto sintético de variáveis.");
            } else {
                $servico = new ContratoVariaveisModeloService(new ContratoPdfService());

                return $servico->montar($contrato)['variaveis'];
            }
        }

        return array_fill_keys(ContratoVariaveisModeloService::nomes(), 'SONDAGEM');
    }

    /**
     * Varre o texto do `.docx` (zip com `word/document.xml`) por `{{nome}}`
     * — incluindo os blocos `{{#nome}}`/`{{/nome}}` de tabela em loop, cujo
     * nome de variável vem no mesmo grupo de captura.
     *
     * ⚠️ Limitação conhecida: se o Word quebrar `{{nome}}` em múltiplos
     * `<w:r>` (formatação diferente dentro da mesma chave dupla), a extração
     * pode não casar. Suficiente para o confronto de primeira leitura do
     * plano 126-11 — não é um parser de OOXML completo.
     *
     * @return array<int, string>
     */
    private function extrairVariaveisDoDocx(string $caminho): array
    {
        $zip = new ZipArchive();

        if ($zip->open($caminho) !== true) {
            throw new \RuntimeException("não foi possível abrir \"{$caminho}\" como .docx (zip inválido)");
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new \RuntimeException("\"{$caminho}\" não contém word/document.xml — não parece ser um .docx válido");
        }

        $texto = strip_tags($xml);

        preg_match_all('/\{\{\s*#?\/?\s*([a-z0-9_]+)\s*\}\}/i', $texto, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }

    /**
     * A tabela de confronto — o produto principal deste comando. Cruza o que
     * `ContratoVariaveisModeloService::nomes()` emite com o que o `.docx`
     * espera (quando `--criar-modelo` foi usado); sem isso, a coluna do
     * modelo fica "desconhecido" em vez de inventar um veredito.
     *
     * @param  array<int, string>       $nomesCodigo
     * @param  array<int, string>|null  $nomesModelo
     */
    private function confrontarVariaveis(array $nomesCodigo, ?array $nomesModelo): void
    {
        $todos = $nomesModelo === null
            ? $nomesCodigo
            : array_values(array_unique(array_merge($nomesCodigo, $nomesModelo)));

        sort($todos);

        $linhas = [];

        foreach ($todos as $nome) {
            $codigoEmite = in_array($nome, $nomesCodigo, true);

            if ($nomesModelo === null) {
                $modeloEspera = 'desconhecido';
                $veredito     = '—';
            } else {
                $modeloEsperaBool = in_array($nome, $nomesModelo, true);
                $veredito         = match (true) {
                    $codigoEmite && $modeloEsperaBool   => 'ok',
                    $codigoEmite && !$modeloEsperaBool  => 'faltando no .docx',
                    !$codigoEmite && $modeloEsperaBool  => 'sobrando no .docx',
                    default                              => '—',
                };
                $modeloEspera = $modeloEsperaBool ? 'sim' : 'não';
            }

            $linhas[] = [$nome, $codigoEmite ? 'sim' : 'não', $modeloEspera, $veredito];
        }

        $this->table(['Variável', 'Código emite', 'Modelo espera', 'Veredito'], $linhas);
    }

    // =========================================================================
    // --baixar
    // =========================================================================

    /**
     * ⚠️ MEDIDO em 11/08/2026 (gate do plano 126-11) — três coisas que a
     * versão anterior deste método errava, e que nenhum teste local pegaria:
     *
     * 1. **O link vive em `links.files.original`, não em `attributes.files`.**
     *    A primeira versão lia `attributes` e nunca achava nada.
     * 2. **Ele não aparece enquanto o envelope está em `draft`.** A Clicksign
     *    só materializa o arquivo na ATIVAÇÃO. Documento recém-instanciado
     *    não tem link nenhum — não é erro, é o ciclo de vida do recurso.
     * 3. **O arquivo é `.docx`, não PDF.** `files.original` devolve o
     *    documento gerado a partir do modelo, no formato de origem. O PDF
     *    assinado é outro artefato, posterior à assinatura (Fase 129).
     *
     * O link é pré-assinado em S3 e expira em 5 minutos (`X-Amz-Expires=300`),
     * por isso é buscado e consumido na mesma execução.
     *
     * @param  array<string, mixed>  $documento  bloco `data` do documento
     * @return int  número de requisições consumidas (0 ou 1)
     */
    private function baixarArquivoGerado(array $documento): int
    {
        $url = $documento['links']['files']['original'] ?? null;

        if ($url === null) {
            $this->warn('Sem link de download em links.files.original.');
            $this->line('  Isso é ESPERADO com o envelope em rascunho: a Clicksign só materializa o arquivo na ativação (medido, §10.4 do empírico).');

            return 0;
        }

        $resposta = Http::timeout(180)->get($url);

        if (!$resposta->successful()) {
            $this->warn('Falha ao baixar — o link é pré-assinado e expira em 5 minutos (X-Amz-Expires=300); pode ter vencido.');

            return 1;
        }

        // Extensão do que a API de fato devolveu, não `.pdf` presumido.
        $nome      = $documento['attributes']['filename'] ?? 'documento.docx';
        $extensao  = pathinfo($nome, PATHINFO_EXTENSION) ?: 'docx';
        $destino   = storage_path('app/private/clicksign-sondagem-' . now()->format('Ymd-His') . '.' . $extensao);

        file_put_contents($destino, $resposta->body());
        $this->info("Arquivo gerado baixado em: {$destino}");

        return 1;
    }

    // =========================================================================
    // Saída segura (nunca o token, nunca o corpo bruto — WR-11)
    // =========================================================================

    private function imprimirErroSeguro(ClicksignException $e): void
    {
        $this->error($e->getMessage());

        if ($e->httpStatus !== null) {
            $this->line("Status HTTP: {$e->httpStatus}");
        }

        if ($e->ponteiro !== null) {
            $this->line("Ponteiro: {$e->ponteiro}");
        }
    }

    private function imprimirContagem(int $n): void
    {
        $this->line('');
        $this->info("Requisições feitas: {$n}");
    }
}
