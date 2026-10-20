<?php

namespace App\Services\Publicador\Agente;

use App\Models\PubAgenteConfiguracao;
use App\Models\PubAgenteExecucao;
use App\Models\PubAprovacaoOferta;
use App\Models\PubProduto;
use App\Services\Publicador\Aprovacao\AndamentoDaOfertaService;
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
    public const REGRA_LIMITE_DIARIO = 'AG-06-LIMITE-DIARIO';
    public const REGRA_TETO_FILA = 'AG-06-TETO-FILA';

    /** Andamentos em que a oferta espera gente na Aprovação (ocupa vaga do teto). */
    private const ESPERANDO_GENTE = [
        PubAprovacaoOferta::AGUARDANDO_APROVACAO, PubAprovacaoOferta::EM_REVISAO_ECF, PubAprovacaoOferta::AJUSTES_SOLICITADOS,
    ];

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

                // AG-06: só trabalho NOVO reserva vaga — devolver a viva ou a feita acima não gasta nada.
                if ($recusa = $this->recusaDeCapacidade($conta, $cfg)) {
                    throw new RegraViolada($recusa['regra'], $recusa['mensagem']);
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

    /**
     * Limite diário e teto da fila de Aprovação (AG-06), contados AQUI — dentro da transação que travou a
     * configuração da conta, então duas admissões da mesma loja nunca usam a última vaga juntas.
     *
     * - `limite_diario` conta PRODUTOS admitidos hoje (fuso de São Paulo) que gastaram ou podem gastar preparo:
     *   bloqueada pela elegibilidade e cancelada não contam.
     * - `teto_fila` conta OFERTAS esperando gente na Aprovação desta conta + execuções vivas (as vagas já
     *   reservadas por trabalho em curso). No piloto 1 produto = 1 oferta; com Clássico + Premium não seria.
     *
     * @return array{regra: string, mensagem: string}|null
     */
    private function recusaDeCapacidade(array $conta, PubAgenteConfiguracao $cfg): ?array
    {
        $inicioDoDia = now()->setTimezone('America/Sao_Paulo')->startOfDay()->setTimezone(config('app.timezone'));
        $hoje = PubAgenteExecucao::query()
            ->where('conta_chave', $conta['chave'])
            ->where('iniciada_em', '>=', $inicioDoDia)
            ->whereNotIn('estado', [PubAgenteExecucao::BLOQUEADA, PubAgenteExecucao::CANCELADA])
            ->count();
        if ($hoje >= (int) $cfg->limite_diario) {
            return ['regra' => self::REGRA_LIMITE_DIARIO,
                'mensagem' => "Limite diário do agente nesta loja atingido ({$cfg->limite_diario} produto(s) por dia)."];
        }

        $esperando = app(AndamentoDaOfertaService::class)
            ->filaDaConta($conta['company']?->id, $conta['mlb_empresa']?->id)
            ->whereIn('andamento', self::ESPERANDO_GENTE)
            ->count();
        $vivas = PubAgenteExecucao::query()->where('conta_chave', $conta['chave'])->whereIn('estado', PubAgenteExecucao::VIVAS)->count();
        if ($esperando + $vivas >= (int) $cfg->teto_fila) {
            return ['regra' => self::REGRA_TETO_FILA,
                'mensagem' => "A fila de Aprovação desta loja está no teto do agente ({$cfg->teto_fila} oferta(s) esperando revisão ou em preparo)."];
        }

        return null;
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
