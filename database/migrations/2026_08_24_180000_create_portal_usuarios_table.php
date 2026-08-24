<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `portal_usuarios` — as pessoas do lado do CLIENTE que entram no Portal.
 *
 * ### Por que uma tabela nova, e não `users` com uma flag
 * `users` é o time da ECF, e o sistema inteiro trata todo `User` como interno:
 * `role` (admin/consultor/mentor), `publication_role`, o pivot `company_users`
 * como CARTEIRA de quem atende, `EnsureUserHasRole`, os seletores de
 * responsável, os rankings de desempenho. Uma flag `is_cliente` obrigaria a
 * revisar cada uma dessas consultas para excluir clientes — e a que fosse
 * esquecida colocaria um cliente num ranking interno ou num seletor de
 * responsável. Tabela separada torna esse erro impossível por construção.
 *
 * ### O vínculo com empresa é pivot, não coluna
 * Ver `portal_usuario_empresa`. Uma pessoa pode responder por duas empresas do
 * mesmo grupo (o sistema já tem `CompanyGroup`), e `company_id` aqui obrigaria
 * a duplicar a pessoa — com o mesmo e-mail, o que quebra a unicidade que a
 * autenticação precisa.
 *
 * ### `email` é único e é a identidade
 * É por ele que a pessoa entra. Guardado em minúsculas pelo model, para
 * "Joao@" e "joao@" serem a mesma pessoa.
 *
 * Sem coluna de senha, de propósito: o acesso é por código enviado ao e-mail.
 * Senha traria recuperação de senha, política de senha e suporte — tudo que a
 * decisão de produto de 24/08/2026 recusou.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_usuarios', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 120);
            $table->string('email', 190)->unique();
            $table->string('telefone', 30)->nullable();

            // Rótulo livre do papel na empresa ("Financeiro", "Operação"). É
            // informativo: NÃO controla o que a pessoa vê. A decisão de produto
            // de 24/08/2026 foi explícita — todos da mesma empresa veem o mesmo
            // conteúdo. Quando (e se) virar permissão, será outra coluna, com
            // valores fechados.
            $table->string('cargo', 60)->nullable();

            // Desligar sem apagar: preserva o histórico de auditoria da pessoa
            // e permite religar. `false` bloqueia o login imediatamente.
            $table->boolean('ativo')->default(true);

            $table->timestamp('primeiro_acesso_em')->nullable();
            $table->timestamp('ultimo_acesso_em')->nullable();

            // Quem da ECF cadastrou. `nullOnDelete` porque o usuário do portal
            // não pode sumir se o funcionário que o convidou sair da empresa.
            $table->foreignId('convidado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('convidado_em')->nullable();

            $table->timestamps();

            $table->index('ativo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_usuarios');
    }
};
