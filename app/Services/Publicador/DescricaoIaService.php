<?php

namespace App\Services\Publicador;

use App\Jobs\Publicador\GerarDescricaoIaJob;
use App\Models\MlCategoriaSchema;
use App\Models\PubRascunho;
use App\Services\Ia\AnaliseAnuncioService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Descrição do anúncio pelo MAG T8 (Fase 172, D-09/D-11): a ficha do rascunho, as medidas do
 * pacote e a descrição que o cliente escreveu no Portal viram as "especificações" da análise
 * existente (`AnaliseAnuncioService::analise` e depois `::descricao`). O texto dos prompts não
 * muda; só a entrada.
 *
 * A IA leva de segundos a minutos: roda em Job e o resultado fica no cache por pedido, de onde a
 * tela o lê e o aplica pelo caminho normal de edição. Este serviço NUNCA escreve no rascunho
 * (learnings do Publicador §10 — sem segunda escrita concorrente).
 */
class DescricaoIaService
{
    public const TTL_PEDIDO = 1800;

    /** O pedido automático vale uma vez por rascunho: 30 dias. */
    public const TTL_AUTOMATICO = 2592000;

    /** Teto das especificações enviadas à IA (caracteres). */
    public const LIMITE_SPECS = 8000;

    private const MEDIDAS = [
        'SELLER_PACKAGE_HEIGHT' => 'Altura da embalagem',
        'SELLER_PACKAGE_WIDTH' => 'Largura da embalagem',
        'SELLER_PACKAGE_LENGTH' => 'Comprimento da embalagem',
        'SELLER_PACKAGE_WEIGHT' => 'Peso da embalagem',
    ];

    public function __construct(
        private AnaliseAnuncioService $ia,
        private PortalProdutoLeitor $leitor,
    ) {}

    /**
     * Põe o pedido na fila e devolve o id dele. Automático (D-11): só com a descrição do rascunho
     * vazia, com descrição do cliente disponível e uma única vez por rascunho; fora disso, null.
     */
    public function pedir(PubRascunho $r, bool $automatico = false): ?string
    {
        if ($automatico) {
            if (trim((string) $r->descricao) !== '' || $this->descricaoDoCliente($r) === null) {
                return null;
            }
            // `add` é atômico: dois pedidos simultâneos não passam os dois.
            if (! Cache::add(self::chaveAuto($r->id), true, self::TTL_AUTOMATICO)) {
                return null;
            }
        }

        $pedido = (string) Str::uuid();
        Cache::put(self::chave($r->id), ['pedido' => $pedido, 'status' => 'rodando', 'valor' => null, 'erro' => null], self::TTL_PEDIDO);
        GerarDescricaoIaJob::dispatch($r->id, $pedido);

        return $pedido;
    }

    /** O pedido mais recente do rascunho; nulo = nenhum. */
    public function estado(PubRascunho $r): ?array
    {
        $e = Cache::get(self::chave($r->id));

        return is_array($e) ? $e : null;
    }

    /** Roda no Job: grava `pronto` ou `erro` no cache — só se o pedido ainda for o mais recente. */
    public function executar(PubRascunho $r, string $pedido, ?float $prazo = null): void
    {
        try {
            $ia = $prazo !== null ? $this->ia->comPrazo($prazo) : $this->ia;
            $produto = $r->produto;
            $nome = $produto->nomeExibido();
            // Loja como no "Anunciar por IA".
            $loja = $produto->contaOuNula()?->nomeContaMl() ?? ($produto->mlbEmpresa?->nome ?? $produto->company?->name ?? '');
            $specs = $this->specs($r);

            $analise = $ia->analise($nome, $loja, $specs)['dados'];
            $texto = $this->limparDescricao($ia->descricao($nome, $loja, $specs, $analise)['dados']);
            if ($texto === '') {
                throw new \RuntimeException('A IA não devolveu nada aproveitável. Tente de novo.');
            }
            $this->concluir($r->id, $pedido, ['status' => 'pronto', 'valor' => $texto, 'erro' => null]);
        } catch (\Throwable $e) {
            $this->concluir($r->id, $pedido, ['status' => 'erro', 'valor' => null, 'erro' => $e->getMessage()]);

            throw $e;
        }
    }

