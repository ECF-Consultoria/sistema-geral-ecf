<?php

namespace Tests\Feature\Phase162;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\MlAnuncioRascunho;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `criativo.kit.status` passa a carregar os campos de validação por slot
 * (Fase 162, Plano 04) — APROV-04/VAL-03. Molde de
 * `tests/Feature/Phase161/CriativoKitGeracaoTest.php` e
 * `tests/Feature/Phase162/AprovacaoComValidacaoTest.php::kitComSlotsProntos`.
 *
 * Disciplina T-161-05/T-162-18: whitelist fechada — nenhuma chave crua de
 * `validacao` (fidelidade, tipo, override, motivo_curto, prompt, truth,
 * contexto, regenerar_motivos) pode vazar para o navegador.
 */
class KitStatusValidacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        Configuracao::set('creative_engine_ativo', '1');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function companyConectada(): Company
    {
        $company = Company::factory()->create(['name' => 'Unity Móveis']);

        MlToken::create([
            'company_id'    => $company->id,
            'ml_user_id'    => '1489433777',
            'access_token'  => 'APP_USR-x',
            'refresh_token' => 'TG-x',
            'expires_at'    => now()->addHours(5),
            'status'        => 'active',
        ]);

        return $company;
    }

    /**
     * Monta um kit com portador (referência viva) + N slots `pronto`, cada
     * um com `validacao_status`/`validacao` dados em `$slotsDef` (índice
     * 0-based na ordem de `slot_indice`). Molde de
     * `AprovacaoComValidacaoTest::kitComSlotsProntos`.
     *
     * @param  array<int, array{status: ?string, validacao?: ?array}>  $slotsDef
     * @return array{0: MlAnuncioCriativoKit, 1: \Illuminate\Support\Collection<int, MlAnuncioCriativo>}
     */
    private function kitComSlots(array $slotsDef): array
    {
        Storage::fake('local');

        $company = $this->companyConectada();
        $userId  = User::factory()->create(['role' => 'admin'])->id;

        $rascunho = MlAnuncioRascunho::create([
            'company_id'  => $company->id,
            'user_id'     => $userId,
            'category_id' => 'MLB1574',
            'payload'     => ['title' => 'Gabinete de cozinha', 'category_id' => 'MLB1574', 'description' => 'x', 'attributes' => [], 'pictures' => []],
            'status'      => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);

        $portadorToken = Str::random(32);
        Storage::disk('local')->put("creative-referencias/{$portadorToken}/0.jpg", 'bytes-da-foto-original');
        $portador = MlAnuncioCriativo::create([
            'token'       => $portadorToken,
            'company_id'  => $company->id,
            'rascunho_id' => $rascunho->id,
            'user_id'     => $userId,
            'slot'        => 'referencia',
            'status'      => MlAnuncioCriativo::STATUS_PRONTO,
            'referencias' => [
                ['indice' => 0, 'nome' => 'foto.jpg', 'path' => "creative-referencias/{$portadorToken}/0.jpg", 'mime' => 'image/jpeg'],
            ],
        ]);

        $kit = MlAnuncioCriativoKit::create([
            'token'                  => Str::random(32),
            'company_id'             => $company->id,
            'rascunho_id'            => $rascunho->id,
            'user_id'                => $userId,
            'criativo_referencia_id' => $portador->id,
            'status'                 => MlAnuncioCriativoKit::STATUS_PRONTO,
            'total_slots'            => count($slotsDef),
            'minimo_aprovadas'       => 3,
            'imagens_geradas'        => count($slotsDef),
        ]);
        $portador->update(['kit_id' => $kit->id]);

        $tipos = ['hero', 'white_background', 'angles', 'detail', 'lifestyle', 'benefits', 'specifications'];
        $slots = collect();
        foreach ($slotsDef as $i => $def) {
            $token = Str::random(32);
            $tipo  = $tipos[$i] ?? "slot-{$i}";
            Storage::disk('local')->put("creative-geradas/{$token}/{$tipo}.jpg", 'bytes-da-imagem-gerada');

            $slots->push(MlAnuncioCriativo::create([
                'token'               => $token,
                'company_id'          => $company->id,
                'rascunho_id'         => $rascunho->id,
                'user_id'             => $userId,
                'kit_id'              => $kit->id,
                'slot'                => $tipo,
                'slot_indice'         => $i + 1,
                'slot_plano'          => ['indice' => $i + 1, 'tipo' => $tipo, 'objetivo' => "Objetivo {$tipo}."],
                'status'              => MlAnuncioCriativo::STATUS_PRONTO,
                'imagem_path'         => "creative-geradas/{$token}/{$tipo}.jpg",
                'imagem_mime'         => 'image/jpeg',
                'validacao_status'    => $def['status'],
                'validacao'           => $def['validacao'] ?? null,
                'validacao_pedida_em' => $def['status'] === MlAnuncioCriativo::VALIDACAO_PENDENTE ? now() : null,
            ]));
        }

        return [$kit->fresh(), $slots];
    }

    // ═══ (a) os 5 campos por slot + 2 do kit para cada estado + NULL ════

    public function test_status_do_kit_traz_os_campos_de_validacao_por_slot_e_do_kit(): void
    {
        [$kit] = $this->kitComSlots([
            ['status' => MlAnuncioCriativo::VALIDACAO_APROVADA, 'validacao' => ['status' => 'aprovada', 'mensagem' => 'Sem problema.', 'problemas' => []]],
            ['status' => MlAnuncioCriativo::VALIDACAO_REPROVADA, 'validacao' => ['status' => 'reprovada', 'mensagem' => 'Risco: produto alterado.', 'problemas' => [['tipo' => 'produto_alterado', 'gravidade' => 'alta', 'explicacao' => 'O produto aparece com três portas; as fotos mostram duas.']]]],
            ['status' => MlAnuncioCriativo::VALIDACAO_PENDENTE],
            ['status' => MlAnuncioCriativo::VALIDACAO_INDISPONIVEL, 'validacao' => ['status' => 'indisponivel', 'mensagem' => 'Não foi possível validar — confira você mesmo.']],
            ['status' => null],
        ]);

        $resposta = $this->actingAs($this->admin())->getJson(
            route('mlb.anuncios.criativo.kit.status', ['kit' => $kit->token]),
        );

        $resposta->assertOk();

        // `prontasSemRisco()` conta pronto + (status fora de pendente/reprovada OU NULL):
        // aprovada + indisponivel + legado (NULL) — só reprovada e pendente ficam de fora.
        $this->assertSame(3, $resposta->json('prontas_sem_risco'));
        $this->assertSame(1, $resposta->json('reprovadas'));

        $slots = collect($resposta->json('slots'))->keyBy('indice');

        // Slot 1 — aprovada: pode aprovar, sem exigir confirmação
        $aprovada = $slots->get(1);
        $this->assertSame(MlAnuncioCriativo::VALIDACAO_APROVADA, $aprovada['validacao_status']);
        $this->assertSame('Sem problema.', $aprovada['validacao_mensagem']);
        $this->assertSame([], $aprovada['validacao_problemas']);
        $this->assertTrue($aprovada['pode_aprovar']);
        $this->assertFalse($aprovada['exige_confirmacao_risco']);

        // Slot 2 — reprovada: NÃO pode aprovar direto, exige confirmação
        $reprovada = $slots->get(2);
        $this->assertSame(MlAnuncioCriativo::VALIDACAO_REPROVADA, $reprovada['validacao_status']);
        $this->assertSame('Risco: produto alterado.', $reprovada['validacao_mensagem']);
        $this->assertSame(
            [['gravidade' => 'alta', 'explicacao' => 'O produto aparece com três portas; as fotos mostram duas.']],
            $reprovada['validacao_problemas'],
        );
        $this->assertFalse($reprovada['pode_aprovar']);
        $this->assertTrue($reprovada['exige_confirmacao_risco']);

        // Slot 3 — pendente: não pode aprovar, não exige confirmação (ainda não reprovou)
        $pendente = $slots->get(3);
        $this->assertSame(MlAnuncioCriativo::VALIDACAO_PENDENTE, $pendente['validacao_status']);
        $this->assertFalse($pendente['pode_aprovar']);
        $this->assertFalse($pendente['exige_confirmacao_risco']);

        // Slot 4 — indisponivel: fail-open, pode aprovar
        $indisponivel = $slots->get(4);
        $this->assertSame(MlAnuncioCriativo::VALIDACAO_INDISPONIVEL, $indisponivel['validacao_status']);
        $this->assertTrue($indisponivel['pode_aprovar']);
        $this->assertFalse($indisponivel['exige_confirmacao_risco']);

        // Slot 5 — NULL (legado): pode aprovar, aparência de hoje
        $legado = $slots->get(5);
        $this->assertNull($legado['validacao_status']);
        $this->assertNull($legado['validacao_mensagem']);
        $this->assertSame([], $legado['validacao_problemas']);
        $this->assertTrue($legado['pode_aprovar']);
        $this->assertFalse($legado['exige_confirmacao_risco']);
    }

    // ═══ (b) whitelist — chaves internas nunca vazam ════════════════════

    public function test_status_do_kit_nunca_vaza_chaves_internas_da_validacao(): void
    {
        [$kit] = $this->kitComSlots([
            ['status' => MlAnuncioCriativo::VALIDACAO_REPROVADA, 'validacao' => [
                'status'       => 'reprovada',
                'fidelidade'   => 'falha',
                'motivo_curto' => 'texto interno que não deve vazar',
                'mensagem'     => 'Risco: produto alterado.',
                'modelo'       => 'gemini-2.5-flash-image',
                'latencia_ms'  => 3200,
                'problemas'    => [['tipo' => 'produto_alterado', 'gravidade' => 'alta', 'explicacao' => 'O produto aparece com três portas; as fotos mostram duas.']],
            ]],
        ]);
        $kit->slots()->first()->update(['regenerar_motivos' => [['origem' => 'automatica', 'motivo' => 'segredo']]]);

        $resposta = $this->actingAs($this->admin())->getJson(
            route('mlb.anuncios.criativo.kit.status', ['kit' => $kit->token]),
        );

        $resposta->assertOk();

        // Asserção sobre o JSON DECODIFICADO (não json_encode, que escapa
        // acento e produz falso positivo) — disciplina do plano.
        $json     = $resposta->json();
        $slotJson = collect($json['slots'])->firstWhere('indice', 1);

        // 'tipo' NÃO entra nesta lista: já existe como campo legítimo e
        // pré-existente do slot (`'tipo' => $slot->slot`, ex. "hero") — a
        // chave proibida com o mesmo nome só é vocabulário interno DENTRO de
        // cada item de `validacao_problemas` (checado abaixo).
        foreach (['fidelidade', 'override', 'motivo_curto', 'prompt', 'truth', 'contexto', 'regenerar_motivos'] as $chaveProibida) {
            $this->assertArrayNotHasKey($chaveProibida, $slotJson, "chave '{$chaveProibida}' não deveria estar no nível do slot");
        }

        // E dentro de cada item de `validacao_problemas`, só gravidade/explicacao
        foreach ($slotJson['validacao_problemas'] as $problema) {
            $this->assertSame(['gravidade', 'explicacao'], array_keys($problema));
        }

        $this->assertSame(['gravidade' => 'alta', 'explicacao' => 'O produto aparece com três portas; as fotos mostram duas.'], $slotJson['validacao_problemas'][0]);
    }

    // ═══ (c) pendente há 11 minutos vira indisponivel já nesta chamada ══

    public function test_slot_pendente_ha_11_minutos_vira_indisponivel_e_pode_aprovar(): void
    {
        [$kit, $slots] = $this->kitComSlots([
            ['status' => MlAnuncioCriativo::VALIDACAO_PENDENTE],
        ]);
        $slots->first()->update(['validacao_pedida_em' => now()->subMinutes(11)]);

        $resposta = $this->actingAs($this->admin())->getJson(
            route('mlb.anuncios.criativo.kit.status', ['kit' => $kit->token]),
        );

        $resposta->assertOk();

        $slotJson = collect($resposta->json('slots'))->firstWhere('indice', 1);
        $this->assertSame(MlAnuncioCriativo::VALIDACAO_INDISPONIVEL, $slotJson['validacao_status']);
        $this->assertTrue($slotJson['pode_aprovar']);
        $this->assertNotEmpty($slotJson['validacao_mensagem']);

        // Efeito persistido no banco, não só na resposta
        $this->assertSame(MlAnuncioCriativo::VALIDACAO_INDISPONIVEL, $slots->first()->fresh()->validacao_status);
    }

    // ═══ (d) escopo de empresa e permissão continuam barrando ═══════════

    /**
     * Rascunho sem `mlb_empresa_id` (caminho dos kits criados pelo helper
     * desta suíte) cai no fallback de `checarEscopoDoRascunho()`: só admin
     * ou o dono (`rascunho->user_id`) passam. `$outroUsuario` não é nenhum
     * dos dois.
     */
    public function test_escopo_de_empresa_continua_barrando_publicador_fora_da_carteira(): void
    {
        [$kit] = $this->kitComSlots([
            ['status' => MlAnuncioCriativo::VALIDACAO_REPROVADA, 'validacao' => ['status' => 'reprovada', 'mensagem' => 'Risco.', 'problemas' => []]],
        ]);

        $outroUsuario = User::factory()->create(['role' => 'consultor']);

        $resposta = $this->actingAs($outroUsuario)->getJson(
            route('mlb.anuncios.criativo.kit.status', ['kit' => $kit->token]),
        );

        $resposta->assertStatus(403);
    }
}
