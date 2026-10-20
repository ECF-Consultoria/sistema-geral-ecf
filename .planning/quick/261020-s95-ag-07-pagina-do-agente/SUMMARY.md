---
quick_id: 261020-s95
status: complete
---
# AG-07 — resumo

**Entregue:**
- `LeituraDoAgente` — configuração, contadores com UNIDADE declarada (preparo = produtos; Aprovação/publicação = ofertas, pela mesma `filaDaConta` da tela da Aprovação), fila e atividade (50 execuções mais recentes, com motivo, bloqueios, quem pediu e os destinos reais: `meus.aprovacao?oferta=` e o editor). Só banco; sem ML, sem IA.
- `MlbPublicadorAgenteController` + rotas `mlb.anuncios.publicador.agente{,.configuracao,.executar,.pausar,.suspender,.retomar}` no grupo `auth`+`verified`+`role:admin`, `{conta}` resolvido no servidor, throttle nomeado. PUT da configuração exige `versao_esperada`; regra recusada volta com `errors.agente` + flash.
- `Pages/Mlb/Publicador/Agente.jsx` (ponte) + `Components/Mlb/Publicador/agente/ConteudoDoAgente.jsx` (apresentação pura): estado da loja + Pausar/Suspender/Retomar, aviso de que nada vai ao ML sem aprovação, 5 contadores, "Preparar agora", fila em curso, atividade e o formulário da configuração essencial.
- Header: `aba="configuracoes"` (o agente não é uma das quatro abas e `CabecalhoPublicadorService::ABAS` é lista fechada — mesmo caso de Configurações da conta) e `liberada` real (`ContasLiberadas`), então conta travada mostra o selo como nas outras telas.
- Gates de página do Publicador ("nenhuma entra calada"): `Agente.jsx` DECLARADA em `publicador-header-nas-paginas.test.js` (PAGINAS) e `publicador-vocabulario-novo.test.js` (CONTAINER_POR_PAGINA / ABA_POR_PAGINA), com o motivo escrito — o gate cobrou a página nova, e a declaração é a resposta.

**Testes:** `TelaDoAgenteTest` 6/6 (81 asserções: só admin, loja desligada sem dado inventado, versão obsoleta recusada, Preparar/Pausar/Retomar pela rota com os links reais, produto de outra loja recusado e cada loja vê só o seu, 404). `tests/js/publicador-agente-render.test.js` 5/5 (render real da página inteira com o header, unidades, destinos, desligada, lixo nas props). Suíte JS inteira 6029/6030 — a única falha é a #191 (`estrutura-grade-glide`), pré-existente. `npm run build` feito (o `public/build` não é versionado).

**Limites declarados:**
- Pill do agente no header: NÃO feito (precisa integrar o `origin/main`, onde o header mudou).
- O Publicador só tem tema escuro (tokens `pub-*` fixos); o "claro/escuro" do plano não se aplica a este produto.
- Fila/atividade sem paginação (as 50 mais recentes); paginar quando o volume pedir.
- Nada foi conferido em navegador: render estático + rotas em teste.

**Gate da Fase 186 que cobrou a tela (e a resposta certa):** `FechamentoDaFase186Test::test_a_escrita_de_andamento_mora_num_lugar_so_em_app_inteiro_com_isca` acusou `LeituraDoAgente` como escritor de `andamento` — falso positivo (a sonda larga casa `'andamento' =>` em array de SAÍDA). Entrou na lista de EXPOSITORES com prova (o próprio laço exige que case a sonda e não tenha nenhuma forma de escrita), sem renomear a chave e sem afrouxar a sonda. `contagens-186-09.txt` REMEDIDO: `andamento-um-lugar-so` 20→21 e `transversais` 258→259 (o +1 é o `assertFileExists` por expositor, já documentado no manifesto); `verificar-testes.sh 186-09` passou nos dois.

**Suíte PHP do Publicador inteira (antes deste ajuste):** 1972 passaram, 2 falharam — a do gate acima (resolvida) e `CapaDoKitTest::capa_cria_o_kit_de_criativos_com_a_copia_da_foto_do_proprio_kit`, que passa 15/15 isolada: dependência de ordem na suíte completa, sem relação com o agente (registrada, não investigada).
