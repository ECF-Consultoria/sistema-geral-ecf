<?php

use App\Models\Servico;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 157 (D-B) — cria o setor **Performance** em `setores`.
 *
 * Por que não existia: `servicos.setor` é uma string própria do catálogo de
 * serviços ('performance', 'publicacao', 'polos', 'shopee', 'outros') e nasceu
 * sem contraparte em `setores`, que é a tabela de setores DA EMPRESA (com
 * cargos, membros e líderes). Os dois vocabulários casam por `slug` para
 * 'publicacao', 'polos' e 'shopee' — mas 'performance' e 'outros' nunca tiveram
 * linha.
 *
 * Consequência medida na Fase 154: 'performance' é o setor de MAIS empresas
 * (9 no banco local, contra 6 de polos e 2 de publicacao), e o seletor de
 * analista/estrategista caía sempre no fallback "mostrando todos os
 * habilitados" justamente para elas. Com esta linha, o filtro por setor passa
 * a funcionar de verdade para Gestão e Mentoria.
 *
 * Idempotente: `setores.slug` e `setores.nome` são UNIQUE, então a checagem é
 * por slug antes de inserir. Rodar duas vezes não duplica.
 */
return new class extends Migration
{
    private const SLUG = 'performance';

    public function up(): void
    {
        if (DB::table('setores')->where('slug', self::SLUG)->exists()) {
            return;
        }

        // `nome` também é UNIQUE — se já existir um setor com este nome e outro
        // slug, não inventar um segundo: quem resolve é gente, não migration.
        if (DB::table('setores')->where('nome', 'Performance')->exists()) {
            return;
        }

        DB::table('setores')->insert([
            'nome'       => 'Performance',
            'slug'       => self::SLUG,
            'descricao'  => 'Setor das empresas de Gestão e Mentoria (servicos.setor = '
                . Servico::SETOR_PERFORMANCE . '). O líder deste setor distribui analista e '
                . 'estrategista pela aba Distribuição de /companies (Fase 157).',
            'active'     => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * `down()` só remove o setor se ele estiver VAZIO — sem cargo, sem membro,
     * sem líder. Derrubar um setor que já tem gente dentro apagaria vínculo de
     * trabalho por causa de um rollback de migration.
     */
    public function down(): void
    {
        $id = DB::table('setores')->where('slug', self::SLUG)->value('id');

        if ($id === null) {
            return;
        }

        $temGente = DB::table('cargos')->where('setor_id', $id)->exists()
            || DB::table('user_setores')->where('setor_id', $id)->exists()
            || DB::table('setor_lideres')->where('setor_id', $id)->exists();

        if ($temGente) {
            return;
        }

        DB::table('setores')->where('id', $id)->delete();
    }
};
