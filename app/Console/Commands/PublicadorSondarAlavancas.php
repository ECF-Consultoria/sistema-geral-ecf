<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\MercadoLivreService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Questão aberta 8 do RESEARCH — captura respostas REAIS da conta de teste para tirar as suposições
 * A1 (faixas no GET de prices), A3 (cofinanciada), A6 (leitura de Ads sem a permissão Advertising),
 * A9 (paginação de convites) e a Questão 1 (offer_id de candidato SMART/PRICE_MATCHING).
 *
 * SÓ GET — recusa qualquer outro verbo, inclusive o POST de recomendações. Roda só em conta liberada
 * das Alavancas (a conta de teste): conta de cliente, nunca. O token só vai no cabeçalho; cabeçalho
 * não é gravado, e dado pessoal sai antes de gravar (as fixtures vão para o git).
 *
 * Arquivo autocontido de propósito: depende só de Company, MercadoLivreService, PublicadorSondar
 * (sanitizar e CAMPOS_USUARIO), Http e config, para rodar na produção pelo runner do learnings §3
 * (como www-data, numa pasta de /tmp, sem deploy). O USUÁRIO roda; a fase não depende disso.
 */
class PublicadorSondarAlavancas extends Command
{
    protected $signature = 'publicador:sondar-alavancas
        {--empresa= : companies.id da conta de teste (só contas liberadas das Alavancas)}
        {--itens= : até 5 MLBs separados por vírgula}
        {--saida= : pasta de saída (padrão tests/fixtures-ml/alavancas/sondagem)}';

    protected $description = 'Alavancas (166): grava respostas reais da API do ML da conta de teste como fixtures (só GET)';

    private const API = 'https://api.mercadolibre.com';

    private string $saida;

    private string $token;

    private int $gravados = 0;

    /** @var list<string> arquivos gravados, relativos à pasta de saída */
    private array $arquivos = [];

    public function handle(MercadoLivreService $ml): int
    {
        $id = (int) $this->option('empresa');
        if ($id <= 0) {
            $this->error('Informe --empresa=ID (companies.id da conta de teste das Alavancas). Uso: php artisan publicador:sondar-alavancas --empresa=459 [--itens=MLB1,MLB2] [--saida=/tmp/sondagem]');

            return self::FAILURE;
        }

        $liberadas = array_map('intval', (array) config('publicador.alavancas.contas_liberadas.companies', [459]));
        if (! in_array($id, $liberadas, true)) {
            $this->error('Sondagem só em conta liberada das Alavancas (conta de teste). Conta de cliente: nunca.');

            return self::FAILURE;
        }

        $empresa = Company::find($id);
        $token = $empresa ? $ml->ensureValidToken($empresa) : null;
        if (! $token) {
            $this->error('Empresa sem conta do ML conectada (ou token revogado) — nada foi sondado.');

            return self::FAILURE;
        }

        $this->saida = rtrim((string) ($this->option('saida') ?: base_path('tests/fixtures-ml/alavancas/sondagem')), '/\\');
        $this->token = $token->access_token;
        $sellerId = (string) $token->ml_user_id;

        $this->conta($sellerId);
        $promocoes = $this->promocoes($sellerId);
        $this->itens($sellerId, $this->itensDaSondagem($sellerId));
        $this->publicidade();
        $this->resumo($promocoes);

        return self::SUCCESS;
    }

    // ═══ Conta e promoções ══════════════════════════════════════════════════

    private function conta(string $sellerId): void
    {
        $r = $this->chamar('GET', '/users/me');
        // Só o que as Alavancas usam: o corpo inteiro tem dado pessoal.
        if (is_array($r['resposta'])) {
            $r['resposta'] = array_intersect_key($r['resposta'], array_flip(PublicadorSondar::CAMPOS_USUARIO));
        }
        $this->gravar('conta/usuario.json', $r);
    }

