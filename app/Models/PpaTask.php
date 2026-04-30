<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PpaTask extends Model
{
    protected $fillable = ['ppa_id', 'title', 'description', 'status', 'order', 'created_by'];

    public function ppa()  { return $this->belongsTo(Ppa::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
