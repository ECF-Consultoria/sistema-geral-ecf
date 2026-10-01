# Publicador da Incubadora — o que não se deduz do código

Leitura recomendada antes de mexer em `/incubadora/publicador`. Módulo começado
em 01/10/2026 para ser construído **pelos dois devs juntos**; o lugar definitivo
dele no sistema ainda não foi decidido. Prazo combinado: publicador funcionando
na Incubadora até **20/10/2026**.

## 0. Oculto de propósito — só por URL e só para Dev

Sem item de menu. A rota tem `modulo:incubadora.publicador`: quem não tem o
cargo Dev (`users.is_dev`) recebe **404**, mesmo com a URL na mão. O módulo está
no catálogo (`App\Support\Modules`, grupo "Incubadora", `nasce_oculto`), então
o `modules:sync` cria a linha já oculta. Abrir para mais gente = ligar em
Dev → Controle Dev, sem deploy. Antes do `sync`, também só o Dev entra.

## 1. Onde está cada coisa (e por que fora do `web.php`)

- Rotas: `routes/incubadora_publicador.php`, registrado no `bootstrap/app.php`
  (`then`), como o `mlb_anuncios.php` — para os dois devs não colidirem no
  `routes/web.php`.
- Controller: `IncubadoraPublicadorController`. Serviços:
  `app/Services/Incubadora/Publicador/`. Tela: `Pages/Incubadora/Publicador/`.
- Testes: `tests/Feature/IncubadoraPublicador/` (Http::fake, SQLite em memória).
- Nada é gravado no banco ainda: a seleção de termos vive na tela. Quando
  precisar de tabela, o desenho vai por escrito antes (CLAUDE.md).

## 2. Passo 1: nome → categoria → termos mais buscados

- Categoria: `domain_discovery` (preditor do ML) + `GET /categories/{id}` em
  paralelo, para mostrar o caminho inteiro ANTES de escolher (nomes parecidos
  só se distinguem pela árvore). App token, nunca o token de uma empresa.
- Termos: `GET /trends/MLB/{categoria}`. O que a doc oficial garante
  (conferido em 01/10): **até 50 termos, atualizados toda semana, sem volume —
  só a posição**. Com a lista cheia: 1–10 maior crescimento, 11–30 mais
  desejados, 31–50 mais populares. Com menos de 50 a doc não diz como divide,
  então a tela não mostra grupo. Vem marca de concorrente (husky, pichau,
  nike): quem decide é o operador.
- Folha de nicho traz menos termos (Mesas de Jantar e Cozinha: 43; Cadeiras
  Gamer: 15). Cada nível do caminho é clicável para ver os termos dele.
- **Destaque "relacionado" exclui as palavras do caminho da categoria.**
  Medido com "mesa de jantar redonda 4 lugares": contando "mesa", 40 de 43
  termos ficavam marcados e o destaque não dizia nada. Sem as palavras do
  caminho sobram "redonda" e "lugares" (12 termos). Num nível acima ("Móveis de
  Cozinha") "mesa" volta a distinguir — a régua é o caminho do nível mostrado.

## 3. Armadilha: `MlCatalogoMetaService::categoria()` guarda falha por 7 dias

Lá é `Cache::remember`, e uma falha passageira do ML devolve `[]` — que fica no
cache por 7 dias (o wizard `/mlb/anuncios` e o Portal usam esse método). O
Publicador lê a categoria pelo próprio lote (`CategoriaSugestaoService`), que
não grava falha e trata `[]` no cache como ausente. Mesma chave de cache
(`ml_meta_categoria_{id}`), então o que um lê bom o outro aproveita.

## 4. Estado da Incubadora em produção (01/10/2026)

O serviço "Incubadora" (`servicos.id = 4`) tinha **0 contratos**; só 3
`mlb_empresas` antigas com `projeto = 'Incubadora'`, nenhuma com `Company` e
nenhuma com token ML. Publicar exige `Company` + `ml_tokens` ativo — os
clientes da Incubadora precisam ser cadastrados e conectar o OAuth primeiro.

## 5. O que a API do ML permite nos próximos passos (doc oficial, 01/10)

| Alavanca | API? | Ressalva |
|---|---|---|
| Comissão (`/sites/MLB/listing_prices`) | Ler | Sem `logistic_type` + `shipping_mode` a tarifa fixa sai errada; a % muda por faixa de preço (2 rodadas). Nenhum código do sistema usa ainda |
| Frete (`/users/{id}/shipping_options/free`) | Estimativa | Mandar preço, tipo e logística, senão vem 0 |
| Desconto individual (PRICE_DISCOUNT) | Criar | Reputação verde; 5–80%; **máx. 14 dias** (overprice sem renovação = anúncio 20% mais caro); item sem venda pode não ser candidato |
| Campanha do vendedor / cupom | Criar | Reputação verde; campanha 14 dias, cupom 1–31 dias com orçamento |
| Atacado | Só B2B habilitado | Venda ao consumidor final só para pneus; para móveis, o equivalente é `VOLUME` |
| Afiliados | **Não há API** | Só pelo painel |
| Product Ads | Ler | Criar não documentado no MLB; conta com 15+ dias e vendas |
| Capa ambientada | Sim, em parte de Casa e Móveis | Conferir por categoria com `/moderations/pictures/diagnostic` |
| AdMan (MCP) | Privado | O sistema só lê; os agentes dela disparam por desempenho, não "ao publicar" |

Permissões "Promoções, cupons e descontos" e "Publicidade" precisam estar
ligadas no DevCenter ANTES de os clientes conectarem — senão reconectar todos.
