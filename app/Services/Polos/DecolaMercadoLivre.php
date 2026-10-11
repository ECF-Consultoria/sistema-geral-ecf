<?php

namespace App\Services\Polos;

use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\PoloDecolaStatus;
use App\Services\MercadoLivreService;
use App\Support\CustId;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Decola: Sim" automático pelo Mercado Livre (TKT-0013).
 *
 * Pergunta ao ML, com o token OAuth da própria empresa, se o Programa Decola está ativo na
 * conta (`GET /users/reputation/seller_recovery/status`: `type` NEWBIE_GRNTEE = Decola,
 * `status` ACTIVE = ativado). Grava em dois lugares, na mesma transação:
 *  - `mlb_implementacoes.decola` — o que TODAS as telas já leem (Painel, ficha, aba Metas);
 *  - `polos_decola_status` — o que o ML respondeu e quando, para a tela saber que é automático
 *    e a planilha não desfazer.
 *
 * O que não é óbvio:
 *  - **Só promove.** Programa ativo vira 'Sim'; qualquer outra resposta (convite não aceito,
 *    programa encerrado, conta sem programa) não mexe na ficha. O 'Sim' entra em "M1 completo"
 *    da aba Metas, e rebaixar sozinho apagaria o que o time conferiu à mão.
 *  - **Só alcança quem conectou a conta.** O token de Polos é guardado desde 21/09/2026
 *    (`ml_tokens.mlb_empresa_id`); quem autorizou antes disso, ou nunca autorizou, segue manual.
 *  - **A conta do token tem de ser a do cadastro.** O callback já recusa conta divergente, mas
 *    o Cust ID pode ser editado depois; perguntar com o token de outra conta marcaria o Decola
 *    de um vendedor na ficha de outro.
 */
class DecolaMercadoLivre
{
    public const ENDPOINT = '/users/reputation/seller_recovery/status';

    public function __construct(private MercadoLivreService $ml) {}

    /**
     * Empresas POLOS não arquivadas com conta do ML conectada (token não revogado).
     *
     * @return Collection<int, MlbEmpresa>
     */
    public static function empresas(): Collection
    {
        return MlbEmpresa::ativas()
            ->programa(MlbEmpresa::PROGRAMA_POLOS)
            ->whereHas('mlToken', fn ($q) => $q->where('status', '!=', 'revoked'))
            ->with('mlToken')
            ->orderBy('nome')
            ->get();
    }

    /** O token guardado é da mesma conta do Cust ID cadastrado? */
    public function contaConfere(MlbEmpresa $empresa): bool
    {
        $cust = CustId::normaliza((string) $empresa->cust_id);

        return $cust !== '' && $empresa->mlToken !== null && (string) $empresa->mlToken->ml_user_id === $cust;
    }

    /**
     * Consulta o ML. Devolve null se ele não respondeu ou respondeu algo que não dá para ler —
     * nada é gravado, o que preserva a última leitura boa e a marcação manual.
     *
     * @return array{tipo: ?string, status: string}|null
     */
    public function ler(MlbEmpresa $empresa): ?array
    {
        try {
            $r = $this->ml->get($empresa, self::ENDPOINT);
        } catch (\Throwable $e) {
            Log::warning("[Polos Decola] empresa {$empresa->id} ({$empresa->nome}): o ML não respondeu — {$e->getMessage()}");

            return null;
        }

        $status = $r['status'] ?? null;
        if (! is_string($status) || trim($status) === '') {
            Log::warning("[Polos Decola] empresa {$empresa->id} ({$empresa->nome}): resposta sem status", [
                'chaves' => array_keys($r),
            ]);

            return null;
        }

        $tipo = $r['type'] ?? null;

        return [
            'tipo'   => is_string($tipo) && trim($tipo) !== '' ? mb_strtoupper(trim($tipo)) : null,
            'status' => mb_strtoupper(trim($status)),
        ];
    }

    /**
     * Grava a leitura e, se o programa está ativo, promove a ficha para 'Sim'.
     * Devolve true quando a ficha mudou nesta chamada.
     *
     * @param  array{tipo: ?string, status: string}  $leitura
     */
    public function gravar(MlbEmpresa $empresa, array $leitura): bool
    {
        return DB::transaction(function () use ($empresa, $leitura) {
            $registro = PoloDecolaStatus::updateOrCreate(['mlb_empresa_id' => $empresa->id], [
                'tipo'          => $leitura['tipo'],
                'status'        => $leitura['status'],
                'verificado_em' => now(),
            ]);

            if (! $registro->decolaAtivo()) {
                return false;
            }

            // Ficha relida aqui, não a da coleção: o time edita o painel enquanto o sync roda.
            $ficha = MlbImplementacao::where('empresa_id', $empresa->id)->first();
            if ($ficha === null || $ficha->decola === 'Sim') {
                return false;
            }

            $anterior = $ficha->decola; // capturar ANTES: update() ressincroniza o original
            $ficha->update(['decola' => 'Sim']);

            activity('implementacao')
                ->withProperties(['empresa' => $empresa->nome, 'anterior' => $anterior, 'origem' => 'mercado_livre'])
                ->log('[Onboarding] Programa Decola marcado como "Sim" — o Mercado Livre informa o programa ativo na conta de "' . $empresa->nome . '" (automático)');

            return true;
        });
    }
}
