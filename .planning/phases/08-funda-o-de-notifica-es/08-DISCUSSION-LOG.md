# Phase 8: Fundação de Notificações - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-21
**Phase:** 8-Fundação de Notificações
**Areas discussed:** Classe base de Notification

---

## Seleção de gray areas

| Gray area | Selecionado | Tratamento |
|-----------|-------------|------------|
| Classe base de Notification | ✓ | Discutido a fundo (4 perguntas) |
| Contrato do payload `data` JSON | | Resolvido incidentalmente via D-02 (shape estrito + meta opcional) |
| Taxonomia de categorias | | Resolvido incidentalmente via D-03 (enum PHP 8.1 backed string) |
| Cobertura de testes da fundação | | Resolvido incidentalmente via D-04 + D-11 (TestNotification anônima + smoke test) |

**Nota:** o usuário declinou abrir as outras 3 áreas porque as 4 decisões sobre a classe base já fixaram o payload, as categorias e a estratégia de teste implicitamente.

---

## Classe base de Notification

### Q1: Phase 8 deve entregar uma classe abstrata `App\Notifications\BaseNotification`, ou apenas tabela + permission?

| Option | Description | Selected |
|--------|-------------|----------|
| Sim, classe abstrata BaseNotification | abstract com via() => ['database'], construtor padrão (titulo, mensagem, categoria, autor_id, url opcional) e toArray() já estruturado. Phase 9/11/12 estendem com 1 linha por evento e garantem shape uniforme do data JSON. | ✓ |
| Só tabela + permission | Phase 8 entrega só a infra do banco; cada fase futura cria sua classe direto de Illuminate\Notifications\Notification. Mais flexível, porém sem garantia de shape uniforme no payload — Phase 9 (UI dropdown) terá que tolerar variações. | |
| Trait + interface, sem base class | StoresInDatabase trait (define via() => ['database']) + interface HasPayload (contrato toArray()). Mais leve que herdar de base, mas com contrato verificável via type-hint. | |

**User's choice:** Sim, classe abstrata BaseNotification (Recomendado)
**Notes:** Locks D-01.

---

### Q2: Qual deve ser a assinatura/payload da BaseNotification? Phase 9 vai ler esses campos no dropdown e na página de histórico.

| Option | Description | Selected |
|--------|-------------|----------|
| Estrito + meta opcional | Construtor: (string $titulo, string $mensagem, string $categoria, ?int $autorUserId = null, ?string $url = null, array $meta = []). toArray() devolve essas 6 chaves sempre presentes (meta sempre array). Phase 9 sabe exatamente o que renderizar; meta acomoda extras específicos do evento. | ✓ |
| Mínimo + array livre | Construtor: (string $titulo, string $mensagem, array $extras = []). toArray() devolve titulo+mensagem+...extras. Mais flexível, mas Phase 9 precisa testar existência de cada campo (categoria/autor/url) em tempo de render. | |
| Estrito sem meta | Mesma assinatura da opção 1, sem o array $meta. Phase 11/12 precisam estender BaseNotification se quiserem campos extras (ex: setor_id em meta_atribuida). Mais rígido. | |

**User's choice:** Estrito + meta opcional (Recomendado)
**Notes:** Locks D-02. Ajuste: o tipo de `$categoria` é elevado para enum em Q3.

---

### Q3: Como tipar o parâmetro categoria no construtor da BaseNotification?

| Option | Description | Selected |
|--------|-------------|----------|
| Enum PHP 8.1 | App\Notifications\Categoria enum com cases META_ATRIBUIDA, META_ATINGIDA, MANUAL. Construtor aceita Categoria $categoria; toArray() persiste ->value. Type-safe; adicionar categoria nova exige código (mesmo princípio do catálogo Permissions estático). | ✓ |
| String com constantes na BaseNotification | Phase 8 define constantes públicas CATEGORIA_META_ATRIBUIDA='meta_atribuida' etc. Construtor aceita string. Mais leve; perde type safety, mas sem nova classe. | |
| String livre | categoria é string qualquer; convenções (meta_atribuida, meta_atingida, manual) ficam só em pt-BR de comentário. Mais flexível, com risco de typos divergentes entre Phase 11 e Phase 12. | |

**User's choice:** Enum PHP 8.1 (Recomendado)
**Notes:** Locks D-03.

---

### Q4: A Phase 8 deve incluir uma subclasse concreta de referência + smoke test para provar o fluxo, ou só a abstract + enum?

| Option | Description | Selected |
|--------|-------------|----------|
| Só a abstract + enum | Phase 8 entrega apenas BaseNotification (abstract) + enum Categoria + tabela + permission. Tests da Phase 8 instanciam uma TestNotification anônima dentro do próprio teste (Notification::fake() + Notification::route('database')->notify(...)). Mantém o escopo da Phase 8 enxuto. | ✓ |
| Abstract + uma subclasse 'ManualNotification' já nesta fase | Phase 8 já entrega App\Notifications\ManualNotification (subclasse de BaseNotification com categoria=MANUAL fixa) para a Phase 12 reutilizar direto. Adianta trabalho de Phase 12; expande levemente o escopo da fundação. | |
| Abstract + 3 subclasses (Manual, MetaAtribuida, MetaAtingida) | Phase 8 já cria os 3 esqueletos de subclasses, vazios além do construtor + categoria. Phase 11/12 só preenchem título/mensagem. Mais opinionado; arrisca decidir prematuramente assinaturas que mudariam depois. | |

**User's choice:** Só a abstract + enum (Recomendado)
**Notes:** Locks D-04. Phase 8 fica enxuta; as subclasses concretas são responsabilidade da Phase 11 (auto) e Phase 12 (manual).

---

## Wrap-up — Próximo passo

| Option | Description | Selected |
|--------|-------------|----------|
| Pronto para o CONTEXT.md | As 4 decisões da classe base já cobrem incidentalmente payload + categorias + testes. | ✓ |
| Discutir outras gray areas | Abrir as outras 3 (contrato payload, taxonomia, cobertura testes). | |
| Tenho mais perguntas sobre a classe base | Continuar com mais 4 perguntas dentro da área. | |

**User's choice:** Pronto para o CONTEXT.md (Recomendado)

---

## Claude's Discretion

- Namespace exato dos artifacts (sugestão: `App\Notifications\BaseNotification`, `App\Notifications\Categoria`).
- Nome exato do arquivo de migration (segue o padrão `2026_05_2X_XXXXXX_create_notifications_table.php`).
- Nome do arquivo de teste e estrutura interna.
- Ordem exata do grupo "Notificações" dentro do array `catalog()`, desde que entre "Sistema" e "Liderança".

## Deferred Ideas

- Categorias adicionais (sync_falhado, sugador_detectado, nps_recebido) — explicitamente Out of Scope v3.0.
- Outros canais (mail, broadcast) — Out of Scope v3.0; `via()` fixo em `['database']`.
- Coluna dedicada `categoria` na tabela — não justificada no MVP; `data->>'categoria'` resolve.
- Validação de length de titulo/mensagem no construtor — pertence ao FormRequest da Phase 12.
- Trait `StoresInDatabase` como alternativa à herança — descartado em Q1.