    /** @return list<array{id: string, tipo: string}> as promoções sondadas */
    private function promocoes(string $sellerId): array
    {
        $r = $this->chamar('GET', "/seller-promotions/users/{$sellerId}", ['app_version' => 'v2', 'limit' => 50]);
        $this->gravar('promocoes/convites.json', $r);

        $sondadas = [];
        foreach (array_slice((array) ($r['resposta']['results'] ?? []), 0, 5) as $p) {
            $pid = (string) ($p['id'] ?? '');
            $tipo = (string) ($p['type'] ?? '');
            // Id e tipo viram caminho e query: só caracteres seguros.
            if (! preg_match('/^[A-Za-z0-9-]{1,40}$/', $pid) || ! preg_match('/^[A-Z_]{2,40}$/', $tipo)) {
                continue;
            }
            $sondadas[] = ['id' => $pid, 'tipo' => $tipo];
            $base = ['promotion_type' => $tipo, 'app_version' => 'v2'];
            $this->gravar("promocoes/{$pid}_detalhe.json", $this->chamar('GET', "/seller-promotions/promotions/{$pid}", $base));
            $this->gravar("promocoes/{$pid}_itens.json", $this->chamar('GET', "/seller-promotions/promotions/{$pid}/items", [...$base, 'limit' => 10]));
        }

        // Um pedido só com candidatos (Questão 1: offer_id de SMART/PRICE_MATCHING).
        if ($sondadas !== []) {
            $p = $sondadas[0];
            $this->gravar("promocoes/{$p['id']}_itens_candidatos.json", $this->chamar('GET', "/seller-promotions/promotions/{$p['id']}/items",
                ['promotion_type' => $p['tipo'], 'app_version' => 'v2', 'limit' => 10, 'status' => 'candidate']));
        }

        $this->gravar('promocoes/exclusao_vendedor.json', $this->chamar('GET', '/seller-promotions/exclusion-list/seller', ['app_version' => 'v2']));

        return $sondadas;
    }

    // ═══ Anúncios ═══════════════════════════════════════════════════════════

    /** @return list<string> */
    private function itensDaSondagem(string $sellerId): array
    {
        if ($this->option('itens')) {
            $ids = array_filter(array_map('trim', explode(',', (string) $this->option('itens'))), fn ($i) => preg_match('/^MLB\d+$/', $i));

            return array_slice(array_values($ids), 0, 5);
        }

        $r = $this->chamar('GET', "/users/{$sellerId}/items/search", ['status' => 'active', 'limit' => 5]);
        $this->gravar('itens/busca.json', $r);

        return array_slice(array_values(array_filter((array) ($r['resposta']['results'] ?? []), fn ($i) => is_string($i) && preg_match('/^MLB\d+$/', $i))), 0, 5);
    }

    /** @param list<string> $itens */
    private function itens(string $sellerId, array $itens): void
    {
        foreach ($itens as $item) {
            $this->gravar("itens/{$item}_promocoes.json", $this->chamar('GET', "/seller-promotions/items/{$item}", ['app_version' => 'v2']));
            $this->gravar("itens/{$item}_precos.json", $this->chamar('GET', "/items/{$item}/prices", ['display_version' => 'true'], ['show-all-prices' => 'true']));
            $this->gravar("itens/{$item}_dados.json", $this->chamar('GET', "/items/{$item}",
                ['attributes' => 'id,tags,shipping,price,original_price,category_id,listing_type_id']));
        }
    }

    // ═══ Publicidade ════════════════════════════════════════════════════════

