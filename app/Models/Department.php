<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    protected $fillable = [
        'dep_id',
        'name',
        'shopify_collection_id',
    ];

    public function subDepartments()
    {
        return $this->hasMany(SubDepartment::class, 'dep_id', 'dep_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'dep_id', 'dep_id');
    }
}
