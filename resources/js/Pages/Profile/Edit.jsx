import AppLayout from '@/Layouts/AppLayout';
import { useForm, router } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { CheckCircle2, Calendar, AlertTriangle, Unlink } from 'lucide-react';

function ProfileSection({ user }) {
    const { data, setData, patch, processing, errors } = useForm({
        name: user.name,
        email: user.email,
    });

    const submit = (e) => {
        e.preventDefault();
        patch(route('profile.update'));
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Informações do Perfil</CardTitle>
                <CardDescription>Atualize seu nome e e-mail.</CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-4 max-w-md">
                    <div className="space-y-1.5">
                        <Label>Nome</Label>
                        <Input value={data.name} onChange={e => setData('name', e.target.value)} />
                        {errors.name && <p className="text-destructive text-xs">{errors.name}</p>}
                    </div>
                    <div className="space-y-1.5">
                        <Label>E-mail</Label>
                        <Input type="email" value={data.email} onChange={e => setData('email', e.target.value)} />
                        {errors.email && <p className="text-destructive text-xs">{errors.email}</p>}
                    </div>
                    <Button type="submit" disabled={processing}>
                        {processing ? 'Salvando...' : 'Salvar'}
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

function PasswordSection() {
    const { data, setData, put, processing, errors, reset } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('password.update'), { onSuccess: () => reset() });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Alterar Senha</CardTitle>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-4 max-w-md">
                    <div className="space-y-1.5">
                        <Label>Senha atual</Label>
                        <Input type="password" value={data.current_password} onChange={e => setData('current_password', e.target.value)} />
                        {errors.current_password && <p className="text-destructive text-xs">{errors.current_password}</p>}
                    </div>
                    <div className="space-y-1.5">
                        <Label>Nova senha</Label>
                        <Input type="password" value={data.password} onChange={e => setData('password', e.target.value)} />
                        {errors.password && <p className="text-destructive text-xs">{errors.password}</p>}
                    </div>
                    <div className="space-y-1.5">
                        <Label>Confirmar nova senha</Label>
                        <Input type="password" value={data.password_confirmation} onChange={e => setData('password_confirmation', e.target.value)} />
                    </div>
                    <Button type="submit" disabled={processing}>
                        {processing ? 'Salvando...' : 'Alterar Senha'}
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

function GoogleCalendarSection({ connected, tokenExpiry }) {
    const disconnect = () => {
        if (confirm('Desconectar o Google Calendar?')) {
            router.delete(route('google.disconnect'));
        }
    };

    return (
        <Card>
            <CardHeader>
                <div className="flex items-center gap-2">
                    <Calendar size={18} className="text-ecf-yellow/70" />
                    <CardTitle className="text-base">Google Calendar</CardTitle>
                </div>
                <CardDescription>
                    Conecte sua conta para sincronizar reuniões automaticamente.
                    Use o padrão <code className="text-xs bg-white/[0.06] px-1 rounded">[Cliente: NomeEmpresa]</code> no título dos eventos.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {connected ? (
                    <div className="space-y-3">
                        <div className="flex items-center gap-2 text-emerald-400 text-sm">
                            <CheckCircle2 size={16} />
                            <span>Google Calendar conectado</span>
                            {tokenExpiry && (
                                <span className="text-white/30 text-xs">· token expira em {tokenExpiry}</span>
                            )}
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={disconnect}
                            className="text-red-400 border-red-400/30 hover:bg-red-400/10"
                        >
                            <Unlink size={13} className="mr-1.5" /> Desconectar
                        </Button>
                    </div>
                ) : (
                    <div className="space-y-3">
                        <div className="flex items-start gap-2 text-yellow-400/80 text-sm">
                            <AlertTriangle size={15} className="mt-0.5 shrink-0" />
                            <span>Não conectado. Ao conectar, você poderá sincronizar eventos do Google Agenda com as reuniões do sistema.</span>
                        </div>
                        <a href={route('google.connect')}>
                            <Button size="sm">
                                <Calendar size={14} className="mr-1.5" /> Conectar Google Calendar
                            </Button>
                        </a>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

export default function ProfileEdit({ auth, googleConnected, googleTokenExpiry }) {
    const user = auth?.user;

    return (
        <AppLayout title="Perfil">
            <div className="max-w-2xl space-y-5">
                <ProfileSection user={user} />
                <PasswordSection />
                <GoogleCalendarSection connected={googleConnected} tokenExpiry={googleTokenExpiry} />
            </div>
        </AppLayout>
    );
}
