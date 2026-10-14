<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * A ORIGINAL DE REFERÊNCIA DE UMA IMAGEM GERADA — **DURÁVEL, N:N E NOSSA** (DADO-15).
 *
 * As duas pontas são linhas de **`pub_imagens`**: `imagem_id` é a imagem **GERADA** e
 * `referencia_imagem_id` é a **ORIGINAL** de que ela nasceu. A razão completa de a estrutura
 * existir está no docblock da migration
 * `2026_10_15_100500_create_pub_imagem_referencias_table.php`; aqui ficam as regras de leitura.
 *
 * ═══ ⚠️ POR QUE É DURÁVEL, E POR QUE É NOSSA ═══════════════════════════════════════════════════
 *
 * O vínculo de hoje vive em **`ml_anuncio_criativos.referencias`** e **morre em 48 horas** — é
 * apagado na aprovação do criativo (`MlbAnuncioController::criativoAprovar()`) e pela varredura
 * diária `creative:limpar-referencias`. ⚠️ **As originais em si, porém, são DURÁVEIS:** são linhas
 * de `pub_imagens` do próprio rascunho, e o docblock de
 * `app/Services/Publicador/Criativos/PublicadorCriativoReferenciaService.php:19-32` é textual —
 * *"nada aqui move nem apaga `pub_imagens`"*. É essa durabilidade que faz este vínculo poder
 * existir sem tocar em nada do outro dev.
 *
 * ⚠️⚠️ **TERR-11: a retenção de 48 h e o `creative:limpar-referencias` NÃO são tocados.** A janela
 * é decisão de custo e privacidade da v24.0 (D-02) e é do outro dev. **A saída certa é este
 * vínculo, nunca esticar a retenção alheia.**
 *
 * ═══ ⚠️⚠️ **A ESCRITA É DAS FASES 192 E 194 — ESTE MODELO SÓ LÊ** ══════════════════════════════
 *
 * ⚠️⚠️ **NADA NESTA FASE PREENCHE `pub_imagem_referencias`.** A escrita é da **Fase 192** (a
 * regeneração de UMA imagem, quando a escolha das originais acontece) e da **Fase 194** (a
 * automação ligada atrás do portão). Está escrito para ninguém achar que faltou — e é a fase dona
 * NOMEADA que torna "nada sem destino" (PUB-10) mecânico em vez de promessa.
 *
 * ⚠️ **Nenhum backfill é possível:** o dado do acervo antigo já foi apagado pelas duas rotas acima,
 * e não há de onde reconstruir. ➡️ **Na estreia, TODO o acervo atual cai no estado declarado
 * abaixo.**
 *
 * ═══ ⚠️ O ESTADO DECLARADO DE IMG-13 ══════════════════════════════════════════════════════════
 *
 * Imagem gerada sem vínculo **não** produz painel vazio, **não** produz zero e **não** repete a
 * imagem de IA no lugar da original. Ela produz `SEM_ORIGINAL`, com **ícone E texto** (VOC-05) e
 * uma frase que diz **o motivo** — "ela é anterior a este registro" —, porque "não disponível"
 * seco parece erro de carregamento.
 *
 * ⚠️ A tela que renderiza esse estado é da **Fase 190**; aqui ficam o nome, o rótulo e a frase,
 * para as duas pontas não escreverem versões diferentes da mesma ausência.
 *
 * ═══ ⚠️ `criativo_id` É CONVENIÊNCIA DE LEITURA, SEM FK ════════════════════════════════════════
 *
 * De `pub_imagens.id` já se chega ao criativo por `ml_anuncio_criativos.pub_imagem_id` (indexado,
 * Fase 165). A coluna é atalho, **não** o caminho, e é **sem FK** por convenção de âncora do módulo
 * **e** porque FK ali seria DDL encostando na tabela do outro dev (T-186-23). Imagem vinda de
 * **upload** não tem criativo — e isso é **correto**: é o que o "N fotos · N geradas por IA"
 * distingue.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class PubImagemReferencia extends Model
{
    protected $table = 'pub_imagem_referencias';

    // ─── ⚠️ O ESTADO DECLARADO DE IMG-13 ────────────────────────────────────────────────────

    /** ⚠️ Chave de dado — identificador, nunca rótulo de tela (VOC-06). */
    public const SEM_ORIGINAL = 'sem_original_de_referencia';

    public const SEM_ORIGINAL_ROTULO = 'Original de referência não disponível';

    public const SEM_ORIGINAL_APOIO = 'A referência desta imagem não foi guardada — ela é anterior a este registro.';

    /** ⚠️ VOC-05 exige ícone **E** texto: sem ícone a ausência parece erro de carregamento. */
    public const SEM_ORIGINAL_ICONE = 'image-off';

    protected $guarded = ['id'];

    /** ⚠️ O Eloquent não lê o default do banco (defeito real medido no plano 186-04). */
    protected $attributes = ['posicao' => 0];

    protected $casts = [
        'posicao' => 'integer',
        'criativo_id' => 'integer',
        'gravado_em' => 'datetime',
    ];

    /** A imagem GERADA. */
    public function imagem(): BelongsTo
    {
        return $this->belongsTo(PubImagem::class, 'imagem_id');
    }

    /**
     * A ORIGINAL de referência — a MESMA tabela, outra linha.
     *
     * ⚠️⚠️ **O NOME DESTA RELAÇÃO NÃO PODE SER `original()`, E ISSO É BUG MEDIDO EM 15/10/2026,
     * NÃO PREFERÊNCIA DE ESTILO.** `Illuminate\Database\Eloquent\Model` tem uma **propriedade
     * protegida `$original`** (os atributos originais do modelo — conferido por
     * `ReflectionClass`). Quando a relação se chama `original`:
     *
     *   · **de FORA da classe** `$v->original` passa por `__get()` e devolve a relação — funciona;
     *   · **de DENTRO da classe** (por exemplo num `map()` dentro de um método estático deste
     *     mesmo modelo) o PHP **enxerga a propriedade protegida** e **não chama `__get()`**:
     *     `$v->original` devolve o **array cru de atributos da própria linha**, silenciosamente.
     *
     * ➡️ Medido: `originaisDe()` devolvia as linhas de `pub_imagem_referencias` **em formato
     * array** em vez das `pub_imagens`, e o painel de comparação mostraria a coisa errada. ⚠️ E o
     * modo de falhar é o pior possível: **um teste escrito de fora passava** e o código de dentro
     * estava errado. Há gate para esta classe de colisão em
     * `VinculoDuravelDaOriginalTest::test_nenhuma_relacao_destes_modelos_colide_com_propriedade_do_eloquent`.
     */
    public function imagemOriginal(): BelongsTo
    {
        return $this->belongsTo(PubImagem::class, 'referencia_imagem_id');
    }

    /**
     * As originais de uma imagem gerada, **na ordem em que foram escolhidas**, e o estado
     * declarado quando não há nenhuma.
     *
     * ⚠️ `estado` é `null` quando há vínculo e `SEM_ORIGINAL` quando não há — **nunca** vazio
     * silencioso, **nunca** zero, e **nunca** a própria imagem gerada no lugar da original
     * (IMG-13). É o caso de TODO o acervo atual na estreia, e devolver a coleção vazia sem o
     * estado deixaria a tela sem como distinguir "não tem" de "não carregou".
     *
     * @return array{originais: Collection<int, PubImagem>, estado: ?string}
     */
    public static function originaisDe(PubImagem|int $imagem): array
    {
        $id = $imagem instanceof PubImagem ? $imagem->id : $imagem;

        $originais = self::query()
            ->where('imagem_id', $id)
            ->with('imagemOriginal')
            ->orderBy('posicao')
            ->orderBy('id')
            ->get()
            ->map(fn (self $v) => $v->imagemOriginal)
            ->filter()
            ->values();

        return [
            'originais' => new Collection($originais->all()),
            'estado' => $originais->isEmpty() ? self::SEM_ORIGINAL : null,
        ];
    }
}
