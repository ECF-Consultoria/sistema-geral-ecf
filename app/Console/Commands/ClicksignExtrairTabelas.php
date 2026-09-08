<?php

namespace App\Console\Commands;

use App\Services\Clicksign\AcervoContratosClicksignService;
use App\Services\Clicksign\ClicksignClient;
use App\Services\Contratos\EmpresaPalpiteService;
use App\Services\Contratos\ExtratorTextoContratoService;
use App\Services\Contratos\TabelaProgressivaContratoParser;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * `clicksign:extrair-tabelas` — Fase 140 Plano 03 (TAB-06). O comando que o
 * usuário pediu primeiro (D-06 do CONTEXT): lê os contratos de gestão de ADS
 * que estão no Clicksign e gera um relatório, sem tela, sem escrita, sem
 * tocar no banco. Com ele o usuário confere a qualidade real do casamento
 * (D-05) e decide se vale construir o resto da fase (planos 140-04/140-05).
 *
 * Pipeline por envelope, reusando os serviços das ondas anteriores sem
 * reimplementar nada:
 * `AcervoContratosClicksignService::envelopesDeGestaoDeAds()` →
 * `baixarArquivo()` → `ExtratorTextoContratoService::extrair()` →
 * `TabelaProgressivaContratoParser::analisar()` →
 * `EmpresaPalpiteService::palpitar()`.
 *
 * ⚠️ **O relatório é sobre dinheiro.** Ele pareia nome de empresa com valor
 * de mensalidade — grava SÓ no disco `local` (`storage/app/private/relatorios/`,
 * já ignorado pelo git, T-140-09), nunca no disco público nem dentro da pasta
 * de planejamento do projeto. O comando imprime, ao final, o aviso de que o
 * arquivo não deve ser commitado nem colocado em canal aberto.
 *
 * ⚠️ **Vocabulário sem jargão e sem falsa certeza (D-05).** As constantes
 * `CONFIANCA_LABEL`/`TIPO_LABEL` são o único lugar que decide como cada
 * confiança/tipo vira texto para quem lê — nunca escrever só o número da
 * pontuação sem a palavra ao lado, nunca "match"/"score"/"parser"/"envelope
 * id" como rótulo (T-140-11).
 *
 * ⚠️ **Nada é gravado no banco.** Este comando só lê e escreve arquivo em
 * disco — nenhum `Model::save()`/`create()`/`update()` em nenhum caminho.
 */
class ClicksignExtrairTabelas extends Command
{
    protected $signature = 'clicksign:extrair-tabelas {--limite=} {--situacao=closed} {--pausa-ms=3500} {--saida=}';

    protected $description = 'Lê os contratos de gestão de ADS que estão no Clicksign e gera um relatório com a tabela de cobrança de cada um. Só lê — não altera nada no sistema.';

    /**
     * Vocabulário fixo de confiança (T-140-11, D-05) — a régua de
     * `EmpresaPalpiteService` nunca aparece no relatório como número solto.
     *
     * Pública (não privada) de propósito: `Phase140RelatorioTabelasTest`
     * lê esta constante por reflexão/acesso direto para travar que todo
     * rótulo existe — ver `TIPO_LABEL` abaixo para o mesmo raciocínio.
     *
     * @var array<string, string>
     */
    public const CONFIANCA_LABEL = [
        'certo'    => 'confirmado pelo CNPJ',
        'provavel' => 'parece ser esta — confira',
        'incerto'  => 'só um palpite — confira',
    ];

