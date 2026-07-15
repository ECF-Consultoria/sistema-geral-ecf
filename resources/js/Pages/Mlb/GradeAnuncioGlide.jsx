import { useCallback, useMemo, useState } from 'react';
import { DataEditor, GridCellKind, CompactSelection } from '@glideapps/glide-data-grid';
import { DropdownCell } from '@glideapps/glide-data-grid-cells';
// CSS obrigatorio da lib — sem ele o canvas renderiza quebrado. Fica SO aqui
// (nao em app.jsx) pra nao pesar as outras paginas: a grade e lazy-loaded por rota.
import '@glideapps/glide-data-grid/dist/index.css';
import { cn } from '@/lib/utils';
import { Barcode, Trash2 } from 'lucide-react';
import {
    nomeCurto,
    parseDimensoes,
    casarValueList,
    normalizarTipoAnuncio,
    gerarEan13,
} from '@/Pages/Mlb/gradeMassaUtils';

// ═══════════════════════════════════════════════════════════════════════
// Grade de anuncio em massa em CANVAS (glide-data-grid).
//
// Troca a <table> HTML (1 <input> por celula) pelo modelo da lib: a grade
// PERGUNTA o conteudo celula a celula via getCellContent e devolve edicoes em
// lote via onCellsEdited. A pagina (AnunciarMassa.jsx) continua dona do estado
// das abas, do autosave e da publicacao — aqui so se desenha e se delega.
//
// IMPORTANTE: canvas nao e DOM. Classes Tailwind NAO alcancam o conteudo
// desenhado; toda cor passa pelo objeto `temaEcf` abaixo. (A toolbar acima da
// grade e JSX normal, entao usa Tailwind como o resto da pagina.)
// ═══════════════════════════════════════════════════════════════════════

// ─── Tema: traduz os tokens ecf-* do tailwind.config.js para o canvas (SHEET2-08) ───
// Constante de modulo: nao depende de nada, entao nao precisa de memo.
const temaEcf = {
    accentColor: '#ffe600',              // ecf.yellow — selecao/foco
    accentFg: '#000000',                 // texto sobre o amarelo (padrao do botao Publicar)
    accentLight: 'rgba(255,230,0,0.08)', // mesmo tom do focus:bg-ecf-yellow/[0.08] da grade atual
    textDark: '#ffffff',
    textMedium: 'rgba(255,255,255,0.6)',
    textLight: 'rgba(255,255,255,0.4)',
    textHeader: 'rgba(255,255,255,0.4)',
    bgCell: '#0f1116',                   // ecf.card
    bgCellMedium: '#14161d',             // ecf.card-2
    bgHeader: '#14161d',                 // ecf.card-2
    bgHeaderHasFocus: '#191c24',
    bgHeaderHovered: 'rgba(255,255,255,0.04)',
    borderColor: 'rgba(255,255,255,0.08)',        // ecf.line
    horizontalBorderColor: 'rgba(255,255,255,0.06)',
    fontFamily: 'Inter, ui-sans-serif, system-ui, sans-serif', // fontFamily.sans do tailwind
    headerFontStyle: '600 11px',
    baseFontStyle: '13px',
    editorFontSize: '13px',
};

// ─── Renderers extras: so o DropdownCell (allCells arrastaria renderers que nao usamos) ───
// NAO existe hook useExtraCells neste pacote — o pacote exporta DropdownCell direto.
const RENDERERS = [DropdownCell];

