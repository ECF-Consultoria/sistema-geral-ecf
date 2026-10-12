import { Search } from 'lucide-react';
import { cn } from '@/lib/utils';
import { textoSeguro, textoUtil } from '../BarraDaConta';
import GlyphDaFase from '../identidade/GlyphDaFase';
import TileDoProduto from '../identidade/TileDoProduto';
import { CARREGANDO, ERRO, PLACEHOLDER, RODAPE, VAZIO } from './novaPublicacao';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 178, plano 02 — o CORPO do modal "Nova publicação" (NOVA-01..NOVA-06).
//
// Protótipo: `new_layout_publicador/PublicadorHeader.dc.html`
//   · cabeçalho de 60px   → :135   (ícone :136, input :137, tecla Esc :138)
//   · corpo de 420px      → :140
//   · grupo               → :142   (título + hint :143)
//   · item de 50px        → :145   (tile :146, coluna :147, nome :148,
//                                   meta/SKU :149, CTA :151)
//   · tons do CTA         → :213   (o objeto `meta`)
//   · vazio               → :156
//   · rodapé              → :158   (glyph :159, frase :160)
//
// ⚠️ EXPORT NOMEADO E COMPONENTE PURO, por um motivo medido: o renderizador de
// servidor do React **não suporta portal**. A casca do modal (wave 3) é um
// `Dialog.Portal`, e gate por leitura de fonte é fraco — foi o que o plano
// 177-04 teve de reescrever depois de 4 mutações PASSAREM. Com o corpo aqui
// fora, o gate renderiza de verdade e compara a lista de classes elemento por
// elemento. Por isso: **nenhum `useState`, nenhum `useEffect`, nenhuma chamada
// de rede.** Tudo chega por prop.
//
// ⚠️ FID-03: 13,5px é `text-[13.5px]`, 11,5px é `text-[11.5px]`. A escala
// nomeada do Tailwind arredonda e muda o desenho — `text-sm` é 14px.
// ⚠️ FID-04/TOK-15: nada de `ecf-*`, `font-display`, `border-white/[0.08]` nem
// hex literal. Cor por nome de token `pub-*`.
//
// ─── Divergências DECLARADAS (restrição concreta, não preferência) ───
//
// 1. `carregando` e `erro` não existem no protótipo, porque a lista dele é um
//    mock constante. Com fetch real existem, e lista vazia em silêncio é
//    proibida (mesma disciplina de `PainelDeEmpresas`). As duas frases usam a
//    MESMA geometria do vazio (:156), para não haver um segundo desenho de
//    "nada aqui".
// 2. `truncate` no nome do produto: o protótipo tem `white-space:nowrap` no
//    botão e nome longo estouraria a caixa de 620px do modal. É a mesma adição
//    defensiva do `flex-none` do plano 177-04.
// 3. `flex-none` no ícone de busca, na tecla `Esc` e no CTA: sob
//    `white-space:nowrap` e com a coluna do meio em `flex-1`, sem isso os três
//    encolhem. O protótipo declara `flex:none` no tile (:146) e não nos outros
//    três — mas ali o dado é curto e fixo.
// 4. `placeholder:text-pub-txt-4`: o protótipo não declara cor de placeholder
//    (fica a do navegador). É o mesmo token que o input do dropdown de empresa
//    usa (177-06), para os dois campos do header não divergirem.
//
// ⚠️ O que NÃO está aqui: `font-family: inherit` do input (:137). O preflight do
// Tailwind já aplica `font-family: inherit` a `input`/`button`/`textarea`; o
// protótipo precisa declarar porque é HTML cru sem preflight.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * As classes do CTA por `tom` — a tradução do objeto `meta` do protótipo :213:
 *   ready → fundo `rgba(250,204,21,0.14)` + texto `#FACC15`
 *   none  → fundo `#1F1F1F`              + texto `#F4F4F5`
 *   draft → fundo `#1F1F1F`              + texto `#A1A1AA`
 *
 * ⚠️ A tinta `rgba(250,204,21,0.14)` NÃO ganha token: sai do modificador de
 * opacidade do próprio `pub-accent`, que é o mesmo valor computado com um nome
 * só (é o que diz o comentário da paleta em `tailwind.config.js`).
 */
const TOM_DO_CTA = {
    accent: 'bg-pub-accent/[0.14] text-pub-accent',
    solido: 'bg-pub-line-subtle text-pub-txt',
    discreto: 'bg-pub-line-subtle text-pub-txt-2',
};

