import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import {
    Building2, CheckCircle2, Clock, KeyRound, Mail, Plus, ShieldOff, ShieldCheck, Trash2, UserPlus, X,
} from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import SeletorEmpresa from '@/Components/Portal/SeletorEmpresa';
import { cn } from '@/lib/utils';

// ─── Acessos do Portal do Cliente ───────────────────────────────────────────
//
// Onde a EQUIPE decide quem entra. Não há auto-cadastro: sem passar por aqui,
// ninguém acessa o portal.
//
// ### Por que fica dentro da aba Onboarding
// Era uma página própria em `/acessos-portal`, sem link em lugar nenhum — só
// chegava quem já sabia a URL. Dar acesso ao portal é parte de colocar o
// cliente para dentro, e a pergunta "esse cliente já consegue entrar?" nasce
// olhando o onboarding dele. O lugar da operação é aqui.
//
// ### As duas formas de tirar acesso, e por que são separadas
//  - **Desvincular a empresa** tira o acesso àquela empresa e preserva as
//    demais — o caso do gestor que saiu de uma unidade.
//  - **Desativar** derruba o acesso a tudo, na requisição seguinte.
//
// Nenhuma das duas apaga a pessoa: apagar levaria junto o histórico de quem fez
// o quê no PPA.
//
// ### "Nunca entrou" é a informação mais útil da lista
// Separa "convidei e a pessoa não usou" de "usou e parou". É o que diz se o
// convite chegou — e é por isso que tem selo próprio em vez de ficar escondido
// numa coluna de data.

function Selo({ children, tom = 'neutro', icone: Icone }) {
    const tons = {
        neutro:  'bg-white/[0.06] text-white/50',
        ok:      'bg-emerald-400/12 text-emerald-300',
        aviso:   'bg-amber-400/12 text-amber-300',
        parado:  'bg-rose-400/12 text-rose-300',
    };

    return (
        <span className={cn('inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-medium', tons[tom])}>
            {Icone && <Icone size={11} />} {children}
        </span>
    );
}

/** `e:123` / `g:5` -> o par de campos que o backend espera. */
function separarAlvo(valor) {
    if (!valor) return { company_id: null, company_group_id: null };

    const [tipo, id] = valor.split(':');

    return tipo === 'g'
        ? { company_id: null, company_group_id: Number(id) }
        : { company_id: Number(id), company_group_id: null };
}

/**
 * `dados` chega `undefined` até o partial reload responder — a lista NÃO vem
 * na carga de /companies. O esqueleto ocupa esse intervalo; sem ele a sub-aba
 * abriria vazia e leria como "ninguém tem acesso".
 */
