<?php

namespace App\Services\Publicador;

use App\Jobs\Publicador\GerarPalavrasChaveIaJob;
use App\Models\PubRascunho;
use App\Services\Ia\AnaliseAnuncioService;
use App\Services\Incubadora\Publicador\TermosMaisBuscadosService;
use App\Support\Publicador\RegraViolada;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Termos mais buscados da categoria → campo Modelo e título (melhoria do
 * Publicador de 03/10/2026, `melhoria_publicador.docx` §2 e §3).
 *
 * Os termos vêm do `GET /trends/MLB/{categoria}` (o mesmo serviço da
 * Incubadora). A IA (NVIDIA, `AnaliseAnuncioService`) escolhe os coerentes
 * com o produto; o corte no limite é daqui, porque o modelo erra contagem.
 *
 * A IA leva de segundos a minutos: roda em Job e o resultado fica no cache
 * por pedido (`estado()`), de onde a tela o lê e o aplica pelo caminho normal
 * de edição — nada é gravado no rascunho por trás da pessoa.
 */
class PalavrasChaveService
{
    public const MODELO = 'modelo';

    /** Limite do campo Modelo pedido pela equipe (o ML aceita 255). */
    public const LIMITE_MODELO = 120;

    public const ALVOS = [self::MODELO, 'titulo_gold_special', 'titulo_gold_pro'];

    /** Quantos termos vão para a IA: a lista cheia do ML tem 50. */
    private const TERMOS_PARA_IA = 50;

    private const TTL_PEDIDO = 1800;

    /**
     * Palavras de ligação: não contam como "palavra nova" no Modelo
     * ("puff para sala" com "Sala" no título não traz nada novo).
     */
    private const LIGACAO = ['de', 'da', 'do', 'das', 'dos', 'para', 'pra', 'com', 'sem', 'e', 'em', 'no', 'na', 'a', 'o', 'os', 'as', 'um', 'uma', 'por'];

    public function __construct(
        private TermosMaisBuscadosService $trends,
        private CategorySchemaRepository $schemas,
        private AnaliseAnuncioService $ia,
    ) {}

    /**
     * Os termos da categoria do rascunho, com a pista `relacionado` (o termo
     * tem palavra do nome do produto que não está no caminho da categoria).
     *
     * @throws RegraViolada sem categoria
     * @throws \RuntimeException quando o ML não responde
     */
    public function termos(PubRascunho $r): array
    {
        [$categoria, $caminho] = $this->categoria($r);

        return [
            'categoria' => ['id' => $categoria, 'caminho' => $caminho],
            ...$this->trends->termos($categoria, $r->produto->nomeExibido(), $caminho),
        ];
    }

    /**
     * Põe o pedido na fila e devolve o id dele. `escolhidos` só vale para título;
     * `titulo` (o que está na tela, talvez ainda não salvo) só para o Modelo —
     * ele se SOMA aos títulos ativos gravados, nunca os substitui.
     */
    public function pedir(PubRascunho $r, string $alvo, array $escolhidos = [], ?string $titulo = null): string
    {
        $this->categoria($r);
        $pedido = (string) Str::uuid();
        Cache::put(self::chave($r->id, $alvo), ['pedido' => $pedido, 'status' => 'rodando', 'valor' => null, 'erro' => null], self::TTL_PEDIDO);
        $titulo = $alvo === self::MODELO ? (trim((string) $titulo) ?: null) : null;
        GerarPalavrasChaveIaJob::dispatch($r->id, $alvo, $pedido, array_values(array_slice($escolhidos, 0, 20)), $titulo);

        return $pedido;
    }

    /** O pedido mais recente deste alvo; nulo = nenhum. */
    public function estado(PubRascunho $r, string $alvo): ?array
    {
        $e = Cache::get(self::chave($r->id, $alvo));

        return is_array($e) ? $e : null;
    }

