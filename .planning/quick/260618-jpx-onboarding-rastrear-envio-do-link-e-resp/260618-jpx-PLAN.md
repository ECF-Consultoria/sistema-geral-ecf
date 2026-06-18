---
phase: quick-260618-jpx
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - database/migrations/2026_06_18_000000_add_link_enviado_e_responsavel_to_mlb_implementacoes.php
  - app/Models/MlbImplementacao.php
  - app/Http/Controllers/MlbImplementacaoController.php
  - routes/web.php
  - resources/js/Pages/Mlb/Implementacao.jsx
autonomous: true
requirements: [ONB-ENVIO-LINK, ONB-RESPONSAVEL]
must_haves:
  truths:
    - "Na listagem /implementacao a equipe vê o status do envio do link por empresa (Falta enviar / Enviado / Cliente acessou / Concluído)"
    - "A equipe consegue marcar manualmente que o link foi enviado, gravando quem (usuário logado) e quando (now())"
    - "A equipe consegue desfazer o envio, limpando quem/quando"
    - "Cada onboarding pode ter um responsável (usuário da equipe) atribuído via select"
    - "A listagem mostra quem enviou e em que data quando o link já foi enviado"
    - "Existe filtro 'Falta enviar link' combinável com os filtros Polo/Fase/Fora do prazo já existentes"
    - "Um contador no topo informa quantas empresas faltam enviar o link"
  artifacts:
    - path: "database/migrations/2026_06_18_000000_add_link_enviado_e_responsavel_to_mlb_implementacoes.php"
      provides: "Colunas link_enviado_em, link_enviado_por, responsavel_id (nullable + FK users nullOnDelete)"
      contains: "Schema::table('mlb_implementacoes'"
    - path: "app/Models/MlbImplementacao.php"
      provides: "fillable + cast + relações responsavel()/linkEnviadoPor() + método statusEnvio()"
      contains: "function statusEnvio"
    - path: "app/Http/Controllers/MlbImplementacaoController.php"
      provides: "index() estendido + ações marcarLinkEnviado/desfazerEnvio/atribuirResponsavel"
      contains: "function marcarLinkEnviado"
    - path: "routes/web.php"
      provides: "3 rotas novas dentro do grupo mlb auth"
      contains: "implementacao.marcar-enviado"
    - path: "resources/js/Pages/Mlb/Implementacao.jsx"
      provides: "Coluna Status do envio + coluna Responsável + filtro/contador + botões marcar/desfazer"
      contains: "STATUS_ENVIO_BADGE"
  key_links:
    - from: "resources/js/Pages/Mlb/Implementacao.jsx"
      to: "mlb.implementacao.marcar-enviado / desfazer-envio / responsavel"
      via: "router.post / router.patch com preserveScroll"
      pattern: "implementacao\\.(marcar-enviado|desfazer-envio|responsavel)"
    - from: "app/Http/Controllers/MlbImplementacaoController.php::index"
      to: "MlbImplementacao::statusEnvio()"
      via: "map() por item + filtro Collection ?falta_enviar=1"
      pattern: "statusEnvio\\(\\)"
---

<objective>
Tornar visível, na tela /implementacao (Onboarding), QUAIS empresas ainda faltam ter o
link do cliente enviado, para quem/quando o link já foi enviado, e atribuir um responsável
(dono) por onboarding. Hoje "nunca enviado" e "enviado mas cliente não abriu" ficam idênticos
(ambos sem ultimo_acesso) — esta entrega separa esses estados via um marcador manual.

Purpose: dar à equipe controle operacional do envio do link e accountability (quem é dono de cada onboarding), sem inferência automática.
Output: migração + extensões no model/controller/rotas + UI da listagem (status, responsável, filtro, contador, botões).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@./CLAUDE.md

@app/Models/MlbImplementacao.php
@app/Http/Controllers/MlbImplementacaoController.php
@resources/js/Pages/Mlb/Implementacao.jsx
@database/migrations/2026_06_10_120000_add_campos_onboarding_to_mlb_implementacoes.php

