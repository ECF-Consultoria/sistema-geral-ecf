// ─── Trilha de produtos — o 2º caminho até as pastas `Cadastro/`, `Revisao/` e `Cliente/` (é para o Vite) ──
//
// NÃO é uma tela: nenhum controller manda `Trilha/CadastroSobDemanda`, e nada
// aqui roda. O arquivo existe para o MANIFEST do Vite (3ª entrega, 03/11/2026).
//
// O problema: a página `Trilha/Empresa` carrega a pasta `Components/Trilha/Cadastro/`
// por `lazy` (só quem escreve baixa aquele JS — os textos são da equipe). Os
// pedaços do cadastro usam peças que só a página usa (`PainelLateral`, `Pecas`,
// `trilhaEscrita`…). Como o único caminho até eles passava pela página, o Rollup
// deixava essas peças DENTRO do pedaço da página e fazia o pedaço exportá-las
// para o cadastro — e um pedaço que exporta mais do que a página deixa de ser a
// "fachada" dela: o manifest perdia a chave `resources/js/Pages/Trilha/Empresa.jsx`
// (virava `_Empresa-….js`). O `@vite([... "resources/js/Pages/{$page['component']}.jsx"])`
// do `app.blade.php` então quebra a rota com "Unable to locate file in Vite
// manifest" — e o build sai 0, sem aviso nenhum.
//
// O conserto: este arquivo também é uma página para o `import.meta.glob` do
// `app.jsx`, e pede os mesmos pedaços. Com dois caminhos até eles (a página e
// este), o Rollup não pode supor que as peças da página já estão carregadas e as
// põe num pedaço próprio — o da página volta a ser só dela.
//
// A regra (conferida em tests/js/trilha-empresa-render.test.js e, para `Cliente/`,
// em tests/js/trilha-fotos-cliente.test.js): todo `lazy` de fora das pastas aponta
// para um pedaço que está aqui também. Apagar este arquivo, ou esquecer um pedaço
// novo aqui, tira a página do manifest.
//
// 4ª entrega (4a, 03/11/2026): a pasta `Components/Trilha/Revisao/` (a prévia do
// anúncio, só da equipe) entra pelo mesmo caminho — o pedaço dela usa as mesmas
// peças da página (`PainelLateral`, `Pecas`, `trilhaEscrita`).
//
// 4f (04/11/2026): a pasta `Components/Trilha/Cliente/` (as fotos que o CLIENTE
// envia, pedidas por `lazy` dentro do `PainelLateral`) também — o pedaço dela usa
// `Pecas` e `trilhaEscrita`, como a página.
//
// 4e (04/11/2026): a gaveta do cliente que aprova o anúncio (`Cliente/AprovarAnuncio`,
// pedida por `lazy` na página) — usa `PainelLateral`, `Pecas` e `trilhaEscrita`.

/** Os pedaços da pasta `Cadastro/` que a trilha carrega sob demanda (os mesmos `lazy` da página e da Lista). */
export const PEDACOS_DO_CADASTRO = {
    PainelDoCadastro: () => import('@/Components/Trilha/Cadastro/PainelDoCadastro'),
    NovoProduto: () => import('@/Components/Trilha/Cadastro/NovoProduto'),
    QuadrosDaLista: () => import('@/Components/Trilha/Cadastro/QuadrosDaLista'),
    PlanilhaPorCategoria: () => import('@/Components/Trilha/Cadastro/PlanilhaPorCategoria'),
    ImportarDoMercadoLivre: () => import('@/Components/Trilha/Cadastro/ImportarDoMercadoLivre'),
};

/** Os pedaços da pasta `Revisao/` (4ª entrega) que a página carrega sob demanda (os mesmos `lazy` dela). */
export const PEDACOS_DA_REVISAO = {
    PreviaDoAnuncio: () => import('@/Components/Trilha/Revisao/PreviaDoAnuncio'),
};

/**
 * Os pedaços da pasta `Cliente/` que a porta do cliente carrega sob demanda: as fotos (4f, o `lazy` do `PainelLateral`)
 * e a aprovação do anúncio (4e, o `lazy` da página).
 */
export const PEDACOS_DO_CLIENTE = {
    FotosDoProduto: () => import('@/Components/Trilha/Cliente/FotosDoProduto'),
    AprovarAnuncio: () => import('@/Components/Trilha/Cliente/AprovarAnuncio'),
};

/** Não desenha nada (não é uma tela). */
export default function CadastroSobDemanda() {
    return null;
}
