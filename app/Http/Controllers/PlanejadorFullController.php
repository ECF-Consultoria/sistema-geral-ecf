<?php

namespace App\Http\Controllers;

use App\Jobs\ColetarPlanejadorFullJob;
use App\Models\Company;
use App\Models\FullColeta;
use App\Models\FullConfiguracao;
use App\Models\FullEnvio;
use App\Models\FullItem;
use App\Models\FullProduto;
use App\Models\FullSku;
use App\Services\PlanejadorFull\FullEnviosService;
use App\Services\PlanejadorFull\PlanejadorFullService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Planejador FULL — reposição do estoque no Fulfillment do Mercado Livre, por empresa.
 *
 * Os dados do ML chegam pela coleta diária (full:coletar → FullColetaService); aqui só se
 * lê o que já está no banco e se grava o que a pessoa ajusta. Acesso de todos os
 * colaboradores logados (decisão de produto de 15/10/2026) — gate na rota.
 */
class PlanejadorFullController extends Controller
{
    public function __construct(
        private PlanejadorFullService $service,
        private FullEnviosService $envios,
    ) {}

    public function index()
    {
        return Inertia::render('PlanejadorFull/Index', [
            'empresas' => $this->service->empresas(),
        ]);
    }

    public function show(Company $company)
    {
        return Inertia::render('PlanejadorFull/Show', $this->service->payload($company, $this->envios) + [
            'outrasEmpresas' => collect($this->service->empresas())
                ->map(fn ($e) => ['id' => $e['id'], 'nome' => $e['nome']])
                ->values(),
            'temToken' => $company->mlToken?->status === 'active',
        ]);
    }

    /** "Atualizar agora": enfileira a coleta desta empresa na fila de cliques. */
    public function atualizar(Company $company)
    {
        if ($company->mlToken?->status !== 'active') {
            return back()->with('error', 'Esta empresa não tem token do Mercado Livre ativo. Conecte em ML OAuth.');
        }

        ColetarPlanejadorFullJob::dispatch($company, 'manual')->onQueue('high');

        return back()->with('success', 'Atualização pedida ao Mercado Livre. Leva alguns minutos; a tela recarrega sozinha quando terminar.');
    }

    /** Situação da última coleta — a tela consulta enquanto uma coleta está rodando. */
    public function status(Company $company): JsonResponse
    {
        $ultima = FullColeta::where('company_id', $company->id)->latest('iniciado_em')->first();

        return response()->json(['coleta' => $ultima ? $this->service->coletaPublica($ultima) : null]);
    }

    /** Venda por dia de um Código ML, para o gráfico da gaveta. */
    public function vendas(Request $request, Company $company, string $inventory): JsonResponse
    {
        $dataBase = $request->query('base');
        $dataBase = is_string($dataBase) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataBase) ? $dataBase : now()->toDateString();

