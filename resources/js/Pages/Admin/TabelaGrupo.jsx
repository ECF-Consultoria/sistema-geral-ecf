import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { CampoDinheiro } from '@/Components/ui/campo-dinheiro';
import TabelaProgressivaFaixas from '@/Components/Fechamento/TabelaProgressivaFaixas';
import { valorExibidoNoCampo, tetoGravado, indiceDeGravacao } from '@/lib/faixasFaturamento';
import { ArrowLeft, Users, Plus, Trash2, AlertTriangle, ArrowUpRight } from 'lucide-react';

/**
 * Admin/TabelaGrupo.jsx — a ficha da tabela de cobrança de um GRUPO de
 * empresas (Fase 143 Plano 03, T2).
 *
 * ⚠️ **Por que esta página precisou existir.** Até a Fase 143, a tabela de
 * um grupo só era editável de dentro da ficha de uma empresa-membro
 * (`Admin/TabelaEmpresa.jsx`, bloco 5). O grupo que fica POR CIMA dos
 * outros pode não ter empresa nenhuma pendurada direto nele — e é
 * justamente a tabela dele que governa a cobrança de todas as empresas
 * abaixo. Sem esta página, essa tabela era inalcançável pela tela.
 *
 * ⚠️ **O que esta tela nunca pode esconder: o TAMANHO da decisão.** Salvar
 * aqui muda a mensalidade de todas as empresas do grupo e dos grupos que
 * fazem parte dele — 10 de uma vez, no caso que abriu a fase. Por isso o
 * bloco "Empresas que esta tabela vai cobrar" vem ANTES do formulário,
 * contadas e listadas uma a uma, e não como uma frase genérica.
 *
 * Cada campo de valor usa `CampoDinheiro` (máscara, D-02 da Fase 142) —
 * NUNCA `type="number"` cru, que é o que deixava o zero a mais invisível.
 *
 * ⚠️ `FormularioFaixas` aqui é uma segunda definição, local, e isso é
 * deliberado: a gêmea vive dentro de `Admin/TabelaEmpresa.jsx`, cujo texto
 * é travado asserção por asserção em `Phase142FichaTabelaUiTest` — extrair
 * para um componente compartilhado quebraria aquele gate e é mudança para
 * ser feita de propósito, com o teste atualizado junto (registrado em
 * `deferred-items.md`). O que importa NÃO é duplicado: a conversão de borda
 * (teto ",99" ↔ valor redondo) mora inteira em `lib/faixasFaturamento.js`,
 * e a grade de leitura em `Components/Fechamento/TabelaProgressivaFaixas.jsx`.
 */
