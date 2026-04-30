<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'role', 'created_by', 'active', 'phone',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
        ];
    }

    public function isAdmin(): bool { return $this->role === 'admin'; }
    public function isConsultor(): bool { return $this->role === 'consultor'; }
    public function isMentor(): bool { return $this->role === 'mentor'; }

    public function companies()
    {
        return $this->belongsToMany(Company::class, 'company_users')
            ->withPivot('role', 'assigned_at')
            ->withTimestamps();
    }

    public function consultorCompanies()
    {
        return $this->belongsToMany(Company::class, 'company_users')
            ->wherePivot('role', 'consultor')
            ->withPivot('role', 'assigned_at');
    }

    public function mentorCompanies()
    {
        return $this->belongsToMany(Company::class, 'company_users')
            ->wherePivot('role', 'mentor')
            ->withPivot('role', 'assigned_at');
    }

    public function generatedSurveys()
    {
        return $this->hasMany(NpsSurvey::class, 'generated_by');
    }

    public function ppas()
    {
        return $this->hasMany(Ppa::class, 'mentor_id');
    }

    public function portfolioGoals()
    {
        return $this->hasMany(PortfolioGoal::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
