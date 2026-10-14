import { useRef, useState } from 'react';
import { cn } from '@/lib/utils';
import { textoSeguro } from '@/Components/Mlb/Publicador/BarraDaConta';
import { formatarDataHora } from '@/lib/dataHora';
import { Button } from '@/Components/ui/button';
import useBlingDaLoja from './useBlingDaLoja';

// ─── Bling da loja — Configurações do Publicador (Fase 184, plano 12) ──────
//
// Seção PRÓPRIA nas Configurações da loja, FORA da linha só-leitura de
// `Configuracoes.jsx` — contrato do arquivo: "nunca um botão de editar aqui
// dentro". Os sete estados vêm prontos do bloco `bling` de
// `PainelVisaoGeralService::integracoes()` (plano 11, controller
// `MlbPublicadorBlingController`); esta seção NUNCA recalcula estado, só lê.
//
// `SecaoBling` é a parte PURA de apresentação (mesma separação de
// `ConteudoConfiguracoes`/`CampoIdentidade`): recebe tudo pronto, nunca
// chama o hook — é o que o teste de render exercita. `BlingDaLoja` (default)
// é quem chama `useBlingDaLoja` e alimenta `SecaoBling`.
//
// Defesas obrigatórias (T-184-83): `bling` pode vir `null`/`undefined` numa
// recarga parcial do Inertia (a mesma lição do `cabecalho ?? null` que
// `Configuracoes.jsx` já registra) — toda leitura usa `bling?.x`, e um
// `estado` desconhecido cai no ramo neutro, nunca uma tela em branco.

const ESTILO_SELO = {
    sem_company: { rotulo: 'Sem empresa', classe: 'border-white/[0.08] bg-white/[0.04] text-white/55' },
    nao_configurado: { rotulo: 'Não configurado', classe: 'border-white/[0.08] bg-white/[0.04] text-white/55' },
    a_conectar: { rotulo: 'A conectar', classe: 'border-sky-500/25 bg-sky-500/[0.06] text-sky-200' },
    aguardando_cliente: { rotulo: 'Aguardando o cliente', classe: 'border-amber-300/25 bg-amber-300/10 text-amber-300' },
    conectado: { rotulo: 'Conectado', classe: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-400' },
    divergente: { rotulo: 'Divergente', classe: 'border-red-500/30 bg-red-500/10 text-red-400' },
    erro: { rotulo: 'Erro', classe: 'border-red-500/30 bg-red-500/10 text-red-400' },
};

function Selo({ estado }) {
    const estilo = ESTILO_SELO[estado] ?? {
        rotulo: textoSeguro(estado, 'Desconhecido'),
        classe: 'border-white/[0.08] bg-white/[0.04] text-white/55',
    };

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 whitespace-nowrap rounded-full border px-2 py-1 text-[11px] font-bold',
                estilo.classe,
            )}
        >
            {estilo.rotulo}
        </span>
    );
}

/**
 * "Copiar link" com reserva: `navigator.clipboard` não existe em contexto
 * não seguro, e a tela não pode simplesmente não funcionar — o campo
 * selecionável é a reserva pra o usuário copiar manualmente.
 */
function BotaoCopiarLink({ url }) {
    const [copiado, setCopiado] = useState(false);
    const campoRef = useRef(null);

    const copiar = async () => {
        try {
            if (navigator?.clipboard?.writeText) {
                await navigator.clipboard.writeText(url ?? '');
                setCopiado(true);
                setTimeout(() => setCopiado(false), 2000);
                return;
            }
        } catch {
            // Segue para a reserva abaixo — contexto não seguro ou permissão negada.
        }
        // Reserva: seleciona o campo de texto pro usuário copiar manualmente.
        campoRef.current?.select();
    };

    return (
        <div className="inline-flex items-center gap-2">
            <Button type="button" size="sm" variant="outline" onClick={copiar}>
                {copiado ? 'Copiado!' : 'Copiar link'}
            </Button>
            <input
                ref={campoRef}
                readOnly
                value={url ?? ''}
                aria-hidden="true"
                tabIndex={-1}
                className="sr-only"
            />
        </div>
    );
}

/** A linha discreta do teto de contas (D-02) — nenhuma explicação de homologação, só o aviso. */
function TetoBling({ bling }) {
    const teto = bling?.teto;
    if (!teto || typeof teto.limite !== 'number') return null;
    const conectadas = typeof teto.conectadas === 'number' ? teto.conectadas : 0;
    const semVaga = conectadas >= teto.limite;

    return (
        <p className="mt-3 text-[11px] font-normal text-white/40">
            {conectadas} de {teto.limite} lojas conectadas
            {semVaga && (
                <span className="ml-1 font-bold text-amber-300">
                    — sem vaga para mais uma loja agora; avise a equipe antes de gerar um novo link.
                </span>
            )}
        </p>
    );
}

