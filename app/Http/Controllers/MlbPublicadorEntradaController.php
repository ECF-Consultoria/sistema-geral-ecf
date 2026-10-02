<?php

namespace App\Http\Controllers;

use App\Services\Publicador\ProgramasPublicadorService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Tela A do Publicador (Fase 160): entrada em /mlb/anuncios, por programa
 * (Polos · Incubadora · Gestão). Só admins — o grupo de rotas aplica role:admin (D17).
 */
class MlbPublicadorEntradaController extends Controller
{
    private const POR_PAGINA = 50;
    private const FILTROS = ['todos', 'prontos', 'atencao', 'nunca'];

    public function __construct(private ProgramasPublicadorService $programas) {}

    public function index(Request $request)
    {
        $programa = (string) $request->query('programa', 'polos');
        if (! in_array($programa, ProgramasPublicadorService::PROGRAMAS, true)) {
            $programa = 'polos';
        }

        $busca = mb_substr(trim((string) $request->query('busca', '')), 0, 120);
        $filtro = (string) $request->query('filtro', 'todos');
        if (! in_array($filtro, self::FILTROS, true)) {
            $filtro = 'todos';
        }

        $todas = $this->programas->empresas($programa);
        $indicadores = $this->programas->indicadores($programa, $todas);

        $linhas = $todas
            ->when($busca !== '', fn ($c) => $c->filter(fn ($l) => mb_stripos($l['nome'], $busca) !== false
                || mb_stripos($l['identificador'], $busca) !== false))
            ->when($filtro === 'prontos', fn ($c) => $c->filter(fn ($l) => $l['prontos'] > 0))
            ->when($filtro === 'atencao', fn ($c) => $c->filter(fn ($l) => $l['token'] !== 'ativo'))
            ->when($filtro === 'nunca', fn ($c) => $c->filter(fn ($l) => $l['portal']['situacao'] === 'nunca'))
            ->sortBy('nome', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $total = $linhas->count();
        $pagina = max(1, (int) $request->query('pagina', 1));
        $pagina = min($pagina, max(1, (int) ceil($total / self::POR_PAGINA)));
        $deslocamento = ($pagina - 1) * self::POR_PAGINA;
        $pagBase = $linhas->slice($deslocamento, self::POR_PAGINA)->values();

        return Inertia::render('Mlb/AnunciosEmpresas', [
            'programa' => $programa,
            'programas' => $this->programas->contagens(),
            'indicadores' => $indicadores,
            'empresas' => $pagBase,
            'paginacao' => [
                'pagina' => $pagina,
                'por_pagina' => self::POR_PAGINA,
                'total' => $total,
                'de' => $total === 0 ? 0 : $deslocamento + 1,
                'ate' => $deslocamento + $pagBase->count(),
            ],
            'filtros' => ['busca' => $busca, 'filtro' => $filtro],
        ]);
    }
}
