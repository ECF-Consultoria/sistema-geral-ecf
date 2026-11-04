<?php

namespace App\Support\Trilha;

use Illuminate\Contracts\Database\Query\Builder as Consulta;
use Illuminate\Support\Facades\DB;

/**
 * O endereço do anúncio no Mercado Livre, para a EQUIPE abrir o anúncio publicado (pedido do usuário de 04/11/2026: "o
 * MLB já me direcionando para o anúncio"). Só a porta da equipe recebe o link — o cliente não vê o MLB, e o link leva o
 * MLB dentro ({@see \App\Http\Controllers\Trilha\ApresentacaoDaTrilha}).
 *
 * De onde vem, na ordem:
 * 1. o `permalink` do acervo (`ml_acervo_itens`, chave `company_id` + `ml_item_id`, o mesmo upsert do "Meus Anúncios"):
 *    é o endereço que o ML devolveu, com o título no caminho (o comentário da migration: "não reconstruir");
 * 2. o `permalink` que o Publicador leu do ML logo depois de criar o anúncio (`pub_publicacao_itens.avisos.estado`,
 *    {@see \App\Support\Publicador\SituacaoDoAnuncioMl::doCorpo()}) — o anúncio recém-publicado ainda fora do acervo;
 * 3. o endereço MONTADO pelo MLB (`https://produto.mercadolivre.com.br/MLB-{dígitos}-_JM`): o mesmo formato do ML sem o
 *    título — o ML aceita o endereço curto (testado em 04/11/2026; só pede verificação a robôs).
 *
 * O endereço guardado só vale se for http(s) num domínio do `mercadolivre.com.br` (o `http://` vira `https://`, a regra
 * do {@see FotoDaPrevia::emHttps()}): o dado vem de API de fora e vai para um `href` — `javascript:` ou outro domínio
 * nunca chegam à tela; nesses casos vale o montado.
 */
final class LinkDoAnuncio
{
    /** O começo do endereço montado — o mesmo do permalink que o ML devolve para o anúncio (sem o título). */
    public const BASE = 'https://produto.mercadolivre.com.br/';

    /**
     * O endereço montado pelo MLB (`MLB123` ou `MLB-123`, sem caixa). Nulo para o que não tem cara de código de anúncio.
     */
    public static function montado(?string $mlb): ?string
    {
        if (! preg_match('/^MLB-?(\d+)$/i', trim((string) $mlb), $m)) {
            return null;
        }

        return self::BASE.'MLB-'.$m[1].'-_JM';
    }

    /**
     * O endereço que o ML devolveu, só se for http(s) num domínio do `mercadolivre.com.br`, sempre em https. Nulo no resto.
     */
    public static function doMl(mixed $permalink): ?string
    {
        if (! is_string($permalink)) {
            return null;
        }
        $url = trim($permalink);
        if (! preg_match('#^https?://([a-z0-9-]+\.)*mercadolivre\.com\.br(?:[/?\#]|$)#i', $url)) {
            return null;
        }

        return (string) preg_replace('#^http://#i', 'https://', $url);
    }

    /**
     * Os `permalink` do acervo da empresa para os MLBs, numa consulta só (nenhuma sem MLB) — MLB (maiúsculo) → endereço.
     * `$empresa` é o id da Company ou uma subconsulta que o devolva (`pub_produtos.company_id` do anúncio, na 4d).
     *
     * @param  int|Consulta  $empresa  (o Builder do Eloquent também é uma `Consulta`)
     * @param  list<string>  $mlbs
     * @return array<string, string>
     */
    public static function doAcervo(int|Consulta $empresa, array $mlbs): array
    {
        $mlbs = array_values(array_unique(array_filter(array_map(fn ($m) => strtoupper(trim((string) $m)), $mlbs), fn ($m) => $m !== '')));
        if ($mlbs === []) {
            return [];
        }

        $consulta = DB::table('ml_acervo_itens')->whereIn('ml_item_id', $mlbs)->whereNotNull('permalink');
        is_int($empresa) ? $consulta->where('company_id', $empresa) : $consulta->whereIn('company_id', $empresa);

        $saida = [];
        foreach ($consulta->get(['ml_item_id', 'permalink']) as $linha) {
            $url = self::doMl($linha->permalink);
            if ($url !== null) {
                $saida[strtoupper(trim((string) $linha->ml_item_id))] ??= $url;
            }
        }

        return $saida;
    }

    /**
     * O link do anúncio: o do acervo, senão o que o Publicador leu do ML, senão o montado.
     *
     * @param  array<string, string>  $doAcervo  {@see doAcervo()}
     */
    public static function escolher(string $mlb, array $doAcervo, mixed $doPublicador = null): ?string
    {
        return $doAcervo[strtoupper(trim($mlb))] ?? self::doMl($doPublicador) ?? self::montado($mlb);
    }
}
