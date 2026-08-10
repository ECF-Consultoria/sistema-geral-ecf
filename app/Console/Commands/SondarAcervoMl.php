<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\MercadoLivreService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sondagem read-only da Fase 134 ("Meus Anúncios").
 *
 * Único ponto desta fase que fala com a API real do Mercado Livre. Existe
 * para responder à pergunta que o D-21 deixou aberta — o ML ainda expõe um
 * score de saúde comparável (`GET /item/{id}/performance`) para item
 * clássico deste app? — e para capturar fixtures REAIS que alimentam os
 * testes das waves seguintes (`Http::fake()` nunca deve rodar sobre shape
 * inventado).
 *
 * D-11 — ZERO write na API do ML: toda chamada sai por
 * `MercadoLivreService::get()`. Este arquivo NUNCA usa post()/put()/delete()
 * nem `Http::` direto — o teste `comando_de_sondagem_nunca_escreve_na_api_do_ml()`
 * (tests/Unit/Phase134/SondagemSaudeMlTest.php) trava essa invariante.
 */
class SondarAcervoMl extends Command
{
    protected $signature = 'mlb:acervo-sondar
        {--company= : ID da Company (senão usa a primeira com MlToken ativo)}
        {--item= : MLB id específico para a sondagem D-21 (senão escolhe do multiget)}
        {--fixtures : grava os corpos reais recebidos em tests/fixtures/phase134/}';

    protected $description = 'Sondagem read-only da Fase 134 — testa GET /item/{id}/performance (D-21) e captura fixtures reais da API do ML';

    private const FIXTURES_DIR = 'tests/fixtures/phase134';