    /**
     * Vocabulário fixo de tipo de cobrança.
     *
     * Pública (não privada) de propósito: um teste (140-03,
     * `Phase140RelatorioTabelasTest::todo_tipo_que_o_parser_pode_emitir_tem_rotulo_no_comando`)
     * lê o docblock de `TabelaProgressivaContratoParser::analisar()` por
     * reflexão e confere que TODO tipo que o parser pode emitir tem
     * entrada aqui — sem essa trava, um tipo novo lá (como
     * `numeros_ilegiveis`, acrescentado pelo 140-02 em 2026-09-08 sem
     * atualizar este comando) cai no fallback `?? $linha['tipo']` e o
     * relatório imprime o nome CRU da constante em vez de uma frase que a
     * pessoa entende — o defeito que este comentário existe para não
     * deixar se repetir uma quarta vez.
     *
     * @var array<string, string>
     */
    public const TIPO_LABEL = [
        'tabela'            => 'cobra por faixa de faturamento',
        'valor_fixo'        => 'valor fixo por mês',
        'indefinido'        => 'não deu para entender a cobrança',
        // 140-02 (correção pós-rodada real, commit 539d3731): contratos
        // antigos (ago/2025) têm os dígitos apagados no PDF — o arquivo
        // abre, mas o valor da cobrança não dá para ler.
        'numeros_ilegiveis' => 'os números deste contrato não são legíveis — precisa abrir o contrato à mão',
    ];

    private const TIPO_LABEL_ILEGIVEL = 'não deu para ler o arquivo';

    public function handle(
        ClicksignClient $client,
        ExtratorTextoContratoService $extrator,
        TabelaProgressivaContratoParser $parser,
        EmpresaPalpiteService $palpiteService,
    ): int {
        $limiteOpcao = $this->option('limite');
        $limite      = $limiteOpcao !== null ? (int) $limiteOpcao : null;
        $situacao    = (string) $this->option('situacao');
        $pausaMs     = (int) $this->option('pausa-ms');

        // AcervoContratosClicksignService (140-01) não entra por injeção de
        // container — o `$pausaMs` é escolhido pela opção do comando, não
        // pelo default da classe.
        $acervo = new AcervoContratosClicksignService($client, $pausaMs);

        $this->info("Buscando contratos de gestão de ADS no Clicksign (situação: {$situacao})...");

        $envelopes = $acervo->envelopesDeGestaoDeAds([$situacao], $limite);

        $this->info(count($envelopes) . ' contrato(s) encontrado(s). Lendo cada um...');

        $linhas = [];
        $resumo = [
            'total'             => 0,
            'casaram_seguranca' => 0,
            'duvidosos'         => 0,
            'valor_fixo'        => 0,
            'ilegiveis'         => 0,
        ];

        foreach ($envelopes as $envelope) {
            $resumo['total']++;

            $linha    = $this->processarEnvelope($envelope, $acervo, $extrator, $parser, $palpiteService);
            $linhas[] = $linha;

            $this->contabilizar($resumo, $linha);
        }

        // Data mais antiga primeiro — a virada de dezembro/2025 (valor fixo
        // → tabela progressiva, D-03) fica visível de bater o olho ao ler as
        // linhas em ordem. Contrato sem data conhecida vai para o fim, nunca
        // para o topo (não empurra os datados para baixo).
        usort($linhas, fn (array $a, array $b) => $this->compararData($a['data'] ?? null, $b['data'] ?? null));

        [$caminhoMd, $caminhoCsv] = $this->gravarRelatorios($linhas, $resumo, $situacao);

        $this->exibirResumo($resumo);
        $this->line('');
        $this->info('Arquivo (leitura humana): ' . $caminhoMd);
        $this->info('Arquivo (planilha): ' . $caminhoCsv);
        $this->line('');
        $this->warn('**Este arquivo tem nome de empresa e valor de mensalidade — não commite e não coloque em canal aberto.**');

        return self::SUCCESS;
    }

