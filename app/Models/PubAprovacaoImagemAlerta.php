<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma **verificação automática** sobre uma foto da revisão (Fase 186, DADO-06): severidade,
 * texto em pt-BR pronto para a tela e a **coordenada do ponto apontado**.
 *
 * ⚠️ **`x`/`y` são PERCENTUAL da imagem (0,00–100,00), não pixel** — é o que permite desenhar
 * o marcador sobre a foto em qualquer tamanho de tela. O protótipo só manda x/y quando a
 * severidade é diferente de `ok`, então as duas colunas são anuláveis: alerta `ok` é um
 * veredicto sobre a foto inteira, sem ponto.
 *
 * ⚠️ **A FOLGA DO DECIMAL E O MODO DE FALHAR ASSIMÉTRICO.** A coluna é `decimal(5,2)`, teto
 * **999,99** — 10× a faixa esperada. Estourar a precisão dá **erro 1264 no MySQL/MariaDB** e
 * **grava truncado em silêncio no SQLite dos testes**: a suíte não pega. Por isso a faixa está
 * escrita aqui e na migration, e não só no código que grava.
 */
class PubAprovacaoImagemAlerta extends Model
{
    protected $table = 'pub_aprovacao_imagem_alertas';

    // ─── As 3 severidades (coluna `severidade`, string(4), sem enum por MIG-04) ───
    public const OK = 'ok';
    public const MEDIO = 'med';
    public const ALTO = 'high';

    /** De onde veio o veredicto; hoje só a verificação automática. */
    public const ORIGEM_AUTOMATICA = 'automatica';

    protected $guarded = ['id'];

    protected $casts = [
        'aprovacao_imagem_id' => 'integer',
        'x' => 'float',
        'y' => 'float',
    ];

    public function aprovacaoImagem(): BelongsTo
    {
        return $this->belongsTo(PubAprovacaoImagem::class, 'aprovacao_imagem_id');
    }

    /** `ok` não é alerta: é a verificação que passou. */
    public function ehAlerta(): bool
    {
        return $this->severidade !== self::OK;
    }

    /** Tem ponto a desenhar sobre a foto? */
    public function temPonto(): bool
    {
        return $this->x !== null && $this->y !== null;
    }
}
