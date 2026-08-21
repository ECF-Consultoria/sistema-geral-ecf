# A régua do onboarding é congelada no nascimento — e o congelamento tem TRÊS dimensões

Leitura obrigatória antes de mexer em `DefinicaoOnboarding`, e **especialmente**
antes de dizer "mudei a régua" para alguém do negócio.

## 1. O que "congelado" significa na prática

`DefinicaoOnboarding` é a receita em código, mas ela **não é lida em runtime**
pelos onboardings que já existem. `montarPassos()` a COPIA para colunas de
`onboarding_passos` no nascimento. Cada onboarding carrega a definição com que
nasceu — é isso que garante que o processo não muda debaixo de quem já está
rodando.

O efeito colateral é o que morde: **deployar uma mudança na definição não muda
nada para ninguém que já existe.** A mudança vale só para empresas futuras.

## 2. As três dimensões, e as três ferramentas

O congelamento tem três formas de divergir, e por muito tempo só duas tinham
comando:

| O que mudou na régua | Efeito em quem já roda | Ferramenta |
|---|---|---|
| Passo **entrou** | não recebe o passo | `onboarding:aplicar-passos-novos --apply` |
| Passo **saiu** | continua cobrando o passo | `onboarding:remover-passos-fora-da-regua --apply` |
| Passo **continua, mas mudou de `depende_de`** | continua com a dependência velha | `onboarding:sincronizar-dependencias --apply` *(criado em 2026-08-19)* |

A terceira faltava, e é a mais silenciosa: nada quebra, nada aparece no log — o
passo simplesmente fica `bloqueado` esperando algo que a régua nova já não pede.

**O caso concreto que a criou:** na v13 `agendar_reuniao_onboarding` deixou de
depender de `metricas_da_conta` e `anuncios_ativos_inativos`, porque o negócio
inverteu a premissa (nós marcamos a data e cobramos o cliente, em vez de esperar
que ele conceda acesso). Sem o comando, **todo onboarding em curso continuaria
com a primeira etapa da tela BLOQUEADA** — ou seja, a mudança inteira valeria só
para empresa nova, exatamente o oposto do que se pediu.

## 3. O que o dry-run revelou, e por que ele importa em produção

Rodar `onboarding:sincronizar-dependencias` (dry-run) no banco local mostrou
**18 divergências em 4 onboardings** — não só a da v13. Os onboardings locais
nasceram nas v4/v5 e carregavam dependências de várias versões atrás, inclusive
a ordem **invertida** de antes da v5 (`grant_sistema_ecf` dependendo de
`acesso_colaborador_ml`, quando hoje é o contrário).

Consequência para produção: **sincronizar sem `--chave` realinha TUDO**, não só
a mudança que você acabou de fazer. Afrouxar dependência só destrava, mas o
comando também sabe apertar — se a régua passou a exigir uma dependência nova,
um passo hoje aberto vira bloqueado, e isso muda o que a tela cobra de uma
empresa em andamento.

Por isso: em produção, rode **escopado** à mudança que você fez.

```bash
php artisan onboarding:sincronizar-dependencias --chave=agendar_reuniao_onboarding
# confira a tabela do dry-run e só então:
php artisan onboarding:sincronizar-dependencias --chave=agendar_reuniao_onboarding --apply
```

## 4. `titulo` também é congelado — e isso aparece para o CLIENTE

`etapa`, `dono`, `sla_dias`, `depende_de` **e `titulo`** são estrutura: vão para
a coluna. Corrigir um título na definição não alcança quem já existe.

Em 2026-08-19 cinco títulos estavam sem acento (`Participantes das reunioes
cadastrados`, `Analista responsavel definido`, `Operacao da publicidade
explicada`, `Informacoes da ADMAN preenchidas pelo Analista`, `Agenda das
reunioes quinzenais definida`). Um deles é `dono=cliente` — ou seja, o cliente
lia "reunioes" sem acento no portal. Corrigidos na definição; **quem já existe
segue com o título velho**, e nenhum comando o reescreve (reescrever exigiria
furar o congelamento de propósito).

Contraste deliberado: `instrucaoDe()`, `tutorialDe()` e `passoAPassoDe()` são
**conteúdo**, moram em código e NÃO são copiados — corrigir uma frase confusa
precisa alcançar justamente quem está travado por não tê-la entendido.

## 5. Ao mudar a régua, o checklist é

1. Editar `DefinicaoOnboarding` e **subir `VERSAO`** (com nota no docblock
   dizendo o que mudou e por quê — é o histórico que se relê).
2. Ajustar os testes que fixam a `VERSAO` (há dois:
   `OnboardingNaturezaTest` e `Phase135/OnboardingEtapasEInstrucoesTest`).
3. Ajustar `Phase135/OnboardingEngineDependenciasTest` se mexeu em dependência —
   ele tem duas listas literais (`$semDependencia` / `$comDependencia`).
4. Rodar as três ferramentas da tabela §2 em **dry-run**, ler a tabela, e só
   então `--apply`.
5. Lembrar que `--apply` **não** carimba `definicao_versao` (é preciso
   `--carimbar-versao`) — de propósito: a versão responde "sob qual receita esta
   empresa entrou?", não "qual régua ela está seguindo agora".
