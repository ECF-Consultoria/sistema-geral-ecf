<?php

namespace App\Services\Portal\Estrutura\Produtos\MercadoLivre;

use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDaCategoria;

/**
 * O diagnóstico de UM anúncio para a tela "Importar do Mercado Livre" (28/10/2026): o que
 * está fraco no cadastro dele e o que os números dizem, para a pessoa decidir o que vale importar
 * e jardinar pela metodologia.
 *
 * Função PURA: recebe o anúncio, os campos da ficha da categoria (já montados por
 * {@see FichaTecnicaDaCategoria}) e as métricas; não lê nada.
 *
 * ### Dois tipos de problema
 * - **Cadastro** (o que a importação + IA resolvem): título curto, sem Modelo ou Modelo fora do
 *   padrão ECF (lista de buscas separadas por vírgula, perto de 120 caracteres), campo obrigatório
 *   faltando, ficha com menos de 60% dos campos, sem medidas do produto, sem a caixa.
 * - **Desempenho** (o sintoma): pouca visita, muita visita e pouca venda, nenhuma venda em 30 dias.
 *   Só para anúncio ATIVO: pausado ou encerrado não recebe visita nem venda, e isso não diz nada do cadastro.
 *
 * Prioridade: "alta" com 2+ problemas de cadastro, ou 1 de cadastro com 1 de desempenho; "média"
 * com qualquer um; "baixa" sem nenhum. Os cortes são heurística de triagem, não régua de nota:
 * a nota ECF (0–86) e a saúde do ML vêm prontas do acervo e NÃO são recalculadas aqui.
 */
final class DiagnosticoDoAnuncio
{
    /** Título com menos que isso do limite da categoria é "curto". */
    public const FRACAO_TITULO = 0.75;

    /** O limite de título quando a categoria não diz o dela. */
    public const MAX_TITULO_PADRAO = 60;

    /** Modelo no padrão ECF: lista de buscas com vírgula. Sem vírgula e mais curto que isto = fora do padrão. */
    public const MODELO_MINIMO = 60;

    /** Ficha com menos que esta fração dos campos preenchida = incompleta. */
    public const FRACAO_FICHA = 0.6;

    /** Visitas em 30 dias. Anúncio mais novo que 30 dias: proporcional aos dias que está no ar. */
    public const POUCA_VISITA = 100;
    public const MUITA_VISITA = 300;

    /**
     * Abaixo disto o anúncio é novo demais para os números dizerem algo (o usuário viu "Pouca visita: 3 em 30
     * dias" num anúncio de poucos dias, 28/10/2026): nenhum problema de desempenho.
     */
    public const DIAS_MINIMOS = 7;

    /** A janela das visitas e vendas. */
    public const JANELA_DIAS = 30;
    /** Conversão abaixo disto (em %), com muita visita = "muita visita, pouca venda". */
    public const CONVERSAO_BAIXA = 1.0;

    private const CAIXA = ['SELLER_PACKAGE_LENGTH', 'SELLER_PACKAGE_WIDTH', 'SELLER_PACKAGE_HEIGHT', 'SELLER_PACKAGE_WEIGHT'];

