import { Loader2, Sparkles } from 'lucide-react';
import CampoAtributo, { RotuloAtributo } from '../CampoAtributo';
import { valorVazio } from '../apoio';
import { Tile } from './comum';
import { cn } from '@/lib/utils';

// ─── Item "Ficha técnica" (e os subitens Obrigatórios / Outras características) ─
//
// Todos os campos da categoria abertos, como campos normais (docx §6,
// 03/10/2026): nada recolhido nem rotulado "opcional"; o selo "obrigatório" só
// aparece no que o ML exige. Dois grupos, na ordem do schema: os que o ML pede
// (seção PRINCIPAIS ou REQUIRED) e as outras características — a árvore deixa
// escolher um grupo só (`grupo`), ou os dois. Os atributos da seção EMBALAGEM
// não entram aqui: moram em "Envio e garantia".
//
// O Modelo ganha a IA dos termos mais buscados (docx §2): até 120 caracteres.

const MODELO = 'MODEL';
const LIMITE_MODELO = 120;
const ORDEM = { PRINCIPAIS: 0, FICHA: 1, AVANCADO: 2 };
const PESO = { REQUIRED: 0, RECOMMENDED: 1 };

/** As características da ficha (PRINCIPAIS, FICHA e AVANÇADO), como o card as lista. */
export const atributosDaFicha = (schema) => Object.values(schema?.atributos ?? {}).filter((a) => ['PRINCIPAIS', 'FICHA', 'AVANCADO'].includes(a.secao));
export const ehObrigatorio = (a) => a.secao === 'PRINCIPAIS' || a.obrigatoriedade === 'REQUIRED';
export const obrigatoriosDaFicha = (atributos) => atributos.filter(ehObrigatorio);

/**
 * As pendências da ficha que caem num subitem ("obrigatorios" ou "outras"), pelo atributo que
 * apontam. Pendência sem atributo fica só no item "Ficha técnica". A árvore e o centro contam
 * por aqui — assim o subitem nunca fica âmbar com o item pai verde.
 */
export const problemasDoGrupoDaFicha = (problemas, schema, grupo) => (problemas ?? []).filter((p) => {
    if (! p.alvo?.atributo) return false;
    const a = schema?.atributos?.[p.alvo.atributo];

    return a ? (grupo === 'obrigatorios') === ehObrigatorio(a) : grupo === 'outras';
});

/** Na ordem da tela: obrigatórios primeiro, depois por seção, depois pela ordem do schema. */
const ordenadas = (schema) => atributosDaFicha(schema)
    .map((a, i) => ({ a, i }))
    .sort((x, y) => ((PESO[x.a.obrigatoriedade] ?? 2) - (PESO[y.a.obrigatoriedade] ?? 2)) || (ORDEM[x.a.secao] - ORDEM[y.a.secao]) || (x.i - y.i))
    .map(({ a }) => a);

/** "11 de 15" dos obrigatórios e o total de características preenchidas (para a árvore e o cabeçalho). */
export const contagemDaFicha = (m) => {
    const atributos = atributosDaFicha(m.schema);
    const obrigatorios = obrigatoriosDaFicha(atributos);
    const cheio = (a) => ! valorVazio(m.rasc?.atributos?.[a.id]);

    return {
        obrigatorios: obrigatorios.length,
        obrigatoriosPreenchidos: obrigatorios.filter(cheio).length,
        outras: atributos.length - obrigatorios.length,
        outrasPreenchidas: atributos.filter((a) => ! ehObrigatorio(a) && cheio(a)).length,
    };
};

