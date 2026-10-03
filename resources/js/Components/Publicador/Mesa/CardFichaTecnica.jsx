import { ListChecks, Loader2, Sparkles } from 'lucide-react';
import CampoAtributo, { RotuloAtributo } from '../CampoAtributo';
import { estadoDasSecoes, valorVazio } from '../apoio';
import { CardMesa, ChipSecao, Tile } from './comum';
import { cn } from '@/lib/utils';

// ─── Card 2 — Ficha técnica (check "Características") ───────────────────────
//
// Todos os campos da categoria abertos, como campos normais (docx §6,
// 03/10/2026): nada recolhido nem rotulado "opcional"; o selo "obrigatório" só
// aparece no que o ML exige. Os obrigatórios vêm primeiro. Os atributos da
// seção EMBALAGEM não entram aqui: moram no card de logística.
//
// O Modelo ganha a IA dos termos mais buscados (docx §2): até 120 caracteres.

const MODELO = 'MODEL';
const LIMITE_MODELO = 120;
const ORDEM = { PRINCIPAIS: 0, FICHA: 1, AVANCADO: 2 };
const PESO = { REQUIRED: 0, RECOMMENDED: 1 };

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
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
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

export default function CardFichaTecnica({ m, aberto = true, onAlternar }) {
    const schema = m.schema;
    const atributos = Object.values(schema?.atributos ?? {})
        .filter((a) => ['PRINCIPAIS', 'FICHA', 'AVANCADO'].includes(a.secao))
        .map((a, i) => ({ a, i }))
        .sort((x, y) => ((PESO[x.a.obrigatoriedade] ?? 2) - (PESO[y.a.obrigatoriedade] ?? 2)) || (ORDEM[x.a.secao] - ORDEM[y.a.secao]) || (x.i - y.i))
        .map(({ a }) => a);
    const obrigatorios = atributos.filter((a) => a.secao === 'PRINCIPAIS' || a.obrigatoriedade === 'REQUIRED');
    const preenchidos = obrigatorios.filter((a) => ! valorVazio(m.rasc.atributos?.[a.id])).length;
    const faltam = estadoDasSecoes(m.problemasDaSecao('caracteristicas'), schema).caracteristicas.faltam;

    const chip = (
        <span className="inline-flex items-center gap-3">
            {schema && <span className="text-[11px] font-bold uppercase tracking-[0.05em] text-white/55 tabular-nums" data-obrigatorios>{preenchidos} de {obrigatorios.length} obrigatórios</span>}
            <ChipSecao faltam={faltam} />
        </span>
    );

    return (
        <CardMesa id="card-ficha" icone={ListChecks} titulo="Ficha técnica" chip={chip} aberto={aberto} onAlternar={onAlternar}
            apoio={schema ? `Características da categoria ${schema.categoria_id}. Quanto mais completas, mais o anúncio aparece nas buscas e filtros.` : null}>
            {! schema ? (
                <p className="text-[13px] text-white/55">Escolha a categoria no card acima para ver as características.</p>
            ) : (
                atributos.length > 0 && <GradeTiles atributos={atributos} m={m} />
            )}
        </CardMesa>
    );
}
