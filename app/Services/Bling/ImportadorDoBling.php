<?php

namespace App\Services\Bling;

use App\Jobs\LerProdutosDoBlingJob;
use App\Models\BlingVinculo;
use App\Models\Company;
use App\Models\EstruturaProdutoVariacao;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use Illuminate\Support\Facades\Cache;

/**
 * Fase 184 (BLING-23, BLING-32) — a importação do catálogo do Bling para o
 * cadastro de Produtos: `previa()` (esta Task) e `aplicar()` (Task 3) sem
 * estado, no mesmo molde de `ImportadorProdutos` (plano/aplicar — o navegador
 * não manda o que gravar; a confirmação refaz o plano do zero a partir da
 * rodada já lida pelo `LerProdutosDoBlingJob`).
 *
 * ### `previa()` não grava NADA
 * Nem produto, nem variação, nem vínculo, nem Job. Ela só lê o estado da
 * rodada (cache) e os dados já cadastrados da empresa (banco).
 *
 * ### Vínculo pelo id do Bling, nunca pelo SKU
 * `TradutorBlingParaPortal::paraLinhas()` já traduz 1 produto por vez; esta
 * classe vê o LOTE inteiro, então é aqui — e só aqui — que um código repetido
 * ENTRE produtos diferentes do Bling é pego (o tradutor não tem como saber
 * disso, pois só vê um produto).
 *
 * ### Sigilo
 * Nenhum motivo de recusa, aviso ou texto desta classe cita a plataforma de
 * venda — a mesma regra do `ImportadorProdutos`.
 */
final class ImportadorDoBling
{
    /** Mesmo teto de detalhe por grupo do `ImportadorProdutos`. */
    public const DETALHE_MAXIMO = 200;

    /**
     * 200, abaixo do `ProdutoCadastroService::MAX_IMPORTACAO` (1000) de
     * propósito: é o tamanho que o seed §7 fixou para manter cada transação de
     * gravação curta, mesmo numa importação de centenas de produtos (Task 3).
     */
    public const LOTE = 200;

    public function __construct(private ProdutoCadastroService $cadastro) {}

    /**
     * @return array{erro_geral: ?string, totais: array{novos: int, atualizados: int, sem_mudanca: int, erros: int}, grupos: array{novos: list<array>, atualizados: list<array>, sem_mudanca: list<array>, erros: list<array>}, avisos: list<string>, progresso: array{concluida: bool, lidos: int}}
     */
    public function previa(Company $empresa, string $rodada): array
    {
        $plano = $this->plano($empresa, $rodada);

        if ($plano['erro_geral'] !== null) {
            return [
                'erro_geral' => $plano['erro_geral'],
                'totais'     => ['novos' => 0, 'atualizados' => 0, 'sem_mudanca' => 0, 'erros' => 0],
                'grupos'     => ['novos' => [], 'atualizados' => [], 'sem_mudanca' => [], 'erros' => []],
                'avisos'     => [],
                'progresso'  => ['concluida' => false, 'lidos' => 0],
            ];
        }

        $grupos = [];
        foreach (['novos', 'atualizados', 'sem_mudanca', 'erros'] as $g) {
            $grupos[$g] = array_slice($plano[$g], 0, self::DETALHE_MAXIMO);
        }

        return [
            'erro_geral' => null,
            'totais'     => [
                'novos'       => count($plano['novos']),
                'atualizados' => count($plano['atualizados']),
                'sem_mudanca' => count($plano['sem_mudanca']),
                'erros'       => count($plano['erros']),
            ],
            'grupos'    => $grupos,
            'avisos'    => $plano['avisos'],
            'progresso' => $plano['progresso'],
        ];
    }

    // ═══ Plano ══════════════════════════════════════════════════════════════

