<?php

namespace App\Services\Trilha\Ondas;

use App\Models\MlAnuncioCriativoKit;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Publicador\Criativos\CriativosAutomaticosService;
use App\Services\Publicador\PreparoIaDoRascunhoService;
use App\Support\Publicador\MemoriaDoPreparoIa;
use Illuminate\Support\Carbon;

/**
 * A Simples (a Fase 1 no Publicador) está PRONTA para o combo nascer dela? Trilha de produtos, 2ª entrega
 * (decisão do usuário de 31/10/2026: "o combo não espera a simples vender; se o Publicador precisar da simples, o
 * agente a prepara antes"). Fora da trilha de trabalho o Kit N continua nascendo só com a Fase 1 PUBLICADA.
 *
 * Pronta = o agente terminou: rascunho em DRAFT ou VALIDATED (nem publicando, nem publicado, nem com falha), o texto
 * do preparo completo para os fatos de agora (`situacaoDoTexto`) e as imagens automáticas resolvidas — nada no teto
 * do dia, nada esperando nova tentativa, nenhum kit ainda andando e, com elas ligadas, o Job delas já rodado (ou o
 * prazo da espera vencido). O Kit N é uma CÓPIA do rascunho da Simples no instante em que nasce
 * (`CriarFaseService`): copiar no meio da geração levaria fotos pela metade.
 */
final class SimplesPronta
{
    /** Quanto esperar, depois do fim do texto, pelas imagens automáticas que ainda não deixaram memória. */
    public const ESPERA_DAS_IMAGENS_MIN = 30;

    /** Os estados do kit de imagem que ainda vão entrar no anúncio (`IaTrabalhando::KIT_EM_ANDAMENTO`, privada lá). */
    private const KIT_EM_ANDAMENTO = [
        MlAnuncioCriativoKit::STATUS_PLANEJANDO, MlAnuncioCriativoKit::STATUS_PLANEJADO, MlAnuncioCriativoKit::STATUS_GERANDO,
        MlAnuncioCriativoKit::STATUS_PRONTO, MlAnuncioCriativoKit::STATUS_PARCIAL,
    ];

    public static function para(?PubRascunho $r): bool
    {
        if ($r === null || ! in_array($r->status, [PubRascunho::DRAFT, PubRascunho::VALIDATED], true)) {
            return false;
        }
        // Relido inteiro: quem chama pode ter carregado só algumas colunas, e o hash dos fatos do preparo lê a
        // categoria, os atributos e o produto — com o modelo parcial ele diria "sem preparo" para sempre.
        $r = PubRascunho::query()->find($r->id);
        if ($r === null) {
            return false;
        }
        if (app(PreparoIaDoRascunhoService::class)->situacaoDoTexto($r) !== 'completo') {
            return false;
        }
        if (in_array(CriativosAutomaticosService::situacaoDasImagens($r), ['adiado', 'nova_tentativa'], true)) {
            return false;
        }

        // As imagens automáticas são o ÚLTIMO elo da cadeia da descrição: com elas ligadas para a empresa e ainda sem
        // memória nenhuma, o Job delas pode estar na fila — copiar agora levaria o Kit N sem a galeria da IA (revisão
        // de 02/11/2026). Os desfechos sem kit (sem foto, sem permissão…) não gravam memória, então a espera tem
        // prazo: passado {@see self::ESPERA_DAS_IMAGENS_MIN} do fim do texto, a Simples conta como pronta.
        $memoria = (array) (((array) $r->step_state)[CriativosAutomaticosService::MEMORIA] ?? []);
        $companyId = PubProduto::query()->whereKey($r->produto_id)->value('company_id');
        if ($memoria === [] && app(CriativosAutomaticosService::class)->ligadoPara($companyId !== null ? (int) $companyId : null)) {
            $fim = self::fimDoTexto($r);

            return $fim !== null && $fim->lte(now()->subMinutes(self::ESPERA_DAS_IMAGENS_MIN));
        }

        $andando = [];
        foreach ((array) ($memoria['grupos'] ?? []) as $g) {
            if (is_array($g) && ! empty($g['kit_id']) && ! isset($g['aplicado_em'])) {
                $andando[] = (int) $g['kit_id'];
            }
        }

        return $andando === []
            || ! MlAnuncioCriativoKit::query()->whereIn('id', $andando)->whereIn('status', self::KIT_EM_ANDAMENTO)->exists();
    }

    /** O instante em que a última etapa do texto terminou (o `em` mais recente das etapas), ou null. */
    private static function fimDoTexto(PubRascunho $r): ?Carbon
    {
        $fim = null;
        foreach ((array) (((array) $r->step_state)[MemoriaDoPreparoIa::PREPARO]['etapas'] ?? []) as $etapa) {
            $em = is_array($etapa) ? ($etapa['em'] ?? null) : null;
            if (! is_string($em) || $em === '') {
                continue;
            }
            try {
                $quando = Carbon::parse($em);
            } catch (\Throwable) {
                continue;
            }
            $fim = $fim === null || $quando->gt($fim) ? $quando : $fim;
        }

        return $fim;
    }
}