    /** Marca o pedido como falho (Job que caiu sem passar pelo `executar`). */
    public function falhou(int $rascunhoId, string $pedido, string $mensagem): void
    {
        $this->concluir($rascunhoId, $pedido, ['status' => 'erro', 'valor' => null, 'erro' => $mensagem]);
    }

    /**
     * As especificações que a IA recebe: ficha preenchida, medidas e a descrição do cliente (dado,
     * sob cabeçalho fixo — nunca instrução). Cortada em `LIMITE_SPECS`.
     */
    public function specs(PubRascunho $r): string
    {
        $nomes = $this->nomesDoSchema($r);
        $linhas = [];
        $medidas = [];
        foreach ($r->atributos()->get() as $a) {
            $valor = trim((string) ($a->value_name ?? ''));
            if ($valor === '' && $a->value_number !== null) {
                $valor = rtrim(rtrim(number_format((float) $a->value_number, 4, '.', ''), '0'), '.').($a->value_unit ? ' '.$a->value_unit : '');
            }
            if ($valor === '' && is_array($a->values_multi)) {
                $valor = implode(', ', array_filter(array_map(fn ($v) => trim((string) (is_array($v) ? ($v['name'] ?? $v['value_name'] ?? '') : $v)), $a->values_multi)));
            }
            if ($valor === '') {
                continue;
            }
            if (isset(self::MEDIDAS[$a->attribute_id])) {
                $medidas[self::MEDIDAS[$a->attribute_id]] = $valor;

                continue;
            }
            $linhas[] = ($nomes[$a->attribute_id] ?? $a->attribute_id).': '.$valor;
        }
        foreach ($medidas as $rotulo => $valor) {
            $linhas[] = "{$rotulo}: {$valor}";
        }

        $texto = implode("\n", $linhas);
        $cliente = $this->descricaoDoCliente($r);
        if ($cliente !== null) {
            $texto .= ($texto === '' ? '' : "\n\n")."Descrição fornecida pelo cliente:\n".$cliente;
        }

        return mb_substr($texto, 0, self::LIMITE_SPECS);
    }

    public static function chave(int $rascunhoId): string
    {
        return "publicador:descricao:{$rascunhoId}";
    }

    public static function chaveAuto(int $rascunhoId): string
    {
        return "publicador:descricao:auto:{$rascunhoId}";
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    private function descricaoDoCliente(PubRascunho $r): ?string
    {
        $t = $this->leitor->descricaoDoCliente($r->produto);

        return $t === null || trim($t) === '' ? null : trim($t);
    }

    /** @return array<string, string> id → nome, do schema JÁ guardado (sem HTTP). */
    private function nomesDoSchema(PubRascunho $r): array
    {
        if (! $r->categoria_id) {
            return [];
        }
        $guardado = MlCategoriaSchema::find($r->categoria_id);
        $saida = [];
        foreach ((array) ($guardado?->atributos ?? []) as $a) {
            if (isset($a['id'], $a['name'])) {
                $saida[(string) $a['id']] = (string) $a['name'];
            }
        }

        return $saida;
    }

    /** A IA escreve em HTML simples; a descrição do anúncio é texto puro (mesma regra do IaParaRascunhoService). */
    private function limparDescricao(?string $html): string
    {
        $texto = preg_replace('~</(p|li|h[1-6]|div)>|<br\s*/?>~i', "\n", (string) $html);
        $texto = html_entity_decode(strip_tags((string) $texto), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $texto)));
    }

    private function concluir(int $rascunhoId, string $pedido, array $resultado): void
    {
        $chave = self::chave($rascunhoId);
        $atual = Cache::get($chave);
        // Um pedido mais novo já está na fila: este resultado perdeu a vez.
        if (is_array($atual) && ($atual['pedido'] ?? null) !== $pedido) {
            return;
        }
        Cache::put($chave, ['pedido' => $pedido, ...$resultado], self::TTL_PEDIDO);
    }
}
