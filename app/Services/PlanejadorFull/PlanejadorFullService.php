<?php

namespace App\Services\PlanejadorFull;

use App\Models\Company;
use App\Models\FullColeta;
use App\Models\FullConfiguracao;
use App\Models\FullEnvio;
use App\Models\FullEnvioItem;
use App\Models\FullItem;
use App\Models\FullProduto;
use App\Models\FullSku;
use App\Models\FullVendaDiaria;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Lado da TELA do Planejador FULL: monta o que a página recebe e grava o que a pessoa
 * edita. O cálculo (venda/dia, cobertura, sugestão de envio e compra) roda no navegador
 * (resources/js/lib/planejadorFull.js), para cada ajuste recalcular na hora.
 */
class PlanejadorFullService
{
    /**
     * Ajustes padrão (dias corridos). Mesmos números do HTML original do planejador.
     * As chaves são as mesmas que a tela usa em `config`.
     */
    public const CONFIG_PADRAO = [
        'preparo'            => 2,    // separar, conferir, etiquetar e embalar
        'agenda'             => 3,    // espera pela data de coleta/entrega no ML
        'transito'           => 2,    // até o centro de distribuição
        'recebimento'        => 3,    // da chegada no CD até ficar apto para venda
        'proximoDespacho'    => '',   // vazio = mais cedo possível
        'intervaloEnvios'    => 14,
        'segA'               => 10,
        'segB'               => 7,
        'segC'               => 5,
        'fatorSazonal'       => 1,
        'limiteExcesso'      => 90,
        'leadTimeFornecedor' => 15,
        'intervaloCompras'   => 30,
        'abcA'               => 0.8,
        'abcB'               => 0.95,
    ];

    /** Chaves numéricas que aceitam no mínimo 1 (as outras aceitam 0). */
    public const CONFIG_MINIMO_UM = ['intervaloEnvios', 'limiteExcesso', 'intervaloCompras'];

