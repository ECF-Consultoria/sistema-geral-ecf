<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Agenda do onboarding (16/09/2026): `onboarding_eventos_google` deixa de
 * guardar só os dois convites fixos — kickoff e rotina — e passa a guardar
 * QUALQUER evento que o sistema criou para um onboarding (mapeamento da conta,
 * apresentação, outro).
 *
 * ### Por que ampliar esta tabela, e não criar outra
 * Ela já é o vínculo "evento do Google ↔ onboarding". Uma segunda tabela para
 * os eventos novos faria a agenda juntar duas fontes para responder a mesma
 * pergunta. E ela estava VAZIA em produção quando esta migration foi escrita
 * (0 linhas em 16/09/2026, conferido na VPS) — ampliar não mexe em dado de
 * ninguém.
 *
 * ### O que passa a ser guardado
 * O Google continua sendo a verdade do evento. As colunas novas são o RETRATO
 * do que foi criado — título, início, fim, plataforma, link, participantes —
 * para a ficha desenhar a agenda sem chamar a API a cada visita, para quem não
 * conectou o Google ver os eventos do onboarding, e para a tela não ficar vazia
 * quando o Google estiver fora do ar. `sincronizado_em` diz quando o retrato
 * foi conferido contra o Google pela última vez.
 *
 * ### O índice único
 * Antes: único (onboarding_id, tipo) — um evento por tipo. Continua valendo
 * para kickoff e rotina (é o que impede o clique duplo de mandar dois
 * convites), mas não para "mapeamento da conta", que pode acontecer duas vezes.
 * Por isso a unicidade passa para (onboarding_id, chave): `chave` repete o tipo
 * nos eventos únicos e fica NULA nos avulsos — e NULL não conflita em índice
 * único, nem no MariaDB nem no SQLite.
 *
 * ### Armadilhas de MariaDB (learnings §6)
 * - O único antigo começa por `onboarding_id` e, por isso, é o índice que
 *   sustenta a FK dessa coluna: dropá-lo sozinho dá erro 1553. O índice simples
 *   `oeg_onboarding_idx` nasce ANTES do drop.
 * - Nomes de índice escritos à mão, curtos (o nome da tabela já tem 25).
 * - Cada passo confere se já foi feito: uma migration que morre no meio fica
 *   `Pending` com metade aplicada, e precisa poder rodar de novo.
 */
