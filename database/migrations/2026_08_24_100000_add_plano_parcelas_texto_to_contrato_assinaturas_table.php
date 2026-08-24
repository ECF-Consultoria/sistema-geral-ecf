<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// pt-BR: Migration ADITIVA — Quick 260824-bte, Tarefa 3a.
//
// Acrescenta `plano_parcelas_texto` (nullable) a `contrato_assinaturas`: o
// override manual da frase de pagamento escalonado que preenche a variável
// {{plano_parcelas}} do modelo `.docx` da Clicksign (ver
// ContratoVariaveisModeloService/ContratoPdfService::montarDados()).
// `null` = usar o texto composto a partir das fases do `servicos_snapshot`
// congelado; preenchido = usar literalmente o que a pessoa escreveu na tela
// (ContratoAdminController::atualizarCadastro()). Guarda o override como
// FATO, nunca sobrescreve o composto — a composição continua calculável a
// qualquer momento a partir do snapshot.
//
// `text()` livre (não `enum`) — mesma disciplina de `cancelamento_motivo`
// (migration 2026_08_16_100000): o CHECK do `enum` é enforçado pelo SQLite
// dos testes e quebraria a suíte assim que a frase mudasse.
//
// Sem índice/FK novos — só uma coluna de texto simples, nada que exija o
// cuidado de nome de índice acima de 64 caracteres (armadilha MariaDB 1059).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contrato_assinaturas', function (Blueprint $table) {
            if (! Schema::hasColumn('contrato_assinaturas', 'plano_parcelas_texto')) {
                $table->text('plano_parcelas_texto')->nullable()->after('servicos_snapshot');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contrato_assinaturas', function (Blueprint $table) {
            if (Schema::hasColumn('contrato_assinaturas', 'plano_parcelas_texto')) {
                $table->dropColumn('plano_parcelas_texto');
            }
        });
    }
};
