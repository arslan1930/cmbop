<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'role_id',
        'balance',
        'reserved_balance',
        'currency',
    ];

    /**
     * Owner of the wallet
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Role associated with this wallet
     */
    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Add amount to balance
     */
    public function addBalance(float $amount)
    {
        $this->balance += $amount;
        return $this->save();
    }

    /**
     * Deduct amount from balance
     */
    public function deductBalance(float $amount)
    {
        if ($amount > $this->balance) {
            throw new \Exception("Insufficient balance");
        }
        $this->balance -= $amount;
        return $this->save();
    }

    /**
     * Move funds to reserved balance
     */
    public function reserveAmount(float $amount)
    {
        if ($amount > $this->balance) {
            throw new \Exception("Insufficient balance to reserve");
        }
        $this->balance -= $amount;
        $this->reserved_balance += $amount;
        return $this->save();
    }

    /**
     * Release reserved amount back to balance
     */
    public function releaseReserved(float $amount)
    {
        if ($amount > $this->reserved_balance) {
            throw new \Exception("Reserved balance too low");
        }
        $this->reserved_balance -= $amount;
        $this->balance += $amount;
        return $this->save();
    }
}