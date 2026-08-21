<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `companies.logo_url` — a marca do cliente, exibida no topo do Portal do
 * Cliente (`/portal-cliente/{token}`) no lugar onde antes ficava fixo "ECF
 * Consultoria".
 *
 * ### Por que uma coluna nova, e não reaproveitar algo existente
 * Não havia NENHUMA logo de empresa no sistema antes desta migration —
 * varredura em `INFORMATION_SCHEMA` por `%logo%`, `%avatar%`, `%imagem%`,
 * `%thumbnail%` e `%foto%` devolveu só `users.avatar_url`,
 * `sugadores.thumbnail`, `mlb_publicacoes.thumbnail_url` e
 * `ml_acervo_itens.thumbnail` — todos de PESSOA ou de ANÚNCIO, nenhum de
 * empresa. O thumbnail do vendedor no Mercado Livre foi descartado como fonte:
 * só existe para empresa com conta ML conectada, vem pequeno e redondo, e com
 * frequência é uma foto pessoal e não a marca.
 *
 * ### Por que `logo_url` (URL) e não `logo_path` (caminho)
 * Espelha `users.avatar_url`, que já resolve o mesmo problema no sistema há
 * tempo: o controller grava `Storage::url($path)` e a tela consome direto,
 * sem `asset()` nem concatenação espalhada pelo JSX. O efeito colateral bom é
 * aceitar uma URL EXTERNA no futuro (CDN da marca do cliente) sem migration
 * nova — o `apagarLogoLocal()` do controller só apaga arquivo quando a URL
 * começa com `/storage/`, exatamente como o avatar faz.
 *
 * 500 caracteres pelo mesmo motivo de `app_ecf_link`: URL de storage é curta,
 * mas URL externa assinada não é.
 *
 * Coluna nova e nullable: `null` = empresa sem logo, e o Portal cai no
 * monograma com as iniciais. Nada a preencher retroativamente; `down()` só
 * remove a coluna (os arquivos em `storage/app/public/logos` ficam órfãos de
 * propósito — apagar imagem de cliente num rollback de schema seria perda
 * irreversível por uma operação que se espera reversível).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('logo_url', 500)->nullable()->after('app_ecf_link');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('logo_url');
        });
    }
};