    private function publicidade(): void
    {
        $r = $this->chamar('GET', '/advertising/advertisers', ['product_id' => 'PADS'], ['Api-Version' => '1']);
        $this->gravar('publicidade/anunciante.json', $r);

        $adv = null;
        foreach ((array) ($r['resposta']['advertisers'] ?? []) as $a) {
            if (is_array($a) && ($a['site_id'] ?? null) === 'MLB' && preg_match('/^\d{1,20}$/', (string) ($a['advertiser_id'] ?? ''))) {
                $adv = (string) $a['advertiser_id'];
            }
        }

        if ($adv !== null) {
            $janela = ['date_from' => now()->subDays(29)->format('Y-m-d'), 'date_to' => now()->format('Y-m-d'),
                'metrics' => 'clicks,prints,ctr,cost,cpc,acos,roas,units_quantity,total_amount'];
            $this->gravar('publicidade/campaigns_search.json', $this->chamar('GET', "/advertising/MLB/advertisers/{$adv}/product_ads/campaigns/search",
                [...$janela, 'limit' => 50, 'offset' => 0, 'metrics_summary' => 'true'], ['api-version' => '2']));
            $this->gravar('publicidade/ad_groups_search.json', $this->chamar('GET', "/advertising/MLB/advertisers/{$adv}/product_ads/ad_groups/search",
                [...$janela, 'limit' => 50, 'sort' => 'desc', 'sort_by' => 'clicks'], ['api-version' => '2']));
        } else {
            $this->warn('Sem anunciante de Product Ads no site MLB: campanhas e Ad Groups não sondados (A6: veja publicidade/anunciante.json).');
        }

        $this->gravar('publicidade/bonifications.json', $this->chamar('GET', '/advertising/advertisers/bonifications'));
    }

    private function resumo(array $promocoes): void
    {
        $this->info("{$this->gravados} arquivo(s) em {$this->saida}");
        foreach ($this->arquivos as $a) {
            $this->line("  {$a}");
        }
        $p = $promocoes[0]['id'] ?? '<promoção>';
        $this->line('Qual arquivo responde a cada suposição:');
        $this->line('  A1  (faixas no GET de prices)        → itens/<MLB>_precos.json');
        $this->line("  A3  (cofinanciada)                   → promocoes/{$p}_itens.json (meli_percentage / seller_percentage)");
        $this->line('  A6  (Ads sem a permissão Advertising)→ publicidade/*.json (status 403/404 ou dados)');
        $this->line('  A9  (paginação de convites)          → promocoes/convites.json (paging e total)');
        $this->line("  Q1  (offer_id de candidato SMART)    → promocoes/{$p}_itens_candidatos.json");
    }

    // ═══ HTTP e gravação ════════════════════════════════════════════════════

    /**
     * Uma chamada GET, devolvida como envelope `{requisicao, status, resposta}`. Cabeçalhos não entram no envelope.
     *
     * @return array{requisicao: array, status: int, resposta: mixed, capturado_em: string, origem: string}
     */
    private function chamar(string $metodo, string $caminho, array $query = [], array $cabecalhos = []): array
    {
        self::garantirSomenteLeitura($metodo, $caminho);

        try {
            $resp = Http::withToken($this->token)->withHeaders($cabecalhos)->acceptJson()->timeout(30)->get(self::API.$caminho, $query);
            $status = $resp->status();
            $corpo = $resp->json() ?? ($resp->body() === '' ? null : $resp->body());
        } catch (\Throwable $e) {
            $status = 0;
            $corpo = ['erro_de_rede' => $e->getMessage()];
        }

        $this->line(sprintf('  %s %s → %d', $metodo, $caminho, $status));

        return [
            'requisicao' => ['metodo' => $metodo, 'caminho' => $caminho, 'query' => $query ?: null],
            'status' => $status,
            'resposta' => $corpo,
            'capturado_em' => now()->toIso8601String(),
            'origem' => 'sondagem',
        ];
    }

    /** A sondagem das Alavancas não escreve em nada: qualquer verbo diferente de GET é recusado. */
    public static function garantirSomenteLeitura(string $metodo, string $caminho): void
    {
        if (strtoupper($metodo) !== 'GET') {
            throw new \LogicException("A sondagem das Alavancas só lê: {$metodo} {$caminho} recusado.");
        }
    }

    private function gravar(string $relativo, array $envelope): void
    {
        $envelope['resposta'] = PublicadorSondar::sanitizar($envelope['resposta']);
        $arquivo = $this->saida.'/'.$relativo;
        if (! is_dir(dirname($arquivo))) {
            mkdir(dirname($arquivo), 0775, true);
        }
        file_put_contents($arquivo, json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        $this->gravados++;
        $this->arquivos[] = $relativo;
    }
}
