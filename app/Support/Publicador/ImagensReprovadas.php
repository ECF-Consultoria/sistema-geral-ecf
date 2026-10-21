<?php

namespace App\Support\Publicador;

use App\Models\MlAnuncioCriativo;
use App\Models\PubRascunho;
use App\Services\Publicador\Criativos\CriativosAutomaticosService;

/**
 * As imagens por IA AUTOMÁTICAS que o juiz reprovou e ficaram de fora do anúncio, com o motivo (21/10/2026, o
 * usuário: "tem que ter o aviso quando for reprovado, e o motivo" — no TESTE-SKU a foto do Portal era de outro produto,
 * o juiz reprovou as 3 e nada dizia isso na tela). Lido pela vitrine (linha no cartão) e pelo editor (faixa no topo).
 *
 * Só conta o kit que a automação JÁ aplicou (`aplicado_em` na memória do grupo): enquanto ela trabalha, quem fala é o
 * `IaTrabalhando` ("Atualizando…"). Imagem reprovada que alguém aprovou à mão no painel (tem `pub_imagem_id`) não conta,
 * e um kit novo no grupo (foto ou ficha nova) troca o `kit_id` da memória — o aviso velho some sozinho.
 *
 * O motivo sai da mensagem OFICIAL do juiz (`validacaoMensagem()`), a mesma que o painel de criativos mostra — nunca do
 * json cru da validação (T-162-18).
 */
final class ImagensReprovadas
{
    /** Quantos motivos o aviso lista (os repetidos contam uma vez). */
    private const MAX_MOTIVOS = 3;

    /**
     * @return array{todas: bool, reprovadas: int, geradas: int, motivos: list<string>}|null
     */
    public static function do(?PubRascunho $r): ?array
    {
        if ($r === null) {
            return null;
        }

        $kits = [];
        foreach ((array) ($r->step_state[CriativosAutomaticosService::MEMORIA]['grupos'] ?? []) as $g) {
            if (! empty($g['kit_id']) && ! empty($g['aplicado_em'])) {
                $kits[] = (int) $g['kit_id'];
            }
        }
        if ($kits === []) {
            return null;
        }

        // As imagens que o kit gerou (o portador das referências fica `pendente` e não entra).
        $geradas = MlAnuncioCriativo::query()->whereIn('kit_id', $kits)
            ->whereIn('status', [MlAnuncioCriativo::STATUS_PRONTO, MlAnuncioCriativo::STATUS_APROVADO])
            ->get(['id', 'status', 'validacao_status', 'validacao', 'pub_imagem_id']);
        $reprovadas = $geradas->filter(fn (MlAnuncioCriativo $s) => $s->validacao_status === MlAnuncioCriativo::VALIDACAO_REPROVADA
            && $s->pub_imagem_id === null);
        if ($reprovadas->isEmpty()) {
            return null;
        }

        return [
            'todas' => $reprovadas->count() === $geradas->count(),
            'reprovadas' => $reprovadas->count(),
            'geradas' => $geradas->count(),
            'motivos' => $reprovadas->map(fn (MlAnuncioCriativo $s) => self::motivo($s->validacaoMensagem()))
                ->filter()->unique()->take(self::MAX_MOTIVOS)->values()->all(),
        ];
    }

    /**
     * A mensagem do juiz como motivo do aviso: sem o "Risco:" e o "Confira antes de aprovar." (que falam com quem aprova
     * no painel), sem ponto dobrado e sem nada com cara de token.
     */
    public static function motivo(?string $mensagem): ?string
    {
        $t = trim((string) $mensagem);
        $t = (string) preg_replace('/^Risco:\s*/u', '', $t);
        $t = (string) preg_replace('/\s*Confira antes de aprovar\.?$/u', '', $t);
        $t = (string) preg_replace('/\b[A-Za-z0-9]{32,}\b/', '…', $t);
        $t = trim((string) preg_replace('/\.{2,}/u', '.', $t));

        return $t === '' ? null : $t;
    }
}
