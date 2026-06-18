---
quick_id: 260618-jcb
description: "Onboarding — remover tutoriais App ECF e Planilha de Produtos + tornar link App ECF (Adman) global"
date: 2026-06-18
status: complete
commit: pending (working tree — sem commit por ora)
---

# Quick Task 260618-jcb — Resumo

## O que foi feito

### 1. Tutoriais removidos (App ECF e Planilha de Produtos)
- `MlbImplementacao::CHECKLIST`: `app_ecf` e `planilha_produtos` agora têm
  `tem_tutorial => false`. Isso remove o campo de tutorial dos dois modais admin
  (`ConfigurarForm` por-empresa e `PadroesModal` global), que filtram por
  `i.tem_tutorial`.
- `ImplementacaoPublica.jsx`: o workspace público passou a gatear o botão
  "Tutorial" pelo `item.tem_tutorial` (`tutorialUrl={item.tem_tutorial ? ... : ''}`).
  Antes ele renderizava o botão só com base na URL salva — então implementações
  ANTIGAS com URL de tutorial gravada continuariam mostrando o botão. Agora o
  botão some também para essas.
- Chaves órfãs de tutorial removidas dos defaults: `dadosPadrao()['tutoriais']`
  (modelo) e `implementacaoPadroes()` base `tutoriais` (config) ficaram apenas
  com `acesso_colaborador` e `inscricao_estadual`.

### 2. Link do App ECF (Adman) virou GLOBAL
- `MlbConfiguracao::implementacaoPadroes()` base `links_admin_extra` ganhou
  `'app_ecf' => ''` (junto de `programa_decola` e `tabela_frete`).
- `Implementacao.jsx`:
  - `ConfigurarForm` (por-empresa): removido o campo "Link — App ECF" da lista
    "Links configurados por vocês" e do estado `form.links_admin`.
  - `PadroesModal` (global): adicionado o campo "Link — App ECF" na lista de
    links padrão e no estado do form.
- `MlbImplementacaoController::workspace()` e `publicador()`: injetam o link
  global no `$dados['links_admin']['app_ecf']` antes do render (padrão idêntico
  ao `tabela_frete_url` já existente). Assim o item `app_ecf` (tipo `link_admin`)
  lê sempre o link global, e contas existentes pegam o link sem migração.
- `ImplementacaoPublicador.jsx`: a linha "App ECF" da seção Dados Gerais lia de
  `itens.app_ecf?.link` (campo onde esse link nunca foi gravado — é `link_admin`,
  mora em `links_admin`, então ficava vazia). Passou a ler `linksAdmin.app_ecf`
  (o link global injetado), alinhada com o card "Drive com Imagens" ao lado.

## Arquivos alterados
- `app/Models/MlbImplementacao.php`
- `app/Models/MlbConfiguracao.php`
- `app/Http/Controllers/MlbImplementacaoController.php`
- `resources/js/Pages/Mlb/Implementacao.jsx`
- `resources/js/Pages/Mlb/ImplementacaoPublica.jsx`
- `resources/js/Pages/Mlb/ImplementacaoPublicador.jsx`

## Verificação
- `php -l` limpo nos 3 arquivos PHP alterados.
- `npm run build` verde (10.91s) — Implementacao e ImplementacaoPublica
  recompilados.

## Fora de escopo (intocado)
- Tutoriais de Acesso Colaborador e Inscrição Estadual.
- Link `programa_decola` (continua per-conta + seed global como estava).
- `DadosView` (visão interna admin dos dados do cliente).
- `MlbImplementacaoFactory` (render-injection do controller já cobre).

## Notas
- **Sem commit** e **sem deploy** — alterações no working tree, aguardando
  autorização do usuário.
- Próximo passo operacional: configurar o link único do App ECF em
  Onboarding › **Padrões** (Padrões Globais → "Link — App ECF").
