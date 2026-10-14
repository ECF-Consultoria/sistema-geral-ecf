<?php

namespace App\Services\Bling;

use App\Jobs\LerProdutosDoBlingJob;
use App\Models\BlingConexao;
use App\Models\BlingVinculo;
use App\Models\Company;
use App\Models\EstruturaProdutoVariacao;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use App\Support\Portal\AtorDoPortal;
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

    /**
     * Refaz o plano do ZERO (sem estado — o navegador não manda o que gravar)
     * e grava em lotes de até `self::LOTE` linhas, pelo caminho único de
     * escrita de produto com `agendarPreparo: false` — fecha os DOIS caminhos
     * até o agendamento do preparo pela IA (risco 5 do seed: importar 500
     * produtos agendaria centenas de cadeias de IA na fila que publica
     * anúncio e manda o código de acesso do Portal).
     *
     * `nao_entraram` junta, na mesma lista, as recusas do PLANO (código vazio,
     * repetido, formato não suportado) e as falhas da GRAVAÇÃO (categoria
     * inválida, código que bate no unique do banco) — o mesmo precedente
     * BE-WR-04 do `ImportadorProdutos`: sem isso a tela diria "concluída" com
     * produto que não existe.
     *
     * Vínculos órfãos (produto que estava vinculado e não apareceu nesta
     * rodada) viram `BlingVinculo::SITUACAO_REMOVIDO_NO_BLING`, com motivo —
     * NADA é apagado no Portal: nem variação, nem produto, nem oferta. Só
     * roda com a rodada CONCLUÍDA: uma rodada interrompida no meio não leu o
     * catálogo inteiro, e marcaria como removido tudo o que ainda não viu.
     *
     * Esta classe nunca chama, direta ou indiretamente, o agendamento do
     * preparo pela IA. Se um dia for preciso levar os produtos importados ao
     * Publicador, o caminho é um Job de sincronização por produto, só para
     * quem tem rascunho ainda não publicado, despachado no FIM — não
     * implementado aqui (seed §5).
     *
     * @return array{erro_geral: ?string, novos: int, atualizados: int, sem_mudanca: int, erros: int, nao_entraram: list<array{codigo: ?string, motivo: string}>, vinculos_criados: int, vinculos_removidos: int, avisos: list<string>}
     */
    public function aplicar(Company $empresa, string $rodada, AtorDoPortal $ator): array
    {
        $plano = $this->plano($empresa, $rodada);

        if ($plano['erro_geral'] !== null) {
            return [
                'erro_geral' => $plano['erro_geral'],
                'novos' => 0, 'atualizados' => 0, 'sem_mudanca' => 0, 'erros' => 0,
                'nao_entraram' => [], 'vinculos_criados' => 0, 'vinculos_removidos' => 0, 'avisos' => [],
            ];
        }

        $linhas = $plano['linhas'];
        $vinculosDaLinha = $plano['vinculos'];

        $naoEntraram = array_map(
            fn (array $r) => ['codigo' => $r['codigo'] ?? null, 'motivo' => (string) $r['motivo']],
            $plano['erros'],
        );

        $totais = ['criadas' => 0, 'atualizadas' => 0, 'sem_mudanca' => 0, 'com_erro' => 0];
        $vinculosCriados = 0;
        $avisos = $plano['avisos'];

        foreach (array_chunk($linhas, self::LOTE, true) as $loteComIndice) {
            $indices = array_keys($loteComIndice);
            $loteLinhas = array_values($loteComIndice);

            $res = $this->cadastro->gravarLinhas($empresa, $loteLinhas, $ator, ProdutoCadastroService::MODO_IMPORTACAO, agendarPreparo: false);

            $totais['criadas'] += $res['totais']['criadas'];
            $totais['atualizadas'] += $res['totais']['atualizadas'];
            $totais['sem_mudanca'] += $res['totais']['sem_mudanca'];
            $totais['com_erro'] += $res['totais']['com_erro'];
            $avisos = [...$avisos, ...$res['avisos']];

            foreach ($res['erros'] as $e) {
                $naoEntraram[] = ['codigo' => $e['codigo'] ?? null, 'motivo' => (string) $e['mensagem']];
            }

            $variacaoIdPorCodigo = [];
            foreach ($res['linhas'] as $l) {
                if (isset($l['codigo'])) {
                    $variacaoIdPorCodigo[ProdutoCadastroService::chaveCodigo((string) $l['codigo'])] = (int) $l['id'];
                }
            }

            foreach ($indices as $posicao => $indiceOriginal) {
                $linha = $loteLinhas[$posicao];
                $vinculo = $vinculosDaLinha[$indiceOriginal] ?? null;
                if ($vinculo === null) {
                    continue;
                }

                $chave = ProdutoCadastroService::chaveCodigo((string) ($linha['codigo'] ?? ''));
                $variacaoId = $variacaoIdPorCodigo[$chave] ?? null;
                if ($variacaoId === null) {
                    // A linha não foi gravada (entrou em nao_entraram): sem variação, sem vínculo.
                    continue;
                }

                $existiaAntes = BlingVinculo::query()
                    ->where('company_id', $empresa->id)
                    ->where('bling_produto_id', $vinculo['bling_produto_id'])
                    ->exists();

                BlingVinculo::updateOrCreate(
                    ['company_id' => $empresa->id, 'bling_produto_id' => $vinculo['bling_produto_id']],
                    [
                        'bling_pai_id' => $vinculo['bling_pai_id'] ?? null,
                        'variacao_id'  => $variacaoId,
                        'codigo_bling' => $vinculo['codigo_bling'] ?? null,
                        'formato'      => $vinculo['formato'] ?? null,
                        'gtin'         => $vinculo['gtin'] ?? null,
                        'situacao'     => BlingVinculo::SITUACAO_VINCULADO,
                        'motivo'       => null,
                        // A memória de "quem escreveu" (CONTEXT §4.1): o próximo Sincronizar
                        // compara contra isto para saber se o cliente editou por cima.
                        'escrito' => json_encode([
                            'nome'      => $linha['nome'] ?? null,
                            'descricao' => $linha['descricao'] ?? null,
                            'custo'     => $linha['custo'] ?? null,
                            'volumes'   => $linha['volumes'] ?? null,
                            'codigo'    => $linha['codigo'] ?? null,
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                );

                if (! $existiaAntes) {
                    $vinculosCriados++;
                }
            }
        }

        $vinculosRemovidos = 0;
        if ($plano['progresso']['concluida']) {
            $idsNestaRodada = array_values(array_filter(array_column($vinculosDaLinha, 'bling_produto_id')));

            $orfaos = BlingVinculo::query()
                ->where('company_id', $empresa->id)
                ->where('situacao', BlingVinculo::SITUACAO_VINCULADO)
                ->when($idsNestaRodada !== [], fn ($q) => $q->whereNotIn('bling_produto_id', $idsNestaRodada))
                ->get();

            foreach ($orfaos as $vinculo) {
                $vinculo->situacao = BlingVinculo::SITUACAO_REMOVIDO_NO_BLING;
                $vinculo->motivo = 'O produto não apareceu na última leitura do Bling — pode ter sido removido ou inativado lá.';
                $vinculo->save();
                $vinculosRemovidos++;
            }
        }

        $conexao = BlingConexao::where('company_id', $empresa->id)->first();
        if ($conexao) {
            $conexao->ultima_importacao_em = now();
            $conexao->save();
        }

        return [
            'erro_geral'         => null,
            'novos'              => $totais['criadas'],
            'atualizados'        => $totais['atualizadas'],
            'sem_mudanca'        => $totais['sem_mudanca'],
            'erros'              => count($plano['erros']) + $totais['com_erro'],
            'nao_entraram'       => $naoEntraram,
            'vinculos_criados'   => $vinculosCriados,
            'vinculos_removidos' => $vinculosRemovidos,
            'avisos'             => array_values(array_unique($avisos)),
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
