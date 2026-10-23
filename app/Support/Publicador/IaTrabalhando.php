<?php

namespace App\Support\Publicador;

use App\Models\MlAnuncioCriativoKit;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Publicador\Criativos\CriativosAutomaticosService;
use App\Services\Publicador\PreparoIaAgenda;
use App\Services\Publicador\PreparoIaDoRascunhoService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * "A IA está mexendo neste anúncio agora?" (20/10/2026, o usuário: "se estiver em pendência e a IA estiver
 * trabalhando, sinalizar que está atualizando"). Lido pela vitrine (chip "Atualizando…") e pelo editor aberto, que
 * fica só leitura enquanto ela grava e relê no fim (rota `presenca`). Três momentos, do save no Portal até a última
 * imagem:
 *
 *  - `agendada`: o cliente salvou no Portal e o preparo ainda não começou (`PreparoIaAgenda::chaveDoAgendado`);
 *  - `escrevendo`: uma etapa do preparo (título, Modelo, descrição) está `rodando` — há menos de 10 minutos;
 *  - `imagens`: o kit AUTOMÁTICO ainda não entrou (planejando, gerando ou pronto esperando o juiz) — há menos de 30.
 *
 * Os prazos impedem o "Atualizando…" eterno de um Job que morreu no meio.
 *
 * O {@see carimbo()} muda só quando a AUTOMAÇÃO grava (as etapas do preparo e as imagens aplicadas) — nunca com o
 * salvamento de quem edita. É ele que diz ao editor aberto "releia": o editor manda a chave de topo INTEIRA da cópia
 * dele (`EditorEmUso`), e reler antes de voltar a salvar é o que impede a cópia velha de desfazer a escrita da IA.
 */
final class IaTrabalhando
{
    public const AGENDADA = 'agendada';

    public const ESCREVENDO = 'escrevendo';

    public const IMAGENS = 'imagens';

    /** Kit automático que ainda vai entrar no anúncio. */
    private const KIT_EM_ANDAMENTO = [
        MlAnuncioCriativoKit::STATUS_PLANEJANDO, MlAnuncioCriativoKit::STATUS_PLANEJADO, MlAnuncioCriativoKit::STATUS_GERANDO,
        MlAnuncioCriativoKit::STATUS_PRONTO, MlAnuncioCriativoKit::STATUS_PARCIAL,
    ];

    /** O que a IA está fazendo neste rascunho agora, ou null. */
    public static function no(?PubRascunho $r, ?int $estruturaProdutoId = null): ?string
    {
        if ($estruturaProdutoId !== null && Cache::has(PreparoIaAgenda::chaveDoAgendado($estruturaProdutoId))) {
            return self::AGENDADA;
        }
        if ($r === null) {
            return null;
        }

        $estado = (array) $r->step_state;
        foreach ((array) ($estado[MemoriaDoPreparoIa::PREPARO]['etapas'] ?? []) as $etapa) {
            if (($etapa['status'] ?? null) === PreparoIaDoRascunhoService::RODANDO && self::recente($etapa['em'] ?? null, 10)) {
                return self::ESCREVENDO;
            }
        }

        $kits = [];
        foreach ((array) ($estado[CriativosAutomaticosService::MEMORIA]['grupos'] ?? []) as $g) {
            if (! empty($g['kit_id']) && empty($g['aplicado_em'])) {
                $kits[] = (int) $g['kit_id'];
            }
        }
        if ($kits !== [] && MlAnuncioCriativoKit::query()->whereIn('id', $kits)->whereIn('status', self::KIT_EM_ANDAMENTO)
            ->where('created_at', '>=', now()->subMinutes(30))->exists()) {
            return self::IMAGENS;
        }

        return null;
    }

    /** Muda só quando a automação grava: as etapas do preparo pela IA e as imagens automáticas aplicadas. */
    public static function carimbo(PubRascunho $r): string
    {
        $estado = (array) $r->step_state;
        $grupos = [];
        foreach ((array) ($estado[CriativosAutomaticosService::MEMORIA]['grupos'] ?? []) as $chave => $g) {
            $grupos[(string) $chave] = [$g['kit_id'] ?? null, $g['aplicado_em'] ?? null];
        }
        ksort($grupos);

        return sha1((string) json_encode([$estado[MemoriaDoPreparoIa::PREPARO]['etapas'] ?? null, $grupos]));
    }

    /**
     * O que o editor aberto recebe no sinal de presença: com a IA trabalhando, ele fica só leitura; o carimbo mudou,
     * ele relê o rascunho.
     *
     * @return array{ia: ?string, carimbo: ?string}
     */
    public static function paraOEditor(PubProduto $p): array
    {
        $r = PubRascunho::where('produto_id', $p->id)->first();

        return [
            'ia' => self::no($r, $p->estrutura_produto_id !== null ? (int) $p->estrutura_produto_id : null),
            'carimbo' => $r !== null ? self::carimbo($r) : null,
        ];
    }

    private static function recente(mixed $em, int $minutos): bool
    {
        if (! is_string($em) || $em === '') {
            return false;
        }
        try {
            return Carbon::parse($em)->gte(now()->subMinutes($minutos));
        } catch (\Throwable) {
            return false;
        }
    }
}