// ─── Colunas base, na ordem visual de hoje ───
// Esta ordem tambem e a ordem de mapeamento do paste — nao reordenar sem
// conferir. Mudancas deliberadas vs. a tabela antiga:
//   (a) "A×L×C cm" (1 celula com 3 inputs) virou 3 colunas — canvas nao aninha
//       inputs, e 3 colunas e o que torna fill handle e paste uteis nesses campos;
//   (b) "Foto" saiu — era placeholder decorativo ("+" que nao fazia nada; o
//       payload sempre mandou pictures: []). Nao e capacidade perdida.
const COLS_BASE = [
    { id: 'title',         title: 'Título',       width: 240, req: true },
    { id: 'tier',          title: 'Tipo',         width: 110 },
    { id: 'price',         title: 'Preço',        width: 100, req: true, num: true },
    { id: 'estoque',       title: 'Estoque',      width: 90,  req: true, num: true },
    { id: 'sku',           title: 'SKU',          width: 120 },
    { id: 'gtin',          title: 'GTIN',         width: 140, num: true },
    { id: 'pesoG',         title: 'Peso g',       width: 90,  num: true },
    { id: 'alturaCm',      title: 'Altura cm',    width: 90,  num: true },
    { id: 'larguraCm',     title: 'Largura cm',   width: 90,  num: true },
    { id: 'comprimentoCm', title: 'Comprim. cm',  width: 100, num: true },
];

// ─── Tipo de anuncio: rotulo legivel <-> codigo do ML ───
// O dropdown mostra o rotulo; normalizarTipoAnuncio (gradeMassaUtils) traduz de
// volta pro codigo. Um mapa so, sem segundo mapa espalhado.
const LABEL_TIER = { gold_special: 'Clássico', gold_pro: 'Premium' };
const TIERS = ['Clássico', 'Premium'];

// ─── Chave do mapa de origem por campo (SHEET-04) ───
// puxarProduto grava origem.available_quantity / origem.description — que NAO
// sao os nomes dos campos da linha (estoque / descricao). Sem esta traducao,
// editar Estoque nunca apagaria a marca de "veio do cliente".
const CHAVE_ORIGEM = { estoque: 'available_quantity', descricao: 'description' };
const chaveOrigem = (campo) => CHAVE_ORIGEM[campo] ?? campo;

// Separador de dimensao aceito ao digitar/colar "10x20x30" na coluna Altura
const TEM_DIMENSAO = /[x×*]/i;

// Selecao vazia (reset apos remover linhas — os indices mudam)
const SEM_SELECAO = {
    columns: CompactSelection.empty(),
    rows: CompactSelection.empty(),
    current: undefined,
};

