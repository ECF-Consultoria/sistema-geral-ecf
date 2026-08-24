<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `portal_usuario_empresa` — quais empresas cada pessoa do Portal pode ver.
 *
 * **Esta tabela É a autorização.** Autenticar responde "quem é você"; esta
 * linha responde "o que você pode ver". Nenhuma rota do Portal deve derivar
 * empresa de outro lugar — nem de parâmetro de URL, nem de campo de formulário,
 * nem de sessão preenchida pelo cliente. Sem linha aqui, não há acesso.
 *
 * ### Por que pivot, e não `company_id` no usuário
 * Uma pessoa pode responder por mais de uma empresa do mesmo grupo — o sistema
 * já modela isso em `CompanyGroup`. Com coluna única seria preciso duplicar a
 * pessoa, e duas linhas com o mesmo e-mail quebrariam a identidade que a
 * autenticação usa.
 *
 * ### `cascadeOnDelete` nos dois lados
 * Apagar o usuário ou a empresa apaga o vínculo. É o comportamento certo para
 * uma tabela de autorização: vínculo órfão aqui seria acesso a uma empresa que
 * não existe mais, ou de uma pessoa que já foi removida.
 *
 * O par é único: a mesma pessoa não pode ter dois vínculos com a mesma empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_usuario_empresa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portal_usuario_id')->constrained('portal_usuarios')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // Quando a pessoa tem mais de uma empresa, é esta que abre por
            // padrão. Sem isso ela cairia num seletor toda vez, mesmo quando
            // 99% dos acessos são à mesma empresa.
            $table->boolean('principal')->default(false);

            $table->timestamps();

            $table->unique(['portal_usuario_id', 'company_id'], 'portal_usuario_empresa_unico');
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_usuario_empresa');
    }
};
