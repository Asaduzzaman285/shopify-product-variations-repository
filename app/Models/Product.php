<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = ['shopify_product_id', 'title', 'description'];

    public function variations()
    {
        return $this->hasMany(Variation::class);
    }
}