/** O bloco com os totais, avisos e `nao_entraram`, antes de qualquer gravação (T-184-87). */
function PreviaImportacao({ previa, agindo, acoes }) {
    if (!previa) return null;

    const novos = typeof previa?.novos === 'number' ? previa.novos : 0;
    const atualizados = typeof previa?.atualizados === 'number' ? previa.atualizados : 0;
    const semMudanca = typeof previa?.sem_mudanca === 'number' ? previa.sem_mudanca : 0;
    const comErro = typeof previa?.erros === 'number' ? previa.erros : 0;
    const avisos = Array.isArray(previa?.avisos) ? previa.avisos : [];
    const naoEntraram = Array.isArray(previa?.nao_entraram) ? previa.nao_entraram : [];
    const TETO_ITENS_VISIVEIS = 10;
    const visiveis = naoEntraram.slice(0, TETO_ITENS_VISIVEIS);
    const resto = naoEntraram.length - visiveis.length;

    return (
        <div className="mt-3 rounded-lg border border-white/[0.08] bg-white/[0.02] p-3">
            <h4 className="text-[12px] font-bold text-white/80">Prévia da importação</h4>
            <p className="mt-1 text-[11px] font-normal text-white/50">
                Nada é gravado até o clique em &quot;Importar&quot;.
            </p>
            <div className="mt-2 grid grid-cols-4 gap-2 text-[12px] font-normal text-white/70">
                <div><span className="font-bold text-emerald-400">{novos}</span> novos</div>
                <div><span className="font-bold text-sky-300">{atualizados}</span> atualizados</div>
                <div><span className="font-bold text-white/60">{semMudanca}</span> sem mudança</div>
                <div><span className="font-bold text-red-400">{comErro}</span> com erro</div>
            </div>
            {avisos.length > 0 && (
                <ul className="mt-2 list-disc pl-4 text-[11px] font-normal text-amber-300">
                    {avisos.map((aviso, i) => (
                        <li key={i}>{textoSeguro(aviso, '')}</li>
                    ))}
                </ul>
            )}
            {naoEntraram.length > 0 && (
                <div className="mt-2 text-[11px] font-normal text-white/50">
                    <p>Não entraram:</p>
                    <ul className="list-disc pl-4">
                        {visiveis.map((item, i) => (
                            <li key={i}>{textoSeguro(item?.motivo ?? item, '')}</li>
                        ))}
                    </ul>
                    {resto > 0 && <p className="mt-1">e mais {resto}</p>}
                </div>
            )}
            <div className="mt-3 flex gap-2">
                <Button type="button" size="sm" disabled={agindo === 'importar'} onClick={acoes?.importar}>
                    Importar
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={agindo === 'importar'}
                    onClick={acoes?.fecharPrevia}
                >
                    Cancelar
                </Button>
            </div>
        </div>
    );
}

