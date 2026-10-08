<?php

namespace App\Services\Publicador;

use App\Jobs\Publicador\GerarExplicacoesDeAtributosJob;
use App\Models\AtributoExplicacao;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * A explicação curta de cada atributo da categoria, para o ícone de informação ao lado do rótulo
 * (pedido do usuário em 08/10/2026: "ao colocar o cursor do mouse em cima pelo menos explicar o que
 * é — isso para tudo, não apenas para siglas").
 *
 * PRIORIDADE (decisão do usuário):
 *  (a) o glossário nosso (`config/publicador_glossario.php`) — siglas e códigos;
 *  (b) o que já está guardado em `atributo_explicacoes` (ML de antes ou IA);
 *  (c) o `tooltip`/`hint` do ML — guardado com origem `ml` na 1ª vez;
 *  (d) nada → um texto montado na hora (nome + unidade/tipo) e a IA é ENFILEIRADA para escrever
 *      uma vez por atributo (`GerarExplicacoesDeAtributosJob`). O `attribute_id` se repete entre
 *      categorias, então o custo fica baixo e o texto, estável.
 *
 * Medido em 08/10: na MLB193945 o ML traz `tooltip` em 20 de 91 atributos e `hint` em 2.
 *
 * `paraPortal` aplica o filtro de SIGILO: o cliente do Portal não pode saber para onde o cadastro
 * vai, então texto que cite mercado/anúncio/publicar/marketplace… cai para o texto montado.
 */
class ExplicacaoDeAtributos
{
    /** Teto do texto escrito por nós e pela IA (o do ML pode chegar a 300, a largura da coluna). */
    public const LIMITE = 220;

    /** Atributos por chamada à IA (uma chamada só, JSON de volta). */
    public const MAX_POR_PEDIDO = 40;

    /** A trava por atributo dura isto: evita enfileirar a mesma geração a cada abertura da tela. */
    public const TRAVA_HORAS = 6;

    private const TRAVA = 'publicador:explicacao:';

    private const COLUNA = 300;

    /**
     * Palavras que entregam o destino do cadastro. Comparadas sem acento e em minúsculas, então
     * `\bmercado\b` (não pega "mercadoria") e sem "ml" — "500 ml" é unidade; a sigla "ML" em
     * caixa-alta tem regra própria (`SIGILO_ML`, no texto original). `loja` só entra na regra da IA
     * (no Portal o cliente TEM loja; citar não revela nada).
     */
    private const SIGILO = '/mercado\s*livre|mercadolivre|\bmercado\b|\bmeli\b|\bmlb|anunci|publica|marketplace|vendedor|seller|plataforma|shopee|amazon|magalu/u';

    private const SIGILO_ML = '/\bML\b/u';

    private const UNIDADES = [
        'cm' => 'centímetros', 'mm' => 'milímetros', 'm' => 'metros', 'km' => 'quilômetros',
        '"' => 'polegadas', 'pol' => 'polegadas', 'in' => 'polegadas',
        'g' => 'gramas', 'kg' => 'quilos', 'mg' => 'miligramas', 't' => 'toneladas',
        'ml' => 'mililitros', 'mL' => 'mililitros', 'l' => 'litros', 'L' => 'litros',
        'W' => 'watts', 'kW' => 'quilowatts', 'V' => 'volts', 'A' => 'ampères', 'mA' => 'miliampères',
        'Ah' => 'ampère-hora', 'mAh' => 'miliampère-hora', 'Hz' => 'hertz', 'dB' => 'decibéis',
        'rpm' => 'rotações por minuto', 'Nm' => 'newton-metro', 'N·m' => 'newton-metro',
        'h' => 'horas', 'min' => 'minutos', 's' => 'segundos', '°' => 'graus', '°C' => 'graus Celsius',
    ];

    /**
     * A explicação de cada atributo, por id.
     *
     * Cada atributo: `id`, `nome` e, quando houver, `tooltip`, `hint` (ou `dica`), `tipo`,
     * `unidades`, `unidade_padrao`, `valores` (lista de `{id, name}`) e `oculto` (atributo que a
     * tela não mostra: recebe texto, mas nunca enfileira IA). `contexto` (nome/caminho da
     * categoria) só ajuda a IA a entender o sentido do campo.
     *
     * @param  list<array<string, mixed>>  $atributos
     * @return array<string, string>
     */
    public function paraAtributos(array $atributos, ?string $contexto = null): array
    {
        $lista = array_values(array_filter(array_map([self::class, 'normalizar'], $atributos)));
        if ($lista === []) {
            return [];
        }

        $glossario = (array) config('publicador_glossario.atributos', []);
        [$salvos, $comBanco] = $this->salvos(array_column($lista, 'id'));

        $saida = [];
        $doMl = [];
        $faltam = [];
        foreach ($lista as $a) {
            $id = $a['id'];
            if (isset($glossario[$id]) && is_string($glossario[$id])) {
                $saida[$id] = $glossario[$id];
            } elseif (isset($salvos[$id])) {
                $saida[$id] = $salvos[$id];
            } elseif (($ml = self::textoDoMl($a)) !== null) {
                $saida[$id] = $ml;
                $doMl[$id] = ['atributo_id' => $id, 'nome' => mb_substr($a['nome'], 0, 160), 'texto' => $ml, 'origem' => AtributoExplicacao::ORIGEM_ML, 'modelo' => null];
            } else {
                $saida[$id] = self::provisorio($a);
                if (! $a['oculto']) {
                    $faltam[] = $a;
                }
            }
        }

        // Sem a tabela (deploy sem migrate) a tela continua: só não guarda nem enfileira.
        if ($comBanco) {
            if ($doMl !== []) {
                $this->inserir(array_values($doMl));
            }
            if ($faltam !== []) {
                $this->enfileirar($faltam, $contexto);
            }
        }

        return $saida;
    }

    /**
     * O mesmo, para a ficha do Portal: texto que entregue o destino do cadastro (sigilo) vira o
     * texto montado; se nem esse passar (nome do atributo cita a plataforma), fica o genérico.
     *
     * @param  list<array<string, mixed>>  $atributos
     * @return array<string, string>
     */
    public function paraPortal(array $atributos, ?string $contexto = null): array
    {
        $porId = [];
        foreach ($atributos as $a) {
            $n = self::normalizar($a);
            if ($n !== null) {
                $porId[$n['id']] = $n;
            }
        }

        $saida = [];
        foreach ($this->paraAtributos(array_values($porId), $contexto) as $id => $texto) {
            if (! self::revelaDestino($texto)) {
                $saida[$id] = $texto;

                continue;
            }
            $montado = self::provisorio($porId[$id]);
            $saida[$id] = self::revelaDestino($montado) ? 'Característica do produto.' : $montado;
        }

        return $saida;
    }

    /** Os campos fixos do editor que não são atributo (estoque…), do glossário. */
    public function camposFixos(): array
    {
        return array_filter((array) config('publicador_glossario.campos', []), 'is_string');
    }

    /**
     * Guarda as respostas da IA que passam na regra. Só os ids pedidos; `insertOrIgnore`: o que já
     * existe (de outra execução ou do ML) não é sobrescrito.
     *
     * @param  array<string, mixed>  $respostas  `{ "ATTR_ID": "explicação" }`
     * @param  list<array<string, mixed>>  $pedidos  os atributos mandados à IA (id, nome…)
     * @return int quantos foram aceitos
     */
    public function guardarDaIa(array $respostas, array $pedidos, ?string $modelo): int
    {
        $linhas = [];
        foreach ($pedidos as $p) {
            $id = (string) ($p['id'] ?? '');
            $texto = self::limparTextoDaIa($respostas[$id] ?? null);
            if ($id === '' || $texto === null) {
                continue;
            }
            $linhas[] = [
                'atributo_id' => $id, 'nome' => mb_substr((string) ($p['nome'] ?? $id), 0, 160), 'texto' => $texto,
                'origem' => AtributoExplicacao::ORIGEM_IA, 'modelo' => $modelo !== null ? mb_substr($modelo, 0, 120) : null,
            ];
        }
        if ($linhas !== []) {
            $this->inserir($linhas);
        }

        return count($linhas);
    }

    // ═══ Regras do texto ════════════════════════════════════════════════════

    /**
     * O texto da IA, se servir: string, sem HTML, até 220 caracteres e NEUTRO (sem citar plataforma,
     * loja, anúncio… — o mesmo texto vai para o Portal). Senão, nulo.
     */
    public static function limparTextoDaIa(mixed $texto): ?string
    {
        if (! is_string($texto)) {
            return null;
        }
        $t = trim(preg_replace('/\s+/u', ' ', $texto));
        $t = trim($t, " \"'“”‘’");
        if ($t === '' || mb_strlen($t) > self::LIMITE || preg_match('/<[^>]*>|&[a-z]+;/i', $t)) {
            return null;
        }
        if (self::revelaDestino($t) || preg_match('/\blojas?\b|\bsite\b|\becommerce\b|e-commerce/u', self::semAcento($t))) {
            return null;
        }

        return $t;
    }

    /** O texto entrega para onde o cadastro vai (mercado, anúncio, publicar, marketplace…)? */
    public static function revelaDestino(string $texto): bool
    {
        return (bool) preg_match(self::SIGILO, self::semAcento($texto)) || (bool) preg_match(self::SIGILO_ML, $texto);
    }

    /**
     * Texto montado na hora, enquanto a IA não escreveu: "Largura do assento, em centímetros.",
     * "É giratória? Responda Sim ou Não." …
     */
    public static function provisorio(array $a): string
    {
        $nome = trim((string) ($a['nome'] ?? '')) ?: (string) ($a['id'] ?? 'Característica');
        $tipo = (string) ($a['tipo'] ?? '');
        $unidade = (string) ($a['unidade_padrao'] ?? '') ?: (string) (($a['unidades'] ?? [])[0] ?? '');

        return match (true) {
            $tipo === 'number_unit' && $unidade !== '' => "{$nome}, em ".(self::UNIDADES[$unidade] ?? $unidade).'.',
            $tipo === 'number' => "{$nome}, em número.",
            $tipo === 'boolean' => "{$nome}? Responda Sim ou Não.",
            $tipo === 'list' => "{$nome}. Escolha na lista a opção que descreve o produto.",
            default => "{$nome} do produto.",
        };
    }

    // ═══ Apoio ══════════════════════════════════════════════════════════════

    /** @return ?array{id: string, nome: string, tooltip: ?string, hint: ?string, tipo: string, unidades: list<string>, unidade_padrao: ?string, valores: list<string>, oculto: bool} */
    private static function normalizar(mixed $a): ?array
    {
        if (! is_array($a) || trim((string) ($a['id'] ?? '')) === '') {
            return null;
        }
        $valores = array_values(array_filter(array_map(
            fn ($v) => is_array($v) ? (string) ($v['name'] ?? $v['nome'] ?? '') : (string) $v,
            array_slice((array) ($a['valores'] ?? []), 0, 8),
        )));

        return [
            'id' => mb_substr(trim((string) $a['id']), 0, 80),
            'nome' => trim((string) ($a['nome'] ?? $a['name'] ?? '')),
            'tooltip' => is_string($a['tooltip'] ?? null) ? $a['tooltip'] : null,
            'hint' => is_string($a['hint'] ?? null) ? $a['hint'] : (is_string($a['dica'] ?? null) ? $a['dica'] : null),
            'tipo' => (string) ($a['tipo'] ?? $a['value_type'] ?? ''),
            'unidades' => array_values(array_map('strval', (array) ($a['unidades'] ?? []))),
            'unidade_padrao' => isset($a['unidade_padrao']) ? (string) $a['unidade_padrao'] : null,
            'valores' => $valores,
            'oculto' => (bool) ($a['oculto'] ?? false),
        ];
    }

    /** O tooltip do ML (o que é) e o hint (como preencher), juntos quando cabem na coluna. */
    private static function textoDoMl(array $a): ?string
    {
        $limpa = fn (?string $t) => $t === null ? '' : trim(preg_replace('/\s+/u', ' ', strip_tags($t)));
        $tooltip = $limpa($a['tooltip']);
        $hint = $limpa($a['hint']);
        if ($tooltip === '' && $hint === '') {
            return null;
        }
        if ($tooltip !== '' && $hint !== '' && mb_strtolower($tooltip) !== mb_strtolower($hint)) {
            $junto = rtrim($tooltip, '.').'. '.$hint;
            if (mb_strlen($junto) <= self::COLUNA) {
                return $junto;
            }
        }

        return mb_substr($tooltip !== '' ? $tooltip : $hint, 0, self::COLUNA);
    }

    /** @return array{0: array<string, string>, 1: bool} os textos guardados e se a tabela respondeu */
    private function salvos(array $ids): array
    {
        try {
            return [AtributoExplicacao::query()->whereIn('atributo_id', $ids)->pluck('texto', 'atributo_id')->all(), true];
        } catch (\Throwable $e) {
            Log::warning('[Publicador] Explicações de atributos indisponíveis (tabela atributo_explicacoes?): '.$e->getMessage());

            return [[], false];
        }
    }

    private function inserir(array $linhas): void
    {
        $agora = now();
        DB::table('atributo_explicacoes')->insertOrIgnore(array_map(
            fn ($l) => $l + ['created_at' => $agora, 'updated_at' => $agora],
            $linhas,
        ));
    }

    /**
     * Enfileira a IA para os atributos sem explicação, até 40 por Job, com uma trava por atributo
     * (`Cache::add`): a tela abre muitas vezes e a mesma geração não pode sair várias vezes.
     *
     * NÃO enfileira com a fila `sync` (testes, máquina sem worker): ali o Job rodaria DENTRO da
     * abertura da tela e a página esperaria a IA (minutos) — a lição do "Sistema não carrega".
     */
    private function enfileirar(array $faltam, ?string $contexto): void
    {
        if (Queue::connection() instanceof SyncQueue) {
            return;
        }

        $livres = array_values(array_filter(
            $faltam,
            fn (array $a) => Cache::add(self::TRAVA.$a['id'], 1, now()->addHours(self::TRAVA_HORAS)),
        ));

        foreach (array_chunk($livres, self::MAX_POR_PEDIDO) as $lote) {
            GerarExplicacoesDeAtributosJob::dispatch(array_map(fn (array $a) => [
                'id' => $a['id'], 'nome' => $a['nome'], 'tipo' => $a['tipo'], 'unidades' => $a['unidades'], 'valores' => $a['valores'],
            ], $lote), $contexto);
        }
    }

    private static function semAcento(string $s): string
    {
        return strtr(mb_strtolower($s), [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e',
            'í' => 'i', 'î' => 'i', 'ì' => 'i', 'ï' => 'i',
            'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ò' => 'o', 'ö' => 'o',
            'ú' => 'u', 'û' => 'u', 'ù' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);
    }
}
