<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OnboardingAgenda — a REGRA das reuniões recorrentes combinada no onboarding
 * (§14): dia da semana, horário e periodicidade. Uma linha por onboarding.
 *
 * ─── Não confundir com a reunião de kickoff ────────────────────────────────
 * As colunas `reuniao_*` de {@see Onboarding} são a reunião ÚNICA de kickoff,
 * com data e hora absolutas (`reuniao_agendada_para`), que o cliente solicita
 * e o responsável marca. Aqui não há data nenhuma: há "toda terça às 14h, de
 * 15 em 15 dias", válido para o resto do contrato. Evento pontual e regra
 * perpétua são coisas diferentes — definir a agenda NÃO mexe nas `reuniao_*`,
 * e ler uma no lugar da outra dá resposta errada. Ver o docblock da migration
 * `2026_08_20_140000_create_onboarding_agendas_table.php`.
 *
 * ─── Sem ocorrências e sem Google Calendar ─────────────────────────────────
 * Esta tabela guarda a regra, nunca as datas geradas a partir dela. E não
 * cria evento no Google Agenda: o OAuth do repo tem só o escopo
 * `calendar.readonly` ({@see \App\Services\GoogleCalendarService}), e trocar
 * o escopo forçaria reconsentimento de todos os usuários já conectados.
 */
class OnboardingAgenda extends Model
{
    protected $table = 'onboarding_agendas';

    protected $fillable = [
        'onboarding_id',
        'dia_semana',
        'horario',
        'periodicidade',
        'observacoes',
        'definida_em',
        'definida_por',
    ];

    protected $casts = [
        'dia_semana'  => 'integer',
        'definida_em' => 'datetime',
    ];

    /**
     * A frase pronta viaja no payload, junto com o horário curto.
     *
     * Sem isto, `toArray()` devolve só as colunas cruas e a tela não tem outra
     * saída senão remontar a string em JavaScript — exatamente a terceira
     * cópia que {@see self::getAgendaLegivelAttribute()} existe para evitar.
     * Acessor que não é `$appends` só serve a quem já tem o objeto PHP na mão.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'horario_curto',
        'agenda_legivel',
    ];

    // ─── Catálogo fechado de `periodicidade` ────────────────────────────────
    public const PERIODICIDADE_QUINZENAL = 'quinzenal';

    /**
     * Só `quinzenal`, de propósito.
     *
     * O documento §14 pede uma agenda quinzenal e nada mais. Cadastrar
     * `semanal` e `mensal` agora encheria o select da tela de opções que
     * ninguém combinou com o cliente e criaria dois ramos de código nunca
     * exercitados — o formato legível de cada um, por exemplo, tem que ser
     * decidido junto com quem vai lê-lo, não chutado aqui. A extensão fica
     * barata: é uma constante e uma linha em cada um destes dois arrays,
     * sem migration nenhuma, porque a coluna é varchar(20) e não enum.
     *
     * @var array<int, string>
     */
    public const PERIODICIDADES = [
        self::PERIODICIDADE_QUINZENAL,
    ];

    /** @var array<string, string> */
    public const PERIODICIDADE_LABELS = [
        self::PERIODICIDADE_QUINZENAL => 'Quinzenal',
    ];

    /**
     * Dias da semana em ISO-8601: 1 = segunda … 7 = domingo.
     *
     * É o `dayOfWeekIso` do Carbon, NÃO o `dayOfWeek` (0 = domingo … 6 =
     * sábado). Trocar um pelo outro desloca a semana inteira sem erro nenhum.
     *
     * @var array<int, string>
     */
    public const DIAS_SEMANA = [
        1 => 'segunda-feira',
        2 => 'terça-feira',
        3 => 'quarta-feira',
        4 => 'quinta-feira',
        5 => 'sexta-feira',
        6 => 'sábado',
        7 => 'domingo',
    ];

    /**
     * Os três campos que a agenda precisa ter para valer como combinada, com
     * o rótulo pt-BR que aparece no motivo do resolver e na tela.
     *
     * @var array<string, string>
     */
    public const CAMPOS_OBRIGATORIOS = [
        'dia_semana'    => 'dia da semana',
        'horario'       => 'horário',
        'periodicidade' => 'periodicidade',
    ];

    public function onboarding(): BelongsTo
    {
        return $this->belongsTo(Onboarding::class);
    }

