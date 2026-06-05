<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

/**
 * Phase 21 — Manual do Sistema.
 * Acesso liberado a TODOS os usuários autenticados (sem permission/role).
 * O catálogo de artigos vive no frontend (resources/js/Pages/Manual/artigos.js);
 * este controller apenas passa o slug adiante para o wrapper Show.jsx fazer o lookup.
 */
class ManualController extends Controller
{
    public function index()
    {
        return Inertia::render('Manual/Index');
    }

    public function show(string $slug)
    {
        return Inertia::render('Manual/Show', [
            'slug' => $slug,
        ]);
    }
}