/**
 * ⚠️ Lookup por comparação explícita, e NÃO `TOM_DO_CTA[tom]`: indexar um objeto
 * literal com texto que veio de fora devolve `Object.prototype` para
 * `'__proto__'` — um OBJETO entrando no `cn()`. É a mesma família da tela preta
 * de 07/10 (campo não-escalar chegando onde se esperava texto).
 */
function classesDoCta(tom) {
    if (tom === 'accent') return TOM_DO_CTA.accent;
    if (tom === 'solido') return TOM_DO_CTA.solido;
    return TOM_DO_CTA.discreto;
}

/** Item sem destino usável não vira clique mudo (EXMP-03). */
const TITULO_SEM_DESTINO = 'Este produto está sem destino no Publicador — abra a tela de Produtos da empresa para conferir o cadastro.';

/**
 * Uma linha de 50px da lista (protótipo :145-152).
 *
 * ⚠️ Função de MÓDULO, e toda flag nasce DENTRO dela: variável de escopo do
 * componente lida dentro de um `.map()` já foi eliminada pelo Rollup no bundle
 * de produção deste projeto (`feedback_rollup_map_scope_bug.md`). É a mesma
 * forma de `PainelDeEmpresas.linha` e de `AbasDoHeader.pilula`.
 */
function Item({ item, grupo, aoEscolher }) {
    const nome = textoSeguro(item?.nome);
    const sku = textoSeguro(item?.sku);
    const nota = textoSeguro(item?.nota);
    const capa = item?.capa;
    const destino = textoUtil(item?.url);
    const semDestino = destino === null;

    return (
        <button
            type="button"
            // ⚠️ GATE-05: `disabled` com o `=` no JSX. Item sem destino aparece
            // (esconder produto de uma lista que se diz completa é pior), mas
            // NÃO clica, e o `title` diz por quê.
            disabled={semDestino}
            aria-disabled={semDestino ? 'true' : undefined}
            title={semDestino ? TITULO_SEM_DESTINO : undefined}
            onClick={semDestino ? undefined : () => aoEscolher(item)}
            className={cn(
                'flex h-[50px] w-full items-center gap-[12px] whitespace-nowrap rounded-pub-sub border-none bg-transparent px-[10px] text-left text-pub-txt cursor-pointer hover:bg-pub-hover-3',
                semDestino && 'opacity-40',
            )}
        >
            {/* tile 34×34, raio 8px, letra 11px (:146) — REUSADO, não redesenhado.
                ⚠️ `letra` existe porque :146 mede 11px e a escada do tile
                devolve 10.5px para `lado <= 34`: a escada está certa nas telas
                onde foi medida e errada nesta. */}
            <TileDoProduto id={item?.produto_id} nome={nome} foto={capa} tamanho={34} letra="text-[11px]" />

            {/* coluna nome/meta (:147) */}
            <span className="flex min-w-0 flex-1 flex-col gap-[2px]">
                <span className="truncate text-[13.5px] font-medium">{nome}</span>
                <span className="text-[11.5px] text-pub-txt-3">
                    <span className="font-pub-mono text-[11px]">{sku}</span>
                    {' · '}
                    {nota}
                </span>
            </span>

            {/* CTA em pill (:151), com o tom do grupo (:213) */}
            <span className={cn('flex-none rounded-pub-control-sm px-[10px] py-[5px] text-[12px] font-semibold', classesDoCta(grupo?.tom))}>
                {textoSeguro(grupo?.cta, '')}
            </span>
        </button>
    );
}

/**
 * ⚠️ Export NOMEADO (e também default) — ver o aviso sobre portais no topo.
 *
 * @param {object} props
 * @param {string} [props.consulta]     o texto digitado; o estado é da wave 3
 * @param {Function} [props.aoConsultar] handler de `onChange` do input
 * @param {Array} [props.grupos]        a saída de `filtrarGrupos()`, já na ordem
 *   da tela, já sem grupo vazio e já com rótulo/hint/cta/tom
 * @param {boolean} [props.carregando]
 * @param {boolean} [props.erro]
 * @param {Function} [props.aoEscolher] recebe o item inteiro. ⚠️ Quem NAVEGA é a
 *   wave 3 — nenhum `router.get` aqui
 * @param {object} [props.inputRef]     para a casca focar o campo na abertura
 */