        return response()->json(['serie' => $this->service->serieVendas($company, $inventory, $dataBase)]);
    }

    public function salvarConfig(Request $request, Company $company): JsonResponse
    {
        $regras = [
            'config'                     => ['required', 'array'],
            'config.proximoDespacho'     => ['nullable', 'date_format:Y-m-d'],
            'config.fatorSazonal'        => ['required', 'numeric', 'gt:0', 'max:10'],
            'config.abcA'                => ['required', 'numeric', 'gt:0', 'lt:1'],
            'config.abcB'                => ['required', 'numeric', 'gt:0', 'lt:1', 'gte:config.abcA'],
            'fornecedores'               => ['present', 'array', 'max:300'],
            'fornecedores.*.nome'        => ['required', 'string', 'max:120', 'distinct'],
            'fornecedores.*.leadTime'    => ['nullable', 'integer', 'min:0', 'max:365'],
            'fornecedores.*.ciclo'       => ['nullable', 'integer', 'min:1', 'max:365'],
            'fornecedores.*.observacoes' => ['nullable', 'string', 'max:255'],
            'renomear'                   => ['nullable', 'array'],
            'renomear.de'                => ['required_with:renomear', 'string', 'max:120'],
            'renomear.para'              => ['required_with:renomear', 'string', 'max:120'],
        ];

        foreach (['preparo', 'agenda', 'transito', 'recebimento', 'segA', 'segB', 'segC', 'leadTimeFornecedor'] as $k) {
            $regras["config.{$k}"] = ['required', 'integer', 'min:0', 'max:365'];
        }
        foreach (PlanejadorFullService::CONFIG_MINIMO_UM as $k) {
            $regras["config.{$k}"] = ['required', 'integer', 'min:1', 'max:365'];
        }

        $dados = $request->validate($regras, [
            'config.abcB.gte' => 'A curva B precisa terminar depois da curva A.',
        ]);

        // Só as chaves conhecidas — nada de lixo do navegador no JSON.
        $config = array_intersect_key($dados['config'], PlanejadorFullService::CONFIG_PADRAO);
        $config['proximoDespacho'] = $config['proximoDespacho'] ?? '';

        $fornecedores = collect($dados['fornecedores'])->map(fn ($f) => [
            'nome'        => trim($f['nome']),
            'leadTime'    => $f['leadTime'] ?? null,
            'ciclo'       => $f['ciclo'] ?? null,
            'observacoes' => $f['observacoes'] ?? null,
        ])->values()->all();

        DB::transaction(function () use ($company, $config, $fornecedores, $dados, $request) {
            FullConfiguracao::updateOrCreate(
                ['company_id' => $company->id],
                ['config' => $config, 'fornecedores' => $fornecedores, 'updated_by' => $request->user()->id],
            );

            // Fornecedor renomeado: os SKUs que apontavam para o nome antigo seguem junto.
            if (! empty($dados['renomear'])) {
                FullSku::where('company_id', $company->id)
                    ->where('fornecedor', $dados['renomear']['de'])
                    ->update(['fornecedor' => trim($dados['renomear']['para'])]);
            }
        });

        return response()->json(['ok' => true]);
    }

    /** Grava um ou vários SKUs de uma vez: {skus: {SKU: {galpao, aberto, ...}}}. */
    public function salvarSkus(Request $request, Company $company): JsonResponse
    {
        $dados = $request->validate([
            'skus'              => ['required', 'array', 'max:3000'],
            'skus.*'            => ['array'],
            'skus.*.galpao'     => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'skus.*.aberto'     => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'skus.*.abertoData' => ['nullable', 'date_format:Y-m-d'],
            'skus.*.fornecedor' => ['nullable', 'string', 'max:120'],
            'skus.*.multiplo'   => ['nullable', 'integer', 'min:1', 'max:10000'],
            'skus.*.leadTime'   => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);

        $mapa = ['galpao' => 'galpao', 'aberto' => 'aberto', 'abertoData' => 'aberto_data', 'fornecedor' => 'fornecedor', 'multiplo' => 'multiplo', 'leadTime' => 'lead_time'];

        DB::transaction(function () use ($company, $dados, $mapa) {
            foreach ($dados['skus'] as $sku => $campos) {
                $sku = mb_substr(trim((string) $sku), 0, 120);
                if ($sku === '') {
                    continue;
                }

                $valores = [];
                foreach ($campos as $campo => $valor) {
                    if (isset($mapa[$campo])) {
                        $valores[$mapa[$campo]] = $valor === '' ? null : $valor;
                    }
                }

                if ($valores !== []) {
                    FullSku::updateOrCreate(['company_id' => $company->id, 'sku' => $sku], $valores);
                }
            }
        });

        return response()->json(['ok' => true]);
    }

    /** Grava um ou vários Códigos ML: {itens: {COD: {ajuste, qtdManual, fora, observacao}}}. */
    public function salvarItens(Request $request, Company $company): JsonResponse
    {
        $dados = $request->validate([
            'itens'              => ['required', 'array', 'max:3000'],
            'itens.*'            => ['array'],
            'itens.*.ajuste'     => ['nullable', 'numeric', 'min:-0.95', 'max:10'],
            'itens.*.qtdManual'  => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'itens.*.fora'       => ['nullable', 'boolean'],
            'itens.*.observacao' => ['nullable', 'string', 'max:255'],
        ]);

        $mapa = ['ajuste' => 'ajuste', 'qtdManual' => 'qtd_manual', 'fora' => 'fora', 'observacao' => 'observacao'];

        DB::transaction(function () use ($company, $dados, $mapa) {
            foreach ($dados['itens'] as $cod => $campos) {
                $valores = [];
                foreach ($campos as $campo => $valor) {
                    if (isset($mapa[$campo])) {
                        $valores[$mapa[$campo]] = $campo === 'fora' ? (bool) $valor : ($valor === '' ? null : $valor);
                    }
                }

                if ($valores === []) {
                    continue;
                }

                $item = FullItem::firstOrNew(['company_id' => $company->id, 'inventory_id' => (string) $cod]);
                $item->fill($valores);

                // Linha sem nenhum ajuste não precisa existir.
                if (! $item->fora && ! $item->ajuste && $item->qtd_manual === null && ! $item->observacao) {
                    if ($item->exists) {
                        $item->delete();
                    }

                    continue;
                }

                $item->save();
            }
        });

        return response()->json(['ok' => true]);
    }

    /** Planilha (xlsx/csv) com SKU e estoque do galpão do cliente. */
    public function importarGalpao(Request $request, Company $company): JsonResponse
    {
        $request->validate(['arquivo' => ['required', 'file', 'max:20480']]);
        $arquivo = $request->file('arquivo');

        if (! in_array(strtolower($arquivo->getClientOriginalExtension()), ['xlsx', 'xls', 'csv', 'txt'], true)) {
            return response()->json(['message' => 'Envie uma planilha .xlsx ou .csv.'], 422);
        }

        try {
            $lidos = $this->service->lerPlanilhaGalpao($arquivo->getRealPath(), $arquivo->getClientOriginalName());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Não consegui ler o arquivo do galpão.'], 422);
        }

        // Casa sem diferenciar maiúscula de minúscula, gravando com a grafia do ML.
        $conhecidos = FullProduto::where('company_id', $company->id)->whereNotNull('sku')->pluck('sku')
            ->mapWithKeys(fn ($s) => [mb_strtoupper($s) => $s]);

        $atualizados = 0;
        $ignorados = 0;

        DB::transaction(function () use ($lidos, $conhecidos, $company, &$atualizados, &$ignorados) {
            foreach ($lidos as $sku => $qtd) {
                $real = $conhecidos[mb_strtoupper($sku)] ?? null;
                if ($real === null) {
                    $ignorados++;

                    continue;
                }
                FullSku::updateOrCreate(['company_id' => $company->id, 'sku' => $real], ['galpao' => $qtd]);
                $atualizados++;
            }
        });

        return response()->json([
            'atualizados' => $atualizados,
            'ignorados'   => $ignorados,
            'skus'        => FullSku::where('company_id', $company->id)->pluck('galpao', 'sku'),
        ]);
    }

    // ═══ Envios ao FULL (a "Entrada pendente") ═══════════════════════════════

    public function registrarEnvio(Request $request, Company $company): JsonResponse
    {
        $dados = $request->validate([
            'dataDespacho'       => ['required', 'date_format:Y-m-d'],
            'inboundMl'          => ['nullable', 'string', 'max:30', 'regex:/^\d+$/'],
            'observacao'         => ['nullable', 'string', 'max:255'],
            'itens'              => ['required', 'array', 'min:1', 'max:3000'],
            'itens.*.cod'        => ['required', 'string', 'max:30', 'distinct'],
            'itens.*.quantidade' => ['required', 'integer', 'min:1', 'max:10000000'],
        ], [
            'inboundMl.regex' => 'O número do envio do ML tem só dígitos.',
        ]);

        $codigos = collect($dados['itens'])->pluck('cod');
        $conhecidos = FullProduto::where('company_id', $company->id)->whereIn('inventory_id', $codigos)->count();

        if ($conhecidos !== $codigos->count()) {
            return response()->json(['message' => 'Há Código ML que não é desta empresa.'], 422);
        }

        DB::transaction(function () use ($company, $dados, $request) {
            $envio = FullEnvio::create([
                'company_id'    => $company->id,
                'data_despacho' => $dados['dataDespacho'],
                'inbound_ml'    => $dados['inboundMl'] ?? null,
                'observacao'    => $dados['observacao'] ?? null,
                'status'        => FullEnvio::STATUS_A_CAMINHO,
                'origem'        => FullEnvio::ORIGEM_PLANEJADOR,
                'criado_por'    => $request->user()->id,
            ]);

            foreach ($dados['itens'] as $i) {
                $envio->itens()->create(['inventory_id' => $i['cod'], 'quantidade' => $i['quantidade']]);
            }
        });

        return $this->respostaEnvios($company);
    }

    public function atualizarEnvio(Request $request, Company $company, FullEnvio $envio): JsonResponse
    {
        abort_unless($envio->company_id === $company->id, 404);

        $dados = $request->validate([
            'inboundMl'    => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^\d+$/'],
            'status'       => ['sometimes', Rule::in(FullEnvio::STATUS)],
            'observacao'   => ['sometimes', 'nullable', 'string', 'max:255'],
            'dataDespacho' => ['sometimes', 'date_format:Y-m-d'],
        ], [
            'inboundMl.regex' => 'O número do envio do ML tem só dígitos.',
        ]);

        if (array_key_exists('inboundMl', $dados)) {
            $envio->inbound_ml = $dados['inboundMl'] ?: null;
        }
        if (array_key_exists('observacao', $dados)) {
            $envio->observacao = $dados['observacao'];
        }
        if (array_key_exists('dataDespacho', $dados)) {
            $envio->data_despacho = $dados['dataDespacho'];
        }
        if (array_key_exists('status', $dados) && $dados['status'] !== $envio->status) {
            $envio->status = $dados['status'];
            // "Marcar como recebido" à mão fecha tudo; reabrir volta a contar pela API.
            $envio->recebido_em = $dados['status'] === FullEnvio::STATUS_RECEBIDO ? now() : null;
        }

        $envio->save();

        return $this->respostaEnvios($company);
    }

    public function excluirEnvio(Company $company, FullEnvio $envio): JsonResponse
    {
        abort_unless($envio->company_id === $company->id, 404);

        $envio->delete();

        return $this->respostaEnvios($company);
    }

    /**
     * Importação ÚNICA da planilha de estoque do ML: a coluna "Entrada pendente" vira um envio
     * a caminho, para os envios que já estavam no caminho quando o módulo entrou no ar.
     */
    public function importarPlanilhaEstoque(Request $request, Company $company): JsonResponse
    {
        $request->validate(['arquivo' => ['required', 'file', 'max:30720']]);
        $arquivo = $request->file('arquivo');

        if (strtolower($arquivo->getClientOriginalExtension()) !== 'xlsx') {
            return response()->json(['message' => 'Envie o "Relatório geral de estoque" do FULL em .xlsx, como sai do Mercado Livre.'], 422);
        }

        try {
            $lido = $this->service->lerPlanilhaEstoque($arquivo->getRealPath());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Não consegui ler a planilha.'], 422);
        }

        $conhecidos = FullProduto::where('company_id', $company->id)
            ->whereIn('inventory_id', array_keys($lido['pendentes']))
            ->pluck('inventory_id')
            ->flip();

        $itens = collect($lido['pendentes'])->filter(fn ($q, $cod) => isset($conhecidos[$cod]));

        if ($lido['pendentes'] === []) {
            return response()->json(['message' => 'A planilha não tem nenhuma unidade em "Entrada pendente".'], 422);
        }
        if ($itens->isEmpty()) {
            return response()->json(['message' => 'Nenhum Código ML da planilha é desta empresa. Confira se o relatório é da conta certa.'], 422);
        }

        $em = $lido['atualizadoEm'] ? CarbonImmutable::parse($lido['atualizadoEm']) : CarbonImmutable::now();

        DB::transaction(function () use ($company, $itens, $em, $lido, $request) {
            $envio = FullEnvio::create([
                'company_id'     => $company->id,
                'data_despacho'  => $em->setTimezone(config('app.timezone'))->toDateString(),
                'chegadas_desde' => $em,
                'status'         => FullEnvio::STATUS_A_CAMINHO,
                'origem'         => FullEnvio::ORIGEM_PLANILHA,
                'observacao'     => mb_substr('Entrada pendente da planilha do ML' . ($lido['rotulo'] ? " ({$lido['rotulo']})" : ''), 0, 255),
                'criado_por'     => $request->user()->id,
            ]);

            foreach ($itens as $cod => $qtd) {
                $envio->itens()->create(['inventory_id' => $cod, 'quantidade' => $qtd]);
            }
        });

        // Abate já o que chegou depois do horário do relatório.
        if ($company->mlToken?->status === 'active') {
            ColetarPlanejadorFullJob::dispatch($company, 'manual')->onQueue('high');
        }

        return $this->respostaEnvios($company, [
            'importados' => $itens->count(),
            'ignorados'  => count($lido['pendentes']) - $itens->count(),
            'unidades'   => (int) $itens->sum(),
        ]);
    }

    private function respostaEnvios(Company $company, array $extra = []): JsonResponse
    {
        return response()->json([
            'envios'    => $this->service->envios($company),
            'pendentes' => $this->envios->pendentesPorInventario($company->id),
        ] + $extra);
    }

    // ═══ Exportação ════════════════════════════════════════════════════════════

    /**
     * Gera o .xlsx de uma tabela que a TELA calculou (envio ou compras) — o cálculo mora no
     * navegador, então quem manda as linhas prontas é ele.
     */
    public function exportar(Request $request, Company $company)
    {
        $dados = $request->validate([
            'tipo'      => ['required', Rule::in(['envio', 'compras'])],
            'colunas'   => ['required', 'array', 'min:1', 'max:30'],
            'colunas.*' => ['string', 'max:60'],
            'linhas'    => ['present', 'array', 'max:10000'],
            'linhas.*'  => ['array'],
            'rodape'    => ['nullable', 'array', 'max:5'],
            'rodape.*'  => ['string', 'max:300'],
        ]);

        $planilha = new Spreadsheet();
        $folha = $planilha->getActiveSheet();
        $folha->setTitle($dados['tipo'] === 'envio' ? 'Envio FULL' : 'Compras');
        $folha->fromArray($dados['colunas'], null, 'A1');

        $linha = 2;
        foreach ($dados['linhas'] as $valores) {
            $folha->fromArray(array_map(fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v), array_values($valores)), null, "A{$linha}", true);
            $linha++;
        }

        foreach ($dados['rodape'] ?? [] as $texto) {
            $linha++;
            $folha->setCellValue("A{$linha}", $texto);
        }

        $ultima = $folha->getHighestColumn();
        $folha->getStyle("A1:{$ultima}1")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $folha->getStyle("A1:{$ultima}1")->getFill()->setFillType('solid')->getStartColor()->setARGB('FF1F2A44');
        $folha->freezePane('A2');
        foreach ($folha->getColumnIterator() as $coluna) {
            $folha->getColumnDimension($coluna->getColumnIndex())->setAutoSize(true);
        }

        $slug = \Illuminate\Support\Str::slug($company->name);
        $nome = ($dados['tipo'] === 'envio' ? 'envio-full' : 'compras-full') . "-{$slug}-" . now()->format('Y-m-d') . '.xlsx';

        return response()->streamDownload(function () use ($planilha) {
            (new Xlsx($planilha))->save('php://output');
        }, $nome, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
