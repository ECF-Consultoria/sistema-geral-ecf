# Portal do Cliente — login obrigatório

Base: VPS `fba3f55a`, com o ajuste de Analista preservado. GSD quick inicializado em 15/09/2026.

## Decisões

- A posse de um token antigo não autoriza mais leitura nem escrita no Portal.
- GETs antigos conduzem ao login fixo; escritas antigas são recusadas antes dos controllers.
- A sessão autenticada continua resolvendo a empresa por vínculo, incluindo a entrada auditada da equipe.
- O código por e-mail é a entrada principal; senha já cadastrada permanece uma alternativa autenticada.
- A equipe confirma o cadastro em Acessos do portal. Selecionar a empresa preenche os dados já cadastrados, inclusive antes da distribuição/onboarding. Importação não concede acesso automaticamente.
- Nenhuma migration, backfill de pessoas ou envio de mensagens a clientes nesta entrega.
- Endpoints de NPS e Implementação de Polos são fluxos distintos e não pertencem à substituição das rotas do Portal.

## Implementação e validação

1. Aposentar tokens no servidor e geração de novos links; padronizar URLs de login e retorno OAuth.
2. Ajustar as telas internas, preenchimento por empresa e entrada principal por código.
3. Testar em cópia isolada: SQLite em memória, e-mail falso, sem configuração de produção. Cobrir tokens válidos/inválidos, escritas, vínculo, revogação, OTP, preenchimento e contas existentes.
4. Build e verificação visual local. Revalidar HEAD/GitHub e hashes antes de publicar/commitar somente os arquivos desta entrega na VPS.
