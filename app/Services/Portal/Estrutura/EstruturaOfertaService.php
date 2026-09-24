<?php

namespace App\Services\Portal\Estrutura;

use App\Models\Company;
use App\Models\EstruturaAnuncioEspera;
use App\Models\EstruturaOferta;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Criar, editar e excluir oferta — e a regra de composição, num lugar só.
 *
 * ### A composição, conforme a aula
 * | fase    | componentes                                                    |
 * |---------|----------------------------------------------------------------|
 * | simples | nenhum                                                         |
 * | combo   | exatamente 1, quantidade ≥ 2 ("mesmo produto, mais unidades")  |
 * | kit     | ≥ 2 distintos, todos ×1 ("produtos diferentes juntos")         |
 * | combit  | ≥ 2 distintos, algum ≥ 2 ("kit com mais unidades de um item")  |
 *
 * Todo componente é oferta SIMPLES da MESMA empresa. O FK não garante a
 * empresa — é aqui que se confere. Não há o teto de 3 componentes do
 * Planejamento: era limite de coluna da planilha, não regra.
 *
 * ### A varredura da espera roda na ESCRITA
 * Criar oferta, mudar o SKU dela ou excluí-la recalcula as linhas da espera
 * cujo SKU foi afetado, dentro da mesma transação. Nunca num GET: leitura que
 * muta dado esconde efeito colateral onde ninguém procura.
 */
class EstruturaOfertaService
{
    public function __construct(private EstruturaAnuncioService $anuncios)
    {
    }

    /**
     * @param  array{sku: string, fase: string, nome?: ?string, logistica?: ?string, observacoes?: ?string, componentes?: array<int, array{id: int, quantidade: int}>}  $dados
     * @return array{0: EstruturaOferta, 1: int} a oferta e quantos anúncios da espera ela absorveu
     */
    public function criar(Company $empresa, array $dados, AtorDoPortal $ator): array
    {
        $campos = $this->campos($dados);
        $componentes = $this->composicao($empresa, $campos['fase'], $dados['componentes'] ?? []);

        return DB::transaction(function () use ($empresa, $campos, $componentes, $ator) {
            $oferta = EstruturaOferta::create([...$campos, 'company_id' => $empresa->id]);
            $this->gravarComposicao($oferta, $componentes);

            $absorvidos = $this->varrerEspera($empresa, [$oferta->sku]);

            RegistroEstrutura::registrar($ator, $empresa, $oferta, 'oferta_criada',
                "Oferta {$oferta->sku} criada ({$oferta->fase})", ['absorvidos_da_espera' => $absorvidos]);

            return [$oferta, $absorvidos];
        });
    }

