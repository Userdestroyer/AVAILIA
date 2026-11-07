<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['content', 'author', 'message_id'])]
class ContextPlateVersion extends Model
{
    public function plate(): BelongsTo
    {
        return $this->belongsTo(ContextPlate::class, 'context_plate_id');
    }
}