export function PainelNovaPublicacao({
    consulta = '',
    aoConsultar = () => {},
    grupos = [],
    carregando = false,
    erro = false,
    aoEscolher = () => {},
    inputRef = null,
}) {
    const cheios = Array.isArray(grupos) ? grupos : [];

    return (
        <>
            {/* ─── Cabeçalho de 60px (:135) ─── */}
            <div className="flex h-[60px] items-center gap-[12px] border-b border-pub-line-subtle px-[16px]">
                {/* ⚠️ `strokeWidth={2.4}` = 1.6 × 1,5 — o protótipo (:136) desenha
                    em `viewBox 0 0 16 16` e o Lucide em `0 0 24 24`, então o mesmo
                    traço precisa do fator 24/16. A conta está escrita no topo de
                    `PublicadorHeader.jsx`; não repetir o 1.6 cru. */}
                <Search size={18} strokeWidth={2.4} aria-hidden="true" className="flex-none text-pub-txt-2" />
                <input
                    ref={inputRef}
                    type="text"
                    value={consulta}
                    onChange={aoConsultar}
                    placeholder={PLACEHOLDER}
                    aria-label={PLACEHOLDER}
                    className="h-full flex-1 border-none bg-transparent text-[16px] text-pub-txt outline-none placeholder:text-pub-txt-4"
                />
                {/* a tecla Esc (:138) — a casca da wave 3 é que fecha com ela */}
                <span className="flex-none rounded-[5px] border border-pub-line-strong px-[6px] py-[2px] font-pub-mono text-[11px] text-pub-txt-2">
                    Esc
                </span>
            </div>

            {/* ─── Corpo rolável, 420px (:140) ─── */}
            <div className="max-h-[420px] overflow-y-auto p-[8px]">
                {/* ⚠️ Nunca uma lista vazia em silêncio — e as três frases usam a
                    MESMA geometria do vazio (:156). */}
                {carregando && <div className="p-[36px] text-center text-[13px] text-pub-txt-3">{CARREGANDO}</div>}
                {!carregando && erro && <div className="p-[36px] text-center text-[13px] text-pub-txt-3">{ERRO}</div>}
                {!carregando && !erro && cheios.length === 0 && (
                    <div className="p-[36px] text-center text-[13px] text-pub-txt-3">{VAZIO}</div>
                )}

                {/* ⚠️ NOVA-03: `cheios` já vem sem grupo vazio de `filtrarGrupos()`.
                    Grupo sem item não aparece — nem o título. */}
                {!carregando && !erro && cheios.map((grupo) => (
                    <div key={grupo.chave} className="flex flex-col">
                        {/* título + hint (:143). O rótulo em caixa alta é o TEXTO,
                            não um `text-transform`. */}
                        <span className="flex items-center justify-between px-[10px] pb-[6px] pt-[10px] text-[11px] font-semibold tracking-[0.08em] text-pub-txt-4">
                            {textoSeguro(grupo?.rotulo, '')}
                            <span className="font-medium tracking-[0] text-pub-txt-off">{textoSeguro(grupo?.hint, '')}</span>
                        </span>
                        {(Array.isArray(grupo.itens) ? grupo.itens : []).map((item, indice) => (
                            <Item
                                // ⚠️ Nunca o índice do `.map()` como `key`: o
                                // `produto_id` quando for número finito, senão o
                                // nome. O `indice` só entra como último recurso,
                                // para item nulo/`{}` não colidir com outro.
                                key={Number.isFinite(item?.produto_id) ? item.produto_id : (textoUtil(item?.nome) ?? `${grupo.chave}-${indice}`)}
                                item={item}
                                grupo={grupo}
                                aoEscolher={aoEscolher}
                            />
                        ))}
                    </div>
                ))}
            </div>

            {/* ─── Rodapé (:158) ─── */}
            <div className="flex items-center gap-[8px] border-t border-pub-line-subtle bg-pub-sunken px-[16px] py-[12px] text-[12px] text-pub-txt-3">
                {/* o tamanho `rodape` do glyph foi medido EXATAMENTE em :159 —
                    REUSAR, nunca desenhar retângulo à mão (há gate que reprova) */}
                <GlyphDaFase fase={2} tamanho="rodape" />
                {RODAPE}
            </div>
        </>
    );
}

export default PainelNovaPublicacao;
