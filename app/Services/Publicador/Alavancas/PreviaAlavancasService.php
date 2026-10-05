<?php

namespace App\Services\Publicador\Alavancas;

use App\Jobs\Publicador\ExecutarLoteAlavancaJob;
use App\Models\PubAlavancaEscrita;
use App\Models\User;
use App\Services\Publicador\Alavancas\Acoes\AcaoAlavanca;
use App\Support\Publicador\AlavancasLiberadas;
use App\Support\Publicador\RegraViolada;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * D-04 — prévia assinada e confirmação das escritas das Alavancas.
 * A prévia só LÊ (GET) e assina o que vai ao ML; o confirmar confere a assinatura, queima-a
 * (uso único) e escreve — 1 produto na hora, vários por job de lote. Nenhum caminho daqui fala
 * com o ML sem o EscritorAlavancas (AL166-05).
 */
class PreviaAlavancasService
{
    public function __construct(
        private EscritorAlavancas $escritor,
        private AnaliseAlavancasService $analise,
        private LeitorContaAlavancas $leitor,
    ) {}

    /**
     * A prévia nunca escreve: só GET (leituras das ações e da análise) — D-04.
     *
     * @param  list<array>  $itens
     * @return array{acao: string, liberada: bool, motivo: ?string, regra: ?string,
     *               resumo: array{conta: array, itens: list<array>, avisos: list<string>},
     *               assinatura: ?string, parcial: bool, analise_limitada?: bool}
     */
    public function previa(ContaAlavanca $c, User $u, string $acao, array $itens): array
    {
        $classe = RegistroDeAcoes::classe($acao);
        $this->exigirContagem($itens);
        $normalizados = $this->normalizar($this->validarForma($classe, $itens));

        // Uma leitura em bloco para todos os itens (multiget de 20; cada promoção uma vez só).
        $leituras = LeiturasDaAcao::para($c);
        $leituras->preCarregar($normalizados);

        $linhas = [];
        $pedidos = [];
        $avisos = [];
        foreach ($normalizados as $i => $dados) {
            $a = (new $classe($c, $dados))->usarLeituras($leituras);
            try {
                $resumo = $a->resumo();
            } catch (RegraViolada $e) {
                throw $this->comItem($e, $i, $dados, count($normalizados));
            }
            if (is_array($resumo['analise'] ?? null)) {
                $pedidos[$i] = $resumo['analise'];
            }
            unset($resumo['analise']);
            $linhas[$i] = $resumo;
            foreach ((array) ($resumo['avisos'] ?? []) as $aviso) {
                $avisos[$aviso] = $aviso;
            }
        }

        $parcial = false;
        $limitada = false;
        if ($pedidos !== []) {
            $max = (int) config('publicador.alavancas.limites.itens_analise_previa', 20);
            $limitada = count($pedidos) > $max;
            $indices = array_slice(array_keys($pedidos), 0, $max);
            $pedidosAnalisados = array_map(fn ($i) => $pedidos[$i], $indices);
            // Os produtos já foram lidos em bloco acima: a análise reaproveita, sem novo multiget.
            $lidos = [];
            foreach ($pedidosAnalisados as $p) {
                $id = (string) ($p['item_id'] ?? '');
                if ($id !== '' && ($produto = $leituras->produto($id)) !== null) {
                    $lidos[$id] = $produto;
                }
            }
            $resultado = $this->analise->analisar($c, $pedidosAnalisados, $lidos);
            $parcial = (bool) ($resultado['parcial'] ?? false);
            foreach ($indices as $k => $i) {
                if (isset($resultado['itens'][$k])) {
                    $linhas[$i]['recebe'] = $this->recebe($resultado['itens'][$k]);
                }
            }
            if ($limitada) {
                $avisos['analise_limitada'] = "Análise dos {$max} primeiros produtos; a escrita vale para todos.";
            }
        }

        $liberada = $c->liberada();

        $retorno = [
            'acao' => $acao,
            'liberada' => $liberada,
            'motivo' => $liberada ? null : AlavancasLiberadas::MOTIVO,
            'regra' => $liberada ? null : AlavancasLiberadas::REGRA,
            'resumo' => [
                'conta' => ['nome' => $c->nome, 'chave' => $c->chaveTela, 'nickname' => $this->nickname($c)],
                'itens' => array_values($linhas),
                'avisos' => array_values($avisos),
            ],
            'assinatura' => $liberada
                ? AssinaturaDaPrevia::gerar(AssinaturaDaPrevia::canonico($acao, $normalizados), $c->chaveTela, $u->id)
                : null,
            'parcial' => $parcial,
        ];
        if ($limitada) {
            $retorno['analise_limitada'] = true;
        }

        return $retorno;
    }

