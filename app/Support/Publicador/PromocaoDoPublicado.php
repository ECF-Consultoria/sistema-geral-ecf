<?php

namespace App\Support\Publicador;

use App\Models\PubPromocaoAutomatica;

/**
 * Demo 23/10/2026 (A1) — a situação da promoção automática de UM anúncio publicado, para a lista final do
 * Publicador (`Mesa/Publicar.jsx`).
 *
 * Medido em produção em 22/10: TODAS as promoções automáticas dos anúncios novos do dia voltaram `recusada`
 * com "No candidates found for item" — o ML não oferece o desconto para o anúncio recém-criado (learnings
 * §21/§32). A tela dizia que "o sistema cria sozinho"; agora a lista mostra o que de fato aconteceu, lido do
 * último ciclo em `pub_promocoes_automaticas`.
 *
 * Só leitura. Nulo = o anúncio não tem ciclo (sem promoção calculável, ou publicado antes da automação).
 */
final class PromocaoDoPublicado
{
    /** O ML não tem o anúncio como candidato ao desconto (anúncio novo, conta sem venda, reputação vermelha). */
    public const SEM_CANDIDATO = 'O Mercado Livre não ofereceu desconto para este anúncio novo';

    /**
     * @return array{status: string, tom: string, texto: string}|null
     */
    public static function do(?string $mlItemId): ?array
    {
        if ($mlItemId === null || $mlItemId === '') {
            return null;
        }

        $ciclo = PubPromocaoAutomatica::query()->where('ml_item_id', $mlItemId)->orderByDesc('ciclo')->orderByDesc('id')->first();

        return $ciclo ? self::paraTela($ciclo) : null;
    }

    /** @return array{status: string, tom: string, texto: string} */
    public static function paraTela(PubPromocaoAutomatica $ciclo): array
    {
        $valor = PrecoDaPromocao::reais((float) $ciclo->preco_promocao).' (−'.PrecoDaPromocao::pct((float) $ciclo->percentual).'%)';
        $ate = $ciclo->fimData()?->format('d/m');
        $motivo = self::motivo((string) $ciclo->motivo);

        [$tom, $texto] = match ($ciclo->status) {
            PubPromocaoAutomatica::AGENDADA => ['neutro', "Promoção da Central de {$valor}: o sistema vai tentar criar em alguns minutos"],
            PubPromocaoAutomatica::ENVIANDO => ['neutro', "Promoção da Central de {$valor}: criando agora"],
            PubPromocaoAutomatica::ATIVA => ['ok', "Promoção da Central criada: {$valor}".($ate ? " até {$ate}" : '')],
            PubPromocaoAutomatica::RECUSADA => ['aviso', 'Promoção da Central não criada. '.($motivo !== '' ? $motivo : 'O Mercado Livre recusou')],
            PubPromocaoAutomatica::CANCELADA => ['neutro', 'Promoção da Central não criada'.($motivo !== '' ? ". {$motivo}" : '')],
            PubPromocaoAutomatica::ENCERRADA => ['neutro', 'Promoção da Central encerrada'],
            default => ['neutro', 'Promoção da Central: '.$ciclo->status],
        };

        return ['status' => (string) $ciclo->status, 'tom' => $tom, 'texto' => $texto];
    }

    /** O motivo gravado, legível: o "No candidates" cru do ML vira a frase em pt-BR; sem ponto final (a tela põe). */
    private static function motivo(string $motivo): string
    {
        $motivo = trim($motivo);
        if (stripos($motivo, 'no candidates') !== false || stripos($motivo, 'candidate') !== false) {
            return self::SEM_CANDIDATO;
        }

        return rtrim($motivo, '. ');
    }
}
