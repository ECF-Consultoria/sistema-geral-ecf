import AppLayout from '@/Layouts/AppLayout';
import { textoSeguro } from '@/Components/Mlb/Publicador/BarraDaConta';
import PublicadorHeader from '@/Components/Mlb/Publicador/header/PublicadorHeader';
import CockpitDaConta from '@/Components/Mlb/Publicador/visaogeral/CockpitDaConta';

/**
 * Página "Visão geral" do Publicador — primeira aba da conta, destino ao abrir
 * uma empresa (ver `AnunciosEmpresas.jsx`).
 *
 * ⚠️ Fase 177, plano 07 — `BarraDaConta` + `AbasDaConta` saíram e o
 * `PublicadorHeader` global entrou no lugar das duas. Ele é o PRIMEIRO filho do
 * `AppLayout`, FORA do container do corpo: é ele que carrega o `-mx-6 -mt-6`
 * que neutraliza o topo e as laterais do `p-6` do `<main>` (HEAD-14), e por
 * isso não pode nascer dentro de um container com padding lateral.
 *
 * O que o header resolve por dentro, a partir da prop `cabecalho`
 * (`CabecalhoPublicadorService::para()`, plano 177-03): nome e chave da conta,
 * selos de conexão, "trocar empresa", as abas com contadores e o D23. A página
 * não passa nada disso à mão.
 *
 * ⚠️ `cabecalho ?? null`: o default de parâmetro só cobre `undefined`, e
 * `cabecalho: null` numa recarga parcial do Inertia já derrubou tela aqui.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Fase 179, plano 09 — o corpo virou o COCKPIT (VG-01)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * O corpo da tela é `Components/Mlb/Publicador/visaogeral/CockpitDaConta.jsx`:
 * os cinco blocos do protótipo (`new_layout_publicador/Visao Geral.dc.html`),
 * na ordem e nas grades medidas lá. Ele devolve um FRAGMENTO — os blocos são
 * filhos diretos do container abaixo, que é quem carrega o `gap-[20px]`,
 * exatamente como o `<main>` do protótipo (`:26`).
 *
 * ⚠️ `PainelVisaoGeral.jsx` saiu do import desta página, mas o ARQUIVO não foi
 * apagado nem modificado: ele fica como ÓRFÃO DECLARADO, como `BarraDaConta` e
 * `AbasDaConta` ficaram na Fase 177. É o desenho de GATE-01 aplicado da forma
 * mais barata possível — os gates `publicador-visao-geral-render.test.js` (33
 * casos) e `publicador-dashboard-v2.test.js` (56 casos) o renderizam DIRETO e
 * seguem verdes sem nenhuma cirurgia, porque as 14 props antigas continuam
 * chegando do servidor com o mesmo nome e o mesmo valor (plano 179-05).
 * Nenhuma asserção de comportamento foi apagada e nenhuma foi afrouxada.
 *
 * ⚠️ A ARITMÉTICA DO `p-6`, e por que ela não é opcional. O `<main>` do
 * `AppLayout` tem `p-6` (24px) — e `AppLayout.jsx` é território PROIBIDO nesta
 * fase (D2/HEAD-14), então a neutralização é AQUI. O protótipo mede
 * `padding:24px 36px 80px` a partir da borda da página (`:26`). Sem
 * neutralizar, o lateral efetivo seria 24 + 36 = 60px e o inferior
 * 80 + 24 = 104px.
 *
 * ➡️ São DOIS elementos, e não um:
 *   · o externo com `-mx-6 -mb-6` (cancela o `px-6` e o `pb-6` do `<main>`);
 *   · o interno com `mx-auto max-w-[1600px] min-w-[1240px]` e o padding do
 *     protótipo.
 * Não dá para fundir os dois: `-mx-6` e `mx-auto` disputam a mesma margem
 * lateral — `mx-auto` precisa da margem automática para centrar, e a margem
 * negativa a sobrescreveria.
 *
 * O `pt-6` do `<main>` já é cancelado pelo `-mt-6` do header, então o
 * `pt-[24px]` do interno é medido a partir da borda inferior do header, que é
 * o que o protótipo desenha.
 *
 * ⚠️ `min-w-[1240px]` é FID-05: a tela é desktop-first em 1440 e abaixo de
 * 1240 rola na horizontal, em vez de reflowar (o protótipo o declara na raiz,
 * `:22`).
 */
export default function VisaoGeral(props) {
    const { empresa = {}, liberada = true, cabecalho = null } = props;
    const nome = textoSeguro(empresa?.nome, 'conta');

    return (
        <AppLayout title={`Publicador — ${nome}`}>
            <PublicadorHeader aba="visao-geral" cabecalho={cabecalho ?? null} liberada={liberada} />

            {/* A neutralização do `p-6` do `<main>` — ver a aritmética acima. */}
            <div className="-mx-6 -mb-6">
                <div className="mx-auto flex max-w-[1600px] min-w-[1240px] flex-col gap-[20px] px-[36px] pt-[24px] pb-[80px]">
                    <CockpitDaConta {...props} />
                </div>
            </div>
        </AppLayout>
    );
}
