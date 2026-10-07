---
task_id: 261005-si3
slug: foto-de-referencia-do-painel-de-criativos
date: 2026-10-05
status: complete
commits:
  - 6726135f
  - 950bfd63
  - 62b274a7
  - 5fd18940
  - 61353dbd
---

# Resumo

O painel "Gerar fotos com IA" nao dava nenhum sinal quando o usuario escolhia uma
foto de referencia do computador. Quatro tarefas: (1) o bug de render instavel
achado na investigacao original, (2) sinal de vida visual + erro de upload em
pt-BR, (3) texto correto sobre a foto de referencia nao precisar do tamanho do
Mercado Livre, (4) registrar no learnings o limite de PHP-FPM que tambem
contribuia para o silencio.

## Diagnostico da Tarefa 1 -- confirmado, com uma ressalva importante

**O encadeamento existe no codigo, exatamente como descrito no plano:**
FotosPorGrupo.jsx:104 calculava `sugeridas` inline (filter/slice), array novo
a cada render; Mesa/PainelCriativos.jsx:135-137 tinha um useEffect com
dependencias [c.fase, sugeridas] chamando setMarcadas com um Set novo -- um
Set novo nunca e Object.is-igual ao anterior.

**Mas nao e um loop auto-sustentado que congela a tela sozinho.** Rastreei a
cadeia completa: setMarcadas e estado LOCAL do PainelCriativos; ele nao faz o
BlocoDeFotos (pai) renderizar de novo, entao o efeito nao se realimenta por
conta propria. O gatilho real e QUALQUER outro render do Editor.jsx enquanto o
painel esta aberto -- e esse gatilho e frequente: o contexto
CriativosDoPublicador reconstroi um objeto novo a cada render de quem chama
useCriativosDoPublicador (usePublicador.js), e esse hook reenderiza a cada
tecla digitada em QUALQUER outro campo da tela (autosave a cada 900ms), a cada
tick do estado de salvando, etc. Cada um desses renders recomputa `sugeridas`
com identidade nova e reabre o efeito, que reseta silenciosamente os
checkboxes marcados.

**O que isso significa para o sintoma relatado:** o efeito explica um bug real
(selecao de fotos do bloco resetando sem aviso em qualquer digitacao na tela)
mas NAO explica sozinho "nada acontece ao escolher a foto do computador" --
esse fluxo (setArquivos dentro do proprio PainelCriativos, puramente local)
nao passa pelo `sugeridas`/efeito nenhum e deveria funcionar mesmo com o bug
intacto. A explicacao mais provavel para "nao sei se deu certo" e a UX: o
unico sinal era um nome de arquivo em texto cinza -- facil de nao notar, sem
contraste, sem miniatura (ver Task 2). Corrigi os dois: o efeito instavel
(correcao real, Rule 1) e a falta de sinal visual (Task 2), sem forcar a
narrativa de que o loop por si so explicava o congelamento.

**Fix:** `fotos`/`sugeridas` agora vem de useMemo com deps
[imagens, atribuicoes, grupo] (referencias estaveis entre renders nao
relacionados), e o efeito do painel passou a depender de uma chave por
conteudo (ids concatenados por virgula), nao da identidade do array -- dupla
blindagem, como pedido no plano.

## Tarefa 2 -- sinal de vida

- Mesa/PainelCriativos.jsx: cada arquivo escolhido ganha miniatura
  (URL.createObjectURL + revokeObjectURL na limpeza, via useEffect
  sincronizado com `arquivos`) e um banner verde "N fotos escolhidas do
  computador" -- chamei a atencao de verdade, em vez de um texto que se
  perdia entre os outros paragrafos de ajuda.
- MlbPublicadorCriativoController::planejar(): antes da validacao normal,
  detecta se o PHP descartou o corpo inteiro por passar de post_max_size
  (compara Content-Length do cabecalho -- que sobrevive -- com
  ini_get(post_max_size), e confere que $_POST/$_FILES vieram vazios) e
  devolve uma mensagem em pt-BR sobre o tamanho do arquivo, em vez do 422
  generico "grupo obrigatorio" (campo que o operador nunca viu nem preencheu).

