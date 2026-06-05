<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Amenity extends Model
{
    protected $fillable = ['amenity_name', 'icon'];

    public function roomTypes()
    {
        return $this->belongsToMany(RoomType::class, 'room_type_amenities');
    }
}
