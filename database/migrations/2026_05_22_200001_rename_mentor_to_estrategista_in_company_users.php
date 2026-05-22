<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renomeia 'mentor' → 'estrategista' na pivot `company_users.role` E expande
 * a coluna pra aceitar o novo valor. O time da ECF passou a chamar o cargo
 * de "Estrategista" — a UI já refletia, agora o dado também muda pra que
 * `Company::estrategista()` consulte direto sem aliasing.
 *
 * MySQL: ENUM('consultor','mentor','analista') vira ENUM('consultor',
 *        'estrategista','analista'). 'mentor' não sobra na lista.
 * SQLite: CHECK constraint da create_table original (limita a consultor/mentor)
 *         é dropada e a coluna fica como string livre — driver de testes só
 *         precisa permitir os valores novos.
 *
 * Não toca em users.role (legacy) — esse é um conceito separado (papel
 * principal do user no sistema). Aqui só o vínculo Empresa↔Pessoa.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // 1º expande pra aceitar os dois (transição). 2º faz o UPDATE.
            // 3º remove 'mentor' do enum.
            DB::statement("ALTER TABLE company_users MODIFY COLUMN role ENUM('consultor', 'mentor', 'estrategista', 'analista') NOT NULL");
            DB::table('company_users')->where('role', 'mentor')->update(['role' => 'estrategista']);
            DB::statement("ALTER TABLE company_users MODIFY COLUMN role ENUM('consultor', 'estrategista', 'analista') NOT NULL");
        } else {
            // SQLite (testes) — pra dropar o CHECK constraint enum precisa
            // recriar a coluna. Ordem: drop unique → backup dados → drop col →
            // recriar col como string → restaurar dados (renomeando mentor) →
            // re-adicionar unique. Em testes via RefreshDatabase a tabela está
            // vazia, então o backup só importa pra ambientes locais não-RD.
            $rows = DB::table('company_users')->get(['id', 'role']);
            Schema::table('company_users', function (Blueprint $t) {
                $t->dropUnique(['company_id', 'user_id', 'role']);
            });
            Schema::table('company_users', function (Blueprint $t) {
                $t->dropColumn('role');
            });
            Schema::table('company_users', function (Blueprint $t) {
                $t->string('role')->after('user_id')->nullable();
            });
            foreach ($rows as $r) {
                $newRole = $r->role === 'mentor' ? 'estrategista' : $r->role;
                DB::table('company_users')->where('id', $r->id)->update(['role' => $newRole]);
            }
            Schema::table('company_users', function (Blueprint $t) {
                $t->unique(['company_id', 'user_id', 'role']);
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE company_users MODIFY COLUMN role ENUM('consultor', 'mentor', 'estrategista', 'analista') NOT NULL");
            DB::table('company_users')->where('role', 'estrategista')->update(['role' => 'mentor']);
            DB::statement("ALTER TABLE company_users MODIFY COLUMN role ENUM('consultor', 'mentor', 'analista') NOT NULL");
        } else {
            DB::table('company_users')->where('role', 'estrategista')->update(['role' => 'mentor']);
        }
    }
};
