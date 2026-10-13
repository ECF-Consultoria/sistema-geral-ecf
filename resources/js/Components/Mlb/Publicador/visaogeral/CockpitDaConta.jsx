import BannerDeIntegracao from './BannerDeIntegracao';
import JornadaDosProdutos from './JornadaDosProdutos';
import OQueFazerAgora from './OQueFazerAgora';
import SaudeDaConta from './SaudeDaConta';
import PublicacoesNoMercadoLivre from './PublicacoesNoMercadoLivre';
import FaixaDeDesempenho from './FaixaDeDesempenho';
import PublicacoesRecentes from './PublicacoesRecentes';
import AtividadeRecente from './AtividadeRecente';
import { numeroSeguro } from './acoes';
import { textoUtil } from '../BarraDaConta';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 179, plano 09 — a COMPOSIÇÃO da Visão geral (VG-01).
//
// Protótipo: `new_layout_publicador/Visao Geral.dc.html`. Os CINCO filhos
// diretos do `<main>` (`:25`), medidos com `grep -n` no arquivo:
//   1. `:27`  banner de integração (dentro de `sc-if hasBanner`)
//   2. `:40`  a Jornada dos produtos (`<section>`)
//   3. `:164` a grade `minmax(0,1fr) 392px`, `gap:20px`, `align-items:start`
//   4. `:286` a faixa de Desempenho
//   5. `:312` a grade `minmax(0,1fr) minmax(0,1fr)`, `gap:20px`, `align-items:start`
// E **nada depois**: a grade 5 fecha em `:383` e o `</main>` é `:384`.
//
// ⚠️ Este componente devolve um FRAGMENTO de propósito: no protótipo os cinco
// blocos são filhos DIRETOS do `<main>`, que é quem carrega
// `display:flex;flex-direction:column;gap:20px`. Um wrapper aqui criaria um
// segundo nível de `gap` e quebraria o ritmo vertical de 20px. O container
// (com o `gap-[20px]`) mora na PÁGINA — `Pages/Mlb/Publicador/VisaoGeral.jsx`.
//
// ⚠️ As URLs são montadas AQUI, uma vez, e descem como prop. Nenhum bloco
// monta URL por conta própria e nenhuma URL é concatenada à mão.
//
// ─────────────────────────────────────────────────────────────────────────────
// ⚠️ OS DOIS BLOCOS QUE NÃO NASCEM NESTA TELA — decisão informada do usuário
//    em 12/10/2026. NÃO RESTAURE SEM UMA DECISÃO NOVA DELE.
// ─────────────────────────────────────────────────────────────────────────────
//
// "Produtos por fase" (os 5 buckets) e "Quem publicou" (o agregado de 30 dias)
// existiam em `PainelVisaoGeral.jsx` e **não têm bloco equivalente aqui**. Não
// há bloco preservado, não há apêndice depois da grade 5 e os dois NÃO foram
// para "Configurações da conta".
//
// É uma EXCEÇÃO EXPLÍCITA do usuário à regra-mestra "melhorar sem regredir",
// que valeu na milestone v26.0 inteira — até aqui a única remoção autorizada
// era o seletor de período. Como a exceção foi concedida:
//
//   1. Foi MEDIDO que os dois são o ÚNICO caminho desses dados no sistema:
//      `Produtos.jsx` recebe `contagens` mas não renderiza `por_fase`, e
//      nenhuma outra tela recebe `quemPublicou`.
//   2. Pelo precedente LIST-25 ("o atalho sai, o dado não; se for o ÚNICO
//      caminho para algum dado, a fase PARA e PERGUNTA"), a fase parou e
//      perguntou.
//   3. O usuário recebeu as TRÊS opções (preservar no fim da página · mover
//      para Configurações da conta · tirar os dois), com o aviso explícito de
//      que tirar só deveria ser escolhido se ele não usa nenhum dos dois, e
//      ESCOLHEU TIRAR, em 12/10/2026.
//   4. LIST-25 não foi violado: foi cumprido. A decisão de perder o dado é do
//      dono do produto, não do planejador.
//
// ⚠️ As props `produtosPorFase` e `quemPublicou` CONTINUAM CHEGANDO do servidor
// (as 23 props do plano 179-05) e isso é PROPOSITAL: a decisão do usuário é de
// TELA, e payload não é tela. Parar de enviá-las exigiria cirurgia em 14
// asserções de `ListaPorFaseTest`/`VisaoGeralTest`, uma delas um teste cujo
// propósito inteiro é provar que o contrato não perde prop. Prop órfã aqui é
// deliberada — não "limpe".
//
// A ausência é travada por asserção NEGATIVA em
// `tests/js/publicador-visao-geral-cockpit.test.js`, rodada com as duas props
// PREENCHIDAS (com elas vazias a ausência seria vazia por construção).
// ═══════════════════════════════════════════════════════════════════════════