/** O que aparece em cada um dos sete estados — e os botões de cada um. */
function CorpoDoEstado({ estado, bling, agindo, acoes }) {
    const motivo = textoSeguro(bling?.motivo, null);

    if (!bling || !estado) {
        return <p className="text-[12px] font-normal text-white/50">Sem informação de conexão agora.</p>;
    }

    switch (estado) {
        case 'sem_company':
        case 'nao_configurado':
            return <p className="text-[12px] font-normal text-white/50">{motivo ?? 'Não é possível conectar agora.'}</p>;

        case 'a_conectar':
            return (
                <div>
                    <p className="text-[12px] font-normal text-white/60">
                        Gere um link e envie ao cliente para conectar a loja.
                    </p>
                    <div className="mt-2">
                        <Button type="button" size="sm" disabled={agindo === 'gerarLink'} onClick={acoes?.gerarLink}>
                            Gerar link para o cliente
                        </Button>
                    </div>
                </div>
            );

        case 'aguardando_cliente': {
            const geradoEm = formatarDataHora(bling?.link_gerado_em);
            const expiraEm = formatarDataHora(bling?.link_expira_em);
            const expiraTs = typeof bling?.link_expira_em === 'string' ? new Date(bling?.link_expira_em).getTime() : NaN;
            const vencido = !Number.isNaN(expiraTs) && expiraTs < Date.now();

            return (
                <div>
                    <p className="text-[12px] font-normal text-white/60">
                        {geradoEm ? `Link gerado em ${geradoEm}` : 'Link gerado'}
                        {expiraEm && `, vale até ${expiraEm}`}
                        {vencido && <span className="ml-1 font-bold text-red-400">vencido</span>}
                    </p>
                    <div className="mt-2 flex flex-wrap items-center gap-2">
                        <BotaoCopiarLink url={bling?.link_url} />
                        <Button type="button" size="sm" variant="outline" disabled={agindo === 'gerarLink'} onClick={acoes?.gerarLink}>
                            Gerar outro link
                        </Button>
                    </div>
                </div>
            );
        }

        case 'conectado': {
            const nome = textoSeguro(bling?.bling_nome, null);
            const cnpj = textoSeguro(bling?.bling_cnpj, null);
            const conectadoEm = formatarDataHora(bling?.conectado_em);
            const ultimaImportacao = formatarDataHora(bling?.ultima_importacao_em);
            const ultimoEstoque = formatarDataHora(bling?.ultimo_estoque_em);
            const produtos = typeof bling?.produtos_vinculados === 'number' ? bling?.produtos_vinculados : 0;

            return (
                <div>
                    <p className="text-[12px] font-normal text-white/70">
                        {nome ?? 'Conta conectada'}
                        {cnpj && ` · ${cnpj}`}
                    </p>
                    {conectadoEm && <p className="mt-1 text-[11px] font-normal text-white/40">Conectado em {conectadoEm}</p>}
                    <p className="mt-1 text-[11px] font-normal text-white/40">
                        {produtos} {produtos === 1 ? 'produto vinculado' : 'produtos vinculados'}
                    </p>
                    {ultimaImportacao && (
                        <p className="mt-1 text-[11px] font-normal text-white/40">Última importação em {ultimaImportacao}</p>
                    )}
                    {ultimoEstoque && (
                        <p className="mt-1 text-[11px] font-normal text-white/40">Último estoque em {ultimoEstoque}</p>
                    )}
                    <div className="mt-2 flex flex-wrap gap-2">
                        <Button type="button" size="sm" disabled={agindo === 'iniciarLeitura'} onClick={acoes?.iniciarLeitura}>
                            Importar produtos
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={agindo === 'atualizarEstoque'}
                            onClick={acoes?.atualizarEstoque}
                        >
                            Atualizar estoque agora
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="destructive"
                            disabled={agindo === 'desconectar'}
                            onClick={acoes?.desconectar}
                        >
                            Desconectar
                        </Button>
                    </div>
                </div>
            );
        }

        case 'divergente':
            return (
                <div>
                    <p className="text-[12px] font-bold text-amber-300">
                        {motivo ?? 'A conta do Bling não confere com a desta empresa.'}
                    </p>
                    <div className="mt-2 flex flex-wrap gap-2">
                        <Button type="button" size="sm" disabled={agindo === 'gerarLink'} onClick={acoes?.gerarLink}>
                            Gerar outro link
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="destructive"
                            disabled={agindo === 'desconectar'}
                            onClick={acoes?.desconectar}
                        >
                            Desconectar
                        </Button>
                    </div>
                </div>
            );

        case 'erro': {
            const erroEm = formatarDataHora(bling?.ultimo_erro_em);
            return (
                <div>
                    <p className="text-[12px] font-bold text-red-400">
                        {textoSeguro(bling?.ultimo_erro, 'Não foi possível conectar.')}
                    </p>
                    {erroEm && <p className="mt-1 text-[11px] font-normal text-white/40">em {erroEm}</p>}
                    <div className="mt-2 flex flex-wrap gap-2">
                        <Button type="button" size="sm" disabled={agindo === 'gerarLink'} onClick={acoes?.gerarLink}>
                            Gerar outro link
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="destructive"
                            disabled={agindo === 'desconectar'}
                            onClick={acoes?.desconectar}
                        >
                            Desconectar
                        </Button>
                    </div>
                </div>
            );
        }

        default:
            // Estado desconhecido (um valor novo que o servidor passe a mandar):
            // ramo neutro, nenhum botão, nunca uma tela em branco.
            return <p className="text-[12px] font-normal text-white/50">{motivo ?? textoSeguro(estado, 'Estado desconhecido.')}</p>;
    }
}

/**
 * Parte PURA de apresentação — recebe tudo já pronto (sem chamar o hook),
 * pro teste de render exercitar com o JSON real do controller (mesma defesa
 * de `ConteudoConfiguracoes`/`CampoIdentidade`: campo em formato inesperado
 * nunca derruba a tela).
 */
export function SecaoBling({ bling, agindo = null, erro = null, previa = null, acoes = {} }) {
    const estado = bling?.estado ?? null;

    return (
        <section className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
            <div className="flex items-center justify-between gap-3">
                <h3 className="text-[13px] font-bold text-white/90">Bling</h3>
                {estado && <Selo estado={estado} />}
            </div>

            {erro && (
                <p className="mt-2 text-[12px] font-normal text-red-400">{textoSeguro(erro, 'Não foi possível concluir.')}</p>
            )}

            <div className="mt-3">
                <CorpoDoEstado estado={estado} bling={bling} agindo={agindo} acoes={acoes} />
            </div>

            {bling?.disponivel && <TetoBling bling={bling} />}

            <PreviaImportacao previa={previa} agindo={agindo} acoes={acoes} />
        </section>
    );
}

/** Chama o hook e alimenta `SecaoBling` — é isto que `Configuracoes.jsx` importa e renderiza. */
export default function BlingDaLoja({ conta, inicial }) {
    const hook = useBlingDaLoja({ conta, inicial });

    return (
        <SecaoBling
            bling={hook.bling}
            agindo={hook.agindo}
            erro={hook.erro}
            previa={hook.previa}
            acoes={{
                gerarLink: hook.gerarLink,
                desconectar: hook.desconectar,
                iniciarLeitura: hook.iniciarLeitura,
                importar: hook.importar,
                atualizarEstoque: hook.atualizarEstoque,
                fecharPrevia: hook.fecharPrevia,
            }}
        />
    );
}
