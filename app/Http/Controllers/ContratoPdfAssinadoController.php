<?php

namespace App\Http\Controllers;

use App\Models\ContratoAssinatura;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ContratoPdfAssinadoController — Fase 129 plano 06 (CLICK-11, D-13).
 *
 * O PDF assinado é evidência jurídica e vive em disco privado
 * (`Storage::disk('local')`, `storage/app`) — NUNCA no disco `public`. Este
 * controller é a ÚNICA porta de saída do arquivo, e ela exige login de
 * admin (`middleware(['auth', 'role:admin'])` na rota).
 *
 * A permissão dedicada `admin.contratos` (UI-05) é da Fase 131 — esta rota
 * deve migrar para ela quando existir. `role:admin` é o controle CORRETO
 * até lá, não um atalho provisório.
 */
class ContratoPdfAssinadoController extends Controller
{
    public function download(ContratoAssinatura $contratoAssinatura): StreamedResponse
    {
        $path = $contratoAssinatura->pdf_assinado_path;

        if (blank($path)) {
            abort(404);
        }

        // Validar o caminho ANTES de servir, mesmo vindo do nosso banco: o
        // valor é nosso hoje, mas uma rota que entrega arquivo a partir de
        // string de banco tem que ser segura POR SI, não por confiança na
        // origem (T-129-38). Recusa caminho absoluto, qualquer ocorrência
        // de ".." e exige o prefixo esperado.
        if (str_contains($path, '..') || ! str_starts_with($path, 'contratos/')) {
            abort(404);
        }

        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return Storage::disk('local')->download($path, "contrato-{$contratoAssinatura->id}-assinado.pdf");
    }
}
