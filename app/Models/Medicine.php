<?php namespace App\Models; use Illuminate\Database\Eloquent\Model; class Medicine extends Model { protected $guarded=[]; public function offers(){return $this->hasMany(Offer::class);} }
