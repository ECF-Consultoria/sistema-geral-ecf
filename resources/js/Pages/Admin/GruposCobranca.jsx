import { useEffect, useMemo, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { cn, formatCurrency } from '@/lib/utils';
import ParticipacaoFechamento from '@/Components/Fechamento/ParticipacaoFechamento';
import {
    ArrowLeft,
    AlertTriangle,
    ArrowUpRight,
    Layers,
    Plus,
    Users,
    X,
} from 'lucide-react';

/**
 * Admin/GruposCobranca.jsx — a tela onde o grupo de cobrança é montado
 * (Fase 143 Plano 04).
 *
 * ⚠️ **É esta tela que decide se a Fase 143 valeu alguma coisa.** O usuário
 * foi explícito (143-CONTEXT, D-06): a correção existe "para que, caso
 * existam outros casos, seja possível resolver pela UI". Só conhecemos um
 * caso (MPozenato) e não dá para achar os outros por dado — 145 das 203
 * empresas estão sem CNPJ. A montagem é curadoria humana feita aqui; se a
 * tela não for boa, a fase vira uma migration manual disfarçada.
 *
 * ## As três coisas que esta tela não pode deixar de fazer
 *
 * 1. **Nada é gravado sem a prévia na frente.** O botão de confirmar só
 *    existe depois que a prévia do backend voltou, e qualquer mudança na
 *    seleção ou no destino APAGA a prévia (o `useEffect` lá embaixo) — para
 *    ninguém confirmar um arranjo lendo o número de outro.
 * 2. **A prévia diz de ONDE vem a tabela.** No caso real a diferença entre
 *    R$ 21.000 e R$ 12.000 é exatamente qual tabela governa; mostrar só o
 *    número final transformaria uma decisão de R$ 9.000/mês num palpite.
 * 3. **Grupo de cobrança sem tabela própria é avisado ANTES de juntar**, com
 *    o caminho para cadastrar. Juntar sem tabela faz a cobrança seguir uma
 *    tabela copiada do serviço e cair mais do que deveria.
 *
 * ⚠️ **Queda de cobrança é o caso normal aqui, não erro.** A tela mostra o
 * número grande e legível porque é decisão de dinheiro, mas sem pintar a
 * redução de vermelho nem de alerta: ela é a correção.
 *
 * ⛔ **Nenhuma conta de faixa mora aqui.** Todo número (faturamento, faixa,
 * mensalidade, diferença) vem pronto do backend — da mesma máquina que o
 * fechamento usa para congelar a competência. Uma segunda conta na tela
 * seria o jeito mais rápido de a tela dizer um valor e a fatura sair outro.
 */
export default function GruposCobranca({ mes, grupos = [], total_cobranca = 0 }) {
    const { flash, errors } = usePage().props;

    // ── Montagem: quais grupos juntar e para onde ────────────────────────
    const [selecionados, setSelecionados] = useState([]);
    const [destinoId, setDestinoId] = useState('');
    const [previa, setPrevia] = useState(null);
    const [carregandoPrevia, setCarregandoPrevia] = useState(false);
    const [erroPrevia, setErroPrevia] = useState(null);
    const [cienteSemTabela, setCienteSemTabela] = useState(false);
    const [gravando, setGravando] = useState(false);

    // ── Tirar um grupo de dentro de outro (também com prévia antes) ──────
    const [saida, setSaida] = useState(null);

    const montados = grupos.filter(g => g.dentro.length > 0);
    const sozinhos = grupos.filter(g => g.dentro.length === 0);

    // Só quem está sendo cobrado sozinho pode entrar num grupo de cobrança:
    // grupo que já tem outros dentro dele não pode ser colocado em ninguém.
    const podemEntrar = sozinhos;

    // Destinos possíveis: qualquer grupo que não esteja selecionado.
    const destinos = grupos.filter(g => !selecionados.includes(g.id));

    const grupoDestino = useMemo(
        () => grupos.find(g => String(g.id) === String(destinoId)) ?? null,
        [grupos, destinoId]
    );

    // ⚠️ A trava central da tela: mexeu na seleção ou no destino, a prévia
    // some. Confirmar lendo o número de um arranjo anterior é exatamente o
    // erro que esta fase existe para evitar.
    useEffect(() => {
        setPrevia(null);
        setErroPrevia(null);
        setCienteSemTabela(false);
    }, [selecionados, destinoId]);

    const alternarSelecao = (id) => {
        setSelecionados(atual => atual.includes(id)
            ? atual.filter(i => i !== id)
            : [...atual, id]);
    };

    const limparMontagem = () => {
        setSelecionados([]);
        setDestinoId('');
        setPrevia(null);
        setErroPrevia(null);
        setCienteSemTabela(false);
    };

    const pedirPrevia = () => {
        if (selecionados.length === 0 || !destinoId) return;

        setCarregandoPrevia(true);
        setErroPrevia(null);

        window.axios
            .get(route('admin.contratos.grupos.hierarquia.previa'), {
                params: { grupo_ids: selecionados, pai_id: destinoId, mes },
            })
            .then(resposta => setPrevia(resposta.data))
            .catch(erro => setErroPrevia(
                erro?.response?.data?.message
                ?? 'Não foi possível calcular o que muda na cobrança. Tente de novo.'
            ))
            .finally(() => setCarregandoPrevia(false));
    };

    const confirmarJuncao = () => {
        if (!previa) return;

        setGravando(true);

        router.post(
            route('admin.contratos.grupos.hierarquia.pendurar'),
            { grupo_ids: selecionados, pai_id: destinoId, mes },
            {
                preserveScroll: true,
                onSuccess: () => limparMontagem(),
                onFinish: () => setGravando(false),
            }
        );
    };

    // ── Tirar de dentro: mesma disciplina, prévia antes de gravar ────────
    const pedirPreviaDeSaida = (grupo) => {
        setSaida({ id: grupo.id, nome: grupo.nome, previa: null, carregando: true, erro: null });

        window.axios
            .get(route('admin.contratos.grupos.hierarquia.previa'), {
                params: { grupo_ids: [grupo.id], pai_id: null, mes },
            })
            .then(resposta => setSaida(atual => atual && atual.id === grupo.id
                ? { ...atual, previa: resposta.data, carregando: false }
                : atual))
            .catch(erro => setSaida(atual => atual && atual.id === grupo.id
                ? {
                    ...atual,
                    carregando: false,
                    erro: erro?.response?.data?.message
                        ?? 'Não foi possível calcular o que muda na cobrança. Tente de novo.',
                }
                : atual));
    };

    const confirmarSaida = () => {
        if (!saida?.previa) return;

        router.delete(route('admin.contratos.grupos.hierarquia.despendurar'), {
            data: { grupo_ids: [saida.id], mes },
            preserveScroll: true,
            onSuccess: () => setSaida(null),
        });
    };

    return (
        <AppLayout title="Adm · Grupos de cobrança">
            <main className="p-6">
                <div className="space-y-6 max-w-5xl">
                    <Cabecalho mes={mes} totalCobranca={total_cobranca} />

                    {flash?.success && (
                        <div className="rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-[13px] text-emerald-300">
                            {flash.success}
                        </div>
                    )}
                    {errors?.grupo_ids && (
                        <div className="rounded-xl border border-amber-500/20 bg-amber-500/10 px-4 py-3 text-[13px] text-amber-300">
                            {errors.grupo_ids}
                        </div>
                    )}

                    {/* Caminho primário: criar o grupo de cobrança, cadastrar a
                        tabela dele e só então juntar os grupos do cliente. */}
                    <CartaoCriarGrupo />

                    {grupos.length === 0 && (
                        <Card>
                            <CardContent className="p-4">
                                <p className="text-[13px] text-white/50">
                                    Nenhum grupo de empresas cadastrado ainda.
                                </p>
                            </CardContent>
                        </Card>
                    )}

                    {montados.length > 0 && (
                        <section className="space-y-3">
                            <h2 className="text-white/85 text-[15px] font-semibold">
                                Grupos que já são cobrados juntos
                            </h2>
                            {montados.map(grupo => (
                                <CartaoGrupoMontado
                                    key={grupo.id}
                                    grupo={grupo}
                                    saida={saida}
                                    onTirar={pedirPreviaDeSaida}
                                    onCancelarSaida={() => setSaida(null)}
                                    onConfirmarSaida={confirmarSaida}
                                    onColocarOutro={() => setDestinoId(String(grupo.id))}
                                />
                            ))}
                        </section>
                    )}

                    {sozinhos.length > 0 && (
                        <section className="space-y-3">
                            <div>
                                <h2 className="text-white/85 text-[15px] font-semibold">
                                    Grupos cobrados sozinhos
                                </h2>
                                <p className="text-[13px] text-white/50 mt-0.5">
                                    {selecionados.length === 0 && grupoDestino
                                        ? `Marque aqui os grupos que vão ser cobrados dentro de ${grupoDestino.nome}.`
                                        : 'Marque os grupos que são do mesmo cliente para cobrá-los juntos.'}
                                </p>
                            </div>
                            <Card>
                                <CardContent className="p-0">
                                    <ul className="divide-y divide-white/[0.06]">
                                        {podemEntrar.map(grupo => (
                                            <LinhaGrupoSozinho
                                                key={grupo.id}
                                                grupo={grupo}
                                                marcado={selecionados.includes(grupo.id)}
                                                onAlternar={() => alternarSelecao(grupo.id)}
                                            />
                                        ))}
                                    </ul>
                                </CardContent>
                            </Card>
                        </section>
                    )}

                    {selecionados.length > 0 && (
                        <PainelMontagem
                            grupos={grupos}
                            selecionados={selecionados}
                            destinos={destinos}
                            destinoId={destinoId}
                            grupoDestino={grupoDestino}
                            onDestino={setDestinoId}
                            onLimpar={limparMontagem}
                            previa={previa}
                            carregandoPrevia={carregandoPrevia}
                            erroPrevia={erroPrevia}
                            onPedirPrevia={pedirPrevia}
                            cienteSemTabela={cienteSemTabela}
                            onCiente={setCienteSemTabela}
                            gravando={gravando}
                            onConfirmar={confirmarJuncao}
                        />
                    )}
                </div>
            </main>
        </AppLayout>
    );
}

/** "2026-08" → "agosto de 2026". */
function mesPorExtenso(mes) {
    if (!mes) return '';

    const [ano, numero] = String(mes).split('-').map(Number);

    if (!ano || !numero) return mes;

    return new Date(ano, numero - 1, 1)
        .toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' });
}

function Cabecalho({ mes, totalCobranca }) {
    return (
        <div className="space-y-2">
            <Link
                href={route('admin.contratos.index')}
                className="inline-flex items-center gap-1 text-[12px] text-white/40 hover:text-white/70"
            >
                <ArrowLeft size={12} /> Voltar para contratos
            </Link>
            <h1 className="text-xl font-semibold font-display text-white flex items-center gap-2">
                <Layers size={20} className="text-ecf-yellow" />
                Grupos de cobrança
            </h1>
            <p className="text-[13px] text-white/60 max-w-3xl">
                Quando o mesmo cliente está cadastrado em vários grupos, ele é cobrado como se fosse
                vários clientes diferentes — e perde o desconto por volume da tabela. Aqui os grupos
                do mesmo cliente são juntados para a cobrança sair uma só.
            </p>
            <p className="text-[12px] text-white/40">
                Os valores desta tela usam o faturamento de {mesPorExtenso(mes)}. Somando todos os
                grupos, a cobrança de hoje é de {formatCurrency(totalCobranca)} por mês.
            </p>
        </div>
    );
}

/**
 * Criar um grupo de cobrança — o caminho primário (143-04-PLAN, T1).
 *
 * Criar o grupo primeiro evita o "MPozenato dentro do MPozenato" e deixa o
 * nome do grupo de cobrança livre. E é a única ordem possível: o grupo
 * precisa existir para se cadastrar a tabela dele, e a tabela precisa
 * existir ANTES de juntar — senão a cobrança segue uma tabela copiada do
 * serviço e cai mais do que deveria.
 *
 * Criar um grupo vazio não muda a cobrança de ninguém: ele não tem empresa
 * nem grupo nenhum dentro. Quem muda cobrança é juntar, e isso só acontece
 * depois da prévia.
 */
function CartaoCriarGrupo() {
    const [aberto, setAberto] = useState(false);
    const [nome, setNome] = useState('');
    const [gravando, setGravando] = useState(false);

    const criar = () => {
        if (nome.trim() === '') return;

        setGravando(true);

        router.post(route('admin.contratos.grupos.criar'), { name: nome.trim() }, {
            preserveScroll: true,
            onSuccess: () => { setNome(''); setAberto(false); },
            onFinish: () => setGravando(false),
        });
    };

    if (!aberto) {
        return (
            <Button type="button" variant="outline" onClick={() => setAberto(true)}>
                <Plus size={14} className="mr-1.5" /> Criar um grupo de cobrança
            </Button>
        );
    }

    return (
        <Card>
            <CardContent className="p-4 space-y-3">
                <div>
                    <h2 className="text-white/85 text-[15px] font-semibold">Criar um grupo de cobrança</h2>
                    <p className="text-[13px] text-white/60 mt-0.5">
                        Use o nome do cliente. Depois de criar, cadastre a tabela de cobrança dele e
                        então escolha quais grupos entram.
                    </p>
                </div>
                <div className="flex flex-wrap items-end gap-2">
                    <div className="flex-1 min-w-[220px]">
                        <Label htmlFor="nome-grupo-cobranca" className="text-[12px] text-white/50">
                            Nome do grupo
                        </Label>
                        <Input
                            id="nome-grupo-cobranca"
                            value={nome}
                            onChange={e => setNome(e.target.value)}
                            placeholder="Nome do cliente"
                            className="mt-1"
                        />
                    </div>
                    <Button type="button" onClick={criar} disabled={gravando || nome.trim() === ''}>
                        {gravando ? 'Criando...' : 'Criar grupo'}
                    </Button>
                    <Button type="button" variant="ghost" onClick={() => setAberto(false)}>
                        Cancelar
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}

/**
 * Selo de como a tabela que governa a cobrança chegou ali — mesmo
 * vocabulário de `Admin/TabelaEmpresa.jsx` e `Admin/ContratoDetalhe.jsx`,
 * para as três telas não contarem histórias diferentes do mesmo dado.
 */
function SeloDaTabela({ procedencia, tabelaGrupoNome, servicoNome, herdadaDeNome }) {
    if (!procedencia && !tabelaGrupoNome) return null;

    const selo = {
        contrato: {
            texto: 'Tabela conferida pelo contrato assinado',
            classe: 'bg-emerald-500/10 text-emerald-300 border-emerald-500/20',
        },
        manual: {
            texto: 'Tabela cadastrada à mão no sistema',
            classe: 'bg-white/[0.06] text-white/60 border-white/10',
        },
        presumida_servico: {
            texto: 'Tabela copiada do serviço contratado — ninguém conferiu contra o contrato ainda',
            classe: 'bg-amber-500/10 text-amber-300 border-amber-500/20',
        },
    }[procedencia] ?? {
        texto: 'Tabela cadastrada à mão no sistema',
        classe: 'bg-white/[0.06] text-white/60 border-white/10',
    };

    const de = tabelaGrupoNome
        ? `do grupo ${tabelaGrupoNome}`
        : (herdadaDeNome ? `da empresa ${herdadaDeNome}` : (servicoNome ? `do serviço ${servicoNome}` : null));

    return (
        <span className={cn('inline-block text-[11px] font-semibold px-2 py-0.5 rounded-full border', selo.classe)}>
            {selo.texto}{de ? ` (${de})` : ''}
        </span>
    );
}

/** Quanto este grupo cobra hoje, com quantas empresas — a escala da decisão. */
function ResumoDoGrupo({ grupo }) {
    return (
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px]">
            <span className="inline-flex items-center gap-1.5 text-white/60">
                <Users size={13} />
                {grupo.empresas_count === 1 ? '1 empresa' : `${grupo.empresas_count} empresas`}
            </span>
            <span className="text-white/60">
                Faturamento: <span className="text-white/80 tabular-nums">{grupo.faturamento_total == null ? '—' : formatCurrency(grupo.faturamento_total)}</span>
            </span>
            <span className="text-white/60">
                Cobrança: <span className="text-white font-semibold tabular-nums">{grupo.cobranca_mensal == null ? '—' : `${formatCurrency(grupo.cobranca_mensal)}/mês`}</span>
            </span>
        </div>
    );
}

/**
 * Quick 260916-onn — porta do GRUPO para "não participa do fechamento".
 * Marcado aqui, o grupo inteiro (e os grupos dentro dele) sai do fechamento;
 * a tabela e a cobrança mostradas acima continuam cadastradas.
 */
function ParticipacaoDoGrupo({ grupo, compacto = false }) {
    if (!grupo.fora_do_fechamento) return null;

    return (
        <ParticipacaoFechamento
            estado={grupo.fora_do_fechamento}
            urlMarcar={route('admin.contratos.fora-fechamento.grupo.marcar', grupo.id)}
            urlDesmarcar={route('admin.contratos.fora-fechamento.grupo.desmarcar', grupo.id)}
            alvo={`o grupo ${grupo.nome}`}
            compacto={compacto}
        />
    );
}

/** Um grupo de cobrança já montado, com os grupos que estão dentro dele. */
function CartaoGrupoMontado({ grupo, saida, onTirar, onCancelarSaida, onConfirmarSaida, onColocarOutro }) {
    const saindoDaqui = saida != null && grupo.dentro.some(d => d.id === saida.id);

    return (
        <Card>
            <CardContent className="p-4 space-y-3">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="space-y-1">
                        <h3 className="text-white text-[15px] font-semibold">{grupo.nome}</h3>
                        <ResumoDoGrupo grupo={grupo} />
                        <SeloDaTabela
                            procedencia={grupo.procedencia}
                            tabelaGrupoNome={grupo.tabela_grupo_nome}
                            servicoNome={grupo.tabela_servico_nome}
                            herdadaDeNome={grupo.tabela_herdada_de_nome}
                        />
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Link href={route('admin.contratos.tabela.grupo.show', grupo.id)}>
                            <Button type="button" variant="outline" size="sm">
                                Abrir a tabela deste grupo <ArrowUpRight size={13} className="ml-1" />
                            </Button>
                        </Link>
                        <Button type="button" variant="ghost" size="sm" onClick={onColocarOutro}>
                            Colocar outro grupo aqui
                        </Button>
                    </div>
                </div>

                <ParticipacaoDoGrupo grupo={grupo} compacto />

                {!grupo.tem_tabela_propria && <AvisoSemTabela grupo={grupo} />}

                <div className="space-y-1.5">
                    <p className="text-[12px] text-white/40">Grupos que fazem parte de {grupo.nome}:</p>
                    <ul className="divide-y divide-white/[0.06] rounded-xl border border-white/[0.06] overflow-hidden">
                        {grupo.dentro.map(dentro => (
                            <li key={dentro.id} className="flex items-center justify-between gap-3 px-[18px] py-2.5 text-[13px]">
                                <span className="text-white/80 truncate">
                                    {dentro.nome}
                                    <span className="text-white/40 ml-2">
                                        {dentro.empresas_count === 1 ? '1 empresa' : `${dentro.empresas_count} empresas`}
                                    </span>
                                    {dentro.fora_do_fechamento?.marcado && (
                                        <span className="text-amber-200/70 ml-2">· não participa do fechamento</span>
                                    )}
                                </span>
                                {/* Um grupo de dentro só aparece aqui para ser desfeito:
                                    marcar se faz no grupo de cima, que leva todos. */}
                                {dentro.fora_do_fechamento?.marcado && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => router.delete(
                                            route('admin.contratos.fora-fechamento.grupo.desmarcar', dentro.id),
                                            { preserveScroll: true }
                                        )}
                                    >
                                        Voltar a participar
                                    </Button>
                                )}
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => onTirar(dentro)}
                                >
                                    Tirar deste grupo
                                </Button>
                            </li>
                        ))}
                    </ul>
                </div>

                {saindoDaqui && (
                    <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4 space-y-3">
                        <h4 className="text-white/85 text-[14px] font-semibold">
                            O que muda se {saida.nome} sair deste grupo
                        </h4>
                        {saida.carregando && <p className="text-[13px] text-white/50">Calculando...</p>}
                        {saida.erro && (
                            <p className="text-[13px] text-amber-300">{saida.erro}</p>
                        )}
                        {saida.previa && <PainelPrevia previa={saida.previa} />}
                        <div className="flex flex-wrap gap-2">
                            {/* ⚠️ Confirmar só existe depois que a prévia voltou. */}
                            {saida.previa && (
                                <Button type="button" onClick={onConfirmarSaida}>
                                    Confirmar e tirar {saida.nome} daqui
                                </Button>
                            )}
                            <Button type="button" variant="ghost" onClick={onCancelarSaida}>
                                Cancelar
                            </Button>
                        </div>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

/** Uma linha da lista de grupos cobrados sozinhos, com a caixa de seleção. */
function LinhaGrupoSozinho({ grupo, marcado, onAlternar }) {
    return (
        <li className={cn('px-[18px] py-3 transition-colors', marcado && 'bg-ecf-yellow/[0.04]')}>
            <label className="flex items-start gap-3 cursor-pointer">
                <input
                    type="checkbox"
                    checked={marcado}
                    onChange={onAlternar}
                    className="mt-1 h-4 w-4 rounded border-white/20 bg-transparent accent-ecf-yellow"
                />
                <span className="flex-1 min-w-0 space-y-1">
                    <span className="block text-white/85 text-[14px] font-semibold">{grupo.nome}</span>
                    <ResumoDoGrupo grupo={grupo} />
                    <SeloDaTabela
                        procedencia={grupo.procedencia}
                        tabelaGrupoNome={grupo.tabela_grupo_nome}
                        servicoNome={grupo.tabela_servico_nome}
                        herdadaDeNome={grupo.tabela_herdada_de_nome}
                    />
                </span>
            </label>
            {/* Fora do <label>: clicar aqui não pode marcar a caixa de seleção. */}
            <div className="pl-7 pt-2">
                <ParticipacaoDoGrupo grupo={grupo} compacto />
            </div>
        </li>
    );
}

/**
 * ⚠️ O aviso do item 3 do plano: grupo de cobrança sem tabela própria.
 * Sem tabela cadastrada nele, a cobrança do conjunto segue a tabela de uma
 * das empresas — que costuma ser uma cópia do serviço, nunca conferida — e
 * cai mais do que deveria (R$ 12.000 em vez de R$ 21.000, no caso real).
 */
function AvisoSemTabela({ grupo }) {
    return (
        <div className="rounded-xl border border-amber-500/20 bg-amber-500/10 px-4 py-3 space-y-2">
            <p className="text-[13px] text-amber-300 font-semibold flex items-center gap-2">
                <AlertTriangle size={14} className="shrink-0" />
                {grupo.nome} ainda não tem tabela de cobrança própria
            </p>
            <p className="text-[13px] text-white/70">
                Sem ela, a cobrança do conjunto vai seguir a tabela de uma das empresas — que na
                maioria dos casos foi copiada do serviço contratado e nunca conferida contra o
                contrato. O valor pode cair bem mais do que deveria. Cadastre a tabela antes de
                juntar os grupos.
            </p>
            <Link href={route('admin.contratos.tabela.grupo.show', grupo.id)}>
                <Button type="button" variant="outline" size="sm">
                    Cadastrar a tabela de {grupo.nome} <ArrowUpRight size={13} className="ml-1" />
                </Button>
            </Link>
        </div>
    );
}

/**
 * O painel de montagem: quem entra, para onde vai, o que muda, e só então
 * o botão de confirmar.
 */
function PainelMontagem({
    grupos,
    selecionados,
    destinos,
    destinoId,
    grupoDestino,
    onDestino,
    onLimpar,
    previa,
    carregandoPrevia,
    erroPrevia,
    onPedirPrevia,
    cienteSemTabela,
    onCiente,
    gravando,
    onConfirmar,
}) {
    const nomesSelecionados = grupos
        .filter(g => selecionados.includes(g.id))
        .map(g => g.nome);

    const faltaTabelaNoDestino = grupoDestino != null && !grupoDestino.tem_tabela_propria;
    const travadoPelaTabela = faltaTabelaNoDestino && !cienteSemTabela;

    return (
        <Card className="border-ecf-yellow/25">
            <CardContent className="p-4 space-y-4">
                <div className="flex items-start justify-between gap-2">
                    <div>
                        <h2 className="text-white text-[15px] font-semibold">
                            Juntar {selecionados.length === 1 ? 'este grupo' : `estes ${selecionados.length} grupos`}
                        </h2>
                        <p className="text-[13px] text-white/60 mt-0.5">{nomesSelecionados.join(', ')}</p>
                    </div>
                    <Button type="button" variant="ghost" size="sm" onClick={onLimpar}>
                        <X size={14} className="mr-1" /> Limpar
                    </Button>
                </div>

                <div className="space-y-1.5">
                    <Label htmlFor="destino-grupo-cobranca" className="text-[12px] text-white/50">
                        Cobrar dentro de qual grupo
                    </Label>
                    <select
                        id="destino-grupo-cobranca"
                        value={destinoId}
                        onChange={e => onDestino(e.target.value)}
                        className="w-full h-10 rounded-xl bg-ecf-card-2 border border-white/[0.08] px-3 text-[13px] text-white/85 focus:outline-none focus:border-ecf-yellow/40"
                    >
                        <option value="">Escolha o grupo de cobrança...</option>
                        {destinos.map(g => (
                            <option key={g.id} value={g.id}>{g.nome}</option>
                        ))}
                    </select>
                    <p className="text-[12px] text-white/40">
                        Se o grupo de cobrança ainda não existe, crie ele no botão
                        "Criar um grupo de cobrança", no topo desta tela.
                    </p>
                </div>

                {faltaTabelaNoDestino && <AvisoSemTabela grupo={grupoDestino} />}

                {previa == null && (
                    <Button
                        type="button"
                        onClick={onPedirPrevia}
                        disabled={!destinoId || carregandoPrevia}
                    >
                        {carregandoPrevia ? 'Calculando...' : 'Ver o que muda na cobrança'}
                    </Button>
                )}

                {erroPrevia && (
                    <div className="rounded-xl border border-amber-500/20 bg-amber-500/10 px-4 py-3 text-[13px] text-amber-300">
                        {erroPrevia}
                    </div>
                )}

                {/* ⚠️ Nada é gravado sem isto na frente: o botão de confirmar
                    só existe dentro deste bloco, e a prévia é apagada sempre
                    que a seleção ou o destino mudam. */}
                {previa && (
                    <div className="space-y-4">
                        <PainelPrevia previa={previa} />

                        {faltaTabelaNoDestino && (
                            <label className="flex items-start gap-2 text-[13px] text-white/70 cursor-pointer">
                                <input
                                    type="checkbox"
                                    checked={cienteSemTabela}
                                    onChange={e => onCiente(e.target.checked)}
                                    className="mt-1 h-4 w-4 rounded border-white/20 bg-transparent accent-ecf-yellow"
                                />
                                <span>
                                    Li o aviso acima e quero juntar mesmo sem tabela cadastrada em {grupoDestino.nome}.
                                </span>
                            </label>
                        )}

                        <Button
                            type="button"
                            onClick={onConfirmar}
                            disabled={gravando || travadoPelaTabela}
                        >
                            {gravando ? 'Salvando...' : 'Confirmar e juntar os grupos'}
                        </Button>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

/**
 * A prévia do impacto — o coração da tela (143-04-PLAN, T2).
 *
 * Mostra o que se cobra hoje, o que passaria a ser cobrado, e a diferença
 * com sinal. No caso real: R$ 33.500 em quatro mensalidades → R$ 21.000
 * numa só, −R$ 12.500 por mês.
 *
 * ⚠️ A queda aparece grande e legível, mas NÃO como alerta de erro: juntar
 * os grupos de um mesmo cliente faz a tabela dar o desconto por volume que
 * ela sempre deveria ter dado. O texto diz isso com todas as letras.
 *
 * ⛔ Nenhum número é calculado aqui — todos vêm da prévia do backend.
 */
function PainelPrevia({ previa }) {
    const antes = previa.antes;
    const depois = previa.depois;
    const delta = previa.delta ?? 0;
    const caiu = delta < 0;
    const semMudanca = Math.abs(delta) < 0.005;

    return (
        <div className="space-y-3">
            <div className="grid gap-3 md:grid-cols-2">
                <LadoDaPrevia
                    titulo="Como está sendo cobrado hoje"
                    linhas={antes.linhas}
                    total={antes.total_cobranca}
                />
                <LadoDaPrevia
                    titulo="Como passaria a ser cobrado"
                    linhas={depois.linhas}
                    total={depois.total_cobranca}
                    destaque
                />
            </div>

            <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] px-4 py-3">
                <p className="text-[12px] text-white/50 uppercase tracking-[0.05em] font-semibold">
                    Diferença na cobrança
                </p>
                <p className={cn(
                    'text-3xl font-bold tabular-nums mt-1',
                    semMudanca ? 'text-white/70' : (caiu ? 'text-ecf-yellow' : 'text-emerald-300')
                )}>
                    {semMudanca ? formatCurrency(0) : `${delta > 0 ? '+' : '−'}${formatCurrency(Math.abs(delta))}`}
                    <span className="text-[14px] font-semibold text-white/50 ml-2">por mês</span>
                </p>
                <p className="text-[13px] text-white/60 mt-1">
                    {semMudanca
                        ? 'A cobrança continua igual com esta mudança.'
                        : caiu
                            ? `A cobrança cai ${formatCurrency(Math.abs(delta))} por mês, ou ${formatCurrency(Math.abs(delta) * 12)} por ano. Cair é o resultado esperado: cobrado em pedaços, o cliente paga como se fosse vários clientes médios e perde o desconto por volume da tabela.`
                            : `A cobrança sobe ${formatCurrency(Math.abs(delta))} por mês, ou ${formatCurrency(Math.abs(delta) * 12)} por ano.`}
                </p>
            </div>
        </div>
    );
}

/** Um lado da prévia — as linhas de cobrança e o total daquele lado. */
function LadoDaPrevia({ titulo, linhas = [], total, destaque = false }) {
    return (
        <div className={cn(
            'rounded-xl border p-3 space-y-2',
            destaque ? 'border-ecf-yellow/25 bg-ecf-yellow/[0.03]' : 'border-white/[0.08] bg-white/[0.02]'
        )}>
            <div>
                <p className="text-[12px] text-white/50 uppercase tracking-[0.05em] font-semibold">{titulo}</p>
                <p className="text-white text-lg font-bold tabular-nums mt-0.5">
                    {formatCurrency(total)}<span className="text-[13px] font-semibold text-white/50 ml-1.5">por mês</span>
                </p>
                <p className="text-[12px] text-white/50">
                    {linhas.length === 1 ? '1 cobrança' : `${linhas.length} cobranças separadas`}
                </p>
            </div>

            <ul className="space-y-2">
                {linhas.map(linha => (
                    <li key={linha.company_group_id} className="rounded-lg bg-white/[0.03] px-3 py-2 space-y-1">
                        <div className="flex items-baseline justify-between gap-2">
                            <span className="text-[13px] text-white/85 font-semibold truncate">{linha.grupo_nome}</span>
                            <span className="text-[13px] text-white font-semibold tabular-nums shrink-0">
                                {linha.cobranca_mensal == null ? '—' : formatCurrency(linha.cobranca_mensal)}
                            </span>
                        </div>
                        <p className="text-[12px] text-white/50">
                            {linha.empresas_count === 1 ? '1 empresa' : `${linha.empresas_count} empresas`}
                            {linha.faturamento_total != null && ` · faturamento de ${formatCurrency(linha.faturamento_total)}`}
                            {linha.faixa_label && ` · ${linha.faixa_label}`}
                        </p>
                        {linha.subgrupos?.length > 1 && (
                            <p className="text-[12px] text-white/40">
                                Junta: {linha.subgrupos.map(s => s.nome).join(', ')}
                            </p>
                        )}
                        {/* ⚠️ De onde vem a tabela: é o que separa R$ 21.000 de
                            R$ 12.000 no caso real. */}
                        <SeloDaTabela
                            procedencia={linha.procedencia}
                            tabelaGrupoNome={linha.tabela_grupo_nome}
                            servicoNome={linha.tabela_servico_nome}
                            herdadaDeNome={linha.tabela_herdada_de_nome}
                        />
                    </li>
                ))}
            </ul>
        </div>
    );
}
