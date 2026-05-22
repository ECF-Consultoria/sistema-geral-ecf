<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Cargo dentro de um setor (ex: "Publicador", "Consultor Sênior").
 * meta_publicacoes herda o significado do antigo User.publication_meta (220/mês),
 * agora vinculado ao cargo "Publicador" do setor "Publicação".
 */
class Cargo extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'setor_id', 'nome', 'slug', 'meta_publicacoes', 'ordem', 'active',
    ];

    protected $casts = [
        'active'           => 'boolean',
        'meta_publicacoes' => 'integer',
        'ordem'            => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['setor_id', 'nome', 'slug', 'meta_publicacoes', 'ordem', 'active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function booted(): void
    {
        static::saving(function (Cargo $cargo) {
            if (empty($cargo->slug) && !empty($cargo->nome)) {
                $cargo->slug = Str::slug($cargo->nome);
            }
        });
    }

    public function setor(): BelongsTo
    {
        return $this->belongsTo(Setor::class);
    }

    /** Users que ocupam este cargo (pode ser N — cargo é por-setor-por-user). */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_setores')
            ->wherePivot('cargo_id', $this->id)
            ->withPivot('cargo_id', 'is_principal', 'assigned_at')
            ->withTimestamps();
    }
}
