import { useCallback, useMemo, useState } from 'react';
import { DataEditor, GridCellKind, CompactSelection } from '@glideapps/glide-data-grid';
import { DropdownCell } from '@glideapps/glide-data-grid-cells';
// CSS obrigatorio da lib — sem ele o canvas renderiza quebrado. Fica SO aqui
// (nao em app.jsx) pra nao pesar as outras paginas: a grade e lazy-loaded por rota.
import '@glideapps/glide-data-grid/dist/index.css';
import { cn } from '@/lib/utils';
import { Barcode, Trash2, Undo2, Redo2 } from 'lucide-react';
import {
    nomeCurto,
    parseDimensoes,
    casarValueList,
    normalizarTipoAnuncio,
    gerarEan13,
    errosLocaisLinha,
    normalizarPreco,
} from '@/Pages/Mlb/gradeMassaUtils';

// ═══════════════════════════════════════════════════════════════════════
// Grade de anuncio em massa — planilha em CANVAS (glide-data-grid).
//
// COMO ESTE ARQUIVO FUNCIONA (e diferente de um componente React comum):
//   - a grade PERGUNTA o conteudo celula a celula via `getCellContent`;
//   - e devolve TODA escrita (digitacao, fill handle, paste) por um unico
//     `onCellsEdited`, que delega pros callbacks da pagina;
//   - a pagina (AnunciarMassa.jsx) segue dona do estado, do autosave e da
//     publicacao. Aqui so se desenha e se delega.
//
// CANVAS NAO E DOM — a pegadinha central deste arquivo:
//   - classes Tailwind NAO alcancam o conteudo desenhado. Toda cor de celula
//     passa pelo objeto `temaEcf`, por `getRowThemeOverride` (por LINHA) ou
//     pelo `draw()` de um custom renderer (por CELULA);
//   - o que e DOM aqui: a toolbar acima da grade e os editores de overlay
//     (EditorOrigem), que abrem dentro do <div id="portal"> do app.blade.php.
//     Nesses dois, Tailwind funciona normalmente.
//   - sem o #portal no Blade, NENHUM editor de celula abre — e falha em
//     silencio, so logando no console.
//
// Capacidades de planilha: selecao multi-retangulo, fill handle nas duas
// direcoes, copiar/colar do Excel, teclado nativo, selecao de linha/coluna e
// dropdown fechado nos campos de valor pre-definido.
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

// ═══════════════════════════════════════════════════════════════════════
// Custom cell da BOLINHA DE ORIGEM (SHEET-04).
//
// Por que custom renderer e nao getRowThemeOverride: aquele recebe `row` e nao
// `col` — pinta a linha inteira, e origem e POR CELULA. Nao ha outro caminho.
// A bolinha e so decoracao: provideEditor cai no editor de texto padrao, entao
// a celula continua editavel como qualquer outra.
// ═══════════════════════════════════════════════════════════════════════
const CORES_ORIGEM = {
    cliente: '#a78bfa',    // violet-400 — mesmo hex do OrigemBadge antigo
    publicador: '#fbbf24', // amber-400  — idem
};

// ─── Editor do overlay das celulas de origem (DOM — aqui Tailwind FUNCIONA) ───
// Renderizado dentro do <div id="portal"> do Plan 01. Quando a celula declara
// `max` (coluna Titulo), mostra o contador N/max e limita o texto — as MESMAS
// duas barreiras do input de hoje (maxLength + slice), preservadas.
function EditorOrigem({ value, onChange }) {
    const d = value.data;
    const max = d.max ?? null;
    const texto = String(d.texto ?? '');
    const setTexto = (t) => onChange({
        ...value,
        data: { ...d, texto: max ? t.slice(0, max) : t },
    });
    return (
        <div className="flex w-full items-center gap-2 bg-ecf-card px-2 py-1">
            <input
                autoFocus
                value={texto}
                maxLength={max ?? undefined}
                onChange={(e) => setTexto(e.target.value)}
                className={cn(
                    'w-full bg-transparent text-[13px] text-white placeholder-white/25 focus:outline-none',
                    d.alinharDireita && 'text-right font-mono tabular-nums',
                )}
            />
            {max && (
                <span className="shrink-0 text-[9px] tabular-nums text-white/25">
                    {texto.length}/{max}
                </span>
            )}
        </div>
    );
}

