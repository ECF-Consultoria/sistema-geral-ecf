import { useState } from 'react';
import { ChevronDown, ListChecks, SlidersHorizontal } from 'lucide-react';
import CampoAtributo, { RotuloAtributo } from '../CampoAtributo';
import { estadoDasSecoes, valorVazio } from '../apoio';
import { CardMesa, ChipSecao, Tile } from './comum';
import { cn } from '@/lib/utils';

// ─── Card 2 — Ficha técnica e atributos obrigatórios (check "Características") ──
//
// Obrigatórios sempre visíveis; opcionais recolhidos. Os atributos da seção
// EMBALAGEM não entram aqui: moram no card de logística.

function GradeTiles({ atributos, m }) {
    return (
        <div className="grid gap-4 md:grid-cols-3">
            {atributos.map((a) => {
                const valor = m.rasc.atributos?.[a.id];
                const preenchido = ! valorVazio(valor);
                // Vermelho só para valor que o servidor recusou: precisa haver valor digitado.
                const recusa = preenchido ? (m.problemasDoAtributo(a.id).find((p) => p.severidade === 'BLOCKER')?.mensagem ?? null) : null;

                return (
                    <Tile key={a.id} rotulo={<RotuloAtributo atributo={a} valor={valor} />} preenchido={preenchido} problema={recusa}>
                        <CampoAtributo variante="tile" atributo={a} valor={valor} disabled={m.disabled} erro={recusa}
                            onChange={(v) => m.mudarAtributo(a.id, v)} />
                    </Tile>
                );
            })}
        </div>
    );
}

export default function CardFichaTecnica({ m, aberto = true, onAlternar }) {
    const [maisCampos, setMaisCampos] = useState(false);
    const schema = m.schema;
    const atributos = Object.values(schema?.atributos ?? {});
    const obrigatorios = atributos.filter((a) => a.secao === 'PRINCIPAIS' || (a.secao === 'FICHA' && a.obrigatoriedade === 'REQUIRED'));
    const opcionais = atributos.filter((a) => (a.secao === 'FICHA' && a.obrigatoriedade !== 'REQUIRED') || a.secao === 'AVANCADO');
    const preenchidos = obrigatorios.filter((a) => ! valorVazio(m.rasc.atributos?.[a.id])).length;
    const faltam = estadoDasSecoes(m.problemasDaSecao('caracteristicas'), schema).caracteristicas.faltam;

    const chip = (
        <span className="inline-flex items-center gap-3">
            {schema && <span className="text-[11px] font-bold uppercase tracking-[0.05em] text-white/55 tabular-nums" data-obrigatorios>{preenchidos} de {obrigatorios.length} obrigatórios</span>}
            <ChipSecao faltam={faltam} />
        </span>
    );

    return (
        <CardMesa id="card-ficha" icone={ListChecks} titulo="Ficha técnica e atributos obrigatórios" chip={chip} aberto={aberto} onAlternar={onAlternar}
            apoio={schema ? `Campos exigidos pela categoria ${schema.categoria_id}.` : null}>
            {! schema ? (
                <p className="text-[13px] text-white/55">Escolha a categoria no card acima para ver as características.</p>
            ) : (
                <div className="space-y-4">
                    {obrigatorios.length > 0 && <GradeTiles atributos={obrigatorios} m={m} />}
                    {opcionais.length > 0 && (
                        <div>
                            <button type="button" onClick={() => setMaisCampos((v) => ! v)} aria-expanded={maisCampos} aria-controls="card-ficha-opcionais" data-acao="ver-opcionais"
                                className="inline-flex items-center gap-2 text-[13px] text-white/55 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                                <SlidersHorizontal size={14} /> Ver {opcionais.length} {opcionais.length === 1 ? 'atributo opcional' : 'atributos opcionais'}
                                <ChevronDown size={14} className={cn('transition-transform', maisCampos && 'rotate-180')} />
                            </button>
                            {maisCampos && <div id="card-ficha-opcionais" className="mt-4"><GradeTiles atributos={opcionais} m={m} /></div>}
                        </div>
                    )}
                </div>
            )}
        </CardMesa>
    );
}