    /**
     * Empresas com FULL coletado, para a lista de entrada do módulo.
     *
     * @return array<int, array>
     */
    public function empresas(): array
    {
        $contagem = FullProduto::query()
            ->select('company_id', DB::raw('COUNT(*) as codigos'), DB::raw('SUM(aptas) as aptas'))
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');

        if ($contagem->isEmpty()) {
            return [];
        }

        $ultimas = FullColeta::query()
            ->whereIn('company_id', $contagem->keys())
            ->orderByDesc('iniciado_em')
            ->get()
            ->unique('company_id')
            ->keyBy('company_id');

        $aCaminho = FullEnvio::query()
            ->whereIn('company_id', $contagem->keys())
            ->where('status', FullEnvio::STATUS_A_CAMINHO)
            ->select('company_id', DB::raw('COUNT(*) as n'))
            ->groupBy('company_id')
            ->pluck('n', 'company_id');

        return Company::query()
            ->whereIn('id', $contagem->keys())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Company $c) => [
                'id'        => $c->id,
                'nome'      => $c->name,
                'codigos'   => (int) $contagem[$c->id]->codigos,
                'aptas'     => (int) $contagem[$c->id]->aptas,
                'envios'    => (int) ($aCaminho[$c->id] ?? 0),
                'coleta'    => isset($ultimas[$c->id]) ? $this->coletaPublica($ultimas[$c->id]) : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Tudo que a página do planejador de uma empresa precisa.
     */
    public function payload(Company $company, FullEnviosService $envios): array
    {
        $ultimaOk = FullColeta::where('company_id', $company->id)
            ->where('status', FullColeta::STATUS_OK)
            ->latest('iniciado_em')
            ->first();
        $ultima = FullColeta::where('company_id', $company->id)->latest('iniciado_em')->first();

        // A data base é o dia em que o estoque foi lido (a "data do relatório").
        $dataBase = ($ultimaOk?->iniciado_em ?? now())->copy()->setTimezone(config('app.timezone'));
        $hojeBase = CarbonImmutable::parse($dataBase->toDateString());
        $vendasDe = $hojeBase->subDays(FullColetaService::JANELA_VENDAS_DIAS)->toDateString();
        $vendasAte = $hojeBase->subDay()->toDateString();

        $vendas = FullVendaDiaria::query()
            ->where('company_id', $company->id)
            ->whereBetween('data', [$vendasDe, $vendasAte])
            ->select('inventory_id', DB::raw('SUM(unidades) as unidades'), DB::raw('SUM(faturamento) as faturamento'))
            ->groupBy('inventory_id')
            ->get()
            ->keyBy('inventory_id');

        $pendentes = $envios->pendentesPorInventario($company->id);

        $produtos = FullProduto::where('company_id', $company->id)
            ->orderBy('titulo')
            ->get()
            ->map(function (FullProduto $p) use ($vendas, $pendentes) {
                $v = $vendas[$p->inventory_id] ?? null;

                return [
                    'cod'           => $p->inventory_id,
                    'sku'           => $p->sku,
                    'titulo'        => $p->titulo,
                    'marca'         => $p->marca,
                    'anuncios'      => $p->anuncios ?? [],
                    'status'        => $p->anuncio_status,
                    'aptas'         => $p->aptas,
                    'transferencia' => $p->transferencia,
                    'indisponivel'  => $p->indisponivel,
                    'pendente'      => $pendentes[$p->inventory_id] ?? 0,
                    'vendas'        => (int) ($v->unidades ?? 0),
                    'faturamento'   => round((float) ($v->faturamento ?? 0), 2),
                ];
            })
            // Mesmo recorte do HTML original: só entra quem tem estoque, entrada ou venda.
            ->filter(fn ($p) => $p['aptas'] > 0 || $p['transferencia'] > 0 || $p['pendente'] > 0 || $p['vendas'] > 0)
            ->values()
            ->all();

        $conf = FullConfiguracao::where('company_id', $company->id)->first();

        return [
            'empresa'   => ['id' => $company->id, 'nome' => $company->name],
            'dataBase'  => $hojeBase->toDateString(),
            'vendasPeriodo' => ['de' => $vendasDe, 'ate' => $vendasAte, 'dias' => FullColetaService::JANELA_VENDAS_DIAS],
            'coleta'    => $ultima ? $this->coletaPublica($ultima) : null,
            'coletaOk'  => $ultimaOk ? $this->coletaPublica($ultimaOk) : null,
            'produtos'  => $produtos,
            'config'    => array_merge(self::CONFIG_PADRAO, $conf?->config ?? []),
            'fornecedores' => $conf?->fornecedores ?? [],
            'skus'      => FullSku::where('company_id', $company->id)->get()
                ->mapWithKeys(fn (FullSku $s) => [$s->sku => [
                    'galpao'     => $s->galpao,
                    'aberto'     => $s->aberto,
                    'abertoData' => $s->aberto_data?->toDateString(),
                    'fornecedor' => $s->fornecedor,
                    'multiplo'   => $s->multiplo,
                    'leadTime'   => $s->lead_time,
                ]])
                ->all(),
            'itens'     => FullItem::where('company_id', $company->id)->get()
                ->mapWithKeys(fn (FullItem $i) => [$i->inventory_id => [
                    'ajuste'     => $i->ajuste,
                    'qtdManual'  => $i->qtd_manual,
                    'fora'       => $i->fora,
                    'observacao' => $i->observacao,
                ]])
                ->all(),
            'envios'    => $this->envios($company),
        ];
    }

    /** Envios a caminho + os 20 mais recentes já encerrados. */
    public function envios(Company $company): array
    {
        $titulos = FullProduto::where('company_id', $company->id)->pluck('titulo', 'inventory_id');

        $abertos = FullEnvio::where('company_id', $company->id)
            ->where('status', FullEnvio::STATUS_A_CAMINHO)
            ->with(['itens', 'criador:id,name'])
            ->orderBy('data_despacho')
            ->get();

        $fechados = FullEnvio::where('company_id', $company->id)
            ->where('status', '!=', FullEnvio::STATUS_A_CAMINHO)
            ->with(['itens', 'criador:id,name'])
            ->orderByDesc('data_despacho')
            ->limit(20)
            ->get();

        return $abertos->concat($fechados)->map(fn (FullEnvio $e) => [
            'id'           => $e->id,
            'dataDespacho' => $e->data_despacho?->toDateString(),
            'inboundMl'    => $e->inbound_ml,
            'status'       => $e->status,
            'origem'       => $e->origem,
            'observacao'   => $e->observacao,
            'criadoPor'    => $e->criador?->name,
            'criadoEm'     => $e->created_at?->toIso8601String(),
            'recebidoEm'   => $e->recebido_em?->toIso8601String(),
            'conferidoEm'  => $e->conferido_em?->toIso8601String(),
            'quantidade'   => (int) $e->itens->sum('quantidade'),
            'recebido'     => (int) $e->itens->sum(fn ($i) => min($i->recebido, $i->quantidade)),
            'itens'        => $e->itens->map(fn (FullEnvioItem $i) => [
                'cod'        => $i->inventory_id,
                'titulo'     => $titulos[$i->inventory_id] ?? null,
                'quantidade' => $i->quantidade,
                'recebido'   => min($i->recebido, $i->quantidade),
            ])->sortByDesc('quantidade')->values()->all(),
        ])->values()->all();
    }

    public function coletaPublica(FullColeta $c): array
    {
        return [
            'status'      => $c->status,
            'origem'      => $c->origem,
            'iniciadoEm'  => $c->iniciado_em?->toIso8601String(),
            'concluidoEm' => $c->concluido_em?->toIso8601String(),
            'produtos'    => $c->produtos,
            'pedidos'     => $c->pedidos,
            'erro'        => $c->erro,
        ];
    }

    /**
     * Venda por dia de um Código ML (gráfico da gaveta), com zero nos dias sem venda.
     *
     * @return array<int, array{data:string, unidades:int}>
     */
    public function serieVendas(Company $company, string $inventoryId, string $dataBase): array
    {
        $base = CarbonImmutable::parse($dataBase);
        $de = $base->subDays(FullColetaService::JANELA_VENDAS_DIAS);

        $porDia = FullVendaDiaria::where('company_id', $company->id)
            ->where('inventory_id', $inventoryId)
            ->whereBetween('data', [$de->toDateString(), $base->subDay()->toDateString()])
            ->get()
            ->mapWithKeys(fn (FullVendaDiaria $v) => [$v->data->toDateString() => $v->unidades]);

        $serie = [];
        for ($d = $de; $d->lt($base); $d = $d->addDay()) {
            $serie[] = ['data' => $d->toDateString(), 'unidades' => (int) ($porDia[$d->toDateString()] ?? 0)];
        }

        return $serie;
    }

    // ═══ Leitura de arquivos ═══════════════════════════════════════════════════

    /**
     * Lê o "Relatório geral de estoque" do FULL (aba Resumo) e devolve a Entrada pendente por
     * Código ML e o instante do relatório. Usado UMA vez por empresa, para registrar os envios
     * que já estavam a caminho quando o módulo entrou no ar.
     *
     * @return array{pendentes: array<string,int>, atualizadoEm: ?string, rotulo: ?string}
     */
    public function lerPlanilhaEstoque(string $caminho, ?CarbonImmutable $referencia = null): array
    {
        $linhas = $this->lerLinhas($caminho, 'Resumo');
        $referencia ??= CarbonImmutable::now();

        // Cabeçalho: a linha que tem "Código ML"; o rótulo das colunas pode ocupar 3 linhas.
        $hr = -1;
        $cCod = -1;
        foreach (array_slice($linhas, 0, 40, true) as $r => $linha) {
            foreach ($linha as $c => $v) {
                if (self::normalizar($v) === 'codigo ml') {
                    $hr = $r;
                    $cCod = $c;
                    break 2;
                }
            }
        }

        if ($hr < 0) {
            throw new \InvalidArgumentException('Não encontrei a coluna "Código ML". Use o "Relatório geral de estoque" do FULL, como sai do Mercado Livre.');
        }

        $cPend = -1;
        for ($k = 0; $k <= 2 && $cPend < 0; $k++) {
            foreach ($linhas[$hr + $k] ?? [] as $c => $v) {
                if (self::normalizar($v) === 'entrada pendente') {
                    $cPend = $c;
                    break;
                }
            }
        }
        if ($cPend < 0) {
            // Posição fixa do relatório de 2026 (14 colunas depois do Código ML).
            $cPend = $cCod + 14;
        }

        [$atualizadoEm, $rotulo] = $this->dataDoRelatorio(array_slice($linhas, 0, $hr), $referencia);

        $pendentes = [];
        foreach (array_slice($linhas, $hr + 1) as $linha) {
            $cod = trim((string) ($linha[$cCod] ?? ''));
            if (! preg_match('/^[A-Z]{3,5}\d{3,7}$/i', $cod)) {
                continue;
            }
            $q = (int) round(self::numero($linha[$cPend] ?? 0));
            if ($q > 0) {
                $pendentes[strtoupper($cod)] = $q;
            }
        }

        return ['pendentes' => $pendentes, 'atualizadoEm' => $atualizadoEm?->toIso8601String(), 'rotulo' => $rotulo];
    }

    /**
     * Lê uma planilha (xlsx ou csv) de estoque do galpão: precisa de uma coluna de SKU e uma de
     * quantidade (Estoque, Quantidade, Qtd, Saldo, Disponível ou Galpão).
     *
     * @return array<string, int>  SKU (como veio) → quantidade
     */
    public function lerPlanilhaGalpao(string $caminho, string $nomeOriginal): array
    {
        $linhas = preg_match('/\.(csv|txt)$/i', $nomeOriginal)
            ? $this->lerCsv($caminho)
            : $this->lerLinhas($caminho, null);

        $hr = -1;
        $cs = -1;
        $cq = -1;
        foreach (array_slice($linhas, 0, 20, true) as $r => $linha) {
            $h = array_map(fn ($v) => self::normalizar($v), $linha);
            $s = $q = -1;
            foreach ($h as $c => $x) {
                if ($s < 0 && ($x === 'sku' || str_starts_with($x, 'sku') || $x === 'codigo' || $x === 'referencia')) {
                    $s = $c;
                }
                if ($q < 0 && preg_match('/^(estoque|quantidade|qtd|qtde|saldo|disponivel|galpao)/', $x)) {
                    $q = $c;
                }
            }
            if ($s >= 0 && $q >= 0) {
                [$hr, $cs, $cq] = [$r, $s, $q];
                break;
            }
        }

        if ($hr < 0) {
            throw new \InvalidArgumentException('Não achei as colunas. A planilha precisa ter "SKU" e "Estoque" (ou Quantidade, Qtd, Saldo).');
        }

        $saida = [];
        foreach (array_slice($linhas, $hr + 1) as $linha) {
            $sku = trim((string) ($linha[$cs] ?? ''));
            if ($sku === '') {
                continue;
            }
            $saida[$sku] = max(0, (int) round(self::numero($linha[$cq] ?? 0)));
        }

        return $saida;
    }

    /** "Atualizado em 15 de outubro às 08h45" → instante no fuso da app. */
    private function dataDoRelatorio(array $linhas, CarbonImmutable $referencia): array
    {
        $meses = ['janeiro', 'fevereiro', 'marco', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
        $tz = config('app.timezone');

        foreach ($linhas as $linha) {
            foreach ($linha as $v) {
                $t = self::normalizar($v);
                if (! preg_match('/atualizado em (\d{1,2}) de ([a-z]+)(?: de (\d{4}))?(?: as (\d{1,2})h(\d{2}))?/', $t, $m)) {
                    continue;
                }
                $mes = array_search($m[2], $meses, true);
                if ($mes === false) {
                    continue;
                }
                $ref = $referencia->setTimezone($tz);
                $ano = ! empty($m[3]) ? (int) $m[3] : $ref->year;
                if (empty($m[3]) && $mes + 1 > $ref->month) {
                    $ano--;
                }

                $em = CarbonImmutable::create($ano, $mes + 1, (int) $m[1], (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), 0, $tz);

                return [$em, trim((string) $v)];
            }
        }

        return [null, null];
    }

    /** @return array<int, array<int, mixed>> linhas 0-based, colunas 0-based */
    private function lerLinhas(string $caminho, ?string $aba): array
    {
        $leitor = IOFactory::createReaderForFile($caminho);
        $leitor->setReadDataOnly(true);
        if ($aba !== null && method_exists($leitor, 'listWorksheetNames')) {
            $nomes = $leitor->listWorksheetNames($caminho);
            if (in_array($aba, $nomes, true)) {
                $leitor->setLoadSheetsOnly([$aba]);
            }
        }

        $planilha = $leitor->load($caminho);
        $folha = $aba !== null && $planilha->getSheetByName($aba) ? $planilha->getSheetByName($aba) : $planilha->getSheet(0);

        $linhas = [];
        foreach ($folha->toArray(null, true, false, false) as $linha) {
            $linhas[] = array_values($linha);
        }

        return $linhas;
    }

    private function lerCsv(string $caminho): array
    {
        $texto = (string) file_get_contents($caminho);
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);
        $primeira = strtok($texto, "\n") ?: '';
        $sep = substr_count($primeira, ';') >= substr_count($primeira, ',') ? ';' : ',';

        $linhas = [];
        foreach (preg_split('/\r?\n/', $texto) as $l) {
            if (trim($l) === '') {
                continue;
            }
            $linhas[] = str_getcsv($l, $sep);
        }

        return $linhas;
    }

    public static function normalizar(mixed $v): string
    {
        // Str::ascii em vez de \Normalizer: não depende da extensão intl estar ligada.
        return trim(preg_replace('/\s+/', ' ', mb_strtolower(Str::ascii((string) ($v ?? '')))));
    }

    /** Número em formato BR ("1.234,5") ou inglês; vazio e "-" viram 0. */
    public static function numero(mixed $v): float
    {
        if ($v === null || $v === '' || $v === '-') {
            return 0;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = trim((string) $v);
        if (preg_match('/^-?\d+(\.\d+)?$/', $s)) {
            return (float) $s;
        }
        $n = str_replace(',', '.', str_replace('.', '', $s));

        return is_numeric($n) ? (float) $n : 0;
    }
}
