<?php

namespace Tests\Feature\Quick261007;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use App\Support\Cnpj;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Quick 261007-m0t — CNPJ repetido deixou de dar 500 e passou a ser aviso.
 *
 * ### O incidente que estes testes travam
 * O Administrativo não conseguia salvar o cadastro em
 * `/administrativo/contratos/empresa/{id}`: o botão "Salvar cadastro" devolvia
 * 500, três vezes seguidas, com `SQLSTATE[23000] ... 1062 Duplicate entry
 * '38.196.897/0001-43' for key 'companies.companies_cnpj_unique'`.
 *
 * ### A decisão que estes testes travam
 * CNPJ repetido é LEGÍTIMO aqui — uma empresa jurídica opera várias lojas de
 * marketplace e cada loja é um registro de `companies`. Então o índice único
 * saiu do banco e no lugar dele entrou um AVISO, que compara por DÍGITOS (e por
 * isso enxerga os 12 pares medidos em produção, em que um registro guarda só
 * dígitos e o outro guarda pontuado — justamente os pares que o unique nunca
 * via).
 *
 * ⛔ O que NÃO pode voltar: validação que recuse o salvar por CNPJ repetido,
 * aviso que desabilite o botão, ou 500 em cima deste formulário.
 *
 * Conferência por RECONSULTA ao banco, nunca pela mensagem de sucesso da tela
 * — mesma disciplina da Fase 131.
 */
class CnpjRepetidoNaoBloqueiaCadastroTest extends TestCase
{
    use RefreshDatabase;

    /** O CNPJ do incidente de produção, nos dois formatos em que ele existe. */
    private const CNPJ_PONTUADO = '38.196.897/0001-43';

    private const CNPJ_DIGITOS = '38196897000143';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();

        // Mesma blindagem do `ContratoAdminDetalheTest` da Fase 131: a
        // reavaliação automática do Observer (Fase 128) roda SÍNCRONA quando
        // Company/ContratoServico são salvos com campos-gatilho alterados —
        // e `cnpj` é campo-gatilho. Sem isto, um PATCH que deixa a empresa
        // completa poderia disparar contrato de verdade como efeito colateral,
        // fora do que estes testes estão medindo.
        config(['services.clicksign.signatarios_ecf' => []]);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function empresa(string $nome, ?string $cnpj): Company
    {
        return Company::factory()->create([
            'name'   => $nome,
            'cnpj'   => $cnpj,
            'active' => true,
        ]);
    }

    private function servicoComContrato(string $nome = 'Gestão de Tráfego (quick 261007-m0t)'): Servico
    {
        return Servico::create([
            'nome'           => $nome,
            'valor_padrao'   => 100,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_PERFORMANCE,
            'exige_contrato' => true,
        ]);
    }

    /** `withoutEvents` para o SETUP não disparar o observer de gatilho. */
    private function vincularServico(Company $c, Servico $s): ContratoServico
    {
        return ContratoServico::withoutEvents(fn () => ContratoServico::create([
            'company_id'            => $c->id,
            'servico_id'            => $s->id,
            'valor_contratado'      => 100,
            'data_contratacao'      => now()->toDateString(),
            'data_primeira_parcela' => now()->addMonth()->toDateString(),
            'dia_vencimento'        => 10,
            'ativo'                 => true,
        ]));
    }

    /** @return array<int, array{id: int, name: string, active: bool}> */
    private function avisoDaFicha(User $admin, Company $empresa): array
    {
        $response = $this->actingAs($admin)->get(route('admin.contratos.show', $empresa));
        $response->assertOk();

        $props = $response->viewData('page')['props'];

        $this->assertArrayHasKey('empresas_mesmo_cnpj', $props,
            'a prop do aviso precisa existir sempre — a tela decide mostrar ou não pelo tamanho da lista.');

        return $props['empresas_mesmo_cnpj'];
    }

    // ─── Caso 0 — o índice único saiu do banco (a trava da migration) ──────
    //
    // É o teste que fica vermelho se alguém recriar o unique: a migration
    // `2026_10_07_120000_remove_unique_do_cnpj_em_companies` existe exatamente
    // para ele não estar lá.

    public function test_companies_cnpj_nao_tem_mais_indice_unico_e_tem_indice_comum(): void
    {
        $this->assertFalse(
            Schema::hasIndex('companies', 'companies_cnpj_unique'),
            'o índice único de companies.cnpj precisa ter saído — CNPJ repetido é legítimo (várias lojas da mesma empresa jurídica).'
        );

        $this->assertTrue(
            Schema::hasIndex('companies', 'companies_cnpj_idx'),
            'cnpj continua sendo coluna de busca; o índice comum entrou no lugar do unique.'
        );
    }

    // ─── Caso 1 — salvar cadastro com CNPJ que outra empresa já tem GRAVA ──
    //
    // O caso literal do incidente: era 500, agora grava.

