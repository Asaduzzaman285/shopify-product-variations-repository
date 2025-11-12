<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Image extends Model
{
    protected $fillable = ['variation_id', 'src'];

    public function variation()
    {
        return $this->belongsTo(Variation::class);
    }
}
