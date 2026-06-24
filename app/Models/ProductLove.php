<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductLove extends Model
{
    // Pin the table name: Laravel's inflector treats "Love" as already-plural,
    // so the default would (wrongly) resolve to "product_love".
    protected $table = 'product_loves';

    protected $fillable = ['product_id', 'customer_id', 'visitor_token'];
}