export default function TabelaGrupo({
    grupo,
    tabela_grupo = [],
    tabela_que_vale = null,
    subgrupos = [],
    empresas = [],
    modelos_de_partida = [],
}) {
    const { flash } = usePage().props;

    const tabelaEDeOutroGrupo = tabela_que_vale != null && tabela_que_vale.grupo_id !== grupo.id;

    return (
        <AppLayout title={`Adm · Tabela de cobrança — ${grupo.name}`}>
            <main className="p-6">
                <div className="space-y-6 max-w-4xl">
                    {/* Cabeçalho */}
                    <div>
                        <Link
                            href={route('admin.contratos.index')}
                            className="inline-flex items-center gap-1 text-[12px] text-white/40 hover:text-white/70 mb-2"
                        >
                            <ArrowLeft size={12} /> Voltar para contratos
                        </Link>
                        <h1 className="text-xl font-semibold font-display text-white flex items-center gap-2">
                            <Users size={20} className="text-ecf-yellow" />
                            {grupo.name}
                        </h1>
                        <p className="text-[13px] text-white/50 mt-0.5">Tabela de cobrança do grupo</p>
                    </div>

                    {flash?.success && (
                        <div className="rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-[13px] text-emerald-300">
                            {flash.success}
                        </div>
                    )}
                    {flash?.aviso && (
                        <div className="rounded-xl border border-amber-500/20 bg-amber-500/10 px-4 py-3 text-[13px] text-amber-300">
                            {flash.aviso}
                        </div>
                    )}

                    {/* Bloco 0 — este grupo faz parte de outro: quem manda é a tabela de lá. */}
                    {grupo.pai && <AvisoGrupoMaior pai={grupo.pai} />}

                    {/* Bloco 1 — o que está cobrando estas empresas hoje. */}
                    <BlocoTabelaQueVale
                        tabelaQueVale={tabela_que_vale}
                        tabelaEDeOutroGrupo={tabelaEDeOutroGrupo}
                    />

                    {/* Bloco 2 — quem esta tabela alcança. Vem ANTES do formulário de propósito. */}
                    <BlocoEmpresasAlcancadas grupo={grupo} subgrupos={subgrupos} empresas={empresas} />

                    {/* Bloco 3 — editar a tabela deste grupo. */}
                    <Card>
                        <CardContent className="p-4 space-y-3">
                            <h2 className="text-white/85 text-[15px] font-semibold">Tabela deste grupo</h2>
                            {tabela_grupo.length === 0 && (
                                <p className="text-white/40 text-[13px]">Este grupo ainda não tem tabela cadastrada.</p>
                            )}
                            <FormularioFaixas
                                linhasIniciais={tabela_grupo}
                                modelosDePartida={modelos_de_partida}
                                avisoAntesDeSalvar={
                                    empresas.length > 0
                                        ? `Esta tabela passa a valer para ${empresas.length === 1 ? '1 empresa' : `${empresas.length} empresas`}. Salvar agora?`
                                        : null
                                }
                                onSalvar={(faixas, { onSuccess, onError, onFinish }) => {
                                    router.post(route('admin.contratos.tabela.grupo.salvar', grupo.id), { faixas }, {
                                        preserveScroll: true,
                                        onSuccess,
                                        onError,
                                        onFinish,
                                    });
                                }}
                                onRemover={tabela_grupo.length > 0 ? () => {
                                    if (!confirm('Apagar a tabela deste grupo? Cada empresa volta a ser cobrada pela tabela dela.')) return;
                                    router.delete(route('admin.contratos.tabela.grupo.remover', grupo.id), { preserveScroll: true });
                                } : null}
                                rotuloRemover="Apagar a tabela deste grupo"
                            />
                        </CardContent>
                    </Card>
                </div>
            </main>
        </AppLayout>
    );
}

/**
 * Bloco 0 — este grupo faz parte de um grupo maior. Quem governa a cobrança
 * é a tabela de lá; esta só entra em cena se lá não houver nenhuma.
 * A copy não usa jargão de hierarquia — fala de "grupo maior" e leva para a
 * página dele.
 */
function AvisoGrupoMaior({ pai }) {
    return (
        <Card className="border-amber-500/20">
            <CardContent className="p-4 space-y-2">
                <div className="flex items-center gap-2">
                    <AlertTriangle size={14} className="text-amber-400 shrink-0" />
                    <h2 className="text-amber-300 text-[15px] font-semibold">
                        Este grupo faz parte de {pai.name}
                    </h2>
                </div>
                <p className="text-[13px] text-white/60">
                    Para a cobrança, quem manda é a tabela de {pai.name}. A tabela cadastrada aqui só
                    passa a valer se {pai.name} não tiver nenhuma.
                </p>
                <Link href={route('admin.contratos.tabela.grupo.show', pai.id)}>
                    <Button type="button" variant="outline">
                        Abrir a tabela de {pai.name} <ArrowUpRight size={13} className="ml-1" />
                    </Button>
                </Link>
            </CardContent>
        </Card>
    );
}

/**
 * Bloco 1 — "O que está cobrando estas empresas hoje", lendo
 * `tabela_que_vale` (o retorno de `FechamentoFaixaResolver::paraGrupo`).
 * Quando a tabela que vale é de outro grupo, dizer QUAL é o ponto todo:
 * tabela herdada sem dizer de onde veio é o defeito que a Fase 138 corrigiu.
 */