## Tarefa 3 -- confirmado ponta a ponta antes de escrever a frase

O usuario estava certo, e a conferencia completa (nao so a validacao do
upload) confirma:

- **Upload da referencia** (planejar()): valida so required, file, image,
  max:10240 (10 MB) -- **nenhuma regra de dimensao**.
- **Quando a imagem e aprovada e entra como foto do rascunho**
  (PublicadorCriativoAprovacaoService::aprovarSlot() ->
  ImagemAssetService::receber()): essa chamada roda
  ValidadorImagem::problemas(nova, $meta, ...), que **inclui** o bloqueio de
  dimensao (V-IMG-03, minimo 500px no lado menor --
  ContextoValidacao::$ladoMinimoImagem).

Ou seja: a foto que o usuario ENVIA como referencia nunca passa por checagem
de tamanho; e a imagem GERADA pela IA (o resultado, que a IA controla) que
passa pela mesma conferencia de qualquer foto do anuncio, no momento em que e
aprovada. Escrevi a frase do jeito que isso realmente funciona, sem prometer
que "nada nunca falha" -- so que a foto de referencia em si nao tem exigencia
de tamanho:

> "Esta foto e so para a IA se inspirar: pode ter qualquer tamanho, nao
> precisa ser do tamanho que o Mercado Livre exige para o anuncio."

## Tarefa 4 -- learnings

Nova entrada em .planning/learnings/publicador-ml.md (secao 13, Creative
Engine no Publicador): upload_max_filesize=2M / post_max_size=8M contra uma
validacao que promete 10 MB -- subido para 12M/50M com reload do php8.2-fpm
em producao (05/10/2026). Registrado como config de VPS fora do git, igual a
pm.max_children e sort_buffer_size ja catalogados.

## Testes e build (medidos nesta execucao)

| Suite | Comando | Resultado |
|---|---|---|
| tests/js/publicador-mesa.test.js + tests/js/publicador-editor.test.js | node --test | 158/158, 0 falhas (antes e depois das duas rodadas de edicao) |
| tests/Feature/Phase165 | php artisan test --testsuite=Feature --filter=Phase165 | 136 passed, 1 incomplete (805 assertions) |
| tests/Feature/Phase165 + tests/Unit/Phase165 | php artisan test | 151 passed, 1 incomplete (847 assertions) -- igual ao baseline (152 com o incomplete esperado) |
| tests/Feature/Publicador + tests/Unit/Publicador | php artisan test | 803 passed (4039 assertions) -- igual ao baseline pedido |
| Build de producao | npm run build | built in 1m, sem erros; Editor-Drq3NnRG.js contem "fotos escolhidas do computador" |

Nao rodei a suite JS inteira (npm run test:js) porque as duas falhas
pre-existentes (estrutura-grade-glide.test.js, polosEntrantes.test.js) sao
conhecidas e nao tocadas por este quick task; os dois arquivos relevantes
(publicador-mesa, publicador-editor) foram conferidos isoladamente nas duas
rodadas de edicao, sempre 158/158.

## Deviations from Plan

**1. Commit das Tasks 2 e 3 ficou junto.** Ambas tocam a mesma regiao de
Mesa/PainelCriativos.jsx (a tela de "escolhendo" fotos) e foram escritas na
mesma passada de edicao; separar o commit exigiria desfazer e reaplicar
partes do mesmo arquivo sem ganho real de rastreabilidade. Documentado aqui
em vez de forcar uma separacao artificial.

**2. Nenhum teste automatizado novo para o caso post_max_size.** A condicao
depende do SAPI real do PHP truncando $_POST/$_FILES antes do Laravel -- o
cliente de teste do Laravel constroi a Request diretamente, sem passar pelo
parsing real do PHP, entao nao ha como provocar esse caminho especificamente
via php artisan test. Validado por leitura de codigo (php -l limpo) e pela
suite completa de Publicador/Phase165 nao regredindo.

## Self-Check: PASSED