    /**
     * Ordem (D-03/D-04): ação conhecida e contagem → TRAVA (com ou sem assinatura) → forma dos itens
     * → assinatura → uso único → escrita.
     *
     * @param  list<array>  $itens
     * @return array{tipo: string, linhas?: list<\App\Models\PubAlavancaEscrita>, escrita?: \App\Models\PubAlavancaEscrita, lote?: string, total?: int}
     */
    public function confirmar(ContaAlavanca $c, User $u, string $acao, array $itens, ?string $assinatura): array
    {
        $classe = RegistroDeAcoes::classe($acao);
        $this->exigirContagem($itens);

        // A trava vem ANTES da assinatura: conta fora da lista nunca lê nem escreve (RECUSADA no histórico).
        if (! $c->liberada()) {
            $linhas = [];
            foreach (array_values($itens) as $item) {
                $linhas[] = $this->escritor->executar(new $classe($c, $this->dadosDaRecusa($item)), $u);
            }

            return ['tipo' => 'recusado', 'linhas' => $linhas];
        }

        $normalizados = $this->normalizar($this->validarForma($classe, $itens));

        if (! AssinaturaDaPrevia::confere($assinatura, AssinaturaDaPrevia::canonico($acao, $normalizados), $c->chaveTela, $u->id)) {
            throw new RegraViolada('ALAV-ASSIN', 'A conferência expirou ou mudou. Refaça a conferência antes de confirmar.');
        }

        // Uso único: a mesma confirmação não escreve duas vezes.
        $validade = (int) config('publicador.alavancas.previa_validade_minutos', 10);
        if (! Cache::add('alavancas:assinatura:'.sha1((string) $assinatura), 1, now()->addMinutes($validade))) {
            throw new RegraViolada('ALAV-ASSIN-USADA', 'Esta confirmação já foi usada.');
        }

        // Nenhum caminho daqui fala com o ML sem o EscritorAlavancas (AL166-05).
        if (count($normalizados) === 1) {
            return ['tipo' => 'unico', 'escrita' => $this->escritor->executar(new $classe($c, $normalizados[0]), $u)];
        }

        // Vários: uma linha PENDENTE por produto e o job (fila high) processa em fatias.
        // WR-BE-02: as linhas nascem todas ou nenhuma; e se o job não entrar na fila, nenhuma fica PENDENTE sem dono.
        $lote = (string) Str::uuid();
        $chaveAssinatura = 'alavancas:assinatura:'.sha1((string) $assinatura);
        try {
            DB::transaction(function () use ($normalizados, $classe, $c, $u, $lote) {
                foreach ($normalizados as $dados) {
                    $this->escritor->abrirLinha(new $classe($c, $dados), $u, $lote);
                }
            });
        } catch (\Throwable $e) {
            // Nada foi aberto nem enviado: devolve a confirmação para a pessoa poder tentar de novo.
            Cache::forget($chaveAssinatura);
            throw $e;
        }

        try {
            ExecutarLoteAlavancaJob::dispatch($lote, $u->id);
        } catch (\Throwable $e) {
            Log::error("[Alavancas] lote {$lote} não entrou na fila: ".$e->getMessage());
            PubAlavancaEscrita::query()->where('lote_uuid', $lote)
                ->where('resultado', PubAlavancaEscrita::PENDENTE)->whereNull('enviado_em')
                ->update([
                    'resultado' => PubAlavancaEscrita::ERRO,
                    'erro_codigo' => 'ALAV-LOTE-FILA',
                    'mensagem' => 'Não consegui colocar o lote na fila de processamento. Nada foi enviado ao Mercado Livre; refaça a conferência e confirme de novo.',
                    'concluido_em' => now(),
                ]);
            Cache::forget($chaveAssinatura);
            throw $e;
        }

        return ['tipo' => 'lote', 'lote' => $lote, 'total' => count($normalizados)];
    }

