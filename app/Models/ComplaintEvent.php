<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Timeline + audit trail of a return: every status change, who did it, when. */
class ComplaintEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['complaint_id', 'status', 'actor_id', 'actor_role', 'note', 'meta', 'created_at'];

    protected $casts = ['meta' => 'array', 'created_at' => 'datetime'];

    public function complaint() { return $this->belongsTo(Complaint::class); }
    public function actor()     { return $this->belongsTo(User::class, 'actor_id'); }
}
