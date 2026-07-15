import { useCallback, useMemo } from 'react';
import { DataEditor, GridCellKind } from '@glideapps/glide-data-grid';
// CSS obrigatorio da lib — sem ele o canvas renderiza quebrado. Fica SO aqui
// (nao em app.jsx) pra nao pesar as outras paginas: a grade e lazy-loaded por rota.
import '@glideapps/glide-data-grid/dist/index.css';
import { nomeCurto, parseDimensoes } from '@/Pages/Mlb/gradeMassaUtils';

// ═══════════════════════════════════════════════════════════════════════
// Grade de anuncio em massa em CANVAS (glide-data-grid).
//
// Troca a <table> HTML (1 <input> por celula) pelo modelo da lib: a grade
// PERGUNTA o conteudo celula a celula via getCellContent e devolve edicoes em
// lote via onCellsEdited. A pagina (AnunciarMassa.jsx) continua dona do estado
// das abas, do autosave e da publicacao — aqui so se desenha e se delega.
//
// IMPORTANTE: canvas nao e DOM. Classes Tailwind NAO alcancam o conteudo
// desenhado; toda cor passa pelo objeto `temaEcf` abaixo.
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

// ─── Colunas base, na ordem visual de hoje ───
// Esta ordem tambem e a ordem de mapeamento do paste (Plan 03) — nao reordenar
// sem ajustar la. Mudancas deliberadas vs. a tabela antiga:
//   (a) "A×L×C cm" (1 celula com 3 inputs) virou 3 colunas — canvas nao aninha
//       inputs, e 3 colunas e o que torna fill handle e paste uteis nesses campos;
//   (b) "Foto" saiu — era placeholder decorativo ("+" que nao fazia nada; o
//       payload sempre mandou pictures: []). Nao e capacidade perdida.
const COLS_BASE = [
    { id: 'title',         title: 'Título',       width: 240, req: true },
    { id: 'tier',          title: 'Tipo',         width: 100 },
    { id: 'price',         title: 'Preço',        width: 100, req: true, num: true },
    { id: 'estoque',       title: 'Estoque',      width: 90,  req: true, num: true },
    { id: 'sku',           title: 'SKU',          width: 120 },
    { id: 'gtin',          title: 'GTIN',         width: 140, num: true },
    { id: 'pesoG',         title: 'Peso g',       width: 90,  num: true },
    { id: 'alturaCm',      title: 'Altura cm',    width: 90,  num: true },
    { id: 'larguraCm',     title: 'Largura cm',   width: 90,  num: true },
    { id: 'comprimentoCm', title: 'Comprim. cm',  width: 100, num: true },
];

// ─── Rotulo legivel do tipo de anuncio (a coluna e somente-leitura ate o Plan 03) ───
const LABEL_TIER = { gold_special: 'Clássico', gold_pro: 'Premium' };

// ─── Chave do mapa de origem por campo (SHEET-04) ───
// puxarProduto grava origem.available_quantity / origem.description — que NAO
// sao os nomes dos campos da linha (estoque / descricao). Sem esta traducao,
// editar Estoque nunca apagaria a marca de "veio do cliente".
const CHAVE_ORIGEM = { estoque: 'available_quantity', descricao: 'description' };
const chaveOrigem = (campo) => CHAVE_ORIGEM[campo] ?? campo;

// Separador de dimensao aceito ao digitar/colar "10x20x30" na coluna Altura
const TEM_DIMENSAO = /[x×*]/i;

export default function GradeAnuncioGlide({ aba, empresa, onEditarCelula, onAdicionarLinha, onRemoverLinha }) {
    const nomeCat = nomeCurto(aba?.caminho, aba?.category_id);

    // ─── Colunas = 10 base + SO os obrigatorios da categoria ATIVA (SHEET-02) ───
    // Nunca a uniao das categorias de todas as abas.
    const colunas = useMemo(() => {
        const base = COLS_BASE.map((c) => ({
            id: c.id,
            title: c.id === 'title' ? `Título (${aba?.max_title_length ?? 60})${c.req ? ' *' : ''}` : `${c.title}${c.req ? ' *' : ''}`,
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

        // Tipo de anuncio: somente leitura ate virar DropdownCell no Plan 03.
        // Editavel como texto livre aqui gravaria lixo em listing_type_id.
        if (coluna.id === 'tier') {
            return {
                kind: GridCellKind.Text,
                allowOverlay: false,
                data: valor,
                displayData: LABEL_TIER[valor] ?? valor,
            };
        }

        // Atributo de lista fechada (ex.: Genero): idem — vira DropdownCell no Plan 03.
        const ehLista = ehAttr
            && coluna._attr.value_type === 'list'
            && Array.isArray(coluna._attr.values)
            && coluna._attr.values.length > 0;
        if (ehLista) {
            return {
                kind: GridCellKind.Text,
                allowOverlay: false,
                data: valor,
                displayData: valor,
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

    // ─── UNICO ponto de escrita da grade ───
    // A lib chama onCellsEdited primeiro para TUDO (digitacao, fill handle, paste)
    // e so cai no onCellEdited singular se este retornar falsy. Por isso o
    // singular NAO e implementado: um handler so, sem logica duplicada.
    const onCellsEdited = useCallback((edicoes) => {
        for (const ed of edicoes) {
            const [col, row] = ed.location;
            const coluna = colunas[col];
            const linha = aba.linhas[row];
            if (!coluna || !linha) continue;

            const valor = String(ed.value?.data ?? '');

            // Atributo da ficha tecnica
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
    }, [colunas, aba.linhas, onEditarCelula]);

    return (
        <div className="overflow-hidden rounded-xl border border-white/[0.08]">
            <DataEditor
                theme={temaEcf}
                columns={colunas}
                rows={aba.linhas.length}
                getCellContent={getCellContent}
                onCellsEdited={onCellsEdited}
                // Titulo sempre visivel no scroll horizontal (equivale ao sticky left-0 de hoje).
                // O Plan 05 prepende a coluna de status e passa isto para 2.
                freezeColumns={1}
                // "+ Adicionar linha" nativo — traducao direta da ultima <tr> da tabela antiga
                onRowAppended={onAdicionarLinha}
                trailingRowOptions={{
                    hint: '+ Adicionar linha',
                    sticky: true,
                    tint: true,
                }}
                rowMarkers="number"
                smoothScrollX
                smoothScrollY
                width="100%"
                height={520}
            />
        </div>
    );
}
