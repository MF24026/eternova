<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Providers;

use App\Modules\Reservations\Models\Reservation;
use App\Modules\Reservations\Policies\ReservationPolicy;
use App\Modules\Reservations\Repositories\EloquentReservationRepository;
use App\Modules\Reservations\Repositories\ReservationRepositoryInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class ReservationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ReservationRepositoryInterface::class, EloquentReservationRepository::class);
    }

    public function boot(): void
    {
        Gate::policy(Reservation::class, ReservationPolicy::class);
    }
}
