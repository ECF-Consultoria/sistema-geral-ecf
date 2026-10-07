<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kit de criativos de imagem por IA do publicador ML (Fase 161).
 *
 * Molde literal de `MlAnuncioCriativo` (estados, trava anti-loop por tempo,
 * relações) — ver docblock da migration `2026_10_03_090000_...` para a
 * decisão de nomenclatura e cardinalidade.
 *
 * Quick 261007-kit2 (decisão de reunião, 2026-10-07): o tamanho do kit
 * passou de 7 para 2 — a imagem principal (`hero`) e, quando o Product
 * Truth sustenta algum ponto forte ou medida, uma segunda com texto; sem
 * fato que sustente texto, a segunda sai puramente visual. Continua sendo
 * `N` slots (`SLOTS_PADRAO`, configurável), nunca um número fixo no código
 * além da constante.
 *
 * O PORTADOR da referência (o criativo do upload, 160-01) nunca é um slot:
 * ele é apontado por `criativo_referencia_id` e, depois do planejamento,
 * ganha `kit_id` + `slot = 'referencia'` + `slot_indice = NULL` — a
 * agregação por slot (`slots()`) filtra `whereNotNull('slot_indice')` e
 * nunca o confunde com um dos N slots (Decisão 1b do 161-01-PLAN.md).
 */
class MlAnuncioCriativoKit extends Model
{
    protected $table = 'ml_anuncio_criativo_kits';

    public const STATUS_PLANEJANDO = 'planejando';
    public const STATUS_PLANEJADO  = 'planejado';
    public const STATUS_GERANDO    = 'gerando';
    public const STATUS_PARCIAL    = 'parcial';
    public const STATUS_PRONTO     = 'pronto';
    public const STATUS_APROVADO   = 'aprovado';
    public const STATUS_ERRO       = 'erro';

    /** Estados em que ainda vale a pena o front continuar perguntando. */
    public const STATUS_EM_ANDAMENTO = [self::STATUS_PLANEJANDO, self::STATUS_GERANDO];

    /**
     * Fase 165 (D-15, Q7): estados em que um kit do Publicador pode ser
     * RETOMADO ao reabrir a tela — a mesma lista que `planejarKitSobLock()`
     * do assistente antigo usa para achar o kit existente de um rascunho
     * (`MlbAnuncioController.php:~1901`), aqui por `pub_rascunho_id` +
     * `pub_grupo` em vez de `rascunho_id`.
     */
    public const STATUS_RETOMAVEIS = [
        self::STATUS_PLANEJANDO, self::STATUS_PLANEJADO, self::STATUS_GERANDO,
        self::STATUS_PARCIAL, self::STATUS_PRONTO,
    ];

    /**
     * Passou disto em andamento, não vai terminar: vira erro.
     *
     * Conta: planejamento (até ~2 min, é uma chamada de TEXTO) + as ondas de
     * despacho da geração (3 ondas de 15s no default do 161-02, ~45s) + o
     * pior caso de UM asset até esgotar tentativas
     * (`MlAnuncioCriativo::LIMITE_MINUTOS = 12` com 2 tentativas, ~24 min no
     * pior caso absoluto de fila lenta) cabem com folga dentro de 25 min
     * para o caminho comum (as gerações do kit rodam em paralelo, não em
     * série — folga maior ainda com `SLOTS_PADRAO` reduzido a 2);
     * acima disso o kit não vai terminar — travou por definição.
     */
    public const LIMITE_MINUTOS = 25;

    /**
     * Quantidade padrão de slots planejados — override por
     * `config('services.creative.kit.slots')`. Quick 261007-kit2 (decisão
     * de reunião, 2026-10-07): a principal (`hero`) +, quando o Product
     * Truth sustenta algum ponto forte ou medida, uma segunda com texto;
     * sem fato que sustente texto, a segunda sai visual — nunca menos de 2,
     * nunca "gerar só uma" nem "bloquear até ter o fato" (ambos recusados
     * explicitamente pelo usuário). Era 7 até esta decisão (PLAN-01 original
     * da Fase 161, substituído aqui).
     */
    public const SLOTS_PADRAO = 2;

