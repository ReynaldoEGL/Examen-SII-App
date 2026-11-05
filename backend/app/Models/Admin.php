<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Admin extends Model
{
    protected $fillable = ['name', 'email', 'password', 'role', 'active'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'active' => 'boolean',
        ];
    }
}
