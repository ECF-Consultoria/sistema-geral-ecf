<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um critério do **checklist de fidelidade** de uma foto de IA (Fase 186, DADO-07): a pessoa
 * diz, critério por critério, se a imagem gerada **confere** com o produto real ou **diverge**.
 *
 * ⚠️⚠️ **NÃO HÁ ENUM COMPARTILHADO ENTRE PHP E JS NESTE PROJETO** — a sincronia é MANUAL e é
 * a convenção declarada no `CLAUDE.md` ("No shared enum type between PHP and JS — kept in sync
 * manually"). As 7 chaves e os 7 rótulos abaixo são o espelho de `CRITERIOS` em
 * `resources/js/Components/Mlb/Publicador/aprovacao/vocabularioDoPortao.jsx:182-190`, copiados
 * byte a byte em 14/10/2026. **O teste do plano 186-09 confere as duas listas uma contra a
 * outra** — é ele que pega a divergência se alguém mexer num lado só. Mexer aqui sem mexer lá
 * (ou vice-versa) não quebra nada em tempo de execução, e é exatamente por isso que o teste
 * cruzado existe.
 *
 * ⚠️ **`quantidade` ("Quantidade do kit") só se aplica em COMBO**, e a regra é do SERVIÇO, não
 * do schema: o banco aceita a linha, quem decide é `aplicavel()`. "Unidade" e "Combo ×N" são
 * produtos DIFERENTES (`pub_produtos` próprio, `quantidade_kit >= 2`), logo rascunhos
 * diferentes e fotos próprias — a flag de combo não alcança esta tabela sem um join de 3
 * saltos, e CHECK com join não existe em MariaDB 10.4.
 */
class PubAprovacaoFidelidade extends Model
{
    protected $table = 'pub_aprovacao_fidelidade';

    // ─── Os 2 veredictos (coluna `resultado`, string(8), sem enum por MIG-04) ───
    public const CONFERE = 'confere';
    public const DIVERGE = 'diverge';

    /**
     * Os 7 critérios: chave de dado → rótulo pt-BR da tela.
     *
     * Espelho manual de `CRITERIOS` do `vocabularioDoPortao.jsx` (ver docblock da classe).
     */
    public const CRITERIOS = [
        'cor' => 'Cor',
        'formato' => 'Formato',
        'material' => 'Material',
        'textura' => 'Textura',
        'proporcoes' => 'Proporções',
        'quantidade' => 'Quantidade do kit',
        'acessorios' => 'Acessórios e componentes',
    ];

    /** O único critério que depende do formato do produto. */
    public const CRITERIO_SO_DE_COMBO = 'quantidade';

    protected $guarded = ['id'];

    protected $casts = [
        'aprovacao_imagem_id' => 'integer',
        'quem_id' => 'integer',
        'quando' => 'datetime',
    ];

    public function aprovacaoImagem(): BelongsTo
    {
        return $this->belongsTo(PubAprovacaoImagem::class, 'aprovacao_imagem_id');
    }

    public function quem(): BelongsTo
    {
        return $this->belongsTo(User::class, 'quem_id');
    }

    /** O rótulo em pt-BR da tela; chave desconhecida devolve a própria chave, nunca vazio. */
    public function rotulo(): string
    {
        return self::CRITERIOS[$this->criterio] ?? (string) $this->criterio;
    }

    public function confere(): bool
    {
        return $this->resultado === self::CONFERE;
    }

    /**
     * "Quantidade do kit" só se pergunta em combo; os outros 6 valem sempre.
     * A regra é do SERVIÇO — o schema aceita a linha de qualquer forma.
     */
    public function aplicavel(bool $ehCombo): bool
    {
        return $this->criterio !== self::CRITERIO_SO_DE_COMBO || $ehCombo;
    }
}
