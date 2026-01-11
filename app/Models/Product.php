<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'title',
        'price',
        'barcode',
        'dep_id',
        'sub_id',
        'shopify_product_id',
    ];

    public function department()
    {
        return $this->belongsTo(Department::class, 'dep_id', 'dep_id');
    }

    public function subDepartment()
    {
        return $this->belongsTo(SubDepartment::class, 'sub_id', 'sub_id');
    }
}
