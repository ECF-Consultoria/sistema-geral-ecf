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
 * (`MlbPublicadorController::produto`), e a tela manda um sinal a cada 20 s enquanto a aba está
 * visível (`usePublicador`, rota `presenca`). Vale `publicador.preparo_ia.editor_em_uso_min`.
 *
 * 20/10/2026 — DOIS sinais. "Em uso" ({@see emUso()}) vale para quem só OLHA: revisar é trabalhar no
 * produto, e a fila não publica por baixo (decisão do usuário de 19/10). "Editando" ({@see editando()})
 * só para o que GRAVA — é o que o preparo pela IA espera agora, para gerar logo depois do save no Portal
 * mesmo com a tela aberta ("que salvar o produto com imagem, já gerar"). Para a escrita da IA não ser
 * desfeita pela cópia velha da tela, o editor fica só leitura enquanto ela grava e relê no fim
 * (`IaTrabalhando`, devolvido pela rota `presenca`).
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
        // Só o que GRAVA é "editando" — o sinal de presença e as leituras (estado, prévia, miniatura) não.
        $pedido = app()->bound('request') ? app('request') : null;
        if ($pedido !== null && ! $pedido->isMethodSafe() && ! $pedido->routeIs('mlb.anuncios.publicador.presenca')) {
            self::marcarEdicao($produtoId);
        }
    }

    public static function emUso(int $produtoId): bool
    {
        return Cache::has(self::chave($produtoId));
    }

    public static function chaveDaEdicao(int $produtoId): string
    {
        return "publicador:editor:editando:{$produtoId}";
    }

    /** Alguém GRAVOU pelo editor deste produto (vale os mesmos minutos do "em uso"). */
    public static function marcarEdicao(int $produtoId): void
    {
        $minutos = max(1, (int) config('publicador.preparo_ia.editor_em_uso_min', 3));
        Cache::put(self::chaveDaEdicao($produtoId), now()->timestamp, now()->addMinutes($minutos));
    }

    /** "Alguém está EDITANDO este produto agora?" — o que o preparo pela IA espera (20/10/2026). */
    public static function editando(int $produtoId): bool
    {
        return Cache::has(self::chaveDaEdicao($produtoId));
    }
}