    /**
     * A função SEM ESTADO que `previa()` e `aplicar()` (Task 3) compartilham:
     * lê a rodada do cache, traduz cada produto, deduplica código no LOTE
     * INTEIRO (o tradutor só vê um produto por vez) e classifica cada linha
     * contra o que a empresa já tem — numa consulta por tabela, nunca dentro
     * do laço de classificação.
     *
     * Nunca grava nada: nenhum verbo de escrita do Eloquent (criar, atualizar
     * ou salvar um model) aparece neste método.
     *
     * @return array{erro_geral: ?string, novos: list<array>, atualizados: list<array>, sem_mudanca: list<array>, erros: list<array{codigo: ?string, motivo: string}>, linhas: list<array>, vinculos: list<?array>, avisos: list<string>, progresso: array{concluida: bool, lidos: int}}
     */
    private function plano(Company $empresa, string $rodada): array
    {
        $vazio = fn (string $erro) => [
            'erro_geral' => $erro,
            'novos' => [], 'atualizados' => [], 'sem_mudanca' => [], 'erros' => [],
            'linhas' => [], 'vinculos' => [], 'avisos' => [],
            'progresso' => ['concluida' => false, 'lidos' => 0],
        ];

        $estado = Cache::get(LerProdutosDoBlingJob::chaveEstado((int) $empresa->id, $rodada));

        if (! is_array($estado)) {
            return $vazio('A leitura do catálogo do Bling não foi encontrada ou expirou. Gere uma nova leitura e tente de novo.');
        }

        $linhas = [];
        $vinculos = [];
        $recusadas = [];
        $avisos = [];
        $vistosNoLote = []; // chaveCodigo => true, para o segundo código repetido ir para erros

        $produtosDaRodada = is_array($estado['produtos'] ?? null) ? $estado['produtos'] : [];
        foreach ($produtosDaRodada as $produto) {
            $traduzido = TradutorBlingParaPortal::paraLinhas(is_array($produto) ? $produto : []);

            $recusadas = [...$recusadas, ...$traduzido['recusadas']];
            $avisos = [...$avisos, ...$traduzido['avisos']];

            foreach ($traduzido['linhas'] as $i => $linha) {
                $vinculo = $traduzido['vinculos'][$i] ?? null;
                $chave = ProdutoCadastroService::chaveCodigo((string) ($linha['codigo'] ?? ''));

                if ($chave !== '' && isset($vistosNoLote[$chave])) {
                    $recusadas[] = [
                        'bling_produto_id' => $vinculo['bling_produto_id'] ?? null,
                        'codigo'           => $linha['codigo'] ?? null,
                        'motivo'           => "O código \"{$linha['codigo']}\" já apareceu em outro produto nesta leitura do Bling — mantivemos só o primeiro.",
                    ];
                    continue;
                }
                if ($chave !== '') {
                    $vistosNoLote[$chave] = true;
                }

                $linhas[] = $linha;
                $vinculos[] = $vinculo;
            }
        }

        $avisos = array_values(array_unique($avisos));

        // ─── O que já existe, numa consulta por tabela (nunca dentro do laço de classificação) ───
        $codigosDoLote = array_values(array_unique(array_map(
            fn (array $l) => (string) ($l['codigo'] ?? ''),
            array_filter($linhas, fn (array $l) => trim((string) ($l['codigo'] ?? '')) !== ''),
        )));
        $variacoesExistentes = $codigosDoLote === [] ? collect() : EstruturaProdutoVariacao::query()
            ->where('company_id', $empresa->id)
            ->whereIn('codigo', $codigosDoLote)
            ->pluck('codigo')
            ->map(fn ($c) => ProdutoCadastroService::chaveCodigo((string) $c))
            ->flip();

        $idsBlingDoLote = array_values(array_unique(array_filter(array_column($vinculos, 'bling_produto_id'))));
        $vinculosExistentes = $idsBlingDoLote === [] ? collect() : BlingVinculo::query()
            ->where('company_id', $empresa->id)
            ->whereIn('bling_produto_id', $idsBlingDoLote)
            ->pluck('bling_produto_id')
            ->flip();

        $novos = [];
        $atualizados = [];
        foreach ($linhas as $k => $l) {
            $chave = ProdutoCadastroService::chaveCodigo((string) ($l['codigo'] ?? ''));
            $blingId = $vinculos[$k]['bling_produto_id'] ?? null;
            $item = ['codigo' => $l['codigo'] ?? null, 'nome' => $l['nome'] ?? null];

            $jaExiste = $variacoesExistentes->has($chave) || ($blingId !== null && $vinculosExistentes->has($blingId));

            if ($jaExiste) {
                $atualizados[] = $item;
            } else {
                $novos[] = $item;
            }
        }

        $erros = array_map(fn (array $r) => ['codigo' => $r['codigo'] ?? null, 'motivo' => (string) $r['motivo']], $recusadas);

        return [
            'erro_geral'  => null,
            'novos'       => $novos,
            'atualizados' => $atualizados,
            // Fora de escopo deste plano: distinguir "sem mudança nenhum campo" exigiria repetir
            // a comparação campo a campo que `ProdutoCadastroService::gravarLinhas` já faz na
            // gravação de verdade — toda variação já existente ou já vinculada entra em
            // "atualizados" na prévia; é `aplicar()` (via `gravarLinhas`) quem decide, na gravação
            // real, se de fato mudou algo.
            'sem_mudanca' => [],
            'erros'       => $erros,
            'linhas'      => $linhas,
            'vinculos'    => $vinculos,
            'avisos'      => $avisos,
            'progresso'   => ['concluida' => (bool) ($estado['concluida'] ?? false), 'lidos' => (int) ($estado['lidos'] ?? 0)],
        ];
    }
}
