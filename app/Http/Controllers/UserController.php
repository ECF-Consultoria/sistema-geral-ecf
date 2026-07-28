<?php

namespace App\Http\Controllers;

use App\Models\Cargo;
use App\Models\Setor;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * CRUD de usuários. Substituiu a estrutura legacy de publication_role + setor
 * string por vínculos com Setor/Cargo via tabela pivot user_setores.
 *
 * Mantém role (admin/consultor/mentor) porque ainda é usada por Goal/PortfolioGoal/
 * PpaController/company_users.role.
 */
class UserController extends Controller
{
    public function index()
    {
        $mapUser = function (User $u) {
            $setores = $u->setores()->get(['setores.id', 'nome', 'slug'])->map(function ($s) {
                $cargoId = $s->pivot->cargo_id;
                $cargoNome = $cargoId ? Cargo::find($cargoId)?->nome : null;
                return [
                    'id'           => $s->id,
                    'nome'         => $s->nome,
                    'slug'         => $s->slug,
                    'cargo_id'     => $cargoId,
                    'cargo_nome'   => $cargoNome,
                    'is_principal' => (bool) $s->pivot->is_principal,
                ];
            });

            return [
                'id'              => $u->id,
                'name'            => $u->name,
                'email'           => $u->email,
                'avatar'          => $u->avatar_url,
                'role'            => $u->role,
                'is_admin'        => $u->role === 'admin',
                // Cargo Dev (quick 260727-mx3) — alimenta o toggle "Dev" do form.
                'is_dev'          => (bool) $u->is_dev,
                'active'          => $u->active,
                'phone'           => $u->phone,
                'setores'         => $setores,
                'lideres_setores' => $u->setoresLiderados()->pluck('setores.id'),
                'companies_count' => $u->companies_count,
                'created_by_name' => $u->createdBy?->name,
                'created_at'      => $u->created_at->format('d/m/Y'),
                'deleted_at'      => $u->deleted_at?->format('d/m/Y H:i'),
            ];
        };

        $users = User::with('createdBy')
            ->withCount('companies')
            ->orderBy('name')
            ->get()
            ->map($mapUser);

        $deletedUsers = User::onlyTrashed()
            ->with('createdBy')
            ->withCount('companies')
            ->orderBy('name')
            ->get()
            ->map($mapUser);

        // Catálogo de setores + cargos pra dropdowns do form.
        // O setor Desenvolvimento fica FORA: quem governa aquele vínculo é o
        // toggle "Dev" (que escreve vínculo + espelho users.is_dev juntos).
        // Deixá-lo no dropdown criaria um segundo caminho, capaz de gravar o
        // vínculo sem o espelho — e o Dev não veria os módulos ocultos.
        $setoresDisponiveis = Setor::where('active', true)
            ->where('slug', '!=', User::SETOR_DEV_SLUG)
            ->with(['cargos' => fn($q) => $q->where('active', true)->orderBy('ordem')->orderBy('nome')])
            ->orderBy('nome')
            ->get()
            ->map(fn($s) => [
                'id'         => $s->id,
                'nome'       => $s->nome,
                'slug'       => $s->slug,
                'is_system'  => (bool) $s->is_system,
                'cargos'     => $s->cargos->map(fn($c) => ['id' => $c->id, 'nome' => $c->nome])->all(),
            ]);

        return Inertia::render('Users/Index', [
            'users'              => $users,
            'deletedUsers'       => $deletedUsers,
            'setoresDisponiveis' => $setoresDisponiveis,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateUser($request, isUpdate: false);

        $isAdmin = (bool) ($data['is_admin'] ?? false);

        $user = User::create([
            'name'       => $data['name'],
            'email'      => $data['email'],
            'password'   => Hash::make($data['password']),
            'role'       => $this->resolveSystemRole($isAdmin, $data['vinculos'] ?? []),
            'phone'      => $data['phone'] ?? null,
            'created_by' => $request->user()->id,
            'active'     => true,
        ]);

        $this->syncVinculos($user, $isAdmin, $data['vinculos'] ?? []);
        $this->syncCargoDev($request, $user, (bool) ($data['is_dev'] ?? false));

        return back()->with('success', "Usuário {$user->name} criado.");
    }

    public function update(Request $request, User $user)
    {
        $data = $this->validateUser($request, isUpdate: true, userId: $user->id);

        $isAdmin = (bool) ($data['is_admin'] ?? false);

        $update = [
            'name'   => $data['name'],
            'email'  => $data['email'],
            'phone'  => $data['phone'] ?? null,
            'active' => $data['active'] ?? $user->active,
            'role'   => $this->resolveSystemRole($isAdmin, $data['vinculos'] ?? []),
        ];
        if (!empty($data['password'])) {
            $update['password'] = Hash::make($data['password']);
        }

        $user->update($update);

        $this->syncVinculos($user, $isAdmin, $data['vinculos'] ?? []);
        $devMudou = $this->syncCargoDev($request, $user, (bool) ($data['is_dev'] ?? false));

        if ($devMudou === 'auto_lockout_bloqueado') {
            return back()->with('error', 'Você não pode remover o seu próprio cargo Dev.');
        }

        return back()->with('success', 'Usuário atualizado.');
    }

    /**
     * Upload da foto do usuário. Guarda no disco público (storage/app/public/avatars)
     * e salva a URL pública em users.avatar_url. Requer `php artisan storage:link`.
     */
    public function updateAvatar(Request $request, User $user)
    {
        // Aceita a imagem original grande (foto de celular). O peso final é resolvido
        // no redimensionamento abaixo — o que fica guardado é sempre pequeno.
        $request->validate(
            ['avatar' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:15360']],
            [
                'avatar.required' => 'Selecione uma imagem.',
                'avatar.image'    => 'O arquivo precisa ser uma imagem.',
                'avatar.mimes'    => 'Formatos aceitos: JPG, PNG ou WEBP.',
                'avatar.max'      => 'A imagem deve ter no máximo 15 MB.',
            ],
        );

        // Remove a foto anterior se era um upload local (não mexe em URL externa).
        $this->apagarAvatarLocal($user);

        // Redimensiona/comprime antes de guardar (avatar leve, sistema não incha).
        $path = $this->salvarAvatarRedimensionado($request->file('avatar'), $user);
        $user->forceFill(['avatar_url' => Storage::url($path)])->save();

        return back()->with('success', "Foto de {$user->name} atualizada.");
    }

    /**
     * Redimensiona a imagem enviada para no máximo 512px (mantendo proporção, só reduz)
     * e re-encoda para um formato leve — WebP quando disponível, senão JPEG. Devolve o
     * caminho salvo no disco público. Usa GD (sem dependência externa). Assim o usuário
     * pode subir uma foto grande, mas o arquivo guardado fica em ~100–200 KB.
     */
    private function salvarAvatarRedimensionado(\Illuminate\Http\UploadedFile $file, User $user): string
    {
        $maxDim = 512;
        $origem = $file->getRealPath();
        $mime   = $file->getMimeType();

        $src = match (true) {
            $mime === 'image/jpeg' && function_exists('imagecreatefromjpeg') => @imagecreatefromjpeg($origem),
            $mime === 'image/png'  && function_exists('imagecreatefrompng')  => @imagecreatefrompng($origem),
            $mime === 'image/webp' && function_exists('imagecreatefromwebp') => @imagecreatefromwebp($origem),
            default => null,
        };

        // Se o GD não conseguiu abrir (formato exótico/corrompido), guarda o original.
        if (! $src) {
            return $file->store('avatars', 'public');
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $escala = min(1, $maxDim / max($w, $h)); // nunca amplia
        $nw = max(1, (int) round($w * $escala));
        $nh = max(1, (int) round($h * $escala));

        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);   // preserva transparência (PNG/WebP)
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        if (function_exists('imagewebp')) {
            $ext = 'webp';
            ob_start();
            imagewebp($dst, null, 82);
            $bin = ob_get_clean();
        } else {
            // Sem WebP: achata a transparência em branco e salva JPEG.
            $fundo  = imagecreatetruecolor($nw, $nh);
            $branco = imagecolorallocate($fundo, 255, 255, 255);
            imagefilledrectangle($fundo, 0, 0, $nw, $nh, $branco);
            imagecopy($fundo, $dst, 0, 0, 0, 0, $nw, $nh);
            $ext = 'jpg';
            ob_start();
            imagejpeg($fundo, null, 85);
            $bin = ob_get_clean();
            imagedestroy($fundo);
        }

        imagedestroy($src);
        imagedestroy($dst);

        $path = 'avatars/' . $user->id . '_' . uniqid() . '.' . $ext;
        Storage::disk('public')->put($path, $bin);

        return $path;
    }

    /** Remove a foto do usuário (apaga o arquivo local, se houver, e zera a URL). */
    public function destroyAvatar(User $user)
    {
        $this->apagarAvatarLocal($user);
        $user->forceFill(['avatar_url' => null])->save();

        return back()->with('success', "Foto de {$user->name} removida.");
    }

    /** Apaga o arquivo físico da foto quando ela é um upload local (/storage/avatars/...). */
    private function apagarAvatarLocal(User $user): void
    {
        $url = (string) $user->avatar_url;
        if (str_starts_with($url, '/storage/')) {
            Storage::disk('public')->delete(substr($url, strlen('/storage/')));
        }
    }

    private function validateUser(Request $request, bool $isUpdate, ?int $userId = null): array
    {
        // Normaliza password vazia → null pra `nullable` valer (string '' não passa pelo nullable do Laravel)
        if ($isUpdate && trim((string) $request->input('password', '')) === '') {
            $request->merge(['password' => null, 'password_confirmation' => null]);
        }

        $rules = [
            'name'                    => ['required', 'string', 'max:255'],
            'email'                   => [
                'required', 'email',
                $isUpdate ? Rule::unique('users')->ignore($userId) : Rule::unique('users'),
            ],
            'phone'                   => ['nullable', 'string', 'max:20'],
            'is_admin'                => ['boolean'],
            'is_dev'                  => ['boolean'],
            'active'                  => ['boolean'],
            'vinculos'                => ['array'],
            'vinculos.*.setor_id'     => ['required', 'integer', 'exists:setores,id'],
            'vinculos.*.cargo_id'     => ['nullable', 'integer', 'exists:cargos,id'],
            'vinculos.*.is_principal' => ['boolean'],
        ];

        if ($isUpdate) {
            $rules['password'] = ['nullable', 'string', 'min:8', 'confirmed'];
        } else {
            $rules['password'] = ['required', 'string', 'min:8', 'confirmed'];
        }

        $data = $request->validate($rules);

        // Validação cruzada: cargo precisa pertencer ao setor do mesmo vínculo
        // (a regra `exists:cargos,id` sozinha não garante isso)
        $errors = [];
        foreach ($data['vinculos'] ?? [] as $i => $v) {
            if (!empty($v['cargo_id'])) {
                $cargo = Cargo::find($v['cargo_id']);
                if ($cargo && (int) $cargo->setor_id !== (int) $v['setor_id']) {
                    $errors["vinculos.{$i}.cargo_id"] = 'Cargo selecionado não pertence ao setor escolhido.';
                }
            }
        }
        if ($errors) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }

        return $data;
    }

    /**
     * Decide role do sistema (admin/consultor/mentor) baseado em:
     * - is_admin flag → role=admin
     * - se algum vínculo tem cargo "Mentor" → role=mentor
     * - senão → role=consultor (default)
     */
    private function resolveSystemRole(bool $isAdmin, array $vinculos): string
    {
        if ($isAdmin) return 'admin';

        foreach ($vinculos as $v) {
            if (empty($v['cargo_id'])) continue;
            $cargo = Cargo::find($v['cargo_id']);
            if ($cargo && $cargo->slug === 'mentor') return 'mentor';
        }
        return 'consultor';
    }

    /**
     * Sincroniza user_setores: remove vínculos que não vieram, atualiza os existentes,
     * cria os novos. Garante no máximo 1 is_principal.
     */
    private function syncVinculos(User $user, bool $isAdmin, array $vinculos): void
    {
        // Admin tem 1 vínculo fixo: setor Administração + cargo Admin
        if ($isAdmin) {
            $admin = Setor::where('slug', 'administracao')->first();
            if ($admin) {
                $cargoAdmin = Cargo::where('setor_id', $admin->id)->where('slug', 'admin')->first();
                $vinculos = [[
                    'setor_id'     => $admin->id,
                    'cargo_id'     => $cargoAdmin?->id,
                    'is_principal' => true,
                ]];
            }
        }

        // Garante no máx 1 is_principal — se o front mandou múltiplos, pega o primeiro
        $principalEncontrado = false;
        foreach ($vinculos as &$v) {
            if (!empty($v['is_principal'])) {
                if ($principalEncontrado) $v['is_principal'] = false;
                else $principalEncontrado = true;
            }
        }
        unset($v);
        // Se nenhum vínculo foi marcado como principal, marca o primeiro
        if (!$principalEncontrado && count($vinculos) > 0) {
            $vinculos[0]['is_principal'] = true;
        }

        // O vínculo do cargo Dev não trafega neste array (o setor é filtrado do
        // dropdown — quem o governa é o toggle "Dev"), então precisa ficar fora
        // da remoção abaixo, senão todo save comum derrubaria o cargo Dev.
        $setorDevId = Setor::where('slug', User::SETOR_DEV_SLUG)->value('id');

        DB::transaction(function () use ($user, $vinculos, $setorDevId) {
            $setorIdsAtuais = DB::table('user_setores')->where('user_id', $user->id)->pluck('setor_id')->all();
            $setorIdsNovos  = array_column($vinculos, 'setor_id');
            if ($setorDevId) {
                $setorIdsNovos[] = $setorDevId;
            }

            // Remove os que sumiram
            $aRemover = array_diff($setorIdsAtuais, $setorIdsNovos);
            if (!empty($aRemover)) {
                DB::table('user_setores')
                    ->where('user_id', $user->id)
                    ->whereIn('setor_id', $aRemover)
                    ->delete();
            }

            // Upsert dos vínculos atuais
            foreach ($vinculos as $v) {
                DB::table('user_setores')->updateOrInsert(
                    ['user_id' => $user->id, 'setor_id' => $v['setor_id']],
                    [
                        'cargo_id'     => $v['cargo_id'] ?? null,
                        'is_principal' => !empty($v['is_principal']),
                        'assigned_at'  => now(),
                        'updated_at'   => now(),
                        'created_at'   => now(),
                    ]
                );
            }
        });
    }

    /**
     * Aplica/remove o cargo Dev (quick 260727-mx3) — vínculo real em
     * `user_setores` (setor 'desenvolvimento' + cargo 'dev') MAIS o espelho
     * `users.is_dev`, escritos na mesma transação.
     *
     * Roda DEPOIS de `syncVinculos()` de propósito: quando `is_admin`, aquele
     * método descarta os vínculos enviados e força um único (Administração +
     * Admin) — o cargo Dev precisa sobreviver a isso. Do outro lado,
     * `syncVinculos()` já exclui o setor Dev da sua etapa de remoção, porque
     * esse vínculo não trafega no array `vinculos`.
     *
     * Anti-lockout: ninguém remove o próprio cargo Dev, senão perderia o acesso
     * ao painel /dev/modulos. Qualquer admin pode reconceder aqui pelo /users.
     *
     * `is_dev` é guarded (fora de $fillable, Fase 97) → gravado via forceFill.
     *
     * Idempotente por desenho (reaplica o estado desejado mesmo quando ele não
     * mudou): assim um "salvar" comum reconstrói o vínculo caso ele tenha se
     * perdido, e vínculo e espelho voltam a bater sozinhos.
     *
     * @return string|null 'auto_lockout_bloqueado' quando a trava impediu a remoção.
     */
    private function syncCargoDev(Request $request, User $user, bool $querDev): ?string
    {
        $eraDev = (bool) $user->is_dev;

        if (! $querDev && $eraDev && $user->id === $request->user()?->id) {
            return 'auto_lockout_bloqueado';
        }

        $setor = Setor::where('slug', User::SETOR_DEV_SLUG)->first();
        $cargo = $setor
            ? Cargo::where('setor_id', $setor->id)->where('slug', User::CARGO_DEV_SLUG)->first()
            : null;

        // Sem o setor/cargo semeados não há como materializar o vínculo — não
        // adianta gravar só o espelho, ele deixaria de ser derivado de nada.
        if (! $setor || ! $cargo) {
            return null;
        }

        DB::transaction(function () use ($user, $setor, $cargo, $querDev) {
            if ($querDev) {
                DB::table('user_setores')->updateOrInsert(
                    ['user_id' => $user->id, 'setor_id' => $setor->id],
                    [
                        'cargo_id'     => $cargo->id,
                        // Nunca principal: o setor principal do dev continua
                        // sendo o setor de negócio dele.
                        'is_principal' => false,
                        'assigned_at'  => now(),
                        'updated_at'   => now(),
                        'created_at'   => now(),
                    ],
                );
            } else {
                DB::table('user_setores')
                    ->where('user_id', $user->id)
                    ->where('setor_id', $setor->id)
                    ->delete();
            }

            $user->forceFill(['is_dev' => $querDev])->save();
        });

        return null;
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['delete' => 'Você não pode excluir sua própria conta.']);
        }

        $nome = $user->name;
        $user->delete();
        return back()->with('success', "Usuário \"{$nome}\" excluído.");
    }

    public function restore(int $id)
    {
        $user = User::withTrashed()->findOrFail($id);
        $user->restore();
        return back()->with('success', "Usuário \"{$user->name}\" restaurado.");
    }

    public function forceDestroy(Request $request, int $id)
    {
        $user = User::withTrashed()->findOrFail($id);

        if ($user->id === $request->user()->id) {
            return back()->withErrors(['delete' => 'Você não pode excluir sua própria conta.']);
        }

        $nome = $user->name;
        $user->forceDelete();
        return back()->with('success', "Usuário \"{$nome}\" removido permanentemente.");
    }
}
