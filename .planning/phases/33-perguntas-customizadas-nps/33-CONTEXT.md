# Phase 33: Perguntas Customizadas no NPS — Context

**Gathered:** 2026-06-12
**Status:** Ready for execution (lean — sem discuss/research/plan-check)
**Source:** Pedido direto da gerência — quer adicionar perguntas extras às surveys NPS.

<domain>
## Phase Boundary

### O que esta fase entrega

Admin cria perguntas customizadas (escala 1-5, texto livre, sim/não, múltipla escolha) que aparecem **junto** das 3 perguntas fixas da Phase 31 na página `/nps/{token}` do cliente. As respostas ficam visíveis na lista admin `/nps` via botão "Abrir" que expande um modal com TODAS as respostas (3 fixas + comentário + nome respondente + customizadas).

Mantém Phase 31 (3 perguntas core escala 1-5 + comentário) + Phase 32 (textos editáveis + logo) intactos.

### Estado atual investigado (2026-06-12)

- Phase 31+32 deployed: surveys auto_generated, escala 1-5, logo ECF, textos via `Configuracao::get('nps_textos')`, 11 chaves config, log em `nps_email_envios`.
- `Nps/Index.jsx` mostra tabela com colunas Empresa/Origem/Respondente/Estrategista/Analista/Empresa/Comentário/Data/Status/Link (print do usuário confirma). Tabela só lê das 3 colunas numéricas + comentário em `nps_responses`.
- `NpsController::respond` carrega `survey.textos`, `tem_analista`, `estrategista_name`, `analista_name`. UI Respond.jsx renderiza 3 RatingPicker + textarea + nome opcional.
- `submitResponse` valida `score_estrategista`, `score_analista`, `score_empresa`, `comment`, `respondent_name` — todos campos fixos.

</domain>

<decisions>
## Implementation Decisions

### Schema (D-01)

**D-01 — 2 tabelas novas (LOCKED).**

`nps_perguntas_customizadas`:
- `id` bigint pk
- `texto` varchar 500 (a pergunta em si — ex: "O quanto recomendaria a ECF a um amigo?")
- `tipo` enum: `escala_1_5`, `texto`, `sim_nao`, `multipla`
- `opcoes` JSON nullable — usado APENAS quando tipo=multipla. Array de strings: `["WhatsApp", "Email", "Telefone"]`
- `obrigatorio` boolean default false
- `ordem` int default 0 — usado para ordenar na renderização (ASC). Empate vai por `id`.
- `ativa` boolean default true — false esconde a pergunta de novas surveys mas mantém histórico de respostas.
- `timestamps`

`nps_respostas_customizadas`:
- `id` bigint pk
- `response_id` bigint FK → `nps_responses` cascade onDelete
- `pergunta_id` bigint FK → `nps_perguntas_customizadas` set null onDelete (permite hard-delete da pergunta no futuro sem perder histórico)
- `pergunta_texto_snapshot` varchar 500 — congela o texto da pergunta no momento da resposta (defensa contra edição da pergunta depois)
- `tipo_snapshot` varchar 20 — congela o tipo
- `valor` TEXT — armazena:
  - tipo=escala_1_5: string "1".."5"
  - tipo=texto: string livre
  - tipo=sim_nao: "sim" ou "nao"
  - tipo=multipla: string com a opção escolhida (texto da opção, não índice — facilita display)
- `timestamps`
- index em `response_id`

### Tipos de pergunta (D-02)

**D-02 — 4 tipos suportados (LOCKED).**

| Tipo            | Backend valida                          | Frontend Respond.jsx render             |
|-----------------|-----------------------------------------|------------------------------------------|
| `escala_1_5`    | integer 1..5                            | RatingPicker (reutiliza componente atual)|
| `texto`         | string max 2000                         | Textarea                                 |
| `sim_nao`       | in:['sim','nao']                        | 2 botões grandes lado a lado             |
| `multipla`      | in: opcoes[]                            | Radio group vertical                     |

Se `obrigatorio=true`: validação backend `required`. Se false: nullable.

### Ordem no fluxo (D-03)

**D-03 — Perguntas custom após as 3 fixas, antes do comentário (LOCKED).**

Ordem no Respond.jsx (de cima pra baixo):
1. Logo ECF
2. Pergunta estrategista (fixa)
3. Pergunta analista (fixa, condicional `tem_analista`)
4. Pergunta empresa (fixa)
5. **Perguntas customizadas ativas** (ordem ASC, fallback `id` ASC)
6. Comentário livre (fixo)
7. Nome respondente (fixo)

### Gerenciamento (D-04)

**D-04 — Nova aba "Perguntas extras" em `/nps/configuracao` (LOCKED).**

Página existente `Nps/Configuracao.jsx` já tem Tabs Email/Perguntas (Plan 32-02). Adicionar 3ª tab "Perguntas extras" com:
- Lista das perguntas existentes (ativa + desativadas separadas, ou flag visível)
- Botão "Nova pergunta" abre modal/inline form
- Form: texto + tipo (select) + opções (input dinâmico só se tipo=multipla) + obrigatório (switch) + ativa (switch)
- Reordenação via botões ↑↓ (drag-and-drop seria ideal mas vai além do escopo)
- Botão "Excluir" desativa por padrão; se zero respostas associadas, oferece "Excluir definitivamente" com confirm
- Sem preview da tela do cliente (preview do email continua existindo para os textos fixos)

### Exibição na lista (D-05)

**D-05 — Modal "Abrir" em cada linha respondida (LOCKED).**