    /**
     * Processa UM envelope de ponta a ponta. Qualquer `\Throwable` vira
     * linha com motivo e a rodada continua — mesmo espírito de
     * `AdmanService::syncAll()`: uma rodada de ~123 contratos não pode
     * morrer no contrato 7.
     *
     * @param  array{id: ?string, nome: string, situacao: ?string, data: ?string}  $envelope
     * @return array<string, mixed>
     */
    private function processarEnvelope(
        array $envelope,
        AcervoContratosClicksignService $acervo,
        ExtratorTextoContratoService $extrator,
        TabelaProgressivaContratoParser $parser,
        EmpresaPalpiteService $palpiteService,
    ): array {
        $base = $this->linhaBase($envelope);

        $caminhoTemporario = tempnam(sys_get_temp_dir(), 'clicksign_relatorio_');

        try {
            $envelopeId = $envelope['id'] ?? null;

            if ($envelopeId === null) {
                return $this->linhaIlegivel($base, 'envelope sem identificador — não deu para baixar');
            }

            try {
                $download = $acervo->baixarArquivo($envelopeId, $caminhoTemporario);

                if (!$download['ok']) {
                    return $this->linhaIlegivel($base, $download['motivo'] ?? 'não deu para baixar o arquivo');
                }

                $binario = file_get_contents($caminhoTemporario);
            } finally {
                // ⛔ T-140-10 — o binário do contrato do cliente nunca
                // acumula no disco do servidor além do tempo de leitura.
                @unlink($caminhoTemporario);
            }

            if ($binario === false) {
                return $this->linhaIlegivel($base, 'não deu para ler o arquivo baixado');
            }

            $extraido = $extrator->extrair($binario);

            if ($extraido['texto'] === null) {
                return $this->linhaIlegivel($base, $extraido['motivo'] ?? self::TIPO_LABEL_ILEGIVEL);
            }

            $analise = $parser->analisar($extraido['texto']);
            $palpite = $palpiteService->palpitar($analise['cnpj'], $analise['razao_social'], $envelope['nome'] ?? '');

            return array_merge($base, [
                'legivel'      => true,
                'motivo'       => null,
                'tipo'         => $analise['tipo'],
                'valor_fixo'   => $analise['valor_fixo'],
                'faixas'       => $analise['faixas'],
                'cnpj'         => $analise['cnpj'],
                'razao_social' => $analise['razao_social'],
                'palpite'      => $palpite,
                // Fase 140-02 já devolve avisos prontos em pt-BR (ex.: o
                // caso `numeros_ilegiveis`) — reusar em vez de reescrever a
                // frase aqui.
                'avisos'       => $analise['avisos'] ?? [],
            ]);
        } catch (Throwable $e) {
            // T-140-12 — log só com id do envelope e motivo curto; nunca o
            // texto do contrato, o link S3 ou a mensagem de exceção crua
            // (poderia ecoar caminho de arquivo ou outro dado interno).
            Log::channel('ecf-webhooks')->warning('[Clicksign] Falha ao processar contrato no relatório de tabelas', [
                'envelope_id' => $envelope['id'] ?? null,
                'motivo'      => get_class($e),
            ]);

            @unlink($caminhoTemporario);

            return $this->linhaIlegivel($base, 'erro inesperado ao processar este contrato');
        }
    }

