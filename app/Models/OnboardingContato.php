<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * OnboardingContato — uma pessoa do lado do CLIENTE dentro de um onboarding:
 * o ponto de contato (§13.2) ou um participante das reuniões (§16).
 *
 * Os dois papéis moram na mesma tabela porque têm shape idêntico; `papel` é o
 * que os separa. Ver o docblock da migration para o porquê de não serem duas
 * tabelas gêmeas.
 *
 * ─── REGRA DE ESCRITA: uma linha por pessoa, SEMPRE por id ─────────────────
 * Toda gravação desta lista é POR LINHA — `create()` para uma pessoa nova,
 * `update()` no registro daquele `id` para editar, `delete()` naquele `id`
 * para remover. **Nunca** apagar tudo e regravar o array inteiro, nunca
 * chavear a lista por nome, e-mail ou qualquer campo digitado pelo cliente.
 *
 * Isto não é preferência de estilo: é o incidente da Precificação
 * (`.planning/learnings/precificacao-onboarding-duas-telas.md` §3). Lá a
 * lista de produtos era um mapa chaveado por SKU e o cliente digitava
 * "Não tenho" no SKU de todos — a cada save, as 11 linhas viravam cópia de
 * uma só e o custo já preenchido desaparecia. **Custo perdido não voltou por
 * código.** Aqui o campo repetido óbvio é o e-mail vazio (dois participantes
 * ainda sem e-mail seriam a "mesma chave"), e o dano seria o mesmo: dois
 * participantes viram um.
 *
 * O `id` autoincremental é a única chave estável desta lista. Toda tela e
 * todo endpoint que edite contatos tem de trafegar esse `id`.
 */
class OnboardingContato extends Model
{
    protected $table = 'onboarding_contatos';

    protected $fillable = [
        'onboarding_id',
        'papel',
        'nome',
        'email',
        'funcao',
        'telefone',
        'principal',
        'criado_por',
    ];

    protected $casts = [
        'principal' => 'boolean',
    ];

    // ─── Catálogo fechado de `papel` ────────────────────────────────────────
    /** Quem responde pela empresa no dia a dia do onboarding (§13.2). */
    public const PAPEL_PONTO_CONTATO = 'ponto_de_contato';

    /** Quem recebe convite para as reuniões, pelo Gmail (§16). */
    public const PAPEL_PARTICIPANTE = 'participante_reuniao';

    public const PAPEIS = [
        self::PAPEL_PONTO_CONTATO,
        self::PAPEL_PARTICIPANTE,
    ];

    public const PAPEL_LABELS = [
        self::PAPEL_PONTO_CONTATO => 'ponto de contato',
        self::PAPEL_PARTICIPANTE  => 'participante das reuniões',
    ];

    // ─── Teto do motivo (ver motivoDentroDoLimite) ──────────────────────────
    /**
     * Largura de `onboarding_passos.ultimo_erro`, que é onde
     * `OnboardingEngineService::aplicarResultado()` grava o `motivo` de todo
     * resultado `nao_coletado`. Espelhado aqui de propósito: é o número que
     * limita o que os dois resolvers podem escrever.
     */
    public const LIMITE_MOTIVO = 255;

    /** Nomes citados antes de a cobrança virar contagem. */
    public const MAX_NOMES_NO_MOTIVO = 4;

    /**
     * Largura de cada nome dentro do motivo. `nome` aceita 120 caracteres e
     * quatro deles já estouram sozinhos o teto de `ultimo_erro` — cortar por
     * nome mantém o "e mais N" do fim, que é a parte que diz o tamanho real
     * do problema.
     */
    public const LIMITE_NOME_NO_MOTIVO = 40;

    public function onboarding(): BelongsTo
    {
        return $this->belongsTo(Onboarding::class);
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    // ─── Scopes ─────────────────────────────────────────────────────────────

    public function scopePontosDeContato(Builder $query): Builder
    {
        return $query->where('papel', self::PAPEL_PONTO_CONTATO);
    }

    public function scopeParticipantes(Builder $query): Builder
    {
        return $query->where('papel', self::PAPEL_PARTICIPANTE);
    }

    // ─── Leitura ────────────────────────────────────────────────────────────

    /**
     * Tem e-mail utilizável. Separado de `filled($this->email)` porque a
     * pergunta se repete nos dois resolvers e porque "tem e-mail" é a régua
     * do §16: participante sem e-mail não recebe convite, logo não conta.
     */
    public function temEmail(): bool
    {
        return filled($this->email);
    }

    /** Rótulo para cobrança nominal na tela ("falta o e-mail de ..."). */
    public function nomeParaCobranca(): string
    {
        return filled($this->nome) ? $this->nome : "contato #{$this->id}";
    }

    /**
     * Lista de nomes para o motivo do resolver, com as pessoas excedentes
     * viradas em contagem.
     *
     * A lista de participantes não tem tamanho máximo e `nome` aceita 120
     * caracteres: citar todo mundo produz um motivo de tamanho arbitrário, e
     * motivo arbitrário não cabe em `ultimo_erro`. Cortar aqui mantém a
     * cobrança legível; quem garante a validade é
     * {@see self::motivoDentroDoLimite()}.
     */
    public static function cobrancaNominal(Collection $contatos): string
    {
        $nomes = $contatos
            ->map(fn (self $contato) => self::encurtar($contato->nomeParaCobranca(), self::LIMITE_NOME_NO_MOTIVO))
            ->values()
            ->all();

        $excedente = count($nomes) - self::MAX_NOMES_NO_MOTIVO;

        if ($excedente > 0) {
            $nomes = array_slice($nomes, 0, self::MAX_NOMES_NO_MOTIVO);
            $nomes[] = "e mais {$excedente}";
        }

        return implode(', ', $nomes);
    }

    /**
     * Garante que o motivo cabe em `onboarding_passos.ultimo_erro`.
     *
     * `ultimo_erro` é `varchar(255)` e as conexões MySQL/MariaDB deste projeto
     * rodam com `strict => true`: string maior não trunca em silêncio, ela
     * **estoura** com SQLSTATE 22001 dentro de `aplicarResultado()`. O passo
     * então cai em `indeterminado` com "Data too long" pela mão do
     * `failed()` do job — ou seja, some justamente a cobrança nominal que o
     * §16 existe para produzir. O SQLite dos testes ignora largura de
     * `varchar` e nunca acusaria isso (learnings §6.5): o corte tem de ser
     * explícito no PHP.
     */
    public static function motivoDentroDoLimite(string $motivo): string
    {
        return self::encurtar($motivo, self::LIMITE_MOTIVO);
    }

    private static function encurtar(string $texto, int $limite): string
    {
        return mb_strlen($texto) <= $limite
            ? $texto
            : mb_substr($texto, 0, $limite - 1).'…';
    }
}
