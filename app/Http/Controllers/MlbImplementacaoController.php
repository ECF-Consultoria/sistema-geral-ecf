<?php

namespace App\Http\Controllers;

use App\Models\MlbConfiguracao;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class MlbImplementacaoController extends Controller
{
    private function checkAccess(Request $request): void
    {
        $user   = $request->user();
        $perms  = $user->publication_permissions ?? [];
        $role   = $user->publication_role;

        abort_unless(
            $user->role === 'admin'
            || in_array('empresas', $perms)
            || in_array($role, ['gestor', 'analista', 'lider']),
            403
        );
    }

    public function indicadores(Request $request)
    {
        $this->checkAccess($request);

        $impls = MlbImplementacao::with('empresa.responsavel')
            ->orderBy('created_at', 'desc')
            ->get();

        $checklist   = MlbImplementacao::CHECKLIST;
        $checkIds    = array_column($checklist, 'id');
        $checkTitles = array_column($checklist, 'titulo', 'id');
        $agora       = now();

        $itemStats    = array_fill_keys($checkIds, ['feitos' => 0, 'pendentes' => 0, 'total' => 0]);
        $statusCounts = ['concluida' => 0, 'em_andamento' => 0, 'parada' => 0, 'nao_iniciada' => 0];
        $somaProgresso = 0;

        $empresasList = $impls->map(function ($impl) use ($checkIds, $checkTitles, $agora, &$itemStats, &$statusCounts, &$somaProgresso) {
            $progresso    = $impl->progresso();
            $itens        = $impl->dados['itens'] ?? [];
            $empresa      = $impl->empresa;
            $ultimoAcesso = $impl->ultimo_acesso;
            $diasSem      = $ultimoAcesso ? (int) $agora->diffInDays($ultimoAcesso) : null;

            foreach ($checkIds as $id) {
                $feito = $itens[$id]['feito'] ?? false;
                $itemStats[$id]['total']++;
                $feito ? $itemStats[$id]['feitos']++ : $itemStats[$id]['pendentes']++;
            }

            $pct = $progresso['pct'];

            if ($pct === 100) {
                $status = 'concluida';
            } elseif ($progresso['feitos'] === 0) {
                $status = 'nao_iniciada';
            } elseif ($diasSem === null || $diasSem > 7) {
                $status = 'parada';
            } else {
                $status = 'em_andamento';
            }

            $statusCounts[$status]++;
            $somaProgresso += $pct;

            return [
                'id'            => $empresa->id,
                'nome'          => $empresa->nome,
                'estagio'       => $empresa->estagio,
                'responsavel'   => $empresa->responsavel?->name ?? '—',
                'criado_em'     => $impl->created_at->format('d/m/Y'),
                'ultimo_acesso' => $ultimoAcesso?->format('d/m/Y H:i'),
                'dias_sem'      => $diasSem,
                'status'        => $status,
                'progresso'     => $progresso,
                'itens'         => collect($checkIds)->map(fn($id) => [
                    'id'     => $id,
                    'titulo' => $checkTitles[$id] ?? $id,
                    'feito'  => $itens[$id]['feito'] ?? false,
                ])->values()->all(),
            ];
        })->values()->all();

        $total = count($empresasList);

        $dificuldades = array_values(array_map(function ($id) use ($itemStats, $checkTitles) {
            $s = $itemStats[$id];
            return [
                'id'            => $id,
                'titulo'        => $checkTitles[$id] ?? $id,
                'feitos'        => $s['feitos'],
                'pendentes'     => $s['pendentes'],
                'total'         => $s['total'],
                'pct_concluido' => $s['total'] > 0 ? round($s['feitos']    / $s['total'] * 100) : 0,
                'pct_pendente'  => $s['total'] > 0 ? round($s['pendentes'] / $s['total'] * 100) : 0,
            ];
        }, $checkIds));

        usort($dificuldades, fn($a, $b) => $b['pct_pendente'] - $a['pct_pendente']);

        return Inertia::render('Mlb/ImplementacaoIndicadores', [
            'total'           => $total,
            'media_progresso' => $total > 0 ? round($somaProgresso / $total) : 0,
            'status_counts'   => $statusCounts,
            'dificuldades'    => $dificuldades,
            'empresas'        => $empresasList,
        ]);
    }

    public function index(Request $request)
    {
        $this->checkAccess($request);

        $globalPadroes = MlbConfiguracao::implementacaoPadroes();

        $empresas = MlbImplementacao::with('empresa')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($impl) {
                $e = $impl->empresa;
                return [
                    'id'            => $e->id,
                    'nome'          => $e->nome,
                    'estagio'       => $e->estagio,
                    'impl_id'       => $impl->id,
                    'token'         => $impl->token,
                    'dados'         => $impl->dados,
                    'progresso'     => $impl->progresso(),
                    'ultimo_acesso' => $impl->ultimo_acesso?->diffForHumans(),
                ];
            });

        return Inertia::render('Mlb/Implementacao', [
            'empresas'          => $empresas,
            'checklist'         => MlbImplementacao::CHECKLIST,
            'erp_opcoes'        => MlbImplementacao::ERP_OPCOES,
            'integrador_opcoes' => MlbImplementacao::INTEGRADOR_OPCOES,
            'global_padroes'    => $globalPadroes,
        ]);
    }

    public function criar(Request $request)
    {
        $this->checkAccess($request);

        $validated = $request->validate([
            'nome' => 'required|string|max:200',
        ]);

        $existing = MlbEmpresa::whereRaw('LOWER(nome) = LOWER(?)', [$validated['nome']])->first();

        if ($existing) {
            if ($existing->implementacao) {
                throw ValidationException::withMessages([
                    'nome' => "A empresa \"{$existing->nome}\" já existe e já possui uma implementação.",
                ]);
            }
            if (!$request->boolean('confirmar')) {
                throw ValidationException::withMessages([
                    'empresa_existente' => "A empresa \"{$existing->nome}\" já está cadastrada em Empresas. Deseja vincular a implementação a ela?",
                ]);
            }
        }

        $msg = '';

        DB::transaction(function () use ($validated, $request, $existing, &$msg) {
            if ($existing) {
                $empresa = $existing;
                $msg = "Implementação vinculada à empresa existente \"{$empresa->nome}\".";
            } else {
                $empresa = MlbEmpresa::create([
                    'nome'       => $validated['nome'],
                    'tipo'       => 'POLO',
                    'projeto'    => 'POLOS',
                    'fase'       => 'M0',
                    'estagio'    => 'Não Listado',
                    'criado_por' => $request->user()->id,
                ]);
                $msg = 'Implementação criada com sucesso.';
            }

            $impl = $this->criarImplementacaoPolo($empresa);

            // Preenche gmail do colaborador se vinculando empresa existente com gmail configurado
            if ($existing && !empty($empresa->gmail)) {
                $dados                                   = $impl->dados;
                $dados['links_admin']['gmail_colaborador'] = $empresa->gmail;
                $impl->update(['dados' => $dados]);
            }

            activity('implementacao')
                ->causedBy($request->user())
                ->withProperties(['empresa' => $empresa->nome, 'vinculada' => (bool) $existing])
                ->log('Implementação MLB criada para "' . $empresa->nome . '"');
        });

        return back()->with('success', $msg);
    }

    public function salvarPadroes(Request $request)
    {
        $this->checkAccess($request);

        $validated = $request->validate([
            'tutorial_intro'           => 'nullable|string|max:500',
            'tutoriais'                => 'nullable|array',
            'tutoriais.*'              => 'nullable|string|max:500',
            'links_admin_extra'        => 'nullable|array',
            'links_admin_extra.*'      => 'nullable|string|max:500',
        ]);

        MlbConfiguracao::get()->update([
            'implementacao_defaults' => $validated,
        ]);

        return back()->with('success', 'Padrões globais salvos. Serão aplicados em novas implementações.');
    }

    public function gerarLink(Request $request, MlbEmpresa $empresa)
    {
        $this->checkAccess($request);

        $impl = MlbImplementacao::firstOrCreate(
            ['empresa_id' => $empresa->id],
            [
                'token' => Str::random(48),
                'dados' => MlbImplementacao::dadosPadrao(),
            ]
        );

        $msg = $impl->wasRecentlyCreated ? 'Link de implementação gerado.' : 'Empresa já possui link de implementação.';
        return back()->with('success', $msg);
    }

    public function atualizarTutoriais(Request $request, MlbImplementacao $impl)
    {
        $this->checkAccess($request);

        $validated = $request->validate([
            'tutorial_intro'                    => 'nullable|string|max:500',
            'prazo_data'                        => 'nullable|date_format:Y-m-d',
            'tutoriais'                         => 'required|array',
            'tutoriais.*'                       => 'nullable|string|max:500',
            'links_admin'                       => 'nullable|array',
            'links_admin.*'                     => 'nullable|string|max:500',
            'precificacao_config'               => 'nullable|array',
            'precificacao_config.classico'      => 'nullable|array',
            'precificacao_config.classico.*'    => 'nullable|numeric',
            'precificacao_config.premium'       => 'nullable|array',
            'precificacao_config.premium.*'     => 'nullable|numeric',
        ]);

        $dados    = $impl->dados ?? MlbImplementacao::dadosPadrao();
        $defaults = MlbImplementacao::dadosPadrao()['itens']['precificacao'];

        $dados['tutorial_intro'] = $validated['tutorial_intro'] ?? '';
        $dados['prazo_data']     = $validated['prazo_data']     ?? '';
        $dados['tutoriais']      = array_merge($dados['tutoriais'] ?? [], $validated['tutoriais']);
        if (!empty($validated['links_admin'])) {
            $dados['links_admin'] = array_merge($dados['links_admin'] ?? [], $validated['links_admin']);
        }
        if (!empty($validated['precificacao_config'])) {
            $dados['itens']['precificacao']['classico'] = array_merge(
                $dados['itens']['precificacao']['classico'] ?? $defaults['classico'],
                $validated['precificacao_config']['classico'] ?? []
            );
            $dados['itens']['precificacao']['premium'] = array_merge(
                $dados['itens']['precificacao']['premium'] ?? $defaults['premium'],
                $validated['precificacao_config']['premium'] ?? []
            );
        }
        $impl->update(['dados' => $dados]);

        activity('implementacao')
            ->causedBy($request->user())
            ->withProperties(['empresa' => $impl->empresa->nome])
            ->log('Configurações de implementação atualizadas para "' . $impl->empresa->nome . '"');

        return back()->with('success', 'Configurações atualizadas.');
    }

    public function sincronizarSkus(Request $request, MlbImplementacao $impl)
    {
        $this->checkAccess($request);

        $produtos = $impl->dados['itens']['planilha_produtos']['produtos'] ?? [];

        $skus = array_values(array_filter(
            array_map(fn($p) => trim($p['sku'] ?? ''), $produtos),
            fn($s) => $s !== ''
        ));

        $toSkuArray = fn($list) => array_values(array_map(
            fn($s) => ['sku' => $s, 'ok' => false, 'concluido_em' => null, 'atrasado' => false],
            $list
        ));

        $impl->empresa->update([
            'skus_estagio1' => $toSkuArray(array_slice($skus, 0, 3)),
            'skus_estagio2' => $toSkuArray(array_slice($skus, 3, 4)),
            'skus_estagio3' => $toSkuArray(array_slice($skus, 7, 3)),
        ]);

        return back()->with('success', count($skus) . ' SKU(s) sincronizado(s) para os estágios da empresa.');
    }

    public function destroy(Request $request, MlbImplementacao $impl)
    {
        $this->checkAccess($request);
        $impl->delete();
        return back()->with('success', 'Implementação removida. A empresa permanece em Empresas.');
    }

    // ─── Público (sem autenticação) ──────────────────────────────────────────

    public function publicador(string $token)
    {
        $impl = MlbImplementacao::where('token', $token)->with('empresa')->firstOrFail();

        return Inertia::render('Mlb/ImplementacaoPublicador', [
            'impl' => [
                'token'        => $impl->token,
                'empresa_nome' => $impl->empresa->nome,
                'dados'        => $impl->dados ?? MlbImplementacao::dadosPadrao(),
                'criado_em'    => $impl->created_at->format('d/m/Y'),
            ],
            'checklist'         => MlbImplementacao::CHECKLIST,
            'erp_opcoes'        => MlbImplementacao::ERP_OPCOES,
            'integrador_opcoes' => MlbImplementacao::INTEGRADOR_OPCOES,
        ]);
    }

    public function workspace(string $token)
    {
        $impl = MlbImplementacao::where('token', $token)->with('empresa')->firstOrFail();
        $impl->update(['ultimo_acesso' => now()]);

        $dados = $impl->dados ?? MlbImplementacao::dadosPadrao();

        return Inertia::render('Mlb/ImplementacaoPublica', [
            'impl' => [
                'token'        => $impl->token,
                'empresa_nome' => $impl->empresa->nome,
                'dados'        => $dados,
                'progresso'    => $impl->progresso(),
            ],
            'checklist'         => MlbImplementacao::CHECKLIST,
            'erp_opcoes'        => MlbImplementacao::ERP_OPCOES,
            'integrador_opcoes' => MlbImplementacao::INTEGRADOR_OPCOES,
            'prazo_data'        => $dados['prazo_data'] ?? '',
        ]);
    }

    public function salvarItem(Request $request, string $token)
    {
        $impl = MlbImplementacao::where('token', $token)->firstOrFail();

        $request->validate([
            'id'    => 'required|string',
            'campo' => 'required|string',
            'valor' => 'nullable',
        ]);

        $id    = $request->string('id')->toString();
        $campo = $request->string('campo')->toString();
        $valor = $request->input('valor');

        $dados = $impl->dados ?? MlbImplementacao::dadosPadrao();
        abort_unless(isset($dados['itens'][$id]), 422);

        $dados['itens'][$id][$campo] = $valor;
        $impl->update(['dados' => $dados]);

        // Log público (cliente preenchendo o checklist) — sem usuário autenticado
        if ($campo === 'feito') {
            activity('implementacao')
                ->withProperties(['empresa' => $impl->empresa->nome, 'item' => $id, 'feito' => (bool) $valor])
                ->log('Item "' . $id . '" marcado como ' . ($valor ? 'concluído' : 'pendente') . ' na implementação de "' . $impl->empresa->nome . '" (cliente)');
        }

        return response()->json(['ok' => true, 'progresso' => $impl->progresso()]);
    }

    // ─── Métodos privados ────────────────────────────────────────────────────

    /**
     * Cria uma MlbImplementacao para uma empresa POLO com os dados padrão
     * configurados em MlbConfiguracao::implementacaoPadroes().
     *
     * Extraído de criar() para reutilização em ComercialController (D-20 do CONTEXT.md).
     * O caller fica responsável por atualizar campos extras (ex: gmail_colaborador)
     * se necessário após a criação.
     *
     * @param MlbEmpresa $empresa Empresa POLO já persistida.
     * @return MlbImplementacao
     */
    private function criarImplementacaoPolo(MlbEmpresa $empresa): MlbImplementacao
    {
        $dados = MlbImplementacao::dadosPadrao();
        $p     = MlbConfiguracao::implementacaoPadroes();

        if ($p['tutorial_intro']) {
            $dados['tutorial_intro'] = $p['tutorial_intro'];
        }
        if (!empty($p['tutoriais'])) {
            $dados['tutoriais'] = array_merge($dados['tutoriais'], $p['tutoriais']);
        }
        if (!empty($p['links_admin_extra'])) {
            $dados['links_admin']['programa_decola'] = $p['links_admin_extra']['programa_decola'] ?? '';
        }

        return MlbImplementacao::create([
            'empresa_id' => $empresa->id,
            'token'      => Str::random(48),
            'dados'      => $dados,
        ]);
    }
}