    public function test_salvar_cadastro_com_cnpj_que_outra_empresa_ja_tem_grava(): void
    {
        $admin = $this->admin();

        $jaExistente = $this->empresa('Prensar ZURCDECOR', self::CNPJ_PONTUADO);
        $alvo        = $this->empresa('Future RCLCOMERCIO', null);

        $response = $this->actingAs($admin)->patch(route('admin.contratos.cadastro', $alvo), [
            'cnpj'         => self::CNPJ_PONTUADO,
            'razao_social' => 'Prensar Comercio LTDA',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');

        // Reconsulta ao banco — gravou de verdade, nos dois lados.
        $this->assertSame(self::CNPJ_PONTUADO, $alvo->fresh()->cnpj);
        $this->assertSame('Prensar Comercio LTDA', $alvo->fresh()->razao_social);
        $this->assertSame(self::CNPJ_PONTUADO, $jaExistente->fresh()->cnpj,
            'a empresa que já tinha o CNPJ não é tocada.');
    }

    // ─── Caso 2 — duas empresas com o MESMO CNPJ convivem no banco ─────────
    //
    // Formatos diferentes de propósito: é assim que os 12 pares de produção
    // estão gravados (um só dígitos, o outro pontuado).

    public function test_duas_empresas_com_o_mesmo_cnpj_em_formatos_diferentes_convivem_no_banco(): void
    {
        $this->empresa('KAITONCOMERCIO', self::CNPJ_DIGITOS);
        $this->empresa('LOJAELASTIM', self::CNPJ_PONTUADO);

        // Reconsulta: as duas linhas existem, cada uma com o seu formato.
        $gravados = Company::query()
            ->whereIn('cnpj', [self::CNPJ_DIGITOS, self::CNPJ_PONTUADO])
            ->orderBy('name')
            ->pluck('cnpj', 'name')
            ->all();

        $this->assertSame([
            'KAITONCOMERCIO' => self::CNPJ_DIGITOS,
            'LOJAELASTIM'    => self::CNPJ_PONTUADO,
        ], $gravados);

        // E uma terceira, com o MESMO formato da primeira, também entra — o
        // que o unique barrava era exatamente isto.
        $terceira = $this->empresa('Terceira Loja', self::CNPJ_DIGITOS);

        $this->assertSame(self::CNPJ_DIGITOS, $terceira->fresh()->cnpj);
        $this->assertSame(2, Company::where('cnpj', self::CNPJ_DIGITOS)->count());
    }

    // ─── Caso 3 — a ficha lista a outra empresa de mesmo CNPJ ──────────────

    public function test_ficha_lista_a_outra_empresa_do_mesmo_cnpj_com_id_e_nome(): void
    {
        $admin = $this->admin();

        $aberta = $this->empresa('MAXIGOLD', self::CNPJ_PONTUADO);
        $outra  = $this->empresa('Nutrifour', self::CNPJ_PONTUADO);
        $this->vincularServico($aberta, $this->servicoComContrato());

        // Uma empresa de CNPJ diferente nunca pode aparecer na lista.
        $this->empresa('Empresa Sem Relacao', '11.222.333/0001-81');

        $aviso = $this->avisoDaFicha($admin, $aberta);

        $this->assertCount(1, $aviso);
        $this->assertSame($outra->id, $aviso[0]['id']);
        $this->assertSame('Nutrifour', $aviso[0]['name']);
        $this->assertTrue($aviso[0]['active']);

        // A própria empresa aberta NUNCA entra na própria lista.
        $this->assertNotContains($aberta->id, array_column($aviso, 'id'));
    }

    // ─── Caso 4 — CNPJ exclusivo: prop VAZIA (nenhum aviso) ────────────────
    //
    // Aviso que aparece quando não há nada a conferir ensina a ignorar o aviso.

    public function test_empresa_com_cnpj_exclusivo_devolve_prop_vazia(): void
    {
        $admin = $this->admin();

        $aberta = $this->empresa('Empresa Unica', '11.222.333/0001-81');
        $this->empresa('Outra Empresa Qualquer', '38.196.897/0001-43');

        $this->assertSame([], $this->avisoDaFicha($admin, $aberta),
            'CNPJ exclusivo → lista vazia → a tela não desenha aviso nenhum.');
    }

    public function test_empresa_sem_cnpj_devolve_prop_vazia_e_nao_junta_as_outras_sem_cnpj(): void
    {
        $admin = $this->admin();

        $aberta = $this->empresa('Empresa Sem CNPJ', null);
        $this->empresa('Outra Tambem Sem CNPJ', null);
        $this->empresa('Terceira Com Cnpj Vazio', '');

        $this->assertSame([], $this->avisoDaFicha($admin, $aberta),
            'CNPJ em branco não é coincidência que mereça aviso — jamais listar "todas as outras sem CNPJ".');
    }

    // ─── Caso 5 — a comparação é por DÍGITOS ───────────────────────────────
    //
    // O coração da correção. O índice único comparava as STRINGS cruas e por
    // isso não via nenhum dos 12 pares de produção. O aviso compara dígitos.

    public function test_comparacao_por_digitos_casa_cnpj_pontuado_com_cnpj_sem_pontuacao(): void
    {
        $admin = $this->admin();

        $aberta = $this->empresa('Utilarshop', self::CNPJ_DIGITOS);
        $outra  = $this->empresa('Ita Prime', self::CNPJ_PONTUADO);

        // As strings cruas são DIFERENTES — é o que deixava o unique passar.
        $this->assertNotSame($aberta->cnpj, $outra->cnpj);
        $this->assertSame(Cnpj::digitos($aberta->cnpj), Cnpj::digitos($outra->cnpj));

        // E ainda assim o aviso enxerga o par, dos dois lados.
        $visaoDaAberta = $this->avisoDaFicha($admin, $aberta);
        $this->assertCount(1, $visaoDaAberta);
        $this->assertSame($outra->id, $visaoDaAberta[0]['id']);

        $visaoDaOutra = $this->avisoDaFicha($admin, $outra);
        $this->assertCount(1, $visaoDaOutra);
        $this->assertSame($aberta->id, $visaoDaOutra[0]['id']);
    }

    // ─── Caso 6 — erro de banco no salvar volta com aviso, SEM 500, e loga ─
    //
    // A restrição do incidente foi removida na raiz, então para provar a rede
    // de segurança este teste RECRIA um índice único em `companies.cnpj` em
    // tempo de execução — reproduzindo literalmente a violação 23000 que
    // derrubava o formulário — e confere que agora ela vira aviso.
    //
    // Serve para QUALQUER restrição de banco que apareça aqui no futuro: o
    // ponto do teste é o caminho (QueryException → log + flash), não esta
    // restrição em especial.

    public function test_erro_de_banco_ao_salvar_volta_com_aviso_sem_500_e_registra_no_log(): void
    {
        $admin = $this->admin();

        $jaExistente = $this->empresa('Empresa Que Ja Tem O Cnpj', self::CNPJ_PONTUADO);
        $alvo        = $this->empresa('Empresa Alvo Do Salvar', null);

        // A restrição "nova": mesmo tipo de índice que causou o incidente.
        DB::statement('CREATE UNIQUE INDEX quick261007_cnpj_unique ON companies (cnpj)');

        Log::spy();

        $response = $this->actingAs($admin)->patch(route('admin.contratos.cadastro', $alvo), [
            'cnpj'         => self::CNPJ_PONTUADO,
            'razao_social' => 'Empresa Alvo LTDA',
        ]);

        // ⛔ O que não pode acontecer: 500 em cima do formulário.
        $response->assertRedirect();
        $response->assertSessionHas('error');
        $response->assertSessionMissing('success');

        // A mensagem da tela não vaza SQL nem o jargão do banco.
        $aviso = session('error');
        $this->assertIsString($aviso);
        foreach (['SQLSTATE', 'Duplicate entry', 'unique', 'update `companies`'] as $jargao) {
            $this->assertStringNotContainsStringIgnoringCase($jargao, $aviso,
                'a tela recebe copy sem jargão; o texto do banco vai só para o log.');
        }

        // Reconsulta ao banco — a gravação de fato não aconteceu.
        $this->assertNull($alvo->fresh()->cnpj);
        $this->assertSame(self::CNPJ_PONTUADO, $jaExistente->fresh()->cnpj);

        // E o erro ficou registrado, com empresa e usuário para rastrear.
        Log::shouldHaveReceived('error')
            ->withArgs(function ($message, $context = []) use ($alvo, $admin) {
                return is_string($message)
                    && str_contains($message, 'cadastro')
                    && ($context['company_id'] ?? null) === $alvo->id
                    && ($context['user_id'] ?? null) === $admin->id
                    && filled($context['erro'] ?? null);
            })
            ->atLeast()->once();

        DB::statement('DROP INDEX quick261007_cnpj_unique');
    }

    // ─── Caso 7 — os guards de IDOR seguem devolvendo 422 ──────────────────
    //
    // O try/catch do caso 6 pega SÓ `QueryException`. Os dois `abort(422)` de
    // pertencimento rodam ANTES dele e não podem ter sido engolidos.

    public function test_contrato_servico_de_outra_empresa_segue_devolvendo_422_e_nao_grava_nada(): void
    {
        $admin = $this->admin();

        $alvo    = $this->empresa('Empresa Alvo IDOR m0t', null);
        $servico = $this->servicoComContrato();

        $outra                 = $this->empresa('Empresa Outra IDOR m0t', null);
        $contratoDeOutraEmpresa = $this->vincularServico($outra, $servico);

        $response = $this->actingAs($admin)->patch(route('admin.contratos.cadastro', $alvo), [
            'cnpj'              => self::CNPJ_PONTUADO,
            'contratos_servico' => [
                ['id' => $contratoDeOutraEmpresa->id, 'data_contratacao' => '2026-02-01'],
            ],
        ]);

        $response->assertStatus(422);

        // Reconsulta ao banco — nada gravou, nem a empresa alvo nem o contrato
        // de outra empresa (o guard roda antes de qualquer escrita).
        $this->assertNull($alvo->fresh()->cnpj);
        $this->assertNotSame(
            '2026-02-01',
            optional($contratoDeOutraEmpresa->fresh()->data_contratacao)->format('Y-m-d'),
        );
    }
}
