<?php

namespace App\Services\Publicador\Agente;

use App\Models\PubAgenteConfiguracao;
use App\Models\PubAgenteExecucao;
use App\Models\PubProduto;
use App\Models\User;
use App\Support\Publicador\RegraViolada;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admite e encerra execuções de preparo do agente, sem duplicar trabalho (AG-01, 20/10/2026).
 *
 * Deduplicação em duas camadas, ambas no banco (lock de cache não é registro durável):
 *  1. Uma execução VIVA por produto da conta — `chave_ativa` unique. Pedido repetido (duplo clique, save +
 *     "Preparar agora", dois workers) devolve a viva.
 *  2. A mesma `chave_logica` (conta + produto + modalidade + fatos) já terminada com resultado devolve a
 *     terminada: os mesmos fatos não pagam preparo de novo. Fatos novos = execução nova. Execução que falhou
 *     ou foi cancelada não segura: pedir de novo admite outra.
 *
 * A configuração da conta é travada (`lockForUpdate`) durante a admissão, o que serializa admissões da mesma
 * loja — a base para a reserva de limite da AG-06.
 */
class ExecucoesDoAgente
{
    public function __construct(private PoliticaDoAgente $politica) {}

    /**
     * @return array{execucao: PubAgenteExecucao, nova: bool}
     *
     * @throws RegraViolada quando a política recusa
     */
    public function admitir(array $conta, PubProduto $produto, string $origem, ?User $quem = null, ?string $versaoDosFatos = null): array
    {
        if (! in_array($origem, PubAgenteExecucao::ORIGENS, true)) {
            throw new \InvalidArgumentException("Origem desconhecida: {$origem}");
        }

        $chaveAtiva = PubAgenteExecucao::chaveAtiva($conta['chave'], $produto->id);

        try {
            return DB::transaction(function () use ($conta, $produto, $origem, $quem, $versaoDosFatos, $chaveAtiva) {
                $cfg = PubAgenteConfiguracao::query()->where('conta_chave', $conta['chave'])->lockForUpdate()->first();

                if ($recusa = $this->politica->recusaParaAdmitir($conta, $cfg, $produto, $origem)) {
                    throw new RegraViolada($recusa['regra'], $recusa['mensagem']);
                }

                $viva = PubAgenteExecucao::query()->where('chave_ativa', $chaveAtiva)->lockForUpdate()->first();
                if ($viva !== null) {
                    return ['execucao' => $viva, 'nova' => false];
                }

                $chaveLogica = self::chaveLogica($conta['chave'], $produto->id, $cfg->modalidade, $versaoDosFatos);
                $feita = PubAgenteExecucao::query()
                    ->where('chave_logica', $chaveLogica)
                    ->whereIn('estado', PubAgenteExecucao::TERMINADAS_COM_RESULTADO)
                    ->latest('id')
                    ->first();
                if ($feita !== null) {
                    return ['execucao' => $feita, 'nova' => false];
                }

                // Transação aninhada (savepoint): o unique de `chave_ativa` falhando não derruba a de fora.
                $nova = DB::transaction(fn () => PubAgenteExecucao::create([
                    'uuid' => (string) Str::uuid(),
                    'configuracao_id' => $cfg->id,
                    'conta_chave' => $conta['chave'],
                    'produto_id' => $produto->id,
                    'origem' => $origem,
                    'configuracao_versao' => $cfg->versao,
                    'versao_dos_fatos' => $versaoDosFatos,
                    'chave_logica' => $chaveLogica,
                    'chave_ativa' => $chaveAtiva,
                    'estado' => PubAgenteExecucao::ADMITIDA,
                    'solicitada_por' => $quem?->id,
                    'iniciada_em' => now(),
                    'heartbeat_em' => now(),
                ]));

                return ['execucao' => $nova, 'nova' => true];
            });
        } catch (QueryException $e) {
            // Outro processo admitiu o mesmo produto entre a leitura e o insert: devolve a dele.
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            $viva = PubAgenteExecucao::query()->where('chave_ativa', $chaveAtiva)->first();
            if ($viva === null) {
                throw $e;
            }

            return ['execucao' => $viva, 'nova' => false];
        }
    }

    /** Termina a execução e libera a vaga de "viva" do produto. */
    public function encerrar(PubAgenteExecucao $execucao, string $estado, ?string $motivo = null): PubAgenteExecucao
    {
        if (in_array($estado, PubAgenteExecucao::VIVAS, true)) {
            throw new \InvalidArgumentException("Estado {$estado} não encerra uma execução.");
        }

        $execucao->update([
            'estado' => $estado,
            'chave_ativa' => null,
            'motivo' => $motivo !== null ? mb_substr($motivo, 0, 500) : $execucao->motivo,
            'concluida_em' => now(),
        ]);

        return $execucao;
    }

    public static function chaveLogica(string $contaChave, int $produtoId, ?string $modalidade, ?string $versaoDosFatos): string
    {
        return hash('sha256', implode('|', [$contaChave, $produtoId, $modalidade ?? '', $versaoDosFatos ?? '']));
    }
}