<interfaces>
<!-- Contratos extraídos do código real. O executor deve usá-los diretamente, sem explorar a base. -->

Grupo de rotas (routes/web.php): TODAS as rotas implementacao.* vivem DENTRO do grupo
`Route::middleware(['auth','verified'])->prefix('mlb')->name('mlb.')->group(...)`.
Logo o nome efetivo é `mlb.implementacao.*` (ex: o JSX já usa route('mlb.implementacao.ficha', id)).
As 3 rotas novas vão no MESMO grupo, perto das linhas ~469-472 (gerar / tutoriais / sincronizar-skus / destroy),
usando o model binding {impl} (MlbImplementacao).

MlbImplementacao (model existente):
- progresso(): array { feitos, total, pct(int) }   // pct === 100 ⇒ concluído
- infoPrazo(): array { fora_do_prazo, dias_restantes, dias_decorridos, ... }
- empresa(): BelongsTo MlbEmpresa
- $casts já tem 'ultimo_acesso' => 'datetime'
- coluna ultimo_acesso é datetime nullable

MlbImplementacaoController::checkAccess(Request): void
  // abort_unless(admin || in_array('empresas',perms) || role in [gestor,analista,lider], 403)
  // USAR este método em TODAS as ações novas.

activity('implementacao')->causedBy($request->user())->withProperties([...])->log('texto pt-BR');
  // Padrão de activity log usado em todo o controller. Tag de log/canal segue convenção [Onboarding].

User (model): tem coluna `name`, `id`, `active` (cast boolean). Sem CompanyFactory/seed local.

