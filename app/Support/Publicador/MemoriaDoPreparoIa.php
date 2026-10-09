<?php

namespace App\Support\Publicador;

/**
 * A memória do preparo pela IA no `step_state` do rascunho (09/10/2026). Puro: sem banco.
 *
 * - `ia_escrito.{titulo_gold_special|titulo_gold_pro|modelo|descricao}` = o ÚLTIMO valor que a
 *   automação escreveu em cada campo. É o que separa "o que a IA escreveu" de "o que a equipe
 *   editou": a automação só escreve num campo VAZIO ou que ainda tem EXATAMENTE esse valor.
 *   Perder a memória (outra escrita do `step_state` que a pisou) é seguro: o campo preenchido
 *   passa a contar como da equipe e nunca mais é sobrescrito.
 * - `ia_preparo` = `{hash, em, titulo_gerado (listing_type_id → título), etapas: {etapa: {status, em}}}`: os fatos da última
 *   geração (hash igual = não chama a IA de novo) e como cada etapa terminou.
 */
final class MemoriaDoPreparoIa
{
    public const ESCRITO = 'ia_escrito';

    public const PREPARO = 'ia_preparo';

    public const TITULO_CLASSICO = 'titulo_gold_special';

    public const TITULO_PREMIUM = 'titulo_gold_pro';

    public const MODELO = 'modelo';

    public const DESCRICAO = 'descricao';

    /**
     * A regra de ouro: escreve só no vazio, ou por cima do que a própria automação escreveu
     * por último (o cliente mudou a ficha e ninguém mexeu no campo desde então).
     */
    public static function podeEscrever(?string $atual, ?string $ultimoEscrito): bool
    {
        if (trim((string) $atual) === '') {
            return true;
        }

        return $ultimoEscrito !== null && (string) $atual === $ultimoEscrito;
    }

    /** O campo ainda tem o que a automação escreveu (e não está vazio)? É o selo da tela. */
    public static function aindaDaIa(?string $atual, ?string $ultimoEscrito): bool
    {
        return $ultimoEscrito !== null && trim((string) $atual) !== '' && (string) $atual === $ultimoEscrito;
    }

    public static function chaveDoTitulo(string $listingTypeId): string
    {
        return 'titulo_'.$listingTypeId;
    }

    /**
     * Os campos que, AGORA, ainda mostram o que a automação escreveu — para a tela marcar
     * "gerado pela IA a partir da ficha do Portal".
     *
     * @param  array<string, mixed>  $stepState
     * @param  array<string, ?string>  $titulos  listing_type_id → título gravado
     * @return array{titulos: array<string, bool>, modelo: bool, descricao: bool}
     */
    public static function paraTela(array $stepState, array $titulos, ?array $modelo, ?string $descricao): array
    {
        $escrito = (array) ($stepState[self::ESCRITO] ?? []);
        $marcas = [];
        foreach ($titulos as $tipo => $titulo) {
            $marcas[$tipo] = self::aindaDaIa($titulo, self::texto($escrito[self::chaveDoTitulo($tipo)] ?? null));
        }
        $modeloTexto = $modelo !== null && trim((string) ($modelo['value_id'] ?? '')) === '' ? (string) ($modelo['value_name'] ?? '') : null;

        return [
            'titulos' => $marcas,
            'modelo' => $modeloTexto !== null && self::aindaDaIa($modeloTexto, self::texto($escrito[self::MODELO] ?? null)),
            'descricao' => self::aindaDaIa($descricao, self::texto($escrito[self::DESCRICAO] ?? null)),
        ];
    }

    private static function texto(mixed $v): ?string
    {
        return is_string($v) ? $v : null;
    }
}