    public function handle(MercadoLivreService $ml): int
    {
        $company = $this->resolverEmpresa();

        if (! $company) {
            $this->error('Nenhuma empresa com MlToken ativo neste ambiente — não há dado real para sondar.');
            $this->line('  Rode este comando na VPS de produção, ou conecte uma empresa via OAuth ML localmente.');

            return self::FAILURE;
        }

        $mlUserId = $company->mlToken->ml_user_id;
        $this->info("Sondagem contra empresa {$company->id} ({$company->name}), ml_user_id={$mlUserId}");
        Log::info("[MLB Anuncios] Sondagem D-21 iniciada — company_id={$company->id}");

        // ── Passo 1: enumeração por scroll (3 páginas) ──────────────────────
        [$ids, $scrolls] = $this->enumerarPorScroll($ml, $company, $mlUserId);
        $totalIds  = count($ids);
        $unicos    = count(array_unique($ids));
        $repetidos = $totalIds - $unicos;
        $this->info("1. Scroll: {$totalIds} ids em 3 páginas, {$unicos} únicos (repetidos: {$repetidos})");

        if ($this->option('fixtures')) {
            $this->gravarFixtures($scrolls);
        }

        if (empty($ids)) {
            $this->error('Scroll não trouxe nenhum id — não é possível seguir com o multiget.');

            return self::FAILURE;
        }

        // ── Passo 2: multiget dos 20 primeiros ids ──────────────────────────
        $lote20 = array_slice($ids, 0, config('mlb_acervo.lote_multiget'));
        $multiget = $this->multiget($ml, $company, $lote20);
        $comSucesso = collect($multiget)->filter(fn ($w) => ($w['code'] ?? null) === 200)->count();
        $this->info('2. Multiget: ' . count($multiget) . " itens, {$comSucesso} com code 200");

        if ($this->option('fixtures')) {
            $this->gravarFixture('multiget-lote.json', $multiget);
        }

        // Item com variações — busca até achar (mais lotes de scroll se precisar)
        $itemComVariacoes = $this->buscarItemComVariacoes($ml, $company, $multiget, $ids, $mlUserId);
        if ($this->option('fixtures') && $itemComVariacoes) {
            $this->gravarFixture('multiget-item-com-variacoes.json', $itemComVariacoes);
        }

        // ── Passo 3: seleção do item "clássico" para a sondagem D-21 ────────
        $itemIdSondagem = $this->option('item') ?: $this->selecionarItemClassico($multiget);

        if (! $itemIdSondagem) {
            $this->warn('3. Nenhum item clássico (sem user_product_id, catalog_listing=false) encontrado no lote — sondagem D-21 fica sem alvo.');
        } else {
            $this->info("3. Item da sondagem D-21: {$itemIdSondagem}");
        }

        // ── Passo 4: sondagem D-21 — GET /item/{id}/performance (singular) ──
        $performance = null;
        $veredicto   = 'INDISPONIVEL';

        if ($itemIdSondagem) {
            try {
                $performance = $ml->get($company, "/item/{$itemIdSondagem}/performance");
                $veredicto   = 'DISPONIVEL';
                $this->info('4. GET /item/{id}/performance RESPONDEU — item ' . $itemIdSondagem);
            } catch (\Throwable $e) {
                $performance = ['erro' => $e->getMessage(), 'item_id' => $itemIdSondagem];
                $this->warn('4. GET /item/{id}/performance falhou: ' . $e->getMessage());
                Log::info("[MLB Anuncios] Sondagem D-21 falhou para item {$itemIdSondagem}: {$e->getMessage()}");
            }
        } else {
            $performance = ['erro' => 'nenhum item clássico disponível no lote para sondar'];
        }

        if ($this->option('fixtures')) {
            $this->gravarFixture('performance-sondagem.json', $performance);
        }

        // ── Passo 5: buy box — price_to_win num item catalog_listing=true ───
        $itemCatalogo = collect($multiget)
            ->first(fn ($w) => ($w['code'] ?? null) === 200 && ($w['body']['catalog_listing'] ?? false) === true);

        $priceToWin = null;
        if ($itemCatalogo) {
            $idCatalogo = $itemCatalogo['body']['id'];
            try {
                $priceToWin = $ml->get($company, "/items/{$idCatalogo}/price_to_win", ['version' => 'v2']);
                $this->info("5. price_to_win: item {$idCatalogo}, status=" . ($priceToWin['status'] ?? 'n/d'));
            } catch (\Throwable $e) {
                $priceToWin = ['erro' => $e->getMessage(), 'item_id' => $idCatalogo];
                $this->warn('5. price_to_win falhou: ' . $e->getMessage());
            }
        } else {
            $priceToWin = ['erro' => 'nenhum item catalog_listing=true no lote'];
            $this->warn('5. Nenhum item catalog_listing=true no lote — price_to_win sem alvo.');
        }

        if ($this->option('fixtures')) {
            $this->gravarFixture('price-to-win.json', $priceToWin);
        }

        // ── Passo 6: visitas — 1 item, últimos 30 dias ───────────────────────
        $idParaVisitas = $multiget[0]['body']['id'] ?? null;
        $visits = null;
        if ($idParaVisitas) {
            try {
                $visits = $ml->get($company, "/items/{$idParaVisitas}/visits", [
                    'date_from' => now()->subDays(30)->toDateString(),
                    'date_to'   => now()->toDateString(),
                ]);
                $this->info("6. visits: item {$idParaVisitas} OK");
            } catch (\Throwable $e) {
                $visits = ['erro' => $e->getMessage(), 'item_id' => $idParaVisitas];
                $this->warn('6. visits falhou: ' . $e->getMessage());
            }
        } else {
            $visits = ['erro' => 'nenhum item disponível para visitas'];
        }

        if ($this->option('fixtures')) {
            $this->gravarFixture('visits.json', $visits);
        }

        // ── Veredicto ─────────────────────────────────────────────────────
        $instrucao = $veredicto === 'DISPONIVEL'
            ? 'MLB_ACERVO_SAUDE_ML=true'
            : 'MLB_ACERVO_SAUDE_ML=false (default — nenhuma ação necessária)';

        $this->newLine();
        $this->info("VEREDICTO D-21: {$veredicto} — {$instrucao}");
        Log::info("[MLB Anuncios] VEREDICTO D-21: {$veredicto} — company_id={$company->id}, item={$itemIdSondagem}");

        return self::SUCCESS;
    }

    // ═══ Resolução da empresa ═══════════════════════════════════════════════

    private function resolverEmpresa(): ?Company
    {
        $companyId = $this->option('company');

        if ($companyId) {
            return Company::with('mlToken')->find($companyId);
        }

        return Company::query()
            ->whereHas('mlToken', fn ($q) => $q->where('status', 'active'))
            ->with('mlToken')
            ->first();
    }

    // ═══ Passo 1: enumeração por scroll ══════════════════════════════════════