- resources/js/Components/Publicador/FotosPorGrupo.jsx -- FOUND, contem useMemo em fotos e sugeridas
- resources/js/Components/Publicador/Mesa/PainelCriativos.jsx -- FOUND, contem miniaturas, chaveSugeridas, "fotos escolhidas do computador", "Esta foto e so para a IA se inspirar"
- app/Http/Controllers/MlbPublicadorCriativoController.php -- FOUND, contem corpoDescartadoPeloPhp
- .planning/learnings/publicador-ml.md -- FOUND, contem "PHP-FPM de producao tinha limite de upload menor"
- Commit 6726135f -- FOUND em git log --oneline
- Commit 950bfd63 -- FOUND em git log --oneline
- Commit 62b274a7 -- FOUND em git log --oneline

## Addendum 261007 -- segundo defeito, achado em producao (tela preta)

Depois do SUMMARY acima, o coordenador reportou um segundo defeito no MESMO
painel, com causa ja medida em producao: kit.estrategia e um OBJETO (3
campos de texto da IA -- publico/direcao_visual/proposta_de_valor,
CreativePlan::$estrategia no backend) e Mesa/PainelCriativos.jsx renderizava
{kit.estrategia} cru dentro de um <p>. React recusa objeto como filho
("Objects are not valid as a React child") e derruba a arvore inteira --
nao foi um erro de rede, a PAGINA morreu. Aconteceu bem onde a Task 1 deste
mesmo quick tinha corrigido o loop de render (kit.status === 'planejado',
a tela de confirmacao de custo).

### Por que passou pelos 158 testes anteriores

Todos os testes de tests/js/publicador-mesa.test.js (e os outros deste
diretorio) leem a FONTE como texto e conferem padroes com regex
(lerSemComentarios + assert.match) -- nenhum deles IMPORTA nem MONTA o
componente. Um objeto virando filho cru do React so quebra em tempo de
RENDER; nenhuma leitura de texto pega isso. O contrato entre o presenter PHP
(PublicadorCriativoKitPresenter::paraTela()) e o painel React nunca foi
exercitado de verdade por nenhum teste -- essa fenda exata foi por onde um
objeto virou tela preta em producao. Essa e a licao: gate de estrutura
prova que o VOCABULARIO da tela esta certo, nunca que ela RENDERIZA.

### Correcao

Mesa/PainelCriativos.jsx ganhou:

- textoSeguro(v): string/numero passam, qualquer outra coisa (objeto,
  array) vira null -- a UNICA porta de entrada para texto vindo do
  presenter que carregue texto livre da IA.
- Estrategia({estrategia}): aceita objeto (formato real de hoje, vira 3
  linhas rotuladas em pt-BR -- "Para quem e" / "O que destaca" / "Como vai
  parecer"), string (fallback) ou qualquer outro formato (nao renderiza
  nada, nunca o objeto cru).

### Varredura do item 3 (achou mais 6 pontos, nenhum novo bug confirmado em producao)

Varri Mesa/PainelCriativos.jsx inteiro contra as chaves reais do presenter
(kit: kit_id, grupo, status, etapa, em_andamento, erro, estrategia,
minimo_aprovadas, prontas, aprovadas, prontas_sem_risco, reprovadas,
referencias, slots; slot: indice, tipo, rotulo, objetivo, status, etapa,
erro, imagem_url, modelo, latencia_ms, regeneracoes, regeneracoes_restantes,
validacao_status, validacao_mensagem, validacao_problemas, pode_aprovar,
exige_confirmacao_risco, no_anuncio, imagem_id). KitCriativosGrade.jsx (fora
dos limites, D-08) nao foi tocado nem lido.

Pontos sem tipagem de runtime que agora passam por textoSeguro() por
precaucao (nenhum confirmado como objeto em producao, diferente de
estrategia -- mas tambem nenhum TEM garantia de formato em tempo de
execucao so porque o PHP documenta um shape num docblock):

1. kit.erro (dois pontos de render) -- semToken(?string) tipa o PARAMETRO
   no PHP, entao um array ali já quebraria o BACKEND com TypeError antes de
   chegar ao front; textoSeguro aqui e so uma segunda trava.
