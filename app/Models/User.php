<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'clinic_id',
        'name',
        'username',
        'email',
        'password',
        'roles',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'roles' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class);
    }

    public function doctor(): HasOne
    {
        return $this->hasOne(Doctor::class);
    }

    /** @return Collection<int, Role> */
    public function roleList(): Collection
    {
        return collect($this->roles ?? [])->map(fn (string $r) => Role::from($r));
    }

    public function hasRole(Role $role): bool
    {
        return in_array($role->value, $this->roles ?? [], true);
    }

    /**
     * Cabang yang boleh diakses. Owner otomatis semua cabang klinik.
     *
     * @return Collection<int, Branch>
     */
    public function accessibleBranches(): Collection
    {
        if ($this->hasRole(Role::Owner)) {
            return Branch::where('clinic_id', $this->clinic_id)->where('is_active', true)->orderBy('id')->get();
        }

        return $this->branches()->where('is_active', true)->orderBy('branches.id')->get();
    }

    public function firstName(): string
    {
        return str($this->name)->replace('drg. ', '')->before(' ')->toString();
    }
}
