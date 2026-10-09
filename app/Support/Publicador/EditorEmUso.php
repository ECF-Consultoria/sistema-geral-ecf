<?php

namespace App\Support\Publicador;

use Illuminate\Support\Facades\Cache;

/**
 * "Alguém está com o editor deste produto aberto?" (09/10/2026, preparo pela IA ao salvar no Portal).
 *
 * O editor salva o que a pessoa digitou mandando a chave de topo INTEIRA (`atributos`, `alvos`) da
 * cópia local — e o servidor regrava a lista (`gravarAtributos`/`gravarAlvos`). Uma escrita por trás
 * da tela aberta não apaga o que a pessoa digita (o salvamento dela vence), mas o próximo salvamento
 * dela desfaz a escrita sem ninguém ver, e a tela mostra valor velho. Por isso o preparo pela IA NÃO
 * escreve com o editor em uso: espera e tenta de novo.
 *
 * O sinal é um carimbo no cache, sem migration: toda rota do editor do produto o renova
 * (`MlbPublicadorController::produto`), e a tela manda um sinal por minuto enquanto a aba está
 * visível (`usePublicador`, rota `presenca`). Vale `publicador.preparo_ia.editor_em_uso_min`.
 */
final class EditorEmUso
{
    public static function chave(int $produtoId): string
    {
        return "publicador:editor:em-uso:{$produtoId}";
    }

    public static function marcar(int $produtoId): void
    {
        $minutos = max(1, (int) config('publicador.preparo_ia.editor_em_uso_min', 3));
        Cache::put(self::chave($produtoId), now()->timestamp, now()->addMinutes($minutos));
    }

    public static function emUso(int $produtoId): bool
    {
        return Cache::has(self::chave($produtoId));
    }
}