2. slot.rotulo -- `$padrao['rotulo'] ?? $slot->slot`: catalogo estatico do
   PHP (CreativeSlotCatalog), string por construcao; sem risco real hoje.
3. slot.objetivo -- `$slot->slot_plano['objetivo'] ?? ...`: protegido a
   montante pelo DTO CreativeSlotPlan (public string $objetivo, tipagem
   escalar do construtor PHP), MAS so protegido enquanto slot_plano for
   sempre escrito atraves desse DTO -- nao confirmei que nenhum outro
   caminho grava a coluna direto.
4. slot.erro -- mesma garantia do kit.erro (semToken(?string)).
5. slot.validacao_mensagem -- mesma garantia (semToken(?string) +
   validacaoMensagem(): ?string no model).
6. slot.validacao_problemas[].explicacao -- NAO protegido por nenhum tipo
   escalar na linha do presenter (`$problema['explicacao'] ?? null`, acesso
   de array cru); o shape `array{explicacao: string}` do DTO
   CreativeValidacao e so docblock, nao e imposto em runtime. Este e o
   candidato mais parecido com estrategia -- se o juiz (Gemini) um dia
   devolver uma explicacao estruturada em vez de texto, quebraria do mesmo
   jeito. Protegido agora.
7. referencias[].nome -- atributo (alt=), nao filho; nao dispara "Objects
   are not valid as a React child" por si so, mas protegido por consistencia
   (evita "[object Object]" como texto do atributo).

### Teste de regressao real (nao estrutural)

tests/js/publicador-painel-criativos-render.test.js -- compila
PainelCriativos.jsx DE VERDADE com esbuild (mesmo motor do Vite, resolvendo
o alias @ e os imports reais ./botoes e ./comum) e renderiza com
react-dom/server, usando o JSON real do presenter (inclusive o dump literal
de producao do kit id 2). 5 casos: estrategia objeto real (3 linhas
rotuladas, sem "[object Object]"), estrategia string, estrategia em formato
inesperado (array/numero/booleano, nunca lanca), kit.erro + slot.rotulo +
slot.objetivo + validacao_mensagem + explicacao em formato inesperado
simultaneamente (nunca lanca, fallbacks aparecem, campos validos do
segundo slot continuam de pe), estrategia ausente.

Confirmado que o teste PEGA a regressao: extrai a versao de
Mesa/PainelCriativos.jsx de HEAD (antes desta correcao, ainda com
{kit.estrategia} cru) para um arquivo irmao temporario dentro de Mesa/ (para
os imports relativos ./botoes/./comum resolverem), roda o MESMO harness de
build+render, e ele lanca exatamente "Objects are not valid as a React
child (found: object with keys {publico, direcao_visual,
proposta_de_valor})." -- a mesma mensagem da producao. Arquivos temporarios
apagados depois (git status limpo).

### Testes e build (medidos no addendum)

| Suite | Comando | Resultado |
|---|---|---|
| tests/js/publicador-mesa.test.js + publicador-editor.test.js | node --test | 158/158, 0 falhas |
| tests/js/publicador-painel-criativos-render.test.js (novo) | node --test | 6/6 (1 pai + 5 casos) |
| Suite JS inteira | npm run test:js | 973 testes, 971 passam, 2 falhas pre-existentes (as mesmas de sempre -- estrutura-grade-glide.test.js e polosEntrantes.test.js; 973 = 967 do baseline + 6 do teste novo) |
| Build de producao | npm run build | built in 47.32s, sem erros; Editor-D6JbV_LC.js contem "Para quem e" |

Nenhum arquivo PHP foi tocado neste addendum (confirmado por git status
antes do build) -- os 803 testes de Publicador e os 152 de Phase165 medidos
no SUMMARY original continuam validos sem necessidade de nova rodada.

### Commits do addendum

- 61353dbd -- fix(261005-si3): painel de criativos nao renderiza mais
  objeto cru (tela preta) + novo teste de render real
