<?php

namespace Tests\Feature\Publicador\Lote;

use App\Models\PubFilaPublicacao;
use App\Models\PubFilaPublicacaoItem;
use App\Services\Publicador\PublicacaoService;
use App\Support\Publicador\RegraViolada;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A fila em RODADAS (pedido do usuário, 10/10/2026: "sobe cinco de uma vez — Clássico e Premium —, depois de uns
 * 20 minutos mais cinco"): o padrão é 5 produtos por rodada, uma rodada a cada 20 minutos, os dois ajustáveis;
 * dentro da rodada vale o teto global de 2 inícios por minuto (a rodada de 5 começa em ~3 minutos); a rodada
 * seguinte espera o intervalo E a anterior terminar; produto que não chega a publicar não gasta vaga.
 *
 * Mesmo relógio e mesmo ML simulado do `FilaDePublicacaoTest` (segunda, 12/10/2026 10:00, São Paulo). O passo de
 * um produto por vez (rodada de 1) e as regras de cada produto estão lá.
 */
class FilaEmRodadasTest extends TestCase
{
    use CenarioDaFila;
    use RefreshDatabase;

    private const A = PubFilaPublicacaoItem::AGENDADO;

    private const P = PubFilaPublicacaoItem::PUBLICANDO;

    private const OK = PubFilaPublicacaoItem::PUBLICADO;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:00', 'America/Sao_Paulo'));
        Queue::fake();
        Notification::fake();
        $this->withoutVite();
        $this->montarFila(7);
    }

    private function minuto(int $n): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:00', 'America/Sao_Paulo')->addMinutes($n));
    }

    /** @return list<string> o status do item de cada cadeira, na ordem do cenário */
    private function situacao(): array
    {
        return array_map(fn ($p) => $this->itemDe($p)->status, $this->cadeiras);
    }

    private function terminarAsQuePublicam(): void
    {
        foreach ($this->cadeiras as $p) {
            if ($this->itemDe($p)->status === self::P) {
                $this->terminarPublicacao($p);
            }
        }
    }

    public function test_padrao_cinco_por_rodada_a_cada_vinte_minutos_com_o_teto_de_dois_por_minuto(): void
    {
        $this->conferirTodas();
        $this->agendar()->assertCreated();
        $this->assertSame([5, 20], [$this->fila()->produtos_por_rodada, $this->fila()->intervalo_minutos], 'o padrão do usuário');

        // A previsão do painel: 2 + 2 + 1 na primeira rodada (o teto do minuto), os outros dois às 10:20.
        $painel = $this->actingAs($this->admin)->getJson($this->rotaLote('dados'))->assertOk()->json('fila');
        $this->assertSame(5, $painel['produtos_por_rodada']);
        $this->assertSame(
            ['10:00', '10:00', '10:01', '10:01', '10:02', '10:20', '10:20'],
            array_map(fn ($i) => CarbonImmutable::parse($i['previsto_em'])->format('H:i'), $painel['itens']),
        );
        $this->assertSame('2026-10-12T10:22:00-03:00', $painel['termina_em'], 'a última rodada começa 10:20 e leva ~2 min');

        $this->passada(); // 10:00
        $this->assertSame([self::P, self::P, self::A, self::A, self::A, self::A, self::A], $this->situacao(), 'dois inícios por minuto');
        $this->minuto(1);
        $this->passada();
        $this->assertSame([self::P, self::P, self::P, self::P, self::A, self::A, self::A], $this->situacao(), 'a rodada continua com os outros ainda publicando');
        $this->minuto(2);
        $this->passada();
        $this->assertSame([self::P, self::P, self::P, self::P, self::P, self::A, self::A], $this->situacao());
        $this->assertSame(5, $this->fila()->rodada_inicios);
        $this->assertSame('2026-10-12 10:00', $this->fila()->rodada_iniciada_em->format('Y-m-d H:i'));
        $this->assertSame('2026-10-12 10:20', $this->fila()->proximo_em->format('Y-m-d H:i'), 'a próxima rodada conta do INÍCIO desta');

        $this->minuto(5);
        $this->terminarAsQuePublicam();
        $this->passada(); // fecha os cinco; a rodada está cheia: o 6º espera o intervalo
        $this->assertSame([self::OK, self::OK, self::OK, self::OK, self::OK, self::A, self::A], $this->situacao());

        $this->minuto(19);
        $this->passada();
        $this->assertSame(self::A, $this->itemDe($this->cadeiras[5])->status, 'ainda dentro dos 20 minutos');

        $this->minuto(20);
        $this->passada(); // 10:20: rodada nova
        $this->assertSame([self::OK, self::OK, self::OK, self::OK, self::OK, self::P, self::P], $this->situacao());
        $this->assertSame(2, $this->fila()->rodada_inicios);
        $this->assertSame(7, $this->publicacoes());

        $this->minuto(22);
        $this->terminarAsQuePublicam();
        $this->passada();
        $this->assertSame(PubFilaPublicacao::CONCLUIDA, $this->fila()->status);
        $this->assertSame(7, PubFilaPublicacaoItem::query()->where('status', self::OK)->count());
    }

    public function test_rodada_nova_espera_a_anterior_terminar(): void
    {
        [$a, $b, $c] = $this->cadeiras;
        $this->conferirTodas([$a, $b, $c]);
        $this->agendar([$a, $b, $c], ['ciente' => true, 'produtos_por_rodada' => 2, 'intervalo_minutos' => 10])->assertCreated();

        $this->passada(); // 10:00: A e B
        $this->assertSame([self::P, self::P, self::A], [$this->itemDe($a)->status, $this->itemDe($b)->status, $this->itemDe($c)->status]);

        $this->minuto(3);
        $this->terminarPublicacao($a); // o B segue publicando
        $this->passada();
        $this->minuto(12);
        $this->passada();
        $this->assertSame(self::A, $this->itemDe($c)->status, 'o intervalo venceu, mas o B da rodada anterior ainda publica');

        $this->minuto(13);
        $this->terminarPublicacao($b);
        $this->passada(); // fecha o B e começa a rodada nova na mesma passada
        $this->assertSame(self::P, $this->itemDe($c)->status);
        $this->assertSame('2026-10-12 10:13', $this->fila()->rodada_iniciada_em->format('Y-m-d H:i'));
        $this->assertSame('2026-10-12 10:23', $this->fila()->proximo_em->format('Y-m-d H:i'));
        $this->assertSame(1, $this->fila()->rodada_inicios);
    }

    public function test_produto_que_vira_precisa_revisar_nao_gasta_vaga_da_rodada(): void
    {
        [$a, $b, $c, $d] = $this->cadeiras;
        $this->conferirTodas([$a, $b, $c, $d]);
        $this->agendar([$a, $b, $c, $d], ['ciente' => true, 'produtos_por_rodada' => 3])->assertCreated();
        $this->repo->tocar($this->rascunhoDe($a)); // o A mudou depois de agendado

        $this->passada(); // 10:00: o A vira "precisa revisar" sem gastar vaga; B e C começam (teto do minuto)
        $this->assertSame(PubFilaPublicacaoItem::PRECISA_REVISAR, $this->itemDe($a)->status);
        $this->assertSame([self::P, self::P, self::A], [$this->itemDe($b)->status, $this->itemDe($c)->status, $this->itemDe($d)->status]);

        $this->minuto(1);
        $this->passada(); // a 3ª vaga da rodada é do D
        $this->assertSame(self::P, $this->itemDe($d)->status);
        $this->assertSame(3, $this->fila()->rodada_inicios);
        $this->assertSame(3, $this->publicacoes(), 'o A não publicou nada');
    }

    public function test_inicio_recusado_devolve_a_vaga_e_o_proximo_abre_a_rodada(): void
    {
        [$a, $b] = $this->cadeiras;
        $this->conferirTodas([$a, $b]);
        $this->agendar([$a, $b], ['ciente' => true, 'produtos_por_rodada' => 2])->assertCreated();
        $real = app(PublicacaoService::class);
        $primeira = true;
        $this->mock(PublicacaoService::class, fn ($m) => $m->shouldReceive('iniciar')->andReturnUsing(function (...$args) use ($real, &$primeira) {
            if ($primeira) {
                $primeira = false;

                throw new RegraViolada('V-PUB-99', 'Recusado na hora de começar.');
            }

            return $real->iniciar(...$args);
        }));

        $this->passada(); // 10:00: o A é recusado ao começar (nada sai); o B abre a rodada

        $this->assertSame(PubFilaPublicacaoItem::PRECISA_REVISAR, $this->itemDe($a)->status);
        $this->assertSame('Recusado na hora de começar.', $this->itemDe($a)->motivo);
        $this->assertSame(self::P, $this->itemDe($b)->status);
        $this->assertSame(1, $this->fila()->rodada_inicios, 'o recusado não gastou vaga');
        $this->assertSame('2026-10-12 10:20', $this->fila()->proximo_em->format('Y-m-d H:i'));
    }

    public function test_tamanho_da_rodada_e_ajustavel_e_validado(): void
    {
        [$a, $b] = $this->cadeiras;
        $this->conferirTodas([$a, $b]);
        $this->agendar([$a], ['ciente' => true, 'produtos_por_rodada' => 11])->assertUnprocessable()
            ->assertJsonPath('errors.produtos_por_rodada.0', 'Cada rodada aceita no máximo 10 produtos.');
        $this->agendar([$a], ['ciente' => true, 'produtos_por_rodada' => 0])->assertUnprocessable()->assertJsonValidationErrors('produtos_por_rodada');
        $this->assertSame(0, PubFilaPublicacao::query()->count());

        $this->agendar([$a], ['ciente' => true, 'produtos_por_rodada' => 3, 'intervalo_minutos' => 15])->assertCreated();
        $this->assertSame([3, 15], [$this->fila()->produtos_por_rodada, $this->fila()->intervalo_minutos]);
        // Agendar de novo na fila viva: a rodada nova passa a valer para ela (o intervalo fica).
        $this->agendar([$b], ['ciente' => true, 'produtos_por_rodada' => 2])->assertCreated();
        $this->assertSame([2, 15], [$this->fila()->produtos_por_rodada, $this->fila()->intervalo_minutos]);

        $this->actingAs($this->admin)->get($this->rotaLote('index'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('config.por_rodada_padrao', 5)->where('config.por_rodada_maximo', 10)
                ->where('config.intervalo_padrao', 20)->where('config.teto_por_minuto', 2)
                ->where('fila.produtos_por_rodada', 2));
    }
}