/**
 * A legenda do recorte temporal (VG-03 e critério 1 do ROADMAP).
 *
 * ⚠️ O critério 1 pede DUAS coisas: o seletor de período sai **e** a UI
 * **diz** o recorte de cada bloco. A Jornada já traz "Estado atual · independe
 * do período" no próprio card (plano 179-06) e "Publicações recentes" já diz
 * "…nos últimos 30 dias" no subtítulo (plano 179-08). Faltava dizer isso de
 * Saúde, Ações e Atividade — é o que esta linha faz, numa frase só.
 *
 * ⚠️ Divergência DECLARADA do protótipo: ele não tem esta linha (nem tem o
 * seletor de período, que também não existe lá). Ela entra por exigência do
 * critério 1, não por adaptação de layout.
 */
export const LEGENDA_DO_RECORTE =
    'Jornada, Saúde e Ações mostram o estado atual. Recentes e Atividade mostram os últimos 30 dias.';

/** Objeto simples ou `{}` — nunca array, nunca `null`, nunca escalar. */
function objeto(valor) {
    return valor !== null && typeof valor === 'object' && !Array.isArray(valor) ? valor : {};
}

/**
 * A chave CANÓNICA da conta, ou `null`.
 *
 * ⚠️ [Rule 2] `textoUtil` não basta aqui, e o próprio gate deste plano pegou:
 * `textoUtil(7)` devolve a STRING `'7'` (`textoSeguro` aceita número), e isso
 * montava `route('…', { conta: '7' })` — um destino que **não pode existir**,
 * porque a rota declara `->where('conta', '(empresa|company)-[0-9]+')` e
 * responderia 404. É a mesma arapuca que o 179-07 registrou: *"guarda de
 * DESTINO e de PARÂMETRO mais estrita que guarda de TEXTO"*.
 *
 * A forma aceita é a MESMA que a rota aceita, nem mais larga nem mais
 * estreita: `empresa-N` ou `company-N`.
 */
export function chaveDeContaSegura(valor) {
    const texto = textoUtil(valor);
    return texto !== null && /^(empresa|company)-[0-9]+$/.test(texto) ? texto : null;
}

/**
 * A URL da lista de Produtos da conta.
 *
 * ⚠️ Sem chave canónica a prop desce `null` e os CTA da Jornada simplesmente
 * não nascem (EXMP-01 — botão sem destino é pior que botão ausente).
 */
export function urlDeProdutos(empresa) {
    const conta = chaveDeContaSegura(objeto(empresa).chave);
    if (conta === null) return null;

    try {
        return route('mlb.anuncios.publicador.produtos', { conta });
    } catch {
        return null;
    }
}

/**
 * A URL de "Meus anúncios" (as publicações da conta no Mercado Livre).
 *
 * ⚠️ D23: conta sem `Company` não tem `abas.company_id`, e aí a prop desce
 * `null` — "Ver todas →" e o "Ver todas as publicações →" não nascem, e as
 * linhas de recente deixam de ser `<a>`. O resto dos dois blocos continua.
 */
