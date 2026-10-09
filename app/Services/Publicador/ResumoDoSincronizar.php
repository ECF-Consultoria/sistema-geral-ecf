<?php

namespace App\Services\Publicador;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Resumo agregado de um clique em "Sincronizar do Portal" (Fase 172-12, D-05).
 *
 * Cada produto preenchido por um Job grava o resumo dele no cache; a tela lê o total por
 * `pedido`. O índice do pedido guarda a empresa que clicou: outra empresa não lê o resumo (T-172-42).
 * Nada aqui toca o ML; é só cache (TTL de 1 hora). Os `avisos` seguem no JSON, mas a tela não os
 * mostra (09/10/2026): o servidor os registra no log (`[Publicador] Sincronizar avisos`).
 */
class ResumoDoSincronizar
{
    private const TTL_S = 3600;

    private const MAX_AVISOS = 20;

    /** Abre o pedido para os produtos que serão preenchidos e devolve o uuid. */
    public function abrir(int $companyId, array $ids): string
    {
        $pedido = (string) Str::uuid();
        Cache::put($this->chave($pedido), ['company_id' => $companyId, 'ids' => array_values($ids), 'em' => now()->toIso8601String()], self::TTL_S);

        return $pedido;
    }

    /** Grava o resumo de UM produto (idempotente: reexecução do Job só sobrescreve o próprio). */
    public function registrar(string $pedido, int $produtoId, array $resumo): void
    {
        Cache::put($this->chave($pedido, $produtoId), $resumo, self::TTL_S);
    }

    /**
     * Soma os resumos já registrados. null = pedido inexistente OU de outra empresa.
     *
     * @return ?array{status: string, total: int, concluidos: int, produtos: int, variantes: int, fotos_trazidas: int, fotos_nao_trazidas: array<string, int>, campos_preenchidos: int, campos_mantidos: int, campos_atualizados: int, avisos: list<string>}
     */
    public function ler(string $pedido, int $companyId): ?array
    {
        $indice = Cache::get($this->chave($pedido));
        if (! is_array($indice) || (int) ($indice['company_id'] ?? 0) !== $companyId) {
            return null;
        }

        $r = ['status' => 'preenchendo', 'total' => count($indice['ids']), 'concluidos' => 0, 'produtos' => 0, 'variantes' => 0,
            'fotos_trazidas' => 0, 'fotos_nao_trazidas' => [], 'campos_preenchidos' => 0, 'campos_mantidos' => 0, 'campos_atualizados' => 0, 'avisos' => []];

        foreach ($indice['ids'] as $id) {
            $p = Cache::get($this->chave($pedido, (int) $id));
            if (! is_array($p)) {
                continue;
            }
            $r['concluidos']++;
            if (($p['rascunho_id'] ?? null) !== null) {
                $r['produtos']++;
            }
            $r['variantes'] += (int) ($p['variantes'] ?? 0);
            $r['fotos_trazidas'] += (int) ($p['fotos_trazidas'] ?? 0);
            $r['campos_preenchidos'] += (int) ($p['campos_preenchidos'] ?? 0);
            $r['campos_mantidos'] += (int) ($p['campos_mantidos'] ?? 0);
            $r['campos_atualizados'] += (int) ($p['campos_atualizados'] ?? 0);
            foreach (($p['fotos_nao_trazidas'] ?? []) as $motivo => $n) {
                $r['fotos_nao_trazidas'][$motivo] = ($r['fotos_nao_trazidas'][$motivo] ?? 0) + (int) $n;
            }
            foreach (($p['avisos'] ?? []) as $aviso) {
                if (count($r['avisos']) < self::MAX_AVISOS && ! in_array($aviso, $r['avisos'], true)) {
                    $r['avisos'][] = $aviso;
                }
            }
        }
        if ($r['concluidos'] >= $r['total']) {
            $r['status'] = 'pronto';
        }

        return $r;
    }

    private function chave(string $pedido, ?int $produtoId = null): string
    {
        return 'publicador:sincronizar:'.$pedido.($produtoId !== null ? ':'.$produtoId : '');
    }
}