export default function AcessosDoPortal({ dados }) {
    const [novoAberto, setNovoAberto] = useState(false);
    const [vincular, setVincular] = useState(null);

    const usuarios = dados?.usuarios ?? [];
    const empresas = dados?.empresas ?? [];
    const grupos   = dados?.grupos ?? [];

    // `alvo` guarda `e:123` ou `g:5`; vira company_id/company_group_id no envio.
    const form = useForm({ nome: '', email: '', telefone: '', cargo: '', alvo: '' });
    const formVinculo = useForm({ alvo: '' });

    const criar = (e) => {
        e.preventDefault();
        form.transform(({ alvo, ...resto }) => ({ ...resto, ...separarAlvo(alvo) }));
        form.post(route('portal.usuarios.store'), {
            preserveScroll: true,
            onSuccess: () => { form.reset(); setNovoAberto(false); },
        });
    };

    /**
     * Excluir é definitivo, e a confirmação precisa dizer isso — inclusive o
     * caminho reversível, para quem clicou aqui querendo só tirar o acesso.
     */
    const excluir = (u) => {
        const aviso = [
            `Excluir ${u.nome} (${u.email}) do portal?`,
            '',
            'O cadastro e os acessos dele somem de vez.',
            u.nunca_entrou ? '' : 'O histórico do que ele fez continua registrado.',
            '',
            'Para apenas tirar o acesso e poder devolver depois, use Desativar.',
        ].filter((l) => l !== undefined).join('\n');

        if (!confirm(aviso)) return;

        router.delete(route('portal.usuarios.destroy', u.id), { preserveScroll: true });
    };

    const alternarAtivo = (u) => {
        const acao = u.ativo ? 'Desativar' : 'Reativar';
        if (!confirm(`${acao} o acesso de ${u.nome}?${u.ativo ? ' Ele perde o acesso na hora.' : ''}`)) return;

        router.put(route('portal.usuarios.update', u.id), { ativo: !u.ativo }, { preserveScroll: true });
    };

    const removerEmpresa = (u, empresa) => {
        if (!confirm(`${u.nome} deixa de acessar ${empresa.nome}. Confirmar?`)) return;

        router.delete(route('portal.usuarios.desvincular', [u.id, empresa.id]), { preserveScroll: true });
    };

    const salvarVinculo = (e) => {
        e.preventDefault();
        formVinculo.transform(({ alvo }) => separarAlvo(alvo));
        formVinculo.post(route('portal.usuarios.vincular', vincular.id), {
            preserveScroll: true,
            onSuccess: () => { formVinculo.reset(); setVincular(null); },
        });
    };

    return (
        <div className="space-y-5">
            <div className="flex items-start justify-between gap-4 flex-wrap">
                <div className="min-w-0">
                    <h2 className="text-white font-display font-bold text-xl tracking-tight flex items-center gap-2">
                        <KeyRound size={19} className="text-ecf-yellow" />
                        Acessos do portal
                    </h2>
                    <p className="text-white/40 text-[13px] mt-0.5">
                        Quem entra no Portal do Cliente, e de quais empresas. Sem cadastro aqui, ninguém acessa.
                    </p>
                </div>

                <Button size="sm" onClick={() => setNovoAberto(true)} disabled={!dados}>
                    <UserPlus size={14} className="mr-1.5" /> Dar acesso
                </Button>
            </div>

            {!dados ? (
                // Três cartões cinza no lugar da lista. O intervalo é curto,
                // mas sem ele a sub-aba abre vazia e lê como "ninguém tem
                // acesso" — a resposta oposta à verdadeira.
                <div className="space-y-2.5" aria-busy="true">
                    {[0, 1, 2].map((i) => (
                        <div key={i} className="rounded-2xl bg-white/[0.02] ring-1 ring-inset ring-white/[0.05] p-4 animate-pulse">
                            <div className="h-3.5 w-48 rounded bg-white/[0.06]" />
                            <div className="h-3 w-64 rounded bg-white/[0.04] mt-3" />
                            <div className="h-6 w-40 rounded-lg bg-white/[0.04] mt-4" />
                        </div>
                    ))}
                </div>
            ) : usuarios.length === 0 ? (
                <div className="rounded-2xl bg-white/[0.02] ring-1 ring-inset ring-white/[0.06] text-center py-16 px-6">
                    <span className="grid place-items-center h-12 w-12 rounded-2xl bg-white/[0.04] text-white/30 mx-auto">
                        <UserPlus size={22} />
                    </span>
                    <h3 className="text-white font-display font-bold text-lg mt-4">Ninguém tem acesso ainda</h3>
                    <p className="text-white/40 text-[13px] mt-2 max-w-md mx-auto leading-relaxed">
                        Cadastre a primeira pessoa. Ela vai entrar no portal com o e-mail dela, sem precisar de link nem senha.
                    </p>
                </div>
            ) : (
                <div className="space-y-2.5">
                    {usuarios.map((u) => (
                        <div
                            key={u.id}
                            className={cn(
                                'rounded-2xl ring-1 ring-inset p-4',
                                u.ativo ? 'bg-white/[0.025] ring-white/[0.06]' : 'bg-rose-500/[0.03] ring-rose-400/15',
                            )}
                        >
                            <div className="flex items-start justify-between gap-4 flex-wrap">
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2 flex-wrap">
                                        <p className="text-white font-semibold text-[15px]">{u.nome}</p>
                                        {u.cargo && <span className="text-white/35 text-[12px]">· {u.cargo}</span>}
                                        {!u.ativo && <Selo tom="parado" icone={ShieldOff}>Acesso desativado</Selo>}
                                        {u.ativo && u.nunca_entrou && <Selo tom="aviso" icone={Clock}>Nunca entrou</Selo>}
                                        {u.ativo && !u.nunca_entrou && <Selo tom="ok" icone={CheckCircle2}>Ativo</Selo>}
                                    </div>

                                    <p className="flex items-center gap-1.5 text-white/45 text-[12.5px] mt-1.5">
                                        <Mail size={12} /> {u.email}
                                    </p>

                                    <div className="flex items-center gap-1.5 flex-wrap mt-3">
                                        {u.empresas.map((e) => (
                                            <span
                                                key={e.id}
                                                className="group inline-flex items-center gap-1.5 pl-2 pr-1 py-1 rounded-lg bg-white/[0.05] text-white/70 text-[12px]"
                                            >
                                                <Building2 size={11} className="text-white/35" /> {e.nome}
                                                <button
                                                    type="button"
                                                    onClick={() => removerEmpresa(u, e)}
                                                    title={`Tirar o acesso de ${u.nome} a ${e.nome}`}
                                                    className="p-0.5 rounded text-white/20 hover:text-rose-300 hover:bg-rose-400/10 transition-colors"
                                                >
                                                    <X size={11} />
                                                </button>
                                            </span>
                                        ))}

                                        <button
                                            type="button"
                                            onClick={() => { formVinculo.reset(); setVincular(u); }}
                                            className="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-white/30 hover:text-white/70 hover:bg-white/[0.05] text-[12px] transition-colors"
                                        >
                                            <Plus size={11} /> empresa
                                        </button>
                                    </div>

                                    <p className="text-white/25 text-[11.5px] mt-3">
                                        {u.nunca_entrou
                                            ? `Convidado ${u.convidado_em ? `em ${u.convidado_em}` : ''}${u.convidado_por ? ` por ${u.convidado_por}` : ''} · ainda não entrou`
                                            : `Último acesso em ${u.ultimo_acesso_em}`}
                                    </p>
                                </div>

                                {/* Duas ações com pesos diferentes, e a tela precisa
                                    mostrar isso: desativar é reversível e preserva o
                                    histórico ligado à pessoa; excluir é definitivo. Por
                                    isso a segunda é discreta e fica ao lado, não
                                    competindo com a primeira. */}
                                <div className="flex items-center gap-1 shrink-0">
                                    <button
                                        type="button"
                                        onClick={() => alternarAtivo(u)}
                                        className={cn(
                                            'inline-flex items-center gap-1.5 h-8 px-3 rounded-lg text-[12.5px] font-medium transition-colors',
                                            u.ativo
                                                ? 'text-white/45 hover:text-amber-300 hover:bg-amber-400/10'
                                                : 'text-emerald-300 bg-emerald-400/10 hover:bg-emerald-400/15',
                                        )}
                                    >
                                        {u.ativo ? <><ShieldOff size={13} /> Desativar</> : <><ShieldCheck size={13} /> Reativar</>}
                                    </button>

                                    <button
                                        type="button"
                                        onClick={() => excluir(u)}
                                        title={`Excluir ${u.nome} do portal — definitivo`}
                                        className="grid place-items-center h-8 w-8 rounded-lg text-white/25 hover:text-rose-300 hover:bg-rose-400/10 transition-colors"
                                    >
                                        <Trash2 size={14} />
                                    </button>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {/* ─── Dar acesso ──────────────────────────────────────────────── */}
            <Dialog open={novoAberto} onOpenChange={setNovoAberto}>
                <DialogContent className="max-w-md">
                    <DialogHeader><DialogTitle>Dar acesso ao portal</DialogTitle></DialogHeader>

                    <form onSubmit={criar} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label className="text-[12px]">Nome</Label>
                            <Input
                                value={form.data.nome}
                                onChange={(e) => form.setData('nome', e.target.value)}
                                placeholder="Nome da pessoa"
                                autoFocus
                            />
                            {form.errors.nome && <p className="text-rose-300 text-[12px]">{form.errors.nome}</p>}
                        </div>

                        <div className="space-y-1.5">
                            <Label className="text-[12px]">E-mail</Label>
                            <Input
                                type="email"
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                                placeholder="pessoa@empresa.com.br"
                            />
                            {/* É por aqui que ela entra — vale dizer com todas as letras. */}
                            <p className="text-white/30 text-[11.5px]">
                                É este o e-mail que vai receber o código de acesso.
                            </p>
                            {form.errors.email && <p className="text-rose-300 text-[12px]">{form.errors.email}</p>}
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label className="text-[12px]">Telefone</Label>
                                <Input
                                    value={form.data.telefone}
                                    onChange={(e) => form.setData('telefone', e.target.value)}
                                    placeholder="(00) 00000-0000"
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-[12px]">Cargo</Label>
                                <Input
                                    value={form.data.cargo}
                                    onChange={(e) => form.setData('cargo', e.target.value)}
                                    placeholder="Ex: Financeiro"
                                />
                            </div>
                        </div>

                        <div className="space-y-1.5">
                            <Label className="text-[12px]">Empresa</Label>
                            <SeletorEmpresa
                                empresas={empresas}
                                grupos={grupos}
                                valor={form.data.alvo}
                                onChange={(v) => form.setData('alvo', v)}
                            />
                            <p className="text-white/30 text-[11.5px]">
                                Empresa de um grupo? Dá para liberar o grupo inteiro de uma vez.
                            </p>
                            {(form.errors.company_id || form.errors.company_group_id) && (
                                <p className="text-rose-300 text-[12px]">Selecione uma empresa ou um grupo.</p>
                            )}
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setNovoAberto(false)}>Cancelar</Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing ? 'Salvando…' : 'Dar acesso'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* ─── Mais uma empresa ────────────────────────────────────────── */}
            <Dialog open={!!vincular} onOpenChange={() => setVincular(null)}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Mais uma empresa para {vincular?.nome}</DialogTitle>
                    </DialogHeader>

                    <form onSubmit={salvarVinculo} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label className="text-[12px]">Empresa</Label>
                            <SeletorEmpresa
                                empresas={empresas}
                                grupos={grupos}
                                valor={formVinculo.data.alvo}
                                onChange={(v) => formVinculo.setData('alvo', v)}
                                excluirIds={vincular?.empresas.map((v) => v.id) ?? []}
                                placeholder="Selecione…"
                            />
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setVincular(null)}>Cancelar</Button>
                            <Button type="submit" disabled={formVinculo.processing}>Vincular</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