function BlocoTabelaQueVale({ tabelaQueVale, tabelaEDeOutroGrupo }) {
    if (tabelaQueVale == null) {
        return (
            <Card className="border-amber-500/20">
                <CardContent className="p-4 space-y-2">
                    <div className="flex items-center gap-2">
                        <AlertTriangle size={14} className="text-amber-400" />
                        <h2 className="text-amber-300 text-[15px] font-semibold">Sem tabela cadastrada para o grupo</h2>
                    </div>
                    <p className="text-[13px] text-white/60">
                        Enquanto ninguém cadastrar uma tabela aqui, cada empresa continua sendo cobrada
                        pela tabela dela.
                    </p>
                </CardContent>
            </Card>
        );
    }

    return (
        <Card>
            <CardContent className="p-4 space-y-3">
                <div>
                    <h2 className="text-white/85 text-[15px] font-semibold">O que está cobrando estas empresas hoje</h2>
                    <p className="text-[13px] text-white/60 mt-0.5">
                        {tabelaEDeOutroGrupo
                            ? `Esta tabela é do grupo ${tabelaQueVale.grupo_nome ?? ''}.`.trim()
                            : 'Esta é a tabela deste grupo.'}
                    </p>
                </div>
                <TabelaProgressivaFaixas faixas={tabelaQueVale.faixas} />
            </CardContent>
        </Card>
    );
}

/**
 * Bloco 2 — quantas e QUAIS empresas esta tabela alcança: as do próprio
 * grupo mais as dos grupos que fazem parte dele.
 *
 * ⚠️ Este bloco é o motivo de a página existir com este desenho. Quem
 * cadastra precisa ver que está mexendo na mensalidade de 10 empresas, e
 * não de 2 — um número numa frase não entrega isso; a lista, sim.
 */
