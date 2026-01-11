<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubDepartment extends Model
{
    protected $fillable = [
        'sub_id',
        'dep_id',
        'name',
        'shopify_collection_id',
    ];

    public function department()
    {
        return $this->belongsTo(Department::class, 'dep_id', 'dep_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'sub_id', 'sub_id');
    }
}