    public function definidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'definida_por');
    }

    /**
     * Normaliza o horário para `H:i:s` na gravação.
     *
     * MariaDB normaliza sozinho o que entra numa coluna TIME; o SQLite dos
     * testes guarda a string crua. Sem isto, "14:00" volta `14:00` no teste e
     * `14:00:00` em produção, e qualquer comparação exata passa num e falha
     * no outro. Valor fora do formato é gravado como veio, para o banco
     * reclamar em vez de a gente perder o dado em silêncio — validar entrada
     * é trabalho do controller.
     */
    public function setHorarioAttribute(?string $valor): void
    {
        if ($valor === null || $valor === '') {
            $this->attributes['horario'] = null;

            return;
        }

        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $valor, $partes) !== 1) {
            $this->attributes['horario'] = $valor;

            return;
        }

        $this->attributes['horario'] = sprintf(
            '%02d:%02d:%02d',
            (int) $partes[1],
            (int) $partes[2],
            (int) ($partes[3] ?? 0)
        );
    }

    /** Os três campos combinados, todos com valor do catálogo. */
    public function completa(): bool
    {
        return $this->camposPendentes() === [];
    }

    /**
     * Rótulos pt-BR do que ainda falta combinar, na ordem do formulário.
     *
     * @return array<int, string>
     */
    public function camposPendentes(): array
    {
        return collect(self::CAMPOS_OBRIGATORIOS)
            ->reject(fn (string $rotulo, string $campo) => $this->campoCombinado($campo))
            ->values()
            ->all();
    }

    /**
     * Preenchido NÃO basta: o valor tem que ser do catálogo.
     *
     * O passo da agenda é `auto_fonte`, e `auto_fonte` existe justamente para
     * recusar conclusão manual (D-19) — o único caminho para fechá-lo é o dado
     * real. Aceitar `dia_semana = 9`, `periodicidade = 'trimestral'` ou
     * `horario = '25:00'` devolve essa porta pelos fundos: o passo fecharia
     * sozinho exibindo "Quinzenal, dia 9 às 25:00", que é a mesma meia-verdade
     * que {@see self::getAgendaLegivelAttribute()} recusa a produzir. E o
     * banco não segura nada disso: `dia_semana` é tinyint, `periodicidade` é
     * varchar (nunca enum, learnings §6) e o TIME do MariaDB é um tipo de
     * DURAÇÃO — aceita `25:00:00` sem reclamar.
     *
     * A validação de entrada continua sendo trabalho do controller; esta é a
     * última barreira, a que decide se o passo pode fechar.
     */
    private function campoCombinado(string $campo): bool
    {
        if (! filled($this->{$campo})) {
            return false;
        }

        return match ($campo) {
            'dia_semana'    => array_key_exists((int) $this->dia_semana, self::DIAS_SEMANA),
            'periodicidade' => in_array($this->periodicidade, self::PERIODICIDADES, true),
            'horario'       => $this->horarioValido(),
            default         => true,
        };
    }

    /** Hora do relógio de verdade — 0–23h, 0–59min, 0–59s. */
    private function horarioValido(): bool
    {
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', (string) $this->horario, $partes) !== 1) {
            return false;
        }

        return (int) $partes[1] <= 23
            && (int) $partes[2] <= 59
            && (int) ($partes[3] ?? 0) <= 59;
    }

    /**
     * Horário no formato curto `H:i`, sem os segundos que ninguém combinou.
     */
    public function getHorarioCurtoAttribute(): ?string
    {
        if (! filled($this->horario)) {
            return null;
        }

        if (preg_match('/^(\d{1,2}):(\d{2})/', (string) $this->horario, $partes) !== 1) {
            return (string) $this->horario;
        }

        return sprintf('%02d:%02d', (int) $partes[1], (int) $partes[2]);
    }

    /**
     * A agenda em uma linha pt-BR — "Quinzenal, terça-feira às 14:00".
     *
     * Mora aqui, e não na tela, para que o painel interno, o portal do cliente
     * e o valor do passo digam exatamente a mesma frase; três remontagens da
     * mesma string divergem na primeira vez que alguém mexer em uma delas.
     * Devolve `null` enquanto a agenda estiver incompleta — meia frase
     * ("Quinzenal, às 14:00", sem dia) parece agenda combinada e não é.
     */
    public function getAgendaLegivelAttribute(): ?string
    {
        if (! $this->completa()) {
            return null;
        }

        // Sem fallback para o dia: `completa()` já garantiu que o valor está
        // em DIAS_SEMANA. O fallback antigo ("dia 9") era o que transformava
        // lixo em frase de agenda combinada. Na periodicidade o `??` fica,
        // porque ali o risco é outro e inofensivo — alguém acrescenta a
        // constante em PERIODICIDADES e esquece o rótulo em LABELS.
        $periodicidade = self::PERIODICIDADE_LABELS[$this->periodicidade] ?? ucfirst((string) $this->periodicidade);
        $dia = self::DIAS_SEMANA[(int) $this->dia_semana];

        return "{$periodicidade}, {$dia} às {$this->horario_curto}";
    }
}