    /**
     * Vários combos de um produto numa ação só — a resposta natural a "dá
     * combo? em quantas unidades?" é uma lista ("2, 3, 4, 5, 6"), não cinco
     * diálogos.
     *
     * Cada quantidade vira `SKU-CBn` / "Combo n Nome", o padrão da aula, e
     * passa pela MESMA criação (e pela mesma regra de composição) que o combo
     * avulso. Quantidade que o produto JÁ tem como combo é pulada — a
     * comparação é pela composição (produto + quantidade), não pelo SKU, que o
     * cliente pode ter renomeado.
     *
     * @param  array<int, int>  $quantidades
     * @return array{criados: array<int, string>, pulados: array<int, int>, absorvidos: int}
     */
    public function criarCombos(EstruturaOferta $base, array $quantidades, ?string $logistica, ?string $observacoes, AtorDoPortal $ator): array
    {
        if ($base->fase !== EstruturaOferta::FASE_SIMPLES) {
            throw ValidationException::withMessages(['componentes' => 'Combo se faz a partir de um produto simples.']);
        }

        $quantidades = array_values(array_unique(array_map('intval', $quantidades)));
        sort($quantidades);

        if (! $quantidades || min($quantidades) < 2 || max($quantidades) > 999) {
            throw ValidationException::withMessages(['quantidades' => 'Informe quantidades entre 2 e 999 (ex.: 2, 3, 4).']);
        }

        $existentes = EstruturaOferta::query()
            ->where('company_id', $base->company_id)
            ->where('fase', EstruturaOferta::FASE_COMBO)
            ->whereHas('componentes', fn ($q) => $q->where('componente_id', $base->id))
            ->with('componentes')
            ->get()
            ->map(fn ($o) => $o->componentes->first()?->quantidade)
            ->filter()
            ->all();

        $empresa = $base->company;
        $nome = $base->nome ?: $base->sku;

        return DB::transaction(function () use ($empresa, $base, $quantidades, $existentes, $nome, $logistica, $observacoes, $ator) {
            $r = ['criados' => [], 'pulados' => [], 'absorvidos' => 0];

            foreach ($quantidades as $n) {
                if (in_array($n, $existentes, true)) {
                    $r['pulados'][] = $n;
                    continue;
                }

                [$oferta, $absorvidos] = $this->criar($empresa, [
                    'sku'         => "{$base->sku}-CB{$n}",
                    'fase'        => EstruturaOferta::FASE_COMBO,
                    'nome'        => "Combo {$n} {$nome}",
                    'logistica'   => $logistica,
                    'observacoes' => $observacoes,
                    'componentes' => [['id' => $base->id, 'quantidade' => $n]],
                ], $ator);

                $r['criados'][] = $oferta->sku;
                $r['absorvidos'] += $absorvidos;
            }

            return $r;
        });
    }

    /** @return array{0: EstruturaOferta, 1: int} */
    public function atualizar(EstruturaOferta $oferta, array $dados, AtorDoPortal $ator): array
    {
        $empresa = $oferta->company;
        $campos = $this->campos($dados);
        $componentes = $this->composicao($empresa, $campos['fase'], $dados['componentes'] ?? [], $oferta->id);

        // Quem é componente de alguém tem de continuar simples — senão o combo
        // que a usa passa a apontar para outro combo, que a aula não prevê.
        if ($campos['fase'] !== EstruturaOferta::FASE_SIMPLES && $oferta->usadaEm()->exists()) {
            throw ValidationException::withMessages([
                'fase' => 'Esta oferta entra na composição de outras ofertas e precisa continuar Simples.',
            ]);
        }

        return DB::transaction(function () use ($oferta, $empresa, $campos, $componentes, $ator) {
            $skuAntigo = $oferta->sku;

            $oferta->update($campos);
            $oferta->componentes()->delete();
            $this->gravarComposicao($oferta, $componentes);

            // O SKU mudou: tanto o novo pode absorver linhas da espera quanto o
            // antigo pode ter deixado de ser repetido.
            $absorvidos = EstruturaOferta::normalizarSku($skuAntigo) === EstruturaOferta::normalizarSku($oferta->sku)
                ? 0
                : $this->varrerEspera($empresa, [$skuAntigo, $oferta->sku]);

            RegistroEstrutura::registrar($ator, $empresa, $oferta, 'oferta_editada',
                "Oferta {$oferta->sku} editada", ['sku_antigo' => $skuAntigo, 'absorvidos_da_espera' => $absorvidos]);

            return [$oferta, $absorvidos];
        });
    }

