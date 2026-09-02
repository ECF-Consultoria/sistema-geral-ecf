<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * ComercialEntradaController — Fase 138 (COMERC-01/02/03, D-01/D-02/D-06).
 *
 * Casca do módulo Entrada dentro da Área Comercial: lista as empresas em
 * fluxo de entrada (etapas 1 a 4 do §10 — `ETAPA_AGUARDANDO_ADMINISTRATIVO`
 * até `ETAPA_ADMINISTRATIVO_CONCLUIDO`), com os 8 campos mínimos do §2 e as
 * duas pendências (fluxo + cadastro) em chaves separadas (D-11). O
 * checklist dos 8 itens do módulo chega na Fase 139 — nada aqui finge estar
 * pronto.
 *
 * Nasce nesta Task 1 (Plano 138-05) só como casca mínima para a rota e a
 * permissão `comercial.entrada` terem o que resolver; a query do universo e
 * o payload completo chegam na Task 2 do mesmo plano.
 */
class ComercialEntradaController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('Comercial/Entrada', [
            'companies' => [],
        ]);
    }
}
