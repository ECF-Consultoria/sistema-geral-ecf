<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quick 261007-m0t — `companies.cnpj` deixa de ser único (07/10/2026).
 *
 * ### O incidente
 * O Administrativo não conseguia salvar o cadastro em
 * `/administrativo/contratos/empresa/{id}`: o botão "Salvar cadastro" devolvia
 * 500, três vezes seguidas, com
 * `SQLSTATE[23000] ... 1062 Duplicate entry '38.196.897/0001-43' for key
 * 'companies.companies_cnpj_unique'`. O unique nasceu junto da tabela
 * (`2026_04_26_152217_create_companies_table.php`, linha 17) e
 * `ContratoAdminController::atualizarCadastro()` nunca tratou a violação.
 *
 * ### Por que o unique SAI, em vez de a tela passar a bloquear
 * CNPJ repetido é LEGÍTIMO neste negócio: uma empresa jurídica opera várias
 * lojas de marketplace e cada loja é um registro de `companies`, com conta
 * própria e métricas próprias. Medido em produção em 07/10/2026: 12 CNPJs
 * aparecem em 2 empresas cada, vários no MESMO grupo de cobrança —
 * KAITONCOMERCIO #191 / LOJAELASTIM #447, MAXIGOLD #234 / Nutrifour #426,
 * Utilarshop #368 / Ita Prime #384, ELLITE #137 / UNIQPRIME #314.
 *
 * E o índice JÁ NÃO FUNCIONAVA: nos 12 pares um registro guarda só dígitos
 * (`38196897000143`) e o outro com pontuação (`38.196.897/0001-43`) — as
 * strings diferem, então o unique nunca pegou. Ele só aparecia quando alguém
 * digitava no formato do outro registro, bloqueando trabalho legítimo de forma
 * aleatória. No lugar dele entra um AVISO na tela (quick 261007-m0t, T2), que
 * compara por dígitos e por isso enxerga os 12 pares que o unique não via.
 *
 * ### O índice comum que entra no lugar
 * `cnpj` continua sendo coluna de BUSCA — inclusive da consulta nova do aviso,
 * que varre as outras empresas de mesmo CNPJ a cada abertura da ficha. Sem
 * índice nenhum essa consulta fica ruim. O índice comum nasce ANTES de o
 * unique sair, para a coluna nunca ficar um instante sem índice.
 *
 * ### ⛔ O que esta migration NÃO faz
 * Não normaliza o formato dos CNPJs já gravados. Mexer nos 12 pares de dado de
 * produção é passo humano separado, e mudaria o que a tela mostra hoje.
 *
 * ### ⚠️ O `down()` VAI FALHAR enquanto os duplicados existirem
 * Recriar o unique com 12 pares de CNPJ repetido no banco é impossível — o
 * MariaDB recusa com o mesmo 1062 do incidente. A volta exige decidir antes o
 * destino de cada par (apagar? fundir? renumerar?), e isso é decisão de
 * negócio, não de migration. Por isso o `down()` nem tenta "consertar" dado:
 * ele conta os duplicados por dígitos e lança, dizendo o que falta decidir.
 *
 * ### Armadilhas cobertas
 * - **Idempotente**: cada passo confere se já foi feito. Migration que morre no
 *   meio fica `Pending` com metade aplicada e precisa poder rodar de novo.
 * - **SQLite**: a suíte roda em SQLite (`phpunit.xml`, `DB_CONNECTION=sqlite`),
 *   então esta migration roda lá a cada `RefreshDatabase`. O drop do unique usa
 *   `DROP INDEX IF EXISTS` cru nesse driver — literal, idempotente e sem
 *   depender de como a grammar do SQLite resolve `dropUnique()` por nome.
 */
return new class extends Migration
{
    private const TABELA = 'companies';

    /** Nome gerado pelo Laravel no `->unique()` da migration original. */
    private const UNICO_ANTIGO = 'companies_cnpj_unique';

    /** O índice comum que fica no lugar (nome curto e explícito, learnings §6). */
    private const IDX_CNPJ = 'companies_cnpj_idx';

    public function up(): void
    {
        // Passo 1 — o índice comum nasce ANTES de o unique sair, para `cnpj`
        // nunca ficar sem índice entre os dois passos.
        if (! Schema::hasIndex(self::TABELA, self::IDX_CNPJ)) {
            Schema::table(self::TABELA, fn (Blueprint $t) => $t->index(['cnpj'], self::IDX_CNPJ));
        }

        // Passo 2 — o unique sai.
        if (Schema::hasIndex(self::TABELA, self::UNICO_ANTIGO)) {
            if ($this->ehSqlite()) {
                DB::statement('DROP INDEX IF EXISTS "'.self::UNICO_ANTIGO.'"');
            } else {
                Schema::table(self::TABELA, fn (Blueprint $t) => $t->dropUnique(self::UNICO_ANTIGO));
            }
        }
    }

    public function down(): void
    {
        // Comparação por DÍGITOS, igual à do aviso da tela: é ela que enxerga
        // os pares em que um registro está pontuado e o outro não. Contar por
        // string crua daria zero e deixaria o unique estourar depois, no meio
        // do ALTER.
        $duplicados = DB::table(self::TABELA)
            ->whereNotNull('cnpj')
            ->where('cnpj', '<>', '')
            ->pluck('cnpj')
            ->map(fn ($cnpj) => preg_replace('/\D/', '', (string) $cnpj))
            ->filter(fn (string $digitos) => $digitos !== '')
            ->countBy()
            ->filter(fn (int $vezes) => $vezes > 1);

        if ($duplicados->isNotEmpty()) {
            throw new RuntimeException(
                'Há '.$duplicados->count().' CNPJ(s) repetido(s) em '.self::TABELA
                .' (comparando só os dígitos); o índice único não comporta. '
                .'CNPJ repetido é legítimo aqui (uma empresa jurídica com várias lojas de marketplace), '
                .'então reverter exige decidir antes o destino de cada par — esta migration não apaga '
                .'nem funde empresa nenhuma.'
            );
        }

        // O unique volta ANTES de o índice comum sair: um dos dois sustenta a
        // busca por `cnpj` o tempo todo.
        if (! Schema::hasIndex(self::TABELA, self::UNICO_ANTIGO)) {
            Schema::table(self::TABELA, fn (Blueprint $t) => $t->unique(['cnpj'], self::UNICO_ANTIGO));
        }

        if (Schema::hasIndex(self::TABELA, self::IDX_CNPJ)) {
            if ($this->ehSqlite()) {
                DB::statement('DROP INDEX IF EXISTS "'.self::IDX_CNPJ.'"');
            } else {
                Schema::table(self::TABELA, fn (Blueprint $t) => $t->dropIndex(self::IDX_CNPJ));
            }
        }
    }

    private function ehSqlite(): bool
    {
        return Schema::getConnection()->getDriverName() === 'sqlite';
    }
};