    /**
     * Exclui a oferta. Os anúncios dela VOLTAM PARA A ESPERA em vez de sumirem
     * em cascata — como na planilha, onde apagar a linha da Lista deixa a aba
     * Anúncios intacta. A varredura em seguida pode até devolvê-los a outra
     * oferta com o mesmo SKU (o caso "SKU repetido" que deixou de ser).
     *
     * Oferta que é componente de alguma variação não sai: o FK é `restrict`, e
     * a mensagem diz quais variações a usam.
     */
    public function excluir(EstruturaOferta $oferta, AtorDoPortal $ator): void
    {
        $empresa = $oferta->company;

        $usos = $oferta->usadaEm()->with('oferta:id,sku')->get();
        if ($usos->isNotEmpty()) {
            $lista = $usos->pluck('oferta.sku')->implode(', ');

            throw ValidationException::withMessages([
                'oferta' => "Esta oferta entra em {$lista}. Exclua ou edite essas variações antes.",
            ]);
        }

        DB::transaction(function () use ($oferta, $empresa, $ator) {
            $devolvidos = 0;

            foreach ($oferta->anuncios()->get() as $anuncio) {
                $this->anuncios->guardarNaEspera(
                    $empresa, $oferta->sku, EstruturaAnuncioEspera::MOTIVO_SEM_OFERTA,
                    $anuncio->only(['tipo', 'catalogo', 'kit_virtual', 'status', 'codigo_mlb', 'titulo'])
                );
                $anuncio->delete();
                $devolvidos++;
            }

            $sku = $oferta->sku;

            RegistroEstrutura::registrar($ator, $empresa, null, 'oferta_excluida',
                "Oferta {$sku} excluída", ['oferta' => $oferta->toArray(), 'anuncios_para_espera' => $devolvidos]);

            $oferta->delete();

            $this->varrerEspera($empresa, [$sku]);
        });
    }

    /**
     * Recalcula as linhas da espera cujo SKU está entre `$skus`: casou com UMA
     * oferta → vira anúncio dela; nenhuma → `sem_oferta`; várias →
     * `sku_repetido`. Devolve quantas viraram anúncio.
     *
     * É o mecanismo da planilha — pôr o SKU na Lista faz o colado contar na
     * hora — trazido para o único momento em que ele pode mudar: uma escrita.
     *
     * @param  array<int, ?string>  $skus
     */
    public function varrerEspera(Company $empresa, array $skus): int
    {
        $alvos = array_filter(array_map([EstruturaOferta::class, 'normalizarSku'], $skus));
        if (! $alvos) {
            return 0;
        }

        $linhas = EstruturaAnuncioEspera::where('company_id', $empresa->id)
            ->whereNotNull('sku_colado')
            ->get()
            ->filter(fn ($l) => in_array(EstruturaOferta::normalizarSku($l->sku_colado), $alvos, true));

        if ($linhas->isEmpty()) {
            return 0;
        }

        $ofertasPorSku = EstruturaOferta::where('company_id', $empresa->id)
            ->get(['id', 'sku', 'company_id'])
            ->groupBy(fn ($o) => EstruturaOferta::normalizarSku($o->sku));

        $promovidos = 0;

        foreach ($linhas as $linha) {
            $candidatas = $ofertasPorSku[EstruturaOferta::normalizarSku($linha->sku_colado)] ?? collect();

            if ($candidatas->count() === 1) {
                $this->anuncios->promover($linha, $candidatas->first());
                $promovidos++;
                continue;
            }

            $motivo = $candidatas->isEmpty()
                ? EstruturaAnuncioEspera::MOTIVO_SEM_OFERTA
                : EstruturaAnuncioEspera::MOTIVO_SKU_REPETIDO;

            if ($linha->motivo !== $motivo) {
                $linha->update(['motivo' => $motivo]);
            }
        }

        return $promovidos;
    }

    // ═══ Validação ══════════════════════════════════════════════════════════