Radix Select (CRÍTICO — memória do projeto): NUNCA usar <SelectItem value="">.
  Usar sentinela (ex '__sem__') e mapear para null no envio. Ver ESTAGIO_COLORS/aplicarFiltro como padrão.
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Backend — migração + model + controller + rotas</name>
  <files>
    database/migrations/2026_06_18_000000_add_link_enviado_e_responsavel_to_mlb_implementacoes.php,
    app/Models/MlbImplementacao.php,
    app/Http/Controllers/MlbImplementacaoController.php,
    routes/web.php
  </files>
  <action>
    Seguir EXATAMENTE o padrão da migração 2026_06_10_120000 (todos campos nullable, after, down() limpo).

    (1) MIGRAÇÃO nova `2026_06_18_000000_add_link_enviado_e_responsavel_to_mlb_implementacoes.php`.
    up(): Schema::table('mlb_implementacoes', ...) adicionando:
      - `link_enviado_em`  → $table->timestamp('link_enviado_em')->nullable()->after('ultimo_acesso');
      - `link_enviado_por` → $table->unsignedBigInteger('link_enviado_por')->nullable()->after('link_enviado_em'); + foreign() referencia users.id ->nullOnDelete();
      - `responsavel_id`   → $table->unsignedBigInteger('responsavel_id')->nullable()->after('link_enviado_por'); + foreign() referencia users.id ->nullOnDelete();
    down(): dropConstrainedForeignKey('link_enviado_por'); dropConstrainedForeignKey('responsavel_id'); depois dropColumn(['link_enviado_em','link_enviado_por','responsavel_id']);
    Docblock pt-BR explicando o propósito (rastreio de envio do link + responsável). NÃO mexer em mlb_empresas.

    (2) MODEL app/Models/MlbImplementacao.php:
      - Adicionar ao $fillable: 'link_enviado_em', 'link_enviado_por', 'responsavel_id'.
      - Adicionar ao $casts: 'link_enviado_em' => 'datetime'.
      - Relações belongsTo (após empresa()): responsavel() → belongsTo(User::class, 'responsavel_id'); linkEnviadoPor() → belongsTo(User::class, 'link_enviado_por'). Importar use App\Models\User; (ou usar FQCN App\Models\User::class) — manter import organizado.
      - Método público statusEnvio(): string com PRECEDÊNCIA exata e comentário pt-BR ("status reflete a fase atual, não histórico"):
          if ($this->progresso()['pct'] === 100) return 'concluido';
          if ($this->ultimo_acesso !== null)       return 'acessou';
          if ($this->link_enviado_em !== null)      return 'enviado';
          return 'falta_enviar';

    (3) CONTROLLER index() — estender o método EXISTENTE (linhas ~403-468):
      - Trocar with('empresa') por with(['empresa','responsavel','linkEnviadoPor']).
      - Ler filtro novo: $filtroFaltaEnviar = $request->boolean('falta_enviar');
      - No map() de cada item, adicionar props (sem remover as existentes):
          'status_envio'     => $impl->statusEnvio(),
          'link_enviado_em'  => $impl->link_enviado_em?->format('d/m/Y'),
          'link_enviado_por' => $impl->linkEnviadoPor?->name,
          'responsavel_id'   => $impl->responsavel_id,
          'responsavel_nome' => $impl->responsavel?->name,
      - Aplicar o filtro na Collection APÓS o get()/map(), no mesmo encadeamento ->when($filtroForaDoPrazo,...): adicionar
          ->when($filtroFaltaEnviar, fn($col) => $col->filter(fn($e) => $e['status_envio'] === 'falta_enviar'))
        ANTES do ->values() final. (Não dá pra fazer em SQL — depende de progresso/JSON, igual fora_do_prazo.)
      - Nas props da página adicionar:
          'usuarios' => User::where('active', true)->orderBy('name')->get(['id','name']),  // alimenta o select de responsável; só usuários ativos
        e refletir o filtro em 'filtros': adicionar 'falta_enviar' => $filtroFaltaEnviar.
        Garantir `use App\Models\User;` no topo do controller.

    (4) AÇÕES novas no controller (após salvarBlocoLogistica ou junto das ações de impl), todas chamando $this->checkAccess($request) na 1ª linha, comentários pt-BR, activity('implementacao') com tag textual [Onboarding] e return back()->with('success', ...):
      - marcarLinkEnviado(Request $request, MlbImplementacao $impl): grava link_enviado_em=now(), link_enviado_por=$request->user()->id; log "[Onboarding] Link marcado como enviado para \"{nome}\""; success "Link marcado como enviado."
      - desfazerEnvio(Request $request, MlbImplementacao $impl): seta link_enviado_em=null, link_enviado_por=null; log "[Onboarding] Envio do link desfeito para \"{nome}\""; success "Envio desfeito."
      - atribuirResponsavel(Request $request, MlbImplementacao $impl): validate ['responsavel_id' => ['nullable','integer','exists:users,id']]; $impl->update(['responsavel_id' => $validated['responsavel_id'] ?? null]); log "[Onboarding] Responsável atualizado para \"{nome}\""; success "Responsável atualizado."

    (5) ROTAS routes/web.php — DENTRO do grupo mlb (perto da linha ~472, junto de destroy), usando model binding {impl}:
      - Route::post ('/implementacao/{impl}/marcar-enviado', [MlbImplementacaoController::class, 'marcarLinkEnviado'])->name('implementacao.marcar-enviado');
      - Route::post ('/implementacao/{impl}/desfazer-envio',  [MlbImplementacaoController::class, 'desfazerEnvio'])->name('implementacao.desfazer-envio');
      - Route::patch('/implementacao/{impl}/responsavel',     [MlbImplementacaoController::class, 'atribuirResponsavel'])->name('implementacao.responsavel');
      NÃO colocar antes de /implementacao/indicadores (precedência de rota já documentada no arquivo) — colocar junto das outras {impl}.
  </action>
  <verify>
    <automated>php artisan migrate --force; php artisan route:list --name=implementacao | findstr "marcar-enviado desfazer-envio responsavel"</automated>
  </verify>
  <done>
    `php artisan migrate` aplica a migração sem erro; as 3 colunas existem em mlb_implementacoes;
    `php artisan route:list --name=implementacao` lista mlb.implementacao.marcar-enviado, .desfazer-envio e .responsavel;
    MlbImplementacao::statusEnvio() retorna a string correta pela precedência (concluido > acessou > enviado > falta_enviar);
    index() retorna props status_envio/link_enviado_em/link_enviado_por/responsavel_id/responsavel_nome + usuarios + filtros.falta_enviar.
  </done>
