# Phase 13: Discussion Log — Reestruturação do Cadastro de Empresas

**Date:** 2026-05-25
**Areas discussed:** Acesso do Comercial, Dados no cadastro inicial, Como setores veem empresas pendentes, Migração de dados existentes

---

## Área 1: Acesso do Comercial

| Pergunta | Opções | Resposta |
|----------|--------|----------|
| Como um usuário é identificado como membro do Comercial? | Setor Comercial + permissão / Novo users.role / Só admin | **Setor Comercial + permission_key `comercial.cadastrar_empresa`** |
| Admin também acessa a tela do Comercial? | Sim (short-circuit) / Não, só membros do setor | **Sim — admin sempre tem acesso (padrão existente)** |
| Item "Comercial" aparece no sidebar? | Sim — item próprio no sidebar / Sim — dentro de Administrativo | **Sim — item "Comercial" com sub-item no sidebar** |

---

## Área 2: Dados no Cadastro Inicial

| Pergunta | Opções | Resposta |
|----------|--------|----------|
| Quais campos o Comercial preenche? | Cadastro mínimo / Cadastro completo com contrato | **Mínimo: Nome, CNPJ, service_type (+subtipo para Publicação)** |
| service_type=Publicação exibe subtipo na mesma tela? | Sim — subtipo dinâmico / Não — setor define depois | **Sim — POLOS ou Assessoria aparecem ao selecionar Publicação** |
| O que acontece com nome duplicado? | Bloquear com erro / Permitir e avisar | **Bloquear com mensagem de erro + opção de confirmar vínculo** |
| Comercial atribui responsável no cadastro? | Não — setor faz depois / Sim — Comercial indica | **Não — atribuição fica para o setor responsável** |

---

## Área 3: Como Setores Veem Empresas Pendentes

| Pergunta | Opções | Resposta |
|----------|--------|----------|
| Como o setor é avisado de nova empresa? | Notificação / Badge visual / Ambos | **Ambos — notificação para líderes + badge nas páginas existentes** |
| O que define uma empresa como "pendente"? | Campo status na Company / Critério por setor | **Campo `companies.status` ('pendente'/'ativo')** |
| Qual página cada setor usa para completar dados? | Páginas existentes com seção pendentes / Nova página por setor | **Páginas existentes com seção "Pendentes" no topo** |

---

## Área 4: Migração de Dados Existentes

| Pergunta | Opções | Resposta (freeform) |
|----------|--------|---------------------|
| O que fazer com mlb_empresas sem company_id? | Criar retroativamente / Deixar legadas / Sob demanda | **Criar Companies para todas as mlb_empresas existentes, mantendo dados MLB intactos** |
| Como definir service_type das Companies retroativas? | Derivar de tipo/projeto / Tudo 'polo' | **Derivar: POLO/POLOS→'polos', ASSESSORIA→'assessoria', Incubadora→'incubadora'** |
| Nomenclatura: 'polo' ou 'polos'? | — | **Usuário corrigiu: o nome correto é 'polos' (renomear de 'polo')** |
| service_type expandidos? | 'polos','assessoria','incubadora','publicidade','gestao' / Manter 'polo' | **'polos', 'assessoria', 'incubadora', 'publicidade', 'gestao'** |
| Status das Companies retroativas? | 'ativo' / 'pendente' | **'ativo' — já em operação** |
| Status das Companies já existentes em companies? | Todas 'ativo' / Avaliar campo a campo | **Todas 'ativo' via migration** |
| Observação final do usuário | — | **Testar exclusivamente em localhost. Nenhum deploy para VPS sem autorização explícita.** |

---

## Decisões por Discretion de Claude

- Estrutura interna do controller (inline vs. service)
- URL exata das rotas `/comercial/*`
- Texto das notificações para líderes
- Estilo visual da seção "Pendentes"
