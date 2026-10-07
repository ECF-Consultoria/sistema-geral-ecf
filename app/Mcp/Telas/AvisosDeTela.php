<?php

namespace App\Mcp\Telas;

use App\Models\Chamado;
use App\Models\User;

/**
 * Avisos que vão JUNTO da resposta do `ler_tela`/`listar_telas` quando a tela
 * engana quem a lê pelo nome.
 *
 * Por que na resposta e não só na descrição da ferramenta: o claude.ai guarda
 * a lista de ferramentas (com as descrições) em cache e só a busca de novo de
 * tempos em tempos — em 06/10/2026 uma conversa usou a lista de horas antes,
 * sem o `ler_ticket`, e leu `chamados.index` como "todos os tickets". O que
 * vem na resposta chega ao modelo sempre.
 */
final class AvisosDeTela
{
    /** Aviso para quem abriu esta tela, ou null. */
    public static function paraTela(string $tela, User $usuario): ?string
    {
        return $tela === 'chamados.index' ? self::tickets($usuario) : null;
    }

    /** Dica para uma busca no `listar_telas`, ou null. */
    public static function paraBusca(?string $busca, ?string $modulo, User $usuario): ?string
    {
        return preg_match('/ticket|chamado|tkt/i', (string) $busca.' '.(string) $modulo) ? self::tickets($usuario) : null;
    }

    private static function tickets(User $usuario): string
    {
        $caixa = Chamado::ehEquipe($usuario) && app(CatalogoDeTelas::class)->achar($usuario, 'dev.demandas.index');

        return $caixa
            ? 'Atenção: a tela chamados.index (/tickets) mostra SÓ os tickets que você ABRIU — não é a caixa da equipe dev. '
                .'A lista de tickets da equipe (os que você atende e os da fila) está em ler_tela {"tela": "dev.demandas.index", "campo": "chamados"}; '
                .'um ticket inteiro (descrição e mensagens) em ler_tela {"tela": "dev.demandas.index", "filtros": {"ticket": <id>}, "campo": "chamado_detalhe"}. '
                .'Se a ferramenta ler_ticket estiver disponível, prefira ela: faz as duas coisas e traz os prints anexados.'
            : 'A tela chamados.index (/tickets) mostra os tickets que você abriu. Para ler um deles por inteiro (descrição, mensagens e prints), use ler_ticket {"ticket": "<código>"}.';
    }
}
