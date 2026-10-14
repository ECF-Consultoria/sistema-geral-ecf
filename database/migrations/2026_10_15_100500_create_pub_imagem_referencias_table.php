<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 15/10/2026 — Fase 186, o portão no servidor: **O VÍNCULO DURÁVEL DA IMAGEM GERADA COM A
 * ORIGINAL DE REFERÊNCIA** (`pub_imagem_referencias`, DADO-15). A 12ª estrutura da milestone, e a
 * única que existe porque o vínculo de hoje **morre em 48 horas**.
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2 / MIG-07), escrita ANTES de o código existir.
 * Só CRIA uma tabela; nenhuma tabela existente com dado muda, nenhuma linha é apagada e nenhuma
 * coluna é renomeada (MIG-09). Sem `enum()` (MIG-04), sem `->change()`, sem backfill.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * BLOCO (1) — POR QUE ESTA ESTRUTURA EXISTE: O VÍNCULO DE HOJE É EFÊMERO
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ **MEDIDO em 14/10/2026, com os nomes conferidos no disco.** O vínculo *"esta imagem de IA
 * nasceu DESTAS originais"* está gravado hoje num lugar só — **`ml_anuncio_criativos.referencias`**
 * (json: path/mime/bytes/hash) — e é **apagado DUAS vezes**:
 *
 *   1. **na aprovação do criativo** — `MlbAnuncioController::criativoAprovar()`
 *      (`app/Http/Controllers/MlbAnuncioController.php:2624`);
 *   2. **pela varredura diária** `creative:limpar-referencias`
 *      (`app/Console/Commands/LimparReferenciasCriativos.php`), janela padrão de **48 horas**
 *      (`services.creative.retencao_referencias_horas`, `config/services.php:439`), com carimbo
 *      `referencias_apagadas_em` e o acessor `MlAnuncioCriativo::referenciasVivas()` (`:238`)
 *      devolvendo vazio depois disso.
 *
 * ✅ **MAS AS ORIGINAIS EM SI SÃO DURÁVEIS E SÃO NOSSAS:** são linhas de **`pub_imagens`** do
 * próprio rascunho. O docblock de
 * `app/Services/Publicador/Criativos/PublicadorCriativoReferenciaService.php:19-32` é **textual**:
 * *"As fotos do rascunho são do cliente e continuam do rascunho — **nada aqui move nem apaga
 * `pub_imagens`**. A CÓPIA em `creative-referencias/{token}/` é efêmera."*
 *
 * E os ids da original **já passam pelo seletor**: `selecionarFotos(PubRascunho $r, array $ids)`
 * (`:72`) recebe ids de `pub_imagens` e valida que são do rascunho. ➡️ **O vínculo é DERIVÁVEL no
 * momento da geração, sem nada do outro dev** — o que falta é gravá-lo duravelmente.
 *
 * ➡️ **A DECISÃO: tabela própria, N:N, NOSSA, que sobrevive à aprovação e à retenção.** As duas
 * FKs apontam para `pub_imagens`, que **nada no Creative Engine move ou apaga** (o docblock acima,
 * citado nominalmente para a afirmação poder ser reconferida).
 *
 * ⚠️ **N:N DE PROPÓSITO, e é por isso que é tabela e não coluna.** DADO-15 é explícito: *"aceita
 * **mais de uma** original por imagem gerada"*. Uma coluna `referencia_imagem_id` em `pub_imagens`
 * comportaria UMA, e o painel de comparação mostraria uma das originais como se fosse a única —
 * afirmação falsa sobre como a imagem nasceu.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * BLOCO (2) — ⚠️⚠️ **ESCRITA NAS FASES 192/194 — ESTA MIGRATION SÓ *CRIA* A ESTRUTURA**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️⚠️ **NADA NESTA FASE PREENCHE `pub_imagem_referencias`. A ESCRITA É DAS FASES 192 E 194** —
 * a **Fase 192** (a regeneração de UMA imagem, que é quando a escolha das originais acontece) e a
 * **Fase 194** (a automação ligada atrás do portão). ⚠️ **Está em maiúsculas para ninguém achar
 * que faltou**, e é essa fase dona NOMEADA que torna "nada sem destino" (PUB-10) mecânico em vez
 * de promessa.
 *
 * ⚠️ **E a prova de que esta fase não escreve NÃO é "a tabela tem 0 linhas".** Com
 * `RefreshDatabase` a tabela **nasce vazia em todo teste**, então aquela asserção é **vazia por
 * construção**: passaria igual se o código escrevesse e o teste não exercitasse o caminho. ➡️ A
 * prova que vale é a **ausência de ESCRITA no código** de `app/`, com **isca de dois lados** (a
 * mesma sonda, apontada para um modelo que o `app/` REALMENTE escreve, tem de ACHAR). Está em
 * `tests/Feature/Publicador/Aprovacao/VinculoDuravelDaOriginalTest.php`.
 *
 * ⚠️ **NENHUM BACKFILL É POSSÍVEL.** O dado do acervo antigo **já foi apagado** — as duas rotas de
 * apagamento do bloco (1) rodam desde a Fase 160. Não há de onde reconstruir. ➡️ **Na estreia isso
 * vale para TODO o acervo atual.** O passado fica no **estado declarado de IMG-13**: *"original de
 * referência não disponível"*, com **ícone e texto** (VOC-05) — **nunca** painel vazio, **nunca**
 * a imagem de IA repetida no lugar da original, **nunca** zero. Essa tela é critério de sucesso da
 * **Fase 190**, não desta; aqui fica o **registro do fato** e a constante que a nomeia
 * (`PubImagemReferencia::SEM_ORIGINAL`).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * BLOCO (3) — ⚠️⚠️ **TERR-11: A RETENÇÃO DE 48 h E O `creative:limpar-referencias` NÃO SÃO
 * TOCADOS**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️⚠️ **Nada desta fase muda a janela de 48 h, o comando da varredura, o
 * `PublicadorCriativoAprovacaoService`, o `PublicadorCriativoReferenciaService` (SOMENTE LEITURA)
 * ou qualquer coisa em `app/Services/Creative/`.**
 *
 * **A razão, e por que ela não é negociável aqui:** a janela existe por **decisão de custo e
 * privacidade da v24.0 (D-02)** — é do **outro dev**, e o próprio
 * `config/services.php:430-439` registra o raciocínio (48 h são duas ordens de grandeza acima da
 * vida máxima de um job de geração, `MlAnuncioCriativo::LIMITE_MINUTOS = 12`, e é essa folga que
 * torna a varredura **estruturalmente incapaz** de apagar arquivo que algum job ainda esteja
 * lendo). O incidente do **ECF Drive** — onde a retenção de 90 dias apagava o arquivo vivo porque
 * todas as versões gravavam no mesmo `local_path` — é o motivo de o desenho ser assim.
 *
 * ➡️ **A saída certa é o vínculo durável NOSSO, nunca esticar a retenção alheia.** Esticar a
 * janela resolveria o sintoma por mais algumas horas, continuaria perdendo o vínculo depois, e
 * carregaria num requisito de produto uma decisão de custo e privacidade de outra pessoa.
 * ⚠️ **Se um dia parecer necessário mexer naquela janela: PARAR e devolver a pergunta.**
 *
 * ⚠️ E é por isso que **`criativo_id` é SEM FK** — ver a tabela abaixo: FK para
 * `ml_anuncio_criativos` seria DDL encostando na tabela do outro dev (T-186-23).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * A TABELA DE DECISÃO DE SCHEMA
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * | coluna               | tipo                        | regra / por quê                                                                 |
 * |----------------------|-----------------------------|---------------------------------------------------------------------------------|
 * | id                   | bigint unsigned PK          |                                                                                 |
 * | imagem_id            | unsignedBigInteger          | **a imagem GERADA**. FK `pubimref_imagem_fk` -> pub_imagens, **CASCADE**: imagem apagada leva o vínculo dela (T-186-25) |
 * | referencia_imagem_id | unsignedBigInteger          | **a ORIGINAL**. FK `pubimref_orig_fk` -> pub_imagens, **CASCADE**. As DUAS apontam para a MESMA tabela — ver os nomes abaixo |
 * | criativo_id          | unsignedBigInteger **NULL** | o `ml_anuncio_criativos` de onde a geração saiu, quando houver. ⚠️ **SEM FK** — ver abaixo |
 * | posicao              | unsignedSmallInteger def. 0 | a ORDEM em que as originais foram escolhidas (o seletor recebe uma lista ordenada) |
 * | gravado_em           | dateTime                    | quando o vínculo foi gravado. `dateTime`, nunca `timestamp()` (teto de 2038)     |
 * | timestamps           |                             |                                                                                 |
 *
 * unique (`imagem_id`, `referencia_imagem_id`) = `pubimref_par_uq`: o MESMO par não se repete —
 * a mesma original escolhida duas vezes para a mesma imagem não é duas referências, é um erro.
 *
 * ⚠️ **`criativo_id` É SEM FK, E POR DUAS RAZÕES INDEPENDENTES:**
 *   1. **convenção de âncora deste módulo** — `pub_tarefas` e `pub_filas_publicacao.company_id`
 *      fazem o mesmo ("âncora da conta (sem FK, como `pub_tarefas`)");
 *   2. ⚠️ **TERR-11 / T-186-23** — FK para `ml_anuncio_criativos` é DDL encostando na tabela do
 *      outro dev. **Nenhuma linha desta migration altera aquela tabela.**
 *
 * ⚠️ **E ela é CONVENIÊNCIA DE LEITURA, não o caminho.** De `pub_imagens.id` já se chega ao
 * criativo por **`ml_anuncio_criativos.pub_imagem_id`** (nullable, `nullOnDelete`, índice
 * **`ml_criativos_pubimg_idx`**), criada pela migration da Fase 165
 * (`2026_10_04_120000_add_pub_columns_to_ml_anuncio_criativos_and_kits.php`, bloco **(e)**) e
 * preenchida por `PublicadorCriativoAprovacaoService.php:117`. ➡️ **Nenhuma coluna nova é
 * necessária para alcançar o criativo a partir da imagem** — e imagem vinda de **upload** não tem
 * criativo, o que é **correto**: é exatamente o que o "N fotos · N geradas por IA" distingue.
 *
 * ⚠️ **NÃO HÁ `rascunho_id` AQUI, e é deliberado:** as duas imagens são do MESMO rascunho (o
 * seletor valida isso em `selecionarFotos()`), então `rascunho_id` seria uma terceira cópia da
 * mesma verdade, livre para divergir das duas FKs. O recorte sai por
 * `pub_imagens.rascunho_id`, em um `join`.
 *
 * ⚠️ **NÃO HÁ CONTADOR de referências**: é DERIVADO (`count()` do vínculo). Contador
 * denormalizado é a classe de bug que faz a tela dizer "2 originais" com 3 linhas no banco — o
 * mesmo raciocínio já escrito em `2026_10_15_100000_...`.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * NOMES DE ÍNDICE E DE FK, **MEDIDOS POR `strlen`** (MIG-02)
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * O MariaDB recusa nome acima de **64** (erro **1059**), o SQLite aceita, e a migration fica
 * **`Pending` com a tabela JÁ criada** — o pior estado possível
 * (`project_mariadb_nome_indice_64`). **Nada é contado à mão:** os valores abaixo saíram de
 * `strlen` em 15/10/2026, e o guarda recalcula por `strlen` a cada rodada.
 *
 *   NOMEADOS À MÃO (padrão do módulo — nenhum `->constrained()`):
 *     pubimref_par_uq ......... 15      pubimref_imagem_fk ...... 18
 *     pubimref_orig_ix ........ 16      pubimref_orig_fk ........ 16
 *     pubimref_criativo_ix .... 20
 *
 *   NOMES **PADRÃO DO LARAVEL** (`<tabela>_<coluna>_foreign`) — contados porque são os que
 *   ESTOURAM, e nenhum é usado. `pub_imagem_referencias` = 22:
 *     pub_imagem_referencias_imagem_id_foreign ............. 40
 *     pub_imagem_referencias_referencia_imagem_id_foreign .. **51**   <- o maior; passa
 *
 * ⚠️ **O plano dizia "50 ou 51" para esse nome. MEDIDO: 51** (22 + 1 + 20 + 8). É a segunda vez
 * nesta fase que um número de nome de índice vem do plano com margem de erro de 1 — e é a razão
 * de o guarda recalcular por `strlen` em vez de confiar no texto.
 *
 * ⚠️ **DUAS FKs PARA A MESMA TABELA**, e por isso as duas são **nomeadas à mão**: deixar o Laravel
 * gerar produziria o nome de 51 acima e, num `rename` futuro da tabela, estouraria sem ninguém
 * ver. O unique `pubimref_par_uq` lidera por `imagem_id` e **apoia a `pubimref_imagem_fk`**; o
 * `pubimref_orig_ix` apoia a `pubimref_orig_fk` **e** serve à consulta real ("em quais imagens
 * geradas esta original foi usada").
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * A DISCIPLINA RESTANTE (MIG-01..09), ITEM A ITEM
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * - **MIG-01** — **produção é MySQL 8.0.46, o local é MariaDB 10.4.32, os testes são SQLite**, e o
 *   SQLite é o que MENOS pega. A prova de `migrate → rollback → migrate` é no MariaDB local, com
 *   `--path` só desta migration, e os índices/FKs são conferidos por `information_schema` — nunca
 *   por stdout.
 * - **MIG-03** — não há **nenhuma** FK `nullOnDelete()` aqui: as duas são CASCADE e as colunas são
 *   NOT NULL de propósito (vínculo sem uma das duas pontas não é vínculo). A única coluna anulável
 *   é `criativo_id`, que **não tem FK**. ⚠️ Logo a sonda do **1830** rodaria sobre **ZERO casos**
 *   nesta migration, e **gate que roda sobre zero casos não prova nada** — por isso ela fica no
 *   teste **com isca sintética**, e o invariante que VALE aqui (zero `nullOnDelete`, as duas
 *   CASCADE NOT NULL, `criativo_id` anulável e sem FK) tem asserção própria.
 * - **MIG-04** — sem `enum()`: não há coluna de domínio fechado aqui. A constante de estado
 *   declarado (`SEM_ORIGINAL`) mora no **modelo**, não no banco — ela descreve a AUSÊNCIA de
 *   linha, e ausência não é valor de coluna.
 * - **MIG-05** — `up()` idempotente por `Schema::hasTable`. **Sem `try/catch` em volta de DDL** —
 *   neste projeto um 1553 ficou engolido por 2 meses dentro de um catch, e um gate que CONTA a
 *   palavra `try` reprovaria por esta própria frase, então o guarda lê o CÓDIGO sem comentários,
 *   pelo tokenizador do PHP.
 * - **MIG-08** — os índices são declarados **ANTES** das FKs: o MariaDB usa como apoio o índice
 *   que a coluna lidera.
 * - **MIG-09** — nenhuma linha apagada, nenhuma coluna renomeada, nenhum backfill (e, aqui,
 *   backfill é **impossível** — ver o bloco (2)).
 * - `down()` derruba a **TABELA** (`dropIfExists`), que subsume o par
 *   `dropForeign(['coluna'])`/`dropIndex` — aquela é a receita de migration **ADITIVA**
 *   (`2026_10_04_120000_...`), não de `create`. **Não há `dropIndex` aqui, e é por isso que não há
 *   `dropForeign` tampouco.** Sendo UMA tabela só, não há ordem filha→mãe a respeitar — mas ela é
 *   FILHA de `pub_imagens` (duas vezes), e `pub_imagens` **não é derrubada aqui**.
 *
 * ⚠️ **`pub_imagens` TEM de existir antes desta migration** (as duas FKs apontam para ela). Se um
 * dia uma destas FKs falhar por tabela ausente, a resposta **não é tirar a FK**: é aplicar a
 * migration de `pub_imagens` primeiro.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pub_imagem_referencias')) {
            Schema::create('pub_imagem_referencias', function (Blueprint $t) {
                $t->id();
                // A imagem GERADA.
                $t->unsignedBigInteger('imagem_id');
                // A ORIGINAL de referência — a MESMA tabela, outra linha.
                $t->unsignedBigInteger('referencia_imagem_id');
                // ⚠️ SEM FK (TERR-11 / T-186-23): DDL não encosta em `ml_anuncio_criativos`.
                $t->unsignedBigInteger('criativo_id')->nullable();
                $t->unsignedSmallInteger('posicao')->default(0);
                $t->dateTime('gravado_em');
                $t->timestamps();

                // ⚠️ Índices ANTES das FKs (MIG-08). O unique lidera por `imagem_id` e apoia a
                // primeira FK; o `pubimref_orig_ix` apoia a segunda.
                $t->unique(['imagem_id', 'referencia_imagem_id'], 'pubimref_par_uq');
                $t->index('referencia_imagem_id', 'pubimref_orig_ix');
                $t->index('criativo_id', 'pubimref_criativo_ix');

                $t->foreign('imagem_id', 'pubimref_imagem_fk')->references('id')->on('pub_imagens')->cascadeOnDelete();
                $t->foreign('referencia_imagem_id', 'pubimref_orig_fk')->references('id')->on('pub_imagens')->cascadeOnDelete();
            });
        }
    }

    /** Uma tabela só; ela é FILHA de `pub_imagens` duas vezes, e `pub_imagens` não é derrubada. */
    public function down(): void
    {
        Schema::dropIfExists('pub_imagem_referencias');
    }
};