export function urlDePublicacoes(abas) {
    const company = numeroSeguro(objeto(abas).company_id);
    if (company === null || company <= 0) return null;

    try {
        return route('mlb.anuncios.meus', { company });
    } catch {
        return null;
    }
}

/**
 * O cockpit da Visão geral: os cinco blocos do protótipo, na ordem e nas
 * grades medidas, mais a legenda do recorte.
 *
 * Recebe as props do servidor (`MlbPublicadorEntradaController::visaoGeral()`,
 * plano 179-05) e distribui. ⚠️ Não passa `expandidasIniciais`,
 * `atualizandoInicialmente`, `chipInicial` nem `agora`: as quatro são props de
 * ESTADO INICIAL que existem só para os gates dos planos 179-07/179-08 não
 * serem vazios por construção. Em produção o estado é o do protótipo — tudo
 * fechado, sem spinner, chip "tudo".
 */
export default function CockpitDaConta({
    empresa,
    abas,
    banner,
    jornada,
    acoes,
    saude,
    publicacoesMl,
    acervoImagens,
    desempenho,
    recentes,
    atividade,
}) {
    const urlProdutos = urlDeProdutos(empresa);
    const urlPublicacoes = urlDePublicacoes(abas);
    // ⚠️ A MESMA guarda canónica: `contaChave` vira parâmetro de URL no POST de
    // "Adiar até amanhã" (`publicador.acoes.adiar`, que tem o MESMO `where`).
    const contaChave = chaveDeContaSegura(objeto(empresa).chave);

    return (
        <>
            {/* 1 — o banner (`:27`): só nasce com problema de integração. */}
            <BannerDeIntegracao banner={objeto(banner)} />

            {/* 2 — a Jornada dos produtos (`:40`). */}
            <JornadaDosProdutos jornada={objeto(jornada)} urlProdutos={urlProdutos} />

            {/* A legenda do recorte (VG-03) — no topo da grade 3, como irmã
                direta dela, para o ritmo de 20px do container valer para ela
                também. */}
            <p className="m-0 text-[12px] text-pub-txt-4">{LEGENDA_DO_RECORTE}</p>

            {/* 3 — a grade `minmax(0,1fr) 392px` (`:164`). */}
            <div className="grid grid-cols-[minmax(0,1fr)_392px] items-start gap-[20px]">
                <OQueFazerAgora acoes={objeto(acoes)} contaChave={contaChave} />

                {/* A coluna lateral (`:235`): `white-space:nowrap; flex column;
                    gap:20px`. ⚠️ O `whitespace-nowrap` é do protótipo e NÃO é
                    enfeite: a coluna é fixa em 392px e os dois cards de dentro
                    foram desenhados (planos 179-07) lendo estas mesmas linhas,
                    já herdando o nowrap. */}
                <aside className="flex flex-col gap-[20px] whitespace-nowrap">
                    <SaudeDaConta saude={objeto(saude)} />
                    <PublicacoesNoMercadoLivre
                        publicacoesMl={objeto(publicacoesMl)}
                        acervoImagens={objeto(acervoImagens)}
                        urlPublicacoes={urlPublicacoes}
                    />
                </aside>
            </div>

            {/* 4 — a faixa de Desempenho (`:286`). */}
            <FaixaDeDesempenho desempenho={objeto(desempenho)} />

            {/* 5 — a grade `minmax(0,1fr) minmax(0,1fr)` (`:312`).
                ⚠️ É o ÚLTIMO bloco da página: no protótipo ela fecha em `:383`
                e o `</main>` é `:384`. Nada de apêndice depois dela. */}
            <div className="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)] items-start gap-[20px]">
                <PublicacoesRecentes recentes={objeto(recentes)} urlPublicacoes={urlPublicacoes} />
                <AtividadeRecente atividade={objeto(atividade)} />
            </div>
        </>
    );
}