export default function GradeAnuncioGlide({ aba, empresa, onEditarCelula, onAdicionarLinha, onRemoverLinha }) {
    const nomeCat = nomeCurto(aba?.caminho, aba?.category_id);

    // Selecao CONTROLADA: a toolbar precisa saber quais linhas estao marcadas.
    const [selecao, setSelecao] = useState(SEM_SELECAO);

    // ─── Colunas = 10 base + SO os obrigatorios da categoria ATIVA (SHEET-02) ───
    // Nunca a uniao das categorias de todas as abas.
    const colunas = useMemo(() => {
        const base = COLS_BASE.map((c) => ({
            id: c.id,
            title: c.id === 'title' ? `Título (${aba?.max_title_length ?? 60}) *` : `${c.title}${c.req ? ' *' : ''}`,
            width: c.width,
            group: 'Campos base',
            _num: !!c.num,
        }));
        const dinamicas = (aba?.obrigatorios ?? []).map((o) => ({
            id: `attr:${o.id}`,
            title: `${o.name} *`,
            width: 150,
            group: `Ficha técnica · ${nomeCat}`,
            _attr: o, // getCellContent usa _attr.value_type / _attr.values
        }));
        return [...base, ...dinamicas];
    }, [aba?.obrigatorios, aba?.max_title_length, nomeCat]);

    // GTINs ja usados na aba (nao gerar repetido) — mesma regra da grade antiga
    const gtinsUsados = useMemo(
        () => new Set((aba?.linhas ?? []).map((l) => l.gtin).filter(Boolean)),
        [aba?.linhas],
    );

    // ─── Conteudo de cada celula ───
    // Dependencias MINIMAS de proposito: a lib chama isto centenas de vezes por
    // segundo no scroll; referencia instavel (ex.: passar `aba` inteiro) forca
    // redraw completo do canvas a cada keystroke em qualquer input da pagina.
    const getCellContent = useCallback(([col, row]) => {
        const coluna = colunas[col];
        const linha = aba.linhas[row];
        // Fora do range (a lib pode perguntar por celulas que ainda nao existem)
        if (!coluna || !linha) {
            return { kind: GridCellKind.Loading, allowOverlay: false };
        }

        const ehAttr = !!coluna._attr;
        const bruto = ehAttr ? linha.attrs?.[coluna._attr.id] : linha[coluna.id];
        const valor = String(bruto ?? '');

        // SHEET2-06 — Tipo de anuncio: parece texto na grade, dropdown fechado ao editar.
        // Substitui os 2 botoes Clas/Prem de hoje (anti-padrao numa planilha).
        if (coluna.id === 'tier') {
            const rotulo = LABEL_TIER[valor] ?? valor;
            return {
                kind: GridCellKind.Custom,
                allowOverlay: true,
                copyData: rotulo, // sem isto o Ctrl+C nesta celula sai vazio
                data: { kind: 'dropdown-cell', value: rotulo, allowedValues: TIERS },
            };
        }

        // SHEET2-06 — Atributo de lista fechada (ex.: Genero): so as opcoes validas.
        const ehLista = ehAttr
            && coluna._attr.value_type === 'list'
            && Array.isArray(coluna._attr.values)
            && coluna._attr.values.length > 0;
        if (ehLista) {
            return {
                kind: GridCellKind.Custom,
                allowOverlay: true,
                copyData: valor,
                data: {
                    kind: 'dropdown-cell',
                    value: valor,
                    allowedValues: coluna._attr.values.map((v) => v.name),
                },
            };
        }

        // Demais: texto editavel. Valores ficam STRING (como linhaVazia ja cria) —
        // montarPayloadLinha e quem faz Number(l.price) na saida. Usar
        // GridCellKind.Number aqui mudaria o payload = regressao (SHEET2-07).
        return {
            kind: GridCellKind.Text,
            allowOverlay: true,
            readonly: false,
            data: valor,
            displayData: valor,
            contentAlign: coluna._num ? 'right' : undefined,
        };
    }, [colunas, aba.linhas]);

    // ─── Coercao do valor COLADO, celula a celula, ANTES de virar edicao (SHEET2-03) ───
    // Porta o `aplicarCelula` que vivia dentro do colarNaGrade, reusando as
    // funcoes puras — nenhuma regra de coercao foi reescrita.
    const coercePasteValue = useCallback((valor, celula) => {
        const texto = String(valor ?? '').trim();

        // Celulas de dominio fechado (Tipo e atributos list)
        if (celula.kind === GridCellKind.Custom && celula.data?.kind === 'dropdown-cell') {
            const permitidos = celula.data.allowedValues ?? [];
            // Tipo: so grava se reconhecer; texto nao reconhecido NAO sobrescreve
            // (mesmo comportamento tolerante de hoje, que so emitia aviso).
            if (permitidos === TIERS || (permitidos[0] === 'Clássico' && permitidos.length === 2)) {
                const cod = normalizarTipoAnuncio(texto);
                if (!cod) return undefined; // ignora a celula
                return { ...celula, copyData: LABEL_TIER[cod], data: { ...celula.data, value: LABEL_TIER[cod] } };
            }
            // Atributo list: casa sem acento/caixa; se nada casar, mantem o texto cru
            // (o backend aceita value_name livre).
            const casado = casarValueList(texto, permitidos.map((n) => ({ name: n })));
            return { ...celula, copyData: casado, data: { ...celula.data, value: casado } };
        }

        // Demais: texto trimado. A coluna Altura com "10x20x30" passa cru — quem
        // expande nas 3 dimensoes e o onCellsEdited (nao duplicar parseDimensoes aqui).
        if (celula.kind === GridCellKind.Text) {
            return { ...celula, data: texto, displayData: texto };
        }
        return undefined;
    }, []);

    // ─── UNICO ponto de escrita da grade ───
    // A lib chama onCellsEdited primeiro para TUDO (digitacao, fill handle, paste)
    // e so cai no onCellEdited singular se este retornar falsy. Por isso o
    // singular NAO e implementado: um handler so, sem logica duplicada.
    const onCellsEdited = useCallback((edicoes) => {
        // Colar um bloco mais alto que a grade cria as linhas que faltam (como hoje).
        const maiorRow = edicoes.reduce((m, ed) => Math.max(m, ed.location[1]), -1);
        const faltam = maiorRow + 1 - aba.linhas.length;
        for (let i = 0; i < faltam; i++) onAdicionarLinha();

        for (const ed of edicoes) {
            const [col, row] = ed.location;
            const coluna = colunas[col];
            const linha = aba.linhas[row];
            // Linha recem-criada acima ainda nao esta no estado deste render; a
            // proxima passada do paste a alcanca (o estado ja foi agendado).
            if (!coluna || !linha) continue;

            const cel = ed.value;

            // Celulas de dominio fechado: o valor vem em data.value (rotulo/nome)
            if (cel?.kind === GridCellKind.Custom && cel.data?.kind === 'dropdown-cell') {
                const escolhido = String(cel.data.value ?? '');
                if (coluna.id === 'tier') {
                    // Traduz o rotulo de volta pro codigo do ML reusando a funcao pura
                    const cod = normalizarTipoAnuncio(escolhido);
                    if (cod) onEditarCelula(linha.uid, 'tier', cod, { chaveOrigem: chaveOrigem('tier') });
                    continue;
                }
                if (coluna._attr) {
                    // Grava o `name` da opcao (string), como hoje — montarPayloadLinha
                    // monta { id, value_name } a partir disso. value_id mudaria o payload.
                    onEditarCelula(linha.uid, coluna._attr.id, escolhido, { attr: true });
                    continue;
                }
            }

            const valor = String(cel?.data ?? '');

            // Atributo da ficha tecnica (texto livre)
            if (coluna._attr) {
                onEditarCelula(linha.uid, coluna._attr.id, valor, { attr: true });
                continue;
            }

            // A×L×C: digitar/colar "10x20x30" em Altura distribui nas 3 dimensoes
            // (paridade com a coluna unica dim3 da grade antiga, que so fazia isso
            // no paste — aqui vale tambem na digitacao).
            if (coluna.id === 'alturaCm' && TEM_DIMENSAO.test(valor)) {
                const dims = parseDimensoes(valor);
                if (dims) {
                    onEditarCelula(linha.uid, 'alturaCm', dims.alturaCm, { chaveOrigem: chaveOrigem('alturaCm') });
                    onEditarCelula(linha.uid, 'larguraCm', dims.larguraCm, { chaveOrigem: chaveOrigem('larguraCm') });
                    onEditarCelula(linha.uid, 'comprimentoCm', dims.comprimentoCm, { chaveOrigem: chaveOrigem('comprimentoCm') });
                    continue;
                }
                // Nao parseou: grava o texto cru (mesmo fallback tolerante de hoje)
            }

            onEditarCelula(linha.uid, coluna.id, valor, { chaveOrigem: chaveOrigem(coluna.id) });
        }
        return true;
    }, [colunas, aba.linhas, onEditarCelula, onAdicionarLinha]);

    // ─── Linhas marcadas na toolbar (sempre por uid — a pagina identifica por uid) ───
    const uidsSelecionados = useMemo(() => {
        const uids = [];
        for (const i of selecao.rows) {
            const l = aba.linhas[i];
            if (l) uids.push(l.uid);
        }
        return uids;
    }, [selecao, aba.linhas]);

    // ─── Gerar EAN-13 nas linhas marcadas que ainda nao tem GTIN ───
    const gerarEansEmLote = useCallback(() => {
        // Copia local do Set: os EANs gerados DENTRO do laco precisam entrar nele
        // a cada iteracao, senao 2 linhas do mesmo lote podem sair com o mesmo codigo.
        const usados = new Set(gtinsUsados);
        for (const uid of uidsSelecionados) {
            const l = aba.linhas.find((x) => x.uid === uid);
            if (!l || String(l.gtin ?? '').trim()) continue; // nao sobrescreve GTIN preenchido
            const ean = gerarEan13(usados);
            usados.add(ean);
            onEditarCelula(uid, 'gtin', ean, { chaveOrigem: chaveOrigem('gtin') });
        }
    }, [uidsSelecionados, aba.linhas, gtinsUsados, onEditarCelula]);

    // ─── Remover as linhas marcadas (destrutivo e em lote → confirma) ───
    const removerEmLote = useCallback(() => {
        const n = uidsSelecionados.length;
        if (!n) return;
        const msg = n === 1
            ? 'Remover a linha selecionada?'
            : `Remover as ${n} linhas selecionadas?`;
        if (!window.confirm(msg)) return;
        uidsSelecionados.forEach((uid) => onRemoverLinha(uid));
        setSelecao(SEM_SELECAO); // os indices mudaram
    }, [uidsSelecionados, onRemoverLinha]);

    const nSel = uidsSelecionados.length;

    return (
        <div className="rounded-xl border border-white/[0.08] bg-ecf-card">
            {/* ─── Toolbar de acoes sobre a selecao (DOM/Tailwind — fora do canvas) ─── */}
            {/* Publicar/validar NAO entram aqui: a PublishBar segue dona da publicacao. */}
            <div className="flex items-center gap-2 border-b border-white/[0.08] px-3 py-2">
                <span className="text-[11px] text-white/40">
                    {nSel === 0
                        ? 'Selecione linhas pelo número à esquerda'
                        : `${nSel} linha${nSel !== 1 ? 's' : ''} selecionada${nSel !== 1 ? 's' : ''}`}
                </span>
                <div className="ml-auto flex items-center gap-2">
                    <button
                        type="button"
                        onClick={gerarEansEmLote}
                        disabled={nSel === 0}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-[11px] transition',
                            nSel === 0
                                ? 'cursor-not-allowed border-white/[0.06] text-white/20'
                                : 'border-white/[0.1] bg-white/[0.03] text-white/60 hover:border-white/25 hover:text-white',
                        )}
                    >
                        <Barcode className="h-3 w-3" /> Gerar EAN-13
                    </button>
                    <button
                        type="button"
                        onClick={removerEmLote}
                        disabled={nSel === 0}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-[11px] transition',
                            nSel === 0
                                ? 'cursor-not-allowed border-white/[0.06] text-white/20'
                                : 'border-red-500/30 bg-red-500/10 text-red-400 hover:border-red-500/50 hover:text-red-300',
                        )}
                    >
                        <Trash2 className="h-3 w-3" /> Remover
                    </button>
                </div>
            </div>

            <DataEditor
                theme={temaEcf}
                columns={colunas}
                rows={aba.linhas.length}
                getCellContent={getCellContent}
                onCellsEdited={onCellsEdited}
                customRenderers={RENDERERS}
                // SHEET2-03 — copiar/colar: getCellsForSelection habilita o Ctrl+C
                // (copy e desabilitado por padrao na lib); onPaste intercepta o
                // Ctrl+V e faz o split de TSV/CSV do Excel/Sheets.
                getCellsForSelection={true}
                onPaste={true}
                coercePasteValue={coercePasteValue}
                // SHEET2-01 — varios retangulos de selecao com Ctrl/Cmd
                rangeSelect="multi-rect"
                // SHEET2-02 — alca de preenchimento nas DUAS direcoes.
                // Sem onFillPattern: o padrao ja replica valores como o Excel.
                fillHandle={true}
                allowedFillDirections="any"
                // SHEET2-05 — clicar no numero seleciona a linha. "number" (sem
                // clickable-) e SO decorativo e nao seleciona nada.
                rowMarkers="clickable-number"
                rowSelect="multi"
                columnSelect="multi"
                gridSelection={selecao}
                onGridSelectionChange={setSelecao}
                // SHEET2-04 — Tab/Enter/setas sao NATIVOS. Nenhum onKeyDown custom:
                // seria reimplementar o que a lib ja da e brigaria com o nativo.
                //
                // Titulo sempre visivel no scroll horizontal (equivale ao sticky left-0).
                freezeColumns={1}
                // "+ Adicionar linha" nativo — traducao direta da ultima <tr> da tabela antiga
                onRowAppended={onAdicionarLinha}
                trailingRowOptions={{
                    hint: '+ Adicionar linha',
                    sticky: true,
                    tint: true,
                }}
                smoothScrollX
                smoothScrollY
                width="100%"
                height={520}
            />
        </div>
    );
}
