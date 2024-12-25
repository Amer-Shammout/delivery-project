<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'total_price',
        'order_status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function subOrders()
    {
        return $this->hasMany(SubOrder::class);
    }
    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }
}