`Nps/Index.jsx` mantém tabela atual + adiciona botão "Abrir" (ícone ChevronRight ou Eye) em cada linha **com status="completed"** (respondidas). Click abre Dialog shadcn com:
- Header: nome empresa + data resposta + nome respondente (ou "Não informado")
- Bloco "Notas":
  - Estrategista: nota (1-5)
  - Analista: nota (1-5) ou "—" se mentoria pura
  - Empresa: nota (1-5)
- Bloco "Comentário": texto ou "Não informado"
- Bloco "Respostas extras" (só se houver):
  - Por pergunta custom respondida: pergunta texto + valor formatado por tipo
- Rodapé: botão Fechar

Linhas pending (não respondidas) NÃO ganham botão Abrir — não tem o que mostrar.

### Endpoints (D-06)

**D-06 — Endpoints REST mínimos sob `/nps/configuracao/perguntas` (LOCKED).**

Todos role:admin:
- GET `/nps/configuracao/perguntas` (parte do payload de `/nps/configuracao` já — não criar endpoint separado, vem junto)
- POST `/nps/configuracao/perguntas` → cria
- PUT `/nps/configuracao/perguntas/{pergunta}` → atualiza
- DELETE `/nps/configuracao/perguntas/{pergunta}` → soft delete (set ativa=false) OU hard delete se zero respostas. Query param `?force=1` para forçar hard delete em pergunta com respostas (perda controlada).
- POST `/nps/configuracao/perguntas/{pergunta}/mover` → recebe `direcao=up|down`, troca `ordem` com vizinho.

### Submit + Render do cliente (D-07)

**D-07 — Backend renderiza payload + valida dinâmico (LOCKED).**

`NpsController::respond` adiciona ao payload Inertia:
```php
'perguntas_extras' => NpsPerguntaCustomizada::where('ativa', true)
    ->orderBy('ordem')->orderBy('id')
    ->get(['id', 'texto', 'tipo', 'opcoes', 'obrigatorio']);
```

`NpsController::submitResponse` aceita campo `respostas_extras` (array de `[pergunta_id => valor]`):
- Valida obrigatórios estão presentes
- Valida tipo conforme matriz D-02
- Persiste em `nps_respostas_customizadas` com snapshots de texto/tipo
- Atomicidade: tudo dentro do mesmo `DB::transaction` da resposta principal

`NpsController::index` adiciona eager load `responses.respostasCustomizadas.pergunta` para popular o modal sem N+1.

### Claude's Discretion

- Estética dos botões sim/nao no Respond.jsx (verde/vermelho? ou só amarelo destacado?)
- Layout do form de criar pergunta (inline expandível na lista ou modal dedicado)
- Reordenação via botões ↑↓ ou drag-and-drop (botões mais simples)
- Bloco "Respostas extras" no modal — accordion expandível ou sempre aberto (sempre aberto, simples)

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read.**

### Phase 31 (perguntas fixas)
- `app/Http/Controllers/NpsController.php` — `respond()`, `submitResponse()`, `index()`
- `app/Models/NpsResponse.php` — `$fillable` atual
- `app/Models/NpsSurvey.php`
- `resources/js/Pages/Nps/Respond.jsx` — RatingPicker reutilizar
- `resources/js/Pages/Nps/Index.jsx` — tabela atual + lugar pra botão Abrir

### Phase 32 (config + UI admin)
- `resources/js/Pages/Nps/Configuracao.jsx` — adicionar 3ª tab
- `app/Support/NpsTextRenderer.php` — não afeta esta phase

### Padrões shadcn já em uso
- `Dialog/DialogContent/DialogHeader/DialogFooter` — para modal Abrir
- `Tabs/TabsList/TabsTrigger/TabsContent` — para 3ª tab em Configuracao.jsx
- `Switch` — para flags obrigatorio/ativa
- `Select` — para tipo

</canonical_refs>

<specifics>
## Specific Ideas

- **Validação dinâmica no backend**: itera `perguntas_extras` ativas + obrigatórias, monta rules conforme tipo, passa pro `Validator::make`. Não usar `$request->validate` direto (não suporta rules dinâmicas trivialmente).
- **Snapshot do texto da pergunta**: copia `texto` da pergunta para `pergunta_texto_snapshot` no momento da resposta. Se admin editar a pergunta depois, respostas antigas mostram o texto que o cliente VIU (não o atual). Crítico para integridade do histórico.
- **DELETE pergunta com respostas**: padrão = soft (ativa=false). Hard delete (`?force=1`) zera FK em `nps_respostas_customizadas.pergunta_id` (set null), respostas preservadas via snapshot.
- **Ordem de novas perguntas**: ao criar, `ordem = max(ordem) + 1` para ir pro final automaticamente.
- **Modal Abrir performance**: NpsController::index passa a fazer eager `nps_responses.respostasCustomizadas.pergunta` mas só quando o usuário expandir? Não, simples eager load no index não dá N+1 grave (média ~50 envios/mês × 3-5 perguntas custom = pequeno). Eager direto.

</specifics>

<deferred>
## Deferred Ideas

- Drag-and-drop pra reordenação (sortable.js)
- Tipos adicionais: data, número, slider 0-10
- Lógica condicional (mostrar pergunta B só se resposta de A for X)
- Importação/exportação de perguntas via JSON
- Versionamento / histórico de mudanças nas perguntas
- Estatísticas agregadas das respostas custom no Dashboard

</deferred>

---

*Phase: 33-perguntas-customizadas-nps*
*Context gathered: 2026-06-12*