    /** Roda no Job: grava `pronto` ou `erro` — só se o pedido ainda for o mais recente. */
    public function executar(PubRascunho $r, string $alvo, string $pedido, array $escolhidos, ?float $prazo = null, ?string $tituloDaTela = null): void
    {
        try {
            $valor = $alvo === self::MODELO ? $this->modelo($r, $prazo, $tituloDaTela) : $this->titulo($r, substr($alvo, strlen('titulo_')), $escolhidos, $prazo);
            if ($valor === '') {
                throw new \RuntimeException('A IA não devolveu nada aproveitável. Tente de novo.');
            }
            $this->concluir($r->id, $alvo, $pedido, ['status' => 'pronto', 'valor' => $valor, 'erro' => null]);
        } catch (\Throwable $e) {
            $this->concluir($r->id, $alvo, $pedido, ['status' => 'erro', 'valor' => null, 'erro' => $e->getMessage()]);

            throw $e;
        }
    }

    /** Marca o pedido como falho (Job que caiu sem passar pelo `executar`). */
    public function falhou(int $rascunhoId, string $alvo, string $pedido, string $mensagem): void
    {
        $this->concluir($rascunhoId, $alvo, $pedido, ['status' => 'erro', 'valor' => null, 'erro' => $mensagem]);
    }

    public static function chave(int $rascunhoId, string $alvo): string
    {
        return "publicador:palavras:{$rascunhoId}:{$alvo}";
    }

    // ═══ Pós-processamento (puro) ════════════════════════════════════════════

    /**
     * O Modelo no formato "termo, termo, termo": minúsculas, sem acento, sem
     * repetir termo, e cortado no último termo INTEIRO que cabe no limite —
     * nunca no meio de uma palavra.
     *
     * Com `titulo`, sai todo termo que não traz nenhuma palavra de conteúdo
     * nova: o Modelo existe para EXPANDIR a busca, e repetir o que o título já
     * tem desperdiça caractere (pedido do usuário, 08/10/2026). "puff sala" com
     * "Puff ... Sala" no título sai; "puff para quarto infantil" fica, porque
     * "infantil" é novo. O prompt pede o mesmo, mas a IA não obedece sempre.
     */
    public static function ajustarModelo(string $bruto, int $limite = self::LIMITE_MODELO, string $titulo = ''): string
    {
        $partes = preg_split('/[,;\n|]+/', Str::lower(Str::ascii($bruto))) ?: [];
        $doTitulo = self::palavrasDoTitulo($titulo);
        $vistos = [];
        $saida = '';
        foreach ($partes as $p) {
            $termo = trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]+/', ' ', $p)));
            if ($termo === '' || isset($vistos[$termo])) {
                continue;
            }
            if ($doTitulo !== [] && ! self::trazPalavraNova($termo, $doTitulo)) {
                continue;
            }
            $candidato = $saida === '' ? $termo : "{$saida}, {$termo}";
            if (strlen($candidato) > $limite) {
                // Um termo grande demais não fecha a lista: o seguinte pode caber.
                continue;
            }
            $vistos[$termo] = true;
            $saida = $candidato;
        }

