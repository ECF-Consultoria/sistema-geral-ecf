<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Seed do Setor organizacional "Shopee" (RBAC) — Phase 77, Wave 1.
 *
 * Eixo DISTINTO do `servico.setor='shopee'`: aqui é a tabela `setores`
 * (setor organizacional / RBAC), não o catálogo de serviços.
 *
 * ETAPA 1 (DEC-77-1): cria o setor 'Shopee' (slug 'shopee', active, is_system=false).
 * ETAPA 2 (DEC-77-2): cria os cargos 'analista' e 'estrategista' escopados ao setor.
 * ETAPA 3 (DEC-77-3): vincula SOMENTE a permission 'shopee.empresas' ao setor.
 * ETAPA 4 (DEC-77-4): wiring por email (idempotente, pula+loga se ausente):
 *   - Felipe (consultor.02@)  = estrategista no Shopee + líder do Shopee.
 *   - Gustavo (suporte.11@)   = analista no Shopee (+ analista no Performance se existir).
 *
 * Idempotente (re-rodar não duplica) e cross-driver (roda em SQLite dos testes).
 */
return new class extends Migration
{
    /** Email canônico do Felipe (estrategista + líder Shopee). */
    private const EMAIL_FELIPE = 'consultor.02@ecfconsultoria.com.br';

    /** Email canônico do Gustavo (analista Shopee + analista Performance). */
    private const EMAIL_GUSTAVO = 'suporte.11@ecfconsultoria.com.br';

    public function up(): void
    {
        // ─── ETAPA 1: Setor Shopee (RBAC) ───────────────────────────────────

        $setorId = DB::table('setores')
            ->where('slug', 'shopee')
            ->value('id');

        if (! $setorId) {
            $setorId = DB::table('setores')->insertGetId([
                'nome'       => 'Shopee',
                'slug'       => 'shopee',
                'descricao'  => 'Setor organizacional Shopee — gestão de ADS e NPS',
                'active'     => true,
                'is_system'  => false,
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // ─── ETAPA 2: Cargos analista/estrategista (unique = setor_id+slug) ──
        // Os slugs 'analista'/'estrategista' também existem no Performance;
        // duplicar é esperado e válido — o unique escopa por setor_id.

        $cargos = [
            // slug          => [nome, ordem (senioridade)]
            'estrategista' => ['Estrategista', 1],
            'analista'     => ['Analista', 0],
        ];

        $cargoIds = [];

        foreach ($cargos as $slug => [$nome, $ordem]) {
            $cargoId = DB::table('cargos')
                ->where('setor_id', $setorId)
                ->where('slug', $slug)
                ->value('id');

            if (! $cargoId) {
                $cargoId = DB::table('cargos')->insertGetId([
                    'setor_id'         => $setorId,
                    'nome'             => $nome,
                    'slug'             => $slug,
                    'meta_publicacoes' => null,
                    'ordem'            => $ordem,
                    'active'           => true,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }

            $cargoIds[$slug] = $cargoId;
        }

        $cargoEstrategistaId = $cargoIds['estrategista'];
        $cargoAnalistaId     = $cargoIds['analista'];

        // ─── ETAPA 3: Permissão do setor (SOMENTE shopee.empresas) ──────────

        $permissaoExiste = DB::table('setor_permissoes')
            ->where('setor_id', $setorId)
            ->where('permission_key', \App\Support\Permissions::SHOPEE_EMPRESAS)
            ->exists();

        if (! $permissaoExiste) {
            DB::table('setor_permissoes')->insert([
                'setor_id'       => $setorId,
                'permission_key' => \App\Support\Permissions::SHOPEE_EMPRESAS,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }

        // ─── ETAPA 4: Wiring por email (idempotente, skip+log se ausente) ───

        // Resolve o user_id pelo email; null => loga e pula o wiring.
        $resolverUserId = function (string $email): ?int {
            $id = DB::table('users')->where('email', $email)->value('id');

            if (! $id) {
                Log::warning('[SetorShopee] usuário ausente, wiring pulado', ['email' => $email]);
                return null;
            }

            return (int) $id;
        };

        // Garante uma linha user_setores idempotente por par (user_id, setor_id).
        // Retorna true se a linha já existia (para não sobrescrever is_principal).
        $garantirUserSetor = function (int $userId, int $alvoSetorId, ?int $cargoId, bool $marcarPrincipal): void {
            $existe = DB::table('user_setores')
                ->where('user_id', $userId)
                ->where('setor_id', $alvoSetorId)
                ->exists();

            if ($existe) {
                // Idempotente: mantém a linha; só realinha o cargo (não mexe em is_principal).
                DB::table('user_setores')
                    ->where('user_id', $userId)
                    ->where('setor_id', $alvoSetorId)
                    ->update([
                        'cargo_id'   => $cargoId,
                        'updated_at' => now(),
                    ]);
                return;
            }

            DB::table('user_setores')->insert([
                'user_id'      => $userId,
                'setor_id'     => $alvoSetorId,
                'cargo_id'     => $cargoId,
                'is_principal' => $marcarPrincipal,
                'assigned_at'  => now(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        };

        // True se o user já tem ALGUM setor marcado como principal.
        $temPrincipal = function (int $userId): bool {
            return DB::table('user_setores')
                ->where('user_id', $userId)
                ->where('is_principal', true)
                ->exists();
        };

        // ─── Felipe: estrategista + líder do Shopee ─────────────────────────
        $felipeId = $resolverUserId(self::EMAIL_FELIPE);

        if ($felipeId !== null) {
            // W1 — evitar 2 principais: só marca Shopee como principal se Felipe
            // ainda NÃO tiver nenhum setor principal (não rouba o principal existente).
            $felipePrincipal = ! $temPrincipal($felipeId);

            $garantirUserSetor($felipeId, $setorId, $cargoEstrategistaId, $felipePrincipal);

            // Liderança = membership em setor_lideres (dá AUTO_LIDERANCA). Idempotente.
            $lideraShopee = DB::table('setor_lideres')
                ->where('setor_id', $setorId)
                ->where('user_id', $felipeId)
                ->exists();

            if (! $lideraShopee) {
                DB::table('setor_lideres')->insert([
                    'setor_id'    => $setorId,
                    'user_id'     => $felipeId,
                    'assigned_by' => null,
                    'assigned_at' => now(),
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
        }

        // ─── Gustavo: analista Shopee (+ analista Performance se existir) ────
        $gustavoId = $resolverUserId(self::EMAIL_GUSTAVO);

        if ($gustavoId !== null) {
            // is_principal (Claude's Discretion): se ele já tem principal, não mexe;
            // se é novo e o Performance existir, o Performance vira o principal.
            $gustavoJaTemPrincipal = $temPrincipal($gustavoId);

            // Descobre setor/cargo Performance ANTES de decidir o principal.
            $setorPerformanceId = DB::table('setores')
                ->where('slug', 'performance')
                ->value('id');

            $cargoAnalistaPerformanceId = null;
            if ($setorPerformanceId) {
                $cargoAnalistaPerformanceId = DB::table('cargos')
                    ->where('setor_id', $setorPerformanceId)
                    ->where('slug', 'analista')
                    ->value('id');
            }

            $performanceDisponivel = $setorPerformanceId && $cargoAnalistaPerformanceId;

            // Shopee do Gustavo nunca é principal (DEC-77-4: is_principal=false).
            $garantirUserSetor($gustavoId, $setorId, $cargoAnalistaId, false);

            // Só cria a linha Performance se setor+cargo analista Performance existirem.
            if ($performanceDisponivel) {
                // Performance vira principal apenas se Gustavo ainda não tinha um.
                $performancePrincipal = ! $gustavoJaTemPrincipal;

                $garantirUserSetor(
                    $gustavoId,
                    (int) $setorPerformanceId,
                    (int) $cargoAnalistaPerformanceId,
                    $performancePrincipal
                );
            }
        }
    }

    public function down(): void
    {
        // Reversível SEM destruir dados pré-existentes de OUTROS setores.
        // Só remove o que a migration criou: o setor 'shopee' e seus filhos.

        $setorId = DB::table('setores')
            ->where('slug', 'shopee')
            ->value('id');

        if (! $setorId) {
            return;
        }

        // W3 — defense-in-depth: deleta explicitamente os filhos do Shopee ANTES
        // do setor (não depende só do cascade; imune a FK desligada no SQLite).
        // Todos escopados a setor_id=Shopee — NUNCA tocam o setor Performance.
        // Nota: não dá pra distinguir a membership Performance pré-existente de
        // Gustavo do que a migration inseriu, então preservamos tudo do Performance
        // (comportamento seguro — não apagar dado que pode ser pré-existente).
        DB::table('setor_lideres')->where('setor_id', $setorId)->delete();
        DB::table('user_setores')->where('setor_id', $setorId)->delete();
        DB::table('setor_permissoes')->where('setor_id', $setorId)->delete();
        DB::table('cargos')->where('setor_id', $setorId)->delete();
        DB::table('setores')->where('id', $setorId)->delete();
    }
};