    /** @return array{0: array<string>, 1: array<int, array>} [idsAcumulados, respostasCruas(3)] */
    private function enumerarPorScroll(MercadoLivreService $ml, Company $company, string $mlUserId): array
    {
        $ids      = [];
        $respostas = [];
        $scrollId = null;

        for ($pagina = 1; $pagina <= 3; $pagina++) {
            $params = ['search_type' => 'scan', 'limit' => config('mlb_acervo.pagina_scroll')];
            if ($scrollId) {
                $params['scroll_id'] = $scrollId;
            }

            try {
                $resposta = $ml->get($company, "/users/{$mlUserId}/items/search", $params);
            } catch (\Throwable $e) {
                Log::info("[MLB Anuncios] Scroll página {$pagina} falhou: {$e->getMessage()}");
                $resposta = ['erro' => $e->getMessage()];
            }

            $respostas[] = $resposta;
            $scrollId    = $resposta['scroll_id'] ?? null;
            $paginaIds   = $resposta['results'] ?? [];
            $ids         = array_merge($ids, $paginaIds);

            if (empty($paginaIds)) {
                break; // scroll terminou
            }
        }

        return [$ids, $respostas];
    }

    // ═══ Passo 2: multiget ════════════════════════════════════════════════════

    private function multiget(MercadoLivreService $ml, Company $company, array $ids): array
    {
        try {
            return $ml->get($company, '/items', [
                'ids'        => implode(',', $ids),
                'attributes' => 'id,status,sub_status,available_quantity,sold_quantity,'
                    . 'shipping,listing_type_id,tags,catalog_listing,catalog_product_id,'
                    . 'variations,title,price,permalink,thumbnail,category_id,attributes,pictures',
            ]);
        } catch (\Throwable $e) {
            Log::info("[MLB Anuncios] Multiget falhou: {$e->getMessage()}");

            return [];
        }
    }

    /**
     * Procura, no lote já buscado, um item com `variations` não vazio. Se não
     * achar, busca mais um lote de 20 (próximos ids do scroll) até achar ou
     * esgotar os ids conhecidos.
     */
    private function buscarItemComVariacoes(MercadoLivreService $ml, Company $company, array $multiget, array $todosIds, string $mlUserId): ?array
    {
        $achado = collect($multiget)->first(
            fn ($w) => ($w['code'] ?? null) === 200 && ! empty($w['body']['variations'] ?? [])
        );

        if ($achado) {
            return $achado['body'];
        }

        // Não achou no primeiro lote — tenta mais lotes dos ids já enumerados.
        $loteSize = config('mlb_acervo.lote_multiget');
        $offset   = $loteSize;

        while ($offset < count($todosIds)) {
            $proximoLote = array_slice($todosIds, $offset, $loteSize);
            $resultado   = $this->multiget($ml, $company, $proximoLote);

            $achado = collect($resultado)->first(
                fn ($w) => ($w['code'] ?? null) === 200 && ! empty($w['body']['variations'] ?? [])
            );

            if ($achado) {
                return $achado['body'];
            }

            $offset += $loteSize;
        }

        $this->warn('Nenhum item com variações encontrado nos ids enumerados — fixture multiget-item-com-variacoes.json não será gravada.');

        return null;
    }

    // ═══ Passo 3: seleção do item clássico ════════════════════════════════════

    private function selecionarItemClassico(array $multiget): ?string
    {
        $item = collect($multiget)->first(function ($w) {
            if (($w['code'] ?? null) !== 200) {
                return false;
            }

            $body = $w['body'] ?? [];

            return ($body['catalog_listing'] ?? false) === false
                && ! array_key_exists('user_product_id', $body);
        });

        return $item['body']['id'] ?? null;
    }

    // ═══ Fixtures ══════════════════════════════════════════════════════════════

    private function gravarFixtures(array $respostasScroll): void
    {
        foreach ($respostasScroll as $i => $resposta) {
            $this->gravarFixture('scroll-pagina-' . ($i + 1) . '.json', $resposta);
        }
    }

    private function gravarFixture(string $nome, $conteudo): void
    {
        $dir = base_path(self::FIXTURES_DIR);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir . DIRECTORY_SEPARATOR . $nome;
        file_put_contents($path, json_encode($conteudo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->line("  → fixture gravada: {$nome}");
    }
}