</task>

<task type="auto">
  <name>Task 2: Frontend — Implementacao.jsx (status, responsável, filtro, contador, botões)</name>
  <files>resources/js/Pages/Mlb/Implementacao.jsx</files>
  <action>
    Estender o componente EXISTENTE (não reescrever do zero). Manter tokens ecf-*, dark theme, cn(), pt-BR.
    Select Radix já está importado (linha 6). Reaproveitar o padrão de aplicarFiltro (spread de TODOS os filtros).

    (1) Lookups no escopo do módulo (junto de ESTAGIO_COLORS):
      const STATUS_ENVIO_LABELS = { falta_enviar:'Falta enviar', enviado:'Enviado', acessou:'Cliente acessou', concluido:'Concluído' };
      const STATUS_ENVIO_BADGE  = {
        falta_enviar:'text-red-300 bg-red-500/10 border-red-500/20',
        enviado:'text-amber-300 bg-amber-500/10 border-amber-500/20',
        acessou:'text-blue-300 bg-blue-500/10 border-blue-500/20',
        concluido:'text-emerald-300 bg-emerald-500/10 border-emerald-500/20',
      };

    (2) Assinatura do componente: adicionar props `usuarios = []` ao destructuring de Implementacao({ ... }).
      O `filtros` agora também traz `falta_enviar`.

    (3) aplicarFiltro: incluir falta_enviar no spread preservado (mesmo modelo de fora_do_prazo):
        falta_enviar: filtros?.falta_enviar ? '1' : '',
      Atualizar a condição do "Limpar filtros" e do empty-state para também considerar filtros?.falta_enviar.

    (4) Contador no topo (perto do header/filtros): "N faltam enviar" contando empresas com status_envio === 'falta_enviar'.
      const faltamEnviar = empresas.filter(e => e.status_envio === 'falta_enviar').length;
      Exibir um pequeno badge/texto vermelho discreto (ex ao lado do título ou na barra de filtros).

    (5) Filtro toggle "Falta enviar link" ao lado do botão "Fora do prazo" — MESMO padrão <button> (não Radix Select):
        onClick={() => aplicarFiltro('falta_enviar', filtros?.falta_enviar ? '' : '1')}
        classes condicionais cn(...) iguais ao botão "Fora do prazo" (ativo vermelho, inativo neutro).

    (6) Tabela — nova coluna "Status do envio" (header + célula). Posicioná-la antes de "Progresso".
      Célula: badge cn('text-[11px] font-semibold px-2 py-0.5 rounded-full border', STATUS_ENVIO_BADGE[e.status_envio]) com STATUS_ENVIO_LABELS[e.status_envio].
      Abaixo do badge, quando e.link_enviado_em: <p class text-white/30 text-[10px]> "por {e.link_enviado_por ?? '—'} em {e.link_enviado_em}".

    (7) Tabela — nova coluna "Responsável" (header + célula). Select Radix:
        <Select value={empresa.responsavel_id ? String(empresa.responsavel_id) : '__sem__'}
                onValueChange={v => router.patch(route('mlb.implementacao.responsavel', empresa.impl_id),
                                                 { responsavel_id: v === '__sem__' ? null : Number(v) },
                                                 { preserveScroll: true, preserveState: true })}>
          <SelectTrigger ...><SelectValue placeholder="Sem responsável" /></SelectTrigger>
          <SelectContent>
            <SelectItem value="__sem__">Sem responsável</SelectItem>
            {usuarios.map(u => <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>)}
          </SelectContent>
        </Select>
      CRÍTICO: nunca <SelectItem value="">; sentinela '__sem__' → null no envio (memória do projeto Radix Select).

    (8) Coluna Ações — botão rápido por linha (e também no modal):
      Se e.status_envio === 'falta_enviar' (ou link ainda não enviado): botão "Marcar enviado"
        → router.post(route('mlb.implementacao.marcar-enviado', empresa.impl_id), {}, { preserveScroll: true });
      Se link já enviado (e.link_enviado_em != null): botão "Desfazer envio"
        → router.post(route('mlb.implementacao.desfazer-envio', empresa.impl_id), {}, { preserveScroll: true });
      Estilo coerente com os botões existentes de Ações (px-3 py-1.5 rounded-lg bg-white/[0.05] ...).
      No mínimo replicar esses botões no ImplModal aba "Link & Status" (tab==='link'), que já mostra link e último acesso — lugar natural. Pode usar empresa.impl_id (a row passada ao modal tem impl_id).

    (9) colSpan do empty-state: hoje é 7. Foram adicionadas 2 colunas (Status do envio + Responsável) ⇒ atualizar para 9.
  </action>
  <verify>
    <automated>npm run build</automated>
  </verify>
  <done>
    `npm run build` compila sem erros; a tabela exibe colunas "Status do envio" (badge por status) e "Responsável" (Select);
    contador "N faltam enviar" aparece no topo; filtro toggle "Falta enviar link" coexiste com Polo/Fase/Fora do prazo;
    botões "Marcar enviado"/"Desfazer envio" disparam as rotas novas com preserveScroll; nenhum <SelectItem value="">; colSpan do empty-state = 9.
  </done>
