<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed de dados para o fluxo Comercial — Wave 2 da Phase 13.
 *
 * ETAPA 1: Cria o setor 'Comercial' na tabela setores (idempotente).
 * ETAPA 2: Vincula a permission_key 'comercial.cadastrar_empresa' ao setor (idempotente).
 * ETAPA 3: Migração retroativa — cria um registro em companies para cada mlb_empresa
 *           ainda sem company_id e preenche a FK (idempotente via whereNull).
 *
 * Idempotente — re-rodar não cria dados duplicados.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── ETAPA 1: Setor Comercial ───────────────────────────────────────

        $setorId = DB::table('setores')
            ->where('slug', 'comercial')
            ->value('id');

        if (! $setorId) {
            $setorId = DB::table('setores')->insertGetId([
                'nome'       => 'Comercial',
                'slug'       => 'comercial',
                'descricao'  => 'Fluxo centralizado de cadastro de novas empresas',
                'active'     => true,
                'is_system'  => false,
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // ─── ETAPA 2: Permission key no setor Comercial ─────────────────────

        $permissaoExiste = DB::table('setor_permissoes')
            ->where('setor_id', $setorId)
            ->where('permission_key', 'comercial.cadastrar_empresa')
            ->exists();

        if (! $permissaoExiste) {
            DB::table('setor_permissoes')->insert([
                'setor_id'       => $setorId,
                'permission_key' => 'comercial.cadastrar_empresa',
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }

        // ─── ETAPA 3: Migração retroativa de mlb_empresas sem company_id ────

        // whereNull('company_id') garante idempotência: mlb_empresas já processadas são ignoradas
        DB::table('mlb_empresas')
            ->whereNull('company_id')
            ->orderBy('id')
            ->get()
            ->each(function ($empresa) {
                $serviceType = $this->derivarServiceType($empresa);

                $companyId = DB::table('companies')->insertGetId([
                    'name'         => $empresa->nome,
                    'service_type' => $serviceType,
                    'status'       => 'ativo',
                    'active'       => true,
                    'created_at'   => $empresa->created_at,
                    'updated_at'   => now(),
                ]);

                DB::table('mlb_empresas')
                    ->where('id', $empresa->id)
                    ->update(['company_id' => $companyId]);
            });
    }

    public function down(): void
    {
        // Remove apenas os dados de setor/permissão inseridos por esta migration.
        // NÃO desfaz a migração retroativa de companies — operação destrutiva irreversível.
        DB::table('setor_permissoes')
            ->where('permission_key', 'comercial.cadastrar_empresa')
            ->delete();

        DB::table('setores')
            ->where('slug', 'comercial')
            ->delete();
    }

    /**
     * Deriva o service_type da company a partir dos campos tipo e projeto da mlb_empresa.
     *
     * Regras (D-15 do CONTEXT.md):
     *   POLO + projeto=POLOS|NULL|''  → 'polos'
     *   POLO + projeto contém 'ssessoria' (case-insensitive)  → 'assessoria'
     *   ASSESSORIA  → 'assessoria'
     *   contém 'ncubadora' (case-insensitive)  → 'incubadora'
     *   fallback conservador  → 'polos'
     */
    private function derivarServiceType(object $empresa): string
    {
        $tipo    = $empresa->tipo    ?? '';
        $projeto = $empresa->projeto ?? '';

        // ASSESSORIA direto → assessoria
        if (stripos($tipo, 'ASSESSORIA') !== false) {
            return 'assessoria';
        }

        // Incubadora → incubadora
        if (stripos($tipo, 'ncubadora') !== false) {
            return 'incubadora';
        }

        if (strtoupper($tipo) === 'POLO') {
            // POLO + projeto contém 'ssessoria' → assessoria
            if (! empty($projeto) && stripos($projeto, 'ssessoria') !== false) {
                return 'assessoria';
            }

            // POLO + projeto=POLOS|NULL|'' → polos
            if (empty($projeto) || strtoupper($projeto) === 'POLOS') {
                return 'polos';
            }
        }

        // Fallback conservador
        return 'polos';
    }
};