    private function campos(array $dados): array
    {
        $erros = [];

        $sku = trim((string) ($dados['sku'] ?? ''));
        if ($sku === '') {
            $erros['sku'] = 'Informe o SKU. Na planilha, a linha sem SKU não existe.';
        } elseif (mb_strlen($sku) > 120) {
            $erros['sku'] = 'O SKU pode ter no máximo 120 caracteres.';
        }

        $fase = $dados['fase'] ?? null;
        if (! array_key_exists((string) $fase, EstruturaOferta::FASES)) {
            $erros['fase'] = 'Fase inválida.';
        }

        $logistica = $dados['logistica'] ?? null;
        if ($logistica !== null && $logistica !== '' && ! array_key_exists($logistica, EstruturaOferta::LOGISTICAS)) {
            $erros['logistica'] = 'Logística inválida.';
        }

        if ($erros) {
            throw ValidationException::withMessages($erros);
        }

        $nome = trim((string) ($dados['nome'] ?? ''));
        $obs = trim((string) ($dados['observacoes'] ?? ''));

        return [
            'sku'         => $sku,
            'fase'        => $fase,
            'nome'        => $nome === '' ? null : mb_substr($nome, 0, 255),
            'logistica'   => $logistica ?: null,
            'observacoes' => $obs === '' ? null : $obs,
        ];
    }

    /**
     * Confere a composição contra a fase. Devolve `[componente_id => quantidade]`.
     *
     * @param  array<int, array{id: int, quantidade: int}>  $componentes
     * @return array<int, int>
     */
    private function composicao(Company $empresa, string $fase, array $componentes, ?int $propria = null): array
    {
        $porId = [];
        foreach ($componentes as $c) {
            $id = (int) ($c['id'] ?? 0);
            $qtd = (int) ($c['quantidade'] ?? 0);

            if ($id <= 0 || $qtd < 1 || $qtd > 999) {
                throw ValidationException::withMessages(['componentes' => 'Cada item da composição precisa de um produto e de uma quantidade entre 1 e 999.']);
            }
            if (isset($porId[$id])) {
                throw ValidationException::withMessages(['componentes' => 'O mesmo produto aparece duas vezes na composição. Some as quantidades numa linha só.']);
            }

            $porId[$id] = $qtd;
        }

        $n = count($porId);
        $algumMaiorQueUm = $porId && max($porId) >= 2;

        $erro = match ($fase) {
            EstruturaOferta::FASE_SIMPLES => $n === 0 ? null
                : 'Uma oferta simples é 1 unidade do produto, sem composição.',
            EstruturaOferta::FASE_COMBO => ($n === 1 && $algumMaiorQueUm) ? null
                : 'Um combo é o mesmo produto em mais unidades: um produto, com quantidade 2 ou mais.',
            EstruturaOferta::FASE_KIT => ($n >= 2 && ! $algumMaiorQueUm) ? null
                : 'Um kit junta produtos diferentes, uma unidade de cada. Com mais unidades de algum item, é um combit.',
            EstruturaOferta::FASE_COMBIT => ($n >= 2 && $algumMaiorQueUm) ? null
                : 'Um combit é um kit com mais unidades de algum item: dois ou mais produtos, algum com quantidade 2 ou mais.',
            default => 'Fase inválida.',
        };

        if ($erro) {
            throw ValidationException::withMessages(['componentes' => $erro]);
        }

        if ($n === 0) {
            return [];
        }

        if ($propria !== null && isset($porId[$propria])) {
            throw ValidationException::withMessages(['componentes' => 'Uma oferta não pode entrar na própria composição.']);
        }

        // Da MESMA empresa e SIMPLES. Um id de outra empresa cai aqui com a
        // mesma mensagem de um id inexistente — dizer "é de outro cliente"
        // confirmaria que ele existe.
        $validos = EstruturaOferta::where('company_id', $empresa->id)
            ->where('fase', EstruturaOferta::FASE_SIMPLES)
            ->whereIn('id', array_keys($porId))
            ->count();

        if ($validos !== $n) {
            throw ValidationException::withMessages(['componentes' => 'A composição só pode usar produtos simples desta lista.']);
        }

        return $porId;
    }

    private function gravarComposicao(EstruturaOferta $oferta, array $componentes): void
    {
        foreach ($componentes as $id => $qtd) {
            $oferta->componentes()->create(['componente_id' => $id, 'quantidade' => $qtd]);
        }
    }
}
