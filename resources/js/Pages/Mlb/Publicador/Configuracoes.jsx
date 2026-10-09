import AppLayout from '@/Layouts/AppLayout';
import BarraDaConta, { textoSeguro } from '@/Components/Mlb/Publicador/BarraDaConta';
import AbasDaConta from '@/Components/Mlb/Publicador/AbasDaConta';
import SeloConta from '@/Components/Mlb/Publicador/SeloConta';
import SeloPortal from '@/Components/Mlb/Publicador/SeloPortal';
import { CampoIdentidade } from '@/Components/Publicador/Mesa/IdentidadeDaConta';
import useIdentidadeDaContaPorConta from '@/Components/Mlb/Publicador/useIdentidadeDaContaPorConta';

// ─── Configurações da conta (Fase 173, plano 07) ────────────────────────────
//
// Coluna única de 760px, 3 seções: Identidade visual (editável — único ponto
// de gravação desta tela), Conexões (só leitura) e Programa/responsável (só
// leitura). A identidade usa o MESMO componente de apresentação do editor
// (`CampoIdentidade`, export nomeado de `Components/Publicador/Mesa/
// IdentidadeDaConta.jsx`) e o MESMO registro de banco (`creative_identidades_
// conta`) — só o hook que fala com o servidor é novo, por conta em vez de por
// produto (`useIdentidadeDaContaPorConta`). Editar aqui aparece no editor e
// vice-versa, sem nenhuma migration (D2/IDENT-01, Fase 170; Fase 173-01).
//
// "Salvo por" é IMPOSSÍVEL: `creative_identidades_conta` não tem coluna de
// autor e a spec proíbe criar — só "Salvo em", com o `atualizado_em` que a
// rota devolve.
//
// ERP é apenas DECLARADO no onboarding (decisão 8 do handoff) — nunca mostrar
// como "conectado"; sem valor, "Não informado".

/** Formata uma data ISO para "dd/mm/aaaa às HH:mm"; nulo ou inválida não mostra nada. */
function formatarDataHora(iso) {
    if (typeof iso !== 'string' || iso.trim() === '') return null;
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return null;
    const data = d.toLocaleDateString('pt-BR');
    const hora = d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });

    return `${data} às ${hora}`;
}

/** Uma linha rótulo/valor só leitura — nunca um botão de editar aqui dentro. */
function LinhaSoLeitura({ rotulo, children }) {
    return (
        <div className="flex items-center justify-between gap-3 border-b border-white/[0.06] py-2.5 last:border-0">
            <span className="text-[13px] font-normal text-white/55">{rotulo}</span>
            <span className="text-[13px] font-normal text-white/90">{children}</span>
        </div>
    );
}

/**
 * Parte pura de apresentação — recebe tudo já pronto (sem chamar o hook),
 * para permitir teste de render com o JSON real do controller (mesma defesa
 * de `CampoIdentidade`/`textoSeguro`: campo em formato inesperado nunca
 * derruba a tela — `tests/js/publicador-configuracoes-render.test.js`).
 */
export function ConteudoConfiguracoes({ identidade, conexoes, programa, responsavel }) {
    const dataFormatada = formatarDataHora(identidade?.atualizadoEm);
    const erpValor = typeof conexoes?.erp?.valor === 'string' ? conexoes.erp.valor : null;
    const erpRotulo = textoSeguro(conexoes?.erp?.rotulo, 'Não informado');

    return (
        <div className="mx-auto max-w-[760px] space-y-6">
            <section className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                <CampoIdentidade
                    texto={identidade?.texto ?? null}
                    carregando={identidade?.carregando ?? false}
                    salvando={identidade?.salvando ?? false}
                    erro={identidade?.erro ?? null}
                    onSalvar={identidade?.onSalvar ?? (() => {})}
                />
                {dataFormatada && (
                    <p className="mt-2 text-[11px] font-normal text-white/40">Salvo em {dataFormatada}</p>
                )}
            </section>

            <section className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                <h3 className="text-[13px] font-bold text-white/90">Conexões</h3>
                <p className="mt-1 text-[11px] font-normal text-white/50">Só para consulta — nada aqui se edita nesta tela.</p>
                <div className="mt-3">
                    <LinhaSoLeitura rotulo="Mercado Livre"><SeloConta token={conexoes?.mercado_livre?.token} /></LinhaSoLeitura>
                    <LinhaSoLeitura rotulo="Publicação">{conexoes?.publicacao_liberada ? 'Liberada' : 'Travada'}</LinhaSoLeitura>
                    <LinhaSoLeitura rotulo="Alavancas">{conexoes?.alavancas_liberada ? 'Liberada' : 'Travada'}</LinhaSoLeitura>
                    <LinhaSoLeitura rotulo="Portal"><SeloPortal portal={conexoes?.portal} /></LinhaSoLeitura>
                    <LinhaSoLeitura rotulo="ERP">{erpValor ? `${erpRotulo} · declarado no onboarding` : 'Não informado'}</LinhaSoLeitura>
                </div>
            </section>

            <section className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                <h3 className="text-[13px] font-bold text-white/90">Programa e responsável</h3>
                <div className="mt-3">
                    <LinhaSoLeitura rotulo="Programa">{textoSeguro(programa, '—')}</LinhaSoLeitura>
                    <LinhaSoLeitura rotulo="Responsável">{textoSeguro(responsavel, '—')}</LinhaSoLeitura>
                </div>
            </section>
        </div>
    );
}

export default function Configuracoes({ empresa, conexoes, programa, responsavel }) {
    const idc = useIdentidadeDaContaPorConta({ conta: empresa.chave });

    return (
        <AppLayout title={`Configurações da conta — ${empresa.nome}`}>
            <div className="mx-auto max-w-[1240px] px-8 py-8">
                <BarraDaConta empresa={empresa} liberada />

                <div className="mb-6">
                    <AbasDaConta aba="configuracoes" conta={empresa.chave} companyId={empresa.company_id ?? null} />
                </div>

                <ConteudoConfiguracoes
                    identidade={{
                        texto: idc.texto,
                        atualizadoEm: idc.atualizadoEm,
                        carregando: idc.carregando,
                        salvando: idc.salvando,
                        erro: idc.erro,
                        onSalvar: idc.salvar,
                    }}
                    conexoes={conexoes}
                    programa={programa}
                    responsavel={responsavel}
                />
            </div>
        </AppLayout>
    );
}