    /**
     * @param  array  $item  corpo do multiget do anúncio
     * @param  array<string, array>  $campos  campos da ficha da categoria, por id ({@see FichaTecnicaDaCategoria::camposPorId()})
     * @param  array{vendas_30d: ?int, visitas_30d: ?int}  $metricas  null = ainda não lido
     * @return array{
     *     titulo: array{texto: string, chars: int, max: int, curto: bool},
     *     modelo: array{valor: ?string, vazio: bool, fora_do_padrao: bool},
     *     campos: array{preenchidos: int, total: int, obrigatorios_faltando: list<string>},
     *     medidas: array{produto: ?bool, caixa: bool},
     *     fotos: int,
     *     metricas: array{vendas_30d: ?int, visitas_30d: ?int, conversao: ?float, dias: int},
     *     problemas: list<array{chave: string, tipo: string, texto: string}>,
     *     prioridade: string
     * }
     */
    public static function avaliar(array $item, array $campos, array $metricas, ?int $maxTitulo = null, ?\DateTimeInterface $agora = null): array
    {
        $atributos = [];
        foreach ((array) ($item['attributes'] ?? []) as $a) {
            if (is_array($a) && isset($a['id'])) {
                $atributos[strtoupper((string) $a['id'])] = $a;
            }
        }
        $tem = fn (string $id) => isset($atributos[$id]) && (TradutorAnuncioMlParaPortal::valor($atributos[$id]) !== null
            || (isset($atributos[$id]['value_id']) && (string) $atributos[$id]['value_id'] !== ''));

        $problemas = [];

        // ─── Título ───
        $titulo = trim((string) ($item['title'] ?? ''));
        $max = $maxTitulo !== null && $maxTitulo > 0 ? $maxTitulo : self::MAX_TITULO_PADRAO;
        $chars = mb_strlen($titulo);
        $curto = $chars < (int) floor($max * self::FRACAO_TITULO);
        if ($curto) {
            $problemas[] = ['chave' => 'titulo_curto', 'tipo' => 'cadastro', 'texto' => "Título curto: usa {$chars} de {$max} caracteres"];
        }

        // ─── Modelo ───
        $modelo = TradutorAnuncioMlParaPortal::valor($atributos['MODEL'] ?? null);
        $vazio = $modelo === null;
        $foraDoPadrao = ! $vazio && ! str_contains($modelo, ',') && mb_strlen($modelo) < self::MODELO_MINIMO;
        if ($vazio) {
            $problemas[] = ['chave' => 'sem_modelo', 'tipo' => 'cadastro', 'texto' => 'Sem o campo Modelo'];
        } elseif ($foraDoPadrao) {
            $problemas[] = ['chave' => 'modelo_fora_do_padrao', 'tipo' => 'cadastro', 'texto' => 'Modelo fora do padrão (sem as buscas)'];
        }

        // ─── Ficha da categoria ───
        $total = 0;
        $preenchidos = 0;
        $obrigatoriosFaltando = [];
        $temMedidaDoProduto = null;
        foreach ($campos as $id => $campo) {
            $total++;
            $ok = $tem((string) $id);
            if ($ok) {
                $preenchidos++;
            } elseif ($campo['obrigatorio'] ?? false) {
                $obrigatoriosFaltando[] = (string) ($campo['nome'] ?? $id);
            }
            if (in_array((string) $id, FichaTecnicaDaCategoria::IDS_MEDIDA_DO_PRODUTO, true)) {
                $temMedidaDoProduto = ($temMedidaDoProduto ?? false) || $ok;
            }
        }
        if ($obrigatoriosFaltando !== []) {
            $n = count($obrigatoriosFaltando);
            $problemas[] = ['chave' => 'obrigatorio_faltando', 'tipo' => 'cadastro',
                'texto' => $n === 1 ? "Falta 1 campo obrigatório: {$obrigatoriosFaltando[0]}" : "Faltam {$n} campos obrigatórios"];
        }
        if ($total > 0 && $preenchidos < $total * self::FRACAO_FICHA) {
            $problemas[] = ['chave' => 'ficha_incompleta', 'tipo' => 'cadastro', 'texto' => "Ficha incompleta: {$preenchidos} de {$total} campos"];
        }
        if ($temMedidaDoProduto === false) {
            $problemas[] = ['chave' => 'sem_medidas_produto', 'tipo' => 'cadastro', 'texto' => 'Sem as medidas do produto'];
        }

        $caixa = true;
        foreach (self::CAIXA as $id) {
            $caixa = $caixa && $tem($id);
        }
        if (! $caixa && ! is_string($item['shipping']['dimensions'] ?? null)) {
            $problemas[] = ['chave' => 'sem_caixa', 'tipo' => 'cadastro', 'texto' => 'Sem as medidas da caixa'];
        }

        // ─── Números ───
        $vendas = isset($metricas['vendas_30d']) ? (int) $metricas['vendas_30d'] : null;
        $visitas = isset($metricas['visitas_30d']) ? (int) $metricas['visitas_30d'] : null;
        $conversao = ($vendas !== null && $visitas !== null && $visitas > 0) ? round($vendas / $visitas * 100, 2) : null;

        // Há quantos dias o anúncio está no ar (até a janela de 30): a régua e o texto usam os dias reais.
        $dias = self::diasNoAr($item, $agora);
        $proporcao = $dias / self::JANELA_DIAS;
        $emDias = $dias === 1 ? 'em 1 dia' : "em {$dias} dias";

        // Pausado ou encerrado não recebe visita nem venda; anúncio novo ainda não tem número que diga algo.
        // Nos dois casos os números continuam na tela; só não viram "problema".
        $avaliaDesempenho = ($item['status'] ?? 'active') === 'active' && $dias >= self::DIAS_MINIMOS;
        if (! $avaliaDesempenho) {
            // nada
        } elseif ($visitas !== null && $visitas < self::POUCA_VISITA * $proporcao) {
            $problemas[] = ['chave' => 'pouca_visita', 'tipo' => 'desempenho', 'texto' => "Pouca visita: {$visitas} {$emDias}"];
        } elseif ($visitas !== null && $visitas >= self::MUITA_VISITA * $proporcao && $conversao !== null && $conversao < self::CONVERSAO_BAIXA) {
            $problemas[] = ['chave' => 'visita_sem_venda', 'tipo' => 'desempenho', 'texto' => "Muita visita e pouca venda: {$visitas} visitas {$emDias}, conversão de ".self::pct($conversao)];
        }
        if ($avaliaDesempenho && $vendas === 0) {
            $problemas[] = ['chave' => 'sem_venda', 'tipo' => 'desempenho', 'texto' => "Nenhuma venda {$emDias}"];
        }

        $deCadastro = count(array_filter($problemas, fn ($p) => $p['tipo'] === 'cadastro'));
        $deDesempenho = count($problemas) - $deCadastro;
        $prioridade = match (true) {
            $deCadastro >= 2, $deCadastro >= 1 && $deDesempenho >= 1 => 'alta',
            $deCadastro + $deDesempenho > 0 => 'media',
            default => 'baixa',
        };

        $fotos = count((array) ($item['pictures'] ?? []));

        return [
            'titulo'     => ['texto' => $titulo, 'chars' => $chars, 'max' => $max, 'curto' => $curto],
            'modelo'     => ['valor' => $modelo, 'vazio' => $vazio, 'fora_do_padrao' => $foraDoPadrao],
            'campos'     => ['preenchidos' => $preenchidos, 'total' => $total, 'obrigatorios_faltando' => $obrigatoriosFaltando],
            'medidas'    => ['produto' => $temMedidaDoProduto, 'caixa' => $caixa],
            'fotos'      => $fotos,
            'metricas'   => ['vendas_30d' => $vendas, 'visitas_30d' => $visitas, 'conversao' => $conversao, 'dias' => $dias],
            'problemas'  => $problemas,
            'prioridade' => $prioridade,
        ];
    }

    /** Dias no ar, de 1 até a janela; sem data no anúncio, a janela inteira (como era antes). */
    public static function diasNoAr(array $item, ?\DateTimeInterface $agora = null): int
    {
        $criado = $item['start_time'] ?? $item['date_created'] ?? null;
        if (! is_string($criado) || trim($criado) === '') {
            return self::JANELA_DIAS;
        }
        try {
            $inicio = new \DateTimeImmutable($criado);
        } catch (\Throwable) {
            return self::JANELA_DIAS;
        }
        $horas = (($agora ?? new \DateTimeImmutable())->getTimestamp() - $inicio->getTimestamp()) / 3600;

        return (int) max(1, min(self::JANELA_DIAS, ceil($horas / 24)));
    }

    private static function pct(float $v): string
    {
        return number_format($v, $v < 10 ? 1 : 0, ',', '.').'%';
    }
}
