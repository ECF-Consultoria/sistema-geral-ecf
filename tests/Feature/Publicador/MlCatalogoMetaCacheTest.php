<?php

namespace Tests\Feature\Publicador;

use App\Services\Mlb\Publicacao\MlCatalogoMetaService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * RG2 (22/10/2026): o preditor do ML passou a devolver 200 `[]` e o `[]` ficava
 * 1 h no cache; a falha de `/categories/{id}` ficava 7 dias. Só resposta 2xx não
 * vazia entra no cache longo; o vazio do preditor fica no máximo 60 s.
 */
class MlCatalogoMetaCacheTest extends TestCase
{
    private const PREDITO = [['category_id' => 'MLB1574', 'category_name' => 'Cadeiras', 'domain_id' => 'MLB-CHAIRS']];

    /** Estado do ML: 'fora' (500), 'vazio' (200 []) ou 'ok'. Muda o ESTADO, não o fake (learnings §5). */
    private string $ml = 'fora';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        Http::fake(function (Request $r) {
            if (str_contains($r->url(), '/oauth/token')) {
                return Http::response(['access_token' => 'app-token', 'expires_in' => 21600]);
            }

            if ($this->ml === 'fora') {
                return Http::response(['message' => 'internal error'], 500);
            }

            if (str_contains($r->url(), '/domain_discovery/search')) {
                return Http::response($this->ml === 'vazio' ? [] : self::PREDITO);
            }
            if (str_contains($r->url(), '/attributes')) {
                return Http::response([['id' => 'BRAND', 'tags' => ['required' => true]]]);
            }

            return Http::response(['id' => 'MLB1574', 'settings' => ['max_title_length' => 60]]);
        });
    }

    private function chamadas(string $trecho): int
    {
        return count(Http::recorded(fn (Request $q) => str_contains($q->url(), $trecho)));
    }

    private function meta(): MlCatalogoMetaService
    {
        return app(MlCatalogoMetaService::class);
    }

    public function test_preditor_vazio_nao_fica_uma_hora_no_cache(): void
    {
        $this->ml = 'vazio';
        $this->assertSame([], $this->meta()->preverCategoria('cadeira'));

        // Dentro dos 60 s não volta ao ML (não martela enquanto ele está fora)…
        $this->assertSame([], $this->meta()->preverCategoria('cadeira'));
        $this->assertSame(1, $this->chamadas('/domain_discovery/search'));

        // …e o ML voltou: passados os 60 s, a próxima busca vai a ele e acha.
        $this->ml = 'ok';
        $this->travel(61)->seconds();
        $this->assertSame(self::PREDITO, $this->meta()->preverCategoria('cadeira'));
        $this->assertSame(2, $this->chamadas('/domain_discovery/search'));

        // A resposta boa fica no cache: a terceira não sai daqui.
        $this->assertSame(self::PREDITO, $this->meta()->preverCategoria('cadeira'));
        $this->assertSame(2, $this->chamadas('/domain_discovery/search'));
    }

    public function test_preditor_que_falhou_volta_ao_ml_passado_o_cache_curto(): void
    {
        $this->assertSame([], $this->meta()->preverCategoria('cadeira'));

        $this->ml = 'ok';
        $this->travel(61)->seconds();
        $this->assertSame(self::PREDITO, $this->meta()->preverCategoria('cadeira'));
        $this->assertSame(2, $this->chamadas('/domain_discovery/search'));
    }

    public function test_vazio_antigo_no_cache_do_preditor_conta_como_ausente(): void
    {
        // Sobra do comportamento de antes: `[]` gravado na chave longa.
        Cache::put('ml_meta_preditor_'.md5('cadeira|8'), [], 3600);
        $this->ml = 'ok';

        $this->assertSame(self::PREDITO, $this->meta()->preverCategoria('cadeira'));
        $this->assertSame(1, $this->chamadas('/domain_discovery/search'));
    }

    public function test_categoria_que_falhou_nao_entra_no_cache_e_a_segunda_vai_ao_ml(): void
    {
        $this->assertSame([], $this->meta()->categoria('MLB1574'));
        $this->assertNull(Cache::get('ml_meta_categoria_MLB1574'));

        $this->ml = 'ok';
        $this->assertSame('MLB1574', $this->meta()->categoria('MLB1574')['id']);
        $this->assertSame(2, $this->chamadas('/categories/MLB1574'));

        // Sucesso fica no cache (a chave que outros serviços leem).
        $this->assertSame('MLB1574', Cache::get('ml_meta_categoria_MLB1574')['id']);
        $this->meta()->categoria('MLB1574');
        $this->assertSame(2, $this->chamadas('/categories/MLB1574'));
    }

    public function test_atributos_que_falharam_nao_entram_no_cache_e_vazio_antigo_conta_como_ausente(): void
    {
        $this->assertSame([], $this->meta()->atributos('MLB1574'));
        $this->assertNull(Cache::get('ml_meta_atributos_MLB1574'));

        Cache::put('ml_meta_atributos_MLB1574', [], 604800);
        $this->ml = 'ok';
        $this->assertSame('BRAND', $this->meta()->atributos('MLB1574')[0]['id']);
        $this->assertSame(2, $this->chamadas('/attributes'));
    }

    public function test_tipos_de_anuncio_que_falharam_nao_entram_no_cache(): void
    {
        $this->assertSame([], $this->meta()->tiposDeAnuncio());
        $this->assertNull(Cache::get('ml_meta_listing_types_MLB'));
    }

    public function test_erro_de_rede_degrada_para_vazio_sem_derrubar(): void
    {
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), '/oauth/token')) {
                return Http::response(['access_token' => 'app-token', 'expires_in' => 21600]);
            }
            throw new \Illuminate\Http\Client\ConnectionException('timeout');
        });

        $this->assertSame([], $this->meta()->categoria('MLB1574'));
        $this->assertSame([], $this->meta()->preverCategoria('mesa'));
    }
}
