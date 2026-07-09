<?php

namespace App\Http\Controllers;

use App\Models\BonusFaixa;
use Inertia\Inertia;

/**
 * Phase 21 — Manual do Sistema.
 * Acesso liberado a TODOS os usuários autenticados (sem permission/role).
 * O catálogo de artigos vive no frontend (resources/js/Pages/Manual/artigos.js);
 * este controller apenas passa o slug adiante para o wrapper Show.jsx fazer o lookup.
 *
 * Phase 74 D-24/DESEMP-13 — slug 'desempenho-bonificacao' recebe prop
 * `bonus_faixas` (rows ativas ordenadas) + `metodologia_texto` (parágrafo pt-BR
 * fixo) para render dinâmico no artigo. Sem cache (D-25) — a query roda a cada
 * page load, garantindo que a edição admin em `/desempenho/configuracao` reflete
 * imediatamente no artigo do Manual.
 */
class ManualController extends Controller
{
    public function index()
    {
        return Inertia::render('Manual/Index');
    }

    public function show(string $slug)
    {
        // Props específicas por slug — assembly no backend evita fetch client-side
        // e mantém o componente Manual/Show.jsx wrapper genérico.
        $artigoProps = [];

        if ($slug === 'desempenho-bonificacao') {
            // Phase 74 D-24 · Query direta sem cache (D-25). Colunas explícitas
            // para blindar o payload contra colunas futuras que sejam sensíveis.
            $artigoProps = [
                'bonus_faixas' => BonusFaixa::where('ativo', true)
                    ->orderBy('ordem')
                    ->get(['id', 'slug', 'nome', 'descricao', 'nota_min', 'nota_max', 'ordem', 'ativo']),
                'metodologia_texto' => 'O score de desempenho da equipe Performance é calculado mensalmente a partir de 4 parâmetros — NPS médio, variação de faturamento, variação de margem de contribuição e absenteísmo (em standby). A nota final é a média direta dos 3 primeiros; a faixa de bônus é determinada pela régua abaixo, configurável pela administração.',
            ];
        }

        return Inertia::render('Manual/Show', [
            'slug' => $slug,
            'artigoProps' => $artigoProps,
        ]);
    }
}