    /** Round 2 em todo valor numérico não inteiro, em qualquer profundidade (mesma entrada, mesmo canônico). */
    public function normalizar(array $itens): array
    {
        return array_map(fn ($v) => $this->normalizarValor($v), array_values($itens));
    }

    private function normalizarValor(mixed $v): mixed
    {
        if (is_array($v)) {
            return array_map(fn ($x) => $this->normalizarValor($x), $v);
        }
        if (is_float($v)) {
            return round($v, 2);
        }
        if (is_string($v) && is_numeric($v) && ! ctype_digit($v)) {
            return round((float) $v, 2);
        }

        return $v;
    }

    private function exigirContagem(array $itens): void
    {
        $max = (int) config('publicador.alavancas.limites.itens_por_lote', 50);
        if (count($itens) < 1 || count($itens) > $max) {
            throw new RegraViolada('ALAV-LOTE', "Escolha de 1 a {$max} produtos por confirmação.");
        }
    }

    /**
     * @param  class-string<AcaoAlavanca>  $classe
     * @return list<array>
     */
    private function validarForma(string $classe, array $itens): array
    {
        $erros = [];
        $validos = [];
        foreach (array_values($itens) as $i => $item) {
            if (! is_array($item)) {
                $erros["itens.{$i}"] = ['Item inválido.'];

                continue;
            }
            $v = Validator::make($item, $classe::regras());
            if ($v->fails()) {
                foreach ($v->errors()->messages() as $campo => $mensagens) {
                    $erros["itens.{$i}.{$campo}"] = $mensagens;
                }

                continue;
            }
            // WR-BE-03: só o que as regras declaram segue adiante (chave extra não vai ao histórico nem às colunas).
            $validos[] = $item;
        }
        if ($erros !== []) {
            throw ValidationException::withMessages($erros);
        }

        return $validos;
    }

    /** RegraViolada de validar()/resumo() no item N: contexto {item, item_id} e prefixo do MLB com vários itens. */
    private function comItem(RegraViolada $e, int $i, array $dados, int $total): RegraViolada
    {
        $id = $dados['item_id'] ?? null;
        $msg = ($total > 1 && is_string($id) && $id !== '') ? "{$id}: ".$e->getMessage() : $e->getMessage();

        return new RegraViolada($e->regra, $msg, [...$e->contexto, 'item' => $i, 'item_id' => $id]);
    }

    /**
     * Itens de uma confirmação recusada ainda não passaram por regras(): só o que as colunas do
     * histórico aguentam (item_id MLB válido ou nulo; tipo e promoção como texto curto).
     */
    private function dadosDaRecusa(mixed $item): array
    {
        $item = is_array($item) ? $item : [];
        $id = $item['item_id'] ?? null;
        $texto = fn (mixed $v) => (is_string($v) && $v !== '') ? mb_substr($v, 0, 40) : null;

        return array_filter([
            'item_id' => (is_string($id) && preg_match('/^MLB[0-9]{1,17}$/', $id)) ? $id : null,
            'promotion_type' => $texto($item['promotion_type'] ?? null),
            'promotion_id' => $texto($item['promotion_id'] ?? null),
        ], fn ($v) => $v !== null);
    }

    /** O bloco "quanto a loja recebe" que a prévia mostra por item. */
    private function recebe(array $a): array
    {
        $avisos = (array) ($a['avisos'] ?? []);

        return [
            'normal' => $a['recebe_normal']['voce_recebe'] ?? null,
            'promocao' => $a['recebe_promocao']['voce_recebe'] ?? null,
            'estimativa' => (bool) ($a['estimativa'] ?? false),
            'depende_do_carrinho' => (bool) ($a['depende_do_carrinho'] ?? false),
            'frete_conhecido' => ! collect($avisos)->contains(fn ($t) => str_contains((string) $t, 'Sem o frete')),
            'margem' => $a['margem'] ?? null,
            'alertas' => $a['alertas'] ?? [],
            'calculado' => (bool) ($a['calculado'] ?? false),
            'erro' => $a['erro'] ?? null,
        ];
    }

    private function nickname(ContaAlavanca $c): ?string
    {
        try {
            return $this->leitor->ler($c)['nickname'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }
}
