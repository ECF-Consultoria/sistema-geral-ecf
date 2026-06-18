---
quick_id: 260618-of1
slug: onboarding-mensagem-boas-vindas-padrao-g
date: 2026-06-18
status: in-progress
---

# Quick Task — Onboarding: mensagem de boas-vindas padrão + Grant configurável por polo

## Objetivo

No módulo **Onboarding** (`/implementacao`), permitir que a equipe envie uma
**mensagem de boas-vindas padronizada** para o cliente, com o **link do formulário
da empresa** e o **link do Grant da região** já preenchidos automaticamente —
evitando copiar/colar manual e divergência de nomes de região.

## Decisões (confirmadas com o usuário)

- O 4º polo permanece **"Bento Gonçalves"** (não renomear; empresas existentes
  intactas). O Grant da Serra Gaúcha (`a5bPc000008oswfIAA`) é associado a ele, e o
  **nome do projeto do Grant é configurável por polo** (ex.: "Projeto Polos - Serra Gaúcha").
- O link do formulário continua sendo a URL pública do workspace
  (`route('implementacao.workspace', token)`) — única por empresa.
- Grant resolvido pelo **polo cadastrado da empresa** (`mlb_empresas.polo`), não por
  texto livre → sem divergência.

## Mapa de Grants (defaults)

Base: `https://partners.mercadolivre.com.br/auth/<id>`

| Polo (sistema)    | Nome do projeto (Grant)           | id            |
|-------------------|-----------------------------------|---------------|
| Arapongas         | Projeto Polos - Arapongas         | a5bPc000008osyHIAQ |
| S. J. Rio Preto   | Projeto Polos - São José do Rio Preto | a5bPc000008osztIAA |
| Bento Gonçalves   | Projeto Polos - Serra Gaúcha      | a5bPc000008oswfIAA |
| São Bento do Sul  | Projeto Polos - São Bento do Sul  | a5bPc000008ot1VIAQ |

## Placeholders da mensagem

- `{link_formulario}` → URL do workspace da empresa
- `{link_grant}` → URL do Grant do polo da empresa
- `{projeto_grant}` → nome configurado do projeto do Grant do polo
- `{empresa}` → nome da empresa

## Escopo (arquivos)

1. **`app/Models/MlbConfiguracao.php`** — `implementacaoPadroes()`: adicionar ao
   `$base` os campos `mensagem_boas_vindas` (texto padrão fornecido pelo usuário, com
   placeholders) e `grants_por_polo` (mapa polo → `{url, nome}` com os defaults acima).
2. **`app/Http/Controllers/MlbImplementacaoController.php`** — `salvarPadroes()`:
   validar `mensagem_boas_vindas` (string) e `grants_por_polo.*.{url,nome}`.
3. **`resources/js/Pages/Mlb/Implementacao.jsx`**:
   - `PadroesModal`: nova seção "Mensagem de boas-vindas" (textarea + ajuda dos
     placeholders) e seção "Grants por Polo" (nome + url por polo de `polo_opcoes`).
     Passar `polo_opcoes` ao componente e incluir os campos no submit.
   - `ImplModal` (aba "Link & Status"): bloco "Mensagem de boas-vindas" com a mensagem
     renderizada (placeholders substituídos) + botão "Copiar mensagem". Aviso quando a
     empresa não tem polo ou o polo não tem Grant configurado. Passar `global_padroes`
     ao componente.
4. `npm run build`.

## Fora de escopo

- Sem renomear polos / migração de dados.
- Sem deploy.
- Sem envio automático (WhatsApp/e-mail) — apenas copiar a mensagem pronta.

## Verificação

- `php artisan test` (suite não deve regredir — nenhum teste referencia os campos novos).
- Build Vite verde.
- Manual: em Padrões aparecem a mensagem e os 4 grants pré-preenchidos; ao abrir uma
  empresa, "Copiar mensagem" gera o texto com o link da empresa + grant do polo.