const origemCellRenderer = {
    kind: GridCellKind.Custom,
    isMatch: (c) => c.data?.kind === 'origem-cell',
    draw: (args, cell) => {
        const { ctx, rect, theme } = args;
        const { texto, origem, alinharDireita } = cell.data;
        const pad = theme.cellHorizontalPadding ?? 8;

        // (a) o texto, como uma celula de texto normal faria
        ctx.save();
        ctx.fillStyle = theme.textDark;
        ctx.font = theme.baseFontStyle + ' ' + theme.fontFamily;
        ctx.textBaseline = 'middle';
        const y = rect.y + rect.height / 2;
        if (alinharDireita) {
            ctx.textAlign = 'right';
            ctx.fillText(String(texto ?? ''), rect.x + rect.width - pad, y);
        } else {
            ctx.textAlign = 'left';
            ctx.fillText(String(texto ?? ''), rect.x + pad, y);
        }

        // (b) a bolinha no canto superior direito, quando o campo tem origem
        const cor = CORES_ORIGEM[origem];
        if (cor) {
            ctx.beginPath();
            ctx.arc(rect.x + rect.width - 6, rect.y + 6, 3, 0, Math.PI * 2);
            ctx.fillStyle = cor;
            ctx.fill();
        }
        ctx.restore();
        return true;
    },
    // A bolinha e decoracao: a celula continua editavel. O editor abre no portal.
    provideEditor: () => ({
        editor: EditorOrigem,
        disablePadding: true,
    }),
    onPaste: (v, d) => ({ ...d, texto: v }), // valor colado entra no texto da celula
    // Tecla Delete nas 8 colunas de COLS_COM_ORIGEM. Sem isto elas nao respondem ao
    // Delete: custom cell nao herda o onDelete nativo da lib. So o `texto` e apagado —
    // origem/alinharDireita/max nao sao conteudo digitado.
    // Nao escreve no estado: a lib monta o editList e chama o onCellsEdited desta grade,
    // que ja delega pra pagina (e o autosave dispara sozinho).
    onDelete: (cell) => ({ ...cell, copyData: '', data: { ...cell.data, texto: '' } }),
};

// ─── Colunas que carregam marca de origem (cliente × publicador) ───
const COLS_COM_ORIGEM = new Set([
    'title', 'sku', 'price', 'estoque', 'pesoG', 'alturaCm', 'larguraCm', 'comprimentoCm',
]);

// ─── Renderers extras: DropdownCell + o de origem ───
// NAO existe hook useExtraCells neste pacote — o pacote exporta DropdownCell
// direto. Array constante de modulo: nao recriar a cada render.
const RENDERERS = [DropdownCell, origemCellRenderer];

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
    // Fotos por URL (COL-85-1) — a 1ª preenchida vira a capa. Obrigatória p/ Premium.
    { id: 'imagemUrl',     title: 'Foto (URL)',   width: 200, grupo: 'Fotos' },
    { id: 'imagemUrl2',    title: 'Foto 2',       width: 150, grupo: 'Fotos' },
    { id: 'imagemUrl3',    title: 'Foto 3',       width: 150, grupo: 'Fotos' },
    { id: 'imagemUrl4',    title: 'Foto 4',       width: 150, grupo: 'Fotos' },
    { id: 'imagemUrl5',    title: 'Foto 5',       width: 150, grupo: 'Fotos' },
    { id: 'imagemUrl6',    title: 'Foto 6',       width: 150, grupo: 'Fotos' },
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

