<?php

namespace App\Services\Incubadora\Publicador;

use App\Services\MlColetaService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Publicador da Incubadora — os termos mais buscados de uma categoria
 * (`GET /trends/MLB/{category_id}`), de onde o operador marca as palavras que
 * têm a ver com o produto para montar o título.
 *
 * O que a API entrega (doc oficial, conferida em 01/10/2026):
 *   - até 50 termos, atualizados SEMANALMENTE, sem volume — só a posição;
 *   - com a lista cheia: 1–10 = maior crescimento, 11–30 = mais desejados
 *     (maior volume na semana), 31–50 = mais populares;
 *   - folha de nicho devolve menos (Cadeiras Gamer: 15; Poltronas, em 14/10/2026,
 *     UM termo) e aí a doc não diz como a lista se divide — por isso `grupo` só
 *     vem com a lista cheia;
 *   - categoria sem lista nenhuma responde 404 "Not found public trends"
 *     (Cadeiras de Balanço, 14/10/2026): é resposta, não falha — vale lista vazia;
 *   - vem cheio de marca de concorrente (husky, pichau, nike): é o operador
 *     quem decide, a tela não filtra nada.
 *
 * `relacionado` é só uma pista visual: o termo tem alguma palavra do nome do
 * produto que NÃO está no caminho da categoria. Medido com dado real (01/10,
 * "mesa de jantar redonda 4 lugares" em Mesas de Jantar e Cozinha): contando
 * "mesa", 40 de 43 termos ficavam marcados e a pista não dizia nada; o que
 * distingue o produto é "redonda", "lugares". Num nível acima do caminho
 * ("Móveis de Cozinha") "mesa" volta a distinguir — por isso a régua é o
 * caminho do nível mostrado. Não esconde nem reordena nada no servidor.
 */
class TermosMaisBuscadosService
{
    private const API_BASE = 'https://api.mercadolibre.com';

    // A lista muda uma vez por semana; 6 h mantém a tela rápida sem segurar
    // a virada da semana por muito tempo.
    private const TTL = 21600;

    /** Lista cheia da doc — só com ela os grupos por posição valem. */
    public const LISTA_CHEIA = 50;

    /** Palavras que não contam como "palavra do produto". */
    private const IGNORAR = [
        'de', 'da', 'do', 'das', 'dos', 'para', 'pra', 'com', 'sem', 'por',
        'em', 'no', 'na', 'nos', 'nas', 'um', 'uma', 'e', 'ou', 'a', 'o',
        'as', 'os', 'kit',
    ];

    public function __construct(private MlColetaService $coleta) {}

    /**
     * @param  array<int, string>  $caminho  nomes dos níveis da categoria mostrada (raiz → ela)
     * @return array{total: int, com_grupos: bool, palavras_do_produto: array<int, string>, termos: array<int, array{posicao: int, termo: string, url: ?string, grupo: ?string, relacionado: bool, em_comum: array<int, string>}>}
     *
     * @throws \RuntimeException quando o ML não responde — a tela mostra o erro e deixa tentar de novo
     */
    public function termos(string $categoriaId, ?string $produto = null, array $caminho = []): array
    {
        $brutos = $this->buscar($categoriaId);
        $total = count($brutos);
        $comGrupos = $total === self::LISTA_CHEIA;

        // Palavras do produto que distinguem: as do caminho da categoria saem.
        $daCategoria = $this->palavras(implode(' ', $caminho));
        $doProduto = array_diff_key($this->palavras((string) $produto), $daCategoria);

        $termos = [];
        foreach ($brutos as $i => $t) {
            $posicao = $i + 1;
            // Demo 23/10 (N3): o ML devolve termo sexual ("poltrona erotica para motel") em Sofás e
            // Poltronas, e a lista aparece na tela do cliente. Sai daqui, com a posição dos outros intacta.
            if (self::improprio($t['termo'])) {
                continue;
            }
            $emComum = array_values(array_intersect_key($doProduto, $this->palavras($t['termo'])));

            $termos[] = [
                'posicao'     => $posicao,
                'termo'       => $t['termo'],
                'url'         => $t['url'],
                'grupo'       => $comGrupos ? $this->grupo($posicao) : null,
                'relacionado' => $emComum !== [],
                'em_comum'    => $emComum,
            ];
        }

        return [
            'total'               => $total,
            'com_grupos'          => $comGrupos,
            'palavras_do_produto' => array_values($doProduto),
            'termos'              => $termos,
        ];
    }

