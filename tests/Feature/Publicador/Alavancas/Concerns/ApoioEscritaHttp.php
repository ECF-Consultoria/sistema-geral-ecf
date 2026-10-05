<?php

namespace Tests\Feature\Publicador\Alavancas\Concerns;

use App\Services\Publicador\Alavancas\AssinaturaDaPrevia;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * 166-11: apoio dos testes da porta HTTP de escrita — convite DEAL com N produtos candidatos,
 * rotas da prévia/confirmação e filtro das escritas que SAEM para o ML (o layout também fala
 * com o ECF Drive em cada render, então "não escreveu" olha só o host do Mercado Livre).
 * Usar junto com CenarioAlavancas.
 */
trait ApoioEscritaHttp
{
    /** @var array<string, list<array>> itens de cada promoção ("TIPO|id") no formato do ML */
    protected array $promos = [];

    /** @var list<string> */
    protected array $outroVendedor = [];

    /** Convite DEAL "P-1" com os produtos MLB1..MLB{n} candidatos (preço 100, faixa 70–95). */
    protected function cenarioDeal(int $n = 1, string $ancora = 'company', bool $liberada = true): void
    {
        $this->montarAlavancas($ancora, $liberada);
        RateLimiter::clear('alavancas:analise:'.$this->contaAlavanca()->chaveConta());

        for ($i = 1; $i <= $n; $i++) {
            $this->promos['DEAL|P-1'][] = ['id' => "MLB{$i}", 'status' => 'candidate', 'price' => 100, 'original_price' => 100,
                'min_discounted_price' => 70, 'max_discounted_price' => 95];
        }

        $this->responder('GET', '#^/items$#', function (Request $r) {
            $ids = explode(',', (string) ($r->data()['ids'] ?? ''));

            return Http::response(array_map(fn ($id) => ['code' => 200, 'body' => [
                'id' => $id, 'seller_id' => in_array($id, $this->outroVendedor, true) ? 999000111 : 1555596317, 'title' => "Produto {$id}",
                'price' => 100, 'original_price' => null, 'status' => 'active', 'condition' => 'new', 'listing_type_id' => 'gold_special',
                'category_id' => 'MLB1000', 'available_quantity' => 10, 'attributes' => [],
            ]], $ids), 200);
        });
        $this->responder('GET', '#^/seller-promotions/promotions/[^/]+/items$#', function (Request $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            $id = explode('/', (string) parse_url($r->url(), PHP_URL_PATH))[3];
            $linhas = $this->promos[($q['promotion_type'] ?? '').'|'.$id] ?? [];
            if (isset($q['item_id'])) {
                $linhas = array_values(array_filter($linhas, fn ($l) => $l['id'] === $q['item_id']));
            }

            return Http::response(['results' => $linhas, 'paging' => ['offset' => 0, 'limit' => 50, 'total' => count($linhas)]], 200);
        });
        $this->responder('GET', '#^/seller-promotions/items/MLB\d+$#', []);
        $this->responder('GET', '#^/sites/MLB/listing_prices$#', function (Request $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return Http::response(['listing_type_id' => $q['listing_type_id'] ?? 'gold_special', 'sale_fee_amount' => round(((float) ($q['price'] ?? 100)) * 0.15, 2)], 200);
        });
        $this->responder('POST', '#^/seller-promotions/items/MLB\d+$#', self::fixtureAlavanca('doc/acoes/post_item_ok'));
    }

    /** @return array{item_id: string, promotion_type: string, promotion_id: string, deal_price: int|float} */
    protected function itemDeal(int $i = 1, int|float $preco = 85): array
    {
        return ['item_id' => "MLB{$i}", 'promotion_type' => 'DEAL', 'promotion_id' => 'P-1', 'deal_price' => $preco];
    }

    protected function rota(string $nome, array $extra = []): string
    {
        return route("mlb.anuncios.publicador.alavancas.{$nome}", ['conta' => $this->ancora->chaveContaMl(), ...$extra]);
    }

    protected function previaDe(string $acao, array $itens)
    {
        return $this->actingAs($this->admin)->postJson($this->rota('escritas.previa'), ['acao' => $acao, 'itens' => $itens]);
    }

    protected function confirmarCom(string $acao, array $itens, ?string $assinatura)
    {
        return $this->actingAs($this->admin)->postJson($this->rota('escritas.confirmar'), ['acao' => $acao, 'itens' => $itens, 'assinatura' => $assinatura]);
    }

    /** Assinatura válida forjada no teste (para simular conta que saiu da lista depois da prévia). */
    protected function assinaturaForjada(string $acao, array $itens, ?string $chaveTela = null, ?int $userId = null): string
    {
        return AssinaturaDaPrevia::gerar(AssinaturaDaPrevia::canonico($acao, $itens), $chaveTela ?? $this->ancora->chaveContaMl(), $userId ?? $this->admin->id);
    }

    /** @return list<array> as chamadas que escrevem NO ML (não-GET no host do Mercado Livre) */
    protected function escritasNoMl(): array
    {
        return array_values(array_filter($this->chamadasNaoGet(), fn (array $c) => str_contains($c['url'], 'mercadolibre')));
    }

    /** @return list<array> */
    protected function chamadasAoMl(string $metodo, string $caminhoRegex): array
    {
        return array_values(array_filter($this->chamadas, fn (array $c) => $c['metodo'] === $metodo
            && str_contains($c['url'], 'mercadolibre') && preg_match($caminhoRegex, $c['caminho'])));
    }
}