export default function GradeAnuncioGlide({
    aba, empresa, onEditarCelula, onAdicionarLinha, onRemoverLinha,
    onDesfazer, onRefazer, podeDesfazer, podeRefazer,
}) {
    const nomeCat = nomeCurto(aba?.caminho, aba?.category_id);

    // Selecao CONTROLADA: a toolbar precisa saber quais linhas estao marcadas.
    const [selecao, setSelecao] = useState(SEM_SELECAO);

    // ─── Colunas = 10 base + SO os obrigatorios da categoria ATIVA (SHEET-02) ───
    // Nunca a uniao das categorias de todas as abas.
    const colunas = useMemo(() => {
        // Coluna de status: reproduz o conteudo da antiga coluna "#" sticky MENOS o
        // numero (o numero agora vem do rowMarkers="clickable-number" do Plan 04).
        const status = { id: 'st', title: '', width: 44, group: 'Campos base', _st: true };
        const base = COLS_BASE.map((c) => ({
            id: c.id,
            title: c.id === 'title' ? `Título (${aba?.max_title_length ?? 60}) *` : `${c.title}${c.req ? ' *' : ''}`,
            width: c.width,
            // Fotos ganham grupo próprio no cabeçalho (o agrupamento por cor e o
            // collapse chegam na Phase 86)
            group: c.grupo ?? 'Campos base',
            _num: !!c.num,
        }));
        // Ficha técnica: os obrigatorios da categoria ATIVA (marcados com *)
        const dinamicas = (aba?.obrigatorios ?? []).map((o) => ({
            id: `attr:${o.id}`,
            title: `${o.name} *`,
            width: 150,
            group: `Ficha técnica · ${nomeCat}`,
            _attr: o, // getCellContent usa _attr.value_type / _attr.values
        }));
        // Caracteristicas secundarias: os OPCIONAIS da categoria. Nao bloqueiam a
        // publicacao, mas o ML pede e elas pesam na qualidade/busca do anuncio —
        // paridade com a secao "Caracteristicas secundarias" do wizard.
        // Grupo proprio pra nao se confundirem com os obrigatorios (o collapse por
        // grupo vem na fase de agrupamento visual).
        const secundarias = (aba?.opcionais ?? []).map((o) => ({
            id: `attr:${o.id}`,
            title: o.name,
            width: 150,
            group: 'Características secundárias',
            _attr: o,
        }));
        return [status, ...base, ...dinamicas, ...secundarias];
    }, [aba?.obrigatorios, aba?.opcionais, aba?.max_title_length, nomeCat]);

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

        // ─── Coluna de status: mesma cascata de precedencia do <td> "#" de hoje ───
        // Canvas nao anima: os spinners Loader2 viram glifos estaticos.
        if (coluna._st) {
            const errosLocais = errosLocaisLinha(linha, aba);
            const avisosMl = (linha.valida && !linha.valida.valido) ? (linha.valida.erros ?? []) : [];
            let glifo = '';
            let cor = temaEcf.textLight;
            // Estados TERMINAIS primeiro: sao a verdade final sobre a linha. Um erro
            // local detectado depois nao desfaz uma publicacao que ja aconteceu.
            // '✕' (falha real do POST /items) e '!' (erro local, nem tentou ainda) sao
            // coisas diferentes e nao dividem simbolo. O motivo de cada '✕' aparece no
            // painel abaixo da grade — canvas nao hospeda tooltip.
            if (linha.statusServidor === 'publicado') { glifo = '✓'; cor = '#22c55e'; } // green-500
            else if (linha.statusServidor === 'erro') { glifo = '✕'; cor = '#ef4444'; }
            else if (linha.publicando)           { glifo = '↑'; cor = '#ffe600'; }        // publicando
            else if (linha.salvando || linha.validando) { glifo = '⋯'; cor = 'rgba(255,255,255,0.4)'; }
            else if (errosLocais.length > 0)     { glifo = '!'; cor = '#ef4444'; }        // erro local (bloqueante)
            else if (avisosMl.length > 0)        { glifo = '◐'; cor = '#fbbf24'; }        // aviso do ML (orientativo)
            else if (linha.valida?.valido)       { glifo = '✓'; cor = '#34d399'; }        // valido no ML
            else if (linha.id)                   { glifo = '✓'; cor = 'rgba(255,255,255,0.35)'; } // salvo
            return {
                kind: GridCellKind.Text,
                allowOverlay: false,
                data: glifo,
                displayData: glifo,
                contentAlign: 'center',
                themeOverride: { textDark: cor },
            };
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

        // SHEET-04 — colunas que carregam marca de origem: custom cell que desenha
        // o texto + a bolinha (violeta = veio do cliente, ambar = publicador mexeu).
        // A origem sai de origem[chaveOrigem(campo)] — por isso Estoque le
        // available_quantity, e nao 'estoque'.
        if (COLS_COM_ORIGEM.has(coluna.id)) {
            return {
                kind: GridCellKind.Custom,
                allowOverlay: true,
                copyData: valor, // sem isto o Ctrl+C nestas colunas sai vazio
                data: {
                    kind: 'origem-cell',
                    texto: valor,
                    origem: linha.origem?.[chaveOrigem(coluna.id)],
                    alinharDireita: !!coluna._num,
                    // So o Titulo tem limite: o editor mostra N/max e corta o excesso.
                    max: coluna.id === 'title' ? (aba.max_title_length ?? 60) : null,
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
    }, [colunas, aba.linhas, aba]);

    // ─── Realce da LINHA: erro local (vermelho) tem precedencia sobre aviso do ML (ambar) ───
    // Mesma precedencia do LinhaGrade de hoje: uma linha com os dois problemas
    // aparece vermelha, nao ambar. Reusa errosLocaisLinha — a MESMA funcao que a
    // PublishBar consome via resumoLote; duas implementacoes divergiriam e o
    // usuario veria "3 publicaveis" com 4 linhas verdes.
    const getRowThemeOverride = useCallback((row) => {
        const l = aba.linhas[row];
        if (!l) return undefined;
        // Terminais primeiro (mesma razao da cascata de glifos)
        if (l.statusServidor === 'publicado') {
            return { bgCell: 'rgba(34,197,94,0.06)', borderColor: 'rgba(34,197,94,0.35)' }; // green-500
        }
        if (l.statusServidor === 'erro') {
            // Mais forte que o vermelho de erro local logo abaixo: falhou de verdade no ML
            return { bgCell: 'rgba(239,68,68,0.10)', borderColor: 'rgba(239,68,68,0.5)' };
        }
        if (errosLocaisLinha(l, aba).length > 0) {
            return { bgCell: 'rgba(239,68,68,0.06)', borderColor: 'rgba(239,68,68,0.35)' }; // red-500
        }
        if (l.valida && !l.valida.valido && (l.valida.erros?.length ?? 0) > 0) {
            return { bgCell: 'rgba(251,191,36,0.05)', borderColor: 'rgba(251,191,36,0.3)' }; // amber-400
        }
        return undefined;
    }, [aba]);

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

        // Celulas com marca de origem (a maior parte da grade): o valor colado vai
        // pro texto, respeitando o limite do Titulo.
        if (celula.kind === GridCellKind.Custom && celula.data?.kind === 'origem-cell') {
            const max = celula.data.max;
            return { ...celula, copyData: texto, data: { ...celula.data, texto: max ? texto.slice(0, max) : texto } };
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
                    // Delete no Tipo volta ao padrao (Classico) — mesmo default de
                    // linhaVazia() e o mesmo fallback de montarPayloadLinha. Sem este
                    // ramo, normalizarTipoAnuncio('') devolve null, o if abaixo engole a
                    // edicao e o Delete nesta coluna nao faz nada.
                    if (escolhido.trim() === '') {
                        onEditarCelula(linha.uid, 'tier', 'gold_special', { chaveOrigem: chaveOrigem('tier') });
                        continue;
                    }
                    // Traduz o rotulo de volta pro codigo do ML reusando a funcao pura.
                    // Texto NAO reconhecido continua sendo ignorado (tolerancia de hoje).
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

            // Celula com marca de origem: o texto editado vem em data.texto
            const valor = (cel?.kind === GridCellKind.Custom && cel.data?.kind === 'origem-cell')
                ? String(cel.data.texto ?? '')
                : String(cel?.data ?? '');

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

            // FIX-83-5: preco aceita "129,99" (padrao BR) alem de "129.99".
            // A coercao acontece AQUI, no ponto unico de escrita, e nao no
            // montarPayloadLinha: o autosave chama aquele a cada 600ms de digitacao —
            // com price='129,99', Number() vira NaN, JSON.stringify(NaN) vira null, e o
            // banco receberia price: null antes de o publicador tentar publicar. Pior:
            // errosLocaisLinha faz Number(price) > 0, entao a linha ficaria vermelha com
            // "falta preco" e o campo visivelmente preenchido na tela.
            // Só chega aqui: os ramos de atributo (id 'attr:XXX') e de alturaCm saem antes.
            const valorNormalizado = coluna.id === 'price' ? normalizarPreco(valor) : valor;

            onEditarCelula(linha.uid, coluna.id, valorNormalizado, { chaveOrigem: chaveOrigem(coluna.id) });
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

    // ─── Ctrl+Z / Ctrl+Y no wrapper (FASE 84) ───
    // A lib NAO trata undo/redo (conferido no .d.ts: nao esta em ConfigurableKeybinds
    // nem em ForcedKeybinds), entao o evento borbulha ate aqui e nao ha conflito com
    // o teclado nativo da grade (setas/Tab/Enter seguem intactos — SHEET2-04).
    // Ignora quando o foco esta num input/textarea (o overlay editor tem o proprio
    // undo do browser, que e o que o usuario espera enquanto digita numa celula).
    const onKeyDownAtalhos = useCallback((e) => {
        if (!(e.ctrlKey || e.metaKey)) return;
        const alvo = e.target;
        const digitando = alvo && (alvo.tagName === 'INPUT' || alvo.tagName === 'TEXTAREA' || alvo.isContentEditable);
        if (digitando) return;

        const k = e.key.toLowerCase();
        if (k === 'z' && !e.shiftKey) { e.preventDefault(); onDesfazer?.(); return; }
        if (k === 'y' || (k === 'z' && e.shiftKey)) { e.preventDefault(); onRefazer?.(); }
    }, [onDesfazer, onRefazer]);

    return (
        // eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions
        <div className="rounded-xl border border-white/[0.08] bg-ecf-card" onKeyDown={onKeyDownAtalhos}>
            {/* ─── Toolbar de acoes sobre a selecao (DOM/Tailwind — fora do canvas) ─── */}
            {/* Publicar/validar NAO entram aqui: a PublishBar segue dona da publicacao. */}
            <div className="flex items-center gap-2 border-b border-white/[0.08] px-3 py-2">
                {/* Desfazer/Refazer: o atalho e invisivel — o botao desabilitado
                    comunica "nao ha o que desfazer". */}
                <button
                    type="button"
                    onClick={onDesfazer}
                    disabled={!podeDesfazer}
                    title="Desfazer (Ctrl+Z)"
                    className={cn('rounded-md border p-1 transition',
                        podeDesfazer
                            ? 'border-white/[0.1] bg-white/[0.03] text-white/60 hover:border-white/25 hover:text-white'
                            : 'cursor-not-allowed border-white/[0.06] text-white/20')}
                >
                    <Undo2 className="h-3.5 w-3.5" />
                </button>
                <button
                    type="button"
                    onClick={onRefazer}
                    disabled={!podeRefazer}
                    title="Refazer (Ctrl+Y)"
                    className={cn('rounded-md border p-1 transition',
                        podeRefazer
                            ? 'border-white/[0.1] bg-white/[0.03] text-white/60 hover:border-white/25 hover:text-white'
                            : 'cursor-not-allowed border-white/[0.06] text-white/20')}
                >
                    <Redo2 className="h-3.5 w-3.5" />
                </button>
                <div className="mx-1 h-4 w-px bg-white/[0.08]" />

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
                // Realce da linha: erro local (vermelho) > aviso do ML (ambar)
                getRowThemeOverride={getRowThemeOverride}
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
                // Status + Titulo sempre visiveis no scroll horizontal (equivale as
                // colunas sticky left-0 da tabela antiga).
                freezeColumns={2}
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