        return $saida;
    }

    /**
     * As formas de comparar das palavras do título (ver `formas()`), como chaves.
     *
     * @return array<string, true>
     */
    public static function palavrasDoTitulo(string $titulo): array
    {
        $saida = [];
        foreach (preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($titulo)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $p) {
            foreach (self::formas($p) as $f) {
                $saida[$f] = true;
            }
        }

        return $saida;
    }

    /** O termo tem ao menos uma palavra de conteúdo (fora as de ligação) que o título não tem? */
    public static function trazPalavraNova(string $termo, array $doTitulo): bool
    {
        foreach (preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($termo)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $p) {
            if (in_array($p, self::LIGACAO, true)) {
                continue;
            }
            if (array_intersect_key(array_flip(self::formas($p)), $doTitulo) === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Singular simples para comparar: a palavra, sem o "s" e sem o "es" final.
     * Duas palavras são a mesma quando têm uma forma em comum — "mesas" × "mesa",
     * "cores" × "cor", "chaves" × "chave" — sem dicionário (o mesmo espírito do
     * `relacionado` do `TermosMaisBuscadosService`, que só tira o "s").
     *
     * @return list<string>
     */
    private static function formas(string $p): array
    {
        $formas = [$p];
        if (strlen($p) > 3 && str_ends_with($p, 's')) {
            $formas[] = substr($p, 0, -1);
        }
        if (strlen($p) > 4 && str_ends_with($p, 'es')) {
            $formas[] = substr($p, 0, -2);
        }

        return $formas;
    }

    /**
     * Título limpo: sem os caracteres que o ruleset ECF proíbe e cortado na
     * última palavra inteira que cabe no máximo da categoria.
     */
    public static function ajustarTitulo(string $bruto, int $maximo): string
    {
        $limpo = trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $bruto)));
        if (mb_strlen($limpo) <= $maximo) {
            return $limpo;
        }
        $cortado = mb_substr($limpo, 0, $maximo + 1);

        return trim(mb_substr($cortado, 0, (int) mb_strrpos($cortado, ' ')) ?: mb_substr($limpo, 0, $maximo));
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    private function modelo(PubRascunho $r, ?float $prazo, ?string $tituloDaTela): string
    {
        [$categoria, $caminho] = $this->categoria($r);
        $termos = $this->termosParaIa($categoria, $r, $caminho);
        $titulo = $this->tituloParaModelo($r, $tituloDaTela);
        $ia = $prazo !== null ? $this->ia->comPrazo($prazo) : $this->ia;

        $bruto = $ia->modeloPorTermos($r->produto->nomeExibido(), implode(' > ', $caminho), $termos, self::LIMITE_MODELO, $titulo)['dados'];
        $valor = self::ajustarModelo($bruto, self::LIMITE_MODELO, $titulo);
        if ($valor === '' && $titulo !== '' && self::ajustarModelo($bruto) !== '') {
            throw new \RuntimeException('Todos os termos que a IA sugeriu já estão no título. Tente de novo.');
        }

        return $valor;
    }

    /**
     * O título que o Modelo não deve repetir: os títulos ATIVOS gravados
     * (Clássico e Premium) mais o da tela, sem repetir — lidos aqui, e não só
     * do navegador. Vazio = ainda não há título; o Modelo sai sem o filtro.
     */
    private function tituloParaModelo(PubRascunho $r, ?string $tituloDaTela): string
    {
        $titulos = $r->alvos()->where('ativo', true)->pluck('titulo')->push($tituloDaTela)
            ->map(fn ($t) => trim((string) $t))->filter()->unique()->values();

        return $titulos->implode(' / ');
    }

    private function titulo(PubRascunho $r, string $listingType, array $escolhidos, ?float $prazo): string
    {
        [$categoria, $caminho] = $this->categoria($r);
        $maximo = (int) ($this->schemas->obter($categoria)->settings()['max_title_length'] ?? 60) ?: 60;
        $termos = $this->termosParaIa($categoria, $r, $caminho);
        $ia = $prazo !== null ? $this->ia->comPrazo($prazo) : $this->ia;

        return self::ajustarTitulo($ia->tituloPorTermos($r->produto->nomeExibido(), implode(' > ', $caminho), $termos, $escolhidos, $maximo)['dados'], $maximo);
    }

    /**
     * Os termos que vão para a IA: os relacionados ao produto primeiro (a
     * pista do serviço de trends), depois os demais na ordem do ML. Sem
     * termos (ML fora), a IA trabalha só com o nome do produto.
     *
     * @return list<string>
     */
    private function termosParaIa(string $categoria, PubRascunho $r, array $caminho): array
    {
        try {
            $lista = $this->trends->termos($categoria, $r->produto->nomeExibido(), $caminho)['termos'];
        } catch (\RuntimeException) {
            return [];
        }
        usort($lista, fn ($a, $b) => [(int) $b['relacionado'], $a['posicao']] <=> [(int) $a['relacionado'], $b['posicao']]);

        return array_slice(array_column($lista, 'termo'), 0, self::TERMOS_PARA_IA);
    }

    /** @return array{0: string, 1: list<string>} */
    private function categoria(PubRascunho $r): array
    {
        if (! $r->categoria_id) {
            throw new RegraViolada('V-CAT-01', 'Escolha a categoria do produto antes.');
        }

        return [(string) $r->categoria_id, $this->schemas->obter((string) $r->categoria_id)->caminho()];
    }

    private function concluir(int $rascunhoId, string $alvo, string $pedido, array $resultado): void
    {
        $chave = self::chave($rascunhoId, $alvo);
        $atual = Cache::get($chave);
        // Um pedido mais novo já está na fila: este resultado perdeu a vez.
        if (is_array($atual) && ($atual['pedido'] ?? null) !== $pedido) {
            return;
        }
        Cache::put($chave, ['pedido' => $pedido, ...$resultado], self::TTL_PEDIDO);
    }
}
