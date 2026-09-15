# Portal do Cliente: login obrigatório

## Comportamento

- Endereço fixo `/entrar`, código de seis dígitos por e-mail como entrada principal. A senha opcional já existente permanece disponível.
- Rotas antigas `portal-cliente/{token}` e `onboarding-cliente/{token}` não autorizam acesso: GET/HEAD redirecionam, escritas retornam 410 antes de bindings e handlers.
- Links avulsos de PPA vinculados a Company também deixam de autorizar leitura/escrita. O fluxo separado de Polos sem Company permanece como antes.
- Emissão/consumo de código serializados por conta em transação; expiração, desafio do navegador, limite de tentativas, uso único e revogação preservados. Login do cliente limpa contexto anterior de equipe.
- Cadastro sugere contato da empresa ativa, inclusive antes do onboarding, sem autorizar automaticamente. E-mail já cadastrado oferece vínculo explícito; conta desativada exige revisão separada.
- Checklist e boas-vindas exigem pessoa ativa vinculada, em vez da existência de um token. PPA, onboarding e Entrada divulgam login sem credencial na URL.

## Validação isolada

- PHP 8.2, SQLite descartável, notificações falsas/array; nenhum e-mail real enviado.
- 73 testes de login, senha, equipe, grupos, exclusão, domínio e aposentadoria de tokens: 614 asserções.
- 31 testes de checklist e boas-vindas: 104 asserções.
- 2 testes Node de preenchimento e identificação de conta existente.
- `npm ci` com lock existente e `npm run build` concluídos; sintaxe PHP e `git diff --check` aprovados.
- Navegador local: entrada direta na etapa de código e modal com empresa, nome, e-mail, telefone e cargo sugeridos.

### Migração da suíte (continuação, 15/09)

Os números acima são de suítes selecionadas. Rodando as pastas inteiras que exercitam o portal e o checklist, o patch quebrava **83 testes**:

- **Linha de base**: as mesmas 19 classes, sem o patch, davam 200/200. Nenhuma das 83 falhas era preexistente.
- **Classificação**: 69 miravam a porta por token ou a URL com token (200 via link, 404 para token inválido, 301 legado, carimbo de acesso no link, `link.url` com token na ficha); 14 vinham de helpers de `Phase152`/`Phase153` que fechavam o item 8 criando `OnboardingLink`.
- **Migrar, não apagar**: a porta autenticada não tinha teste para iniciais, badge, PPA em rascunho, ordem dos módulos, responsáveis, pessoas, mapeamento, desmarcar nem isolamento entre empresas. Os 19 arquivos foram levados para a porta autenticada. Aposentados só os casos da própria porta antiga, que `PortalSemTokenTest` cobre.
- **`Tests\Concerns\EntraNoPortal`** grava a sessão do guard `portal`. `actingAs($u, 'portal')` troca o guard PADRÃO: `$request->user()` vira o `PortalUsuario` e o `HandleInertiaRequests` chama `hasPermission()` nele — 500 em toda página, cenário que não existe em produção.
- `ChecklistMarcacaoManualAutoriaTest` chamava `gerarConexaoEcf()`, que o patch remove: virou prova de que o item 8 ignora link por token e fecha com acesso ativo vinculado.
- **Rodada final**, na árvore exata do commit (cópia isolada conferida arquivo a arquivo contra o worktree, 52/52 idênticos): pastas `PortalCliente`, `OnboardingEmCompanies`, `Phase135`, `Phase152`, `Phase153`, `Phase158` e `PpaQuadro` — **678 testes, 3.413 asserções, 74 classes, 0 falhas**. Teste Node 2/2; `npm run build` concluído.

## Impacto medido em produção (antes de publicar)

- 11 `onboarding_links`; **10 empresas** abriram o link nos últimos 30 dias (último acesso em 11/09).
- Dessas, **1** tem login ativo e **9 ficam sem entrada** até alguém da equipe cadastrá-las em Acessos do portal. 5 das 9 já têm `email_cliente` válido, que o modal sugere; 4 exigem o contato informado à mão.
- Nenhum acesso foi criado nesta entrega: cadastrar pode notificar cliente real, e isso é decisão da equipe.

## Notas para acompanhar

- A porta autenticada resolve o registro da empresa com `OnboardingLinkService::paraEmpresa()`, um `firstOrCreate`. Empresa nova não recebe 404, mas ganha uma linha com token inerte — ele não abre mais nada. Remover essa criação é limpeza, não correção de segurança.

## Base e publicação

- Base inicial VPS fba3f55a, incluindo o rótulo Analista solicitado anteriormente.
- Integradas na cópia isolada as alterações concorrentes até 3000eafa, de contratos/fechamento, sem sobreposição.
- Publicar somente o diff do portal. Não transferir `.env`, banco de preview, vendor, node_modules ou dados fictícios.
- Sem migração de banco, sem cadastro automático de clientes, sem limpeza ampla de cache.
- A entrega de e-mail real deve ser conferida com um destinatário autorizado; os testes validam a emissão sem enviar a clientes.
- Login protege contra vazamento do endereço. Não impede compartilhamento deliberado de códigos, sessão ou dados por alguém autorizado.