    /** @return array<int, array{termo: string, url: ?string}> */
    private function buscar(string $categoriaId): array
    {
        $chave = "incubadora_trends_{$categoriaId}";
        $cache = Cache::get($chave);
        if (is_array($cache)) {
            return $cache;
        }

        $resp = Http::withToken($this->coleta->getAppToken())
            ->timeout(15)
            ->get(self::API_BASE."/trends/MLB/{$categoriaId}");

        if ($resp->status() === 404) {
            // Categoria sem lista pública na semana: guarda o vazio como resposta boa.
            Cache::put($chave, [], self::TTL);

            return [];
        }
        if (! $resp->successful()) {
            throw new \RuntimeException("[Publicador Incubadora] trends da categoria {$categoriaId} indisponível: HTTP {$resp->status()}");
        }

        $lista = [];
        foreach ((array) $resp->json() as $t) {
            $termo = trim((string) (is_array($t) ? ($t['keyword'] ?? '') : $t));
            if ($termo !== '') {
                $lista[] = ['termo' => $termo, 'url' => is_array($t) ? ($t['url'] ?? null) : null];
            }
        }

        // Só guarda resposta boa: falha não fica presa no cache por 6 h.
        Cache::put($chave, $lista, self::TTL);

        return $lista;
    }

    /** Começos de palavra de conteúdo sexual (comparados sem acento e em minúsculas). */
    private const IMPROPRIOS_PREFIXO = ['erot', 'sexu', 'porn', 'fetich', 'bdsm', 'sadomaso', 'kamasutra', 'tantra', 'tantric', 'sensual'];

    /** Palavras inteiras de conteúdo sexual — inteiras porque "sex" e "motel" são começo de outras palavras. */
    private const IMPROPRIOS_PALAVRA = ['sex', 'sexo', 'sexshop', 'motel', 'moteis', 'swing', 'kama'];

    /** O termo tem conteúdo sexual e não deve aparecer para o cliente nem ir ao título. */
    public static function improprio(string $termo): bool
    {
        $palavras = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($termo)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($palavras as $p) {
            if (in_array($p, self::IMPROPRIOS_PALAVRA, true)) {
                return true;
            }
            foreach (self::IMPROPRIOS_PREFIXO as $prefixo) {
                if (str_starts_with($p, $prefixo)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function grupo(int $posicao): string
    {
        return match (true) {
            $posicao <= 10 => 'crescimento',
            $posicao <= 30 => 'desejado',
            default        => 'popular',
        };
    }

    /**
     * Palavras comparáveis, indexadas pela forma de comparar (minúsculas, sem
     * acento, sem o "s" final: mesa × mesas, cadeira × cadeiras) e com a
     * palavra como foi escrita (sem acento) como valor, para a tela mostrar
     * "lugares" e não "lugare". Saem as curtas e as de ligação.
     *
     * @return array<string, string>
     */
    private function palavras(string $texto): array
    {
        $tokens = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($texto)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $saida = [];
        foreach ($tokens as $t) {
            if (strlen($t) < 3 || in_array($t, self::IGNORAR, true)) {
                continue;
            }
            $chave = strlen($t) > 3 && str_ends_with($t, 's') ? substr($t, 0, -1) : $t;
            $saida[$chave] ??= $t;
        }

        return $saida;
    }
}