    /**
     * @param  array{id: ?string, nome: string, situacao: ?string, data: ?string}  $envelope
     * @return array<string, mixed>
     */
    private function linhaBase(array $envelope): array
    {
        return [
            'envelope_id' => $envelope['id'] ?? null,
            'nome'        => $envelope['nome'] ?? '',
            'data'        => $envelope['data'] ?? null,
            'situacao'    => $envelope['situacao'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function linhaIlegivel(array $base, string $motivo): array
    {
        return array_merge($base, [
            'legivel'      => false,
            'motivo'       => $motivo,
            'tipo'         => null,
            'valor_fixo'   => null,
            'faixas'       => [],
            'cnpj'         => null,
            'razao_social' => null,
            'palpite'      => null,
            'avisos'       => [],
        ]);
    }

    /**
     * Soma as QUATRO contagens do resumo — cada uma é uma pergunta
     * independente (não são fatias mutuamente exclusivas de um mesmo bolo):
     * "casaram com segurança"/"duvidosos" olham a CONFIANÇA do palpite de
     * empresa; "valor fixo" olha o TIPO de cobrança; "ilegíveis" olha se deu
     * para ler. Um contrato de valor fixo com palpite `certo` conta em dois
     * lugares ao mesmo tempo — de propósito.
     *
     * ⚠️ `numeros_ilegiveis` (140-02) é EXCEÇÃO a essa independência: mesmo
     * que o CNPJ bata com uma empresa certa, a linha ainda precisa de
     * conferência manual porque o VALOR da cobrança não dá para ler — contar
     * como "casaram com segurança" diria "está resolvida" quando não está.
     * Cai no mesmo bucket de "não deu para ler" que um arquivo que nem abriu
     * — do ponto de vista de quem lê o resumo, a ação é a mesma (abrir o
     * contrato à mão) — e NUNCA em "valor fixo"/"casaram com
     * segurança"/"duvidosos".
     *
     * @param  array<string, int>  $resumo
     * @param  array<string, mixed>  $linha
     */
    private function contabilizar(array &$resumo, array $linha): void
    {
        if ($linha['legivel'] === false) {
            $resumo['ilegiveis']++;

            return;
        }

        if ($linha['tipo'] === 'numeros_ilegiveis') {
            $resumo['ilegiveis']++;

            return;
        }

        if ($linha['palpite'] !== null) {
            if ($linha['palpite']['confianca'] === 'certo') {
                $resumo['casaram_seguranca']++;
            } else {
                $resumo['duvidosos']++;
            }
        }

        if ($linha['tipo'] === 'valor_fixo') {
            $resumo['valor_fixo']++;
        }
    }

    private function exibirResumo(array $resumo): void
    {
        $this->line('');
        $this->info('Resumo da rodada:');
        $this->line("  Total varrido: {$resumo['total']}");
        $this->line("  Casaram com segurança: {$resumo['casaram_seguranca']}");
        $this->line("  Ficaram duvidosos: {$resumo['duvidosos']}");
        $this->line("  São valor fixo: {$resumo['valor_fixo']}");
        $this->line("  Não deu para ler: {$resumo['ilegiveis']}");
    }

    /**
     * Grava `.md` (leitura humana) e `.csv` (planilha) com o MESMO
     * nome-base, dentro do disco `local` (`relatorios/` — T-140-09) — nunca
     * no disco público nem na pasta de planejamento do projeto.
     *
     * `--saida` (opcional) sobrescreve o nome-base — útil para quem quer um
     * nome previsível; sem a opção, o nome-base carrega o timestamp da
     * rodada.
     *
     * @param  array<int, array<string, mixed>>  $linhas
     * @param  array<string, int>  $resumo
     * @return array{0: string, 1: string} caminhos absolutos [md, csv]
     */
    private function gravarRelatorios(array $linhas, array $resumo, string $situacao): array
    {
        $saidaOpcao = $this->option('saida');
        $baseNome   = $saidaOpcao !== null && $saidaOpcao !== ''
            ? (string) $saidaOpcao
            : 'relatorios/clicksign-tabelas-' . now()->format('Ymd-His');

        $caminhoMd  = $baseNome . '.md';
        $caminhoCsv = $baseNome . '.csv';

        Storage::disk('local')->put($caminhoMd, $this->montarMarkdown($linhas, $resumo, $situacao));
        Storage::disk('local')->put($caminhoCsv, $this->montarCsv($linhas));

        return [
            Storage::disk('local')->path($caminhoMd),
            Storage::disk('local')->path($caminhoCsv),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $linhas
     * @param  array<string, int>  $resumo
     */
    private function montarMarkdown(array $linhas, array $resumo, string $situacao): string
    {
        $conteudo  = "# Relatório de contratos de gestão de ADS — Clicksign\n\n";
        $conteudo .= 'Rodada em: ' . now()->format('d/m/Y H:i') . " — situação consultada: {$situacao}\n\n";
        $conteudo .= "## Resumo\n\n";
        $conteudo .= "- Total varrido: **{$resumo['total']}**\n";
        $conteudo .= "- Casaram com segurança: **{$resumo['casaram_seguranca']}**\n";
        $conteudo .= "- Ficaram duvidosos: **{$resumo['duvidosos']}**\n";
        $conteudo .= "- São valor fixo: **{$resumo['valor_fixo']}**\n";
        $conteudo .= "- Não deu para ler: **{$resumo['ilegiveis']}**\n\n";
        $conteudo .= "## Contratos\n\n";
        $conteudo .= "| Contrato | Nome | Data | Situação | Empresa (palpite) | Confiança | Tipo de cobrança | Faixas | CNPJ lido | Razão social lida | Motivo |\n";
        $conteudo .= "|---|---|---|---|---|---|---|---|---|---|---|\n";

        foreach ($linhas as $linha) {
            $conteudo .= $this->linhaMarkdown($linha) . "\n";
        }

        return $conteudo;
    }

    /**
     * @param  array<string, mixed>  $linha
     */
    private function linhaMarkdown(array $linha): string
    {
        $empresaNome = $linha['palpite']['company_nome'] ?? ($linha['legivel'] ? 'nenhuma empresa parecida encontrada' : '-');
        $confianca   = $linha['palpite'] !== null ? $this->confiancaLabel($linha['palpite']) : '-';
        $tipo        = $linha['legivel'] ? (self::TIPO_LABEL[$linha['tipo']] ?? $linha['tipo']) : self::TIPO_LABEL_ILEGIVEL;
        $faixas      = $this->formatarFaixas($linha['faixas'] ?? [], $linha['valor_fixo'] ?? null, $linha['tipo'] ?? null, $linha['avisos'] ?? []);

        $celulas = [
            (string) ($linha['envelope_id'] ?? '-'),
            $this->escaparCelula((string) $linha['nome']),
            $this->escaparCelula($this->formatarData($linha['data'] ?? null)),
            (string) ($linha['situacao'] ?? '-'),
            $this->escaparCelula($empresaNome),
            $this->escaparCelula($confianca),
            $this->escaparCelula($tipo),
            $this->escaparCelula($faixas),
            (string) ($linha['cnpj'] ?? '-'),
            $this->escaparCelula((string) ($linha['razao_social'] ?? '-')),
            $this->escaparCelula((string) ($linha['motivo'] ?? '-')),
        ];

        return '| ' . implode(' | ', $celulas) . ' |';
    }

    private function escaparCelula(string $texto): string
    {
        return str_replace(['|', "\n", "\r"], ['\\|', ' ', ''], $texto);
    }

    private function confiancaLabel(array $palpite): string
    {
        $base = self::CONFIANCA_LABEL[$palpite['confianca']] ?? $palpite['confianca'];

        if ($palpite['ambiguo']) {
            $base .= ' — há outra empresa parecida';
        }

        return $base;
    }

    /**
     * @param  array<int, array{ordem: int, limite_superior: ?float, valor: float, valor_e_piso: bool}>  $faixas
     * @param  array<int, string>  $avisos
     */
    private function formatarFaixas(array $faixas, ?float $valorFixo, ?string $tipo, array $avisos = []): string
    {
        if ($tipo === 'valor_fixo') {
            return $valorFixo !== null
                ? 'R$ ' . number_format($valorFixo, 2, ',', '.') . ' por mês'
                : 'valor fixo, mas não deu para ler o número';
        }

        // 140-02 já devolve o aviso pronto em pt-BR para este tipo — reusar
        // em vez de um "-" mudo (mesma disciplina de honestidade da data).
        if ($tipo === 'numeros_ilegiveis') {
            return $avisos[0] ?? self::TIPO_LABEL['numeros_ilegiveis'];
        }

        if ($faixas === []) {
            return '-';
        }

        return implode('; ', array_map(function (array $faixa) {
            $limite = $faixa['limite_superior'] !== null
                ? 'até R$ ' . number_format($faixa['limite_superior'], 2, ',', '.')
                : 'acima disso';

            $valor = ($faixa['valor_e_piso'] ? 'a partir de R$ ' : 'R$ ') . number_format($faixa['valor'], 2, ',', '.');

            return "{$limite} → {$valor}";
        }, $faixas));
    }

    /**
     * @param  array<int, array<string, mixed>>  $linhas
     */
    private function montarCsv(array $linhas): string
    {
        $memoria = fopen('php://temp', 'r+');

        fputcsv($memoria, [
            'contrato', 'nome', 'data', 'situacao', 'empresa_palpite', 'confianca',
            'tipo_cobranca', 'faixas', 'cnpj_lido', 'razao_social_lida', 'motivo',
        ]);

        foreach ($linhas as $linha) {
            $empresaNome = $linha['palpite']['company_nome'] ?? ($linha['legivel'] ? 'nenhuma empresa parecida encontrada' : '-');
            $confianca   = $linha['palpite'] !== null ? $this->confiancaLabel($linha['palpite']) : '-';
            $tipo        = $linha['legivel'] ? (self::TIPO_LABEL[$linha['tipo']] ?? $linha['tipo']) : self::TIPO_LABEL_ILEGIVEL;
            $faixas      = $this->formatarFaixas($linha['faixas'] ?? [], $linha['valor_fixo'] ?? null, $linha['tipo'] ?? null, $linha['avisos'] ?? []);

            fputcsv($memoria, [
                $linha['envelope_id'] ?? '-',
                $linha['nome'],
                $this->formatarData($linha['data'] ?? null),
                $linha['situacao'] ?? '-',
                $empresaNome,
                $confianca,
                $tipo,
                $faixas,
                $linha['cnpj'] ?? '-',
                $linha['razao_social'] ?? '-',
                $linha['motivo'] ?? '-',
            ]);
        }

        rewind($memoria);
        $conteudo = stream_get_contents($memoria);
        fclose($memoria);

        return $conteudo === false ? '' : $conteudo;
    }

    /**
     * Defeito relatado após a rodada real de 2026-09-08: a coluna de data
     * saiu vazia em TODAS as 10 linhas, disfarçada atrás do mesmo "-"
     * genérico usado para "não se aplica" em qualquer outra coluna — o
     * fallback mudo escondeu o defeito. Célula de data sem dado precisa
     * DIZER que faltou, mesma disciplina de honestidade do palpite de
     * empresa (T-140-11): nunca "-" sozinho.
     *
     * Formato `d/m/Y` — leitura humana das 123 linhas, não a string ISO
     * crua que a API devolve.
     */
    private function formatarData(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return 'data não informada pela Clicksign';
        }

        try {
            return Carbon::parse($iso)->format('d/m/Y');
        } catch (Throwable $e) {
            return 'data não informada pela Clicksign';
        }
    }

    /**
     * Comparador para ordenar o relatório da data mais antiga para a mais
     * recente — pedido do coordenador após a rodada real: a virada de
     * dezembro/2025 (valor fixo → tabela progressiva, D-03) fica visível de
     * bater o olho quando as linhas seguem a ordem cronológica. Contrato
     * sem data conhecida (ou com data ilegível) vai sempre para o FIM —
     * nunca para o topo, onde atrapalharia a leitura da virada.
     */
    private function compararData(?string $a, ?string $b): int
    {
        $tsA = $this->paraTimestamp($a);
        $tsB = $this->paraTimestamp($b);

        if ($tsA === null && $tsB === null) {
            return 0;
        }

        if ($tsA === null) {
            return 1;
        }

        if ($tsB === null) {
            return -1;
        }

        return $tsA <=> $tsB;
    }

    private function paraTimestamp(?string $iso): ?int
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        try {
            return Carbon::parse($iso)->getTimestamp();
        } catch (Throwable $e) {
            return null;
        }
    }
}