return new class extends Migration
{
    private const TABELA = 'onboarding_eventos_google';

    private const UNICO_ANTIGO = 'onboarding_eventos_google_onboarding_id_tipo_unique';

    private const IDX_ONBOARDING = 'oeg_onboarding_idx';

    private const UNICO_CHAVE = 'oeg_onboarding_chave_unique';

    private const IDX_INICIO = 'oeg_inicio_idx';

    private const IDX_EVENTO = 'oeg_google_event_idx';

    /** Tipos que continuam valendo uma vez por onboarding. */
    private const TIPOS_UNICOS = ['kickoff', 'recorrente'];

    public function up(): void
    {
        $colunas = [
            // Repete o tipo nos eventos únicos; nula nos avulsos.
            'chave'           => fn (Blueprint $t) => $t->string('chave', 20)->nullable()->after('tipo'),
            'titulo'          => fn (Blueprint $t) => $t->string('titulo', 255)->nullable(),
            // Hora de Brasília, como o resto do sistema (config app.timezone).
            'inicio'          => fn (Blueprint $t) => $t->dateTime('inicio')->nullable(),
            'fim'             => fn (Blueprint $t) => $t->dateTime('fim')->nullable(),
            // A regra RRULE da rotina. Sem ela, quem não tem Google conectado
            // veria a rotina só na primeira data.
            'recorrencia'     => fn (Blueprint $t) => $t->string('recorrencia', 120)->nullable(),
            // 'google_meet' | 'link' | 'presencial' | 'nenhuma' — catálogo em
            // OnboardingEventoGoogle::PLATAFORMAS, varchar pelo mesmo motivo do `tipo`.
            'plataforma'      => fn (Blueprint $t) => $t->string('plataforma', 20)->nullable(),
            'link_reuniao'    => fn (Blueprint $t) => $t->string('link_reuniao', 500)->nullable(),
            'descricao'       => fn (Blueprint $t) => $t->text('descricao')->nullable(),
            // [{email, nome, lado}] do último envio.
            'participantes'   => fn (Blueprint $t) => $t->json('participantes')->nullable(),
            // 'ativo' | 'cancelado'. Cancelar não apaga: o rastro do convite
            // enviado continua valendo, e o kickoff cancelado é reaproveitado
            // pelo próximo envio.
            'status'          => fn (Blueprint $t) => $t->string('status', 20)->default('ativo'),
            'cancelado_em'    => fn (Blueprint $t) => $t->timestamp('cancelado_em')->nullable(),
            'sincronizado_em' => fn (Blueprint $t) => $t->timestamp('sincronizado_em')->nullable(),
        ];

        foreach ($colunas as $nome => $definir) {
            if (! Schema::hasColumn(self::TABELA, $nome)) {
                Schema::table(self::TABELA, fn (Blueprint $t) => $definir($t));
            }
        }

        DB::table(self::TABELA)
            ->whereIn('tipo', self::TIPOS_UNICOS)
            ->whereNull('chave')
            ->update(['chave' => DB::raw('tipo')]);

        // Índice simples ANTES de dropar o único antigo (erro 1553).
        if (! Schema::hasIndex(self::TABELA, self::IDX_ONBOARDING)) {
            Schema::table(self::TABELA, fn (Blueprint $t) => $t->index(['onboarding_id'], self::IDX_ONBOARDING));
        }

        if (Schema::hasIndex(self::TABELA, self::UNICO_ANTIGO)) {
            Schema::table(self::TABELA, fn (Blueprint $t) => $t->dropUnique(self::UNICO_ANTIGO));
        }

        if (! Schema::hasIndex(self::TABELA, self::UNICO_CHAVE)) {
            Schema::table(self::TABELA, fn (Blueprint $t) => $t->unique(['onboarding_id', 'chave'], self::UNICO_CHAVE));
        }

        // A agenda consulta por intervalo de datas e casa os itens do Google
        // pelo id do evento.
        if (! Schema::hasIndex(self::TABELA, self::IDX_INICIO)) {
            Schema::table(self::TABELA, fn (Blueprint $t) => $t->index(['inicio'], self::IDX_INICIO));
        }

        if (! Schema::hasIndex(self::TABELA, self::IDX_EVENTO)) {
            Schema::table(self::TABELA, fn (Blueprint $t) => $t->index(['google_event_id'], self::IDX_EVENTO));
        }
    }

    public function down(): void
    {
        // Voltar ao único (onboarding_id, tipo) é impossível com dois eventos
        // avulsos do mesmo tipo no mesmo onboarding. Apagar evento para caber no
        // índice antigo destruiria o rastro de convite enviado a cliente real.
        $avulsos = DB::table(self::TABELA)->whereNull('chave')->count();

        if ($avulsos > 0) {
            throw new RuntimeException(
                "Há {$avulsos} evento(s) avulso(s) em ".self::TABELA.'; o índice antigo não comporta. '
                .'Decida o destino deles antes de reverter.'
            );
        }

        // O único antigo volta ANTES de sair o índice simples: um dos dois
        // precisa sustentar a FK de onboarding_id o tempo todo.
        if (! Schema::hasIndex(self::TABELA, self::UNICO_ANTIGO)) {
            Schema::table(self::TABELA, fn (Blueprint $t) => $t->unique(['onboarding_id', 'tipo'], self::UNICO_ANTIGO));
        }

        foreach ([self::UNICO_CHAVE, self::IDX_INICIO, self::IDX_EVENTO, self::IDX_ONBOARDING] as $indice) {
            if (Schema::hasIndex(self::TABELA, $indice)) {
                Schema::table(self::TABELA, fn (Blueprint $t) => $indice === self::UNICO_CHAVE
                    ? $t->dropUnique($indice)
                    : $t->dropIndex($indice));
            }
        }

        foreach ([
            'chave', 'titulo', 'inicio', 'fim', 'recorrencia', 'plataforma', 'link_reuniao',
            'descricao', 'participantes', 'status', 'cancelado_em', 'sincronizado_em',
        ] as $coluna) {
            if (Schema::hasColumn(self::TABELA, $coluna)) {
                Schema::table(self::TABELA, fn (Blueprint $t) => $t->dropColumn($coluna));
            }
        }
    }
};