    /**
     * Teto de imagens geradas por kit — override por
     * `config('services.creative.kit.max_imagens')`. Medição do spike
     * (US$ 0,101 por imagem): 2 imagens custam ~US$ 0,20; o dobro (4) é o
     * que impede um kit de custar mais que o previsto mesmo somando
     * regenerações — mesma proporção de antes (quick 261007-kit2 reduziu a
     * base de 7 para 2; o teto acompanha).
     */
    public const MAX_IMAGENS = 4;

    /** Regenerações permitidas por ASSET (um dos slots do kit) — override por config. */
    public const MAX_REGENERACOES_ASSET = 3;

    /**
     * Regenerações permitidas somadas no KIT inteiro — override por config.
     * Quick 261007-kit2: acompanha a redução de `SLOTS_PADRAO` (7→2) na
     * mesma proporção de ~1 regeneração por slot da base (era 7, agora 2) —
     * somado à base de `SLOTS_PADRAO`, fecha o `MAX_IMAGENS` acima (2+2=4).
     */
    public const MAX_REGENERACOES_KIT = 2;

    /**
     * Mínimo de aprovadas RECOMENDADO — nunca mais uma condição para
     * publicar nem para aprovar o kit (quick 261007-kit2, decisão de
     * reunião 2026-10-07: "serão duas imagens geradas por IA e o restante
     * serão imagens reais" — a IA é complemento, nunca trava). Os gates que
     * liam este valor para BLOQUEAR (`CreativeKitPublicacao::conferir()`,
     * `PublicadorCriativoAprovacaoService::aprovarKit()`) foram corrigidos
     * para nunca mais comparar contra ele — inclusive para kits antigos com
     * o valor congelado em 3 (coluna `minimo_aprovadas`, nunca reescrita).
     * O número sobrevive só como informação de tela ("mínimo recomendado").
     * Override por config; valor congelado por kit na coluna
     * `minimo_aprovadas` (não lido do config de novo depois do planejamento).
     */
    public const MINIMO_APROVADAS = 1;

    protected $fillable = [
        'token', 'company_id', 'mlb_empresa_id', 'rascunho_id', 'user_id',
        'criativo_referencia_id',
        'status', 'etapa', 'erro_mensagem',
        'plano', 'plano_origem', 'planner_provider', 'planner_modelo',
        'planner_latencia_ms', 'planner_tentativas',
        'total_slots', 'minimo_aprovadas', 'imagens_geradas', 'regeneracoes',
        'aprovado_por', 'aprovado_em',
        'started_at', 'finished_at',
        // Fase 162 (D-06) — chamadas de juiz somadas no kit (OPS-02) e
        // quantas das `regeneracoes` do kit foram automáticas (VAL-05).
        'validacoes', 'regeneracoes_automaticas',
        // Fase 165 (D-02/D-10) — ponte com o rascunho do Publicador.
        'pub_rascunho_id', 'pub_grupo',
    ];

