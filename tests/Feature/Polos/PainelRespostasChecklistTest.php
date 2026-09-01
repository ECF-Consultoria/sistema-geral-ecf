<?php

// As duas perguntas de perfil do checklist público ("Como são os produtos..." e "Já vende em
// outros canais...") passam a aparecer no Painel Polos para ver e FILTRAR.
//
// O detalhe que separa estas colunas de todas as outras: as demais são colunas de
// mlb_implementacoes e salvam por BLOCO_DE (PATCH parcial); estas moram no JSON da ficha
// (dados.itens.<id>.valor) e são SÓ LEITURA no Painel — quem responde é o cliente.

namespace Tests\Feature\Polos;

use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PainelRespostasChecklistTest extends TestCase
{
    use RefreshDatabase;

    private const PERFIL_GRANDE = 'Produtos grandes, volumosos, multivolumes e/ou com mais de 50 kg';

    private function empresaComFicha(array $dados = null): MlbImplementacao
    {
        $empresa = MlbEmpresa::create([
            'nome'    => 'Loja Resposta ' . Str::random(4),
            'tipo'    => 'POLO',
            'projeto' => 'POLOS',
            'fase'    => 'M2',
            'polo'    => 'Arapongas',
        ]);

        return MlbImplementacao::create([
            'empresa_id' => $empresa->id,
            'token'      => Str::random(48),
            'dados'      => $dados,
        ]);
    }

    // ─── respostaChecklist() ─────────────────────────────────────────────────

    public function test_resposta_checklist_le_o_valor_do_json(): void
    {
        $dados = MlbImplementacao::dadosPadrao();
        $dados['itens']['produtos_perfil']['valor']    = self::PERFIL_GRANDE;
        $dados['itens']['canais_faturamento']['valor'] = 'De 100 a 500k';

        $impl = $this->empresaComFicha($dados);

        $this->assertSame(self::PERFIL_GRANDE, $impl->respostaChecklist('produtos_perfil'));
        $this->assertSame('De 100 a 500k', $impl->respostaChecklist('canais_faturamento'));
    }

    public function test_resposta_checklist_devolve_null_para_nao_respondido(): void
    {
        // Sem resposta, sem a chave e sem dados: os três casos dão null, e é por isso que o
        // accessor pode ler direto do JSON sem passar pelo merge (que é caro em ~500 linhas).
        $semResposta = $this->empresaComFicha(MlbImplementacao::dadosPadrao());
        $this->assertNull($semResposta->respostaChecklist('produtos_perfil'));

        $semChave = MlbImplementacao::dadosPadrao();
        unset($semChave['itens']['produtos_perfil']);
        $this->assertNull($this->empresaComFicha($semChave)->respostaChecklist('produtos_perfil'));

        $this->assertNull($this->empresaComFicha(null)->respostaChecklist('produtos_perfil'));
    }

    public function test_resposta_checklist_ignora_a_sentinela_de_nao_escolhido(): void
    {
        // '---' é "não escolhido" nos selects; exibir isso na grade seria pior que vazio.
        $dados = MlbImplementacao::dadosPadrao();
        $dados['itens']['hub']['valor'] = '---';

        $this->assertNull($this->empresaComFicha($dados)->respostaChecklist('hub'));
    }

    // ─── Payload do Painel ───────────────────────────────────────────────────

    public function test_painel_expoe_as_duas_respostas_no_payload(): void
    {
        $dados = MlbImplementacao::dadosPadrao();
        $dados['itens']['produtos_perfil']['valor']    = self::PERFIL_GRANDE;
        $dados['itens']['canais_faturamento']['valor'] = 'Acima de 500k';
        $this->empresaComFicha($dados);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('mlb.polos-painel'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('empresas.0.produtos_perfil', self::PERFIL_GRANDE)
                ->where('empresas.0.canais_faturamento', 'Acima de 500k')
            );
    }

    public function test_painel_manda_null_quando_a_empresa_nao_tem_ficha(): void
    {
        // Empresa sem ficha: a célula fica vazia, que é diferente de "respondeu nada".
        MlbEmpresa::create([
            'nome' => 'Loja Sem Ficha', 'tipo' => 'POLO', 'projeto' => 'POLOS',
            'fase' => 'M2', 'polo' => 'Arapongas',
        ]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('mlb.polos-painel'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('empresas.0.produtos_perfil', null)
                ->where('empresas.0.canais_faturamento', null)
            );
    }

    public function test_resposta_do_cliente_pelo_link_chega_ao_painel(): void
    {
        // Ponta a ponta: o cliente responde no link público e a equipe vê no Painel.
        $impl = $this->empresaComFicha(MlbImplementacao::dadosPadrao());

        $this->patch(route('implementacao.salvar', $impl->token), [
            'id'    => 'produtos_perfil',
            'campo' => 'valor',
            'valor' => self::PERFIL_GRANDE,
        ])->assertOk();

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('mlb.polos-painel'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('empresas.0.produtos_perfil', self::PERFIL_GRANDE));
    }
}