/** A IA do Modelo: botão, andamento, erro e o contador dos 120 caracteres. */
function IaDoModelo({ m, valor }) {
    const ia = m.palavrasIa?.modelo ?? {};
    const rodando = ia.status === 'rodando';
    const tamanho = String(valor?.value_name ?? '').length;

    return (
        <div className="mt-2 space-y-1" data-ia-modelo={ia.status ?? 'nenhum'}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <button type="button" onClick={() => m.pedirPalavrasIa('modelo')} disabled={m.disabled || rodando} data-acao="gerar-modelo-ia"
                    className="inline-flex items-center gap-1.5 rounded-lg border border-white/[0.10] bg-white/[0.04] px-2.5 py-1 text-[11px] font-bold text-white/80 hover:bg-white/[0.07] hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow disabled:opacity-50">
                    {rodando ? <Loader2 size={12} className="animate-spin" /> : <Sparkles size={12} />}
                    {rodando ? 'IA montando o Modelo…' : 'Gerar com IA pelos termos mais buscados'}
                </button>
                <span className={cn('font-mono text-[11px] tabular-nums', tamanho > LIMITE_MODELO ? 'text-amber-300' : 'text-white/40')} data-contador-modelo>{tamanho}/{LIMITE_MODELO}</span>
            </div>
            {ia.status === 'erro' && <p className="text-[11px] text-amber-300">{ia.erro}</p>}
        </div>
    );
}

function GradeTiles({ atributos, m }) {
    return (
        <div className="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
            {atributos.map((a) => {
                const valor = m.rasc.atributos?.[a.id];
                const preenchido = ! valorVazio(valor);
                // Vermelho só para valor que o servidor recusou: precisa haver valor digitado.
                const recusa = preenchido ? (m.problemasDoAtributo(a.id).find((p) => p.severidade === 'BLOCKER')?.mensagem ?? null) : null;
                return (
                    <div key={a.id} className={cn(a.id === MODELO && 'md:col-span-2')}>
                        {/* Só o vazio obrigatório pede atenção (borda âmbar); o resto é campo normal. */}
                        <Tile rotulo={<RotuloAtributo atributo={a} valor={valor} />} preenchido={preenchido} obrigatorio={a.obrigatoriedade === 'REQUIRED'} problema={recusa}>
                            <CampoAtributo variante="tile" atributo={a} valor={valor} disabled={m.disabled || (a.id === MODELO && m.palavrasIa?.modelo?.status === 'rodando')} erro={recusa}
                                onChange={(v) => m.mudarAtributo(a.id, v)} />
                            {a.id === MODELO && <IaDoModelo m={m} valor={valor} />}
                        </Tile>
                    </div>
                );
            })}
        </div>
    );
}

/** `grupo`: 'obrigatorios' | 'outras' | nulo (os dois, com um título por grupo). */
export default function CardFichaTecnica({ m, grupo = null }) {
    const schema = m.schema;
    if (! schema) return <p className="text-[13px] text-white/55">Escolha a categoria em "Produto e categoria" para ver as características.</p>;
    const todas = ordenadas(schema);
    const obrigatorios = todas.filter(ehObrigatorio);
    const outras = todas.filter((a) => ! ehObrigatorio(a));
    const c = contagemDaFicha(m);

    const Grupo = ({ titulo, contagem, atributos, chave }) => (
        <section aria-label={titulo} data-grupo-ficha={chave}>
            {grupo === null && (
                <h3 className="mb-3 flex flex-wrap items-baseline gap-x-3 text-[15px] font-bold text-white">
                    {titulo} <span className="text-[13px] font-normal text-white/45">{contagem}</span>
                </h3>
            )}
            {atributos.length > 0 ? <GradeTiles atributos={atributos} m={m} /> : <p className="text-[13px] text-white/45">Nenhuma nesta categoria.</p>}
        </section>
    );

    return (
        <div className="space-y-8">
            {grupo !== 'outras' && <Grupo chave="obrigatorios" titulo="Pedidos pelo Mercado Livre" contagem={`${c.obrigatoriosPreenchidos} de ${c.obrigatorios} preenchidos`} atributos={obrigatorios} />}
            {grupo !== 'obrigatorios' && <Grupo chave="outras" titulo="Outras características" contagem={`${c.outrasPreenchidas} de ${c.outras} preenchidas · quanto mais, mais o anúncio aparece nas buscas e filtros`} atributos={outras} />}
        </div>
    );
}