function BlocoEmpresasAlcancadas({ grupo, subgrupos, empresas }) {
    const total = empresas.length;

    return (
        <Card>
            <CardContent className="p-4 space-y-3">
                <div>
                    <h2 className="text-white/85 text-[15px] font-semibold">Empresas que esta tabela vai cobrar</h2>
                    <p className="text-[13px] text-white/60 mt-0.5">
                        {total === 0
                            ? 'Nenhuma empresa está ligada a este grupo hoje.'
                            : total === 1
                                ? 'A tabela deste grupo vale para 1 empresa.'
                                : `A tabela deste grupo vale para ${total} empresas.`}
                    </p>
                </div>

                {subgrupos.length > 0 && (
                    <div className="space-y-1.5">
                        <p className="text-[12px] text-white/40">
                            Grupos que fazem parte de {grupo.name}:
                        </p>
                        <div className="flex flex-wrap gap-2">
                            {subgrupos.map(sub => (
                                <Link
                                    key={sub.id}
                                    href={route('admin.contratos.tabela.grupo.show', sub.id)}
                                    className="inline-flex items-center gap-1.5 text-[12px] font-semibold text-white/70 bg-white/[0.05] hover:bg-white/[0.09] border border-white/15 px-3 h-7 rounded-lg transition-colors"
                                >
                                    {sub.name}
                                    <span className="text-white/40">
                                        {sub.empresas_count === 1 ? '1 empresa' : `${sub.empresas_count} empresas`}
                                    </span>
                                </Link>
                            ))}
                        </div>
                    </div>
                )}

                {total > 0 && (
                    <ul className="divide-y divide-white/[0.06] rounded-xl border border-white/[0.06] overflow-hidden">
                        {empresas.map(empresa => (
                            <li
                                key={empresa.id}
                                className="flex items-center justify-between gap-3 px-[18px] py-2.5 text-[13px]"
                            >
                                <Link
                                    href={route('admin.contratos.tabela.show', empresa.id)}
                                    className="text-white/80 hover:text-ecf-yellow transition-colors truncate"
                                >
                                    {empresa.name}
                                </Link>
                                <span className="flex items-center gap-2 shrink-0">
                                    {!empresa.ativa && (
                                        <span className="text-[11px] font-semibold text-white/40 border border-white/10 rounded-full px-2 py-0.5">
                                            inativa
                                        </span>
                                    )}
                                    {empresa.grupo_nome && empresa.grupo_nome !== grupo.name && (
                                        <span className="text-[12px] text-white/40">{empresa.grupo_nome}</span>
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

function linhaVaziaFaixa(ordem) {
    return { ordem, limite_superior: null, valor: null, valor_e_piso: false };
}

/**
 * `FormularioFaixas` — cadastro/edição da tabela do grupo. Gêmeo local do
 * de `Admin/TabelaEmpresa.jsx` (ver o aviso no topo deste arquivo sobre por
 * que ele ainda não é um componente compartilhado).
 *
 * Estado inicial das linhas vem de `linhasIniciais` (o que está GRAVADO,
 * nunca uma reconstrução). `modelosDePartida` preenche as linhas a partir
 * do catálogo de um serviço SEM salvar — é só ponto de partida e precisa
 * ser conferido contra o contrato antes de salvar.
 *
 * ⚠️ O campo de Faturamento mostra e recebe o valor REDONDO do contrato
 * ("até 500.000" / "a partir de 500.000"), nunca o teto cru gravado
 * (",99"). A conversão mora inteira em `lib/faixasFaturamento.js` (Quick
 * 260910). A primeira linha grava nela mesma; todas as outras (inclusive a
 * última, sem teto) gravam o valor digitado na LINHA ANTERIOR — é a única
 * que funciona ao contrário das outras, e por isso a mais fácil de errar
 * (`indiceDeGravacao`, testado isolado).
 */
function FormularioFaixas({ linhasIniciais, onSalvar, onRemover, rotuloRemover = 'Apagar tabela', modelosDePartida = [], avisoAntesDeSalvar = null }) {
    const [linhas, setLinhas] = useState(() =>
        linhasIniciais && linhasIniciais.length > 0
            ? linhasIniciais.map(f => ({ ...f }))
            : [linhaVaziaFaixa(1)]
    );
    const [partidaEscolhida, setPartidaEscolhida] = useState(null);
    const [salvando, setSalvando] = useState(false);
    const [erro, setErro] = useState(null);

    function atualizarLinha(idx, campo, valor) {
        setLinhas(prev => prev.map((l, i) => {
            if (i !== idx) return l;
            const nova = { ...l, [campo]: valor };
            // Backend recusa "valor é piso" numa faixa com teto — some o
            // checkbox assim que o campo de teto deixa de estar vazio.
            if (campo === 'limite_superior' && valor !== null) nova.valor_e_piso = false;
            return nova;
        }));
    }

    function aoMudarFaturamento(idx, valorRedondo) {
        atualizarLinha(indiceDeGravacao(idx), 'limite_superior', tetoGravado(valorRedondo));
    }

    function adicionarLinha() {
        const proximaOrdem = linhas.length > 0
            ? Math.max(...linhas.map(l => Number(l.ordem) || 0)) + 1
            : 1;
        setLinhas(prev => [...prev, linhaVaziaFaixa(proximaOrdem)]);
    }

    function removerLinha(idx) {
        const linha = linhas[idx];
        if (!confirm(`Remover a faixa "${linha.ordem}ª faixa" desta tabela?`)) return;
        setLinhas(prev => prev.filter((_, i) => i !== idx));
    }

    function comecarAPartirDoModelo(modelo) {
        setLinhas(modelo.faixas.map(f => ({ ...f })));
        setPartidaEscolhida(modelo.nome);
    }

    function extrairErro(errors) {
        const primeiro = Object.values(errors ?? {})[0];
        if (Array.isArray(primeiro)) return primeiro[0];
        return primeiro ?? 'Não foi possível salvar a tabela.';
    }

    function salvar() {
        if (avisoAntesDeSalvar && !confirm(avisoAntesDeSalvar)) return;

        const payload = linhas.map(l => ({
            ordem: Number(l.ordem),
            limite_superior: l.limite_superior === null || l.limite_superior === '' ? null : Number(l.limite_superior),
            valor: Number(l.valor),
            valor_e_piso: !!l.valor_e_piso,
        }));

        setSalvando(true);
        setErro(null);
        onSalvar(payload, {
            onSuccess: () => setPartidaEscolhida(null),
            onError: (errors) => setErro(extrairErro(errors)),
            onFinish: () => setSalvando(false),
        });
    }

    return (
        <div className="space-y-3">
            {modelosDePartida.length > 0 && (
                <div className="space-y-1.5 pb-2 border-b border-white/[0.06]">
                    <p className="text-[12px] text-white/40">Começar a partir da tabela de um serviço já cadastrado:</p>
                    <div className="flex flex-wrap gap-2">
                        {modelosDePartida.map(modelo => (
                            <button
                                key={modelo.id}
                                type="button"
                                onClick={() => comecarAPartirDoModelo(modelo)}
                                className="inline-flex items-center gap-1.5 text-[12px] font-semibold text-white/70 bg-white/[0.05] hover:bg-white/[0.09] border border-white/15 px-3 h-7 rounded-lg transition-colors"
                            >
                                Começar a partir da tabela de {modelo.nome}
                            </button>
                        ))}
                    </div>
                    {partidaEscolhida && (
                        <p className="text-amber-400 text-[12px]">
                            Isto é um ponto de partida — confira contra o contrato antes de salvar.
                        </p>
                    )}
                </div>
            )}

            {erro && <p className="text-red-400 text-[12px]">{erro}</p>}

            <div className="space-y-3">
                {linhas.map((linha, idx) => (
                    <div key={idx} className="border-b border-white/[0.06] pb-3 space-y-2 last:border-0">
                        <div className="grid grid-cols-[64px_1fr_1fr_auto] gap-2 items-end">
                            <div className="space-y-1">
                                <Label className="text-[12px]">Ordem</Label>
                                <Input
                                    type="number"
                                    min="1"
                                    value={linha.ordem}
                                    onChange={e => atualizarLinha(idx, 'ordem', e.target.value)}
                                />
                            </div>
                            <div className="space-y-1">
                                <Label className="text-[12px]">{idx === 0 ? 'Faturamento até' : 'Faturamento a partir de'}</Label>
                                <CampoDinheiro
                                    valor={valorExibidoNoCampo(linhas, idx)}
                                    onChange={(v) => aoMudarFaturamento(idx, v)}
                                    placeholder={idx === 0 ? 'Sem limite superior' : 'Digite o valor'}
                                />
                            </div>
                            <div className="space-y-1">
                                <Label className="text-[12px]">Valor da mensalidade</Label>
                                <CampoDinheiro
                                    valor={linha.valor}
                                    onChange={(v) => atualizarLinha(idx, 'valor', v)}
                                    avisoAcimaDe={100000}
                                />
                            </div>
                            <button
                                type="button"
                                onClick={() => removerLinha(idx)}
                                title="Remover faixa"
                                className="text-white/40 hover:text-red-400 p-2 rounded transition-colors"
                            >
                                <Trash2 size={14} />
                            </button>
                        </div>
                        {linha.limite_superior === null && (
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={!!linha.valor_e_piso}
                                    onChange={e => atualizarLinha(idx, 'valor_e_piso', e.target.checked)}
                                    className="h-4 w-4 rounded border-white/20 bg-white/5 accent-ecf-yellow"
                                />
                                <span className="text-[12px] text-white/60">Valor é um piso (&quot;a partir de&quot;)</span>
                            </label>
                        )}
                    </div>
                ))}
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    onClick={adicionarLinha}
                    className="inline-flex items-center gap-1.5 text-[12px] font-semibold text-ecf-yellow bg-ecf-yellow/10 hover:bg-ecf-yellow/20 border border-ecf-yellow/20 px-3 h-7 rounded-lg transition-colors w-fit"
                >
                    <Plus size={12} /> Adicionar faixa
                </button>
                <Button type="button" onClick={salvar} disabled={salvando || linhas.length === 0}>
                    {salvando ? 'Salvando...' : 'Salvar tabela'}
                </Button>
                {onRemover && (
                    <Button type="button" variant="outline" onClick={onRemover}>
                        {rotuloRemover}
                    </Button>
                )}
            </div>
        </div>
    );
}