    protected $casts = [
        'plano'       => 'array',
        'aprovado_em' => 'datetime',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function mlbEmpresa(): BelongsTo
    {
        return $this->belongsTo(MlbEmpresa::class, 'mlb_empresa_id');
    }

    public function rascunho(): BelongsTo
    {
        return $this->belongsTo(MlAnuncioRascunho::class, 'rascunho_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function aprovador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprovado_por');
    }

    public function criativoReferencia(): BelongsTo
    {
        return $this->belongsTo(MlAnuncioCriativo::class, 'criativo_referencia_id');
    }

    /** O rascunho do Publicador (Fase 165) — nulo no caminho do assistente antigo. */
    public function pubRascunho(): BelongsTo
    {
        return $this->belongsTo(PubRascunho::class, 'pub_rascunho_id');
    }

    /** TODOS os criativos ligados ao kit — inclui o portador da referência. */
    public function criativos(): HasMany
    {
        return $this->hasMany(MlAnuncioCriativo::class, 'kit_id');
    }

    /**
     * Só os slots de verdade do kit — o portador (`slot_indice` NULL) nunca
     * entra aqui (Decisão 1b).
     */
    public function slots(): HasMany
    {
        return $this->hasMany(MlAnuncioCriativo::class, 'kit_id')
            ->whereNotNull('slot_indice')
            ->orderBy('slot_indice');
    }

    public function totalSlots(): int
    {
        return $this->slots()->count();
    }

    public function aprovadas(): int
    {
        return $this->slots()->where('status', MlAnuncioCriativo::STATUS_APROVADO)->count();
    }

    public function prontas(): int
    {
        return $this->slots()->where('status', MlAnuncioCriativo::STATUS_PRONTO)->count();
    }

    /**
     * Slots `pronto` cujo `validacao_status` NÃO é `reprovada` nem
     * `pendente` — ou seja: `aprovada`, `indisponivel` ou NULL (dado
     * legado, nunca passou por validação). É o número que de fato PODE
     * subir ao Mercado Livre agora (Fase 162, VAL-04 em lote): aprovar o
     * kit nunca sobe uma imagem reprovada em silêncio.
     *
     * ⚠️ `whereNotIn` sozinho excluiria as linhas NULL (semântica SQL de
     * `NOT IN` com NULL nunca é verdadeira) — por isso o `orWhereNull`.
     */
    public function prontasSemRisco(): int
    {
        return $this->slots()
            ->where('status', MlAnuncioCriativo::STATUS_PRONTO)
            ->where(function ($query) {
                $query->whereNotIn('validacao_status', [
                    MlAnuncioCriativo::VALIDACAO_REPROVADA,
                    MlAnuncioCriativo::VALIDACAO_PENDENTE,
                ])->orWhereNull('validacao_status');
            })
            ->count();
    }

    /** Slots `pronto` reprovados pela validação automática — para a mensagem de recusa. */
    public function reprovadas(): int
    {
        return $this->slots()
            ->where('status', MlAnuncioCriativo::STATUS_PRONTO)
            ->where('validacao_status', MlAnuncioCriativo::VALIDACAO_REPROVADA)
            ->count();
    }

    public function comErro(): int
    {
        return $this->slots()->where('status', MlAnuncioCriativo::STATUS_ERRO)->count();
    }

    /**
     * Recalcula e PERSISTE o status do kit a partir do estado dos slots.
     * `aprovado` é terminal: nunca é recalculado para baixo, mesmo que os
     * slots mudem de estado depois.
     */
    public function recalcularStatus(): string
    {
        if ($this->status === self::STATUS_APROVADO) {
            return self::STATUS_APROVADO;
        }

        $statusDosSlots = $this->slots()->pluck('status');
        $total = $statusDosSlots->count();

        if ($total === 0) {
            return $this->status;
        }

        $pendentes = $statusDosSlots->filter(fn ($s) => $s === MlAnuncioCriativo::STATUS_PENDENTE)->count();
        $rodando   = $statusDosSlots->filter(fn ($s) => $s === MlAnuncioCriativo::STATUS_RODANDO)->count();
        $prontos   = $statusDosSlots->filter(fn ($s) => $s === MlAnuncioCriativo::STATUS_PRONTO)->count();
        $erros     = $statusDosSlots->filter(fn ($s) => $s === MlAnuncioCriativo::STATUS_ERRO)->count();

        $novo = match (true) {
            $rodando > 0           => self::STATUS_GERANDO,
            $pendentes === $total  => self::STATUS_PLANEJADO,
            $erros === $total      => self::STATUS_ERRO,
            $prontos === $total    => self::STATUS_PRONTO,
            ($prontos + $erros) === $total && $prontos > 0 && $erros > 0 => self::STATUS_PARCIAL,
            // Mistura sem rodando (ex.: alguns pendentes, alguns prontos) —
            // ainda em curso, trata como gerando.
            default => self::STATUS_GERANDO,
        };

        if ($novo !== $this->status) {
            $this->update(['status' => $novo]);
        }

        return $novo;
    }

    /**
     * Em andamento há mais de `LIMITE_MINUTOS`? Então nunca vai terminar.
     * Conta do `created_at`, não do `started_at` — mesmo raciocínio de
     * `MlAnuncioCriativo::travada()`.
     */
    public function travado(): bool
    {
        return in_array($this->status, self::STATUS_EM_ANDAMENTO, true)
            && $this->created_at !== null
            && $this->created_at->lt(now()->subMinutes(self::LIMITE_MINUTOS));
    }

    /**
     * Encerra o kit travado como erro, com mensagem dizendo ONDE travou
     * (planejamento x geração), e fecha como erro só os slots ainda em
     * andamento — os já prontos/aprovados ficam intocados.
     */
    public function encerrarSeTravado(): void
    {
        if (! $this->travado()) {
            return;
        }

        $mensagem = $this->status === self::STATUS_PLANEJANDO
            ? 'O planejamento do kit não terminou em ' . self::LIMITE_MINUTOS . ' minutos (worker parado?). Tente novamente.'
            : 'A geração do kit passou de ' . self::LIMITE_MINUTOS . ' minutos sem terminar e foi encerrada. Tente novamente.';

        $this->update([
            'status'        => self::STATUS_ERRO,
            'etapa'         => null,
            'erro_mensagem' => $mensagem,
            'finished_at'   => now(),
        ]);

        $this->slots()
            ->whereIn('status', MlAnuncioCriativo::STATUS_EM_ANDAMENTO)
            ->get()
            ->each(fn (MlAnuncioCriativo $slot) => $slot->update([
                'status'        => MlAnuncioCriativo::STATUS_ERRO,
                'etapa'         => null,
                'erro_mensagem' => 'O kit foi encerrado por tempo limite antes deste slot terminar.',
                'finished_at'   => now(),
            ]));
    }

    /** Teto efetivo de imagens — `MAX_IMAGENS` com override por config. */
    public function maxImagens(): int
    {
        return (int) config('services.creative.kit.max_imagens', self::MAX_IMAGENS);
    }

    public function tetoDeImagensAtingido(): bool
    {
        return $this->imagens_geradas >= $this->maxImagens();
    }

    /** Teto efetivo de regenerações do KIT inteiro — override por config. */
    public function maxRegeneracoesKit(): int
    {
        return (int) config('services.creative.kit.max_regeneracoes_kit', self::MAX_REGENERACOES_KIT);
    }

    /** Teto efetivo de regenerações por ASSET (um dos slots do kit) — override por config. */
    public function maxRegeneracoesAsset(): int
    {
        return (int) config('services.creative.kit.max_regeneracoes_asset', self::MAX_REGENERACOES_ASSET);
    }

    /**
     * Respeita os dois tetos: o do ASSET e o do KIT, ambos contados por
     * `regeneracoes` — coluna de CLIQUE do operador (161-03), nunca
     * `tentativas` (essa também sobe em retentativa automática do Laravel,
     * `GerarCriativoIaJob::$tries = 2`, sem nenhum clique — contaria
     * retentativa de provedor como regeneração manual; ver docblock da
     * migration `..._add_regeneracoes_...`).
     */
    public function podeRegenerarAsset(MlAnuncioCriativo $asset): bool
    {
        if ($this->tetoDeImagensAtingido()) {
            return false;
        }

        if ($this->regeneracoes >= $this->maxRegeneracoesKit()) {
            return false;
        }

        return $asset->regeneracoes < $this->maxRegeneracoesAsset();
    }

    /** Quantas regenerações o ASSET ainda tem — nunca negativo. */
    public function regeneracoesRestantesAsset(MlAnuncioCriativo $asset): int
    {
        return max(0, $this->maxRegeneracoesAsset() - $asset->regeneracoes);
    }

    /**
     * `regeneracoes_automaticas` é SUBCONJUNTO de `regeneracoes`, nunca soma
     * paralela (Fase 162, Plano 03, VAL-05): toda regeneração automática do
     * `ValidarCriativoIaJob` incrementa as DUAS colunas na mesma transação.
     * Esta diferença — o que sobrou depois de tirar as automáticas — é
     * regeneração por CLIQUE do operador, e é o que separa decisão do juiz
     * de decisão humana na métrica de "média de regenerações por kit"
     * (§19 / Fase 163).
     */
    public function regeneracoesManuais(): int
    {
        return max(0, $this->regeneracoes - $this->regeneracoes_automaticas);
    }

    /** Mensagem pt-BR do motivo do teto, para a tela — `null` quando não há teto batendo. */
    public function motivoDoTeto(): ?string
    {
        if ($this->tetoDeImagensAtingido()) {
            return "Este kit já gerou o máximo de {$this->maxImagens()} imagens permitido.";
        }

        if ($this->regeneracoes >= $this->maxRegeneracoesKit()) {
            return "Este kit já atingiu o limite de {$this->maxRegeneracoesKit()} regenerações.";
        }

        return null;
    }

    /**
     * Mensagem pt-BR do teto que bloqueou a regeneração DESTE asset —
     * tenta primeiro os tetos do KIT (`motivoDoTeto()`, que valem para
     * qualquer slot) e só depois o teto individual do asset. `null` quando
     * `podeRegenerarAsset()` seria `true`.
     */
    public function motivoDoTetoAsset(MlAnuncioCriativo $asset): ?string
    {
        $motivoDoKit = $this->motivoDoTeto();
        if ($motivoDoKit !== null) {
            return $motivoDoKit;
        }

        if ($asset->regeneracoes >= $this->maxRegeneracoesAsset()) {
            return "Este slot já atingiu o limite de {$this->maxRegeneracoesAsset()} regenerações.";
        }

        return null;
    }

    /**
     * Fase 165 (D-15, Q7): o kit RETOMÁVEL de um (`pub_rascunho_id`,
     * `pub_grupo`) do Publicador — no máximo um kit ativo por par, e reabrir
     * o painel retoma o kit existente em vez de planejar outro. Filtra por
     * `pub_rascunho_id` (indexado) e `STATUS_RETOMAVEIS` no SQL, e compara
     * `pub_grupo` em PHP com `===` — a collation `_ci` do MariaDB casaria
     * "txt:M" com "txt:m" num `where('pub_grupo', $grupo)` (learnings de
     * bonificação §9, WR-B05), e `pub_grupo` não tem índice para a
     * comparação SQL valer a pena.
     */
    public static function retomavelDoPublicador(int $pubRascunhoId, string $grupo): ?self
    {
        return static::where('pub_rascunho_id', $pubRascunhoId)
            ->whereIn('status', self::STATUS_RETOMAVEIS)
            ->orderByDesc('id')
            ->get()
            ->first(fn (self $kit) => $kit->pub_grupo === $grupo);
    }

    /**
     * Fase 165 (D-15): o último kit APROVADO de um (`pub_rascunho_id`,
     * `pub_grupo`) — molde literal de `retomavelDoPublicador()`, mesma razão
     * para a comparação de `pub_grupo` em PHP com `===`.
     */
    public static function ultimoAprovadoDoPublicador(int $pubRascunhoId, string $grupo): ?self
    {
        return static::where('pub_rascunho_id', $pubRascunhoId)
            ->where('status', self::STATUS_APROVADO)
            ->orderByDesc('id')
            ->get()
            ->first(fn (self $kit) => $kit->pub_grupo === $grupo);
    }
}
