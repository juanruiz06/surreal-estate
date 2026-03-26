<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Listing extends Model
{
    // Allows the script to assign all the desired values at once
    protected $fillable = [
        'external_id', 'url', 'title', 'description', 'price', 
        'size', 'rooms', 'bathrooms', 'type', 'state', 
        'city', 'neighborhood', 'images', 'characteristics'
    ];

    // We convert the Misc JSON to a PHP array 
    protected function casts(): array
    {
        return [
            'images' => 'array',
            'characteristics' => 'array',
        ];
    }
}