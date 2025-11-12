<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Variation extends Model
{
    protected $fillable = ['product_id', 'shopify_variant_id', 'title', 'price', 'inventory_quantity'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function images()
    {
        return $this->hasMany(Image::class);
    }
}
