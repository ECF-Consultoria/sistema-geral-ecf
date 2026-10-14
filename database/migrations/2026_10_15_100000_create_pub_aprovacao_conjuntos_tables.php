<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 15/10/2026 — Fase 186, o portão no servidor: a espinha de dado da REVISÃO DE FOTOS do
 * Publicador. Três tabelas: o conjunto de fotos compartilhado, o estado de cada imagem NA
 * REVISÃO e as verificações automáticas com coordenada.
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2 / MIG-07), escrita ANTES de o código existir.
 * Só CRIA tabelas; nenhuma tabela existente com dado muda, nenhuma linha é apagada e nenhuma
 * coluna é renomeada (MIG-09). Sem `enum()` (o CHECK é enforçado no SQLite dos testes e
 * quebraria a suíte — MIG-04), sem `->change()`, sem backfill.
 *
 * ═══ POR QUE O COMPARTILHAMENTO CLÁSSICO↔PREMIUM É ESTRUTURAL (critério 12) ═══════════════
 *
 * A aprovação da foto é gravada **por conjunto** (`pub_aprovacao_imagens.conjunto_id`), e o
 * conjunto é **por rascunho** (`unique (rascunho_id, grupo_hash)`). O rascunho tem os DOIS
 * alvos (`pub_rascunho_alvos`: `gold_special` = Clássico, `gold_pro` = Premium), logo as duas
 * ofertas leem o MESMO conjunto — não por regra que alguém aplica, mas porque **não existe
 * coluna onde elas pudessem divergir**.
 *
 * ⚠️ Isso só é verdade porque `pub_aprovacao_ofertas` (plano 186-04) **NÃO tem `conjunto_id`**
 * e porque **nenhuma tabela do portão tem o par `(oferta_id, imagem_id)`**. Com uma FK
 * `conjunto_id` na oferta, o unique da oferta seria só em `alvo_id` e nada no schema forçaria
 * as duas ofertas ao mesmo conjunto — o compartilhamento passaria por FIXTURE, não por
 * construção. É por isso que a ausência da coluna é uma asserção de teste
 * (`ConjuntoCompartilhadoTest`), e não um comentário.
 *
 * ═══ O EIXO VARIANTE — a conta que define quantas linhas há aqui ═══════════════════════════
 *
 * `ResolvedorGruposImagem::chaveDoGrupo()` (`app/Support/Publicador/Imagem/
 * ResolvedorGruposImagem.php:32-50`) devolve **um grupo por valor de eixo que define foto**
 * (`definesPicture`), mais o **`GENERAL`** quando a variante não tem valores. Logo:
 *
 *     rascunho com 3 cores que definem foto  =>  4 linhas em `pub_aprovacao_conjuntos`
 *                                                (3 grupos de cor + o GENERAL)
 *
 * ⚠️ DECISÃO DO USUÁRIO, 14/10/2026: **o bloqueio de fotos considera TODOS os grupos do
 * rascunho.** A oferta só fica aprovável com as fotos de todas as variações **e** as gerais
 * conferidas. Aprovação cor por cor foi oferecida e RECUSADA, pela razão que amarra a
 * milestone: aprovar já publica e não há desfazer, então foto não conferida não pode ir ao ar.
 *
 * ➡️ A relação oferta→conjunto é **1:N**, e é lida por UMA consulta
 * (`PubAprovacaoConjunto::where('rascunho_id', $oferta->rascunho_id)`), nunca uma por grupo.
 * Não há pivô N:N de propósito: ele teria SEMPRE o mesmo conteúdo do `join` por `rascunho_id`,
 * e um pivô que pode divergir da verdade é pior que nenhum.
 *
 * ═══ `pub_aprovacao_conjuntos` — um conjunto de fotos por (rascunho × grupo de variação) ═══
 * | coluna              | tipo                            | regra / por quê                                                      |
 * |---------------------|---------------------------------|----------------------------------------------------------------------|
 * | id                  | bigint unsigned PK              |                                                                      |
 * | rascunho_id         | unsignedBigInteger              | FK `pubapcj_rascunho_fk` -> pub_rascunhos, CASCADE: rascunho apagado leva a revisão dele |
 * | grupo_chave         | string(600) default 'GENERAL'   | o TEXTO do grupo, **sem índice** (ver abaixo); default = galeria geral, nunca NULL (espelha `pub_imagem_atribuicoes`) |
 * | grupo_hash          | char(64)                        | sha256 do grupo — é o que ENTRA no índice                            |
 * | principal_imagem_id | unsignedBigInteger NULL         | FK `pubapcj_principal_fk` -> pub_imagens, SET NULL: a capa do grupo; imagem apagada não derruba o conjunto |
 * | tocado              | boolean default false           | o `touched` do protótipo: "alguém já olhou este grupo"                |
 * | timestamps          |                                 |                                                                      |
 *
 * ⚠️ **`fotos_aprovadas` / `fotos_no_conjunto` NÃO EXISTEM**, de propósito: são DERIVADOS.
 * Contador denormalizado é a classe de bug que faz a tela dizer "3 de 4 aprovadas" com 5 fotos
 * no banco. Quem conta é o modelo (`PubAprovacaoConjunto::aprovadas()/pendentes()`).
 *
 * ⚠️ **`grupo_chave` é string(600) e de propósito SEM ÍNDICE** — exatamente o precedente de
 * `pub_imagem_atribuicoes.grupo_chave` (`2026_10_01_200000_create_publicador_tables.php:196`)
 * e de `pub_grupo` (`2026_10_04_120000_...`): 600 chars em utf8mb4 são até 2400 bytes e
 * estourariam o limite de 3072 bytes de chave do InnoDB somados às demais colunas do índice.
 * **Indexa-se o HASH, nunca o texto.** Como toda consulta filtra `rascunho_id` (indexado)
 * antes de comparar o grupo, o conjunto de linhas por rascunho é pequeno.
 *
 * ═══ `pub_aprovacao_imagens` — o estado da imagem NA REVISÃO (DADO-05) ═════════════════════
 * | coluna      | tipo                           | regra / por quê                                                        |
 * |-------------|--------------------------------|------------------------------------------------------------------------|
 * | id          | bigint unsigned PK             |                                                                        |
 * | conjunto_id | unsignedBigInteger             | FK `pubapim_conjunto_fk` -> pub_aprovacao_conjuntos, CASCADE           |
 * | imagem_id   | unsignedBigInteger             | FK `pubapim_imagem_fk` -> pub_imagens, CASCADE: foto apagada sai da revisão |
 * | tipo        | string(10)                     | `original` \| `ia` — texto livre + constante no modelo (sem enum)      |
 * | estado      | string(14) default 'pending'   | `pending` \| `approved` \| `rejected` \| `regenerating` \| `replaced` (maior = 12 chars) |
 * | posicao     | unsignedSmallInteger NULL      | ⚠️ **NULL = fora da publicação**; a principal é sempre a `posicao` 0 (IMG-07) |
 * | fora        | boolean default false          | tirada da publicação explicitamente                                    |
 * | nova        | boolean default false          | o selo **NOVA** da tela                                                |
 * | quem_id     | unsignedBigInteger NULL        | FK `pubapim_quem_fk` -> users, SET NULL: usuário apagado não apaga a revisão |
 * | quando      | dateTime NULL                  | `dateTime`, nunca `timestamp()`, em data avulsa (convenção de `2026_10_01_200000`) |
 * | motivo      | string(500) NULL               | obrigatório ao reprovar — a regra é do SERVIÇO (IMG-10), o schema aceita NULL |
 * | timestamps  |                                |                                                                        |
 *
 * ⚠️ **ESTE EIXO NÃO É `pub_imagens.upload_status`.** Aquele é de UPLOAD
 * (`pending`/`uploaded`/`failed` — `PubImagem::PENDENTE/ENVIADA/FALHOU`): diz se o byte chegou
 * ao Mercado Livre. Este é de REVISÃO: diz se uma pessoa conferiu a foto. Uma foto pode estar
 * `uploaded` e `rejected` ao mesmo tempo, e isso é correto. **DADO-05 proíbe reusar ou
 * estender o eixo de upload.**
 *
 * ⚠️ **NÃO HÁ coluna de "vínculo com variação" aqui, e isso não é lacuna:** a variação JÁ É o
 * conjunto (`pub_aprovacao_conjuntos.grupo_hash`). Uma coluna de variação na imagem poderia
 * divergir do conjunto que a contém — mesmo raciocínio do pivô N:N acima.
 *
 * ⚠️ **NÃO HÁ coluna de "original de referência" aqui:** essa relação é N:N (uma gerada pode
 * referenciar várias originais) e vive em `pub_imagem_referencias` — **plano 186-06, DADO-15**,
 * e só é ESCRITA nas Fases 192/194. Não é omissão deste plano.
 *
 * ⚠️ **NÃO HÁ coluna para alcançar o criativo do Creative Engine:** a ponte já existe e é
 * indexada no outro sentido — `ml_anuncio_criativos.pub_imagem_id` -> `pub_imagens.id`
 * (`2026_10_04_120000_...`, bloco (e), índice `ml_criativos_pubimg_idx`), preenchida por
 * `PublicadorCriativoAprovacaoService:117`. Imagem de UPLOAD não tem criativo, e isso é
 * correto: é o que o "N fotos · N geradas por IA" distingue.
 *
 * ═══ `pub_aprovacao_imagem_alertas` — verificações automáticas (DADO-06) ═══════════════════
 * | coluna              | tipo                              | regra / por quê                                                |
 * |---------------------|-----------------------------------|----------------------------------------------------------------|
 * | id                  | bigint unsigned PK                |                                                                |
 * | aprovacao_imagem_id | unsignedBigInteger                | FK `pubapal_imagem_fk` -> pub_aprovacao_imagens, CASCADE       |
 * | severidade          | string(4)                         | `ok` \| `med` \| `high` — `high` ocupa os 4; sem enum          |
 * | texto               | string(500)                       | o que a verificação achou, em pt-BR, pronto para a tela        |
 * | x                   | decimal(5,2) NULL                 | ⚠️ coordenada em **% da imagem** (0,00–100,00)                 |
 * | y                   | decimal(5,2) NULL                 | idem; o protótipo só manda x/y quando `sev !== 'ok'`           |
 * | origem              | string(20) default 'automatica'   | de onde veio o alerta; hoje só a verificação automática        |
 * | timestamps          |                                   |                                                                |
 *
 * ⚠️ **A FOLGA DO DECIMAL E O MODO DE FALHAR ASSIMÉTRICO.** `decimal(5,2)` aceita até
 * **999,99** — 10× a faixa esperada (0–100). A folga é de propósito: se um dia a coordenada
 * passar a vir em pixels, 999 ainda cabe e o erro aparece na tela em vez de no deploy.
 * O modo de falhar é **assimétrico e já custou caro neste projeto**: estourar a precisão dá
 * **erro 1264 no MySQL/MariaDB** e **grava calado (truncado) no SQLite dos testes** — ou seja,
 * a suíte não pega. Por isso a faixa está escrita aqui e o modelo casta x/y para `float`.
 *
 * ═══ DADO-14 — o recorte por conta/empresa ════════════════════════════════════════════════
 * Não há `company_id`/`mlb_empresa_id` em nenhuma destas três tabelas, e isso é DECISÃO:
 * o recorte vem por `rascunho_id -> pub_rascunhos.produto_id -> pub_produtos.company_id /
 * .mlb_empresa_id`. Coluna duplicada de conta pode divergir do produto (o produto troca de
 * âncora, a linha do portão fica com a antiga) e seria uma via de vazamento entre empresas
 * **que o schema abençoaria**. Quem filtra é a query do serviço, sempre pelo produto.
 *
 * ═══ NOMES DE ÍNDICE E DE FK, COM OS CARACTERES CONTADOS (MIG-02) ══════════════════════════
 * O MariaDB recusa nome acima de **64** (erro **1059**), o SQLite aceita, e a migration fica
 * **`Pending` com a tabela JÁ criada** — o pior estado possível
 * (`project_mariadb_nome_indice_64` nos learnings).
 *
 *   NOMEADOS À MÃO (padrão do módulo):
 *     pubapcj_grupo_uq ........ 16    pubapim_img_uq ....... 14    pubapal_sev_ix .... 14
 *     pubapcj_rasc_ix ......... 15    pubapim_ordem_ix ..... 16    pubapal_imagem_fk . 17
 *     pubapcj_principal_ix .... 20    pubapim_estado_ix .... 17
 *     pubapcj_rascunho_fk ..... 19    pubapim_imagem_ix .... 17
 *     pubapcj_principal_fk .... 20    pubapim_quem_ix ...... 15
 *                                     pubapim_conjunto_fk .. 19
 *                                     pubapim_imagem_fk .... 17
 *                                     pubapim_quem_fk ...... 15
 *
 *   NOMES PADRÃO DO LARAVEL (`<tabela>_<coluna>_foreign`) — contados porque são os que
 *   ESTOURAM, e todos passam folgados (nenhum é usado: toda FK aqui é nomeada à mão):
 *     pub_aprovacao_conjuntos_rascunho_id_foreign .............. 43
 *     pub_aprovacao_conjuntos_principal_imagem_id_foreign ...... 51
 *     pub_aprovacao_imagens_conjunto_id_foreign ................ 41
 *     pub_aprovacao_imagens_imagem_id_foreign .................. 39
 *     pub_aprovacao_imagens_quem_id_foreign .................... 37
 *     pub_aprovacao_imagem_alertas_aprovacao_imagem_id_foreign . 56
 *
 * ⚠️ O 186-03-PLAN.md dizia **57** para o último. **MEDIDO: 56** (`strlen`). O guarda
 * (`MigrationDoPortaoGuardaTest`) calcula por `strlen`, nunca por número escrito à mão —
 * número copiado à mão é exatamente o que não se confere.
 *
 * ═══ A DISCIPLINA RESTANTE ═════════════════════════════════════════════════════════════════
 * - **MIG-03** — toda FK `nullOnDelete()` tem `->nullable()` **EXPLÍCITO** (erro **1830** do
 *   MariaDB, invisível no SQLite): vale para `principal_imagem_id` e `quem_id`.
 * - **MIG-05** — `up()` idempotente por `Schema::hasTable`: se o deploy morrer no meio, a
 *   re-execução completa só o que faltou. **Sem `try/catch` em volta de DDL** — neste projeto
 *   um 1553 ficou engolido por 2 meses dentro de um catch.
 * - Os índices são declarados **ANTES** das FKs: o MariaDB usa como apoio o índice que a
 *   coluna lidera.
 * - `down()` derruba **filha antes da mãe** (a FK é CASCADE, mas no MariaDB o drop da mãe com
 *   a filha viva falha). Sendo uma migration que só CRIA, o `dropIfExists` da tabela inteira
 *   subsume o par `dropForeign(['coluna'])`/`dropIndex` — que é a receita de migration
 *   ADITIVA (`2026_10_04_120000_...`), não de `create`. **Não há `dropIndex` aqui, e é por
 *   isso que não há `dropForeign` tampouco.**
 * - Prova no MariaDB local com `--path` só desta migration: o SQLite dos testes não pega
 *   1059 / 1830 / 1553 / 1264.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pub_aprovacao_conjuntos')) {
            Schema::create('pub_aprovacao_conjuntos', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('rascunho_id');
                $t->string('grupo_chave', 600)->default('GENERAL');
                $t->char('grupo_hash', 64);
                $t->unsignedBigInteger('principal_imagem_id')->nullable();
                $t->boolean('tocado')->default(false);
                $t->timestamps();

                $t->unique(['rascunho_id', 'grupo_hash'], 'pubapcj_grupo_uq');
                $t->index('rascunho_id', 'pubapcj_rasc_ix');
                $t->index('principal_imagem_id', 'pubapcj_principal_ix');

                $t->foreign('rascunho_id', 'pubapcj_rascunho_fk')->references('id')->on('pub_rascunhos')->cascadeOnDelete();
                $t->foreign('principal_imagem_id', 'pubapcj_principal_fk')->references('id')->on('pub_imagens')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('pub_aprovacao_imagens')) {
            Schema::create('pub_aprovacao_imagens', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('conjunto_id');
                $t->unsignedBigInteger('imagem_id');
                $t->string('tipo', 10);
                $t->string('estado', 14)->default('pending');
                $t->unsignedSmallInteger('posicao')->nullable();
                $t->boolean('fora')->default(false);
                $t->boolean('nova')->default(false);
                $t->unsignedBigInteger('quem_id')->nullable();
                $t->dateTime('quando')->nullable();
                $t->string('motivo', 500)->nullable();
                $t->timestamps();

                $t->unique(['conjunto_id', 'imagem_id'], 'pubapim_img_uq');
                $t->index(['conjunto_id', 'posicao'], 'pubapim_ordem_ix');
                $t->index(['conjunto_id', 'estado'], 'pubapim_estado_ix');
                $t->index('imagem_id', 'pubapim_imagem_ix');
                $t->index('quem_id', 'pubapim_quem_ix');

                $t->foreign('conjunto_id', 'pubapim_conjunto_fk')->references('id')->on('pub_aprovacao_conjuntos')->cascadeOnDelete();
                $t->foreign('imagem_id', 'pubapim_imagem_fk')->references('id')->on('pub_imagens')->cascadeOnDelete();
                $t->foreign('quem_id', 'pubapim_quem_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('pub_aprovacao_imagem_alertas')) {
            Schema::create('pub_aprovacao_imagem_alertas', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('aprovacao_imagem_id');
                $t->string('severidade', 4);
                $t->string('texto', 500);
                $t->decimal('x', 5, 2)->nullable();
                $t->decimal('y', 5, 2)->nullable();
                $t->string('origem', 20)->default('automatica');
                $t->timestamps();

                $t->index(['aprovacao_imagem_id', 'severidade'], 'pubapal_sev_ix');

                $t->foreign('aprovacao_imagem_id', 'pubapal_imagem_fk')->references('id')->on('pub_aprovacao_imagens')->cascadeOnDelete();
            });
        }
    }

    /** Filha antes da mãe, na ordem inversa da criação. */
    public function down(): void
    {
        Schema::dropIfExists('pub_aprovacao_imagem_alertas');
        Schema::dropIfExists('pub_aprovacao_imagens');
        Schema::dropIfExists('pub_aprovacao_conjuntos');
    }
};