</task>

<task type="auto">
  <name>Task 3: Build final + verificação ponta-a-ponta</name>
  <files>resources/js/Pages/Mlb/Implementacao.jsx</files>
  <action>
    Convenção do projeto (CLAUDE.md): rodar `npm run build` ao final de qualquer alteração de frontend.
    Conferir o fluxo ponta-a-ponta sem deploy:
    - migração aplicada e rotas registradas (Task 1);
    - build verde com a UI nova (Task 2);
    - grep de sanidade confirmando ausência de SelectItem value="" no arquivo alterado e presença das rotas novas no JSX.
    NÃO executar deploy (não autorizado — CLAUDE.md + memória do projeto).
  </action>
  <verify>
    <automated>npm run build; (Select-String -Path resources/js/Pages/Mlb/Implementacao.jsx -Pattern 'value=""') ; (Select-String -Path resources/js/Pages/Mlb/Implementacao.jsx -Pattern 'implementacao.(marcar-enviado|desfazer-envio|responsavel)')</automated>
  </verify>
  <done>
    `npm run build` finaliza sem erros (bundle gerado em public/build); o grep NÃO encontra `value=""` no arquivo;
    o grep ENCONTRA as 3 rotas novas referenciadas no JSX; nenhum deploy executado.
  </done>
</task>

</tasks>

<verification>
- `php artisan migrate --force` aplica `2026_06_18_000000_add_link_enviado_e_responsavel_to_mlb_implementacoes` sem erro.
- `php artisan route:list --name=implementacao` lista as 3 rotas novas (mlb.implementacao.marcar-enviado / .desfazer-envio / .responsavel).
- `npm run build` verde após as alterações de JSX.
- Sanidade Radix: nenhum `<SelectItem value="">` em Implementacao.jsx.
- statusEnvio() respeita a precedência concluido > acessou > enviado > falta_enviar.
</verification>

<success_criteria>
- A listagem /implementacao mostra, por empresa, o status do envio do link (4 estados) com badge colorido e, quando enviado, "por {quem} em {quando}".
- A equipe marca/desfaz manualmente o envio do link (grava/limpa link_enviado_em + link_enviado_por), sem inferência automática.
- Cada onboarding pode receber um responsável (usuário ativo) via Select Radix, com sentinela '__sem__' → null.
- Filtro "Falta enviar link" e contador "N faltam enviar" funcionam e combinam com Polo/Fase/Fora do prazo.
- Migração aplicada, rotas registradas, build verde, sem deploy.
</success_criteria>

<output>
Criar `.planning/quick/260618-jpx-onboarding-rastrear-envio-do-link-e-resp/260618-jpx-SUMMARY.md` ao concluir.
</output>
