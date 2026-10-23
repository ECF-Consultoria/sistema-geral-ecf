<?php

namespace Tests\Feature\Publicador;

use App\Models\MlAnuncioCriativoKit;
use App\Models\PubRascunho;
use App\Services\Publicador\Criativos\CriativosAutomaticosService;
use App\Services\Publicador\PreparoIaAgenda;
use App\Services\Publicador\PreparoIaDoRascunhoService;
use App\Support\Publicador\IaTrabalhando;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem as R;
use App\Support\Publicador\MemoriaDoPreparoIa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Publicador\Lote\CenarioDoPreparo;
use Tests\TestCase;

/**
 * "A IA está atualizando este anúncio?" (20/10/2026, o usuário: "se estiver em pendência e a IA estiver trabalhando,
 * sinalizar que está atualizando"). Os três momentos, os prazos que impedem o "Atualizando…" eterno e o carimbo que só
 * a automação muda — é ele que manda o editor aberto reler.
 */
class IaTrabalhandoTest extends TestCase
{
    use CenarioDoPreparo;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarPreparo();
    }

    private function comEstado(PubRascunho $r, callable $mudar): PubRascunho
    {
        $estado = json_decode((string) DB::table('pub_rascunhos')->where('id', $r->id)->value('step_state'), true) ?: [];
        DB::table('pub_rascunhos')->where('id', $r->id)->update(['step_state' => json_encode($mudar($estado))]);

        return $r->fresh();
    }

    public function test_agendada_do_save_no_portal_ate_a_espera_passar(): void
    {
        $p = $this->produtoDoPortal();

        app(PreparoIaAgenda::class)->aoSalvar((int) $this->empresa->id, [$p->id]);

        $this->assertSame(IaTrabalhando::AGENDADA, IaTrabalhando::no(null, (int) $p->id));
        Cache::forget(PreparoIaAgenda::chaveDoAgendado((int) $p->id));
        $this->assertNull(IaTrabalhando::no(null, (int) $p->id));
    }

    public function test_escrevendo_so_com_etapa_rodando_recente(): void
    {
        $p = $this->produtoDoPortal();
        $this->salvarERodar($p);
        $r = $this->rascunhoDo($p);

        $r = $this->comEstado($r, function (array $e) {
            $e[MemoriaDoPreparoIa::PREPARO]['etapas']['titulo'] = ['status' => PreparoIaDoRascunhoService::RODANDO, 'em' => now()->toIso8601String()];

            return $e;
        });
        $this->assertSame(IaTrabalhando::ESCREVENDO, IaTrabalhando::no($r));

        // Um Job que morreu no meio não deixa o anúncio "Atualizando…" para sempre.
        $r = $this->comEstado($r, function (array $e) {
            $e[MemoriaDoPreparoIa::PREPARO]['etapas']['titulo']['em'] = now()->subMinutes(20)->toIso8601String();

            return $e;
        });
        $this->assertNull(IaTrabalhando::no($r));
    }

    public function test_imagens_enquanto_o_kit_automatico_nao_entrou(): void
    {
        $p = $this->produtoDoPortal();
        $this->salvarERodar($p);
        $r = $this->rascunhoDo($p);
        $kit = MlAnuncioCriativoKit::create(['token' => Str::random(32), 'company_id' => $this->empresa->id, 'pub_rascunho_id' => $r->id,
            'pub_grupo' => R::GERAL, 'status' => MlAnuncioCriativoKit::STATUS_GERANDO]);

        $r = $this->comEstado($r, function (array $e) use ($kit) {
            $e[CriativosAutomaticosService::MEMORIA]['grupos'][R::GERAL] = ['hash' => 'x', 'kit_id' => $kit->id];

            return $e;
        });
        $this->assertSame(IaTrabalhando::IMAGENS, IaTrabalhando::no($r));

        $kit->update(['status' => MlAnuncioCriativoKit::STATUS_ERRO]);
        $this->assertNull(IaTrabalhando::no($r), 'kit que deu erro não fica "Atualizando…"');

        $kit->update(['status' => MlAnuncioCriativoKit::STATUS_PRONTO]);
        $this->assertSame(IaTrabalhando::IMAGENS, IaTrabalhando::no($r), 'pronto esperando o juiz ainda vai entrar');
        $r = $this->comEstado($r, function (array $e) {
            $e[CriativosAutomaticosService::MEMORIA]['grupos'][R::GERAL]['aplicado_em'] = now()->toIso8601String();

            return $e;
        });
        $this->assertNull(IaTrabalhando::no($r), 'as imagens já entraram');
    }

    public function test_o_carimbo_muda_so_com_o_que_a_automacao_grava(): void
    {
        $p = $this->produtoDoPortal();
        $this->salvarERodar($p);
        $r = $this->rascunhoDo($p);
        $antes = IaTrabalhando::carimbo($r);

        $r->update(['descricao' => 'A equipe editou.']);
        $this->assertSame($antes, IaTrabalhando::carimbo($r->fresh()), 'o salvamento de quem edita não muda o carimbo');

        $r = $this->comEstado($r, function (array $e) {
            // Uma escrita NOVA da IA (o cenário já rodou a IA uma vez, neste mesmo segundo).
            $e[MemoriaDoPreparoIa::PREPARO]['etapas']['descricao'] = ['status' => 'ok', 'em' => now()->addMinute()->toIso8601String()];

            return $e;
        });
        $depoisDaIa = IaTrabalhando::carimbo($r);
        $this->assertNotSame($antes, $depoisDaIa, 'a IA escreveu');

        $r = $this->comEstado($r, function (array $e) {
            $e[CriativosAutomaticosService::MEMORIA]['grupos'][R::GERAL] = ['kit_id' => 9, 'aplicado_em' => now()->toIso8601String()];

            return $e;
        });
        $this->assertNotSame($depoisDaIa, IaTrabalhando::carimbo($r), 'as imagens entraram');
    }
}
